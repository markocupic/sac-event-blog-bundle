<?php

declare(strict_types=1);

/*
 * This file is part of SAC Event Blog Bundle.
 *
 * (c) Marko Cupic <m.cupic@gmx.ch>
 * @license GPL-3.0-or-later
 * For the full copyright and license information,
 * please view the LICENSE file that was distributed with this source code.
 * @link https://github.com/markocupic/sac-event-blog-bundle
 */

namespace Markocupic\SacEventBlogBundle\EventListener\Kernel;

use Contao\ContentModel;
use Contao\Controller;
use Contao\CoreBundle\Exception\ResponseException;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ResponseContext\CoreResponseContextFactory;
use Contao\CoreBundle\Routing\ResponseContext\ResponseContextAccessor;
use Contao\CoreBundle\Routing\ScopeMatcher;
use Contao\CoreBundle\Util\LocaleUtil;
use Contao\FrontendIndex;
use Contao\LayoutModel;
use Contao\PageModel;
use Contao\System;
use Doctrine\DBAL\Connection;
use Markocupic\SacEventBlogBundle\Controller\ContentElement\EventBlogListController;
use Markocupic\SacEventBlogBundle\Controller\ContentElement\EventBlogReaderController;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Shortcut for the HTMX requests of the event blog list and reader elements.
 *
 * The HTMX requests go to the regular page URL. Without this listener Contao renders
 * the whole page (layout, navigation, all other elements) until the event blog element
 * answers with its fragment (ResponseException). This listener replaces the page
 * controller and renders the targeted content element only - with its own controller.
 *
 * Contao has already resolved the page and checked the access (protected pages) at this point.
 * If the element can not be found on the page, nothing happens and the full page is rendered.
 */
#[AsEventListener(event: KernelEvents::CONTROLLER)]
class HtmxFragmentRequestListener
{
    /**
     * Published, not protected (protected articles: the full page is rendered), start/stop.
     */
    private const string ARTICLE_IS_VISIBLE = "a.published = 1 AND a.protected = 0 AND (a.start = '' OR a.start <= UNIX_TIMESTAMP()) AND (a.stop = '' OR a.stop > UNIX_TIMESTAMP())";

    public function __construct(
        private readonly Connection $connection,
        private readonly ContaoFramework $framework,
        private readonly CoreResponseContextFactory $responseContextFactory,
        private readonly ResponseContextAccessor $responseContextAccessor,
        private readonly ScopeMatcher $scopeMatcher,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(ControllerEvent $event): void
    {
        if (!$this->scopeMatcher->isFrontendMainRequest($event)) {
            return;
        }

        $request = $event->getRequest();

        if ('true' !== $request->headers->get('HX-Request')) {
            return;
        }

        // Only regular pages rendered by Contao
        $controller = $event->getController();

        if (!\is_array($controller) || !$controller[0] instanceof FrontendIndex || 'renderPage' !== $controller[1]) {
            return;
        }

        $pageModel = $request->attributes->get('pageModel');

        if (!$pageModel instanceof PageModel || 'regular' !== $pageModel->type) {
            return;
        }

        if (null === ($elementId = $this->findTargetedElement($request, (int) $pageModel->id))) {
            return;
        }

        $start = microtime(true);

        $event->setController(fn (): Response => $this->renderElement($request, $pageModel, $elementId, $start));
    }

    private function renderElement(Request $request, PageModel $pageModel, int $elementId, float $start): Response
    {
        $this->framework->initialize();

        // Prepare the page like Contao\PageRegular does (without layout and the other elements)
        $GLOBALS['objPage'] = $pageModel;
        $pageModel->loadDetails();

        $GLOBALS['TL_LANGUAGE'] = LocaleUtil::formatAsLanguageTag($pageModel->language);
        $locale = LocaleUtil::formatAsLocale($pageModel->language);
        $request->setLocale($locale);

        if ($this->translator instanceof LocaleAwareInterface) {
            $this->translator->setLocale($locale);
        }

        if (!$this->responseContextAccessor->getResponseContext()) {
            $this->responseContextFactory->createContaoWebpageResponseContext($pageModel);
        }

        $this->framework->getAdapter(System::class)->loadLanguageFile('default');

        // Use the same image densities as on the page
        if (null !== ($layout = $this->framework->getAdapter(LayoutModel::class)->findById($pageModel->layout))) {
            $container = System::getContainer();
            $container->get('contao.image.picture_factory')->setDefaultDensities((string) $layout->defaultImageDensities);
            $container->get('contao.image.preview_factory')->setDefaultDensities((string) $layout->defaultImageDensities);
        }

        $model = $this->framework->getAdapter(ContentModel::class)->findById($elementId);
        $renderStart = microtime(true);

        try {
            // The element controller answers the HTMX request with a ResponseException.
            // If it doesn't (e.g. the element is hidden), send an empty response that htmx does not swap.
            $html = null !== $model ? (string) $this->framework->getAdapter(Controller::class)->getContentElement($model) : '';
            $response = '' === trim($html) ? new Response('', Response::HTTP_NO_CONTENT) : new Response($html);
        } catch (ResponseException $e) {
            $response = $e->getResponse();
        }

        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        // Timing (see the network tab of the browser dev tools):
        // boot = from the start of the request until this listener, prepare = page setup, element = rendering of the element
        $requestStart = (float) $request->server->get('REQUEST_TIME_FLOAT', $start);
        $response->headers->set('Server-Timing', \sprintf(
            'boot;dur=%.1f, prepare;dur=%.1f, element;dur=%.1f',
            ($start - $requestStart) * 1000,
            ($renderStart - $start) * 1000,
            (microtime(true) - $renderStart) * 1000,
        ));

        return $response;
    }

    /**
     * Return the id of the event blog element on this page the HTMX request targets.
     */
    private function findTargetedElement(Request $request, int $pageId): int|null
    {
        $target = (string) $request->headers->get('HX-Target');

        // Pagination of the list: HX-Target = event-blog-list-<list element id>
        if (preg_match('/^'.preg_quote(EventBlogListController::HTMX_TARGET_PREFIX, '/').'(\d+)$/', $target, $matches)) {
            $id = $this->connection->fetchOne(
                'SELECT c.id FROM tl_content c INNER JOIN tl_article a ON a.id = c.pid AND c.ptable = \'tl_article\' WHERE c.id = ? AND c.type = ? AND a.pid = ? AND '.self::ARTICLE_IS_VISIBLE,
                [(int) $matches[1], EventBlogListController::TYPE, $pageId],
            );

            return false !== $id ? (int) $id : null;
        }

        // Reader in the modal window of the list: HX-Target = event-blog-reader-<list element id>,
        // the request goes to the reader page
        if (preg_match('/^'.preg_quote(EventBlogListController::READER_TARGET_PREFIX, '/').'\d+$/', $target)) {
            $id = $this->connection->fetchOne(
                'SELECT c.id FROM tl_content c INNER JOIN tl_article a ON a.id = c.pid AND c.ptable = \'tl_article\' WHERE c.type = ? AND a.pid = ? AND '.self::ARTICLE_IS_VISIBLE.' AND c.invisible = 0 ORDER BY a.sorting, c.sorting LIMIT 1',
                [EventBlogReaderController::TYPE, $pageId],
            );

            return false !== $id ? (int) $id : null;
        }

        return null;
    }
}
