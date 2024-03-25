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

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Codefog\HasteBundle\UrlParser;
use Contao\CalendarEventsModel;
use Contao\CoreBundle\Controller\FrontendModule\AbstractFrontendModuleController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsFrontendModule;
use Contao\CoreBundle\Exception\PageNotFoundException;
use Contao\CoreBundle\Filesystem\FilesystemItem;
use Contao\CoreBundle\Filesystem\FilesystemUtil;
use Contao\CoreBundle\Filesystem\VirtualFilesystem;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ScopeMatcher;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Contao\CoreBundle\Util\SymlinkUtil;
use Contao\Environment;
use Contao\FilesModel;
use Contao\Folder;
use Contao\Input;
use Contao\MemberModel;
use Contao\ModuleModel;
use Contao\PageModel;
use Contao\StringUtil;
use Markocupic\SacEventBlogBundle\Config\PublishState;
use Markocupic\SacEventBlogBundle\Model\CalendarEventsBlogModel;
use Markocupic\SacEventToolBundle\CalendarEventsHelper;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[AsFrontendModule(EventBlogReaderController::TYPE, category: 'sac_event_tool_frontend_modules', template: 'mod_event_blog_reader')]
class EventBlogReaderController extends AbstractFrontendModuleController
{
    public const TYPE = 'event_blog_reader';

