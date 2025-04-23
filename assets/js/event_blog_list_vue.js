/*
 * This file is part of SAC Event Blog Bundle.
 *
 * (c) Marko Cupic <m.cupic@gmx.ch>
 * @license GPL-3.0-or-later
 * For the full copyright and license information,
 * please view the LICENSE file that was distributed with this source code.
 * @link https://github.com/markocupic/sac-event-blog-bundle
 */

"use strict";


class RequestStackProcessor {

    stack = [];
    requestUuids = [];
    isProcessing = false;
    interval = null;

    // Add a URL to the stack
    addUrl(url, eventName) {
        // This will remove
        // all previous requests from the stack
        this.clearStack();

        const uuid = this.generateUUID();

        this.stack.push({
            'url': url,
            'eventName': eventName,
            'uuid': uuid,
        });

        this.requestUuids.push(uuid);

        if (this.interval === null) {
            this.interval = setInterval(() => this.processStack(), 500);
        }
    }

    clearStack() {
        this.stack = [];
        this.requestUuids = [];
        this.isProcessing = false;
    }

    generateUUID() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            const r = Math.random() * 16 | 0; // Random number between 0 and 15
            const v = c === 'x' ? r : (r & 0x3 | 0x8); // Ensure the UUID version and variant
            return v.toString(16); // Convert to hexadecimal
        });
    }

    uuidExists(uuid) {
        return this.requestUuids.includes(uuid);
    }

    // Process the first url in the stack
    async processStack() {
        if (this.isProcessing) return; // Avoid multiple simultaneous processing

        if (!this.stack.length > 0) {
            return;
        }

        try {
            this.isProcessing = true;

            // Get the first URL from the stack
            const requestItem = this.stack.shift();
            const response = await this.processUrl(requestItem.url);

            const event = new CustomEvent(requestItem.eventName, {
                detail: {
                    'response': response,
                    'url': requestItem.url,
                    'uuid': requestItem.uuid,
                }
            });

            document.dispatchEvent(event);

        } catch (error) {
            console.error(`Error processing ${requestItem.url}:`, error);
        }

        this.isProcessing = false;
    }

    async processUrl(url) {
        try {
            return await fetch(url, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'x-requested-with': 'XMLHttpRequest'
                },
            });
        } catch (error) {
            throw new Error(`Failed to fetch ${url}`);
        }
    }
}

