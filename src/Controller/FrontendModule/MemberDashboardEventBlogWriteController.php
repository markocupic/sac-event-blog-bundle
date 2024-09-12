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

use Codefog\HasteBundle\Form\Form;
use Codefog\HasteBundle\UrlParser;
use Contao\CalendarEventsModel;
use Contao\Config;
use Contao\Controller;
use Contao\CoreBundle\Controller\FrontendModule\AbstractFrontendModuleController;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\DependencyInjection\Attribute\AsFrontendModule;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\CoreBundle\Routing\ScopeMatcher;
use Contao\Dbafs;
use Contao\File;
use Contao\FilesModel;
use Contao\FrontendUser;
use Contao\Input;
use Contao\Message;
use Contao\ModuleModel;
use Contao\PageModel;
use Contao\StringUtil;
use Contao\System;
use Contao\Template;
use Contao\Validator;
use Doctrine\DBAL\Connection;
use Markocupic\SacEventBlogBundle\Config\PublishState;
use Markocupic\SacEventBlogBundle\Model\CalendarEventsBlogModel;
use Markocupic\SacEventToolBundle\CalendarEventsHelper;
use Markocupic\SacEventToolBundle\Config\EventExecutionState;
use Markocupic\SacEventToolBundle\Model\CalendarEventsMemberModel;
use Psr\Log\LogLevel;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsFrontendModule(MemberDashboardEventBlogWriteController::TYPE, category: 'sac_event_tool_frontend_modules', template: 'mod_member_dashboard_write_event_blog')]
class MemberDashboardEventBlogWriteController extends AbstractFrontendModuleController
{
    public const TYPE = 'member_dashboard_write_event_blog';

