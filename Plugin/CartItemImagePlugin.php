<?php

declare(strict_types=1);

namespace Rollpix\ConfigurableGallery\Plugin;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Checkout\CustomerData\AbstractItem;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Psr\Log\LoggerInterface;
use Rollpix\ConfigurableGallery\Model\AttributeResolver;
use Rollpix\ConfigurableGallery\Model\ColorMapping;
use Rollpix\ConfigurableGallery\Model\Config;

/**
 * Overrides cart item thumbnail with the image of the selected color (PRD §6.9).
 *
 * Strategy 1: Color-specific image from parent's associated_attributes mapping.
 * Strategy 2: Simple product's own base image (fallback when no color mapping).
 *
 * sortOrder=10: base plugin for cart item image.
 */
class CartItemImagePlugin
{
    public function __construct(
        private readonly Config $config,
        private readonly AttributeResolver $attributeResolver,
        private readonly ColorMapping $colorMapping,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * After getting item data for the customer data section (minicart, cart page),
     * replace product_image with the color-specific image.
     *
     * @param AbstractItem $subject
     * @param array $result
     * @param QuoteItem $item
     * @return array
     */
    public function afterGetItemData(AbstractItem $subject, array $result, QuoteItem $item): array
    {
        if (!$this->config->isEnabled()) {
            return $result;
        }

        if (!$this->config->isCartImageOverrideEnabled()) {
            return $result;
        }

        try {
            $colorImage = $this->getColorImageForCartItem($item);
            if ($colorImage !== null) {
                $result['product_image']['src'] = $colorImage;
            }
        } catch (\Exception $e) {
            $this->logger->error('Rollpix ConfigurableGallery: Failed to override cart item image', [
                'item_id' => $item->getId(),
                'exception' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * Get the color-specific image URL for a cart item.
     */
    private function getColorImageForCartItem(QuoteItem $item): ?string
    {
        // Get the parent configurable product
        $parentItem = $item->getParentItem();
        if ($parentItem !== null) {
            $configurableProduct = $parentItem->getProduct();
            $simpleProduct = $item->getProduct();
        } else {
            $product = $item->getProduct();
            if ($product->getTypeId() !== Configurable::TYPE_CODE) {
                return null;
            }

            $configurableProduct = $product;
            $simpleOption = $item->getOptionByCode('simple_product');
            if ($simpleOption === null) {
                return null;
            }
            $simpleProduct = $simpleOption->getProduct();
        }

        if ($configurableProduct === null || $simpleProduct === null) {
            return null;
        }

        if ($configurableProduct->getTypeId() !== Configurable::TYPE_CODE) {
            return null;
        }

        // Resolve the selector attribute — only needs product ID + type instance (no EAV reload)
        $colorAttributeCode = $this->attributeResolver->resolveForProduct($configurableProduct);
        if ($colorAttributeCode === null) {
            $this->logger->debug('Rollpix ConfigurableGallery: Cart image — no selector attribute', [
                'configurable_id' => $configurableProduct->getId(),
            ]);
            return null;
        }

        // Quote item products may not have EAV attributes loaded — reload only if needed
        $colorOptionId = $simpleProduct->getData($colorAttributeCode);
        if ($colorOptionId === null) {
            $simpleProduct = $this->productRepository->getById((int) $simpleProduct->getId());
            $colorOptionId = $simpleProduct->getData($colorAttributeCode);
        }
        if ($colorOptionId === null) {
            $this->logger->debug('Rollpix ConfigurableGallery: Cart image — no color value on simple', [
                'simple_id' => $simpleProduct->getId(),
                'attribute' => $colorAttributeCode,
            ]);
            return null;
        }

        $colorOptionId = (int) $colorOptionId;

        // Get images for this color from the configurable parent
        $mediaMapping = $this->colorMapping->getColorMediaMapping($configurableProduct);
        $colorKey = (string) $colorOptionId;

        $this->logger->debug('Rollpix ConfigurableGallery: Cart image mapping lookup', [
            'configurable_id' => $configurableProduct->getId(),
            'simple_id' => $simpleProduct->getId(),
            'color_attribute' => $colorAttributeCode,
            'color_option_id' => $colorOptionId,
            'mapping_keys' => array_keys($mediaMapping),
            'match' => isset($mediaMapping[$colorKey]),
        ]);

        // Strategy 1: color-specific image from parent's associated_attributes mapping
        if (isset($mediaMapping[$colorKey]) && !empty($mediaMapping[$colorKey]['images'])) {
            $firstImage = $mediaMapping[$colorKey]['images'][0];
            $file = $firstImage['file'] ?? null;

            if ($file !== null) {
                return '/media/catalog/product' . $file;
            }
        }

        // Strategy 2: simple product's own base image
        $simpleImage = $simpleProduct->getImage();
        if ($simpleImage && $simpleImage !== 'no_selection') {
            return '/media/catalog/product' . $simpleImage;
        }

        return null;
    }
}
