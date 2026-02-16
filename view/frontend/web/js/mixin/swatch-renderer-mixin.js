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

                // Ensure gallery is filtered once Fotorama is ready.
                // gallery:loaded may have already fired before this code runs
                // (RequireJS modules load asynchronously), so we also poll for
                // Fotorama readiness as a fallback.
                this._ensureGalleryFiltered();
            },

            /**
             * Ensure the preselected color filter is applied once Fotorama is ready.
             * Uses two strategies to handle all timing scenarios:
             * 1. gallery:loaded event — for future resets after our binding
             * 2. Polling — catches the case where gallery:loaded already fired
             */
            _ensureGalleryFiltered: function () {
                var self = this;
                var $gallery = $('[data-gallery-role="gallery-placeholder"]');
                if (!$gallery.length || !this._rollpixSwitcher) {
                    return;
                }

                var reapply = function () {
                    var currentColor = self._rollpixSwitcher.getCurrentColor();

                    // If no preselection from config, detect first visible swatch
                    if (currentColor === null) {
                        currentColor = self._getFirstVisibleColorOptionId();
                        if (currentColor !== null) {
                            self._rollpixSwitcher.switchColor(currentColor, true);
                            return true;
                        }
                        return false;
                    }

                    self._rollpixSwitcher.switchColor(currentColor, true);
                    return true;
                };

                // Strategy 1: gallery:loaded event (re-apply after every Magento reset)
                $gallery.on('gallery:loaded.rollpix', function () {
                    setTimeout(reapply, 100);
                });

                // Strategy 2: Poll until Fotorama is ready (handles the case where
                // gallery:loaded already fired before we could bind the handler)
                var attempts = 0;
                var maxAttempts = 25;
                var poll = function () {
                    attempts++;
                    var fotorama = $gallery.data('fotorama');
                    if (!fotorama) {
                        fotorama = $gallery.find('.fotorama').data('fotorama');
                    }
                    if (fotorama) {
                        reapply();
                        return;
                    }
                    if (attempts < maxAttempts) {
                        setTimeout(poll, 200);
                    }
                };
                setTimeout(poll, 200);
            },

            /**
             * Get the first visible (non-disabled) color swatch option ID from the DOM.
             * Leverages Magento's native stock validation which hides swatches for
             * out-of-stock options, so we don't need our own stock detection here.
             */
            _getFirstVisibleColorOptionId: function () {
                var rollpixConfig = this._getRollpixConfig();
                if (!rollpixConfig || !rollpixConfig.colorAttributeId) {
                    return null;
                }

                var colorAttrId = rollpixConfig.colorAttributeId;
                var $colorAttr = this.element.find(
                    '.swatch-attribute[data-attribute-id="' + colorAttrId + '"],' +
                    '.swatch-attribute[attribute-id="' + colorAttrId + '"]'
                );

                if (!$colorAttr.length) {
                    return null;
                }

                var $firstSwatch = $colorAttr
                    .find('.swatch-option:not(.disabled):not([disabled])')
                    .first();

                if ($firstSwatch.length) {
                    var optionId = $firstSwatch.attr('data-option-id')
                        || $firstSwatch.attr('option-id');
                    return optionId ? parseInt(optionId, 10) : null;
                }

                return null;
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

                if (!optionId) {
                    return;
                }

                // After _super() runs in _OnClick, the 'selected' class reflects the new state.
                // If the swatch no longer has 'selected', the user deselected it → show all images.
                var isSelected = $swatch.hasClass('selected');
                if (isSelected) {
                    this._rollpixSwitcher.switchColor(parseInt(optionId, 10));
                } else {
                    this._rollpixSwitcher.switchColor(null);
                }
            },

            /**
             * Check if Rollpix is currently handling gallery updates.
             */
            _isRollpixHandlingGallery: function () {
                return this._rollpixSwitcher && this._rollpixSwitcher.getCurrentColor() !== null;
            },

            /**
             * Override: prevent native gallery update when Rollpix is active.
             * Covers Magento versions that use _processUpdateGallery.
             */
            _processUpdateGallery: function (images) {
                if (this._isRollpixHandlingGallery()) {
                    return;
                }
                this._super(images);
            },

            /**
             * Override: prevent native gallery update when Rollpix is active.
             * In Magento 2.4.x, _loadMedia → updateBaseImage is the main gallery
             * update path. Without blocking this, native code overwrites our
             * filtered gallery immediately after we update it.
             */
            updateBaseImage: function (images, context, isInProductView) {
                if (this._isRollpixHandlingGallery()) {
                    return;
                }
                this._super(images, context, isInProductView);
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
