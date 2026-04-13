/**
 * Rollpix ConfigurableGallery — Configurable (dropdown) Mixin
 *
 * Mixin over Magento_ConfigurableProduct/js/configurable for the case where
 * the color attribute is rendered as a native <select> dropdown instead of
 * swatches. Native Magento only loads Magento_Swatches/js/swatch-renderer
 * when the attribute is `swatch_visual` / `swatch_text`; for plain dropdowns
 * the swatch-renderer-mixin never runs, so this mixin covers that gap.
 *
 * OPT-IN: gated by rollpix_configurable_gallery/general/dropdown_support
 * (admin config, default OFF). When OFF, _create() returns immediately after
 * _super() and no listeners, no switcher, no DOM mutations happen — zero
 * functional impact.
 *
 * GUARD vs swatch mixin: when the color attribute is rendered as a swatch
 * (mixed product: color swatch + size dropdown), the swatch-renderer-mixin
 * already handles gallery filtering. This mixin detects the swatch DOM and
 * stays inactive to avoid double-firing.
 *
 * INTEGRATION: reuses Rollpix_ConfigurableGallery/js/gallery-switcher and
 * the existing fotorama / rollpix-gallery adapters. switcher.switchColor()
 * dispatches `rollpix:gallery:filter` and the active adapter consumes it.
 */
