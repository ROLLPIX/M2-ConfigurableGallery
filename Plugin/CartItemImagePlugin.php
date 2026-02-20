<?php

declare(strict_types=1);

namespace Rollpix\ConfigurableGallery\Plugin;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Configuration\Item\ItemInterface;
use Magento\ConfigurableProduct\Model\Product\Configuration\Item\ItemProductResolver;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Psr\Log\LoggerInterface;
use Rollpix\ConfigurableGallery\Model\AttributeResolver;
use Rollpix\ConfigurableGallery\Model\ColorMapping;
use Rollpix\ConfigurableGallery\Model\Config;

/**
 * Overrides cart/checkout item thumbnail with the color-specific image (PRD §6.9).
 *
 * Plugins on ItemProductResolver::getFinalProduct() — the universal hook that
 * Magento uses in ALL contexts (cart page, minicart, checkout) to determine
 * which product image to render for a configurable cart item.
 *
 * Strategy 1: Color-specific image from parent's associated_attributes mapping.
 * Strategy 2: Simple product's own base image (fallback when no color mapping).
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
     * After Magento resolves which product to use for the cart item image,
     * override the image roles with the color-specific image if available.
     */
    public function afterGetFinalProduct(
        ItemProductResolver $subject,
        Product $result,
        ItemInterface $item
    ): Product {
        if (!$this->config->isEnabled() || !$this->config->isCartImageOverrideEnabled()) {
            return $result;
        }

        try {
            $colorImageFile = $this->resolveColorImage($item);
            if ($colorImageFile !== null) {
                // Set color-specific image on all image roles so it's used everywhere
                $result->setData('image', $colorImageFile);
                $result->setData('small_image', $colorImageFile);
                $result->setData('thumbnail', $colorImageFile);
            }
        } catch (\Exception $e) {
            $this->logger->error('Rollpix ConfigurableGallery: Cart image error', [
                'exception' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * Resolve the color-specific image file path for a cart item.
     *
     * @return string|null Image file relative path (e.g. "/r/e/remera-roja.jpg") or null
     */
    private function resolveColorImage(ItemInterface $item): ?string
    {
        // ItemInterface in cart context is a QuoteItem
        $configurableProduct = null;
        $simpleProduct = null;

        // Navigate the quote item relationship to find configurable + simple
        if (method_exists($item, 'getOptionByCode')) {
            $simpleOption = $item->getOptionByCode('simple_product');
            $product = $item->getProduct();

            if ($product !== null && $product->getTypeId() === Configurable::TYPE_CODE) {
                $configurableProduct = $product;
                $simpleProduct = $simpleOption?->getProduct();
            }
        }

        // Also check parent item relationship
        if ($configurableProduct === null && method_exists($item, 'getParentItem')) {
            $parentItem = $item->getParentItem();
            if ($parentItem !== null) {
                $parentProduct = $parentItem->getProduct();
                if ($parentProduct !== null && $parentProduct->getTypeId() === Configurable::TYPE_CODE) {
                    $configurableProduct = $parentProduct;
                    $simpleProduct = $item->getProduct();
                }
            }
        }

        if ($configurableProduct === null || $simpleProduct === null) {
            return null;
        }

        // Resolve the selector attribute
        $colorAttributeCode = $this->attributeResolver->resolveForProduct($configurableProduct);
        if ($colorAttributeCode === null) {
            return null;
        }

        // Get color option ID — reload simple product if EAV data not loaded
        $colorOptionId = $simpleProduct->getData($colorAttributeCode);
        if ($colorOptionId === null) {
            $simpleProduct = $this->productRepository->getById((int) $simpleProduct->getId());
            $colorOptionId = $simpleProduct->getData($colorAttributeCode);
        }

        if ($colorOptionId === null) {
            return null;
        }

        $colorOptionId = (int) $colorOptionId;

        // Strategy 1: color-specific image from parent's associated_attributes mapping
        $mediaMapping = $this->colorMapping->getColorMediaMapping($configurableProduct);
        $colorKey = (string) $colorOptionId;

        if (isset($mediaMapping[$colorKey]) && !empty($mediaMapping[$colorKey]['images'])) {
            // Find the first non-video image for cart thumbnail
            foreach ($mediaMapping[$colorKey]['images'] as $imageEntry) {
                $file = $imageEntry['file'] ?? null;
                if ($file !== null && strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'mp4') {
                    return $file;
                }
            }
        }

        // Strategy 2: simple product's own base image
        $simpleImage = $simpleProduct->getImage();
        if ($simpleImage && $simpleImage !== 'no_selection') {
            return $simpleImage;
        }

        return null;
    }
}
