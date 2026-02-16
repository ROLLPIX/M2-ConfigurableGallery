<?php

declare(strict_types=1);

namespace Rollpix\ConfigurableGallery\Model\Config\Source;

use Magento\Catalog\Model\Product;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Source model for the color_attribute_code config field.
 * Lists all product select/multiselect attributes suitable for color mapping:
 * - Swatch attributes (visual or text)
 * - Regular select attributes used as configurable super attributes
 * - Any select/multiselect product attribute (admin may use a custom one)
 */
class SwatchAttributes implements OptionSourceInterface
{
    public function __construct(
        private readonly AttributeRepositoryInterface $attributeRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    public function toOptionArray(): array
    {
        $options = [];

        try {
            $searchCriteria = $this->searchCriteriaBuilder
                ->addFilter('entity_type_id', 4) // catalog_product
                ->addFilter('frontend_input', ['select', 'multiselect'], 'in')
                ->create();

            $attributes = $this->attributeRepository->getList(
                Product::ENTITY,
                $searchCriteria
            );

            foreach ($attributes->getItems() as $attribute) {
                $code = $attribute->getAttributeCode();
                $label = $attribute->getDefaultFrontendLabel() ?? $code;
                $swatchType = $attribute->getData('swatch_input_type');

                // Build a descriptive suffix
                $suffix = $code;
                if ($swatchType === 'visual') {
                    $suffix .= ', swatch visual';
                } elseif ($swatchType === 'text') {
                    $suffix .= ', swatch text';
                }

                $options[] = [
                    'value' => $code,
                    'label' => sprintf('%s (%s)', $label, $suffix),
                ];
            }
        } catch (\Exception $e) {
            // Fallback: at minimum offer 'color'
            $options[] = ['value' => 'color', 'label' => 'Color (color)'];
        }

        if (empty($options)) {
            $options[] = ['value' => 'color', 'label' => 'Color (color)'];
        }

        usort($options, fn(array $a, array $b) => strcmp((string) $a['label'], (string) $b['label']));

        return $options;
    }
}
