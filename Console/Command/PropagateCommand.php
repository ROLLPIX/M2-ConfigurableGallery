<?php

declare(strict_types=1);

namespace Rollpix\ConfigurableGallery\Console\Command;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Rollpix\ConfigurableGallery\Model\Config;
use Rollpix\ConfigurableGallery\Model\Propagation;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI command: bin/magento rollpix:gallery:propagate (PRD §10.1)
 *
 * Propagates images from configurable parents to simple children based on color mapping.
 */
class PropagateCommand extends Command
{
    private const OPTION_PRODUCT_ID = 'product-id';
    private const OPTION_ALL = 'all';
    private const OPTION_DRY_RUN = 'dry-run';
    private const OPTION_CLEAN_FIRST = 'clean-first';

    public function __construct(
        private readonly Config $config,
        private readonly Propagation $propagation,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly State $appState
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('rollpix:gallery:propagate')
            ->setDescription('Propaga imágenes del configurable a los simples según mapping de color')
            ->addOption(
                self::OPTION_PRODUCT_ID,
                null,
                InputOption::VALUE_OPTIONAL,
                'ID del producto configurable a propagar'
            )
            ->addOption(
                self::OPTION_ALL,
                null,
                InputOption::VALUE_NONE,
                'Propagar todos los productos configurables'
            )
            ->addOption(
                self::OPTION_DRY_RUN,
                null,
                InputOption::VALUE_NONE,
                'Solo mostrar qué haría sin ejecutar cambios'
            )
            ->addOption(
                self::OPTION_CLEAN_FIRST,
                null,
                InputOption::VALUE_NONE,
                'Limpiar imágenes propagadas anteriormente antes de re-propagar'
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
        $cleanFirst = $input->getOption(self::OPTION_CLEAN_FIRST);

        if (!$productId && !$all) {
            $output->writeln('<error>Debe especificar --product-id=ID o --all</error>');
            return Cli::RETURN_FAILURE;
        }

        if ($dryRun) {
            $output->writeln('<comment>*** MODO DRY-RUN: No se realizarán cambios ***</comment>');
        }

        $output->writeln('');
        $output->writeln('<info>Rollpix ConfigurableGallery — Propagación</info>');
        $output->writeln(str_repeat('=', 50));

        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToFilter('type_id', Configurable::TYPE_CODE);
        $collection->addAttributeToSelect('*');

        if ($productId !== null) {
            $collection->addIdFilter((int) $productId);
        }

        $totalProducts = $collection->getSize();
        $output->writeln(sprintf('Productos a procesar: %d', $totalProducts));
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

            $report = $this->propagation->propagate($product, $dryRun, $cleanFirst ?: null);

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

        $output->writeln(str_repeat('-', 50));
        $output->writeln(sprintf('Total acciones: %d', $totalActions));
        if ($totalErrors > 0) {
            $output->writeln(sprintf('<error>Total errores: %d</error>', $totalErrors));
        }

        return $totalErrors > 0 ? Cli::RETURN_FAILURE : Cli::RETURN_SUCCESS;
    }
}
