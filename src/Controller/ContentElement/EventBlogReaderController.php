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

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Contao\CalendarEventsModel;
use Contao\ContentModel;
use Contao\CoreBundle\Controller\ContentElement\AbstractContentElementController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsContentElement;
use Contao\CoreBundle\Exception\PageNotFoundException;
use Contao\CoreBundle\Exception\ResponseException;
use Contao\CoreBundle\Filesystem\FilesystemItem;
use Contao\CoreBundle\Filesystem\FilesystemUtil;
use Contao\CoreBundle\Filesystem\VirtualFilesystem;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\CoreBundle\Routing\ScopeMatcher;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Contao\CoreBundle\Util\SymlinkUtil;
use Contao\FilesModel;
use Contao\Folder;
use Contao\Input;
use Contao\MemberModel;
use Contao\PageModel;
use Contao\StringUtil;
use Markocupic\SacEventBlogBundle\Config\PublishState;
use Markocupic\SacEventBlogBundle\EventBlog\EventBlogListFinder;
use Markocupic\SacEventBlogBundle\Model\CalendarEventsBlogModel;
use Markocupic\SacEventToolBundle\Util\CalendarEventsUtil;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Shows an event blog on the reader page (auto_item = blog id).
 *
 * The "event blog list" element loads the reader with HTMX into its modal window:
 * The HTMX request goes to the reader page and targets the modal body
 * (HX-Target: event-blog-reader-<list element id>). In this case only the
 * reader element is sent, including the prev/next navigation of the list.
 */
#[AsContentElement(EventBlogReaderController::TYPE, category: 'sac_event_blog')]
class EventBlogReaderController extends AbstractContentElementController
{
    public const string TYPE = 'event_blog_reader';

    private CalendarEventsBlogModel|null $blog = null;

    private bool $isPreviewMode = false;

    /**
     * The list element that has loaded the reader into its modal window (HTMX request).
     */
    private ContentModel|null $listElement = null;

    public function __construct(
        private readonly CalendarEventsUtil $calendarEventsUtil,
        private readonly ContaoFramework $framework,
        private readonly ContentUrlGenerator $contentUrlGenerator,
        private readonly EventBlogListFinder $eventBlogListFinder,
        private readonly ScopeMatcher $scopeMatcher,
        private readonly VirtualFilesystem $filesStorage,
        private readonly string $projectDir,
        private readonly string $locale,
    ) {
    }

    public function __invoke(Request $request, ContentModel $model, string $section, array|null $classes = null): Response
    {
        // The element can not be rendered in the backend preview: show its name instead
        if ($this->scopeMatcher->isBackendRequest($request)) {
            return new Response('<p class="tl_gray">'.htmlspecialchars($GLOBALS['TL_LANG']['CTE'][self::TYPE][0] ?? self::TYPE).'</p>');
        }

        $page = $this->getPageModel();

        if ($this->scopeMatcher->isFrontendRequest($request)) {
            // Adapters
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

            $this->blog = $this->framework->getAdapter(CalendarEventsBlogModel::class)->findOneBy($arrColumns, $arrValues);

            if (null === $this->blog) {
                throw new PageNotFoundException('Page not found: '.$request->getUri());
            }

            $this->listElement = $this->getListElementFromHtmxRequest($request);
        }

        return parent::__invoke($request, $model, $section, $classes);
    }

