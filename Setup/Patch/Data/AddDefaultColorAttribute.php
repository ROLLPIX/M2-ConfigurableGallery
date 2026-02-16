<?php

declare(strict_types=1);

namespace Rollpix\ConfigurableGallery\Setup\Patch\Data;

use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;

class AddDefaultColorAttribute implements DataPatchInterface, PatchRevertableInterface
{
    private const ATTRIBUTE_CODE = 'rollpix_default_color';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {
    }

    public function apply(): self
    {
        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $eavSetup->addAttribute(
            \Magento\Catalog\Model\Product::ENTITY,
            self::ATTRIBUTE_CODE,
            [
                'type' => 'int',
                'label' => 'Color Default de Galería',
                'input' => 'select',
                'required' => false,
                'default' => null,
                'global' => ScopedAttributeInterface::SCOPE_STORE,
                'group' => 'Rollpix Gallery',
                'sort_order' => 20,
                'visible' => true,
                'user_defined' => false,
                'searchable' => false,
                'filterable' => false,
                'comparable' => false,
                'visible_on_front' => false,
                'used_in_product_listing' => false,
                'note' => 'Option ID del color a preseleccionar. Dejar vacío para auto-detección (primer color con stock).',
                'apply_to' => 'configurable',
            ]
        );

        return $this;
    }

    public function revert(): void
    {
        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);
        $eavSetup->removeAttribute(\Magento\Catalog\Model\Product::ENTITY, self::ATTRIBUTE_CODE);
    }

    public static function getDependencies(): array
    {
        return [AddGalleryEnabledAttribute::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
