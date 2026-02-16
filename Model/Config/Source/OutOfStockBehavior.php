<?php

declare(strict_types=1);

namespace Rollpix\ConfigurableGallery\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class OutOfStockBehavior implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'hide', 'label' => __('Ocultar imágenes')],
            ['value' => 'dim', 'label' => __('Mostrar con opacidad reducida')],
        ];
    }
}
