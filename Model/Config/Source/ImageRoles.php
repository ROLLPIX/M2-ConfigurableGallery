<?php

declare(strict_types=1);

namespace Rollpix\ConfigurableGallery\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ImageRoles implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'image', 'label' => __('Imagen Base (image)')],
            ['value' => 'small_image', 'label' => __('Imagen Pequeña (small_image)')],
            ['value' => 'thumbnail', 'label' => __('Thumbnail (thumbnail)')],
            ['value' => 'swatch_image', 'label' => __('Imagen de Swatch (swatch_image)')],
        ];
    }
}
