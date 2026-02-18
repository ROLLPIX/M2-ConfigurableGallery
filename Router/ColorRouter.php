<?php

declare(strict_types=1);

namespace Rollpix\ConfigurableGallery\Router;

use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\RouterInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Rollpix\ConfigurableGallery\Model\Config;
use Rollpix\ConfigurableGallery\Model\SlugGenerator;

/**
 * Custom router for SEO-friendly color URLs.
 *
 * Intercepts paths like /{product-url-key}/{attr-code}/{color-slug}
 * and forwards to catalog/product/view with the color preselected.
 *
 * Registered with sortOrder=25 (before default router at 30).
 */
class ColorRouter implements RouterInterface
{
    public function __construct(
        private readonly ActionFactory $actionFactory,
        private readonly Config $config,
        private readonly SlugGenerator $slugGenerator,
        private readonly ResourceConnection $resourceConnection,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function match(RequestInterface $request): ?\Magento\Framework\App\ActionInterface
    {
        // Prevent infinite loop: Forward re-dispatches through all routers,
        // so skip if we already matched on a previous iteration.
        if ($request->getParam('rollpix_seo_color_option') !== null) {
            return null;
        }

        $storeId = $this->storeManager->getStore()->getId();

        if (!$this->config->isEnabled($storeId)) {
            return null;
        }

        if (!$this->config->isSeoFriendlyUrlEnabled($storeId)) {
            return null;
        }

        $pathInfo = trim($request->getPathInfo(), '/');
        if ($pathInfo === '') {
            return null;
        }

        // Strip URL suffix (e.g. .html)
        $urlSuffix = $this->getUrlSuffix($storeId);
        $pathWithoutSuffix = $pathInfo;
        if ($urlSuffix !== '' && str_ends_with($pathInfo, $urlSuffix)) {
            $pathWithoutSuffix = substr($pathInfo, 0, -strlen($urlSuffix));
        }

        // Need at least 3 segments: product-url / attr-label-slug / color-slug
        $segments = explode('/', $pathWithoutSuffix);
        if (count($segments) < 3) {
            return null;
        }

        // Extract the last 2 segments as attr-label-slug and color-slug
        $colorSlug = array_pop($segments);
        $attrLabelSlug = array_pop($segments);

        // Rebuild the product URL path
        $productPath = implode('/', $segments);
        if ($productPath === '') {
            return null;
        }

        // Resolve the URL segment to a selector attribute code by matching
        // against the slugified frontend label of each selector attribute.
        // e.g. URL segment "color" → attribute code "rollpix_erp_color" (label "Color")
        $selectorAttributes = $this->config->getSelectorAttributes($storeId);
        $attrCode = $this->resolveAttributeCodeFromLabelSlug($attrLabelSlug, $selectorAttributes, $storeId);
        if ($attrCode === null) {
            return null;
        }

        // Look up the product URL in url_rewrite table
        $requestPath = $productPath . $urlSuffix;
        $productId = $this->resolveProductId($requestPath, $storeId);
        if ($productId === null) {
            return null;
        }

        // Resolve the color slug to an option_id
        $optionId = $this->resolveColorOptionId($attrCode, $colorSlug, $storeId);
        if ($optionId === null) {
            return null;
        }

        // Set params for the ViewModel to pick up
        $request->setParam('rollpix_seo_color_option', $optionId);
        $request->setParam('rollpix_seo_color_attribute', $attrCode);

        // Forward to product view
        $request->setModuleName('catalog');
        $request->setControllerName('product');
        $request->setActionName('view');
        $request->setParam('id', $productId);
        $request->setAlias(
            \Magento\Framework\Url::REWRITE_REQUEST_PATH_ALIAS,
            $requestPath
        );

        return $this->actionFactory->create(\Magento\Framework\App\Action\Forward::class);
    }

    /**
     * Resolve a request path to a product ID via the url_rewrite table.
     */
    private function resolveProductId(string $requestPath, int|string $storeId): ?int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('url_rewrite');

        $productId = $connection->fetchOne(
            $connection->select()
                ->from($table, ['entity_id'])
                ->where('request_path = ?', $requestPath)
                ->where('store_id = ?', (int) $storeId)
                ->where('entity_type = ?', 'product')
                ->limit(1)
        );

        return $productId !== false ? (int) $productId : null;
    }

