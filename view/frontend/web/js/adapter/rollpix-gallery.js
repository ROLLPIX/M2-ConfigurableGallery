/**
 * Rollpix ConfigurableGallery — Rollpix ProductGallery Adapter (PRD §7.5)
 *
 * DOM-based integration with Rollpix_ProductGallery.
 * Unlike Fotorama (which has a load(data) API), Rollpix ProductGallery
 * renders images server-side and its JS modules (slider, thumbnails, zoom)
 * use closure-private state. This adapter therefore works by showing/hiding
 * DOM elements using a CSS class.
 *
 * Matching: the GallerySwitcher filters from the same galleryImages array,
 * so filtered results are the exact same JS objects (reference equality).
 * We find each filtered image's index in the full array to map to DOM items.
 * Fallback: value_id matching. Safety: never hides ALL items.
 *
 * Layout support:
 *   - vertical/grid/fashion: show/hide items + thumbnails via CSS class
 *   - slider: additionally manages dots, arrows, and active slide state
 */
define([
    'jquery'
], function ($) {
    'use strict';

    var HIDDEN_CLASS = 'rp-cg-hidden';

    /**
     * @param {Object} gallerySwitcher - GallerySwitcher instance
     */
    function RollpixGalleryAdapter(gallerySwitcher) {
        this.switcher = gallerySwitcher;
        this.$gallery = null;
        this._isSlider = false;
        this._readyRetries = 0;
        this._injectStyles();
        this._bindEvents();
    }

    RollpixGalleryAdapter.prototype = {
        /**
         * Inject minimal CSS for the hidden class.
         * Uses !important to override inline styles set by the slider JS.
         */
        _injectStyles: function () {
            if (document.getElementById('rp-cg-filter-styles')) {
                return;
            }
            var style = document.createElement('style');
            style.id = 'rp-cg-filter-styles';
            style.textContent = '.' + HIDDEN_CLASS + ' { display: none !important; }';
            document.head.appendChild(style);
        },

        /**
         * Listen for gallery filter events dispatched by GallerySwitcher.
         */
        _bindEvents: function () {
            var self = this;
            document.addEventListener('rollpix:gallery:filter', function (event) {
                var detail = event.detail || {};
                self._updateGallery(detail.images, detail.isInitial);
            });
        },

        /**
         * Update the Rollpix Product Gallery to show only filtered images.
         *
         * Matching strategy: the GallerySwitcher filters from the same
         * window.rollpixGalleryImages array, so filtered images are the exact
         * same JS objects (reference equality). We find each filtered image's
         * index in the full array, then show/hide DOM items by that index.
         *
         * Fallback: if reference matching finds nothing, tries value_id matching.
         * Safety: if neither strategy finds any visible items, aborts to prevent
         * hiding the entire gallery.
         *
         * @param {Array} images - Filtered gallery images
         * @param {boolean} isInitial - Whether this is the initial page load
         */
        _updateGallery: function (images, isInitial) {
            if (!images) {
                return;
            }

            var $gallery = this._getGallery();
            if (!$gallery) {
                if (this._readyRetries < 15) {
                    this._readyRetries++;
                    var self = this;
                    setTimeout(function () {
                        self._updateGallery(images, isInitial);
                    }, 200);
                }
                return;
            }
            this._readyRetries = 0;

            var allImages = window.rollpixGalleryImages || [];
            if (!allImages.length) {
                return;
            }

            // Strategy 1: Reference-based matching (most robust).
            // The filtered images are the same JS objects from the galleryImages
            // array, so === comparison finds their index in the full array.
            var visibleSet = {};
            for (var i = 0; i < images.length; i++) {
                for (var j = 0; j < allImages.length; j++) {
                    if (images[i] === allImages[j]) {
                        visibleSet[j] = true;
                        break;
                    }
                }
            }

            // Strategy 2: If reference matching found nothing, try value_id
            var matchCount = 0;
            for (var k in visibleSet) {
                if (visibleSet.hasOwnProperty(k)) {
                    matchCount++;
                }
            }
            if (matchCount === 0 && images.length > 0) {
                var visibleValueIds = {};
                for (var vi = 0; vi < images.length; vi++) {
                    var vid = images[vi].value_id || images[vi].valueId;
                    if (vid !== undefined && vid !== null) {
                        visibleValueIds[String(vid)] = true;
                    }
                }
                for (var ai = 0; ai < allImages.length; ai++) {
                    var itemVid = allImages[ai].value_id || allImages[ai].valueId;
                    if (itemVid !== undefined && itemVid !== null
                        && visibleValueIds[String(itemVid)]) {
                        visibleSet[ai] = true;
                    }
                }
            }

            var $items = $gallery.find('.rp-gallery-item');
            var $thumbs = $gallery.find('.rp-thumbnail-item');
            var visibleIndexes = [];

            $items.each(function (idx) {
                var isVisible = !!visibleSet[idx];

                $(this).toggleClass(HIDDEN_CLASS, !isVisible);
                if (isVisible) {
                    visibleIndexes.push(idx);
                }

                // Update corresponding thumbnail
                if (idx < $thumbs.length) {
                    $thumbs.eq(idx).toggleClass(HIDDEN_CLASS, !isVisible);
                }
            });

            // Safety: never hide ALL items — abort and restore if that would happen
            if (visibleIndexes.length === 0 && $items.length > 0) {
                $items.removeClass(HIDDEN_CLASS);
                $thumbs.removeClass(HIDDEN_CLASS);
                return;
            }

            // Layout-specific state management
            if (this._isSlider) {
                this._updateSliderState($gallery, $items, $thumbs, visibleIndexes);
            } else {
                this._updateNonSliderState($gallery, $thumbs, visibleIndexes);
            }
        },

        /**
         * Slider layout: manage dots, arrows, and ensure first visible item is shown.
         *
         * The slider's closure-private goToSlide() cannot be called externally,
         * so we manipulate inline styles directly. Arrow buttons are hidden during
         * filtering to prevent navigation to hidden items (users navigate via
         * visible thumbnails and dots instead, which use the original DOM index
         * and navigate correctly).
         */
        _updateSliderState: function ($gallery, $items, $thumbs, visibleIndexes) {
            var $dots = $gallery.find('.rp-slider-dot');
            var allVisible = (visibleIndexes.length === $items.length);

            // Toggle dot visibility
            $dots.each(function (idx) {
                $(this).toggleClass(HIDDEN_CLASS, visibleIndexes.indexOf(idx) < 0);
            });

            if (allVisible) {
                // No filtering — restore arrows and let slider manage state
                $gallery.find('.rp-slider-prev, .rp-slider-next').removeClass(HIDDEN_CLASS);
                // Trigger first thumbnail click to reset slider internal state
                if ($thumbs.length > 0) {
                    $thumbs.eq(0).trigger('click');
                }
                return;
            }

            if (visibleIndexes.length > 0) {
                var firstIdx = visibleIndexes[0];

                // Reset all non-hidden items to hidden, then show first visible
                $items.each(function () {
                    if (!$(this).hasClass(HIDDEN_CLASS)) {
                        $(this).css({display: 'none', opacity: ''});
                    }
                });
                $items.eq(firstIdx).css({display: 'block', opacity: '1'});

                // Update active dot
                $dots.removeClass('rp-dot-active');
                if (firstIdx < $dots.length) {
                    $dots.eq(firstIdx).addClass('rp-dot-active');
                }

                // Update active thumbnail
                $thumbs.removeClass('rp-thumbnail-active');
                if (firstIdx < $thumbs.length) {
                    $thumbs.eq(firstIdx).addClass('rp-thumbnail-active');
                }
            }

            // Hide arrows when filtering is active — arrow navigation uses
            // currentIndex±1 which could land on a hidden item
            $gallery.find('.rp-slider-prev, .rp-slider-next').addClass(HIDDEN_CLASS);
        },

        /**
         * Non-slider layouts (vertical, grid, fashion): update thumbnail active state
         * and reposition the sliding highlight indicator.
         */
        _updateNonSliderState: function ($gallery, $thumbs, visibleIndexes) {
            if (visibleIndexes.length > 0) {
                $thumbs.removeClass('rp-thumbnail-active');
                $thumbs.eq(visibleIndexes[0]).addClass('rp-thumbnail-active');
            }

            // Reposition the sliding highlight indicator if present
            var $highlight = $gallery.find('.rp-thumbnail-highlight');
            if ($highlight.length && visibleIndexes.length > 0) {
                var $active = $thumbs.eq(visibleIndexes[0]);
                if ($active.length && $active.is(':visible')) {
                    $highlight.addClass('rp-highlight-no-transition');
                    $highlight.css({
                        width: $active.outerWidth() + 'px',
                        height: $active.outerHeight() + 'px',
                        transform: 'translate(' + $active.position().left + 'px, ' + $active.position().top + 'px)'
                    });
                    // Force reflow then re-enable transitions
                    $highlight[0].offsetHeight;
                    $highlight.removeClass('rp-highlight-no-transition');
                }
            }
        },

        /**
         * Find the Rollpix Product Gallery element.
         * Waits until gallery items exist in the DOM (data-mage-init has run).
         */
        _getGallery: function () {
            if (this.$gallery) {
                return this.$gallery;
            }

            var $gallery = $('[data-role="rp-gallery"]');
            if ($gallery.length && $gallery.find('.rp-gallery-item').length > 0) {
                this.$gallery = $gallery;
                this._isSlider = $gallery.hasClass('rp-layout-slider');
                return $gallery;
            }

            return null;
        }
    };

    return RollpixGalleryAdapter;
});
