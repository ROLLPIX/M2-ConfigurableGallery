<?php

declare(strict_types=1);

namespace Rollpix\ConfigurableGallery\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Centralized configuration reader for all module settings (PRD §9).
 * Reads values from system.xml paths under rollpix_configurable_gallery/.
 */
class Config
{
    private const XML_PATH_PREFIX = 'rollpix_configurable_gallery/';

    // §9.1 General
    private const XML_PATH_ENABLED = self::XML_PATH_PREFIX . 'general/enabled';
    private const XML_PATH_SELECTOR_ATTRIBUTES = self::XML_PATH_PREFIX . 'general/selector_attributes';
    private const XML_PATH_SHOW_GENERIC_IMAGES = self::XML_PATH_PREFIX . 'general/show_generic_images';
    private const XML_PATH_PRESELECT_VARIANT_PDP = self::XML_PATH_PREFIX . 'general/preselect_variant_pdp';
    private const XML_PATH_PRESELECT_VARIANT_PLP = self::XML_PATH_PREFIX . 'general/preselect_variant_plp';
    private const XML_PATH_DEEP_LINK_ENABLED = self::XML_PATH_PREFIX . 'general/deep_link_enabled';
    private const XML_PATH_UPDATE_URL_ON_SELECT = self::XML_PATH_PREFIX . 'general/update_url_on_select';
    private const XML_PATH_SEO_FRIENDLY_URL = self::XML_PATH_PREFIX . 'general/seo_friendly_url';

    // §9.2 Stock
    private const XML_PATH_STOCK_FILTER_ENABLED = self::XML_PATH_PREFIX . 'stock/stock_filter_enabled';
    private const XML_PATH_OUT_OF_STOCK_BEHAVIOR = self::XML_PATH_PREFIX . 'stock/out_of_stock_behavior';

    // §9.3 Propagation
    private const XML_PATH_PROPAGATION_MODE = self::XML_PATH_PREFIX . 'propagation/propagation_mode';
    private const XML_PATH_PROPAGATION_ROLES = self::XML_PATH_PREFIX . 'propagation/propagation_roles';
    private const XML_PATH_CLEAN_BEFORE_PROPAGATE = self::XML_PATH_PREFIX . 'propagation/clean_before_propagate';

    // §9.4 Cart/Checkout
    private const XML_PATH_CART_IMAGE_OVERRIDE = self::XML_PATH_PREFIX . 'cart/cart_image_override';

    // §9.5 Advanced
    private const XML_PATH_DEBUG_MODE = self::XML_PATH_PREFIX . 'advanced/debug_mode';
    private const XML_PATH_GALLERY_ADAPTER = self::XML_PATH_PREFIX . 'advanced/gallery_adapter';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isEnabled(int|string|null $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get the ordered list of selector attribute codes from config.
     *
     * @return string[] e.g. ['color', 'rollpix_erp_color', 'medida']
     */
    public function getSelectorAttributes(int|string|null $storeId = null): array
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_SELECTOR_ATTRIBUTES,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if ($value === null || $value === '') {
            return ['color'];
        }

        return array_filter(array_map('trim', explode(',', (string) $value)));
    }

    public function showGenericImages(int|string|null $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_SHOW_GENERIC_IMAGES,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function isPreselectVariantPdpEnabled(int|string|null $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_PRESELECT_VARIANT_PDP,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function isPreselectVariantPlpEnabled(int|string|null $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_PRESELECT_VARIANT_PLP,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function isDeepLinkEnabled(int|string|null $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_DEEP_LINK_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function isUpdateUrlOnSelectEnabled(int|string|null $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_UPDATE_URL_ON_SELECT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function isSeoFriendlyUrlEnabled(int|string|null $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_SEO_FRIENDLY_URL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function isStockFilterEnabled(int|string|null $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_STOCK_FILTER_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * @return string 'hide' or 'dim'
     */
    public function getOutOfStockBehavior(int|string|null $storeId = null): string
    {
        return (string) ($this->scopeConfig->getValue(
            self::XML_PATH_OUT_OF_STOCK_BEHAVIOR,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?? 'hide');
    }

    /**
     * @return string 'disabled', 'automatic', or 'manual'
     */
    public function getPropagationMode(int|string|null $storeId = null): string
    {
        return (string) ($this->scopeConfig->getValue(
            self::XML_PATH_PROPAGATION_MODE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?? 'disabled');
    }

    public function isPropagationDisabled(int|string|null $storeId = null): bool
    {
        return $this->getPropagationMode($storeId) === 'disabled';
    }

    /**
     * @return string[] e.g. ['image', 'small_image', 'thumbnail']
     */
    public function getPropagationRoles(int|string|null $storeId = null): array
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_PROPAGATION_ROLES,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if ($value === null || $value === '') {
            return ['image', 'small_image', 'thumbnail'];
        }

        return explode(',', (string) $value);
    }

    public function isCleanBeforePropagate(int|string|null $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_CLEAN_BEFORE_PROPAGATE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function isCartImageOverrideEnabled(int|string|null $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_CART_IMAGE_OVERRIDE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function isDebugMode(int|string|null $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_DEBUG_MODE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * @return string 'auto', 'fotorama', 'rollpix', or 'amasty'
     */
    public function getGalleryAdapter(int|string|null $storeId = null): string
    {
        return (string) ($this->scopeConfig->getValue(
            self::XML_PATH_GALLERY_ADAPTER,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?? 'auto');
    }
}