class EventBlogList {
    constructor(elId, opt) {

        // Defaults
        const defaults = {
            'params': {
                'listModuleId': null, 'apiKey': null, 'readerModuleId': null, 'itemIds': [], 'perPage': 4, 'language': 'en',
            },
        };

        // merge options and defaults
        let options = {...defaults, ...opt}

        const {createApp} = Vue

        // Instantiate vue.js application
        const app = createApp({
            data() {
                return {
                    options: options,
                    listContent: '',
                    readerContent: '',
                    itemIds: [],
                    currentPage: null,
                    currentItemIndex: null,
                    currentItemId: null,
                    readerRequestStackProcessor: new RequestStackProcessor(),
                };
            },

            async mounted() {
                let self = this;

                self.itemIds = self.options.params.itemIds;

                let page = await self.getUrlParam('page_e' + self.options.params.listModuleId, null);
                self.currentPage = page === null ? 1 : parseInt(page);

                document.onkeydown = ((e) => {
                    self._handleKeyPress(e);
                });

                // Handle modal
                window.setTimeout(() => {
                    let modal = document.querySelector(elId + ' .modal');
                    if (modal) {
                        modal.addEventListener('hidden.bs.modal', (event) => {
                            self.currentItemId = null;
                            self.readerContent = '';
                            self.readerRequestStackProcessor.clearStack();
                        });
                    }
                }, 1000);

                // Open event blog with id 120
                // https://www.sac-pilatus.ch/home.html?show_event_blog=120
                let eventBlogId = null;

                if (false !== (eventBlogId = await self.getUrlParam('show_event_blog', false))) {
                    self.currentItemId = eventBlogId;

                    // Adjust current page
                    self.currentItemIndex = self.getCurrentItemIndex();
                    self.currentPage = self.getCurrentPage();

                    // Fetch detail page
                    self.fetchReaderDetailContent();
                }

                // Set self.currentPage if user goes back/forward in the browser history
                window.onpopstate = async function (event) {
                    self.currentPage = await self.getUrlParam('page_e' + self.options.params.listModuleId, 1);
                };

                //
                document.addEventListener('REQUEST_STACK_PROCESSOR::modal-reader-content-loaded', (event) => {
                    this.updateDetailContentInModal(event);
                });

            },

            watch: {
                currentPage: async function (val) {
                    let self = this;

                    // Add the current page to the url without reloading the page
                    let nextURL = await (function () {
                        if (self.currentPage < 2) {
                            return self.removeUrlParam('page_e' + self.options.params.listModuleId);
                        } else {
                            return self.setUrlParam('page_e' + self.options.params.listModuleId, self.currentPage);
                        }
                    })();

                    if (nextURL !== window.location.href) {
                        window.history.pushState({}, document.title, nextURL);
                    }

                    // Fetch items from server
                    self.fetchList();
                },

                currentItemId: function (val) {
                    let self = this;

                    // currentItemId === null do not change page
                    if (self.currentItemId !== null) {
                        // Adjust current page
                        self.currentItemIndex = self.getCurrentItemIndex();
                        self.currentPage = self.getCurrentPage();

                        // Fetch detail page
                        self.fetchReaderDetailContent();
                    }
                },

            },

            methods: {

                /**
                 * Fetch items from server
                 * Use markocupic/contao-content-api
                 */
                fetchList: function fetchList() {

                    let self = this;

                    let url = window.location.protocol + '//' + window.location.hostname + '/_api/' + self.options.params.apiKey + '/' + self.options.params.listModuleId + '?page_e' + self.options.params.listModuleId + '=' + self.currentPage + '&_locale=' + self.options.params.language;

                    fetch(url, {

                        method: "GET", headers: {
                            'x-requested-with': 'XMLHttpRequest'
                        },
                    }).then((res) => {
                        return res.json();
                    }).then((json) => {
                        const list = document.querySelector(elId + ' .list-container');

                        self._fadeOutAndIn(list, json.compiledHTML, 500);

                        return new Promise(resolve => setTimeout(() => {
                            resolve();
                        }, 510));
                    }).then(() => {
                        // trigger same height for item boxes
                        // see: vendor\markocupic\contao-theme-sac-pilatus\src\Resources\contao\files\theme-sac-pilatus\js\theme.js
                        window.dispatchEvent(new CustomEvent('vueupdate'));

                        let cssSelectorStr = elId + ' .pagination .link, ' + elId + ' .pagination .first, ' + elId + ' .pagination .last, ' + elId + ' .pagination .previous, ' + elId + ' .pagination .next';

                        const elements = document.querySelectorAll(cssSelectorStr);

                        for (const element of elements) {
                            element.addEventListener('click', (e) => {
                                e.stopPropagation();
                                e.preventDefault();

                                let href = element.getAttribute('href');
                                let regexp = new RegExp("page_e" + self.options.params.listModuleId + "=([\\d]+)");
                                let match = regexp.exec(href);
                                let page = match ? match[1] : 1;

                                self.currentPage = parseInt(page);
                            });
                        }
                    }).then(() => {
                        const elements = document.querySelectorAll(elId + ' a.item-reader-link');

                        for (const element of elements) {
                            element.addEventListener('click', (e) => {
                                e.stopPropagation();
                                e.preventDefault();

                                // Get the item id from href
                                let href = element.getAttribute('href');
                                let regex = /^(.*)(\/)([\d]+)/i;
                                let match = regex.exec(href);

                                if (match.length < 4) {
                                    console.log('Aborted! Could not load content. No item id found.');
                                    return;
                                }
                                let itemId = match[3];

                                if (!options.params.readerModuleId) {
                                    console.log('Aborted! Could not load content. No reader module id found.');
                                    return;
                                }

                                // Fetch reader content
                                itemId = parseInt(itemId);

                                if (itemId === self.currentItemId) {
                                    self.fetchReaderDetailContent();
                                    return;
                                }

                                self.currentItemId = parseInt(itemId);
                            });
                        }
                    });
                },

                updateDetailContentInModal: async function updateDetailContentInModal(event) {
                    try {
                        const response = await event.detail.response;
                        const uuid = event.detail.uuid;
                        const json = await response.json();
                        this.readerContent = json.compiledHTML;

                        if (!this.readerRequestStackProcessor.uuidExists(uuid)) {
                            console.log('Aborted! Could not load content. UUID not found.');
                            return;
                        }

                        let elModal = document.querySelector(elId + ' .modal');
                        let modal = bootstrap.Modal.getOrCreateInstance(elModal);

                        if (!this.isModalOpen()) {
                            modal.show();
                        }

                        this._initLightbox();

                    } catch (error) {
                        console.log(error);
                    }
                },

                /**
                 * Fetch reader/detail content
                 * Use markocupic/contao-content-api
                 */
                fetchReaderDetailContent: function fetchReaderDetailContent() {

                    // Use referer param to generate qrcode in EventBlogReaderController
                    const encodedReferer = btoa(window.location.href);

                    const url = `/_api/${this.options.params.apiKey}/${this.options.params.readerModuleId}?items=${this.currentItemId}&referer=${encodedReferer}&_locale=${this.options.params.language}`;
                    this.readerRequestStackProcessor.clearStack();

                    // the request stack processor will handle the request
                    // and the REQUEST_STACK_PROCESSOR::modal-reader-content-loaded
                    // will be dispatched when we have a response
                    this.readerRequestStackProcessor.addUrl(url, 'REQUEST_STACK_PROCESSOR::modal-reader-content-loaded');
                },

                /**
                 * Check for the prev item
                 * @returns {boolean}
                 */
                hasPrevItem: function hasPrevItem() {
                    let self = this;
                    return typeof self.itemIds[self.currentItemIndex - 1] !== 'undefined';

                },

                /**
                 * Check for the text item
                 * @returns {boolean}
                 */
                hasNextItem: function hasNextItem() {
                    let self = this;
                    return typeof self.itemIds[self.currentItemIndex + 1] !== 'undefined';

                },

                /**
                 * Set current ItemId
                 * The watcher will do the rest...
                 */
                goToNextItem: function goToNextItem() {
                    let self = this;
                    self.currentItemId = parseInt(self.itemIds[self.currentItemIndex + 1]);
                },

                getCurrentItemIndex: function getCurrentItemIndex() {
                    let self = this;
                    return self.itemIds.indexOf(parseInt(self.currentItemId));
                },

                getCurrentPage: function getCurrentPage() {
                    let self = this;
                    return Math.floor(parseInt(self.currentItemIndex) / parseInt(self.options.params.perPage)) + 1;
                },

                /**
                 * Set current ItemId
                 * The watcher will do the rest...
                 */
                goToPrevItem: function goToPrevItem() {
                    let self = this;
                    // Fetch reader content
                    self.currentItemId = parseInt(self.itemIds[self.currentItemIndex - 1]);
                },

                /**
                 * Get an url parameter from the search query string
                 * @param parameter
                 * @param defaultvalue
                 * @returns {Promise<string>}
                 */
                getUrlParam: function getUrlParam(parameter, defaultvalue) {

                    return new Promise(resolve => {
                        let params = new URLSearchParams(document.location.search);
                        if (params.has(parameter)) {
                            resolve(params.get(parameter));
                        } else {
                            resolve(defaultvalue);
                        }
                    });
                },

                /**
                 * Set an url parameter and return the new url
                 * @param parameter
                 * @param value
                 * @param href
                 * @returns {Promise<string>}
                 */
                setUrlParam: function setUrlParam(parameter, value, href = null) {

                    return new Promise(resolve => {
                        if (null === href) {
                            href = window.location.href;
                        }

                        let url = new URL(href);
                        let urlParams = new URLSearchParams(url.search);

                        if (urlParams.has(parameter)) {
                            urlParams.set(parameter, value);
                        } else {
                            urlParams.append(parameter, value);
                        }

                        href = `${window.location.protocol}//${window.location.hostname}${window.location.pathname}`;

                        resolve(href + (urlParams.toString() ? `?${urlParams.toString()}` : ''));
                    });

                },

                /**
                 * Remove an url parameter and return the new url
                 * @param parameter
                 * @param href
                 * @returns {Promise<string>}
                 */
                removeUrlParam: async function removeUrlParam(parameter, href = null) {

                    return new Promise(resolve => {
                        if (null === href) {
                            href = window.location.href;
                        }

                        let url = new URL(href);
                        let urlParams = new URLSearchParams(url.search);

                        if (urlParams.has(parameter)) {
                            urlParams.delete(parameter)
                        }

                        href = `${window.location.protocol}//${window.location.hostname}${window.location.pathname}`;

                        resolve(href + (urlParams.toString() ? `?${urlParams.toString()}` : ''));
                    });

                },

                /**
                 * Check if modal is open
                 * @returns {boolean}
                 */
                isModalOpen: function isModalOpen() {
                    return !!document.querySelector(elId + ' .modal.show');
                },

                /**
                 *
                 * @param e
                 * @private
                 */
                _handleKeyPress: function _handleKeyPress(e) {
                    const LEFT_ARROW = 37;
                    const RIGHT_ARROW = 39;

                    e = e || window.event;

                    const handleLeftArrow = () => {
                        if (this.isModalOpen() && this.hasPrevItem()) {
                            this.goToPrevItem();
                        }
                    };

                    const handleRightArrow = () => {
                        if (this.isModalOpen() && this.hasNextItem()) {
                            this.goToNextItem();
                        }
                    };

                    switch (e.keyCode) {
                        case LEFT_ARROW:
                            handleLeftArrow();
                            break;
                        case RIGHT_ARROW:
                            handleRightArrow();
                            break;
                        default:
                            break;
                    }
                },

                _removeEventListener: function _removeEventListener(elementSelector) {
                    const elements = document.querySelectorAll(elementSelector);
                    for (const element of elements) {
                        const clonedElement = element.cloneNode(true);
                        element.parentNode.replaceChild(clonedElement, element);
                    }
                },

                _fadeOutAndIn: function _fadeOutAndIn(element, newContent, duration) {
                    // Fade out the element
                    element.style.transition = `opacity ${duration}ms ease`;
                    element.style.opacity = '0';

                    // Wait for the fade-out to complete
                    setTimeout(() => {
                        // Change the content
                        element.innerHTML = newContent;

                        // Fade in the element
                        element.style.transition = `opacity ${duration}ms ease`;
                        element.style.opacity = '1';
                    }, duration); // Match the transition duration
                },

                /**
                 * Init Lightbox
                 * @private
                 */
                _initLightbox: function _initLightbox() {
                    // GLightbox support
                    if ('undefined' !== typeof GLightbox) {
                        (function () {
                            'use strict';
                            document.querySelectorAll('a[data-lightbox]').forEach((element) => {
                                if (!!element.dataset.lightbox) {
                                    element.setAttribute('data-gallery', element.dataset.lightbox);
                                }
                            });
                            GLightbox({
                                selector: 'a[data-lightbox]'
                            });
                        })();
                    } else {
                        // Colorbox support
                        if (typeof jQuery !== 'undefined') {
                            const links = document.querySelectorAll('a[data-lightbox]');

                            for (const link of links) {
                                jQuery(link).colorbox({
                                    // Put custom options here
                                    loop: false, rel: jQuery(link).attr('data-lightbox'), maxWidth: '95%', maxHeight: '95%'
                                });
                            }

                        }
                    }
                },
            }
        });
        app.mount(elId);
    }
}
