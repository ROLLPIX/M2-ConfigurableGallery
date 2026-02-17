<?php

declare(strict_types=1);

namespace Rollpix\ConfigurableGallery\Console\Command;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Rollpix\ConfigurableGallery\Model\AttributeResolver;
use Rollpix\ConfigurableGallery\Model\ColorMapping;
use Rollpix\ConfigurableGallery\Model\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI command: bin/magento rollpix:gallery:migrate (PRD §10.2, §11)
 *
 * Migration toolkit with 3 modes:
 * - diagnose: Report current state, identify migration candidates
 * - consolidate: Move images from simple children to configurable parent with dedup
 * - auto-map: Automatically map images to colors by filename/label patterns
 */
class MigrateCommand extends Command
{
    private const OPTION_MODE = 'mode';
    private const OPTION_PRODUCT_ID = 'product-id';
    private const OPTION_ALL = 'all';
    private const OPTION_DRY_RUN = 'dry-run';
    private const OPTION_CLEAN = 'clean';

    public function __construct(
        private readonly Config $config,
        private readonly AttributeResolver $attributeResolver,
        private readonly ColorMapping $colorMapping,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly ResourceConnection $resourceConnection,
        private readonly State $appState
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('rollpix:gallery:migrate')
            ->setDescription('Herramientas de migración para Rollpix ConfigurableGallery')
            ->addOption(
                self::OPTION_MODE,
                'm',
                InputOption::VALUE_REQUIRED,
                'Modo: diagnose | consolidate | auto-map',
                'diagnose'
            )
            ->addOption(
                self::OPTION_PRODUCT_ID,
                null,
                InputOption::VALUE_OPTIONAL,
                'ID del producto configurable a migrar'
            )
            ->addOption(
                self::OPTION_ALL,
                null,
                InputOption::VALUE_NONE,
                'Migrar todos los configurables'
            )
            ->addOption(
                self::OPTION_DRY_RUN,
                null,
                InputOption::VALUE_NONE,
                'Solo reporte, sin cambios'
            )
            ->addOption(
                self::OPTION_CLEAN,
                null,
                InputOption::VALUE_NONE,
                'Eliminar imágenes existentes del configurable antes de consolidar'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        } catch (\Exception $e) {
            // Area code already set
        }

        $mode = $input->getOption(self::OPTION_MODE);
        $productId = $input->getOption(self::OPTION_PRODUCT_ID);
        $all = $input->getOption(self::OPTION_ALL);
        $dryRun = $input->getOption(self::OPTION_DRY_RUN);
        $clean = $input->getOption(self::OPTION_CLEAN);

        if (!in_array($mode, ['diagnose', 'consolidate', 'auto-map'], true)) {
            $output->writeln('<error>Modo inválido. Use: diagnose, consolidate, o auto-map</error>');
            return Cli::RETURN_FAILURE;
        }

        if (!$productId && !$all) {
            $output->writeln('<error>Debe especificar --product-id=ID o --all</error>');
            return Cli::RETURN_FAILURE;
        }

        $output->writeln('');
        $output->writeln(sprintf('<info>Rollpix ConfigurableGallery — Migración [%s]</info>', strtoupper($mode)));
        $output->writeln(str_repeat('=', 50));

        if ($dryRun) {
            $output->writeln('<comment>*** MODO DRY-RUN: No se realizarán cambios ***</comment>');
        }

        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToFilter('type_id', Configurable::TYPE_CODE);
        $collection->addAttributeToSelect('*');

        if ($productId !== null) {
            $collection->addIdFilter((int) $productId);
        }

        $totalProducts = $collection->getSize();
        $output->writeln(sprintf('Productos a procesar: %d', $totalProducts));
        $output->writeln('');

        foreach ($collection as $product) {
            match ($mode) {
                'diagnose' => $this->modeDiagnose($product, $output),
                'consolidate' => $this->modeConsolidate($product, $dryRun, $clean, $output),
                'auto-map' => $this->modeAutoMap($product, $dryRun, $output),
            };
        }

        return Cli::RETURN_SUCCESS;
    }

