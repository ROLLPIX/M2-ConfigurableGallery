/**
 * Rollpix ConfigurableGallery — Rollpix ProductGallery Adapter (PRD §7.5)
 *
 * Direct integration with the Rollpix_ProductGallery JS API.
 * Rollpix ProductGallery removes Fotorama entirely and uses its own gallery structure.
 * This adapter listens to the rollpix:gallery:filter event and calls the gallery's
 * internal methods to update displayed images.
 *
 * Note: Rollpix ProductGallery eliminates product.info.media container (remove="true")
 * and uses its own DOM structure. The adapter works with the Rollpix gallery API,
 * not with generic DOM selectors.
 */
define([
    'jquery'
], function ($) {
    'use strict';

    /**
     * @param {Object} gallerySwitcher - GallerySwitcher instance
     */
    function RollpixGalleryAdapter(gallerySwitcher) {
        this.switcher = gallerySwitcher;
        this.galleryInstance = null;
        this._bindEvents();
    }

    RollpixGalleryAdapter.prototype = {
        /**
         * Bind to gallery filter events from the switcher.
         */
        _bindEvents: function () {
            var self = this;

            document.addEventListener('rollpix:gallery:filter', function (event) {
                var detail = event.detail || {};
                self._updateGallery(detail.images, detail.isInitial);
            });
        },

        /**
         * Update the Rollpix ProductGallery with filtered images.
         *
         * @param {Array} images - Filtered gallery images
         * @param {boolean} isInitial - Whether this is the initial page load
         */
        _updateGallery: function (images, isInitial) {
            if (!images || images.length === 0) {
                return;
            }

            var gallery = this._getGalleryInstance();
            if (!gallery) {
                // Gallery not ready yet, try again shortly
                var self = this;
                setTimeout(function () {
                    self._updateGallery(images, isInitial);
                }, 200);
                return;
            }

            var galleryData = this._convertToRollpixFormat(images);

            try {
                // Rollpix ProductGallery API methods
                if (typeof gallery.updateImages === 'function') {
                    gallery.updateImages(galleryData);
                } else if (typeof gallery.setImages === 'function') {
                    gallery.setImages(galleryData);
                } else if (typeof gallery.load === 'function') {
                    gallery.load(galleryData);
                }

                // Reset to first image
                if (typeof gallery.showSlide === 'function') {
                    gallery.showSlide(0);
                } else if (typeof gallery.goTo === 'function') {
                    gallery.goTo(0);
                }
            } catch (e) {
                // Gallery API may not be fully initialized
            }
        },

        /**
         * Get the Rollpix ProductGallery instance.
         */
        _getGalleryInstance: function () {
            if (this.galleryInstance) {
                return this.galleryInstance;
            }

            // Try common Rollpix gallery selectors
            var $gallery = $('[data-role="rollpix-gallery"], .rollpix-product-gallery, .rpx-gallery');

            if ($gallery.length) {
                // Try to get the widget/component instance
                var instance = $gallery.data('rollpixProductGallery')
                    || $gallery.data('rollpixGallery')
                    || $gallery.data('mageGallery');

                if (instance) {
                    this.galleryInstance = instance;
                    return instance;
                }
            }

            // Try window global (some implementations expose this)
            if (window.rollpixProductGallery) {
                this.galleryInstance = window.rollpixProductGallery;
                return this.galleryInstance;
            }

            return null;
        },

        /**
         * Convert images to Rollpix ProductGallery format.
         *
         * @param {Array} images - Gallery images in Magento format
         * @returns {Array} Rollpix-compatible data
         */
        _convertToRollpixFormat: function (images) {
            var galleryData = [];

            for (var i = 0; i < images.length; i++) {
                var img = images[i];

                galleryData.push({
                    src: img.full || img.img,
                    thumb: img.thumb || img.img,
                    full: img.full || img.img,
                    caption: img.caption || '',
                    type: (img.type === 'video' || img.media_type === 'external-video') ? 'video' : 'image',
                    videoUrl: img.videoUrl || img.video_url || null,
                    position: parseInt(img.position, 10) || i,
                    isMain: img.isMain || (i === 0)
                });
            }

            return galleryData;
        }
    };

    return RollpixGalleryAdapter;
});
