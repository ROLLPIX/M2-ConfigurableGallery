<?php

declare(strict_types=1);

namespace Rollpix\ConfigurableGallery\ViewModel;

use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Psr\Log\LoggerInterface;
use Rollpix\ConfigurableGallery\Model\AttributeResolver;
use Rollpix\ConfigurableGallery\Model\ColorMapping;
use Rollpix\ConfigurableGallery\Model\ColorPreselect;
use Rollpix\ConfigurableGallery\Model\Config;
use Rollpix\ConfigurableGallery\Model\StockFilter;

/**
 * ViewModel that exposes rollpixGalleryConfig JSON to frontend templates (PRD §6.5).
 * Injected in catalog_product_view.xml, consumed by gallery-data.phtml.
 */
class GalleryData implements ArgumentInterface
{
    public function __construct(
        private readonly Registry $registry,
        private readonly Config $config,
        private readonly AttributeResolver $attributeResolver,
        private readonly ColorMapping $colorMapping,
        private readonly ColorPreselect $colorPreselect,
        private readonly StockFilter $stockFilter,
        private readonly ModuleManager $moduleManager,
        private readonly JsonSerializer $jsonSerializer,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getProduct(): ?Product
    {
        return $this->registry->registry('current_product');
    }

    /**
     * Check if the gallery config should be rendered for the current product.
     */
    public function isEnabled(): bool
    {
        if (!$this->config->isEnabled()) {
            return false;
        }

        $product = $this->getProduct();
        if ($product === null) {
            return false;
        }

        if ($product->getTypeId() !== Configurable::TYPE_CODE) {
            return false;
        }

        if ((int) $product->getData('rollpix_gallery_enabled') !== 1) {
            return false;
        }

        // Check that a selector attribute resolves for this product
        return $this->attributeResolver->resolveForProduct($product, $product->getStoreId()) !== null;
    }

    /**
     * Get the full rollpixGalleryConfig JSON for frontend.
     * Structure defined in PRD §6.5.
     */
    public function getGalleryConfigJson(): string
    {
        $product = $this->getProduct();
        if ($product === null || !$this->isEnabled()) {
            return '{}';
        }

        $storeId = $product->getStoreId();

        $resolvedAttributeCode = $this->attributeResolver->resolveForProduct($product, $storeId);
        $colorAttributeId = $this->attributeResolver->resolveAttributeId($product, $storeId);

        if ($resolvedAttributeCode === null || $colorAttributeId === null) {
            return '{}';
        }

        $colorLabels = $this->colorMapping->getColorOptionLabels($product, $storeId);
        $mediaMapping = $this->colorMapping->getColorMediaMapping($product, $storeId);

        // Get available color option IDs from the mapping (excluding generics)
        $availableColors = array_keys($mediaMapping);
        $availableColors = array_filter($availableColors, fn(string $key) => $key !== 'null');
        $availableColorIds = array_map('intval', $availableColors);

        // Stock filtering
        $colorsWithStock = $availableColorIds;
        if ($this->config->isStockFilterEnabled($storeId)) {
            $colorsWithStock = $this->stockFilter->getColorsWithStock($product, $storeId);
        }

        // Determine default color
        $defaultColorOptionId = $this->colorPreselect->getDefaultColorOptionId(
            $product,
            $availableColorIds,
            $storeId
        );

        // Build color mapping for frontend
        $frontendColorMapping = $this->buildFrontendColorMapping(
            $mediaMapping,
            $colorLabels,
            $colorsWithStock
        );

        $galleryConfig = [
            'enabled' => true,
            'colorAttributeId' => $colorAttributeId,
            'colorAttributeCode' => $resolvedAttributeCode,
            'showGenericImages' => $this->config->showGenericImages($storeId),
            'stockFilterEnabled' => $this->config->isStockFilterEnabled($storeId),
            'outOfStockBehavior' => $this->config->getOutOfStockBehavior($storeId),
            'defaultColorOptionId' => $defaultColorOptionId,
            'preselectColor' => $this->config->isPreselectColorEnabled($storeId),
            'deepLinkEnabled' => $this->config->isDeepLinkEnabled($storeId),
            'updateUrlOnSelect' => $this->config->isUpdateUrlOnSelectEnabled($storeId),
            'availableColors' => $availableColorIds,
            'colorsWithStock' => $colorsWithStock,
            'colorMapping' => $frontendColorMapping,
            'galleryAdapter' => $this->getResolvedAdapter($storeId),
        ];

        return $this->jsonSerializer->serialize($galleryConfig);
    }

    /**
     * Build the colorMapping structure for frontend consumption.
     *
     * @param array<string, array> $mediaMapping
     * @param array<int, string> $colorLabels
     * @param int[] $colorsWithStock
     * @return array<string, array>
     */
    private function buildFrontendColorMapping(
        array $mediaMapping,
        array $colorLabels,
        array $colorsWithStock
    ): array {
        $frontendMapping = [];

        foreach ($mediaMapping as $optionKey => $media) {
            $isGeneric = ($optionKey === 'null');

            $imageIndices = [];
            foreach ($media['images'] ?? [] as $image) {
                $imageIndices[] = $image['value_id'];
            }

            $videoIndices = [];
            foreach ($media['videos'] ?? [] as $video) {
                $videoIndices[] = $video['value_id'];
            }

            $entry = [
                'label' => $isGeneric ? __('General') : ($colorLabels[(int) $optionKey] ?? ''),
                'images' => $imageIndices,
                'videos' => $videoIndices,
            ];

            if (!$isGeneric) {
                $entry['hasStock'] = in_array((int) $optionKey, $colorsWithStock, true);
            }

            $frontendMapping[$optionKey] = $entry;
        }

        return $frontendMapping;
    }

    /**
     * Resolve which gallery adapter to use.
     */
    private function getResolvedAdapter(int|string|null $storeId): string
    {
        $configuredAdapter = $this->config->getGalleryAdapter($storeId);

        if ($configuredAdapter !== 'auto') {
            return $configuredAdapter;
        }

        // Auto-detection priority (PRD §7.2)
        if ($this->moduleManager->isEnabled('Rollpix_ProductGallery')) {
            return 'rollpix';
        }

        if ($this->moduleManager->isEnabled('Amasty_Conf')) {
            return 'amasty';
        }

        return 'fotorama';
    }
}
