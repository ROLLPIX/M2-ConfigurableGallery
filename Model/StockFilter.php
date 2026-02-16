<?php

declare(strict_types=1);

namespace Rollpix\ConfigurableGallery\Model;

use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Store\Model\StoreManagerInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use Psr\Log\LoggerInterface;

/**
 * Filters color options based on stock availability (PRD §6.4).
 * For each color option_id, checks if at least one simple child with that color has is_salable = true.
 * Compatible with MSI (Multi Source Inventory) when available, falls back to legacy stock.
 *
 * MSI interfaces are injected as nullable via di.xml to avoid hard dependency.
 * When Magento_InventorySales is not installed, they will be null and legacy stock is used.
 */
class StockFilter
{
    /**
     * @param object|null $areProductsSalable AreProductsSalableInterface (nullable, injected via di.xml)
     * @param object|null $stockResolver StockResolverInterface (nullable, injected via di.xml)
     */
    public function __construct(
        private readonly Config $config,
        private readonly AttributeResolver $attributeResolver,
        private readonly ModuleManager $moduleManager,
        private readonly StoreManagerInterface $storeManager,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly LoggerInterface $logger,
        private readonly ?object $areProductsSalable = null,
        private readonly ?object $stockResolver = null
    ) {
    }

    /**
     * Get color option IDs that have at least one salable simple child.
     *
     * @param Product $product Configurable product
     * @return int[] Option IDs with stock
     */
    public function getColorsWithStock(Product $product, int|string|null $storeId = null): array
    {
        if ($product->getTypeId() !== Configurable::TYPE_CODE) {
            return [];
        }

        $colorAttributeCode = $this->attributeResolver->resolveForProduct($product, $storeId);
        if ($colorAttributeCode === null) {
            return [];
        }

        /** @var Configurable $typeInstance */
        $typeInstance = $product->getTypeInstance();
        $children = $typeInstance->getUsedProducts($product);

        $colorsWithStock = [];

        foreach ($children as $child) {
            $colorValue = $child->getData($colorAttributeCode);
            if ($colorValue === null) {
                continue;
            }

            $colorOptionId = (int) $colorValue;

            // Skip if already confirmed as having stock
            if (in_array($colorOptionId, $colorsWithStock, true)) {
                continue;
            }

            if ($this->isChildSalable($child, $storeId)) {
                $colorsWithStock[] = $colorOptionId;
            }
        }

        if ($this->config->isDebugMode($storeId)) {
            $this->logger->debug('Rollpix ConfigurableGallery: Colors with stock', [
                'product_id' => $product->getId(),
                'colors_with_stock' => $colorsWithStock,
            ]);
        }

        return $colorsWithStock;
    }

    /**
     * Filter a color mapping array to only include colors with stock.
     *
     * @param array<string, array> $colorMapping As returned by ColorMapping::getColorMediaMapping()
     * @param int[] $colorsWithStock As returned by getColorsWithStock()
     * @param string $outOfStockBehavior 'hide' or 'dim'
     * @return array<string, array> Filtered mapping
     */
    public function filterColorMapping(
        array $colorMapping,
        array $colorsWithStock,
        string $outOfStockBehavior = 'hide'
    ): array {
        $filtered = [];

        foreach ($colorMapping as $key => $media) {
            // Generic images (null key) always pass through
            if ($key === 'null') {
                $filtered[$key] = $media;
                continue;
            }

            $optionId = (int) $key;

            if (in_array($optionId, $colorsWithStock, true)) {
                $filtered[$key] = $media;
            } elseif ($outOfStockBehavior === 'dim') {
                // Mark as out of stock but still include
                $media['out_of_stock'] = true;
                $filtered[$key] = $media;
            }
            // 'hide' behavior: simply exclude from result
        }

        return $filtered;
    }

    /**
     * Check if a simple product child is salable.
     */
    private function isChildSalable(Product $child, int|string|null $storeId): bool
    {
        try {
            // Try MSI first if available and injected
            if ($this->areProductsSalable !== null
                && $this->stockResolver !== null
                && $this->moduleManager->isEnabled('Magento_InventorySales')
            ) {
                return $this->isChildSalableMsi($child, $storeId);
            }
        } catch (\Exception $e) {
            // MSI not available or failed, fall through to legacy
            $this->logger->debug('Rollpix ConfigurableGallery: MSI check failed, using legacy stock', [
                'sku' => $child->getSku(),
                'exception' => $e->getMessage(),
            ]);
        }

        // Legacy stock check
        return $this->isChildSalableLegacy($child);
    }

    private function isChildSalableMsi(Product $child, int|string|null $storeId): bool
    {
        $websiteCode = $this->storeManager->getWebsite(
            $this->storeManager->getStore($storeId)->getWebsiteId()
        )->getCode();

        $stock = $this->stockResolver->execute(
            \Magento\InventorySalesApi\Api\Data\SalesChannelInterface::TYPE_WEBSITE,
            $websiteCode
        );
        $results = $this->areProductsSalable->execute([$child->getSku()], $stock->getStockId());

        foreach ($results as $result) {
            return $result->isSalable();
        }

        return false;
    }

    private function isChildSalableLegacy(Product $child): bool
    {
        try {
            $stockItem = $this->stockRegistry->getStockItem($child->getId());
            return $stockItem->getIsInStock();
        } catch (\Exception $e) {
            $this->logger->error('Rollpix ConfigurableGallery: Legacy stock check failed', [
                'product_id' => $child->getId(),
                'exception' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
