<?php

declare(strict_types=1);

namespace Rollpix\ConfigurableGallery\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Gallery\Processor as GalleryProcessor;
use Magento\Catalog\Model\ResourceModel\Product\Gallery as GalleryResource;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem;
use Psr\Log\LoggerInterface;

/**
 * Propagates images from configurable parent to simple children (PRD §6.3).
 *
 * Modes:
 * - automatic: triggered on product save (via observer/plugin, Fase 7)
 * - manual: triggered via CLI command (bin/magento rollpix:gallery:propagate)
 *
 * The propagation creates copies of the parent's images in each simple child,
 * filtered by color. Marks propagated images with a special flag to distinguish
 * from manually uploaded images (to avoid deletion on re-propagation).
 */
class Propagation
{
    private const PROPAGATED_FLAG = 'rollpix_propagated';

    public function __construct(
        private readonly Config $config,
        private readonly AttributeResolver $attributeResolver,
        private readonly ColorMapping $colorMapping,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ResourceConnection $resourceConnection,
        private readonly Filesystem $filesystem,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Propagate images from a configurable parent to its simple children.
     *
     * @param Product $product Configurable product
     * @param bool $dryRun If true, only report what would be done
     * @param bool $cleanFirst Remove previously propagated images before re-propagating
     * @return array Report of actions taken
     */
    public function propagate(Product $product, bool $dryRun = false, ?bool $cleanFirst = null): array
    {
        $report = [
            'product_id' => (int) $product->getId(),
            'sku' => $product->getSku(),
            'actions' => [],
            'errors' => [],
            'dry_run' => $dryRun,
        ];

        if ($product->getTypeId() !== Configurable::TYPE_CODE) {
            $report['errors'][] = 'Product is not configurable';
            return $report;
        }

        $storeId = $product->getStoreId();
        $cleanFirst ??= $this->config->isCleanBeforePropagate($storeId);
        $propagationRoles = $this->config->getPropagationRoles($storeId);
        $colorAttributeCode = $this->attributeResolver->resolveForProduct($product, $storeId);
        if ($colorAttributeCode === null) {
            $report['errors'][] = 'No selector attribute resolves for this product';
            return $report;
        }

        $mediaMapping = $this->colorMapping->getColorMediaMapping($product, $storeId);

        /** @var Configurable $typeInstance */
        $typeInstance = $product->getTypeInstance();
        $children = $typeInstance->getUsedProducts($product);

        foreach ($children as $child) {
            $childColorValue = $child->getData($colorAttributeCode);
            if ($childColorValue === null) {
                $report['actions'][] = sprintf(
                    'SKIP child %s: no color attribute value',
                    $child->getSku()
                );
                continue;
            }

            $colorKey = (string) (int) $childColorValue;
            $colorMedia = $mediaMapping[$colorKey] ?? null;
            $genericMedia = $mediaMapping['null'] ?? null;

            // Combine color-specific and generic images
            $imagesToPropagate = [];
            if ($colorMedia !== null) {
                $imagesToPropagate = array_merge(
                    $colorMedia['images'] ?? [],
                    $colorMedia['videos'] ?? []
                );
            }
            if ($genericMedia !== null) {
                $imagesToPropagate = array_merge(
                    $imagesToPropagate,
                    $genericMedia['images'] ?? [],
                    $genericMedia['videos'] ?? []
                );
            }

            if (empty($imagesToPropagate)) {
                $report['actions'][] = sprintf(
                    'SKIP child %s (color %s): no images to propagate',
                    $child->getSku(),
                    $colorKey
                );
                continue;
            }

            if ($cleanFirst && !$dryRun) {
                $cleanedCount = $this->cleanPropagatedImages($child);
                if ($cleanedCount > 0) {
                    $report['actions'][] = sprintf(
                        'CLEAN child %s: removed %d previously propagated images',
                        $child->getSku(),
                        $cleanedCount
                    );
                }
            }

            foreach ($imagesToPropagate as $image) {
                $file = $image['file'] ?? null;
                if ($file === null) {
                    continue;
                }

                if ($dryRun) {
                    $report['actions'][] = sprintf(
                        'WOULD propagate %s → child %s',
                        $file,
                        $child->getSku()
                    );
                    continue;
                }

                try {
                    $this->propagateImage($child, $file, $propagationRoles, $image);
                    $report['actions'][] = sprintf(
                        'PROPAGATED %s → child %s',
                        $file,
                        $child->getSku()
                    );
                } catch (\Exception $e) {
                    $report['errors'][] = sprintf(
                        'ERROR propagating %s → child %s: %s',
                        $file,
                        $child->getSku(),
                        $e->getMessage()
                    );
                }
            }

            // Save the child product if we made changes
            if (!$dryRun) {
                try {
                    $this->productRepository->save($child);
                } catch (\Exception $e) {
                    $report['errors'][] = sprintf(
                        'ERROR saving child %s: %s',
                        $child->getSku(),
                        $e->getMessage()
                    );
                }
            }
        }

        return $report;
    }

    /**
     * Propagate a single image to a child product.
     *
     * @param Product $child Child simple product
     * @param string $file Image file path (relative, e.g. /r/e/remera-roja-1.jpg)
     * @param string[] $roles Image roles to assign
     * @param array $imageData Original image entry data
     */
    private function propagateImage(Product $child, string $file, array $roles, array $imageData): void
    {
        $mediaDir = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
        $absolutePath = $mediaDir->getAbsolutePath('catalog/product' . $file);

        if (!$mediaDir->isFile('catalog/product' . $file)) {
            throw new \RuntimeException(sprintf('Source file not found: %s', $absolutePath));
        }

        // Check if this image already exists in the child
        $existingGallery = $child->getMediaGalleryEntries() ?? [];
        foreach ($existingGallery as $entry) {
            if ($entry->getFile() === $file) {
                // Image already exists, skip
                return;
            }
        }

        // Add the image to the child via the media gallery
        $child->addImageToMediaGallery(
            $absolutePath,
            $this->shouldBeFirstImage($child) ? $roles : [],
            false, // move
            false  // exclude
        );

        // Mark as propagated in DB
        $this->markAsPropagated($child, $file);
    }

    /**
     * Check if this would be the first image on the child (to assign roles).
     */
    private function shouldBeFirstImage(Product $child): bool
    {
        $gallery = $child->getMediaGalleryEntries();
        return empty($gallery);
    }

    /**
     * Mark an image as propagated by Rollpix (to distinguish from manual uploads).
     */
    private function markAsPropagated(Product $child, string $file): void
    {
        // Store the propagation flag in associated_attributes with a special prefix
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('catalog_product_entity_media_gallery_value');
        $galleryTable = $this->resourceConnection->getTableName('catalog_product_entity_media_gallery');

        $valueId = $connection->fetchOne(
            $connection->select()
                ->from($galleryTable, ['value_id'])
                ->where('value = ?', $file)
        );

        if ($valueId) {
            // We'll use a special comment in the label or a separate mechanism
            // For now, store in the DB to track propagated images
            $connection->update(
                $tableName,
                ['label' => self::PROPAGATED_FLAG],
                [
                    'value_id = ?' => (int) $valueId,
                    'entity_id = ?' => (int) $child->getId(),
                ]
            );
        }
    }

    /**
     * Remove previously propagated images from a child product.
     *
     * @return int Number of images removed
     */
    private function cleanPropagatedImages(Product $child): int
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('catalog_product_entity_media_gallery_value');
        $galleryTable = $this->resourceConnection->getTableName('catalog_product_entity_media_gallery');
        $toEntityTable = $this->resourceConnection->getTableName('catalog_product_entity_media_gallery_value_to_entity');

        // Find propagated images for this child
        $select = $connection->select()
            ->from(['mgv' => $tableName], ['value_id'])
            ->join(
                ['mgvte' => $toEntityTable],
                'mgv.value_id = mgvte.value_id',
                []
            )
            ->where('mgvte.entity_id = ?', (int) $child->getId())
            ->where('mgv.label = ?', self::PROPAGATED_FLAG);

        $valueIds = $connection->fetchCol($select);

        if (empty($valueIds)) {
            return 0;
        }

        // Remove the gallery entries for this child
        $connection->delete(
            $toEntityTable,
            [
                'entity_id = ?' => (int) $child->getId(),
                'value_id IN (?)' => $valueIds,
            ]
        );

        return count($valueIds);
    }
}
