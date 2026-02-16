<?php

declare(strict_types=1);

namespace Rollpix\ConfigurableGallery\Plugin\Plp;

use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Swatches\Helper\Data as SwatchHelper;
use Psr\Log\LoggerInterface;
use Rollpix\ConfigurableGallery\Model\ColorMapping;
use Rollpix\ConfigurableGallery\Model\Config;

/**
 * Plugin on Swatches Helper to provide color-mapped images for PLP swatch changes (PRD §8.3).
 * When a swatch is clicked in PLP, Magento requests the product's media gallery for that option.
 * This plugin returns images from the configurable parent filtered by color mapping.
 *
 * Only active when propagation_mode = disabled (with propagation, simples have their own images).
 *
 * sortOrder=10: base PLP plugin.
 */
class SwatchImagePlugin
{
    public function __construct(
        private readonly Config $config,
        private readonly ColorMapping $colorMapping,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * After getting product media gallery for swatches, replace with color-mapped images
     * from the configurable parent when applicable.
     *
     * @param SwatchHelper $subject
     * @param array $result
     * @param Product $product
     * @return array
     */
    public function afterGetProductMediaGallery(SwatchHelper $subject, array $result, Product $product): array
    {
        if (!$this->shouldProcess($product)) {
            return $result;
        }

        // Get the configurable parent's color mapping
        $mediaMapping = $this->colorMapping->getColorMediaMapping($product);

        if (empty($mediaMapping)) {
            return $result;
        }

        // Find the first color that has images and return its first image
        foreach ($mediaMapping as $key => $media) {
            if ($key === 'null') {
                continue;
            }
            if (!empty($media['images'])) {
                $firstImage = $media['images'][0];
                $result['large'] = '/catalog/product' . $firstImage['file'];
                $result['medium'] = '/catalog/product' . $firstImage['file'];
                $result['small'] = '/catalog/product' . $firstImage['file'];
                break;
            }
        }

        return $result;
    }

    private function shouldProcess(Product $product): bool
    {
        if (!$this->config->isEnabled()) {
            return false;
        }

        // Only active when propagation is disabled
        if (!$this->config->isPropagationDisabled()) {
            return false;
        }

        if ($product->getTypeId() !== Configurable::TYPE_CODE) {
            return false;
        }

        return (int) $product->getData('rollpix_gallery_enabled') === 1;
    }
}
