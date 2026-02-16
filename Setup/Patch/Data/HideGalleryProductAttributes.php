<?php

declare(strict_types=1);

namespace Rollpix\ConfigurableGallery\Setup\Patch\Data;

use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * v1.0.10: Hide rollpix_gallery_enabled and rollpix_default_color from admin product form.
 *
 * These attributes are no longer used (filtering is always active for all configurables,
 * preselection is controlled globally). We hide them instead of deleting to avoid
 * issues with existing data.
 */
class HideGalleryProductAttributes implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {
    }

    public function apply(): self
    {
        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $entityTypeId = $eavSetup->getEntityTypeId(\Magento\Catalog\Model\Product::ENTITY);

        foreach (['rollpix_gallery_enabled', 'rollpix_default_color'] as $attributeCode) {
            $attributeId = $eavSetup->getAttributeId($entityTypeId, $attributeCode);
            if ($attributeId) {
                $eavSetup->updateAttribute($entityTypeId, $attributeCode, 'is_visible', false);
                $eavSetup->updateAttribute($entityTypeId, $attributeCode, 'used_in_product_listing', false);
                $eavSetup->updateAttribute($entityTypeId, $attributeCode, 'is_used_in_grid', false);
                $eavSetup->updateAttribute($entityTypeId, $attributeCode, 'is_visible_in_grid', false);
                $eavSetup->updateAttribute($entityTypeId, $attributeCode, 'is_filterable_in_grid', false);
            }
        }

        return $this;
    }

    public static function getDependencies(): array
    {
        return [
            AddGalleryEnabledAttribute::class,
            AddDefaultColorAttribute::class,
        ];
    }

    public function getAliases(): array
    {
        return [];
    }
}
