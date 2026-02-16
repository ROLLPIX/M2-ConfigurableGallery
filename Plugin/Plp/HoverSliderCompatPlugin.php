<?php

declare(strict_types=1);

namespace Rollpix\ConfigurableGallery\Plugin\Plp;

use Magento\Catalog\Block\Product\Image as ImageBlock;
use Magento\Framework\Module\Manager as ModuleManager;
use Psr\Log\LoggerInterface;
use Rollpix\ConfigurableGallery\Model\ColorMapping;
use Rollpix\ConfigurableGallery\Model\Config;

/**
 * Compatibility plugin for Rollpix_HoverSlider in PLP (PRD §8.4).
 *
 * HoverSlider uses afterToHtml on Image block (sortOrder=10) to inject all-media JSON.
 * This plugin runs after HoverSlider (sortOrder=20) and filters the images by color.
 *
 * Always registered in di.xml but checks ModuleManager::isEnabled('Rollpix_HoverSlider')
 * before executing logic. If module not installed → returns without modification.
 *
 * Only active when propagation_mode = disabled.
 *
 * sortOrder=20: runs after HoverSlider's sortOrder=10.
 */
class HoverSliderCompatPlugin
{
    public function __construct(
        private readonly Config $config,
        private readonly ColorMapping $colorMapping,
        private readonly ModuleManager $moduleManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * After Image block toHtml, filter HoverSlider's all-media JSON by color.
     * Also injects data-rollpix-color-images attribute with full color mapping
     * for frontend JS to use when swatches change.
     */
    public function afterToHtml(ImageBlock $subject, string $result): string
    {
        if (!$this->shouldProcess($subject)) {
            return $result;
        }

        $product = $subject->getData('product');
        if ($product === null) {
            return $result;
        }

        $mediaMapping = $this->colorMapping->getColorMediaMapping($product);
        if (empty($mediaMapping)) {
            return $result;
        }

        // Build color-images JSON for frontend
        $colorImages = $this->buildColorImagesJson($mediaMapping, $product);

        if (empty($colorImages)) {
            return $result;
        }

        $colorImagesJsonAttr = htmlspecialchars(
            json_encode($colorImages, JSON_UNESCAPED_SLASHES),
            ENT_QUOTES,
            'UTF-8'
        );

        // Filter the existing all-media attribute to show only first color's images
        $result = $this->filterAllMediaAttribute($result, $mediaMapping);

        // Inject data-rollpix-color-images attribute
        $result = $this->injectColorImagesAttribute($result, $colorImagesJsonAttr);

        return $result;
    }

    /**
     * Build JSON mapping of color option_id → image URLs for PLP.
     *
     * @return array<string, array<string>>
     */
    private function buildColorImagesJson(array $mediaMapping, $product): array
    {
        $colorImages = [];

        foreach ($mediaMapping as $key => $media) {
            if ($key === 'null') {
                continue;
            }

            $urls = [];
            foreach ($media['images'] ?? [] as $image) {
                $urls[] = '/media/catalog/product' . $image['file'];
            }

            if (!empty($urls)) {
                $colorImages[$key] = $urls;
            }
        }

        return $colorImages;
    }

    /**
     * Filter the all-media JSON in the HTML to show only the first available color.
     */
    private function filterAllMediaAttribute(string $html, array $mediaMapping): string
    {
        // Find the first color with images
        $firstColorImages = [];
        foreach ($mediaMapping as $key => $media) {
            if ($key === 'null') {
                continue;
            }
            if (!empty($media['images'])) {
                foreach ($media['images'] as $image) {
                    $firstColorImages[] = '/media/catalog/product' . $image['file'];
                }
                break;
            }
        }

        // Add generic images
        if (isset($mediaMapping['null'])) {
            foreach ($mediaMapping['null']['images'] ?? [] as $image) {
                $firstColorImages[] = '/media/catalog/product' . $image['file'];
            }
        }

        if (empty($firstColorImages)) {
            return $html;
        }

        $newAllMedia = htmlspecialchars(
            json_encode($firstColorImages, JSON_UNESCAPED_SLASHES),
            ENT_QUOTES,
            'UTF-8'
        );

        // Replace existing all-media attribute
        $html = preg_replace(
            '/all-media=\'[^\']*\'/',
            "all-media='" . $newAllMedia . "'",
            $html
        ) ?? $html;

        return $html;
    }

    /**
     * Inject data-rollpix-color-images attribute into the product image span.
     */
    private function injectColorImagesAttribute(string $html, string $jsonAttr): string
    {
        // Find the product-images span and add our data attribute
        $html = preg_replace(
            '/(<span[^>]*class="product-images"[^>]*)(>)/',
            '$1 data-rollpix-color-images=\'' . $jsonAttr . '\'$2',
            $html
        ) ?? $html;

        return $html;
    }

    private function shouldProcess(ImageBlock $subject): bool
    {
        if (!$this->config->isEnabled()) {
            return false;
        }

        if (!$this->config->isPropagationDisabled()) {
            return false;
        }

        if (!$this->moduleManager->isEnabled('Rollpix_HoverSlider')) {
            return false;
        }

        return true;
    }
}
