<?php

declare(strict_types=1);

namespace Rollpix\ConfigurableGallery\Model;

use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Maps color option IDs to their associated media gallery entries (images + videos).
 * Reads the associated_attributes column from catalog_product_entity_media_gallery_value.
 * PRD §6.1–6.6
 */
class ColorMapping
{
    public function __construct(
        private readonly Config $config,
        private readonly AttributeResolver $attributeResolver,
        private readonly ResourceConnection $resourceConnection,
        private readonly AttributeRepositoryInterface $attributeRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Parse associated_attributes value and extract option IDs for the configured color attribute.
     *
     * @param string|null $associatedAttributes Raw value, e.g. "attribute92-318,attribute92-320"
     * @return int[] Array of option IDs, e.g. [318, 320]
     */
    public function parseOptionIds(?string $associatedAttributes): array
    {
        if ($associatedAttributes === null || $associatedAttributes === '') {
            return [];
        }

        $optionIds = [];
        $parts = explode(',', $associatedAttributes);

        foreach ($parts as $part) {
            $part = trim($part);
            if (preg_match('/^attribute(\d+)-(\d+)$/', $part, $matches)) {
                $optionIds[] = (int) $matches[2];
            }
        }

        return $optionIds;
    }

    /**
     * Build the associated_attributes value from color attribute ID and option IDs.
     *
     * @param int $attributeId The EAV attribute ID (e.g., 92 for color)
     * @param int[] $optionIds Array of option IDs
     * @return string|null Formatted value or null if empty
     */
    public function buildAssociatedAttributes(int $attributeId, array $optionIds): ?string
    {
        if (empty($optionIds)) {
            return null;
        }

        $parts = [];
        foreach ($optionIds as $optionId) {
            $parts[] = sprintf('attribute%d-%d', $attributeId, (int) $optionId);
        }

        return implode(',', $parts);
    }

    /**
     * Get the resolved selector attribute ID for a product.
     */
    public function getColorAttributeId(Product $product, int|string|null $storeId = null): ?int
    {
        return $this->attributeResolver->resolveAttributeId($product, $storeId);
    }

    /**
     * Get media gallery entries grouped by color option ID for a configurable product.
     *
     * @param Product $product Configurable product
     * @return array<string, array<int, array>> Keyed by option_id (string), 'null' for generics.
     *   Each entry: ['images' => [...], 'videos' => [...]]
     */
    public function getColorMediaMapping(Product $product, int|string|null $storeId = null): array
    {
        $colorAttributeId = $this->attributeResolver->resolveAttributeId($product, $storeId);
        if ($colorAttributeId === null) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $mediaGalleryTable = $this->resourceConnection->getTableName('catalog_product_entity_media_gallery');
        $mediaGalleryValueTable = $this->resourceConnection->getTableName('catalog_product_entity_media_gallery_value');
        $mediaGalleryValueToEntityTable = $this->resourceConnection->getTableName(
            'catalog_product_entity_media_gallery_value_to_entity'
        );

        $select = $connection->select()
            ->from(
                ['mgv' => $mediaGalleryValueTable],
                ['value_id', 'position', 'disabled', 'associated_attributes']
            )
            ->join(
                ['mg' => $mediaGalleryTable],
                'mgv.value_id = mg.value_id',
                ['value', 'media_type']
            )
            ->join(
                ['mgvte' => $mediaGalleryValueToEntityTable],
                'mg.value_id = mgvte.value_id',
                []
            )
            ->where('mgvte.entity_id = ?', (int) $product->getId())
            ->where('mgv.store_id IN (?)', [0, (int) ($storeId ?? $product->getStoreId())])
            ->where('mgv.disabled != 1 OR mgv.disabled IS NULL')
            ->order('mgv.position ASC');

        $rows = $connection->fetchAll($select);

        $mapping = [];
        $prefix = sprintf('attribute%d-', $colorAttributeId);

        foreach ($rows as $row) {
            $associatedAttributes = $row['associated_attributes'] ?? null;
            $mediaType = $row['media_type'] ?? 'image';
            $isVideo = ($mediaType === 'external-video');

            $entry = [
                'value_id' => (int) $row['value_id'],
                'file' => $row['value'],
                'media_type' => $mediaType,
                'position' => (int) ($row['position'] ?? 0),
                'disabled' => (bool) ($row['disabled'] ?? false),
                'associated_attributes' => $associatedAttributes,
            ];

            $optionIds = $this->parseColorOptionIds($associatedAttributes, $prefix);

            if (empty($optionIds)) {
                // Generic image/video (no color assigned)
                $key = 'null';
                $mapping[$key] ??= ['images' => [], 'videos' => []];
                $mapping[$key][$isVideo ? 'videos' : 'images'][] = $entry;
            } else {
                foreach ($optionIds as $optionId) {
                    $key = (string) $optionId;
                    $mapping[$key] ??= ['images' => [], 'videos' => []];
                    $mapping[$key][$isVideo ? 'videos' : 'images'][] = $entry;
                }
            }
        }

        if ($this->config->isDebugMode($storeId)) {
            $this->logger->debug('Rollpix ConfigurableGallery: Color media mapping', [
                'product_id' => $product->getId(),
                'color_attribute_id' => $colorAttributeId,
                'mapping_keys' => array_keys($mapping),
            ]);
        }

        return $mapping;
    }

    /**
     * Extract option IDs for the given color attribute prefix.
     *
     * @param string|null $associatedAttributes Raw value
     * @param string $prefix e.g. "attribute92-"
     * @return int[]
     */
    private function parseColorOptionIds(?string $associatedAttributes, string $prefix): array
    {
        if ($associatedAttributes === null || $associatedAttributes === '') {
            return [];
        }

        $optionIds = [];
        $parts = explode(',', $associatedAttributes);

        foreach ($parts as $part) {
            $part = trim($part);
            if (str_starts_with($part, $prefix)) {
                $optionId = substr($part, strlen($prefix));
                if (is_numeric($optionId)) {
                    $optionIds[] = (int) $optionId;
                }
            }
        }

        return $optionIds;
    }

    /**
     * Get color option labels for a configurable product.
     * Only returns options actually used by the product's simple children,
     * not all options in the system for the color attribute.
     *
     * @return array<int, string> option_id => label
     */
    public function getColorOptionLabels(Product $product, int|string|null $storeId = null): array
    {
        $colorAttributeCode = $this->attributeResolver->resolveForProduct($product, $storeId);
        if ($colorAttributeCode === null) {
            return [];
        }

        if ($product->getTypeId() !== Configurable::TYPE_CODE) {
            return [];
        }

        try {
            $colorAttributeId = $this->attributeResolver->resolveAttributeId($product, $storeId);
            if ($colorAttributeId === null) {
                return [];
            }

            // Get option IDs actually used by this product's children
            $usedOptionIds = $this->getUsedOptionIds($product, $colorAttributeId);

            $attribute = $this->attributeRepository->get(
                Product::ENTITY,
                $colorAttributeCode
            );

            $labels = [];
            $options = $attribute->getSource()->getAllOptions(false);
            foreach ($options as $option) {
                if ($option['value'] !== '' && $option['value'] !== null) {
                    $optionId = (int) $option['value'];
                    // Filter: only include options used by this configurable's children
                    if (empty($usedOptionIds) || isset($usedOptionIds[$optionId])) {
                        $labels[$optionId] = (string) $option['label'];
                    }
                }
            }

            return $labels;
        } catch (\Exception $e) {
            $this->logger->error(
                'Rollpix ConfigurableGallery: Failed to get color option labels',
                ['exception' => $e->getMessage()]
            );
            return [];
        }
    }

    /**
     * Get option IDs actually used by a configurable product's children for a given attribute.
     *
     * @return array<int, true> option_id => true (used as a lookup set)
     */
    private function getUsedOptionIds(Product $product, int $attributeId): array
    {
        /** @var Configurable $typeInstance */
        $typeInstance = $product->getTypeInstance();
        $configurableOptions = $typeInstance->getConfigurableOptions($product);

        $usedIds = [];
        if (isset($configurableOptions[$attributeId])) {
            foreach ($configurableOptions[$attributeId] as $option) {
                $valueIndex = (int) ($option['value_index'] ?? 0);
                if ($valueIndex > 0) {
                    $usedIds[$valueIndex] = true;
                }
            }
        }

        return $usedIds;
    }
}
