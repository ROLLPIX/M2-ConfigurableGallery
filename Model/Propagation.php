<?php

declare(strict_types=1);

namespace Rollpix\ConfigurableGallery\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem;
use Psr\Log\LoggerInterface;

/**
 * Propagates images from configurable parent to simple children (PRD §6.3).
 *
 * Modes:
 * - automatic: triggered on product save (via observer, Fase 7)
 * - manual: triggered via CLI command (bin/magento rollpix:gallery:propagate)
 *
 * The propagation creates copies of the parent's images in each simple child,
 * filtered by color. When cleanFirst is enabled, ALL existing images on the
 * child are removed before re-propagating (ensures a clean slate).
 */
class Propagation
{
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
     * @param bool $cleanFirst Remove all child images before re-propagating
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

        // IS-6180 — when cleanFirst is on, only delete child images whose file
        // path also exists on the parent (= previously propagated). Images
        // uploaded directly to the child (e.g. by Magento's "Edit configurations"
        // wizard) have file paths the parent doesn't know about; those must
        // survive. Fetch the parent's gallery file paths once for reuse.
        $parentFilePaths = ($cleanFirst && !$dryRun)
            ? $this->getParentMediaPaths($product)
            : [];

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

            $childChanged = false;

            if ($cleanFirst && !$dryRun && !empty($parentFilePaths)) {
                $cleanedCount = $this->removePropagatedFromChild($child, $parentFilePaths);
                if ($cleanedCount > 0) {
                    $childChanged = true;
                    // Drop only the previously-propagated entries from the
                    // in-memory gallery so the subsequent dedup + save sees
                    // the clean slate for parent images, while child-direct
                    // uploads remain intact and get persisted again on save.
                    $this->filterChildMemoryGallery($child, $parentFilePaths);
                    $report['actions'][] = sprintf(
                        'CLEAN child %s: removed %d propagated images (kept child-direct)',
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
                    $added = $this->propagateImage($child, $file, $propagationRoles, $image);
                    if ($added) {
                        $childChanged = true;
                        $report['actions'][] = sprintf(
                            'PROPAGATED %s → child %s',
                            $file,
                            $child->getSku()
                        );
                    }
                } catch (\Exception $e) {
                    $report['errors'][] = sprintf(
                        'ERROR propagating %s → child %s: %s',
                        $file,
                        $child->getSku(),
                        $e->getMessage()
                    );
                }
            }

            // Only save the child if we actually made changes
            if ($childChanged && !$dryRun) {
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
     * @return bool True if the image was added, false if skipped (already exists)
     */
    private function propagateImage(Product $child, string $file, array $roles, array $imageData): bool
    {
        $mediaDir = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
        $absolutePath = $mediaDir->getAbsolutePath('catalog/product' . $file);

        if (!$mediaDir->isFile('catalog/product' . $file)) {
            throw new \RuntimeException(sprintf('Source file not found: %s', $absolutePath));
        }

        // Check if this image already exists in the child (dedup by file path)
        $existingGallery = $child->getMediaGalleryEntries() ?? [];
        foreach ($existingGallery as $entry) {
            if ($entry->getFile() === $file) {
                return false;
            }
        }

        // Add the image to the child via the media gallery
        $child->addImageToMediaGallery(
            $absolutePath,
            $this->shouldBeFirstImage($child) ? $roles : [],
            false, // move
            false  // exclude
        );

        return true;
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
     * Remove ALL images from simple children of a configurable product.
     *
     * @param Product $product Configurable product
     * @param bool $dryRun If true, only report what would be done
     * @return array Report of actions taken
     */
    public function cleanChildren(Product $product, bool $dryRun = false): array
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

        /** @var Configurable $typeInstance */
        $typeInstance = $product->getTypeInstance();
        $children = $typeInstance->getUsedProducts($product);

        foreach ($children as $child) {
            try {
                $count = $this->removeAllImages($child, $dryRun);
                if ($count > 0) {
                    $report['actions'][] = sprintf(
                        '%s child %s (ID %d): %d images',
                        $dryRun ? 'WOULD CLEAN' : 'CLEANED',
                        $child->getSku(),
                        $child->getId(),
                        $count
                    );
                } else {
                    $report['actions'][] = sprintf(
                        'SKIP child %s (ID %d): no images',
                        $child->getSku(),
                        $child->getId()
                    );
                }
            } catch (\Exception $e) {
                $report['errors'][] = sprintf(
                    'ERROR cleaning child %s: %s',
                    $child->getSku(),
                    $e->getMessage()
                );
            }
        }

        return $report;
    }

    /**
     * Return the file paths currently in the parent's media gallery.
     *
     * Used by smart cleanup to distinguish previously-propagated child images
     * (paths the parent also has) from images uploaded directly to the child
     * (paths the parent has never had).
     *
     * @return string[]
     */
    private function getParentMediaPaths(Product $parent): array
    {
        $connection = $this->resourceConnection->getConnection();
        $galleryTable = $this->resourceConnection->getTableName('catalog_product_entity_media_gallery');
        $toEntityTable = $this->resourceConnection->getTableName(
            'catalog_product_entity_media_gallery_value_to_entity'
        );

        return $connection->fetchCol(
            $connection->select()
                ->from(['g' => $galleryTable], ['value'])
                ->join(['te' => $toEntityTable], 'g.value_id = te.value_id', [])
                ->where('te.entity_id = ?', (int) $parent->getId())
        );
    }

    /**
     * Remove from a child only the gallery rows whose file path also exists
     * on the parent (= came from a previous propagation). Files uploaded
     * directly to the child are left alone.
     *
     * Mirrors removeAllImages() three-table cleanup but scoped by file path.
     *
     * @param string[] $parentFilePaths
     * @return int Number of child gallery rows removed
     */
    private function removePropagatedFromChild(Product $child, array $parentFilePaths): int
    {
        if (empty($parentFilePaths)) {
            return 0;
        }

        $connection = $this->resourceConnection->getConnection();
        $galleryTable = $this->resourceConnection->getTableName('catalog_product_entity_media_gallery');
        $galleryValueTable = $this->resourceConnection->getTableName('catalog_product_entity_media_gallery_value');
        $toEntityTable = $this->resourceConnection->getTableName(
            'catalog_product_entity_media_gallery_value_to_entity'
        );

        $entityId = (int) $child->getId();

        $valueIds = $connection->fetchCol(
            $connection->select()
                ->from(['te' => $toEntityTable], ['te.value_id'])
                ->join(['g' => $galleryTable], 'g.value_id = te.value_id', [])
                ->where('te.entity_id = ?', $entityId)
                ->where('g.value IN (?)', $parentFilePaths)
        );

        if (empty($valueIds)) {
            return 0;
        }

        $connection->delete($galleryValueTable, ['value_id IN (?)' => $valueIds]);
        $connection->delete(
            $toEntityTable,
            ['entity_id = ?' => $entityId, 'value_id IN (?)' => $valueIds]
        );

        foreach ($valueIds as $valueId) {
            $stillLinked = (int) $connection->fetchOne(
                $connection->select()
                    ->from($toEntityTable, [new \Zend_Db_Expr('COUNT(*)')])
                    ->where('value_id = ?', (int) $valueId)
            );
            if ($stillLinked === 0) {
                $connection->delete($galleryTable, ['value_id = ?' => (int) $valueId]);
            }
        }

        return count($valueIds);
    }

    /**
     * Drop the propagated entries from a child's in-memory media_gallery so
     * the subsequent productRepository->save() persists exactly: child-direct
     * uploads (preserved) + the new propagation pass (added below).
     *
     * Without this, after we DB-delete propagated rows the in-memory state
     * still references the now-deleted entries, and the save would put them
     * back.
     *
     * @param string[] $parentFilePaths
     */
    private function filterChildMemoryGallery(Product $child, array $parentFilePaths): void
    {
        $gallery = $child->getMediaGallery('images') ?: [];
        $keptImages = [];
        foreach ($gallery as $key => $img) {
            $file = $img['file'] ?? null;
            if ($file === null || !in_array($file, $parentFilePaths, true)) {
                $keptImages[$key] = $img;
            }
        }
        $child->setData('media_gallery', ['images' => $keptImages, 'values' => []]);

        $entries = $child->getMediaGalleryEntries() ?? [];
        $keptEntries = [];
        foreach ($entries as $entry) {
            if (!in_array($entry->getFile(), $parentFilePaths, true)) {
                $keptEntries[] = $entry;
            }
        }
        $child->setData('media_gallery_entries', $keptEntries);
    }

    /**
     * Remove ALL gallery images from a product via direct DB delete.
     *
     * Used by the standalone {@see cleanChildren()} CLI command (a "wipe
     * everything" operation by design). The propagation path uses the more
     * targeted {@see removePropagatedFromChild()} instead.
     *
     * Deletes from all three gallery tables:
     * 1. catalog_product_entity_media_gallery_value (store-specific data)
     * 2. catalog_product_entity_media_gallery_value_to_entity (entity linkage)
     * 3. catalog_product_entity_media_gallery (main, only orphaned rows)
     *
     * @return int Number of images removed
     */
    private function removeAllImages(Product $product, bool $dryRun = false): int
    {
        $connection = $this->resourceConnection->getConnection();
        $galleryTable = $this->resourceConnection->getTableName('catalog_product_entity_media_gallery');
        $galleryValueTable = $this->resourceConnection->getTableName('catalog_product_entity_media_gallery_value');
        $toEntityTable = $this->resourceConnection->getTableName('catalog_product_entity_media_gallery_value_to_entity');

        $entityId = (int) $product->getId();

        // Get value_ids linked to this product (needed for main table cleanup)
        $valueIds = $connection->fetchCol(
            $connection->select()
                ->from($toEntityTable, ['value_id'])
                ->where('entity_id = ?', $entityId)
        );

        if (empty($valueIds) || $dryRun) {
            return count($valueIds);
        }

        // 1. Remove store-specific data (FK child of main)
        $connection->delete($galleryValueTable, ['value_id IN (?)' => $valueIds]);

        // 2. Remove entity linkage (FK child of main)
        $connection->delete($toEntityTable, ['entity_id = ?' => $entityId]);

        // 3. Remove orphaned rows from main gallery table
        //    (only if the value_id is no longer linked to ANY other product)
        foreach ($valueIds as $valueId) {
            $stillLinked = (int) $connection->fetchOne(
                $connection->select()
                    ->from($toEntityTable, [new \Zend_Db_Expr('COUNT(*)')])
                    ->where('value_id = ?', (int) $valueId)
            );
            if ($stillLinked === 0) {
                $connection->delete($galleryTable, ['value_id = ?' => (int) $valueId]);
            }
        }

        // Reset image role attributes to 'no_selection'
        $this->resetImageRoles($product);

        return count($valueIds);
    }

    /**
     * Reset image/small_image/thumbnail attributes to 'no_selection'.
     */
    private function resetImageRoles(Product $product): void
    {
        $connection = $this->resourceConnection->getConnection();
        $varcharTable = $this->resourceConnection->getTableName('catalog_product_entity_varchar');
        $entityId = (int) $product->getId();

        $roleAttributeIds = [];
        foreach (['image', 'small_image', 'thumbnail'] as $roleCode) {
            $attrId = (int) $connection->fetchOne(
                $connection->select()
                    ->from($this->resourceConnection->getTableName('eav_attribute'), ['attribute_id'])
                    ->where('attribute_code = ?', $roleCode)
                    ->where('entity_type_id = ?', 4)
            );
            if ($attrId > 0) {
                $roleAttributeIds[] = $attrId;
            }
        }

        if (!empty($roleAttributeIds)) {
            $connection->update(
                $varcharTable,
                ['value' => 'no_selection'],
                [
                    'entity_id = ?' => $entityId,
                    'attribute_id IN (?)' => $roleAttributeIds,
                ]
            );
        }
    }
}
