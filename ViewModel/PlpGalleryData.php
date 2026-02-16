<?php

declare(strict_types=1);

namespace Rollpix\ConfigurableGallery\ViewModel;

use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Psr\Log\LoggerInterface;
use Rollpix\ConfigurableGallery\Model\ColorMapping;
use Rollpix\ConfigurableGallery\Model\Config;
use Rollpix\ConfigurableGallery\Model\StockFilter;

/**
 * ViewModel that provides color-to-image mapping JSON for PLP (PRD §8.8).
 * Injected in catalog_category_view.xml. Provides data for swatch image changes,
 * HoverSlider compat, and ImageFlipHover compat in product listing pages.
 *
 * Only outputs data when propagation_mode = disabled and module is enabled.
 */
class PlpGalleryData implements ArgumentInterface
{
    /** @var array<int, array>|null Cached mapping data per request */
    private ?array $cachedPlpConfig = null;

    public function __construct(
        private readonly Config $config,
        private readonly ColorMapping $colorMapping,
        private readonly StockFilter $stockFilter,
        private readonly JsonSerializer $jsonSerializer,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Check if PLP gallery data should be rendered.
     */
    public function isEnabled(): bool
    {
        return $this->config->isEnabled() && $this->config->isPropagationDisabled();
    }

    /**
     * Get the rollpixPlpConfig JSON for a list of products currently on the page.
     *
     * @param Product[] $products Products from the collection
     * @return string JSON string
     */
    public function getPlpConfigJson(array $products): string
    {
        if (!$this->isEnabled()) {
            return '{}';
        }

        $plpConfig = [];

        foreach ($products as $product) {
            if ($product->getTypeId() !== Configurable::TYPE_CODE) {
                continue;
            }

            $productConfig = $this->buildProductPlpConfig($product);
            if ($productConfig !== null) {
                $plpConfig[(int) $product->getId()] = $productConfig;
            }
        }

        if (empty($plpConfig)) {
            return '{}';
        }

        return $this->jsonSerializer->serialize(['rollpixPlpConfig' => $plpConfig]);
    }

    /**
     * Build PLP config for a single configurable product (PRD §8.8 structure).
     */
    private function buildProductPlpConfig(Product $product): ?array
    {
        $storeId = $product->getStoreId();
        $colorAttributeId = $this->colorMapping->getColorAttributeId($product, $storeId);

        if ($colorAttributeId === null) {
            return null;
        }

        $mediaMapping = $this->colorMapping->getColorMediaMapping($product, $storeId);
        $colorLabels = $this->colorMapping->getColorOptionLabels($product, $storeId);

        if (empty($mediaMapping)) {
            return null;
        }

        $colorMappingOutput = [];
        $genericImages = [];

        foreach ($mediaMapping as $key => $media) {
            if ($key === 'null') {
                foreach ($media['images'] ?? [] as $image) {
                    $genericImages[] = '/media/catalog/product' . $image['file'];
                }
                continue;
            }

            $optionId = (int) $key;
            $images = $media['images'] ?? [];
            $allImageUrls = [];

            foreach ($images as $image) {
                $allImageUrls[] = '/media/catalog/product' . $image['file'];
            }

            $mainImage = $allImageUrls[0] ?? null;
            $flipImage = $allImageUrls[1] ?? ($allImageUrls[0] ?? null);

            $colorMappingOutput[$key] = [
                'label' => $colorLabels[$optionId] ?? '',
                'mainImage' => $mainImage,
                'flipImage' => $flipImage,
                'allImages' => $allImageUrls,
            ];
        }

        if (empty($colorMappingOutput)) {
            return null;
        }

        // Determine default color
        $availableColorIds = array_map('intval', array_keys($colorMappingOutput));
        $defaultColorOptionId = null;
        if (!empty($availableColorIds)) {
            $defaultColorOptionId = $availableColorIds[0];
        }

        return [
            'colorAttributeId' => $colorAttributeId,
            'defaultColorOptionId' => $defaultColorOptionId,
            'colorMapping' => $colorMappingOutput,
            'genericImages' => $genericImages,
        ];
    }
}
