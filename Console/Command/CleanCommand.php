<?php

declare(strict_types=1);

namespace Rollpix\ConfigurableGallery\Console\Command;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Rollpix\ConfigurableGallery\Model\Propagation;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI command: bin/magento rollpix:gallery:clean
 *
 * Removes ALL images from simple children of configurable products.
 * Useful for debugging and resetting propagated images.
 */
class CleanCommand extends Command
{
    private const OPTION_PRODUCT_ID = 'product-id';
    private const OPTION_ALL = 'all';
    private const OPTION_DRY_RUN = 'dry-run';

    public function __construct(
        private readonly Propagation $propagation,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly State $appState
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('rollpix:gallery:clean')
            ->setDescription('Elimina TODAS las imágenes de los simples hijos de un configurable')
            ->addOption(
                self::OPTION_PRODUCT_ID,
                null,
                InputOption::VALUE_OPTIONAL,
                'ID del producto configurable'
            )
            ->addOption(
                self::OPTION_ALL,
                null,
                InputOption::VALUE_NONE,
                'Limpiar simples de todos los productos configurables'
            )
            ->addOption(
                self::OPTION_DRY_RUN,
                null,
                InputOption::VALUE_NONE,
                'Solo mostrar qué haría sin ejecutar cambios'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        } catch (\Exception $e) {
            // Area code already set
        }

        $productId = $input->getOption(self::OPTION_PRODUCT_ID);
        $all = $input->getOption(self::OPTION_ALL);
        $dryRun = $input->getOption(self::OPTION_DRY_RUN);

        if (!$productId && !$all) {
            $output->writeln('<error>Debe especificar --product-id=ID o --all</error>');
            return Cli::RETURN_FAILURE;
        }

        if ($dryRun) {
            $output->writeln('<comment>*** MODO DRY-RUN: No se realizarán cambios ***</comment>');
        }

        $output->writeln('');
        $output->writeln('<info>Rollpix ConfigurableGallery — Clean (eliminar imágenes de simples)</info>');
        $output->writeln(str_repeat('=', 60));

        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToFilter('type_id', Configurable::TYPE_CODE);
        $collection->addAttributeToSelect(['name', 'sku']);

        if ($productId !== null) {
            $collection->addIdFilter((int) $productId);
        }

        $totalProducts = $collection->getSize();
        $output->writeln(sprintf('Configurables a procesar: %d', $totalProducts));
        $output->writeln('');

        $totalActions = 0;
        $totalErrors = 0;

        foreach ($collection as $product) {
            $output->writeln(sprintf(
                '<comment>Procesando: %s (SKU: %s, ID: %d)</comment>',
                $product->getName(),
                $product->getSku(),
                $product->getId()
            ));

            $report = $this->propagation->cleanChildren($product, $dryRun);

            foreach ($report['actions'] as $action) {
                $output->writeln('  ' . $action);
                $totalActions++;
            }

            foreach ($report['errors'] as $error) {
                $output->writeln('  <error>' . $error . '</error>');
                $totalErrors++;
            }

            $output->writeln('');
        }

        $output->writeln(str_repeat('-', 60));
        $output->writeln(sprintf('Total acciones: %d', $totalActions));
        if ($totalErrors > 0) {
            $output->writeln(sprintf('<error>Total errores: %d</error>', $totalErrors));
        }

        return $totalErrors > 0 ? Cli::RETURN_FAILURE : Cli::RETURN_SUCCESS;
    }
}
