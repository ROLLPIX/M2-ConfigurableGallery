<?php

declare(strict_types=1);

namespace Rollpix\ConfigurableGallery\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class GalleryAdapter implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'auto', 'label' => __('Auto (detección automática)')],
            ['value' => 'fotorama', 'label' => __('Fotorama (nativa Magento)')],
            ['value' => 'rollpix', 'label' => __('Rollpix ProductGallery')],
            ['value' => 'amasty', 'label' => __('Amasty Color Swatches Pro')],
        ];
    }
}