    private FrontendUser|null $user;
    private PageModel|null $page;

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
        private readonly ScopeMatcher $scopeMatcher,
        private readonly RequestStack $requestStack,
        private readonly TranslatorInterface $translator,
        private readonly UrlParser $urlParser,
        private readonly Security $security,
        private readonly ContaoCsrfTokenManager $contaoCsrfTokenManager,
        private readonly string $projectDir,
        private readonly string $eventBlogAssetDir,
        private readonly string $locale,
    ) {
        // Get logged in member object
        if (($user = $this->security->getUser()) instanceof FrontendUser) {
            $this->user = $user;
        }
    }

    public function __invoke(Request $request, ModuleModel $model, string $section, array $classes = null, PageModel $page = null): Response
    {
        if ($this->scopeMatcher->isFrontendRequest($request)) {
            if (null !== $page) {
                // Neither cache nor search page
                $page->noSearch = 1;
                $page->cache = 0;
                $page->clientCache = 0;

                // Set the page object
                $this->page = $page;
            }
        }

        return parent::__invoke($request, $model, $section, $classes);
    }

    /**
     * @throws \Exception
     */
    protected function getResponse(Template $template, ModuleModel $model, Request $request): Response
    {
        // Do not allow for not authorized users
        if (null === $this->user) {
            throw new UnauthorizedHttpException('Not authorized. Please log in as frontend user.');
        }

        // Set adapters
        $messageAdapter = $this->framework->getAdapter(Message::class);
        $validatorAdapter = $this->framework->getAdapter(Validator::class);
        $calendarEventsModelAdapter = $this->framework->getAdapter(CalendarEventsModel::class);
        $calendarEventsMemberModelAdapter = $this->framework->getAdapter(CalendarEventsMemberModel::class);
        $calendarEventsBlogModelAdapter = $this->framework->getAdapter(CalendarEventsBlogModel::class);
        $calendarEventsHelperAdapter = $this->framework->getAdapter(CalendarEventsHelper::class);
        $controllerAdapter = $this->framework->getAdapter(Controller::class);
        $inputAdapter = $this->framework->getAdapter(Input::class);
        $stringUtilAdapter = $this->framework->getAdapter(StringUtil::class);

        // Load language file
        $controllerAdapter->loadLanguageFile('tl_calendar_events_blog');

        // Handle messages
        if (empty($this->user->email) || !$validatorAdapter->isEmail($this->user->email)) {
            $messageAdapter->addInfo($this->translator->trans('ERR.md_write_event_blog_emailAddressNotFound', [], 'contao_default'));
        }

        $objEvent = $calendarEventsModelAdapter->findByPk($inputAdapter->get('eventId'));

        if (null === $objEvent) {
            $messageAdapter->addError($this->translator->trans('ERR.md_write_event_blog_eventNotFound', [$inputAdapter->get('eventId')], 'contao_default'));
        }

        if (!$messageAdapter->hasError()) {
            // Check if report already exists
            $objReportModel = CalendarEventsBlogModel::findOneBySacMemberIdAndEventId($this->user->sacMemberId, $objEvent->id);

            if (null === $objReportModel) {
                if ($objEvent->endDate + $model->eventBlogTimeSpanForCreatingNew * 24 * 60 * 60 < time()) {
                    // Do not allow blogging for old events
                    $messageAdapter->addError($this->translator->trans('ERR.md_write_event_blog_createBlogDeadlineExpired', [], 'contao_default'));
                }

                if (!$messageAdapter->hasError()) {
                    $blnAllow = false;
                    $intStartDateMin = $model->eventBlogTimeSpanForCreatingNew > 0 ? time() - $model->eventBlogTimeSpanForCreatingNew * 24 * 3600 : time();
                    $arrAllowedEvents = $calendarEventsMemberModelAdapter->findEventsByMemberId($this->user->id, [], $intStartDateMin, time(), true);

                    foreach ($arrAllowedEvents as $allowedEvent) {
                        if ((int) $allowedEvent['id'] === (int) $inputAdapter->get('eventId')) {
                            $blnAllow = true;
                        }
                    }

                    // User has not participated on the event neither as guide nor as participant and is not allowed to write a report
                    if (!$blnAllow) {
                        $messageAdapter->addError($this->translator->trans('ERR.md_write_event_blog_writingPermissionDenied', [], 'contao_default'));
                    }
                }
            }

            if (!$messageAdapter->hasError()) {
                if (null === $objReportModel) {
                    // Create new
                    $aDates = [];
                    $arrDates = $stringUtilAdapter->deserialize($objEvent->eventDates, true);

                    foreach ($arrDates as $arrDate) {
                        $aDates[] = $arrDate['new_repeat'];
                    }

                    $set = [
                        'title' => $objEvent->title,
                        'eventTitle' => $objEvent->title,
                        'eventSubstitutionText' => EventExecutionState::STATE_NOT_EXECUTED_LIKE_PREDICTED === $objEvent->executionState && '' !== $objEvent->eventSubstitutionText ? $stringUtilAdapter->substr($objEvent->eventSubstitutionText, 250) : '',
                        'eventStartDate' => $objEvent->startDate,
                        'eventEndDate' => $objEvent->endDate,
                        'organizers' => $objEvent->organizers,
                        'eventDates' => serialize($aDates),
                        'authorName' => $this->user->firstname.' '.$this->user->lastname,
                        'sacMemberId' => $this->user->sacMemberId,
                        'eventId' => $inputAdapter->get('eventId'),
                        'tstamp' => time(),
                        'dateAdded' => time(),
                    ];

                    $affected = $this->connection->insert('tl_calendar_events_blog', $set);

                    // Set security token for frontend preview
                    if ($affected) {
                        $insertId = $this->connection->lastInsertId();
                        $set = [
                            'securityToken' => md5((string) random_int(100000000, 999999999)).$insertId,
                        ];

                        $this->connection->update('tl_calendar_events_blog', $set, ['id' => $insertId]);

                        $objReportModel = $calendarEventsBlogModelAdapter->findByPk($insertId);
                    }
                }

                if (!isset($objReportModel)) {
                    throw new \Exception('Event report model not found.');
                }

                $template->request_token = $this->contaoCsrfTokenManager->getDefaultTokenValue();
                $template->event = $objEvent->row;
                $template->eventId = $objEvent->id;
                $template->eventName = $objEvent->title;
                $template->executionState = $objEvent->executionState;
                $template->eventSubstitutionText = $objEvent->eventSubstitutionText;
                $template->youTubeId = $objReportModel->youTubeId;
                $template->text = $objReportModel->text;
                $template->title = $objReportModel->title;
                $template->publishState = (int) $objReportModel->publishState;
                $template->eventPeriod = $calendarEventsHelperAdapter->getEventPeriod($objEvent);

                // Get the gallery
                $template->images = $this->getGalleryImages($objReportModel);

                if ('' !== $objReportModel->tourWaypoints) {
                    $template->tourWaypoints = nl2br((string) $objReportModel->tourWaypoints);
                }

                if ('' !== $objReportModel->tourProfile) {
                    $template->tourProfile = nl2br((string) $objReportModel->tourProfile);
                }

                if ('' !== $objReportModel->tourTechDifficulty) {
                    $template->tourTechDifficulty = nl2br((string) $objReportModel->tourTechDifficulty);
                }

                if ('' !== $objReportModel->tourHighlights) {
                    $template->tourHighlights = nl2br((string) $objReportModel->tourHighlights);
                }

                if ('' !== $objReportModel->tourPublicTransportInfo) {
                    $template->tourPublicTransportInfo = nl2br((string) $objReportModel->tourPublicTransportInfo);
                }

                // Generate forms
                $template->objEventBlogTextAndYoutubeForm = $this->generateTextAndYoutubeForm($objReportModel);
                $template->objEventBlogImageUploadForm = $this->generatePictureUploadForm($objReportModel, $model);

                // Image dimension and max upload file size restrictions
                $template->maxImageWidth = $model->eventBlogMaxImageWidth;
                $template->maxImageHeight = $model->eventBlogMaxImageHeight;
                $template->maxImageFileSize = $model->eventBlogMaxImageFileSize;

                // Get the preview link
                $template->previewLink = $this->getPreviewLink($objReportModel, $model);

                // Twig callable
                $template->binToUuid = static fn (string $uuid): string => StringUtil::binToUuid($uuid);
            }
        }

        // Check if all images are labeled with a legend and a photographer name
        if (isset($objReportModel) && PublishState::STILL_IN_PROGRESS === (int) $objReportModel->publishState) {
            if (!$this->validateImageUploads($objReportModel)) {
                $messageAdapter->addInfo($this->translator->trans('ERR.md_write_event_blog_missingImageLegend', [], 'contao_default'));
            }
        }

        // Add messages to template
        $this->addMessagesToTemplate($template);

        return $template->getResponse();
    }

    /**
     * @throws \Exception
     */
    private function getGalleryImages(CalendarEventsBlogModel $objBlog): array
    {
        /** @var Validator $validatorAdapter */
        $validatorAdapter = $this->framework->getAdapter(Validator::class);

        /** @var StringUtil $stringUtilAdapter */
        $stringUtilAdapter = $this->framework->getAdapter(StringUtil::class);

        /** @var FilesModel $filesModelAdapter */
        $filesModelAdapter = $this->framework->getAdapter(FilesModel::class);

        $images = [];
        $arrMultiSRC = $stringUtilAdapter->deserialize($objBlog->multiSRC, true);

        foreach ($arrMultiSRC as $uuid) {
            if ($validatorAdapter->isUuid($uuid)) {
                $objFiles = $filesModelAdapter->findByUuid($uuid);

                if (null !== $objFiles) {
                    if (is_file($this->projectDir.'/'.$objFiles->path)) {
                        $objFile = new File($objFiles->path);

                        if ($objFile->isImage) {
                            $arrMeta = $stringUtilAdapter->deserialize($objFiles->meta, true);
                            $images[] = [
                                'id' => $objFiles->id,
                                'path' => $objFiles->path,
                                'uuid' => $objFiles->uuid,
                                'name' => $objFile->basename,
                                'singleSRC' => $objFiles->path,
                                'title' => $stringUtilAdapter->specialchars($objFile->basename),
                                'filesModel' => $objFiles->current(),
                                'caption' => $arrMeta[$this->locale]['caption'] ?? '',
                                'photographer' => $arrMeta[$this->locale]['photographer'] ?? '',
                                'alt' => $arrMeta[$this->locale]['alt'] ?? '',
                            ];
                        }
                    }
                }
            }
        }

        return array_values($images);
    }

    private function generateTextAndYoutubeForm(CalendarEventsBlogModel $objEventBlogModel): string
    {
        /** @var Controller $controllerAdapter */
        $controllerAdapter = $this->framework->getAdapter(Controller::class);

        /** @var Input $inputAdapter */
        $inputAdapter = $this->framework->getAdapter(Input::class);

        $objForm = new Form(
            'form-event-blog-text-and-youTube',
            'POST',
        );

        $uri = $this->requestStack->getCurrentRequest()->getUri();
        $objForm->setAction($uri);

        // Title
        $objForm->addFormField('title', [
            'label' => $this->translator->trans('FORM.md_write_event_blog_title', [], 'contao_default'),
            'inputType' => 'text',
            'eval' => ['mandatory' => true, 'maxlength' => '250', 'decodeEntities' => true],
            'value' => $this->getTourTitle($objEventBlogModel),
        ]);

        // text
        $maxlength = 1700;
        $objForm->addFormField('text', [
            'label' => $this->translator->trans('FORM.md_write_event_blog_text', [$maxlength], 'contao_default'),
            'inputType' => 'textarea',
            'eval' => ['mandatory' => true, 'maxlength' => $maxlength, 'rows' => 8, 'decodeEntities' => true],
            'value' => (string) $objEventBlogModel->text,
        ]);

        // tour waypoints
        $eval = ['mandatory' => true, 'maxlength' => 300, 'rows' => 2, 'decodeEntities' => true, 'placeholder' => 'z.B. Engelberg 1000m - Herrenrüti 1083 m - Galtiberg 1800 m - Einstieg 2000 m'];

        $objForm->addFormField(
            'tourWaypoints',
            [
                'label' => $this->translator->trans('FORM.md_write_event_blog_tourWaypoints', [], 'contao_default'),
                'inputType' => 'textarea',
                'eval' => $eval,
                'value' => $this->getTourWaypoints($objEventBlogModel),
            ]
        );

        // tour profile
        $eval = ['mandatory' => true, 'rows' => 2, 'decodeEntities' => true, 'placeholder' => 'z.B. Aufst: 1500 Hm/8 h, Abst: 1500 Hm/3 h'];

        $objForm->addFormField(
            'tourProfile',
            [
                'label' => $this->translator->trans('FORM.md_write_event_blog_tourProfile', [], 'contao_default'),
                'inputType' => 'textarea',
                'eval' => $eval,
                'value' => $this->getTourProfile($objEventBlogModel),
            ]
        );

        // tour difficulties
        $eval = ['mandatory' => true, 'rows' => 2, 'decodeEntities' => true];

        $objForm->addFormField('tourTechDifficulty', [
            'label' => $this->translator->trans('FORM.md_write_event_blog_tourTechDifficulty', [], 'contao_default'),
            'inputType' => 'textarea',
            'eval' => $eval,
            'value' => $this->getTourTechDifficulties($objEventBlogModel),
        ]);

        // tour highlights (not mandatory)
        $eval = ['mandatory' => true, 'class' => 'publish-clubmagazine-field', 'rows' => 2, 'decodeEntities' => true];

        $objForm->addFormField('tourHighlights', [
            'label' => $this->translator->trans('FORM.md_write_event_blog_tourHighlights', [], 'contao_default'),
            'inputType' => 'textarea',
            'eval' => $eval,
            'value' => (string) $objEventBlogModel->tourHighlights,
        ]);

        // tour public transport info
        $eval = ['mandatory' => false, 'class' => 'publish-clubmagazine-field', 'rows' => 2, 'decodeEntities' => true];

        $objForm->addFormField('tourPublicTransportInfo', [
            'label' => $this->translator->trans('FORM.md_write_event_blog_tourPublicTransportInfo', [], 'contao_default'),
            'inputType' => 'textarea',
            'eval' => $eval,
            'value' => (string) $objEventBlogModel->tourPublicTransportInfo,
        ]);

        // youTube id
        $objForm->addFormField(
            'youTubeId',
            [
                'label' => $this->translator->trans('FORM.md_write_event_blog_youTubeId', [], 'contao_default'),
                'inputType' => 'text',
                'eval' => ['maxlength' => '11', 'placeholder' => 'z.B. G02hYgT3nGw'],
                'value' => (string) $objEventBlogModel->youTubeId,
            ]
        );

        // Let's add  a submit button
        $objForm->addFormField('submitEventReportTextFormBtn', [
            'label' => $this->translator->trans('FORM.md_write_event_blog_submit', [], 'contao_default'),
            'inputType' => 'submit',
        ]);

        // Bind model
        $objForm->setBoundModel($objEventBlogModel);

        // validate() also checks whether the form has been submitted
        if ($objForm->validate() && $inputAdapter->post('FORM_SUBMIT') === $objForm->getFormId()) {
            $objEventBlogModel->dateAdded = time();
            $objEventBlogModel->title = (string) $objForm->getWidget('title')->value;
            $objEventBlogModel->text = (string) $objForm->getWidget('text')->value;
            $objEventBlogModel->youTubeId = $objForm->getWidget('youTubeId')->value;
            $objEventBlogModel->tourWaypoints = (string) $objForm->getWidget('tourWaypoints')->value;
            $objEventBlogModel->tourProfile = (string) $objForm->getWidget('tourProfile')->value;
            $objEventBlogModel->tourTechDifficulty = (string) $objForm->getWidget('tourTechDifficulty')->value;
            $objEventBlogModel->tourHighlights = (string) $objForm->getWidget('tourHighlights')->value;
            $objEventBlogModel->tourPublicTransportInfo = (string) $objForm->getWidget('tourPublicTransportInfo')->value;

            $objEventBlogModel->save();

            $hasErrors = false;

            // Check mandatory fields
            if ('' === $objForm->getWidget('text')->value) {
                $objForm->getWidget('text')->addError($this->translator->trans('ERR.md_write_event_blog_writeSomethingAboutTheEvent', [], 'contao_default'));
                $hasErrors = true;
            }

            // Reload page
            if (!$hasErrors) {
                $controllerAdapter->reload();
            }
        }

        // Add some Vue.js attributes to the form widgets
        $this->addVueAttributesToFormWidget($objForm);

        return $objForm->generate();
    }

    private function getTourTitle(CalendarEventsBlogModel $objEventBlogModel): string
    {
        $calendarEventsModelAdapter = $this->framework->getAdapter(CalendarEventsModel::class);

        if (!empty($objEventBlogModel->title)) {
            return $objEventBlogModel->title;
        }

        $objEvent = $calendarEventsModelAdapter->findByPk($objEventBlogModel->eventId);

        if (null !== $objEvent) {
            return '' !== $objEvent->title ? $objEvent->title : '';
        }

        return '';
    }

    private function getTourWaypoints(CalendarEventsBlogModel $objEventBlogModel): string
    {
        $calendarEventsModelAdapter = $this->framework->getAdapter(CalendarEventsModel::class);

        if (!empty($objEventBlogModel->tourWaypoints)) {
            return $objEventBlogModel->tourWaypoints;
        }
        $objEvent = $calendarEventsModelAdapter->findByPk($objEventBlogModel->eventId);

        if (null !== $objEvent) {
            return !empty($objEvent->tourDetailText) ? $objEvent->tourDetailText : '';
        }

        return '';
    }

    private function getTourProfile(CalendarEventsBlogModel $objEventBlogModel): string
    {
        $calendarEventsHelperAdapter = $this->framework->getAdapter(CalendarEventsHelper::class);
        $calendarEventsModelAdapter = $this->framework->getAdapter(CalendarEventsModel::class);

        if (!empty($objEventBlogModel->tourProfile)) {
            return $objEventBlogModel->tourProfile;
        }

        $objEvent = $calendarEventsModelAdapter->findByPk($objEventBlogModel->eventId);

        if (null !== $objEvent) {
            $arrData = $calendarEventsHelperAdapter->getTourProfileAsArray($objEvent);

            return implode("\r\n", $arrData);
        }

        return '';
    }

    private function getTourTechDifficulties(CalendarEventsBlogModel $objEventBlogModel): string
    {
        $calendarEventsHelperAdapter = $this->framework->getAdapter(CalendarEventsHelper::class);
        $calendarEventsModelAdapter = $this->framework->getAdapter(CalendarEventsModel::class);

        if (!empty($objEventBlogModel->tourTechDifficulty)) {
            return $objEventBlogModel->tourTechDifficulty;
        }

        $objEvent = $calendarEventsModelAdapter->findByPk($objEventBlogModel->eventId);

        if (null !== $objEvent) {
            $arrData = $calendarEventsHelperAdapter->getTourTechDifficultiesAsArray($objEvent);

            if (empty($arrData)) {
                return $this->translator->trans('ERR.md_write_event_blog_notSpecified', [], 'contao_default');
            }

            return implode("\r\n", $arrData);
        }

        return '';
    }

    private function addVueAttributesToFormWidget(Form $objForm): void
    {
        $objForm->getWidget('text')->addAttribute('v-model', 'ctrl_text.value');
        $objForm->getWidget('text')->addAttribute('v-on:keyup', 'onKeyUp("ctrl_text")');
    }

    /**
     * @throws \Exception
     */
    private function generatePictureUploadForm(CalendarEventsBlogModel $objEventBlogModel, ModuleModel $moduleModel): string
    {
        /** @var Controller $controllerAdapter */
        $controllerAdapter = $this->framework->getAdapter(Controller::class);

        /** @var Input $inputAdapter */
        $inputAdapter = $this->framework->getAdapter(Input::class);

        /** @var Message $messageAdapter */
        $messageAdapter = $this->framework->getAdapter(Message::class);

        /** @var StringUtil $stringUtilAdapter */
        $stringUtilAdapter = $this->framework->getAdapter(StringUtil::class);

        /** @var FilesModel $filesModelAdapter */
        $filesModelAdapter = $this->framework->getAdapter(FilesModel::class);

        /** @var Dbafs $dbafsAdapter */
        $dbafsAdapter = $this->framework->getAdapter(Dbafs::class);

        /** @var Config $configAdapter */
        $configAdapter = $this->framework->getAdapter(Config::class);

        // Set max image width and height
        if ((int) $moduleModel->eventBlogMaxImageWidth > 0) {
            $configAdapter->set('imageWidth', (int) $moduleModel->eventBlogMaxImageWidth);
        }

        if ((int) $moduleModel->eventBlogMaxImageHeight > 0) {
            $configAdapter->set('imageHeight', (int) $moduleModel->eventBlogMaxImageHeight);
        }

        $fs = new Filesystem();

        $tmpUploadDir = sprintf('%s/system/tmp/event_blog/%s', $this->projectDir, $objEventBlogModel->id);

        if (!is_dir($tmpUploadDir)) {
            $fs->mkdir($tmpUploadDir);
        }

        $destDir = $this->projectDir.'/'.$this->eventBlogAssetDir.'/'.$objEventBlogModel->id;

        if (!is_dir($destDir)) {
            $fs->mkdir($destDir);
        }

        $dbafsAdapter->addResource(Path::makeRelative($destDir, $this->projectDir));

        if (!is_dir($destDir)) {
            throw new \Exception($this->translator->trans('ERR.md_write_event_blog_uploadDirNotFound', [], 'contao_default'));
        }

        $objForm = new Form(
            'form-event-blog-picture-upload',
            'POST',
        );

        $uri = $this->requestStack->getCurrentRequest()->getUri();
        $objForm->setAction($uri);

        // Add some fields
        $objForm->addFormField('fileUpload', [
            'label' => $this->translator->trans('FORM.md_write_event_blog_imageUpload', [], 'contao_default'),
            'inputType' => 'fineUploader',
            'eval' => [
                'maxWidth' => max([$objEventBlogModel->eventBlogMaxImageWidth, $objEventBlogModel->eventBlogMaxImageHeight]),
                'maxHeight' => max([$objEventBlogModel->eventBlogMaxImageWidth, $objEventBlogModel->eventBlogMaxImageHeight]),
                'maxlength' => $objEventBlogModel->eventBlogMaxImageFileSize,
                'extensions' => 'jpg,jpeg',
                'storeFile' => true,
                'addToDbafs' => false,
                'isGallery' => false,
                'directUpload' => true,
                'multiple' => true,
                'useHomeDir' => false,
                'uploadFolder' => Path::makeRelative($tmpUploadDir, $this->projectDir),
                'mandatory' => true,
            ],
        ]);

        // Let's add  a submit button
        $objForm->addFormField('submitImageUploadFormBtn', [
            'label' => $this->translator->trans('FORM.md_write_event_blog_startImageUpload', [], 'contao_default'),
            'inputType' => 'submit',
        ]);

        // Add attributes
        $objWidgetFileUpload = $objForm->getWidget('fileUpload');
        $objWidgetFileUpload->addAttribute('accept', '.jpg, .jpeg');
        $objWidgetFileUpload->storeFile = true;

        // validate() also checks whether the form has been submitted
        if ($objForm->validate() && $inputAdapter->post('FORM_SUBMIT') === $objForm->getFormId()) {
            if ($inputAdapter->post('fileUpload')) {
                // Make usage of Input::postRaw() because we don't want brackets to be encoded -> &#040;
                $arrFiles = explode(',', $inputAdapter->postRaw('fileUpload'));

                if (!empty($arrFiles)) {
                    foreach ($arrFiles as $path) {
                        if (!is_file($this->projectDir.'/'.$path)) {
                            $msg = $this->translator->trans('FORM.md_write_event_blog_uploadFileError', ['%s' => basename($path)], 'contao_default');
                            $messageAdapter->addInfo($msg);
                            $objWidgetFileUpload->addError($msg);

                            continue;
                        }

                        $objFile = new File($path);

                        if (!$objFile->isImage) {
                            $msg = $this->translator->trans('FORM.md_write_event_blog_uploadedFileNotImage', ['%s' => basename($path)], 'contao_default');
                            $messageAdapter->addInfo($msg);
                            $objWidgetFileUpload->addError($msg);

                            continue;
                        }

                        $newID = $this->connection->fetchOne('SELECT MAX(id) AS maxId FROM tl_files') + 1;

                        $newPath = sprintf(
                            '%s/event-blog-%s-img-%s.%s',
                            $destDir,
                            $objEventBlogModel->id,
                            $newID,
                            strtolower($objFile->extension),
                        );

                        // Copy image from system/tmp to the destination directory
                        $fs->copy($this->projectDir.'/'.$path, $newPath);

                        // Add image to DBAFS
                        $dbafsAdapter->addResource(Path::makeRelative($newPath, $this->projectDir));

                        $objFilesModel = $filesModelAdapter->findByPath(Path::makeRelative($newPath, $this->projectDir));

                        if (null !== $objFilesModel) {
                            $dbafsAdapter->updateFolderHashes(Path::makeRelative($destDir, $this->projectDir));

                            // Add photographer name to meta field
                            if (null !== $this->user) {
                                $arrMeta = $stringUtilAdapter->deserialize($objFilesModel->meta, true);

                                if (!isset($arrMeta[$this->page->language])) {
                                    $arrMeta[$this->page->language] = [
                                        'title' => '',
                                        'alt' => '',
                                        'link' => '',
                                        'caption' => '',
                                        'photographer' => '',
                                    ];
                                }

                                $arrMeta[$this->page->language]['photographer'] = $this->user->firstname.' '.$this->user->lastname;
                                $objFilesModel->meta = serialize($arrMeta);
                                $objFilesModel->tstamp = time();

                                $objFilesModel->save();
                            }

                            // Save gallery data to tl_calendar_events_blog
                            $multiSRC = $stringUtilAdapter->deserialize($objEventBlogModel->multiSRC, true);
                            $multiSRC[] = $objFilesModel->uuid;
                            $objEventBlogModel->multiSRC = serialize($multiSRC);
                            $objEventBlogModel->save();

                            // Log
                            $strText = sprintf('User with username %s has uploaded a new picture ("%s").', $this->user->username, $objFilesModel->path);
                            $logger = System::getContainer()->get('monolog.logger.contao');
                            $logger->log(LogLevel::INFO, $strText, ['contao' => new ContaoContext(__METHOD__, 'EVENT STORY PICTURE UPLOAD')]);
                        }
                    }
                }
            }

            if (!$objWidgetFileUpload->hasErrors()) {
                $controllerAdapter->reload();
            }
        }

        return $objForm->generate();
    }

    private function getPreviewLink(CalendarEventsBlogModel $objBlog, ModuleModel $objModule): string
    {
        /** @var PageModel $pageModelAdapter */
        $pageModelAdapter = $this->framework->getAdapter(PageModel::class);

        /** @var StringUtil $stringUtilAdapter */
        $stringUtilAdapter = $this->framework->getAdapter(StringUtil::class);

        // Generate frontend preview link
        $previewLink = '';

        if ($objModule->eventBlogReaderPage > 0) {
            $objTarget = $pageModelAdapter->findByPk($objModule->eventBlogReaderPage);

            if (null !== $objTarget) {
                $previewLink = $stringUtilAdapter->ampersand($objTarget->getAbsoluteUrl('/'.$objBlog->id));
                $previewLink = $this->urlParser->addQueryString('securityToken='.$objBlog->securityToken, $previewLink);
            }
        }

        return $previewLink;
    }

    private function validateImageUploads(CalendarEventsBlogModel $objReportModel): bool
    {
        /** @var StringUtil $stringUtilAdapter */
        $stringUtilAdapter = $this->framework->getAdapter(StringUtil::class);

        /** @var FilesModel $filesModelAdapter */
        $filesModelAdapter = $this->framework->getAdapter(FilesModel::class);

        // Check for a valid photographer name an exiting image legends
        if (!empty($objReportModel->multiSRC) && !empty($stringUtilAdapter->deserialize($objReportModel->multiSRC, true))) {
            $arrUuids = $stringUtilAdapter->deserialize($objReportModel->multiSRC, true);
            $objFiles = $filesModelAdapter->findMultipleByUuids($arrUuids);

            if (null !== $objFiles) {
                $blnMissingLegend = false;
                $blnMissingPhotographerName = false;

                while ($objFiles->next()) {
                    $arrMeta = $stringUtilAdapter->deserialize($objFiles->meta, true);

                    if (!isset($arrMeta[$this->locale]['caption']) || '' === $arrMeta[$this->locale]['caption']) {
                        $blnMissingLegend = true;
                    }

                    if (!isset($arrMeta[$this->locale]['photographer']) || '' === $arrMeta[$this->locale]['photographer']) {
                        $blnMissingPhotographerName = true;
                    }
                }

                if ($blnMissingLegend || $blnMissingPhotographerName) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Add messages from session to template.
     */
    private function addMessagesToTemplate(Template $template): void
    {
        // Set adapters
        $messageAdapter = $this->framework->getAdapter(Message::class);

        $template->hasInfoMessage = false;
        $template->hasErrorMessage = false;

        if ($messageAdapter->hasInfo()) {
            $template->hasInfoMessage = true;
            $arrInfoMsg = $this->requestStack->getCurrentRequest()->getSession()->getFlashBag()->get('contao.FE.info');
            $template->infoMessage = $arrInfoMsg[0];
            $template->infoMessages = $arrInfoMsg;
        }

        if ($messageAdapter->hasError()) {
            $template->hasErrorMessage = true;
            $arrErrMsg = $this->requestStack->getCurrentRequest()->getSession()->getFlashBag()->get('contao.FE.error');
            $template->errorMessage = $arrErrMsg[0];
            $template->errorMessages = $arrErrMsg;
        }

        $messageAdapter->reset();
    }
}