    private CalendarEventsBlogModel|null $blog = null;
    private bool $isPreviewMode = false;

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly ScopeMatcher $scopeMatcher,
        private readonly UrlParser $urlParser,
        private readonly VirtualFilesystem $filesStorage,
        private readonly string $projectDir,
        private readonly string $locale,
    ) {
    }

    public function __invoke(Request $request, ModuleModel $model, string $section, array $classes = null, PageModel $page = null): Response
    {
        if ($this->scopeMatcher->isFrontendRequest($request)) {
            // Adapters
            $calendarEventsBlogModelAdapter = $this->framework->getAdapter(CalendarEventsBlogModel::class);
            $environmentAdapter = $this->framework->getAdapter(Environment::class);
            $inputAdapter = $this->framework->getAdapter(Input::class);

            // Set the item from the auto_item parameter
            if (empty($inputAdapter->get('items'))) {
                $inputAdapter->setGet('items', $inputAdapter->get('auto_item'));
            }

            // Do not index or cache the page if no blog item has been specified
            if ($page && empty($inputAdapter->get('items'))) {
                $page->noSearch = 1;
                $page->cache = 0;

                return new Response('', Response::HTTP_NO_CONTENT);
            }

            if (!empty($inputAdapter->get('securityToken'))) {
                $arrColumns = ['tl_calendar_events_blog.securityToken = ?', 'tl_calendar_events_blog.id = ?'];
                $arrValues = [$inputAdapter->get('securityToken'), $inputAdapter->get('items')];
                $this->isPreviewMode = true;
            } else {
                $arrColumns = ['tl_calendar_events_blog.publishState = ?', 'tl_calendar_events_blog.id = ?'];
                $arrValues = [PublishState::PUBLISHED, $inputAdapter->get('items')];
            }

            $this->blog = $calendarEventsBlogModelAdapter->findOneBy($arrColumns, $arrValues);

            if (null === $this->blog) {
                throw new PageNotFoundException('Page not found: '.$environmentAdapter->get('uri'));
            }
        }

        return parent::__invoke($request, $model, $section, $classes);
    }

    /**
     * @throws \Exception
     */
    protected function getResponse(FragmentTemplate $template, ModuleModel $model, Request $request): Response
    {
        // Adapters
        $memberModelModelAdapter = $this->framework->getAdapter(MemberModel::class);
        $calendarEventsModelAdapter = $this->framework->getAdapter(CalendarEventsModel::class);
        $calendarEventsHelperAdapter = $this->framework->getAdapter(CalendarEventsHelper::class);

        // Set data
        $template->setData($this->blog->row());

        $template->set('class', $template->has('class') ? $template->get('class') : '');
        $template->set('cssID', $template->has('cssID') ? $template->get('cssID') : '');

        // Set title as headline
        $template->set('headline', $this->blog->title);

        // Twig callable
        $template->set('binToUuid', static fn (string $uuid): string => StringUtil::binToUuid($uuid));

        // Fallback if author is no more findable in tl_member
        $objAuthor = $memberModelModelAdapter->findOneBySacMemberId($this->blog->sacMemberId);
        $template->set('authorName', null !== $objAuthor ? $objAuthor->firstname.' '.$objAuthor->lastname : $this->blog->authorName);

        // !!! $objEvent can be NULL, if the related event no more exists
        $objEvent = $calendarEventsModelAdapter->findByPk($this->blog->eventId);
        $template->set('event', $objEvent->row());
        $template->set('blog', $this->blog->row());

        if (!$this->isPreviewMode) {
            if ($request->query->has('referer')) {
                $url = base64_decode($request->query->get('referer', ''), true);
                $url = $this->urlParser->addQueryString('show_event_blog='.$this->blog->id, $url);
            } else {
                $url = $this->urlParser->addQueryString('show_event_blog='.$this->blog->id);
            }

            // Remove facebook "fbclid" param
            $url = $this->urlParser->removeQueryString(['fbclid'], $url);

            if (!empty($url)) {
                if (null !== ($qrCodePath = $this->getQrCodeFromUrl($url))) {
                    $template->qrCodePath = $qrCodePath;
                    $template->directLink = $url;
                }
            }
        }

        // Add the gallery

        // Find all images
        $filesystemItems = FilesystemUtil::listContentsFromSerialized($this->filesStorage, $this->blog->multiSRC ?? [])
            ->filter(static fn ($item) => \in_array($item->getExtension(true), ['jpg', 'JPG', 'png', 'PNG'], true))
        ;

        // We do not have to sort the gallery,
        // because we us custom sorting.

        $imageList = [];

        /** @var FilesystemItem $filesystemItem */
        foreach (iterator_to_array($filesystemItems) as $filesystemItem) {
            $file = FilesModel::findByUuid(StringUtil::uuidToBin($filesystemItem->getUuid()));

            if ($file && is_file(Path::makeAbsolute($file->path, $this->projectDir))) {
                $imageList[] = [
                    'uuid' => $filesystemItem->getUuid(),
                    'href' => $file->path,
                    'meta' => ($filesystemItem->getExtraMetadata()['metadata'])->get($this->locale),
                ];
            }
        }

        $template->set('imageList', $imageList);

        // Add YouTube movie
        $template->set('youTubeId', !empty($this->blog->youTubeId) ? $this->blog->youTubeId : null);

        // tour instructors
        $arrTourInstructors = $calendarEventsHelperAdapter->getInstructorNamesAsArray($objEvent);

        if (!empty($arrTourInstructors)) {
            $template->set('tourInstructors', implode(', ', $arrTourInstructors));
        }

        // tour types
        $arrTourTypes = CalendarEventsHelper::getTourTypesAsArray($objEvent, 'title');

        if (!empty($arrTourTypes)) {
            $template->set('tourTypes', implode(', ', $arrTourTypes));
        }

        // event dates
        $template->set('eventDates', CalendarEventsHelper::getEventPeriod($objEvent, 'd.m.Y', false));

        // tour tech. difficulty
        $template->set('tourTechDifficulty', $this->blog->tourTechDifficulty ?? '');

        if (empty($template->get('tourTechDifficulty')) && !empty($objEvent->tourTechDifficulty)) {
            $arrTourTechDiff = $calendarEventsHelperAdapter->getTourTechDifficultiesAsArray($objEvent);
            $template->set('tourTechDifficulty', !empty($arrTourTechDiff) ? implode(', ', $arrTourTechDiff) : null);
        }

        // event organizers
        $arrEventOrganizers = $calendarEventsHelperAdapter->getEventOrganizersAsArray($objEvent);

        if (!empty($arrEventOrganizers)) {
            $template->set('eventOrganizers', implode(', ', $arrEventOrganizers));
        }

        if (!empty($this->blog->tourWaypoints)) {
            $template->set('tourWaypoints', nl2br((string) $this->blog->tourWaypoints));
        }

        if (!empty($this->blog->tourProfile)) {
            $template->set('tourProfile', nl2br((string) $this->blog->tourProfile));
        }

        if (!empty($this->blog->tourHighlights)) {
            $template->set('tourHighlights', nl2br((string) $this->blog->tourHighlights));
        }

        if (!empty($this->blog->tourPublicTransportInfo)) {
            $template->set('tourPublicTransportInfo', nl2br((string) $this->blog->tourPublicTransportInfo));
        }

        return $template->getResponse();
    }

    private function getQrCodeFromUrl(string $url): string|null
    {
        // Generate QR code folder
        $objFolder = new Folder('system/eventblogqrcodes');

        // Get the web directory as relative path --> public (or web)
        $webDir = Path::join($this->projectDir, 'public');

        // Symlink (path: 'system/eventblogqrcodes', link: 'public/system/eventblogqrcodes')
        SymlinkUtil::symlink($objFolder->path, $webDir.'/'.$objFolder->path, $this->projectDir);

        // Generate path
        $filepath = sprintf($objFolder->path.'/'.'eventBlogQRcode_%s.png', md5($url));

        // Defaults
        $opt = [
            'version' => 5,
            'scale' => 4,
            'outputType' => QRCode::OUTPUT_IMAGE_PNG,
            'eccLevel' => QRCode::ECC_L,
            'cachefile' => $filepath,
        ];

        $options = new QROptions($opt);

        // Generate QR code and return the image path
        if ((new QRCode($options))->render($url, $filepath)) {
            return $filepath;
        }

        return null;
    }
}
