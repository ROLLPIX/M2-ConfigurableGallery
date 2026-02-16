/**
 * Rollpix ConfigurableGallery — Core Gallery Switcher (PRD §7.3)
 *
 * Vanilla JS module (NO jQuery). Acts as the "brain" for gallery filtering:
 * - Parses rollpixGalleryConfig from product JSON
 * - Filters images+videos by associatedAttributes for a selected color
 * - Keeps generic images (null association) always visible
 * - Handles color preselection (URL param → manual → first with stock)
 * - Manages deep link: reads/writes #color= or ?color= in URL
 * - Exposes API for gallery adapters to get filtered images
 *
 * Adapters (fotorama.js, rollpix-gallery.js, amasty.js) listen to events
 * dispatched by this module and update their respective galleries.
 */
define([], function () {
    'use strict';

    /**
     * @param {Object} config - rollpixGalleryConfig from backend
     * @param {Array} galleryImages - Full gallery images array from Magento JSON
     */
    function GallerySwitcher(config, galleryImages) {
        this.config = config || {};
        this.galleryImages = galleryImages || [];
        this.currentColorOptionId = null;
        this.colorMapping = config.colorMapping || {};
        this.colorAttributeId = config.colorAttributeId || 0;
        this.colorAttributeCode = config.colorAttributeCode || 'color';
        this.availableColors = config.availableColors || [];
        this.colorsWithStock = config.colorsWithStock || [];
        this.initialized = false;
    }

    GallerySwitcher.prototype = {
        /**
         * Initialize the switcher. Called once when the page loads.
         */
        init: function () {
            if (this.initialized || !this.config.enabled) {
                return;
            }
            this.initialized = true;

            var defaultColor = this._resolveDefaultColor();
            if (defaultColor !== null) {
                this.switchColor(defaultColor, true);
            }
        },

        /**
         * Switch gallery to show images for the given color option ID.
         *
         * @param {number|string} optionId - Color option_id
         * @param {boolean} [isInitial=false] - Whether this is the initial load
         * @returns {Object} Filtered images data
         */
        switchColor: function (optionId, isInitial) {
            optionId = optionId !== null && optionId !== undefined ? parseInt(optionId, 10) : null;

            if (isNaN(optionId)) {
                optionId = null;
            }

            this.currentColorOptionId = optionId;
            var filtered = this.getFilteredImages(optionId);

            // Update URL hash if configured (not on initial load to avoid history pollution)
            if (this.config.updateUrlOnSelect && !isInitial) {
                this._updateUrlHash(optionId);
            }

            // Dispatch event for adapters
            this._dispatchFilterEvent(filtered, optionId, isInitial);

            return filtered;
        },

        /**
         * Get images filtered for a specific color, including generics.
         *
         * @param {number|null} optionId - Color option_id, null for all
         * @returns {Object} { images: Array, allMedia: Array, colorLabel: string }
         */
        getFilteredImages: function (optionId) {
            if (optionId === null || optionId === undefined) {
                // No color selected — return images from all mapped colors.
                // colorMapping already excludes out-of-stock colors (backend filters them
                // when stock_filter_enabled=1 and behavior=hide), so this naturally
                // respects stock filtering without needing extra client-side checks.
                return this._getAllMappedImages();
            }

            var optionKey = String(optionId);
            var colorInfo = this.colorMapping[optionKey];

            return this._filterByValueIds(optionId, colorInfo);
        },

        /**
         * Get images from ALL colors present in colorMapping + generics.
         * Since colorMapping is already stock-filtered by the backend,
         * this excludes out-of-stock color images automatically.
         */
        _getAllMappedImages: function () {
            var showGeneric = this.config.showGenericImages !== false;
            var allowedValueIds = [];

            for (var key in this.colorMapping) {
                if (!this.colorMapping.hasOwnProperty(key)) {
                    continue;
                }
                if (key === 'null' && !showGeneric) {
                    continue;
                }
                var info = this.colorMapping[key];
                allowedValueIds = allowedValueIds.concat(info.images || [], info.videos || []);
            }

            var filteredImages = [];

            for (var i = 0; i < this.galleryImages.length; i++) {
                var img = this.galleryImages[i];
                var valueId = img.value_id || img.valueId;

                if (valueId !== undefined && valueId !== null) {
                    if (this._inArray(parseInt(valueId, 10), allowedValueIds)) {
                        filteredImages.push(img);
                    }
                } else {
                    // Fallback: check associatedAttributes
                    var assoc = img.associatedAttributes || img.associated_attributes;
                    if (assoc === null || assoc === undefined || assoc === '') {
                        if (showGeneric) {
                            filteredImages.push(img);
                        }
                    } else {
                        // Check if matches any color in the mapping
                        var matched = false;
                        for (var mapKey in this.colorMapping) {
                            if (mapKey !== 'null' && this.colorMapping.hasOwnProperty(mapKey)) {
                                if (this._matchesColor(assoc, parseInt(mapKey, 10))) {
                                    matched = true;
                                    break;
                                }
                            }
                        }
                        if (matched) {
                            filteredImages.push(img);
                        }
                    }
                }
            }

            filteredImages.sort(function (a, b) {
                return (parseInt(a.position, 10) || 0) - (parseInt(b.position, 10) || 0);
            });

            return {
                images: filteredImages,
                allMedia: filteredImages,
                colorLabel: null
            };
        },

        /**
         * Filter images for a specific color option ID.
         */
        _filterByValueIds: function (optionId, colorInfo) {
            var genericInfo = this.colorMapping['null'];
            var showGeneric = this.config.showGenericImages !== false;

            var filteredImages = [];
            var colorValueIds = colorInfo ? [].concat(colorInfo.images || [], colorInfo.videos || []) : [];
            var genericValueIds = (genericInfo && showGeneric)
                ? [].concat(genericInfo.images || [], genericInfo.videos || [])
                : [];

            var allowedValueIds = colorValueIds.concat(genericValueIds);

            for (var i = 0; i < this.galleryImages.length; i++) {
                var img = this.galleryImages[i];
                var valueId = img.value_id || img.valueId;

                if (valueId !== undefined && valueId !== null) {
                    if (this._inArray(parseInt(valueId, 10), allowedValueIds)) {
                        filteredImages.push(img);
                    }
                } else {
                    var assoc = img.associatedAttributes || img.associated_attributes;
                    if (assoc === null || assoc === undefined || assoc === '') {
                        if (showGeneric) {
                            filteredImages.push(img);
                        }
                    } else if (this._matchesColor(assoc, optionId)) {
                        filteredImages.push(img);
                    }
                }
            }

            filteredImages.sort(function (a, b) {
                return (parseInt(a.position, 10) || 0) - (parseInt(b.position, 10) || 0);
            });

            return {
                images: filteredImages,
                allMedia: filteredImages,
                colorLabel: colorInfo ? colorInfo.label : null,
                colorOptionId: optionId
            };
        },

        /**
         * Get the currently selected color option ID.
         * @returns {number|null}
         */
        getCurrentColor: function () {
            return this.currentColorOptionId;
        },

        /**
         * Check if a color option has stock.
         * @param {number} optionId
         * @returns {boolean}
         */
        hasStock: function (optionId) {
            return this._inArray(parseInt(optionId, 10), this.colorsWithStock);
        },

        /**
         * Get all color option IDs that have images mapped.
         * @returns {Array<number>}
         */
        getAvailableColors: function () {
            return this.availableColors.slice();
        },

        /**
         * Resolve color from URL parameters.
         * Supports: #color=318, #color=rojo, ?color=318, ?color=rojo
         *
         * @returns {number|null} option_id or null
         */
        getColorFromUrl: function () {
            if (!this.config.deepLinkEnabled) {
                return null;
            }

            var colorParam = null;

            // Try hash first: #color=value
            var hash = window.location.hash;
            if (hash) {
                var hashMatch = hash.match(/[#&]color=([^&]*)/);
                if (hashMatch) {
                    colorParam = decodeURIComponent(hashMatch[1]);
                }
            }

            // Try query param: ?color=value
            if (!colorParam) {
                var search = window.location.search;
                if (search) {
                    var searchMatch = search.match(/[?&]color=([^&]*)/);
                    if (searchMatch) {
                        colorParam = decodeURIComponent(searchMatch[1]);
                    }
                }
            }

            if (!colorParam) {
                return null;
            }

            // Try as numeric option_id first
            var numericId = parseInt(colorParam, 10);
            if (!isNaN(numericId) && this._inArray(numericId, this.availableColors)) {
                return numericId;
            }

            // Try as label (case-insensitive)
            var lowerParam = colorParam.toLowerCase();
            for (var key in this.colorMapping) {
                if (this.colorMapping.hasOwnProperty(key) && key !== 'null') {
                    var mapping = this.colorMapping[key];
                    if (mapping.label && mapping.label.toLowerCase() === lowerParam) {
                        return parseInt(key, 10);
                    }
                }
            }

            return null;
        },

        // --- Private methods ---

        /**
         * Resolve which color should be preselected (PRD §6.7).
         * Priority: URL param → manual default → first with stock → first in position
         */
        _resolveDefaultColor: function () {
            if (!this.config.preselectColor && !this.config.deepLinkEnabled) {
                return null;
            }

            // Priority 1: URL parameter
            var urlColor = this.getColorFromUrl();
            if (urlColor !== null) {
                // Verify stock if filter enabled
                if (this.config.stockFilterEnabled && !this.hasStock(urlColor)) {
                    // Fall through to other options
                } else {
                    return urlColor;
                }
            }

            if (!this.config.preselectColor) {
                return null;
            }

            // Priority 2: Manual default (from backend)
            if (this.config.defaultColorOptionId) {
                var manualDefault = parseInt(this.config.defaultColorOptionId, 10);
                if (!isNaN(manualDefault) && this._inArray(manualDefault, this.availableColors)) {
                    return manualDefault;
                }
            }

            // Priority 3: First color with stock
            if (this.config.stockFilterEnabled && this.colorsWithStock.length > 0) {
                for (var i = 0; i < this.availableColors.length; i++) {
                    if (this._inArray(this.availableColors[i], this.colorsWithStock)) {
                        return this.availableColors[i];
                    }
                }
            }

            // Priority 4: First color in position order
            if (this.availableColors.length > 0) {
                return this.availableColors[0];
            }

            return null;
        },

        /**
         * Check if associatedAttributes string matches a color option ID.
         */
        _matchesColor: function (associatedAttributes, optionId) {
            if (!associatedAttributes || !this.colorAttributeId) {
                return false;
            }
            var needle = 'attribute' + this.colorAttributeId + '-' + optionId;
            var parts = associatedAttributes.split(',');
            for (var i = 0; i < parts.length; i++) {
                if (parts[i].trim() === needle) {
                    return true;
                }
            }
            return false;
        },

        /**
         * Update URL hash with color parameter.
         */
        _updateUrlHash: function (optionId) {
            if (!optionId) {
                return;
            }
            try {
                var newHash = 'color=' + optionId;
                if (window.history && window.history.replaceState) {
                    var url = window.location.pathname + window.location.search + '#' + newHash;
                    window.history.replaceState(null, '', url);
                } else {
                    window.location.hash = newHash;
                }
            } catch (e) {
                // Silently fail if URL update is not possible
            }
        },

        /**
         * Dispatch custom event with filtered images data.
         */
        _dispatchFilterEvent: function (filteredData, optionId, isInitial) {
            var event;
            try {
                event = new CustomEvent('rollpix:gallery:filter', {
                    bubbles: true,
                    detail: {
                        images: filteredData.images,
                        allMedia: filteredData.allMedia,
                        colorOptionId: optionId,
                        colorLabel: filteredData.colorLabel,
                        isInitial: !!isInitial,
                        switcher: this
                    }
                });
            } catch (e) {
                // IE11 fallback
                event = document.createEvent('CustomEvent');
                event.initCustomEvent('rollpix:gallery:filter', true, true, {
                    images: filteredData.images,
                    allMedia: filteredData.allMedia,
                    colorOptionId: optionId,
                    colorLabel: filteredData.colorLabel,
                    isInitial: !!isInitial,
                    switcher: this
                });
            }
            document.dispatchEvent(event);
        },

        /**
         * Simple array includes check (no Array.prototype.includes for IE compat).
         */
        _inArray: function (value, arr) {
            for (var i = 0; i < arr.length; i++) {
                if (arr[i] === value) {
                    return true;
                }
            }
            return false;
        }
    };

    return GallerySwitcher;
});
