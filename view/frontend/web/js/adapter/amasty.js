/**
 * Rollpix ConfigurableGallery — Amasty Color Swatches Pro Adapter (PRD §7.6)
 *
 * Adapter for sites using Amasty_Conf (Color Swatches Pro).
 * Amasty replaces the native swatch-renderer completely and has its own
 * gallery update mechanism. This adapter intercepts Amasty's gallery updates
 * and replaces the image source with our color-mapped configurable images.
 *
 * Works alongside the amasty-swatch-renderer-mixin which handles the swatch
 * event interception on the Amasty side.
 */
define([
    'jquery'
], function ($) {
    'use strict';

    /**
     * @param {Object} gallerySwitcher - GallerySwitcher instance
     */
    function AmastyAdapter(gallerySwitcher) {
        this.switcher = gallerySwitcher;
        this._bindEvents();
    }

    AmastyAdapter.prototype = {
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
         * Update gallery with filtered images.
         * Tries multiple Amasty gallery update methods since Amasty's structure
         * varies between versions and configurations.
         *
         * @param {Array} images - Filtered gallery images
         * @param {boolean} isInitial - Whether this is the initial page load
         */
        _updateGallery: function (images, isInitial) {
            if (!images || images.length === 0) {
                return;
            }

            var updated = false;

            // Method 1: Amasty custom gallery (zoom + lightbox + slick)
            updated = this._updateAmastyGallery(images);

            // Method 2: Fallback to Fotorama (Amasty sometimes keeps Fotorama)
            if (!updated) {
                updated = this._updateFotorama(images);
            }

            // Method 3: Direct DOM manipulation as last resort
            if (!updated) {
                this._updateDirectDom(images);
            }
        },

        /**
         * Update Amasty's custom gallery widget.
         */
        _updateAmastyGallery: function (images) {
            var $gallery = $('[data-role="amasty-gallery"], .amconf-gallery, .am-gallery');

            if (!$gallery.length) {
                return false;
            }

            var galleryWidget = $gallery.data('amconfGallery')
                || $gallery.data('amastyGallery');

            if (!galleryWidget) {
                return false;
            }

            try {
                var formattedImages = this._formatForAmasty(images);

                if (typeof galleryWidget.updateData === 'function') {
                    galleryWidget.updateData(formattedImages);
                } else if (typeof galleryWidget.setImages === 'function') {
                    galleryWidget.setImages(formattedImages);
                }

                return true;
            } catch (e) {
                return false;
            }
        },

        /**
         * Fallback: update Fotorama if Amasty is using it.
         */
        _updateFotorama: function (images) {
            var $gallery = $('[data-gallery-role="gallery-placeholder"]');
            if (!$gallery.length) {
                return false;
            }

            var fotorama = $gallery.data('fotorama');
            if (!fotorama) {
                var $inner = $gallery.find('.fotorama');
                if ($inner.length) {
                    fotorama = $inner.data('fotorama');
                }
            }

            if (!fotorama) {
                return false;
            }

            try {
                var fotoramaData = this._formatForFotorama(images);
                fotorama.load(fotoramaData);
                fotorama.show(0);
                return true;
            } catch (e) {
                return false;
            }
        },

        /**
         * Last resort: update the main product image directly.
         */
        _updateDirectDom: function (images) {
            if (!images.length) {
                return;
            }

            var firstImage = images[0];
            var imgSrc = firstImage.full || firstImage.img;

            if (!imgSrc) {
                return;
            }

            // Update main gallery image
            var $mainImage = $('.gallery-placeholder img, .fotorama__img, .product-image-photo').first();
            if ($mainImage.length) {
                $mainImage.attr('src', imgSrc);
            }
        },

        /**
         * Format images for Amasty gallery widget.
         */
        _formatForAmasty: function (images) {
            var formatted = [];
            for (var i = 0; i < images.length; i++) {
                var img = images[i];
                formatted.push({
                    img: img.full || img.img,
                    thumb: img.thumb || img.img,
                    full: img.full || img.img,
                    caption: img.caption || '',
                    type: (img.type === 'video' || img.media_type === 'external-video') ? 'video' : 'image',
                    videoUrl: img.videoUrl || img.video_url || null,
                    isMain: img.isMain || (i === 0),
                    position: parseInt(img.position, 10) || i
                });
            }
            return formatted;
        },

        /**
         * Format images for Fotorama (when Amasty uses Fotorama internally).
         */
        _formatForFotorama: function (images) {
            var data = [];
            for (var i = 0; i < images.length; i++) {
                var img = images[i];
                var item = {
                    img: img.full || img.img,
                    thumb: img.thumb || img.img,
                    full: img.full || img.img,
                    caption: img.caption || ''
                };

                if (img.type === 'video' || img.media_type === 'external-video') {
                    item.videoUrl = img.videoUrl || img.video_url;
                    item.type = 'video';
                }

                data.push(item);
            }
            return data;
        }
    };

    return AmastyAdapter;
});