    /**
     * Diagnose mode: report current state of the product's images (PRD §11.2).
     */
    private function modeDiagnose(Product $product, OutputInterface $output): void
    {
        $output->writeln(sprintf(
            '<comment>%s (SKU: %s, ID: %d)</comment>',
            $product->getName(),
            $product->getSku(),
            $product->getId()
        ));

        // Images on configurable
        $configMapping = $this->colorMapping->getColorMediaMapping($product);
        $mappedCount = 0;
        $unmappedCount = 0;

        foreach ($configMapping as $key => $media) {
            $count = count($media['images'] ?? []) + count($media['videos'] ?? []);
            if ($key === 'null') {
                $unmappedCount += $count;
            } else {
                $mappedCount += $count;
            }
        }

        $output->writeln(sprintf('  Imágenes en configurable: %d', $mappedCount + $unmappedCount));
        $output->writeln(sprintf('    - Con mapping: %d', $mappedCount));
        $output->writeln(sprintf('    - Sin mapping: %d (genéricas)', $unmappedCount));

        // Images on simple children
        /** @var Configurable $typeInstance */
        $typeInstance = $product->getTypeInstance();
        $children = $typeInstance->getUsedProducts($product);
        $childImageCount = 0;
        $uniqueHashes = [];

        $connection = $this->resourceConnection->getConnection();
        $galleryTable = $this->resourceConnection->getTableName('catalog_product_entity_media_gallery');
        $toEntityTable = $this->resourceConnection->getTableName(
            'catalog_product_entity_media_gallery_value_to_entity'
        );

        foreach ($children as $child) {
            $select = $connection->select()
                ->from(['mg' => $galleryTable], ['value'])
                ->join(['mgvte' => $toEntityTable], 'mg.value_id = mgvte.value_id', [])
                ->where('mgvte.entity_id = ?', (int) $child->getId());

            $childImages = $connection->fetchCol($select);
            $childImageCount += count($childImages);

            foreach ($childImages as $imagePath) {
                $hash = md5($imagePath);
                $uniqueHashes[$hash] = true;
            }
        }

        $uniqueCount = count($uniqueHashes);
        $duplicateCount = $childImageCount - $uniqueCount;

        $output->writeln(sprintf('  Imágenes en simples: %d', $childImageCount));
        $output->writeln(sprintf('    - Únicas (por path): %d', $uniqueCount));
        $output->writeln(sprintf('    - Duplicadas: %d', $duplicateCount));

        // Existing associated_attributes data (Mango migration)
        $hasMangoData = $mappedCount > 0;
        $output->writeln(sprintf(
            '  Datos Mango existentes: %s',
            $hasMangoData ? 'Sí (' . $mappedCount . ' imágenes mapeadas)' : 'No'
        ));

        // Status recommendation
        $output->writeln('');
        if ($hasMangoData) {
            $output->writeln('  <info>Estado: ACTIVO Y CONFIGURADO</info>');
        } elseif ($childImageCount > 0 && !$hasMangoData) {
            $output->writeln('  <comment>Recomendación: Ejecutar --mode=consolidate para mover imágenes al padre</comment>');
        } elseif ($unmappedCount > 0 && $mappedCount === 0) {
            $output->writeln('  <comment>Recomendación: Ejecutar --mode=auto-map para mapear imágenes automáticamente</comment>');
        } else {
            $output->writeln('  <error>Estado: Sin imágenes para migrar</error>');
        }

        $output->writeln('');
    }

