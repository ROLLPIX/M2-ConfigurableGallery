<?php

declare(strict_types=1);

namespace Rollpix\ConfigurableGallery\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * No-op observer kept for backward compatibility.
 *
 * Auto-propagation was moved to AdminGallerySavePlugin::afterSave() because
 * the catalog_product_save_after event fires INSIDE Product::save(), before
 * the plugin's afterSave() runs. This caused the gallery change detection
 * flag to never be set when the observer checked it.
 */
class ProductSaveAfterObserver implements ObserverInterface
{
    public function execute(Observer $observer): void
    {
        // Propagation is now triggered by AdminGallerySavePlugin::afterSave()
    }
}
