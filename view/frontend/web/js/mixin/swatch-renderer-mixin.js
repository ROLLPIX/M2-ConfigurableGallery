/**
 * Rollpix ConfigurableGallery — Swatch Renderer Mixin (PRD §7.4)
 *
 * Mixin over Magento_Swatches/js/swatch-renderer.
 * Intercepts swatch selection to trigger gallery-switcher filtering
 * instead of the native behavior of loading simple product images.
 *
 * When a color swatch is selected:
 * 1. Asks gallery-switcher for filtered images
 * 2. The switcher dispatches rollpix:gallery:filter event
 * 3. The Fotorama adapter picks up the event and updates the gallery
 */
define([
    'jquery',
    'Rollpix_ConfigurableGallery/js/gallery-switcher',
    'Rollpix_ConfigurableGallery/js/adapter/fotorama'
], function ($, GallerySwitcher, FotoramaAdapter) {
    'use strict';

    return function (SwatchRenderer) {
        $.widget('mage.SwatchRenderer', SwatchRenderer, {
            _rollpixSwitcher: null,
            _rollpixAdapter: null,
            _rollpixInitialized: false,
            _rollpixInitRetries: 0,

            /**
             * Initialize Rollpix gallery switcher after widget creation.
             */
            _create: function () {
                this._super();
                this._initRollpixGallery();
            },

            /**
             * Set up the gallery switcher and adapter if rollpixGalleryConfig exists.
             * Supports lazy initialization: if gallery images aren't available yet,
             * waits for the gallery:loaded event or retries with a timeout.
             */
            _initRollpixGallery: function () {
                if (this._rollpixInitialized) {
                    return;
                }

                var rollpixConfig = this._getRollpixConfig();
                if (!rollpixConfig || !rollpixConfig.enabled) {
                    return;
                }

                var galleryImages = this._getRollpixGalleryImages();

                if (!galleryImages || galleryImages.length === 0) {
                    // Gallery images not available yet — defer initialization
                    if (this._rollpixInitRetries < 10) {
                        this._rollpixInitRetries++;
                        var self = this;

                        // Listen for Magento gallery:loaded event on first retry
                        if (this._rollpixInitRetries === 1) {
                            var $gallery = $('[data-gallery-role="gallery-placeholder"]');
                            if ($gallery.length) {
                                $gallery.on('gallery:loaded', function () {
                                    self._initRollpixGallery();
                                });
                            }
                        }

                        // Also retry with timeout as fallback
                        setTimeout(function () {
                            self._initRollpixGallery();
                        }, 300);
                    }
                    return;
                }

                this._rollpixSwitcher = new GallerySwitcher(rollpixConfig, galleryImages);
                this._rollpixAdapter = new FotoramaAdapter(this._rollpixSwitcher);
                this._rollpixSwitcher.init();
                this._rollpixInitialized = true;
            },

            /**
             * Override: intercept swatch click to filter gallery by color.
             */
            _OnClick: function ($this, widget) {
                this._super($this, widget);
                this._handleRollpixSwatchChange($this);
            },

            /**
             * Override: also handle change events (for accessible/keyboard navigation).
             */
            _OnChange: function ($this, widget) {
                this._super($this, widget);
                this._handleRollpixSwatchChange($this);
            },

            /**
             * Handle swatch selection change for Rollpix gallery.
             */
            _handleRollpixSwatchChange: function ($swatch) {
                // Try lazy init if not yet initialized
                if (!this._rollpixSwitcher) {
                    this._initRollpixGallery();
                }

                if (!this._rollpixSwitcher) {
                    return;
                }

                var rollpixConfig = this._getRollpixConfig();
                if (!rollpixConfig) {
                    return;
                }

                var attributeId = $swatch.closest('.swatch-attribute').attr('data-attribute-id')
                    || $swatch.closest('.swatch-attribute').attr('attribute-id');

                // Only process if this is the color attribute
                if (parseInt(attributeId, 10) !== rollpixConfig.colorAttributeId) {
                    return;
                }

                var optionId = $swatch.attr('data-option-id')
                    || $swatch.attr('option-id');

                if (optionId) {
                    this._rollpixSwitcher.switchColor(parseInt(optionId, 10));
                }
            },

            /**
             * Override: prevent native gallery update for color attribute when Rollpix is active.
             */
            _processUpdateGallery: function (images) {
                if (this._rollpixSwitcher && this._rollpixSwitcher.getCurrentColor() !== null) {
                    // Rollpix is handling gallery updates — skip native behavior
                    return;
                }
                this._super(images);
            },

            /**
             * Get rollpixGalleryConfig from the page JSON.
             */
            _getRollpixConfig: function () {
                // Try window global first (set by gallery-data.phtml)
                if (window.rollpixGalleryConfig) {
                    return window.rollpixGalleryConfig;
                }

                // Try from product JSON config
                var jsonConfig = this.options.jsonConfig;
                if (jsonConfig && jsonConfig.rollpixGalleryConfig) {
                    return jsonConfig.rollpixGalleryConfig;
                }

                return null;
            },

            /**
             * Get gallery images array from the page.
             * Multiple sources tried in priority order.
             */
            _getRollpixGalleryImages: function () {
                // Source 1: Window global set by gallery_data.phtml (most reliable)
                if (window.rollpixGalleryImages && window.rollpixGalleryImages.length) {
                    return window.rollpixGalleryImages;
                }

                var $gallery = $('[data-gallery-role="gallery-placeholder"]');
                if (!$gallery.length) {
                    return [];
                }

                // Source 2: From Magento's gallery widget data (after widget init)
                var galleryData = $gallery.data('mageGallery');
                if (galleryData && galleryData.options && galleryData.options.data) {
                    return galleryData.options.data;
                }

                // Source 3: From gallery API (after full Fotorama init)
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

        return $.mage.SwatchRenderer;
    };
});
