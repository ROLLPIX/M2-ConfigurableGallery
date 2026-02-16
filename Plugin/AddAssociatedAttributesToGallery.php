<?php

declare(strict_types=1);

namespace Rollpix\ConfigurableGallery\Plugin;

use Magento\Catalog\Model\ResourceModel\Product\Gallery;
use Magento\Framework\DB\Select;
use Psr\Log\LoggerInterface;
use Rollpix\ConfigurableGallery\Model\Config;

/**
 * Plugin on Gallery ResourceModel to include associated_attributes column in queries.
 * PRD §6.2 — afterLoad: includes column in read queries.
 *
 * sortOrder=10: base plugin, no conflicts expected.
 */
class AddAssociatedAttributesToGallery
{
    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * After creating the select for media gallery, add associated_attributes column.
     *
     * @param Gallery $subject
     * @param Select $result
     * @return Select
     */
    public function afterCreateBatchBaseSelect(Gallery $subject, Select $result): Select
    {
        $result->columns(['associated_attributes' => 'value.associated_attributes']);

        return $result;
    }
}
