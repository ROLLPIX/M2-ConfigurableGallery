<?php

declare(strict_types=1);

namespace Rollpix\ConfigurableGallery\Plugin;

use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;
use Rollpix\ConfigurableGallery\Model\Config;
use Rollpix\ConfigurableGallery\Model\Propagation;

/**
 * Persists color mapping (associated_attributes) when saving a product in admin.
 * PRD §6.2 — Reads the color mapping data from the request and writes to DB.
 *
 * Also detects gallery changes (new/removed images, color mapping modifications)
 * and triggers automatic propagation when enabled. Propagation runs here
 * (not in an observer) because this plugin executes AFTER Product::save()
 * completes, ensuring all gallery data is persisted before propagation.
 *
 * sortOrder=10: base plugin for admin product save.
 */
class AdminGallerySavePlugin
{
    public function __construct(
        private readonly Config $config,
        private readonly Propagation $propagation,
        private readonly ResourceConnection $resourceConnection,
        private readonly RequestInterface $request,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * After product save, persist the associated_attributes mapping for each media gallery entry.
     * Detects if gallery actually changed and flags the product for the observer.
     *
     * @param Product $subject
     * @param Product $result
     * @return Product
     */
    public function afterSave(Product $subject, Product $result): Product
    {
        if (!$this->config->isEnabled()) {
            return $result;
        }

        $mediaGallery = $result->getData('media_gallery');
        if (!is_array($mediaGallery) || !isset($mediaGallery['images'])) {
            return $result;
        }

        $galleryChanged = false;

        // Detect new or removed images
        foreach ($mediaGallery['images'] as $image) {
            if (!empty($image['new_file'])) {
                $galleryChanged = true;
                break;
            }
            if (!empty($image['removed'])) {
                $galleryChanged = true;
                break;
            }
        }

        // Process color mapping data from admin UI
        $colorMappingData = $this->request->getParam('rollpix_color_mapping');

        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('catalog_product_entity_media_gallery_value');

        // Track which value_ids were explicitly handled via rollpix_color_mapping
        $handledValueIds = [];

        // Only proceed with color mapping update if we have explicit data from the admin UI.
        // Without this check, every product save would wipe existing mappings.
        if (is_array($colorMappingData) && !empty($colorMappingData)) {
            foreach ($mediaGallery['images'] as $image) {
                $valueId = $image['value_id'] ?? null;
                if ($valueId === null) {
                    continue;
                }

                if (!isset($colorMappingData[$valueId])) {
                    continue;
                }

                $handledValueIds[] = (int) $valueId;

                $associatedAttributes = $colorMappingData[$valueId];
                if ($associatedAttributes === '' || $associatedAttributes === '0') {
                    $associatedAttributes = null;
                }

                // update() returns number of rows whose values actually changed
                // (MySQL reports 0 affected rows when new value equals old value)
                $rowsAffected = $connection->update(
                    $tableName,
                    ['associated_attributes' => $associatedAttributes],
                    ['value_id = ?' => (int) $valueId]
                );

                if ($rowsAffected > 0) {
                    $galleryChanged = true;
                }
            }
        }

        // Fallback: for new images, the rollpix_color_mapping keys may use temp hashes
        // that don't match the real value_ids assigned during Product::save().
        // However, the image's own form data may contain the associated_attributes
        // value (set by JS auto-detect from filename). Persist it as a fallback.
        foreach ($mediaGallery['images'] as $image) {
            $valueId = $image['value_id'] ?? null;
            if ($valueId === null || !empty($image['removed'])) {
                continue;
            }
            if (in_array((int) $valueId, $handledValueIds, true)) {
                continue;
            }

            $formValue = $image['associated_attributes'] ?? null;
            if ($formValue !== null && $formValue !== '' && $formValue !== '0') {
                $rowsAffected = $connection->update(
                    $tableName,
                    ['associated_attributes' => $formValue],
                    ['value_id = ?' => (int) $valueId]
                );
                if ($rowsAffected > 0) {
                    $galleryChanged = true;
                }
            }
        }

        if ($this->config->isDebugMode()) {
            $this->logger->debug('Rollpix ConfigurableGallery: Saved color mapping for product', [
                'product_id' => $result->getId(),
                'image_count' => count($mediaGallery['images']),
                'gallery_changed' => $galleryChanged,
            ]);
        }

        // Trigger automatic propagation if gallery changed and product is configurable
        if ($galleryChanged && $result->getTypeId() === Configurable::TYPE_CODE) {
            $this->triggerPropagation($result);
        }

        return $result;
    }

    /**
     * Trigger automatic propagation for a configurable product.
     * Only runs when propagation_mode = "automatic".
     */
    private function triggerPropagation(Product $product): void
    {
        $storeId = $product->getStoreId();

        if ($this->config->getPropagationMode($storeId) !== 'automatic') {
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