    /**
     * Consolidate mode: move images from simple children to configurable parent (PRD §11.1 Escenario B).
     * Deduplicates by file path.
     */
    private function modeConsolidate(
        Product $product,
        bool $dryRun,
        bool $clean,
        OutputInterface $output
    ): void {
        $output->writeln(sprintf(
            '<comment>Consolidando: %s (SKU: %s)</comment>',
            $product->getName(),
            $product->getSku()
        ));

        $colorAttributeCode = $this->attributeResolver->resolveForProduct($product);
        $colorAttributeId = $this->attributeResolver->resolveAttributeId($product);

        if ($colorAttributeCode === null || $colorAttributeId === null) {
            $output->writeln('  <error>No se pudo resolver atributo selector para este producto</error>');
            return;
        }

        /** @var Configurable $typeInstance */
        $typeInstance = $product->getTypeInstance();
        $children = $typeInstance->getUsedProducts($product);

        $connection = $this->resourceConnection->getConnection();
        $galleryTable = $this->resourceConnection->getTableName('catalog_product_entity_media_gallery');
        $toEntityTable = $this->resourceConnection->getTableName(
            'catalog_product_entity_media_gallery_value_to_entity'
        );
        $galleryValueTable = $this->resourceConnection->getTableName('catalog_product_entity_media_gallery_value');

        // Clean existing images from configurable parent if requested
        if ($clean) {
            $entityId = (int) $product->getId();
            $existingCount = (int) $connection->fetchOne(
                $connection->select()
                    ->from($toEntityTable, ['cnt' => new \Zend_Db_Expr('COUNT(*)')])
                    ->where('entity_id = ?', $entityId)
            );

            if ($existingCount > 0) {
                if ($dryRun) {
                    $output->writeln(sprintf(
                        '  WOULD CLEAN: %d imágenes existentes del configurable',
                        $existingCount
                    ));
                } else {
                    $connection->delete($galleryValueTable, ['entity_id = ?' => $entityId]);
                    $connection->delete($toEntityTable, ['entity_id = ?' => $entityId]);
                    $output->writeln(sprintf(
                        '  <info>CLEANED: %d imágenes eliminadas del configurable</info>',
                        $existingCount
                    ));
                }
            } else {
                $output->writeln('  No hay imágenes existentes en el configurable para limpiar');
            }
        }

        // Collect images per color from children — only from the FIRST child of each color
        // (other children of the same color have duplicate copies with _1, _2 suffixes)
        $imagesByColor = [];
        $seenColors = [];

        foreach ($children as $child) {
            $colorValue = $child->getData($colorAttributeCode);
            if ($colorValue === null) {
                continue;
            }
            $optionId = (int) $colorValue;

            // Skip if we already collected images for this color from another child
            if (isset($seenColors[$optionId])) {
                continue;
            }

            $select = $connection->select()
                ->from(['mg' => $galleryTable], ['value_id', 'value', 'media_type'])
                ->join(['mgvte' => $toEntityTable], 'mg.value_id = mgvte.value_id', [])
                ->where('mgvte.entity_id = ?', (int) $child->getId());

            $childImages = $connection->fetchAll($select);

            if (!empty($childImages)) {
                $seenColors[$optionId] = true;
                $imagesByColor[$optionId] = $childImages;
            }
        }

        $totalImages = array_sum(array_map('count', $imagesByColor));
        $output->writeln(sprintf('  Imágenes únicas encontradas en simples: %d', $totalImages));

        foreach ($imagesByColor as $optionId => $images) {
            $output->writeln(sprintf('    Color %d: %d imágenes', $optionId, count($images)));

            foreach ($images as $image) {
                $associatedAttributes = sprintf('attribute%d-%d', $colorAttributeId, $optionId);

                if ($dryRun) {
                    $output->writeln(sprintf(
                        '      WOULD: link %s → configurable con %s',
                        $image['value'],
                        $associatedAttributes
                    ));
                    continue;
                }

                // Link the image to the configurable parent
                try {
                    // Check if already linked to parent
                    $exists = $connection->fetchOne(
                        $connection->select()
                            ->from($toEntityTable, ['value_id'])
                            ->where('value_id = ?', (int) $image['value_id'])
                            ->where('entity_id = ?', (int) $product->getId())
                    );

                    if (!$exists) {
                        $connection->insert($toEntityTable, [
                            'value_id' => (int) $image['value_id'],
                            'entity_id' => (int) $product->getId(),
                        ]);
                    }

                    // Set the associated_attributes on the parent's gallery value
                    $existingValue = $connection->fetchOne(
                        $connection->select()
                            ->from($galleryValueTable, ['record_id'])
                            ->where('value_id = ?', (int) $image['value_id'])
                            ->where('store_id = ?', 0)
                    );

                    if ($existingValue) {
                        $connection->update(
                            $galleryValueTable,
                            ['associated_attributes' => $associatedAttributes],
                            ['value_id = ?' => (int) $image['value_id'], 'store_id = ?' => 0]
                        );
                    }

                    $output->writeln(sprintf(
                        '      LINKED %s → configurable con %s',
                        $image['value'],
                        $associatedAttributes
                    ));
                } catch (\Exception $e) {
                    $output->writeln(sprintf(
                        '      <error>ERROR: %s — %s</error>',
                        $image['value'],
                        $e->getMessage()
                    ));
                }
            }
        }

        $output->writeln('');
    }