    /**
     * Resolve a color slug to an option_id by matching against EAV option labels.
     */
    private function resolveColorOptionId(string $attrCode, string $slug, int|string $storeId): ?int
    {
        $connection = $this->resourceConnection->getConnection();

        // Get attribute_id for the given code
        $attrTable = $this->resourceConnection->getTableName('eav_attribute');
        $attributeId = (int) $connection->fetchOne(
            $connection->select()
                ->from($attrTable, ['attribute_id'])
                ->where('attribute_code = ?', $attrCode)
                ->where('entity_type_id = ?', 4) // catalog_product
        );

        if ($attributeId === 0) {
            return null;
        }

        // Get all option labels for this attribute
        $optionTable = $this->resourceConnection->getTableName('eav_attribute_option');
        $optionValueTable = $this->resourceConnection->getTableName('eav_attribute_option_value');

        // Get store-specific labels first, fallback to admin (store_id=0)
        $select = $connection->select()
            ->from(['ao' => $optionTable], ['option_id'])
            ->joinLeft(
                ['aov_store' => $optionValueTable],
                'ao.option_id = aov_store.option_id AND aov_store.store_id = ' . (int) $storeId,
                []
            )
            ->joinLeft(
                ['aov_default' => $optionValueTable],
                'ao.option_id = aov_default.option_id AND aov_default.store_id = 0',
                []
            )
            ->columns([
                'label' => new \Zend_Db_Expr('COALESCE(aov_store.value, aov_default.value)')
            ])
            ->where('ao.attribute_id = ?', $attributeId);

        $options = [];
        foreach ($connection->fetchAll($select) as $row) {
            if ($row['label'] !== null && $row['label'] !== '') {
                $options[(int) $row['option_id']] = $row['label'];
            }
        }

        return $this->slugGenerator->resolveOptionId($slug, $options);
    }

    /**
     * Resolve a URL segment (slugified attribute label) to an attribute code.
     * e.g. "color" → "rollpix_erp_color" when attribute has frontend label "Color".
     */
    private function resolveAttributeCodeFromLabelSlug(string $slug, array $selectorAttributes, int|string $storeId): ?string
    {
        $connection = $this->resourceConnection->getConnection();
        $attrTable = $this->resourceConnection->getTableName('eav_attribute');
        $labelTable = $this->resourceConnection->getTableName('eav_attribute_label');

        foreach ($selectorAttributes as $attrCode) {
            $row = $connection->fetchRow(
                $connection->select()
                    ->from($attrTable, ['attribute_id', 'frontend_label'])
                    ->where('attribute_code = ?', $attrCode)
                    ->where('entity_type_id = ?', 4)
            );

            if (!$row) {
                continue;
            }

            // Check store-specific label first, fallback to admin default
            $storeLabel = $connection->fetchOne(
                $connection->select()
                    ->from($labelTable, ['value'])
                    ->where('attribute_id = ?', (int) $row['attribute_id'])
                    ->where('store_id = ?', (int) $storeId)
            );

            $label = ($storeLabel !== false && $storeLabel !== null && $storeLabel !== '')
                ? $storeLabel
                : ($row['frontend_label'] ?? '');

            if ($label !== '' && $this->slugGenerator->slugify($label) === $slug) {
                return $attrCode;
            }
        }

        return null;
    }

    private function getUrlSuffix(int|string $storeId): string
    {
        return (string) $this->scopeConfig->getValue(
            'catalog/seo/product_url_suffix',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
}
