/**
 * Rollpix ConfigurableGallery — Amasty Swatch Renderer Mixin (PRD §7.6)
 *
 * Mixin over Amasty_Conf/js/swatch-renderer (NOT the native Magento one).
 * Amasty replaces the native swatch-renderer entirely via requirejs map.
 *
 * Intercepts _AmOnClick and _processUpdateGallery to use our color mapping
 * from the configurable parent instead of Amasty's native simple-product images.
 */
define([
    'jquery',
    'Rollpix_ConfigurableGallery/js/gallery-switcher',
    'Rollpix_ConfigurableGallery/js/adapter/amasty'
], function ($, GallerySwitcher, AmastyAdapter) {
    'use strict';

    return function (SwatchRenderer) {
        $.widget('mage.SwatchRenderer', SwatchRenderer, {
            _rollpixSwitcher: null,
            _rollpixAdapter: null,
            _rollpixInitialized: false,

            /**
             * Initialize Rollpix gallery switcher after Amasty widget creation.
             */
            _create: function () {
                this._super();
                this._initRollpixGallery();
            },

            /**
             * Set up the gallery switcher and Amasty adapter.
             */
            _initRollpixGallery: function () {
                if (this._rollpixInitialized) {
                    return;
                }

                var rollpixConfig = this._getRollpixConfig();
                if (!rollpixConfig || !rollpixConfig.enabled) {
                    return;
                }

                // Only initialize if adapter is 'amasty' or 'auto'
                if (rollpixConfig.galleryAdapter !== 'amasty' && rollpixConfig.galleryAdapter !== 'auto') {
                    return;
                }

                var galleryImages = this._getRollpixGalleryImages();

                this._rollpixSwitcher = new GallerySwitcher(rollpixConfig, galleryImages);
                this._rollpixAdapter = new AmastyAdapter(this._rollpixSwitcher);
                this._rollpixSwitcher.init();
                this._rollpixInitialized = true;
            },

            /**
             * Override: intercept Amasty's click handler.
             */
            _AmOnClick: function ($this, widget) {
                this._super($this, widget);
                this._handleRollpixSwatchChange($this);
            },

            /**
             * Override: intercept Amasty's gallery update to use our color mapping.
             */
            _processUpdateGallery: function (images) {
                if (this._rollpixSwitcher && this._rollpixSwitcher.getCurrentColor() !== null) {
                    // Rollpix is handling gallery updates — skip Amasty's native behavior
                    return;
                }
                this._super(images);
            },

            /**
             * Handle swatch selection change for Rollpix gallery.
             */
            _handleRollpixSwatchChange: function ($swatch) {
                if (!this._rollpixSwitcher) {
                    return;
                }

                var rollpixConfig = this._getRollpixConfig();
                if (!rollpixConfig) {
                    return;
                }

                var attributeId = $swatch.closest('.swatch-attribute').attr('data-attribute-id')
                    || $swatch.closest('.swatch-attribute').attr('attribute-id');

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
             * Get rollpixGalleryConfig from the page.
             */
            _getRollpixConfig: function () {
                if (window.rollpixGalleryConfig) {
                    return window.rollpixGalleryConfig;
                }
                var jsonConfig = this.options.jsonConfig;
                if (jsonConfig && jsonConfig.rollpixGalleryConfig) {
                    return jsonConfig.rollpixGalleryConfig;
                }
                return null;
            },

            /**
             * Get gallery images array.
             */
            _getRollpixGalleryImages: function () {
                if (window.rollpixGalleryImages) {
                    return window.rollpixGalleryImages;
                }
                return [];
            }
        });

        return $.mage.SwatchRenderer;
    };
});
