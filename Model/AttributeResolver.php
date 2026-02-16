<?php

declare(strict_types=1);

namespace Rollpix\ConfigurableGallery\Model;

use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves which selector attribute to use for a given configurable product.
 *
 * The admin configures an ordered list of "selector attributes" (e.g. color, rollpix_erp_color, medida).
 * For each configurable product, this class checks which of those attributes is actually used
 * as a super attribute (variation axis) and returns the first match.
 *
 * This allows the module to work with products that use different attributes for their gallery mapping.
 */
class AttributeResolver
{
    /** @var array<string, string|null> Cache: "productId-storeId" => attributeCode|null */
    private array $resolvedCache = [];

    /** @var array<string, int> Cache: attributeCode => attributeId */
    private array $attributeIdCache = [];

    public function __construct(
        private readonly Config $config,
        private readonly AttributeRepositoryInterface $attributeRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Resolve which selector attribute to use for a given configurable product.
     *
     * Checks the product's super attributes against the configured priority list
     * and returns the first match.
     *
     * @return string|null The attribute code, or null if no match found
     */
    public function resolveForProduct(Product $product, int|string|null $storeId = null): ?string
    {
        if ($product->getTypeId() !== Configurable::TYPE_CODE) {
            return null;
        }

        $cacheKey = $product->getId() . '-' . ($storeId ?? 'default');
        if (array_key_exists($cacheKey, $this->resolvedCache)) {
            return $this->resolvedCache[$cacheKey];
        }

        $selectorAttributes = $this->config->getSelectorAttributes($storeId);
        if (empty($selectorAttributes)) {
            $this->resolvedCache[$cacheKey] = null;
            return null;
        }

        // Get the product's super attribute codes (variation axes)
        $superAttributeCodes = $this->getSuperAttributeCodes($product);

        // Return first configured selector that is also a super attribute
        $result = null;
        foreach ($selectorAttributes as $selectorCode) {
            if (in_array($selectorCode, $superAttributeCodes, true)) {
                $result = $selectorCode;
                break;
            }
        }

        if ($result !== null && $this->config->isDebugMode($storeId)) {
            $this->logger->debug('Rollpix ConfigurableGallery: Resolved selector attribute', [
                'product_id' => $product->getId(),
                'resolved' => $result,
                'configured' => $selectorAttributes,
                'super_attributes' => $superAttributeCodes,
            ]);
        }

        $this->resolvedCache[$cacheKey] = $result;
        return $result;
    }

    /**
     * Get the EAV attribute ID for the resolved selector attribute.
     *
     * @return int|null The attribute ID, or null if no attribute resolves
     */
    public function resolveAttributeId(Product $product, int|string|null $storeId = null): ?int
    {
        $code = $this->resolveForProduct($product, $storeId);
        if ($code === null) {
            return null;
        }

        return $this->getAttributeIdByCode($code);
    }

    /**
     * Get the EAV attribute ID for a given attribute code.
     */
    public function getAttributeIdByCode(string $attributeCode): ?int
    {
        if (isset($this->attributeIdCache[$attributeCode])) {
            return $this->attributeIdCache[$attributeCode];
        }

        try {
            $attribute = $this->attributeRepository->get(
                Product::ENTITY,
                $attributeCode
            );
            $id = (int) $attribute->getAttributeId();
            $this->attributeIdCache[$attributeCode] = $id;
            return $id;
        } catch (\Exception $e) {
            $this->logger->error(
                'Rollpix ConfigurableGallery: Failed to get attribute ID',
                ['attribute_code' => $attributeCode, 'exception' => $e->getMessage()]
            );
            return null;
        }
    }

    /**
     * Get super attribute codes for a configurable product.
     *
     * @return string[]
     */
    private function getSuperAttributeCodes(Product $product): array
    {
        try {
            /** @var Configurable $typeInstance */
            $typeInstance = $product->getTypeInstance();
            $superAttributes = $typeInstance->getConfigurableAttributes($product);

            $codes = [];
            foreach ($superAttributes as $superAttribute) {
                $productAttribute = $superAttribute->getProductAttribute();
                if ($productAttribute !== null) {
                    $codes[] = $productAttribute->getAttributeCode();
                }
            }

            return $codes;
        } catch (\Exception $e) {
            $this->logger->error(
                'Rollpix ConfigurableGallery: Failed to get super attribute codes',
                ['product_id' => $product->getId(), 'exception' => $e->getMessage()]
            );
            return [];
        }
    }
}