    /**
     * @throws \Exception
     */
    protected function getResponse(FragmentTemplate $template, ContentModel $model, Request $request): Response
    {
        // Set data
        // Keep the element data (class, cssID, ...) and add the blog data
        $template->setData([...$template->getData(), ...$this->blog->row()]);

        // Twig callable
        $template->set('binToUuid', static fn (string $uuid): string => StringUtil::binToUuid($uuid));

        // Fallback if author is no more findable in tl_member
        $objAuthor = $this->framework->getAdapter(MemberModel::class)->findOneBySacMemberId($this->blog->sacMemberId);

        // Respect privacy and do not show the author name, if an author (frontend user) has deleted his account
        $template->set('authorName', null !== $objAuthor ? $objAuthor->firstname.' '.$objAuthor->lastname : 'Unbekannt');

        // !!! $objEvent can be NULL, if the related event no more exists
        $objEvent = $this->framework->getAdapter(CalendarEventsModel::class)->findById($this->blog->eventId);
        $template->set('event', null !== $objEvent ? $objEvent->row() : []);
        $template->set('blog', $this->blog->row());

        $page = $this->getPageModel();

        // QR code and direct link point to the reader page
        if (!$this->isPreviewMode && null !== $page) {
            $url = $this->getReaderUrl($page, (int) $this->blog->id, UrlGeneratorInterface::ABSOLUTE_URL);

            if (null !== ($qrCodePath = $this->getQrCodeFromUrl($url))) {
                $template->set('qrCodePath', $qrCodePath);
                $template->set('directLink', $url);
            }
        }

        // Add the gallery
        $filesystemItems = FilesystemUtil::listContentsFromSerialized($this->filesStorage, $this->blog->multiSRC ?? [])
            ->filter(static fn ($item) => \in_array($item->getExtension(true), ['jpg', 'JPG', 'png', 'PNG'], true))
        ;

        // We do not have to sort the gallery, because we us custom sorting.
        $imageList = [];

        /** @var FilesystemItem $filesystemItem */
        foreach (iterator_to_array($filesystemItems) as $filesystemItem) {
            $file = FilesModel::findByUuid(StringUtil::uuidToBin($filesystemItem->getUuid()));

            if ($file && is_file(Path::makeAbsolute($file->path, $this->projectDir))) {
                $imageList[] = [
                    'uuid' => $filesystemItem->getUuid(),
                    'href' => $file->path,
                    'meta' => $filesystemItem->getExtraMetadata()['metadata']->get($this->locale),
                ];
            }
        }

        $template->set('imageList', $imageList);

        // Add YouTube movie
        $template->set('youTubeId', !empty($this->blog->youTubeId) ? $this->blog->youTubeId : null);

        // tour tech. difficulty
        $template->set('tourTechDifficulty', $this->blog->tourTechDifficulty ?? '');

        if (null !== $objEvent) {
            // tour instructors
            $arrTourInstructors = $this->calendarEventsUtil->getInstructorNamesAsArray($objEvent);

            if (!empty($arrTourInstructors)) {
                $template->set('tourInstructors', implode(', ', $arrTourInstructors));
            }

            // tour types
            $arrTourTypes = $this->calendarEventsUtil->getTourTypesAsArray($objEvent, 'title');

            if (!empty($arrTourTypes)) {
                $template->set('tourTypes', implode(', ', $arrTourTypes));
            }

            // event dates
            $template->set('eventDates', $this->calendarEventsUtil->getEventPeriod($objEvent, 'd.m.Y', false));

            if (empty($template->get('tourTechDifficulty')) && !empty($objEvent->tourTechDifficulty)) {
                $arrTourTechDiff = $this->calendarEventsUtil->getTourTechDifficultiesAsArray($objEvent);
                $template->set('tourTechDifficulty', !empty($arrTourTechDiff) ? implode(', ', $arrTourTechDiff) : null);
            }

            // event organizers
            $arrEventOrganizers = $this->calendarEventsUtil->getEventOrganizersAsArray($objEvent);

            if (!empty($arrEventOrganizers)) {
                $template->set('eventOrganizers', implode(', ', $arrEventOrganizers));
            }
        } else {
            // The related event no longer exists: use the event dates stored in the blog
            $eventDates = [];

            if (!empty($this->blog->eventStartDate)) {
                $eventDates[] = date('d.m.Y', (int) $this->blog->eventStartDate);
            }

            if (!empty($this->blog->eventEndDate) && date('d.m.Y', (int) $this->blog->eventEndDate) !== ($eventDates[0] ?? null)) {
                $eventDates[] = date('d.m.Y', (int) $this->blog->eventEndDate);
            }

            $template->set('eventDates', implode(' - ', $eventDates));
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

        // HTMX request from the modal window of the list element: only send the reader element
        if (null !== $this->listElement) {
            $template->set('eventBlogNav', $this->getNavigation($page));

            $response = $template->getResponse();
            $response->setPrivate();
            $response->headers->addCacheControlDirective('no-store');

            throw new ResponseException($response);
        }

        return $template->getResponse();
    }

    private function getListElementFromHtmxRequest(Request $request): ContentModel|null
    {
        if ('true' !== $request->headers->get('HX-Request')) {
            return null;
        }

        $prefix = EventBlogListController::READER_TARGET_PREFIX;

        if (!preg_match('/^'.preg_quote($prefix, '/').'(\d+)$/', (string) $request->headers->get('HX-Target'), $matches)) {
            return null;
        }

        $listElement = $this->framework->getAdapter(ContentModel::class)->findById((int) $matches[1]);

        if (null === $listElement || EventBlogListController::TYPE !== $listElement->type) {
            return null;
        }

        return $listElement;
    }

    /**
     * Prev/next links of the list element (modal window).
     *
     * @return array{target: string, prev: string|null, next: string|null}
     */
    private function getNavigation(PageModel|null $page): array
    {
        $siblings = $this->eventBlogListFinder->findSiblings($this->listElement, (int) $this->blog->id);

        return [
            'target' => EventBlogListController::READER_TARGET_PREFIX.$this->listElement->id,
            'prev' => null !== $page && null !== $siblings['prev'] ? $this->getReaderUrl($page, $siblings['prev']) : null,
            'next' => null !== $page && null !== $siblings['next'] ? $this->getReaderUrl($page, $siblings['next']) : null,
        ];
    }

    private function getReaderUrl(PageModel $page, int $blogId, int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH): string
    {
        return $this->contentUrlGenerator->generate($page, ['parameters' => '/'.$blogId], $referenceType);
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
        $filepath = \sprintf($objFolder->path.'/eventBlogQRcode_%s.png', md5($url));

        // Defaults
        $opt = [
            'version' => 8,
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
