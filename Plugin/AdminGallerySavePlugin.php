<?php

declare(strict_types=1);

namespace Rollpix\ConfigurableGallery\Plugin;

use Magento\Catalog\Model\Product;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;
use Rollpix\ConfigurableGallery\Model\Config;

/**
 * Persists color mapping (associated_attributes) when saving a product in admin.
 * PRD §6.2 — Reads the color mapping data from the request and writes to DB.
 *
 * sortOrder=10: base plugin for admin product save.
 */
class AdminGallerySavePlugin
{
    public function __construct(
        private readonly Config $config,
        private readonly ResourceConnection $resourceConnection,
        private readonly RequestInterface $request,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * After product save, persist the associated_attributes mapping for each media gallery entry.
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

        if ((int) $result->getData('rollpix_gallery_enabled') !== 1) {
            return $result;
        }

        $mediaGallery = $result->getData('media_gallery');
        if (!is_array($mediaGallery) || !isset($mediaGallery['images'])) {
            return $result;
        }

        // Also check for rollpix color mapping data in the request
        $colorMappingData = $this->request->getParam('rollpix_color_mapping');

        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('catalog_product_entity_media_gallery_value');

        foreach ($mediaGallery['images'] as $image) {
            $valueId = $image['value_id'] ?? null;
            if ($valueId === null) {
                continue;
            }

            $associatedAttributes = null;

            // Priority 1: rollpix_color_mapping from request (admin UI dropdown)
            if (is_array($colorMappingData) && isset($colorMappingData[$valueId])) {
                $associatedAttributes = $colorMappingData[$valueId];
                if ($associatedAttributes === '' || $associatedAttributes === '0') {
                    $associatedAttributes = null;
                }
            }
            // Priority 2: associated_attributes already in the image data
            elseif (isset($image['associated_attributes'])) {
                $associatedAttributes = $image['associated_attributes'];
                if ($associatedAttributes === '') {
                    $associatedAttributes = null;
                }
            }

            // Update the column
            $connection->update(
                $tableName,
                ['associated_attributes' => $associatedAttributes],
                ['value_id = ?' => (int) $valueId]
            );
        }

        if ($this->config->isDebugMode()) {
            $this->logger->debug('Rollpix ConfigurableGallery: Saved color mapping for product', [
                'product_id' => $result->getId(),
                'image_count' => count($mediaGallery['images']),
            ]);
        }

        return $result;
    }
}
