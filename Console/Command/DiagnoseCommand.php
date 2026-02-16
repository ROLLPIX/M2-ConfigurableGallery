<?php

declare(strict_types=1);

namespace Rollpix\ConfigurableGallery\Console\Command;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Rollpix\ConfigurableGallery\Model\ColorMapping;
use Rollpix\ConfigurableGallery\Model\Config;
use Rollpix\ConfigurableGallery\Model\StockFilter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI command: bin/magento rollpix:gallery:diagnose
 * Reports the state of configurable products regarding gallery mapping (PRD §11.2).
 */
class DiagnoseCommand extends Command
{
    private const OPTION_PRODUCT_ID = 'product-id';
    private const OPTION_ALL = 'all';

    public function __construct(
        private readonly Config $config,
        private readonly ColorMapping $colorMapping,
        private readonly StockFilter $stockFilter,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly State $appState
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('rollpix:gallery:diagnose')
            ->setDescription('Diagnostica el estado de la galería de productos configurables')
            ->addOption(
                self::OPTION_PRODUCT_ID,
                null,
                InputOption::VALUE_OPTIONAL,
                'ID del producto configurable a diagnosticar'
            )
            ->addOption(
                self::OPTION_ALL,
                null,
                InputOption::VALUE_NONE,
                'Diagnosticar todos los configurables con rollpix_gallery_enabled=1'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        } catch (\Exception $e) {
            // Area code already set
        }

        $output->writeln('');
        $output->writeln('<info>Rollpix ConfigurableGallery — Diagnóstico</info>');
        $output->writeln(str_repeat('=', 50));

        // Global config info
        $this->outputGlobalConfig($output);

        $productId = $input->getOption(self::OPTION_PRODUCT_ID);
        $all = $input->getOption(self::OPTION_ALL);

        if ($productId !== null) {
            $this->diagnoseProduct((int) $productId, $output);
        } elseif ($all) {
            $this->diagnoseAll($output);
        } else {
            $this->outputSummary($output);
        }

        $output->writeln('');
        return Cli::RETURN_SUCCESS;
    }

    private function outputGlobalConfig(OutputInterface $output): void
    {
        $output->writeln('');
        $output->writeln('<comment>Configuración Global:</comment>');
        $output->writeln(sprintf('  Módulo habilitado: %s', $this->config->isEnabled() ? 'Sí' : 'No'));
        $output->writeln(sprintf('  Atributo de color: %s', $this->config->getColorAttributeCode()));
        $output->writeln(sprintf('  Filtro de stock: %s', $this->config->isStockFilterEnabled() ? 'Sí' : 'No'));
        $output->writeln(sprintf('  Propagación: %s', $this->config->getPropagationMode()));
        $output->writeln(sprintf('  Adaptador galería: %s', $this->config->getGalleryAdapter()));
        $output->writeln(sprintf('  Preselección color: %s', $this->config->isPreselectColorEnabled() ? 'Sí' : 'No'));
        $output->writeln(sprintf('  Deep link: %s', $this->config->isDeepLinkEnabled() ? 'Sí' : 'No'));
        $output->writeln(sprintf('  Debug mode: %s', $this->config->isDebugMode() ? 'Sí' : 'No'));
    }

    private function outputSummary(OutputInterface $output): void
    {
        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToFilter('type_id', Configurable::TYPE_CODE);

        $totalConfigurables = $collection->getSize();

        $enabledCollection = $this->productCollectionFactory->create();
        $enabledCollection->addAttributeToFilter('type_id', Configurable::TYPE_CODE);
        $enabledCollection->addAttributeToFilter('rollpix_gallery_enabled', 1);

        $enabledCount = $enabledCollection->getSize();

        $output->writeln('');
        $output->writeln('<comment>Resumen del Catálogo:</comment>');
        $output->writeln(sprintf('  Total configurables: %d', $totalConfigurables));
        $output->writeln(sprintf('  Con Rollpix Gallery habilitada: %d', $enabledCount));
        $output->writeln(sprintf('  Sin habilitar: %d', $totalConfigurables - $enabledCount));

        if ($enabledCount > 0) {
            $output->writeln('');
            $output->writeln('<info>Use --all para diagnosticar todos, o --product-id=ID para uno específico.</info>');
        }
    }

    private function diagnoseAll(OutputInterface $output): void
    {
        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToFilter('type_id', Configurable::TYPE_CODE);
        $collection->addAttributeToFilter('rollpix_gallery_enabled', 1);
        $collection->addAttributeToSelect(['name', 'sku', 'rollpix_gallery_enabled', 'rollpix_default_color']);

        $count = $collection->getSize();
        $output->writeln('');
        $output->writeln(sprintf('<comment>Diagnosticando %d productos...</comment>', $count));

        $table = new Table($output);
        $table->setHeaders(['ID', 'SKU', 'Nombre', 'Colores', 'Imgs Mapeadas', 'Videos', 'Genéricas', 'Estado']);

        foreach ($collection as $product) {
            $this->addProductToTable($product, $table, $output);
        }

        $table->render();
    }

