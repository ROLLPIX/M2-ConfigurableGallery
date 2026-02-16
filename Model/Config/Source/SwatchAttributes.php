<?php

declare(strict_types=1);

namespace Rollpix\ConfigurableGallery\Model\Config\Source;

use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Source model for the color_attribute_code config field.
 * Lists all product attributes that are of type swatch_visual or swatch_text.
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
                \Magento\Catalog\Model\Product::ENTITY,
                $searchCriteria
            );

            foreach ($attributes->getItems() as $attribute) {
                $additionalData = $attribute->getData('additional_data');
                $swatchType = $attribute->getData('swatch_input_type');

                // Include visual and text swatches
                if ($swatchType === 'visual' || $swatchType === 'text'
                    || $attribute->getAttributeCode() === 'color'
                ) {
                    $options[] = [
                        'value' => $attribute->getAttributeCode(),
                        'label' => sprintf(
                            '%s (%s)',
                            $attribute->getDefaultFrontendLabel() ?? $attribute->getAttributeCode(),
                            $attribute->getAttributeCode()
                        ),
                    ];
                }
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