    /**
     * Auto-map mode: automatically map images to colors by filename/label (PRD §11.1 Escenario C).
     */
    private function modeAutoMap(Product $product, bool $dryRun, OutputInterface $output): void
    {
        $output->writeln(sprintf(
            '<comment>Auto-mapping: %s (SKU: %s)</comment>',
            $product->getName(),
            $product->getSku()
        ));

        $colorLabels = $this->colorMapping->getColorOptionLabels($product);
        $colorAttributeId = $this->attributeResolver->resolveAttributeId($product);

        if ($colorAttributeId === null || empty($colorLabels)) {
            $output->writeln('  <error>No se pudo resolver atributo selector o colores del configurable</error>');
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $galleryTable = $this->resourceConnection->getTableName('catalog_product_entity_media_gallery');
        $galleryValueTable = $this->resourceConnection->getTableName('catalog_product_entity_media_gallery_value');
        $toEntityTable = $this->resourceConnection->getTableName(
            'catalog_product_entity_media_gallery_value_to_entity'
        );

        // Get all images of this product without associated_attributes
        $select = $connection->select()
            ->from(['mg' => $galleryTable], ['value_id', 'value'])
            ->join(['mgvte' => $toEntityTable], 'mg.value_id = mgvte.value_id', [])
            ->joinLeft(
                ['mgv' => $galleryValueTable],
                'mg.value_id = mgv.value_id AND mgv.store_id = 0',
                ['associated_attributes', 'label']
            )
            ->where('mgvte.entity_id = ?', (int) $product->getId())
            ->where('mgv.associated_attributes IS NULL OR mgv.associated_attributes = ?', '');

        $unmappedImages = $connection->fetchAll($select);

        $output->writeln(sprintf('  Imágenes sin mapping: %d', count($unmappedImages)));
        $output->writeln(sprintf('  Colores disponibles: %s', implode(', ', $colorLabels)));

        $mapped = 0;
        $unmapped = 0;

        // Build a lookup of color patterns (lowercase)
        $colorPatterns = [];
        foreach ($colorLabels as $optionId => $label) {
            $colorPatterns[mb_strtolower($label)] = $optionId;
            // Also add common variations
            $normalized = $this->normalizeColorName($label);
            if ($normalized !== mb_strtolower($label)) {
                $colorPatterns[$normalized] = $optionId;
            }
        }

        foreach ($unmappedImages as $image) {
            $filename = mb_strtolower(basename($image['value']));
            $label = mb_strtolower($image['label'] ?? '');

            $matchedOptionId = null;

            // Try matching by filename
            foreach ($colorPatterns as $pattern => $optionId) {
                if (str_contains($filename, $pattern) || str_contains($label, $pattern)) {
                    $matchedOptionId = $optionId;
                    break;
                }
            }

            if ($matchedOptionId !== null) {
                $associatedAttributes = sprintf('attribute%d-%d', $colorAttributeId, $matchedOptionId);
                $colorLabel = $colorLabels[$matchedOptionId] ?? '?';

                if ($dryRun) {
                    $output->writeln(sprintf(
                        '    WOULD MAP: %s → %s (%s)',
                        $image['value'],
                        $colorLabel,
                        $associatedAttributes
                    ));
                } else {
                    $connection->update(
                        $galleryValueTable,
                        ['associated_attributes' => $associatedAttributes],
                        ['value_id = ?' => (int) $image['value_id'], 'store_id = ?' => 0]
                    );
                    $output->writeln(sprintf(
                        '    MAPPED: %s → %s (%s)',
                        $image['value'],
                        $colorLabel,
                        $associatedAttributes
                    ));
                }
                $mapped++;
            } else {
                $output->writeln(sprintf(
                    '    <comment>NO MATCH: %s (asignar manualmente)</comment>',
                    $image['value']
                ));
                $unmapped++;
            }
        }

        $output->writeln('');
        $output->writeln(sprintf('  Mapeadas automáticamente: %d', $mapped));
        $output->writeln(sprintf('  Sin match (manual): %d', $unmapped));

        $output->writeln('');
    }

    /**
     * Normalize color name for fuzzy matching.
     */
    private function normalizeColorName(string $name): string
    {
        $normalized = mb_strtolower(trim($name));
        // Remove accents (common in Spanish color names)
        $normalized = str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ñ', 'ü'],
            ['a', 'e', 'i', 'o', 'u', 'n', 'u'],
            $normalized
        );
        // Remove common suffixes/prefixes
        $normalized = preg_replace('/\s+/', '-', $normalized) ?? $normalized;
        return $normalized;
    }
}
