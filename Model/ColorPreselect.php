<?php

declare(strict_types=1);

namespace Rollpix\ConfigurableGallery\Model;

use Magento\Catalog\Model\Product;
use Psr\Log\LoggerInterface;

/**
 * Determines the default color to preselect when loading a product page (PRD §6.7).
 *
 * Priority order:
 * 1. Manual per-product: rollpix_default_color attribute (if set and has stock)
 * 2. First color with stock: iterate color options in position order, pick first with is_salable
 * 3. First color (no stock filter): if stock filter is disabled, simply first in position order
 *
 * Note: URL parameter (#color= or ?color=) has highest priority but is handled in frontend JS.
 */
class ColorPreselect
{
    public function __construct(
        private readonly Config $config,
        private readonly StockFilter $stockFilter,
        private readonly ColorMapping $colorMapping,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Determine the default color option ID for a configurable product.
     *
     * @param Product $product Configurable product
     * @param int[] $availableColorOptionIds All color option IDs used in the configurable
     * @return int|null The option_id to preselect, or null if none available
     */
    public function getDefaultColorOptionId(
        Product $product,
        array $availableColorOptionIds,
        int|string|null $storeId = null
    ): ?int {
        if (empty($availableColorOptionIds)) {
            return null;
        }

        if (!$this->config->isPreselectColorEnabled($storeId)) {
            return null;
        }

        // Priority 1: Manual per-product setting
        $manualDefault = $this->getManualDefault($product, $availableColorOptionIds, $storeId);
        if ($manualDefault !== null) {
            return $manualDefault;
        }

        // Priority 2: First color with stock (if stock filter enabled)
        if ($this->config->isStockFilterEnabled($storeId)) {
            $colorsWithStock = $this->stockFilter->getColorsWithStock($product, $storeId);
            foreach ($availableColorOptionIds as $optionId) {
                if (in_array($optionId, $colorsWithStock, true)) {
                    $this->debugLog('Preselected first color with stock', [
                        'product_id' => $product->getId(),
                        'option_id' => $optionId,
                    ], $storeId);
                    return $optionId;
                }
            }
        }

        // Priority 3: First color in position order (no stock filter)
        $firstColor = reset($availableColorOptionIds);
        if ($firstColor !== false) {
            $this->debugLog('Preselected first color (position order)', [
                'product_id' => $product->getId(),
                'option_id' => $firstColor,
            ], $storeId);
            return (int) $firstColor;
        }

        return null;
    }

    /**
     * Check manual default color attribute value.
     */
    private function getManualDefault(
        Product $product,
        array $availableColorOptionIds,
        int|string|null $storeId
    ): ?int {
        $defaultColor = $product->getData('rollpix_default_color');

        if ($defaultColor === null || $defaultColor === '' || $defaultColor === '0') {
            return null;
        }

        $defaultColorId = (int) $defaultColor;

        // Verify the color exists in available options
        if (!in_array($defaultColorId, $availableColorOptionIds, true)) {
            $this->debugLog('Manual default color not in available options, skipping', [
                'product_id' => $product->getId(),
                'default_color_id' => $defaultColorId,
                'available' => $availableColorOptionIds,
            ], $storeId);
            return null;
        }

        // If stock filter enabled, verify it has stock
        if ($this->config->isStockFilterEnabled($storeId)) {
            $colorsWithStock = $this->stockFilter->getColorsWithStock($product, $storeId);
            if (!in_array($defaultColorId, $colorsWithStock, true)) {
                $this->debugLog('Manual default color has no stock, falling through', [
                    'product_id' => $product->getId(),
                    'default_color_id' => $defaultColorId,
                ], $storeId);
                return null;
            }
        }

        $this->debugLog('Using manual default color', [
            'product_id' => $product->getId(),
            'option_id' => $defaultColorId,
        ], $storeId);

        return $defaultColorId;
    }

    private function debugLog(string $message, array $context, int|string|null $storeId): void
    {
        if ($this->config->isDebugMode($storeId)) {
            $this->logger->debug('Rollpix ConfigurableGallery: ' . $message, $context);
        }
    }
}
