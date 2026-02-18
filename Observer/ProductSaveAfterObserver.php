<?php

declare(strict_types=1);

namespace Rollpix\ConfigurableGallery\Observer;

use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;
use Rollpix\ConfigurableGallery\Model\Config;
use Rollpix\ConfigurableGallery\Model\Propagation;

/**
 * Triggers automatic image propagation when a configurable product is saved
 * and propagation_mode = "automatic" (PRD §6.3).
 *
 * Only propagates when AdminGallerySavePlugin detected actual gallery changes
 * (new/removed images or color mapping modifications). This avoids expensive
 * propagation runs when only price, stock, or other non-gallery fields changed.
 */
class ProductSaveAfterObserver implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly Propagation $propagation,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $product = $observer->getEvent()->getProduct();
        if ($product === null) {
            return;
        }

        if ($product->getTypeId() !== Configurable::TYPE_CODE) {
            return;
        }

        $storeId = $product->getStoreId();

        if (!$this->config->isEnabled($storeId)) {
            return;
        }

        if ($this->config->getPropagationMode($storeId) !== 'automatic') {
            return;
        }

        // Skip propagation if no gallery changes were detected by AdminGallerySavePlugin.
        // This prevents expensive propagation on saves that only update price, stock, etc.
        if (!$product->getData('rollpix_gallery_changed')) {
            if ($this->config->isDebugMode($storeId)) {
                $this->logger->debug('Rollpix ConfigurableGallery: Skip auto-propagation (no gallery changes)', [
                    'product_id' => $product->getId(),
                    'sku' => $product->getSku(),
                ]);
            }
            return;
        }

        try {
            $report = $this->propagation->propagate($product);

            $actionCount = count($report['actions']);
            $errorCount = count($report['errors']);

            if ($actionCount > 0 || $errorCount > 0) {
                $this->logger->info('Rollpix ConfigurableGallery: Auto-propagation on save', [
                    'product_id' => $product->getId(),
                    'sku' => $product->getSku(),
                    'actions' => $actionCount,
                    'errors' => $errorCount,
                ]);
            }

            if ($errorCount > 0) {
                $this->logger->warning('Rollpix ConfigurableGallery: Auto-propagation errors', [
                    'product_id' => $product->getId(),
                    'errors' => $report['errors'],
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Rollpix ConfigurableGallery: Auto-propagation failed', [
                'product_id' => $product->getId(),
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
