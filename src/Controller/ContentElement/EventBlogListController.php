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

namespace Markocupic\SacEventBlogBundle\Controller\ContentElement;

use Contao\Config;
use Contao\ContentModel;
use Contao\CoreBundle\Controller\ContentElement\AbstractContentElementController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsContentElement;
use Contao\CoreBundle\Exception\PageNotFoundException;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Exception\ResponseException;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\CoreBundle\Routing\ScopeMatcher;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Contao\FilesModel;
use Contao\MemberModel;
use Contao\PageModel;
use Contao\Pagination;
use Contao\StringUtil;
use Contao\Validator;
use Markocupic\SacEventBlogBundle\EventBlog\EventBlogListFinder;
use Markocupic\SacEventBlogBundle\Model\CalendarEventsBlogModel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Lists the event blogs as cards. The pagination and the reader (in a modal window)
 * are loaded with HTMX: A HTMX request goes to the current page and this controller
 * answers it with the list items only (see self::HTMX_TARGET_PREFIX).
 */
#[AsContentElement(EventBlogListController::TYPE, category: 'sac_event_blog')]
class EventBlogListController extends AbstractContentElementController
{
    public const string TYPE = 'event_blog_list';

    /**
     * The HTML id of the list container is HTMX_TARGET_PREFIX.<content element id>.
     */
    public const string HTMX_TARGET_PREFIX = 'event-blog-list-';

    /**
     * The HTML id of the modal body is READER_TARGET_PREFIX.<content element id>.
     * The reader element uses it to find the list element (prev/next navigation).
     */
    public const string READER_TARGET_PREFIX = 'event-blog-reader-';

