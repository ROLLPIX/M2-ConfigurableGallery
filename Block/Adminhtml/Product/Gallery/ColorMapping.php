<?php

declare(strict_types=1);

namespace Rollpix\ConfigurableGallery\Block\Adminhtml\Product\Gallery;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Rollpix\ConfigurableGallery\Model\AttributeResolver;
use Rollpix\ConfigurableGallery\Model\ColorMapping as ColorMappingModel;
use Rollpix\ConfigurableGallery\Model\Config;

/**
 * Block for rendering color mapping dropdown in admin product gallery (PRD §6.1).
 * Provides color options data to the JavaScript component that adds
 * a dropdown per image/video in the gallery panel.
 */
class ColorMapping extends Template
{
    protected $_template = 'Rollpix_ConfigurableGallery::product/gallery/color_mapping.phtml';

    public function __construct(
        Context $context,
        private readonly Registry $registry,
        private readonly Config $config,
        private readonly AttributeResolver $attributeResolver,
        private readonly ColorMappingModel $colorMapping,
        private readonly JsonSerializer $jsonSerializer,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Get current product from registry.
     */
    public function getProduct(): ?Product
    {
        return $this->registry->registry('current_product');
    }

    /**
     * Check if the block should render (only for configurable products with module enabled).
     */
    public function shouldRender(): bool
    {
        if (!$this->config->isEnabled()) {
            return false;
        }

        $product = $this->getProduct();
        if ($product === null) {
            return false;
        }

        if ($product->getTypeId() !== Configurable::TYPE_CODE) {
            return false;
        }

        // Only render if a selector attribute resolves for this product
        return $this->attributeResolver->resolveForProduct($product) !== null;
    }

    /**
     * Get color options as JSON for the JS component.
     * Returns array of {value: option_id, label: color_label}.
     */
    public function getColorOptionsJson(): string
    {
        $product = $this->getProduct();
        if ($product === null) {
            return '[]';
        }

        $labels = $this->colorMapping->getColorOptionLabels($product);

        $options = [
            ['value' => '', 'label' => __('Sin asignar (todos los colores)')],
        ];

        foreach ($labels as $optionId => $label) {
            $options[] = [
                'value' => $optionId,
                'label' => $label,
            ];
        }

        return $this->jsonSerializer->serialize($options);
    }

    /**
     * Get the resolved selector attribute ID for the current product.
     */
    public function getColorAttributeId(): ?int
    {
        $product = $this->getProduct();
        if ($product === null) {
            return null;
        }

        return $this->attributeResolver->resolveAttributeId($product);
    }

    /**
     * Get existing mapping data as JSON for pre-populating dropdowns.
     * Returns {value_id: "attribute{ID}-{OPTION_ID}", ...}
     */
    public function getExistingMappingJson(): string
    {
        $product = $this->getProduct();
        if ($product === null) {
            return '{}';
        }

        $mediaMapping = $this->colorMapping->getColorMediaMapping($product);
        $existingMapping = [];

        foreach ($mediaMapping as $optionKey => $media) {
            $allEntries = array_merge($media['images'] ?? [], $media['videos'] ?? []);
            foreach ($allEntries as $entry) {
                $valueId = $entry['value_id'] ?? null;
                $associatedAttributes = $entry['associated_attributes'] ?? null;
                if ($valueId !== null) {
                    $existingMapping[(string) $valueId] = $associatedAttributes ?? '';
                }
            }
        }

        return $this->jsonSerializer->serialize($existingMapping);
    }
}
