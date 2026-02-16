<?php

declare(strict_types=1);

namespace Rollpix\ConfigurableGallery\Plugin\Plp;

use Magento\Catalog\Block\Product\Image as ImageBlock;
use Magento\Framework\Module\Manager as ModuleManager;
use Psr\Log\LoggerInterface;
use Rollpix\ConfigurableGallery\Model\ColorMapping;
use Rollpix\ConfigurableGallery\Model\Config;

/**
 * Compatibility plugin for Rollpix_ImageFlipHover in PLP (PRD §8.5).
 *
 * ImageFlipHover uses afterToHtml on Image block (sortOrder=100) to inject flip image HTML.
 * This plugin runs after ImageFlipHover (sortOrder=110) and updates the flip image
 * to show the second image of the first available color (instead of a random image).
 *
 * Always registered in di.xml but checks ModuleManager::isEnabled('Rollpix_ImageFlipHover')
 * before executing logic. If module not installed → returns without modification.
 *
 * Only active when propagation_mode = disabled.
 *
 * sortOrder=110: runs after ImageFlipHover's sortOrder=100.
 */
class ImageFlipCompatPlugin
{
    public function __construct(
        private readonly Config $config,
        private readonly ColorMapping $colorMapping,
        private readonly ModuleManager $moduleManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * After Image block toHtml (after ImageFlipHover has injected its flip image),
     * replace the flip image with the second image of the first available color.
     * Also inject data-rollpix-flip-images for frontend JS swatch changes.
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

        // Build flip image mapping: color option_id → second image URL
        $flipImages = $this->buildFlipImagesMapping($mediaMapping);

        // Replace the current flip image with the first color's second image
        $result = $this->updateFlipImage($result, $mediaMapping);

        // Inject data-rollpix-flip-images attribute for frontend JS
        if (!empty($flipImages)) {
            $flipJsonAttr = htmlspecialchars(
                json_encode($flipImages, JSON_UNESCAPED_SLASHES),
                ENT_QUOTES,
                'UTF-8'
            );
            $result = $this->injectFlipImagesAttribute($result, $flipJsonAttr);
        }

        return $result;
    }

    /**
     * Build mapping of color option_id → flip image URL (second image of each color).
     *
     * @return array<string, string>
     */
    private function buildFlipImagesMapping(array $mediaMapping): array
    {
        $flipImages = [];

        foreach ($mediaMapping as $key => $media) {
            if ($key === 'null') {
                continue;
            }

            $images = $media['images'] ?? [];
            if (count($images) >= 2) {
                // Flip image = second image of the color
                $flipImages[$key] = '/media/catalog/product' . $images[1]['file'];
            } elseif (count($images) === 1) {
                // Only one image — use it as flip too
                $flipImages[$key] = '/media/catalog/product' . $images[0]['file'];
            }
        }

        return $flipImages;
    }

    /**
     * Update the existing flip image in HTML to use the first color's second image.
     */
    private function updateFlipImage(string $html, array $mediaMapping): string
    {
        $flipUrl = null;

        foreach ($mediaMapping as $key => $media) {
            if ($key === 'null') {
                continue;
            }
            $images = $media['images'] ?? [];
            if (count($images) >= 2) {
                $flipUrl = '/media/catalog/product' . $images[1]['file'];
                break;
            } elseif (count($images) === 1) {
                $flipUrl = '/media/catalog/product' . $images[0]['file'];
                break;
            }
        }

        if ($flipUrl === null) {
            return $html;
        }

        // Replace flip image src
        $html = preg_replace(
            '/(class="[^"]*flip-image[^"]*"[^>]*src=")[^"]*(")/i',
            '${1}' . $flipUrl . '${2}',
            $html
        ) ?? $html;

        // Replace data-flip-url attribute
        $html = preg_replace(
            '/data-flip-url="[^"]*"/',
            'data-flip-url="' . htmlspecialchars($flipUrl, ENT_QUOTES, 'UTF-8') . '"',
            $html
        ) ?? $html;

        return $html;
    }

    /**
     * Inject data-rollpix-flip-images attribute for frontend JS.
     */
    private function injectFlipImagesAttribute(string $html, string $jsonAttr): string
    {
        // Add to the product image container
        $html = preg_replace(
            '/(<div[^>]*class="[^"]*product-item-photo[^"]*"[^>]*)(>)/i',
            '$1 data-rollpix-flip-images=\'' . $jsonAttr . '\'$2',
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

        if (!$this->moduleManager->isEnabled('Rollpix_ImageFlipHover')) {
            return false;
        }

        return true;
    }
}
