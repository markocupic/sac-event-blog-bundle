/*
 * This file is part of SAC Event Blog Bundle.
 *
 * (c) Marko Cupic <m.cupic@gmx.ch>
 * @license GPL-3.0-or-later
 * For the full copyright and license information,
 * please view the LICENSE file that was distributed with this source code.
 * @link https://github.com/markocupic/sac-event-blog-bundle
 */

/*
 * Event blog list: the pagination and the reader are loaded with HTMX
 * (see contao/templates/twig/content_element/event_blog_list.html.twig).
 * This script only handles the modal window of the reader.
 */
(() => {
    'use strict';

    // Must match EventBlogListController::HTMX_TARGET_PREFIX and EventBlogListController::READER_TARGET_PREFIX
    const LIST_TARGET_PREFIX = 'event-blog-list-';
    const READER_TARGET_PREFIX = 'event-blog-reader-';

    const isListTarget = (el) => el instanceof Element && el.id.startsWith(LIST_TARGET_PREFIX);
    const isReaderTarget = (el) => el instanceof Element && el.id.startsWith(READER_TARGET_PREFIX);

    const SPINNER = '<div class="d-flex justify-content-center py-5" data-event-blog-spinner><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Wird geladen …</span></div></div>';
    const ERROR = '<div class="alert alert-danger" role="alert">Der Bericht konnte leider nicht geladen werden.</div>';

    // Dim the content while it is loading (inline styles set by JS are allowed by the CSP)
    const setLoading = (el, loading) => {
        el.style.transition = 'opacity .2s';
        el.style.opacity = loading ? '0.4' : '';
        el.style.pointerEvents = loading ? 'none' : '';
        el.style.cursor = loading ? 'progress' : '';
        el.setAttribute('aria-busy', loading ? 'true' : 'false');
    };

    // Loading indicator
    document.addEventListener('htmx:beforeRequest', (event) => {
        const target = event.detail.target;

        if (isListTarget(target)) {
            setLoading(target, true);

            return;
        }

        if (!isReaderTarget(target)) {
            return;
        }

        const elModal = target.closest('.modal');

        // Mark the modal window as open (see htmx:beforeSwap)
        target.dataset.eventBlogModalOpen = '1';

        if (elModal && !elModal.classList.contains('show')) {
            // Open the modal window immediately and show a spinner until the report has been loaded
            target.innerHTML = SPINNER;
            bootstrap.Modal.getOrCreateInstance(elModal).show();
        } else {
            // Prev/next navigation in the open modal window
            setLoading(target, true);
        }
    });

    // The modal window has been closed while the report was loading: discard the response
    document.addEventListener('htmx:beforeSwap', (event) => {
        const target = event.detail.target;

        if (isReaderTarget(target) && '1' !== target.dataset.eventBlogModalOpen) {
            event.detail.shouldSwap = false;
        }
    });

    document.addEventListener('htmx:afterRequest', (event) => {
        const target = event.detail.target;

        if (!isListTarget(target) && !isReaderTarget(target)) {
            return;
        }

        setLoading(target, false);

        // Not swapped (error or 204 No Content): replace the spinner with an error message
        const xhr = event.detail.xhr;

        if (isReaderTarget(target) && (!event.detail.successful || 204 === xhr?.status) && target.querySelector('[data-event-blog-spinner]')) {
            target.innerHTML = ERROR;
        }
    });

    // The pagination pushes its url to the browser history. Do not restore the page from the
    // htmx history cache (scripts of the theme would not be re-initialized), reload it instead.
    if ('undefined' !== typeof htmx) {
        htmx.config.historyCacheSize = 0;
        htmx.config.refreshOnHistoryMiss = true;
    }

    let lightbox = null;

    // Open the modal window, when the reader has been loaded
    document.addEventListener('htmx:afterSwap', (event) => {
        const target = event.detail.target;

        if (!isReaderTarget(target)) {
            return;
        }

        const elModal = target.closest('.modal');

        if (elModal) {
            bootstrap.Modal.getOrCreateInstance(elModal).show();
            // Scroll to the top, when the user navigates to the prev/next report
            // (the modal body is the scroll container in fullscreen mode)
            elModal.scrollTop = 0;
            target.scrollTop = 0;
        }

        // Initialize GLightbox (Vanilla)
        if ('undefined' !== typeof GLightbox) {
            if (lightbox) {
                lightbox.destroy();
            }

            lightbox = GLightbox({
                selector: '.sac-event-blog-reader--modal a[data-lightbox]',
            });
        }
    });

    // Remove the reader, when the modal window has been closed
    document.addEventListener('hidden.bs.modal', (event) => {
        const target = event.target.querySelector(`[id^="${READER_TARGET_PREFIX}"]`);

        if (target) {
            // A pending response must not open the modal window again (see htmx:beforeSwap)
            delete target.dataset.eventBlogModalOpen;
            target.innerHTML = '';
        }
    });

    // Navigate with the left/right arrow keys
    document.addEventListener('keydown', (event) => {
        if ('ArrowLeft' !== event.key && 'ArrowRight' !== event.key) {
            return;
        }

        // The arrow keys belong to the lightbox, while it is open
        if (document.body.classList.contains('glightbox-open') || document.documentElement.classList.contains('glightbox-open')) {
            return;
        }

        const elModal = document.querySelector('.event-blog-reader-modal.show');

        if (!elModal) {
            return;
        }

        const button = elModal.querySelector(`[data-event-blog-nav="${'ArrowLeft' === event.key ? 'prev' : 'next'}"]`);

        if (button) {
            event.preventDefault();
            button.click();
        }
    });
})();
