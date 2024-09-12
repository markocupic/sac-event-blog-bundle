<?php

declare(strict_types=1);

/*
 * This file is part of SAC Event Blog Bundle.
 *
 * (c) Marko Cupic 2024 <m.cupic@gmx.ch>
 * @license GPL-3.0-or-later
 * For the full copyright and license information,
 * please view the LICENSE file that was distributed with this source code.
 * @link https://github.com/markocupic/sac-event-blog-bundle
 */

namespace Markocupic\SacEventBlogBundle\Controller\FrontendModule;

use Contao\Config;
use Contao\CoreBundle\Controller\FrontendModule\AbstractFrontendModuleController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsFrontendModule;
use Contao\CoreBundle\Exception\PageNotFoundException;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ScopeMatcher;
use Contao\CoreBundle\Util\LocaleUtil;
use Contao\Environment;
use Contao\FilesModel;
use Contao\MemberModel;
use Contao\Model\Collection;
use Contao\ModuleModel;
use Contao\PageModel;
use Contao\Pagination;
use Contao\StringUtil;
use Contao\Template;
use Contao\Validator;
use Markocupic\SacEventBlogBundle\Config\PublishState;
use Markocupic\SacEventBlogBundle\Model\CalendarEventsBlogModel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[AsFrontendModule(EventBlogListController::TYPE, category:'sac_event_tool_frontend_modules', template:'mod_event_blog_list')]
class EventBlogListController extends AbstractFrontendModuleController
{
    public const TYPE = 'event_blog_list';

    private Collection|null $blogs;

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly ScopeMatcher $scopeMatcher,
        private readonly string $projectDir,
    ) {
    }

    public function __invoke(Request $request, ModuleModel $model, string $section, array $classes = null, PageModel $page = null): Response
    {
        if ($this->scopeMatcher->isFrontendRequest($request)) {
            // Adapters
            $calendarEventsBlogModelAdapter = $this->framework->getAdapter(CalendarEventsBlogModel::class);
            $stringUtilAdapter = $this->framework->getAdapter(StringUtil::class);

            $arrIds = [];
            $arrOptions = ['order' => 'dateAdded DESC'];

            // Find all published blogs
            $objBlogs = $calendarEventsBlogModelAdapter->findBy(
                ['tl_calendar_events_blog.publishState = ?'],
                [PublishState::PUBLISHED],
                $arrOptions
            );

            if (null !== $objBlogs) {
                while ($objBlogs->next()) {
                    $arrOrganizers = $stringUtilAdapter->deserialize($objBlogs->organizers, true);

                    if (\count(array_intersect($arrOrganizers, $stringUtilAdapter->deserialize($model->eventBlogOrganizers, true))) > 0) {
                        $arrIds[] = $objBlogs->id;
                    }
                }
            }

            $this->blogs = $calendarEventsBlogModelAdapter->findMultipleByIds($arrIds, $arrOptions);

            if (null === $this->blogs) {
                return new Response('', Response::HTTP_NO_CONTENT);
            }
        }

        return parent::__invoke($request, $model, $section, $classes);
    }

    protected function getResponse(Template $template, ModuleModel $model, Request $request): Response
    {
        // Adapters
        $memberModelModelAdapter = $this->framework->getAdapter(MemberModel::class);
        $stringUtilAdapter = $this->framework->getAdapter(StringUtil::class);
        $configAdapter = $this->framework->getAdapter(Config::class);
        $validatorAdapter = $this->framework->getAdapter(Validator::class);
        $filesModelAdapter = $this->framework->getAdapter(FilesModel::class);
        $environmentAdapter = $this->framework->getAdapter(Environment::class);
        $pageModelAdapter = $this->framework->getAdapter(PageModel::class);

        $objPageModel = null;

        if ($model->jumpTo) {
            $objPageModel = $pageModelAdapter->findByPk($model->jumpTo);
        }

        $arrBlogsAll = [];
        $arrBlogIds = [];

        while ($this->blogs->next()) {
            $arrBlog = $this->blogs->row();
            $arrBlogIds[] = $arrBlog['id'];
            // If the profile has been deleted, $objMember will be null!
            $objMember = $memberModelModelAdapter->findOneBySacMemberId($arrBlog['sacMemberId']);
            $arrBlog['author'] = null !== $objMember ? $objMember?->row() : [];
            $arrBlog['author']['model'] = $objMember;
            $arrBlog['author']['name'] = null !== $objMember ? $objMember->firstname.' '.$objMember->lastname : $this->blogs->authorname;
            $arrBlog['href'] = null !== $objPageModel ? $stringUtilAdapter->ampersand($objPageModel->getFrontendUrl('/'.$this->blogs->id)) : null;

            $multiSRC = $stringUtilAdapter->deserialize($arrBlog['multiSRC'], true);

            // Add a random image to the list
            $arrBlog['singleSRC'] = null;

            if (!empty($multiSRC) && \is_array($multiSRC)) {
                $k = array_rand($multiSRC);
                $singleSRC = $multiSRC[$k];

                if ($validatorAdapter->isUuid($singleSRC)) {
                    $objFiles = $filesModelAdapter->findByUuid($singleSRC);

                    if (null !== $objFiles) {
                        if (is_file($this->projectDir.'/'.$objFiles->path)) {
                            $arrBlog['singleSRC'] = [
                                'id' => $objFiles->id,
                                'path' => $objFiles->path,
                                'uuid' => $stringUtilAdapter->binToUuid($objFiles->uuid),
                                'name' => $objFiles->name,
                                'singleSRC' => $objFiles->path,
                                'title' => $stringUtilAdapter->specialchars($objFiles->name),
                                'filesModel' => $objFiles->current(),
                            ];
                        }
                    }
                }
            }

            $arrBlogsAll[] = $arrBlog;
        }

        $template->arrBlogIds = $arrBlogIds;

        // Prepare the pagination
        $total = \count($arrBlogsAll);
        $limit = $total;
        $offset = 0;

        if ($model->eventBlogLimit > 0) {
            $total = min($model->eventBlogLimit, $total);
            $limit = $total;
        }

        if ($model->perPage > 0) {
            $id = 'page_e'.$model->id;

            $page = !empty($request->query->get($id)) ? $request->query->get($id) : 1;

            // Do not index or cache the page if the page number is outside the range
            if ($page < 1 || $page > max(ceil($total / $model->perPage), 1)) {
                throw new PageNotFoundException('Page not found: '.$environmentAdapter->get('uri'));
            }

            $offset = ($page - 1) * $model->perPage;
            $limit = min($model->perPage + $offset, $total);

            $objPagination = new Pagination($total, $model->perPage, $configAdapter->get('maxPaginationLinks'), $id);
            $template->pagination = $objPagination->generate(' ');
        }

        // Add blogs to the template
        $arrBlogs = [];

        for ($i = $offset; $i < $limit; ++$i) {
            if (!isset($arrBlogsAll[$i]) || !\is_array($arrBlogsAll[$i])) {
                continue;
            }
            $arrBlogs[] = $arrBlogsAll[$i];
        }

        $template->blogs = $arrBlogs;
        $template->language = LocaleUtil::formatAsLanguageTag($request->getLocale());
        $template->isAjaxRequest = $environmentAdapter->get('isAjaxRequest');

        return $template->getResponse();
    }
}
