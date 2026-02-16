<?php

declare(strict_types=1);

namespace Rollpix\ConfigurableGallery\Model;

use Magento\Catalog\Model\Product;
use Psr\Log\LoggerInterface;

/**
 * Determines the default color to preselect when loading a product page (PRD §6.7).
 *
 * Simple criteria: first available color in position order.
 * URL parameter (#color= or ?color=) has highest priority but is handled in frontend JS.
 */
class ColorPreselect
{
    public function __construct(
        private readonly Config $config,
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

        // Simple: return first available color in position order
        $firstColor = reset($availableColorOptionIds);

        return $firstColor !== false ? (int) $firstColor : null;
    }
}
