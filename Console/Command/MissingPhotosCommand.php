<?php

declare(strict_types=1);

namespace Rollpix\ConfigurableGallery\Console\Command;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Rollpix\ConfigurableGallery\Model\ColorMapping;
use Rollpix\ConfigurableGallery\Model\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI command: bin/magento rollpix:gallery:missing-photos
 * Finds configurable products that have color values without assigned photos.
 */
class MissingPhotosCommand extends Command
{
    private const OPTION_PRODUCT_ID = 'product-id';
    private const OPTION_ALL = 'all';
    private const OPTION_CSV = 'csv';

    public function __construct(
        private readonly Config $config,
        private readonly ColorMapping $colorMapping,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly State $appState
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('rollpix:gallery:missing-photos')
            ->setDescription('Detecta productos configurables con colores sin fotos asignadas')
            ->addOption(
                self::OPTION_PRODUCT_ID,
                null,
                InputOption::VALUE_OPTIONAL,
                'ID del producto configurable a verificar'
            )
            ->addOption(
                self::OPTION_ALL,
                null,
                InputOption::VALUE_NONE,
                'Verificar todos los productos configurables'
            )
            ->addOption(
                self::OPTION_CSV,
                null,
                InputOption::VALUE_NONE,
                'Salida en formato CSV (para redirigir a archivo: --csv > output.csv)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        } catch (\Exception $e) {
            // Area code already set
        }

        $csv = $input->getOption(self::OPTION_CSV);

        if (!$csv) {
            $output->writeln('');
            $output->writeln('<info>Rollpix ConfigurableGallery — Colores sin fotos</info>');
            $output->writeln(str_repeat('=', 50));
        }

        $productId = $input->getOption(self::OPTION_PRODUCT_ID);
        $all = $input->getOption(self::OPTION_ALL);
        $csv = $input->getOption(self::OPTION_CSV);

        if ($productId !== null) {
            if ($csv) {
                $this->checkAllCsv($output, (int) $productId);
            } else {
                $this->checkProduct((int) $productId, $output);
            }
        } elseif ($all) {
            if ($csv) {
                $this->checkAllCsv($output);
            } else {
                $this->checkAll($output);
            }
        } else {
            $output->writeln('');
            $output->writeln('<info>Use --all para verificar todos, o --product-id=ID para uno específico.</info>');
        }

        if (!$csv) {
            $output->writeln('');
        }
        return Cli::RETURN_SUCCESS;
    }

    private function checkAll(OutputInterface $output): void
    {
        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToFilter('type_id', Configurable::TYPE_CODE);
        $collection->addAttributeToSelect(['name', 'sku']);

        $count = $collection->getSize();
        $output->writeln('');
        $output->writeln(sprintf('<comment>Verificando %d productos configurables...</comment>', $count));

        $table = new Table($output);
        $table->setHeaders(['ID', 'SKU', 'Nombre', 'Colores sin fotos']);

        $foundIssues = 0;

        foreach ($collection as $product) {
            $missing = $this->getMissingColors($product);
            if (!empty($missing)) {
                $table->addRow([
                    $product->getId(),
                    $product->getSku(),
                    mb_substr($product->getName() ?? '', 0, 30),
                    implode(', ', $missing),
                ]);
                $foundIssues++;
            }
        }

        if ($foundIssues > 0) {
            $output->writeln('');
            $table->render();
            $output->writeln('');
            $output->writeln(sprintf('<error>%d producto(s) con colores sin fotos.</error>', $foundIssues));
        } else {
            $output->writeln('');
            $output->writeln('<info>Todos los productos tienen fotos para todos sus colores.</info>');
        }
    }

    private function checkProduct(int $productId, OutputInterface $output): void
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

        $colorLabels = $this->colorMapping->getColorOptionLabels($product);
        $mediaMapping = $this->colorMapping->getColorMediaMapping($product);

        if (empty($colorLabels)) {
            $output->writeln('  <error>No se encontraron colores configurados.</error>');
            return;
        }

        $output->writeln('');
        $allGood = true;

        foreach ($colorLabels as $optionId => $label) {
            $key = (string) $optionId;
            $imgCount = isset($mediaMapping[$key]) ? count($mediaMapping[$key]['images'] ?? []) : 0;

            if ($imgCount > 0) {
                $output->writeln(sprintf(
                    '  <info>✓</info> %s (ID: %d): %d foto(s)',
                    $label,
                    $optionId,
                    $imgCount
                ));
            } else {
                $output->writeln(sprintf(
                    '  <error>✗</error> %s (ID: %d): <error>SIN FOTOS</error>',
                    $label,
                    $optionId
                ));
                $allGood = false;
            }
        }

        $output->writeln('');
        if ($allGood) {
            $output->writeln('  <info>Todos los colores tienen fotos asignadas.</info>');
        } else {
            $output->writeln('  <error>Hay colores sin fotos asignadas.</error>');
        }
    }

    /**
     * CSV output mode: one row per product+color combination without photos.
     * Output is clean CSV (no Symfony formatting) for easy redirect to file.
     */
    private function checkAllCsv(OutputInterface $output, ?int $filterProductId = null): void
    {
        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToFilter('type_id', Configurable::TYPE_CODE);
        $collection->addAttributeToSelect(['name', 'sku']);

        if ($filterProductId !== null) {
            $collection->addIdFilter($filterProductId);
        }

        // CSV header
        $output->writeln($this->csvLine(['ID', 'SKU', 'Nombre', 'Color_ID', 'Color', 'Fotos']));

        foreach ($collection as $product) {
            $colorLabels = $this->colorMapping->getColorOptionLabels($product);
            if (empty($colorLabels)) {
                continue;
            }

            $mediaMapping = $this->colorMapping->getColorMediaMapping($product);

            foreach ($colorLabels as $optionId => $label) {
                $key = (string) $optionId;
                $imgCount = isset($mediaMapping[$key]) ? count($mediaMapping[$key]['images'] ?? []) : 0;

                if ($imgCount === 0) {
                    $output->writeln($this->csvLine([
                        $product->getId(),
                        $product->getSku(),
                        $product->getName() ?? '',
                        $optionId,
                        $label,
                        $imgCount,
                    ]));
                }
            }
        }
    }

    /**
     * Build a CSV line from an array of values.
     */
    private function csvLine(array $fields): string
    {
        $escaped = [];
        foreach ($fields as $field) {
            $value = (string) $field;
            if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
                $value = '"' . str_replace('"', '""', $value) . '"';
            }
            $escaped[] = $value;
        }
        return implode(',', $escaped);
    }

    /**
     * Get list of color labels that have no photos for a product.
     *
     * @return string[] Color labels missing photos
     */
    private function getMissingColors(Product $product): array
    {
        $colorLabels = $this->colorMapping->getColorOptionLabels($product);
        if (empty($colorLabels)) {
            return [];
        }

        $mediaMapping = $this->colorMapping->getColorMediaMapping($product);
        $missing = [];

        foreach ($colorLabels as $optionId => $label) {
            $key = (string) $optionId;
            $imgCount = isset($mediaMapping[$key]) ? count($mediaMapping[$key]['images'] ?? []) : 0;

            if ($imgCount === 0) {
                $missing[] = $label;
            }
        }

        return $missing;
    }
}
