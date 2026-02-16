<?php

declare(strict_types=1);

namespace Rollpix\ConfigurableGallery\Plugin;

use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Catalog\Block\Product\View\Gallery as GalleryBlock;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Psr\Log\LoggerInterface;
use Rollpix\ConfigurableGallery\Model\ColorMapping;
use Rollpix\ConfigurableGallery\Model\ColorPreselect;
use Rollpix\ConfigurableGallery\Model\Config;
use Rollpix\ConfigurableGallery\Model\StockFilter;

/**
 * Enriches the gallery JSON output with associatedAttributes and rollpixGalleryConfig.
 * PRD §6.5, §6.6 — Adds color mapping, video info, and config to frontend JSON.
 *
 * sortOrder=10: base plugin for gallery JSON enrichment.
 */
class EnrichGalleryJson
{
    public function __construct(
        private readonly Config $config,
        private readonly ColorMapping $colorMapping,
        private readonly ColorPreselect $colorPreselect,
        private readonly StockFilter $stockFilter,
        private readonly JsonSerializer $jsonSerializer,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * After getGalleryImagesJson, enrich each image with associatedAttributes.
     */
    public function afterGetGalleryImagesJson(GalleryBlock $subject, string $result): string
    {
        $product = $subject->getProduct();
        if ($product === null) {
            return $result;
        }

        if (!$this->shouldProcess($product)) {
            return $result;
        }

        try {
            $galleryImages = $this->jsonSerializer->unserialize($result);
        } catch (\Exception $e) {
            return $result;
        }

        if (!is_array($galleryImages)) {
            return $result;
        }

        $storeId = $product->getStoreId();
        $colorAttributeId = $this->colorMapping->getColorAttributeId($product, $storeId);

        if ($colorAttributeId === null) {
            return $result;
        }

        $colorLabels = $this->colorMapping->getColorOptionLabels($product, $storeId);
        $mediaMapping = $this->colorMapping->getColorMediaMapping($product, $storeId);

        // Build a value_id to associated_attributes map from the media mapping
        $valueIdToAttributes = $this->buildValueIdToAttributesMap($mediaMapping, $colorLabels);

        // Magento's getGalleryImagesJson() does NOT include value_id in the output.
        // We need to get value_ids from the gallery collection and inject them.
        $collectionValueIds = $this->extractValueIdsFromCollection($subject);

        // Enrich each gallery image with value_id, associated attributes, and color label
        foreach ($galleryImages as $index => &$image) {
            $valueId = $image['value_id'] ?? $collectionValueIds[$index] ?? null;

            if ($valueId !== null) {
                $valueId = (int) $valueId;
                $image['value_id'] = $valueId;

                if (isset($valueIdToAttributes[$valueId])) {
                    $info = $valueIdToAttributes[$valueId];
                    $image['associatedAttributes'] = $info['associated_attributes'];
                    $image['associatedColorLabel'] = $info['label'];
                } else {
                    $image['associatedAttributes'] = null;
                    $image['associatedColorLabel'] = null;
                }
            } else {
                $image['associatedAttributes'] = null;
                $image['associatedColorLabel'] = null;
            }
        }
        unset($image);

        try {
            return $this->jsonSerializer->serialize($galleryImages);
        } catch (\Exception $e) {
            return $result;
        }
    }

    /**
     * Build a map of value_id => attributes info from color media mapping.
     *
     * @param array<string, array> $mediaMapping
     * @param array<int, string> $colorLabels
     * @return array<int, array{associated_attributes: string|null, label: string|null}>
     */
    private function buildValueIdToAttributesMap(array $mediaMapping, array $colorLabels): array
    {
        $map = [];

        foreach ($mediaMapping as $optionKey => $media) {
            $isGeneric = ($optionKey === 'null');
            $label = $isGeneric ? null : ($colorLabels[(int) $optionKey] ?? null);
            $associatedAttributes = $isGeneric ? null : ($media['images'][0]['associated_attributes'] ?? null);

            $allEntries = array_merge($media['images'] ?? [], $media['videos'] ?? []);
            foreach ($allEntries as $entry) {
                $valueId = $entry['value_id'] ?? null;
                if ($valueId !== null && !isset($map[(int) $valueId])) {
                    $map[(int) $valueId] = [
                        'associated_attributes' => $entry['associated_attributes'] ?? null,
                        'label' => $label,
                    ];
                }
            }
        }

        return $map;
    }

    /**
     * Extract value_ids from the gallery block's collection in iteration order.
     * getGalleryImagesJson() iterates getGalleryImages() in the same order,
     * so the array indices correspond to the JSON image indices.
     *
     * @param GalleryBlock $galleryBlock
     * @return array<int, int> index => value_id
     */
    private function extractValueIdsFromCollection(GalleryBlock $galleryBlock): array
    {
        $valueIds = [];

        try {
            $collection = $galleryBlock->getGalleryImages();
            foreach ($collection as $image) {
                $vid = $image->getData('value_id');
                $valueIds[] = $vid !== null ? (int) $vid : null;
            }
        } catch (\Exception $e) {
            $this->logger->error('Rollpix ConfigurableGallery: Failed to extract value_ids from gallery', [
                'exception' => $e->getMessage(),
            ]);
        }

        return $valueIds;
    }

    private function shouldProcess(Product $product): bool
    {
        if (!$this->config->isEnabled()) {
            return false;
        }

        if ($product->getTypeId() !== Configurable::TYPE_CODE) {
            return false;
        }

        return (int) $product->getData('rollpix_gallery_enabled') === 1;
    }
}