    private function diagnoseProduct(int $productId, OutputInterface $output): void
    {
        $collection = $this->productCollectionFactory->create();
        $collection->addIdFilter($productId);
        $collection->addAttributeToSelect('*');
        $product = $collection->getFirstItem();

        if (!$product->getId()) {
            $output->writeln(sprintf('<error>Producto ID %d no encontrado.</error>', $productId));
            return;
        }

        if ($product->getTypeId() !== Configurable::TYPE_CODE) {
            $output->writeln(sprintf(
                '<error>Producto ID %d no es configurable (tipo: %s).</error>',
                $productId,
                $product->getTypeId()
            ));
            return;
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '<comment>Producto: %s (SKU: %s)</comment>',
            $product->getName(),
            $product->getSku()
        ));

        $enabled = (int) $product->getData('rollpix_gallery_enabled') === 1;
        $output->writeln(sprintf('  Rollpix Gallery: %s', $enabled ? '<info>Habilitada</info>' : '<error>Deshabilitada</error>'));

        $defaultColor = $product->getData('rollpix_default_color');
        $output->writeln(sprintf('  Color default: %s', $defaultColor ?: 'Auto-detect'));

        // Color mapping
        $colorLabels = $this->colorMapping->getColorOptionLabels($product);
        $mediaMapping = $this->colorMapping->getColorMediaMapping($product);

        $output->writeln(sprintf('  Colores configurados: %d', count($colorLabels)));
        foreach ($colorLabels as $optionId => $label) {
            $key = (string) $optionId;
            $imgCount = isset($mediaMapping[$key]) ? count($mediaMapping[$key]['images'] ?? []) : 0;
            $vidCount = isset($mediaMapping[$key]) ? count($mediaMapping[$key]['videos'] ?? []) : 0;

            $output->writeln(sprintf(
                '    - %s (ID: %d): %d imgs, %d videos',
                $label,
                $optionId,
                $imgCount,
                $vidCount
            ));
        }

        // Generic images
        $genericImgs = isset($mediaMapping['null']) ? count($mediaMapping['null']['images'] ?? []) : 0;
        $genericVids = isset($mediaMapping['null']) ? count($mediaMapping['null']['videos'] ?? []) : 0;
        $output->writeln(sprintf('  Imágenes genéricas: %d imgs, %d videos', $genericImgs, $genericVids));

        // Stock status
        if ($this->config->isStockFilterEnabled()) {
            $colorsWithStock = $this->stockFilter->getColorsWithStock($product);
            $output->writeln('  Estado de stock:');
            foreach ($colorLabels as $optionId => $label) {
                $hasStock = in_array($optionId, $colorsWithStock, true);
                $output->writeln(sprintf(
                    '    - %s: %s',
                    $label,
                    $hasStock ? '<info>Con stock</info>' : '<error>Sin stock</error>'
                ));
            }
        }

        // Overall status
        $totalMapped = 0;
        foreach ($mediaMapping as $key => $media) {
            if ($key !== 'null') {
                $totalMapped += count($media['images'] ?? []) + count($media['videos'] ?? []);
            }
        }

        $output->writeln('');
        if ($totalMapped > 0 && $enabled) {
            $output->writeln('  <info>Estado: LISTO PARA USAR</info>');
        } elseif ($totalMapped > 0 && !$enabled) {
            $output->writeln('  <comment>Estado: MAPPING EXISTE, FALTA HABILITAR rollpix_gallery_enabled</comment>');
        } else {
            $output->writeln('  <error>Estado: SIN MAPPING — Asignar colores a imágenes en admin</error>');
        }
    }

    private function addProductToTable(Product $product, Table $table, OutputInterface $output): void
    {
        $mediaMapping = $this->colorMapping->getColorMediaMapping($product);

        $totalImages = 0;
        $totalVideos = 0;
        $genericCount = 0;
        $colorCount = 0;

        foreach ($mediaMapping as $key => $media) {
            $imgs = count($media['images'] ?? []);
            $vids = count($media['videos'] ?? []);

            if ($key === 'null') {
                $genericCount = $imgs + $vids;
            } else {
                $totalImages += $imgs;
                $totalVideos += $vids;
                $colorCount++;
            }
        }

        $status = ($totalImages + $totalVideos) > 0 ? 'OK' : 'SIN MAPPING';

        $table->addRow([
            $product->getId(),
            $product->getSku(),
            mb_substr($product->getName() ?? '', 0, 30),
            $colorCount,
            $totalImages,
            $totalVideos,
            $genericCount,
            $status,
        ]);
    }
}