    /**
     * @var list<int>
     */
    private array $blogIds = [];

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly ContentUrlGenerator $contentUrlGenerator,
        private readonly EventBlogListFinder $eventBlogListFinder,
        private readonly ScopeMatcher $scopeMatcher,
        private readonly string $projectDir,
    ) {
    }

    public function __invoke(Request $request, ContentModel $model, string $section, array|null $classes = null): Response
    {
        // The element can not be rendered in the backend preview: show its name instead
        if ($this->scopeMatcher->isBackendRequest($request)) {
            return new Response('<p class="tl_gray">'.htmlspecialchars($GLOBALS['TL_LANG']['CTE'][self::TYPE][0] ?? self::TYPE).'</p>');
        }

        if ($this->scopeMatcher->isFrontendRequest($request)) {
            // Old direct links and QR codes (?show_event_blog=123) point to the list page: redirect to the reader page
            if (!$this->isHtmxRequest($request, $model) && ($blogId = (int) $request->query->get('show_event_blog')) > 0 && null !== ($readerPage = $this->getReaderPage($model))) {
                throw new RedirectResponseException($this->contentUrlGenerator->generate($readerPage, ['parameters' => '/'.$blogId], UrlGeneratorInterface::ABSOLUTE_URL), Response::HTTP_MOVED_PERMANENTLY);
            }

            $this->blogIds = $this->eventBlogListFinder->findIds($model);

            if ([] === $this->blogIds) {
                // HTMX request from the pagination: send an empty list instead of the full page
                if ($this->isHtmxRequest($request, $model)) {
                    $response = new Response('');
                    $response->setPrivate();
                    $response->headers->addCacheControlDirective('no-store');

                    throw new ResponseException($response);
                }

                return new Response('', Response::HTTP_NO_CONTENT);
            }
        }

        return parent::__invoke($request, $model, $section, $classes);
    }

    public static function isHtmxRequestFor(Request $request, string $targetId): bool
    {
        return 'true' === $request->headers->get('HX-Request') && $targetId === $request->headers->get('HX-Target');
    }

    protected function getResponse(FragmentTemplate $template, ContentModel $model, Request $request): Response
    {
        // Prepare the pagination
        $total = \count($this->blogIds);
        $offset = 0;
        $limit = $total;

        if ($model->perPage > 0) {
            $id = 'page_e'.$model->id;
            $page = (int) ($request->query->get($id) ?: 1);

            // Do not index or cache the page if the page number is outside the range
            if ($page < 1 || $page > max(ceil($total / $model->perPage), 1)) {
                throw new PageNotFoundException('Page not found: '.$request->getUri());
            }

            $offset = ($page - 1) * $model->perPage;
            $limit = (int) $model->perPage;

            $objPagination = new Pagination($total, $model->perPage, $this->framework->getAdapter(Config::class)->get('maxPaginationLinks'), $id);
            $template->set('pagination', $objPagination->generate(' '));
        }

        $template->set('blogs', $this->getBlogs(\array_slice($this->blogIds, $offset, $limit), $model));
        $template->set('htmx_target', self::HTMX_TARGET_PREFIX.$model->id);
        $template->set('reader_target', self::READER_TARGET_PREFIX.$model->id);

        // HTMX request from the pagination: only send the list items and the pagination
        if ($this->isHtmxRequest($request, $model)) {
            $template->set('htmx_fragment', true);

            $response = $template->getResponse();
            $response->setPrivate();
            $response->headers->addCacheControlDirective('no-store');

            throw new ResponseException($response);
        }

        return $template->getResponse();
    }

    private function isHtmxRequest(Request $request, ContentModel $model): bool
    {
        return self::isHtmxRequestFor($request, self::HTMX_TARGET_PREFIX.$model->id);
    }

    private function getReaderPage(ContentModel $model): PageModel|null
    {
        if (!$model->eventBlogJumpTo) {
            return null;
        }

        return $this->framework->getAdapter(PageModel::class)->findById($model->eventBlogJumpTo);
    }

    /**
     * @param list<int> $ids
     */
    private function getBlogs(array $ids, ContentModel $model): array
    {
        if ([] === $ids) {
            return [];
        }

        // Adapters
        $memberModelAdapter = $this->framework->getAdapter(MemberModel::class);
        $stringUtilAdapter = $this->framework->getAdapter(StringUtil::class);
        $validatorAdapter = $this->framework->getAdapter(Validator::class);
        $filesModelAdapter = $this->framework->getAdapter(FilesModel::class);

        $readerPage = $this->getReaderPage($model);

        $objBlogs = $this->framework->getAdapter(CalendarEventsBlogModel::class)->findMultipleByIds($ids, ['order' => 'dateAdded DESC, id DESC']);

        if (null === $objBlogs) {
            return [];
        }

        $arrBlogs = [];

        while ($objBlogs->next()) {
            $arrBlog = $objBlogs->row();

            // If the profile has been deleted, $objMember will be null!
            $objMember = $memberModelAdapter->findOneBySacMemberId($arrBlog['sacMemberId']);
            $arrBlog['author'] = null !== $objMember ? $objMember->row() : [];
            $arrBlog['author']['model'] = $objMember;
            $arrBlog['author']['name'] = null !== $objMember ? $objMember->firstname.' '.$objMember->lastname : $objBlogs->authorName;
            $arrBlog['href'] = null !== $readerPage ? $this->contentUrlGenerator->generate($readerPage, ['parameters' => '/'.$objBlogs->id]) : null;

            // Add a random image to the list
            $arrBlog['singleSRC'] = null;

            $multiSRC = $stringUtilAdapter->deserialize($arrBlog['multiSRC'], true);

            if (!empty($multiSRC)) {
                $singleSRC = $multiSRC[array_rand($multiSRC)];

                if ($validatorAdapter->isUuid($singleSRC)) {
                    $objFile = $filesModelAdapter->findByUuid($singleSRC);

                    if (null !== $objFile && is_file($this->projectDir.'/'.$objFile->path)) {
                        $arrBlog['singleSRC'] = [
                            'id' => $objFile->id,
                            'path' => $objFile->path,
                            'uuid' => $stringUtilAdapter->binToUuid($objFile->uuid),
                            'name' => $objFile->name,
                            'singleSRC' => $objFile->path,
                            'title' => $stringUtilAdapter->specialchars($objFile->name),
                            'filesModel' => $objFile->current(),
                        ];
                    }
                }
            }

            $arrBlogs[] = $arrBlog;
        }

        return $arrBlogs;
    }
}
