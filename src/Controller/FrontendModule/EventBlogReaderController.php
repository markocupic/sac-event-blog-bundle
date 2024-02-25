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
use Contao\Config;
use Contao\CoreBundle\Controller\FrontendModule\AbstractFrontendModuleController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsFrontendModule;
use Contao\CoreBundle\Exception\PageNotFoundException;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ScopeMatcher;
use Contao\CoreBundle\Util\SymlinkUtil;
use Contao\Environment;
use Contao\File;
use Contao\FilesModel;
use Contao\Folder;
use Contao\Input;
use Contao\MemberModel;
use Contao\ModuleModel;
use Contao\PageModel;
use Contao\StringUtil;
use Contao\Template;
use Contao\Validator;
use Markocupic\SacEventBlogBundle\Config\PublishState;
use Markocupic\SacEventBlogBundle\Model\CalendarEventsBlogModel;
use Markocupic\SacEventToolBundle\CalendarEventsHelper;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

#[AsFrontendModule(EventBlogReaderController::TYPE, category:'sac_event_tool_frontend_modules', template:'mod_event_blog_reader')]
class EventBlogReaderController extends AbstractFrontendModuleController
{
    public const TYPE = 'event_blog_reader';

    private CalendarEventsBlogModel|null $blog = null;
    private bool $isPreviewMode = false;

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly ScopeMatcher $scopeMatcher,
        private readonly RequestStack $requestStack,
        private readonly UrlParser $urlParser,
        private readonly string $projectDir,
        private readonly string $locale,
    ) {
    }

    public function __invoke(Request $request, ModuleModel $model, string $section, array $classes = null, PageModel $page = null): Response
    {
        if ($this->scopeMatcher->isFrontendRequest($request)) {
            // Adapters
            $calendarEventsBlogModelAdapter = $this->framework->getAdapter(CalendarEventsBlogModel::class);
            $configAdapter = $this->framework->getAdapter(Config::class);
            $environmentAdapter = $this->framework->getAdapter(Environment::class);
            $inputAdapter = $this->framework->getAdapter(Input::class);

            // Set the item from the auto_item parameter
            if (!isset($_GET['items']) && $configAdapter->get('useAutoItem') && isset($_GET['auto_item'])) {
                $inputAdapter->setGet('items', $inputAdapter->get('auto_item'));
            }

            // Do not index or cache the page if no event has been specified
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
    protected function getResponse(Template $template, ModuleModel $model, Request $request): Response
    {
        // Adapters
        $memberModelModelAdapter = $this->framework->getAdapter(MemberModel::class);
        $stringUtilAdapter = $this->framework->getAdapter(StringUtil::class);
        $validatorAdapter = $this->framework->getAdapter(Validator::class);
        $filesModelAdapter = $this->framework->getAdapter(FilesModel::class);
        $calendarEventsModelAdapter = $this->framework->getAdapter(CalendarEventsModel::class);
        $calendarEventsHelperAdapter = $this->framework->getAdapter(CalendarEventsHelper::class);

        // Set data
        $template->setData($this->blog->row());

        $template->class = $template->class ?? '';
        $template->cssID = $template->cssID ?? '';

        // Set title as headline
        $template->headline = $this->blog->title;

        // Twig callable
        $template->binToUuid = static fn (string $uuid): string => StringUtil::binToUuid($uuid);

        // Fallback if author is no more findable in tl_member
        $objAuthor = $memberModelModelAdapter->findOneBySacMemberId($this->blog->sacMemberId);
        $template->authorName = null !== $objAuthor ? $objAuthor->firstname.' '.$objAuthor->lastname : $this->blog->authorName;

        // !!! $objEvent can be NULL, if the related event no more exists
        $objEvent = $calendarEventsModelAdapter->findByPk($this->blog->eventId);
        $template->event = $objEvent->row();
        $template->blog = $this->blog->row();

        // Add qr code, if it is not preview mode
        $request = $this->requestStack->getCurrentRequest();

        if (!$this->isPreviewMode) {
            if ($request->query->has('referer')) {
                $url = base64_decode($request->query->get('referer', ''), true);
                $url = $this->urlParser->addQueryString('show_event_blog='.$this->blog->id, $url);
            } else {
                $url = $this->urlParser->addQueryString('show_event_blog='.$this->blog->id);
            }

            // Remove facebook "fbclid" param
            $url = $this->urlParser->removeQueryString(['fbclid'], $url);

            if ('' !== $url) {
                if (null !== ($qrCodePath = $this->getQrCodeFromUrl($url))) {
                    $template->qrCodePath = $qrCodePath;
                    $template->directLink = $url;
                }
            }
        }

        // Add gallery
        $images = [];
        $arrMultiSRC = $stringUtilAdapter->deserialize($this->blog->multiSRC, true);

        foreach ($arrMultiSRC as $uuid) {
            if ($validatorAdapter->isUuid($uuid)) {
                $objFiles = $filesModelAdapter->findByUuid($uuid);

                if (null !== $objFiles) {
                    if (is_file($this->projectDir.'/'.$objFiles->path)) {
                        $objFile = new File($objFiles->path);

                        if ($objFile->isImage) {
                            $arrMeta = $stringUtilAdapter->deserialize($objFiles->meta, true);
                            $title = '';
                            $alt = '';
                            $caption = '';
                            $photographer = '';

                            if (isset($arrMeta[$this->locale])) {
                                $title = $arrMeta[$this->locale]['title'];
                                $alt = $arrMeta[$this->locale]['alt'];
                                $caption = $arrMeta[$this->locale]['caption'];
                                $photographer = $arrMeta[$this->locale]['photographer'];
                            }

                            $arrFigureCaption = [];

                            if ('' !== $caption) {
                                $arrFigureCaption[] = $caption;
                            }

                            if ('' !== $photographer) {
                                $arrFigureCaption[] = '(Foto: '.$photographer.')';
                            }
                            $strFigureCaption = implode(', ', $arrFigureCaption);

                            $linkTitle = '';
                            $linkTitle .= '' !== $caption ? $caption : '';
                            $linkTitle .= '' !== $photographer ? ' (Foto: '.$photographer.')' : '';

                            $images[$objFiles->path] = [
                                'id' => $objFiles->id,
                                'path' => $objFiles->path,
                                'uuid' => $objFiles->uuid,
                                'name' => $objFile->basename,
                                'singleSRC' => $objFiles->path,
                                'filesModel' => $objFiles->current(),
                                'caption' => $stringUtilAdapter->specialchars($caption),
                                'alt' => $stringUtilAdapter->specialchars($alt),
                                'title' => $stringUtilAdapter->specialchars($title),
                                'photographer' => $stringUtilAdapter->specialchars($photographer),
                                'strFigureCaption' => $stringUtilAdapter->specialchars($strFigureCaption),
                                'linkTitle' => $stringUtilAdapter->specialchars($linkTitle),
                            ];
                        }
                    }
                }
            }
        }

        // Custom image sorting
        if ('' !== $this->blog->orderSRC) {
            $tmp = $stringUtilAdapter->deserialize($this->blog->orderSRC);

            if (!empty($tmp) && \is_array($tmp)) {
                // Remove all values
                $arrOrder = array_map(
                    static function (): void {
                    },
                    array_flip($tmp)
                );

                // Move the matching elements to their position in $arrOrder
                foreach ($images as $k => $v) {
                    if (\array_key_exists($v['uuid'], $arrOrder)) {
                        $arrOrder[$v['uuid']] = $v;
                        unset($images[$k]);
                    }
                }

                // Append the left-over images at the end
                if (!empty($images)) {
                    $arrOrder = array_merge($arrOrder, array_values($images));
                }

                // Remove empty (not replaced) entries
                $images = array_values(array_filter($arrOrder));
                unset($arrOrder);
            }
        }
        $images = array_values($images);

        $template->images = \count($images) ? $images : null;

        // Add YouTube movie
        $template->youTubeId = '' !== $this->blog->youTubeId ? $this->blog->youTubeId : null;

        // tour guides
        $template->tourInstructors = null;
        $arrTourInstructors = $calendarEventsHelperAdapter->getInstructorNamesAsArray($objEvent);

        if (!empty($arrTourInstructors)) {
            $template->tourInstructors = implode(', ', $arrTourInstructors);
        }

        // tour types
        $arrTourTypes = CalendarEventsHelper::getTourTypesAsArray($objEvent, 'title');

        if (!empty($arrTourTypes)) {
            $template->tourTypes = implode(', ', $arrTourTypes);
        }

        // event dates
        $template->eventDates = CalendarEventsHelper::getEventPeriod($objEvent, 'd.m.Y', false);

        // tour tech. difficulty
        $template->tourTechDifficulty = $this->blog->tourTechDifficulty ?? null;

        if (empty($template->tourTechDifficulty) && !empty($objEvent->tourTechDifficulty)) {
            $arrTourTechDiff = $calendarEventsHelperAdapter->getTourTechDifficultiesAsArray($objEvent);
            $template->tourTechDifficulty = !empty($arrTourTechDiff) ? implode(', ', $arrTourTechDiff) : null;
        }

        // event organizers
        $template->eventOrganizers = null;
        $arrEventOrganizers = $calendarEventsHelperAdapter->getEventOrganizersAsArray($objEvent);

        if (!empty($arrEventOrganizers)) {
            $template->eventOrganizers = implode(', ', $arrEventOrganizers);
        }

        $template->tourProfile = null;
        $template->tourWaypoints = null;
        $template->tourHighlights = null;

        if ('' !== $this->blog->tourWaypoints) {
            $template->tourWaypoints = nl2br((string) $this->blog->tourWaypoints);
        }

        if ('' !== $this->blog->tourProfile) {
            $template->tourProfile = nl2br((string) $this->blog->tourProfile);
        }

        if ('' !== $this->blog->tourHighlights) {
            $template->tourHighlights = nl2br((string) $this->blog->tourHighlights);
        }

        if ('' !== $this->blog->tourPublicTransportInfo) {
            $template->tourPublicTransportInfo = nl2br((string) $this->blog->tourPublicTransportInfo);
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
