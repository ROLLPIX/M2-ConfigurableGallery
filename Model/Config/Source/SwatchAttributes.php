<?php

declare(strict_types=1);

namespace Rollpix\ConfigurableGallery\Model\Config\Source;

use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory as AttributeCollectionFactory;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Source model for the selector_attributes config field.
 * Lists all product select/multiselect attributes using the catalog attribute
 * collection (more reliable than the EAV repository API for filtered queries).
 */
class SwatchAttributes implements OptionSourceInterface
{
    public function __construct(
        private readonly AttributeCollectionFactory $attributeCollectionFactory
    ) {
    }

    public function toOptionArray(): array
    {
        $options = [];

        try {
            $collection = $this->attributeCollectionFactory->create();
            $collection->addFieldToFilter('frontend_input', ['in' => ['select', 'multiselect']]);
            $collection->addFieldToFilter('frontend_label', ['neq' => '']);
            $collection->setOrder('frontend_label', 'ASC');

            foreach ($collection as $attribute) {
                $code = $attribute->getAttributeCode();
                $label = $attribute->getFrontendLabel() ?: $code;

                $options[] = [
                    'value' => $code,
                    'label' => sprintf('%s (%s)', $label, $code),
                ];
            }
        } catch (\Exception $e) {
            // Fallback
            $options[] = ['value' => 'color', 'label' => 'Color (color)'];
        }

        if (empty($options)) {
            $options[] = ['value' => 'color', 'label' => 'Color (color)'];
        }

        return $options;
    }
}
