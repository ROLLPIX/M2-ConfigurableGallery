<?php

declare(strict_types=1);

namespace Rollpix\ConfigurableGallery\Plugin;

use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Normalizer;
use Psr\Log\LoggerInterface;
use Rollpix\ConfigurableGallery\Model\AttributeResolver;
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
        private readonly AttributeResolver $attributeResolver,
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

        // Track which real DB value_ids were explicitly handled via rollpix_color_mapping.
        // Only valid positive integers are tracked (temp hash IDs cast to 0 are excluded).
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

                $intValueId = (int) $valueId;

                $associatedAttributes = $colorMappingData[$valueId];
                if ($associatedAttributes === '' || $associatedAttributes === '0') {
                    $associatedAttributes = null;
                }

                // For new images, (int) "tempHash" = 0 → UPDATE WHERE value_id=0 matches nothing.
                // Only execute UPDATE and mark as handled for real positive DB value_ids.
                if ($intValueId <= 0) {
                    continue;
                }

                $handledValueIds[] = $intValueId;

                $rowsAffected = $connection->update(
                    $tableName,
                    ['associated_attributes' => $associatedAttributes],
                    ['value_id = ?' => $intValueId]
                );

                if ($rowsAffected > 0) {
                    $galleryChanged = true;
                }
            }
        }

        // Server-side auto-detect: for images that still have no associated_attributes
        // in the DB, detect color from filename. This is the reliable fallback that
        // doesn't depend on JS data transmission or temp-hash-to-real-ID matching.
        if ($result->getTypeId() === Configurable::TYPE_CODE) {
            $autoDetected = $this->autoDetectColorForNewImages(
                $result,
                $connection,
                $tableName,
                $handledValueIds
            );
            if ($autoDetected > 0) {
                $galleryChanged = true;
            }
        }

        if ($this->config->isDebugMode()) {
            $this->logger->debug('Rollpix ConfigurableGallery: Saved color mapping for product', [
                'product_id' => $result->getId(),
                'image_count' => count($mediaGallery['images']),
                'gallery_changed' => $galleryChanged,
                'handled_by_value_id' => count($handledValueIds),
                'auto_detected' => $autoDetected ?? 0,
            ]);
        }

        // Trigger automatic propagation if gallery changed and product is configurable
        if ($galleryChanged && $result->getTypeId() === Configurable::TYPE_CODE) {
            $this->triggerPropagation($result);
        }

        return $result;
    }

    /**
     * Server-side auto-detect color from filename for images without associated_attributes.
     *
     * Same logic as the JS auto-detect in gallery-color-mapping.js, but running in PHP
     * with access to real DB value_ids. This bypasses all temp-hash matching issues.
     *
     * @param Product $product
     * @param \Magento\Framework\DB\Adapter\AdapterInterface $connection
     * @param string $valueTable The catalog_product_entity_media_gallery_value table name
     * @param int[] $handledValueIds Value IDs already handled (user explicitly set/cleared)
     * @return int Number of images auto-detected
     */
    private function autoDetectColorForNewImages(
        Product $product,
        $connection,
        string $valueTable,
        array $handledValueIds
    ): int {
        $storeId = $product->getStoreId();

        $attributeCode = $this->attributeResolver->resolveForProduct($product, $storeId);
        if ($attributeCode === null) {
            return 0;
        }

        $attributeId = $this->attributeResolver->getAttributeIdByCode($attributeCode);
        if ($attributeId === null) {
            return 0;
        }

        // Get all options for this color attribute
        $optionTable = $this->resourceConnection->getTableName('eav_attribute_option');
        $optionValueTable = $this->resourceConnection->getTableName('eav_attribute_option_value');
        $colorOptions = $connection->fetchPairs(
            $connection->select()
                ->from(['eao' => $optionTable], ['option_id'])
                ->join(
                    ['eaov' => $optionValueTable],
                    'eao.option_id = eaov.option_id AND eaov.store_id = 0',
                    ['value']
                )
                ->where('eao.attribute_id = ?', $attributeId)
        );

        if (empty($colorOptions)) {
            return 0;
        }

        // Build normalized patterns sorted longest-first (so "azul marino" matches before "azul")
        $patterns = [];
        foreach ($colorOptions as $optionId => $label) {
            $normalized = $this->normalizeForMatching($label);
            if ($normalized !== '') {
                $patterns[] = [
                    'normalized' => $normalized,
                    'optionId' => (int) $optionId,
                ];
            }
        }
        usort($patterns, fn($a, $b) => strlen($b['normalized']) - strlen($a['normalized']));

        if (empty($patterns)) {
            return 0;
        }

        // Find images of this product that still have no associated_attributes in the DB
        $galleryTable = $this->resourceConnection->getTableName('catalog_product_entity_media_gallery');
        $toEntityTable = $this->resourceConnection->getTableName(
            'catalog_product_entity_media_gallery_value_to_entity'
        );

        // Subquery: value_ids that already have a non-empty associated_attributes
        $assignedSubSelect = $connection->select()
            ->from($valueTable, ['value_id'])
            ->where('associated_attributes IS NOT NULL')
            ->where("associated_attributes != ''");

        $select = $connection->select()
            ->from(['g' => $galleryTable], ['value_id', 'value'])
            ->join(['te' => $toEntityTable], 'g.value_id = te.value_id', [])
            ->where('te.entity_id = ?', (int) $product->getId())
            ->where('g.value_id NOT IN (?)', $assignedSubSelect);

        // Exclude value_ids explicitly handled by the user (e.g. manually cleared to "no color")
        if (!empty($handledValueIds)) {
            $select->where('g.value_id NOT IN (?)', $handledValueIds);
        }

        $unassigned = $connection->fetchPairs($select);

        if (empty($unassigned)) {
            return 0;
        }

        $count = 0;
        foreach ($unassigned as $valueId => $filePath) {
            $filename = basename((string) $filePath);
            $normalizedFilename = $this->normalizeForMatching($filename);
            if ($normalizedFilename === '') {
                continue;
            }

            foreach ($patterns as $p) {
                if (str_contains($normalizedFilename, $p['normalized'])) {
                    $attrValue = 'attribute' . $attributeId . '-' . $p['optionId'];
                    $connection->update(
                        $valueTable,
                        ['associated_attributes' => $attrValue],
                        ['value_id = ?' => (int) $valueId]
                    );
                    $count++;
                    break;
                }
            }
        }

        if ($count > 0 && $this->config->isDebugMode()) {
            $this->logger->debug('Rollpix ConfigurableGallery: Auto-detected color from filename', [
                'product_id' => $product->getId(),
                'unassigned_count' => count($unassigned),
                'auto_detected_count' => $count,
            ]);
        }

        return $count;
    }

    /**
     * Normalize a string for color matching (mirrors JS normalizeForMatching).
     *
     * Lowercase, strip file extension, remove diacritical marks, normalize separators.
     */
    private function normalizeForMatching(string $str): string
    {
        if ($str === '') {
            return '';
        }

        $normalized = mb_strtolower(trim($str));

        // Remove file extension
        $normalized = preg_replace('/\.\w{2,4}$/', '', $normalized);

        // Strip diacritical marks: NFD decompose then remove combining marks
        // Same as JS: normalize('NFD').replace(/[\u0300-\u036f]/g, '')
        $normalized = Normalizer::normalize($normalized, Normalizer::FORM_D);
        $normalized = preg_replace('/[\x{0300}-\x{036f}]/u', '', $normalized);

        // Normalize separators (hyphens, underscores, dots, spaces) to single space
        $normalized = preg_replace('/[-_.\s]+/', ' ', $normalized);

        return trim($normalized);
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