define([
    'jquery',
    'Rollpix_ConfigurableGallery/js/gallery-switcher',
    'Rollpix_ConfigurableGallery/js/adapter/fotorama',
    'Rollpix_ConfigurableGallery/js/adapter/rollpix-gallery'
], function ($, GallerySwitcher, FotoramaAdapter, RollpixGalleryAdapter) {
    'use strict';

    return function (Configurable) {
        $.widget('mage.configurable', Configurable, {
            _rollpixSwitcher: null,
            _rollpixAdapter: null,
            _rollpixActive: false,
            _rollpixInitRetries: 0,

            /**
             * Override: run native init, then bootstrap Rollpix dropdown handler
             * when dropdown support is enabled and no swatch renderer covers
             * the color attribute.
             */
            _create: function () {
                this._super();

                var cfg = this._getRollpixConfig();
                if (!cfg || !cfg.enabled || !cfg.dropdownSupport) {
                    return;
                }

                if (!cfg.colorAttributeId) {
                    return;
                }

                // Guard: if the color attribute is rendered as a swatch on this
                // product, the swatch-renderer-mixin owns it. Stay out.
                if (this._isColorRenderedAsSwatch(cfg.colorAttributeId)) {
                    return;
                }

                // Confirm the color attribute actually has a dropdown on this widget
                // (it might be excluded — e.g. configurable with only `medida`).
                if (!this._findColorSelect(cfg.colorAttributeId).length) {
                    return;
                }

                this._initRollpixDropdown();
            },

            /**
             * Initialize the gallery switcher + adapter. Defers via gallery:loaded
             * + retry timeout when window.rollpixGalleryImages isn't ready yet
             * (RequireJS module load order is async).
             */
            _initRollpixDropdown: function () {
                if (this._rollpixActive) {
                    return;
                }

                var cfg = this._getRollpixConfig();
                var images = this._getRollpixGalleryImages();

                if (!images || images.length === 0) {
                    if (this._rollpixInitRetries < 10) {
                        this._rollpixInitRetries++;
                        var self = this;

                        if (this._rollpixInitRetries === 1) {
                            var $gallery = $('[data-gallery-role="gallery-placeholder"]');
                            if ($gallery.length) {
                                $gallery.on('gallery:loaded.rollpix-cfg', function () {
                                    self._initRollpixDropdown();
                                });
                            }
                        }

                        setTimeout(function () {
                            self._initRollpixDropdown();
                        }, 300);
                    }
                    return;
                }

                this._rollpixSwitcher = new GallerySwitcher(cfg, images);

                var adapterType = cfg.galleryAdapter || 'fotorama';
                if (adapterType === 'rollpix') {
                    this._rollpixAdapter = new RollpixGalleryAdapter(this._rollpixSwitcher);
                } else {
                    this._rollpixAdapter = new FotoramaAdapter(this._rollpixSwitcher);
                }

                this._rollpixSwitcher.init();
                this._rollpixActive = true;

                this._applyStockFilterToDropdown();
                this._applyInitialColorFilter();
            },

            /**
             * Determine which color to show on page load and apply it.
             * Priority: SEO preselected → URL/input value → defaultColorOptionId
             * (only when preselectColor is enabled). Falls back to switching to
             * `null` (show all in-stock images) when stock filter is on.
             */
            _applyInitialColorFilter: function () {
                var cfg = this._getRollpixConfig();
                var $select = this._findColorSelect(cfg.colorAttributeId);
                if (!$select.length) {
                    return;
                }

                var optionToSelect = null;

                if (cfg.seoPreselectedColor) {
                    optionToSelect = parseInt(cfg.seoPreselectedColor, 10);
                } else if ($select.val()) {
                    optionToSelect = parseInt($select.val(), 10);
                } else if (cfg.preselectColor && cfg.defaultColorOptionId) {
                    optionToSelect = parseInt(cfg.defaultColorOptionId, 10);
                }

                if (optionToSelect) {
                    // Sync the dropdown UI without re-triggering native handlers
                    // (we don't want native _changeProductImage to fight us).
                    if (parseInt($select.val(), 10) !== optionToSelect) {
                        $select.val(optionToSelect);
                    }
                    this._rollpixSwitcher.switchColor(optionToSelect, true);
                    return;
                }

                if (cfg.stockFilterEnabled) {
                    this._rollpixSwitcher.switchColor(null, true);
                }
            },

            /**
             * Hide <option> elements for out-of-stock colors (mirrors swatch
             * mixin behavior — `hide` removes them, `dim` adds a marker class).
             * For dropdowns there is no native "dim" UX, so `dim` falls back
             * to leaving the option but appending a marker label.
             */
            _applyStockFilterToDropdown: function () {
                var cfg = this._getRollpixConfig();
                if (!cfg || !cfg.stockFilterEnabled || !cfg.colorsWithStock) {
                    return;
                }

                var $select = this._findColorSelect(cfg.colorAttributeId);
                if (!$select.length) {
                    return;
                }

                var behavior = cfg.outOfStockBehavior || 'hide';
                var colorsWithStock = cfg.colorsWithStock;

                $select.find('option').each(function () {
                    var $opt = $(this);
                    var val = parseInt($opt.attr('value'), 10);
                    if (isNaN(val) || val === 0) {
                        return;
                    }

                    var hasStock = false;
                    for (var i = 0; i < colorsWithStock.length; i++) {
                        if (colorsWithStock[i] === val) {
                            hasStock = true;
                            break;
                        }
                    }

                    if (!hasStock) {
                        if (behavior === 'hide') {
                            $opt.remove();
                        } else {
                            $opt.prop('disabled', true);
                            if ($opt.text().indexOf('—') === -1) {
                                $opt.text($opt.text() + ' — sin stock');
                            }
                        }
                    }
                });
            },

            /**
             * Override: native _configureElement runs on every super_attribute
             * change. After the native logic, if the changed element is the
             * color attribute, dispatch to the gallery switcher.
             */
            _configureElement: function (element) {
                this._super(element);

                if (!this._rollpixActive || !this._rollpixSwitcher) {
                    return;
                }

                var cfg = this._getRollpixConfig();
                if (!cfg || !cfg.colorAttributeId) {
                    return;
                }

                var attrId = parseInt(
                    element.attributeId || (element.id || '').replace(/[a-z]*/, ''),
                    10
                );
                if (attrId !== cfg.colorAttributeId) {
                    return;
                }

                var optionId = parseInt(element.value, 10);
                if (optionId) {
                    this._rollpixSwitcher.switchColor(optionId);
                } else {
                    this._rollpixSwitcher.switchColor(null);
                }
            },

            /**
             * Override: block native gallery overwrite. Native _changeProductImage
             * pulls images from the simple product config and replaces the
             * Fotorama dataset, which would clobber our filtered view. When
             * Rollpix is active, no-op and let the switcher control the gallery.
             */
            _changeProductImage: function () {
                if (this._rollpixActive) {
                    return;
                }
                this._super();
            },

            /**
             * Locate the dropdown <select> for the color attribute within this
             * widget's scope. Magento renders configurable selects with id
             * `attribute<ID>` (e.g. `attribute93`).
             */
            _findColorSelect: function (colorAttrId) {
                if (!colorAttrId) {
                    return $();
                }
                return this.element.find('select#attribute' + colorAttrId);
            },

            /**
             * Detect whether the color attribute is rendered as a swatch on the
             * page. Used to bail out and leave control to the swatch-renderer
             * mixin (mixed swatch + dropdown products).
             */
            _isColorRenderedAsSwatch: function (colorAttrId) {
                if (!colorAttrId) {
                    return false;
                }
                var sel =
                    '.swatch-attribute[data-attribute-id="' + colorAttrId + '"],' +
                    '.swatch-attribute[attribute-id="' + colorAttrId + '"]';
                return $(sel).length > 0;
            },

            /**
             * Get rollpixGalleryConfig from the page JSON.
             */
            _getRollpixConfig: function () {
                if (window.rollpixGalleryConfig) {
                    return window.rollpixGalleryConfig;
                }
                return null;
            },

            /**
             * Get gallery images array from the page.
             */
            _getRollpixGalleryImages: function () {
                if (window.rollpixGalleryImages && window.rollpixGalleryImages.length) {
                    return window.rollpixGalleryImages;
                }

                var $gallery = $('[data-gallery-role="gallery-placeholder"]');
                if (!$gallery.length) {
                    return [];
                }

                var galleryData = $gallery.data('mageGallery');
                if (galleryData && galleryData.options && galleryData.options.data) {
                    return galleryData.options.data;
                }

                var galleryApi = $gallery.data('gallery');
                if (galleryApi && typeof galleryApi.returnCurrentImages === 'function') {
                    try {
                        var images = galleryApi.returnCurrentImages();
                        if (images && images.length) {
                            return images;
                        }
                    } catch (e) {
                        // Gallery API not ready
                    }
                }

                return [];
            }
        });

        return $.mage.configurable;
    };
});
