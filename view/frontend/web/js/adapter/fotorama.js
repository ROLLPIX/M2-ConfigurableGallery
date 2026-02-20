/**
 * Rollpix ConfigurableGallery — Fotorama Adapter (PRD §7.4)
 *
 * Connects gallery-switcher with Magento's native Fotorama gallery.
 * When a color swatch is selected, receives filtered images from the switcher
 * and updates Fotorama using its native API.
 *
 * Uses jQuery (required by Fotorama) but only for Fotorama interaction.
 */
define([
    'jquery'
], function ($) {
    'use strict';

    /**
     * Detect if a gallery image entry is a local MP4 video.
     */
    function _isLocalVideo(img) {
        var file = img.file || img.img || img.full || '';

        return file.length > 4 && file.toLowerCase().slice(-4) === '.mp4';
    }

    /**
     * @param {Object} gallerySwitcher - GallerySwitcher instance
     * @param {jQuery} $galleryElement - The gallery container element
     */
    function FotoramaAdapter(gallerySwitcher, $galleryElement) {
        this.switcher = gallerySwitcher;
        this.$gallery = $galleryElement || $('[data-gallery-role="gallery-placeholder"]');
        this.fotoramaInstance = null;
        this._updateRetries = 0;
        this._bindEvents();
    }

    FotoramaAdapter.prototype = {
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
         * Update Fotorama gallery with filtered images.
         *
         * @param {Array} images - Filtered gallery images
         * @param {boolean} isInitial - Whether this is the initial page load
         */
        _updateGallery: function (images, isInitial) {
            if (!images || images.length === 0) {
                return;
            }

            var fotorama = this._getFotoramaInstance();
            if (!fotorama) {
                // Fotorama not ready yet, retry with max attempts
                if (this._updateRetries < 15) {
                    this._updateRetries++;
                    var self = this;
                    setTimeout(function () {
                        self._updateGallery(images, isInitial);
                    }, 200);
                }
                return;
            }
            this._updateRetries = 0;

            var fotoramaData = this._convertToFotoramaFormat(images);

            try {
                fotorama.load(fotoramaData);
                fotorama.show(0);
            } catch (e) {
                // Fotorama API may not be ready
            }
        },

        /**
         * Get the Fotorama instance from the gallery element.
         */
        _getFotoramaInstance: function () {
            if (this.fotoramaInstance) {
                return this.fotoramaInstance;
            }

            var $gallery = this.$gallery;
            if (!$gallery || !$gallery.length) {
                $gallery = $('[data-gallery-role="gallery-placeholder"]');
                this.$gallery = $gallery;
            }

            if (!$gallery.length) {
                return null;
            }

            var fotorama = $gallery.data('fotorama');
            if (fotorama) {
                this.fotoramaInstance = fotorama;
                return fotorama;
            }

            // Try finding via nested .fotorama element
            var $fotorama = $gallery.find('.fotorama');
            if ($fotorama.length) {
                fotorama = $fotorama.data('fotorama');
                if (fotorama) {
                    this.fotoramaInstance = fotorama;
                    return fotorama;
                }
            }

            return null;
        },

        /**
         * Convert our image format to Fotorama-compatible format.
         *
         * @param {Array} images - Gallery images in Magento format
         * @returns {Array} Fotorama-compatible data
         */
        _convertToFotoramaFormat: function (images) {
            var fotoramaData = [];

            for (var i = 0; i < images.length; i++) {
                var img = images[i];
                var item = {};

                if (img.type === 'video' || img.media_type === 'external-video' || _isLocalVideo(img)) {
                    // Video entry (external or local MP4)
                    item.videoUrl = img.videoUrl || img.video_url || img.full || img.img;
                    item.img = img.img || img.full || img.thumb;
                    item.thumb = img.thumb || img.img;
                    item.caption = img.caption || '';
                    item.type = 'video';
                } else {
                    // Image entry
                    item.img = img.full || img.img;
                    item.thumb = img.thumb || img.img;
                    item.full = img.full || img.img;
                    item.caption = img.caption || '';
                    item.type = 'image';
                    item.isMain = img.isMain || false;
                }

                fotoramaData.push(item);
            }

            return fotoramaData;
        }
    };

    return FotoramaAdapter;
});
