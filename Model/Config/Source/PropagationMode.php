<?php

declare(strict_types=1);

namespace Rollpix\ConfigurableGallery\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class PropagationMode implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'disabled', 'label' => __('Desactivada')],
            ['value' => 'automatic', 'label' => __('Automática (al guardar)')],
            ['value' => 'manual', 'label' => __('Manual (solo CLI)')],
        ];
    }
}
