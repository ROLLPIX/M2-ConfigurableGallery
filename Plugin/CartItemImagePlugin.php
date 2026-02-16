<?php

declare(strict_types=1);

namespace Rollpix\ConfigurableGallery\Plugin;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Checkout\CustomerData\AbstractItem;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Psr\Log\LoggerInterface;
use Rollpix\ConfigurableGallery\Model\ColorMapping;
use Rollpix\ConfigurableGallery\Model\Config;

/**
 * Overrides cart item thumbnail with the image of the selected color (PRD §6.9).
 * Only active when propagation_mode = disabled (simples don't have their own images).
 *
 * sortOrder=10: base plugin for cart item image.
 */
class CartItemImagePlugin
{
    public function __construct(
        private readonly Config $config,
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

        // Only when propagation is disabled — with propagation, simples have their own images
        if (!$this->config->isPropagationDisabled()) {
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
            // This is the simple child — get parent
            $configurableProduct = $parentItem->getProduct();
            $simpleProduct = $item->getProduct();
        } else {
            // This might be the configurable item itself
            $product = $item->getProduct();
            if ($product->getTypeId() !== Configurable::TYPE_CODE) {
                return null;
            }

            $configurableProduct = $product;
            // Get the simple product from options
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

        if ((int) $configurableProduct->getData('rollpix_gallery_enabled') !== 1) {
            return null;
        }

        // Get color option_id from the simple product
        $colorAttributeCode = $this->config->getColorAttributeCode();
        $colorOptionId = $simpleProduct->getData($colorAttributeCode);

        if ($colorOptionId === null) {
            return null;
        }

        $colorOptionId = (int) $colorOptionId;

        // Get images for this color from the configurable parent
        $mediaMapping = $this->colorMapping->getColorMediaMapping($configurableProduct);
        $colorKey = (string) $colorOptionId;

        if (!isset($mediaMapping[$colorKey]) || empty($mediaMapping[$colorKey]['images'])) {
            return null;
        }

        // Return the first image (main image) for this color
        $firstImage = $mediaMapping[$colorKey]['images'][0];
        $file = $firstImage['file'] ?? null;

        if ($file === null) {
            return null;
        }

        // Build catalog product image URL
        return '/media/catalog/product' . $file;
    }
}
