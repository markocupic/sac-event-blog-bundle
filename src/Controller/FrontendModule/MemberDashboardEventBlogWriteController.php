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
use Markocupic\ContaoFilepondUploader\Widget\FrontendWidget;
use Markocupic\SacEventBlogBundle\Config\PublishState;
use Markocupic\SacEventBlogBundle\Model\CalendarEventsBlogModel;
use Markocupic\SacEventBlogBundle\Upload\Exception\ImageUploadException;
use Markocupic\SacEventBlogBundle\Upload\ImageUploadHandler;
use Markocupic\SacEventBlogBundle\Validator\ImageUploadValidator;
use Markocupic\SacEventToolBundle\Util\CalendarEventsUtil;
use Markocupic\SacEventToolBundle\Config\EventExecutionState;
use Markocupic\SacEventToolBundle\Model\CalendarEventsMemberModel;
use Psr\Log\LogLevel;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;
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
        private readonly ImageUploadValidator $imageUploadValidator,
        private readonly ImageUploadHandler $imageUploadHandler,
        private readonly string $projectDir,
        private readonly string $tmpPath,
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
        $template->showDashboard = true;

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
        $calendarEventsUtilAdapter = $this->framework->getAdapter(CalendarEventsUtil::class);
        $controllerAdapter = $this->framework->getAdapter(Controller::class);
        $inputAdapter = $this->framework->getAdapter(Input::class);
        $stringUtilAdapter = $this->framework->getAdapter(StringUtil::class);

        // Load language file
        $controllerAdapter->loadLanguageFile('tl_calendar_events_blog');

        // Handle messages
        if (empty($this->user->email) || !$validatorAdapter->isEmail($this->user->email)) {
            $template->showDashboard = false;
            $messageAdapter->addInfo($this->translator->trans('ERR.md_write_event_blog_emailAddressNotFound', [], 'contao_default'));
            $this->addMessagesToTemplate($template);

            return $template->getResponse();
        }

        $objEvent = $calendarEventsModelAdapter->findByPk($inputAdapter->get('eventId'));

        if (null === $objEvent) {
            $template->showDashboard = false;
            $messageAdapter->addError($this->translator->trans('ERR.md_write_event_blog_eventNotFound', [$inputAdapter->get('eventId')], 'contao_default'));
            $this->addMessagesToTemplate($template);

            return $template->getResponse();
        }

        // Check if blog already exists
        $objBlog = CalendarEventsBlogModel::findOneBySacMemberIdAndEventId($this->user->sacMemberId, $objEvent->id);

        if (null === $objBlog) {
            if ($objEvent->endDate + $model->eventBlogTimeSpanForCreatingNew * 24 * 60 * 60 < time()) {
                // Do not allow blogging for old events
                $template->showDashboard = false;
                $messageAdapter->addError($this->translator->trans('ERR.md_write_event_blog_createBlogDeadlineExpired', [], 'contao_default'));
                $this->addMessagesToTemplate($template);

                return $template->getResponse();
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
                    $template->showDashboard = false;
                    $messageAdapter->addError($this->translator->trans('ERR.md_write_event_blog_writingPermissionDenied', [], 'contao_default'));
                    $this->addMessagesToTemplate($template);

                    return $template->getResponse();
                }
            }

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

            // Set security token for the frontend preview.
            if ($affected) {
                $insertId = $this->connection->lastInsertId();
                $set = [
                    'securityToken' => md5((string) random_int(100000000, 999999999)).$insertId,
                ];

                $this->connection->update('tl_calendar_events_blog', $set, ['id' => $insertId]);

                $objBlog = $calendarEventsBlogModelAdapter->findByPk($insertId);
            }
        }

        if (empty($objBlog)) {
            throw new \Exception('Blog model not found.');
        }

        $template->request_token = $this->contaoCsrfTokenManager->getDefaultTokenValue();
        $template->event = $objEvent->row;
        $template->eventId = $objEvent->id;
        $template->eventName = $objEvent->title;
        $template->executionState = $objEvent->executionState;
        $template->eventSubstitutionText = $objEvent->eventSubstitutionText;
        $template->youTubeId = $objBlog->youTubeId;
        $template->text = $objBlog->text;
        $template->title = $objBlog->title;
        $template->publishState = (int) $objBlog->publishState;
        $template->eventPeriod = $calendarEventsUtilAdapter->getEventPeriod($objEvent);

        // Get the gallery
        $template->images = $this->getGalleryImages($objBlog);

        if ('' !== $objBlog->tourWaypoints) {
            $template->tourWaypoints = nl2br((string) $objBlog->tourWaypoints);
        }

        if ('' !== $objBlog->tourProfile) {
            $template->tourProfile = nl2br((string) $objBlog->tourProfile);
        }

        if ('' !== $objBlog->tourTechDifficulty) {
            $template->tourTechDifficulty = nl2br((string) $objBlog->tourTechDifficulty);
        }

        if ('' !== $objBlog->tourHighlights) {
            $template->tourHighlights = nl2br((string) $objBlog->tourHighlights);
        }

        if ('' !== $objBlog->tourPublicTransportInfo) {
            $template->tourPublicTransportInfo = nl2br((string) $objBlog->tourPublicTransportInfo);
        }

        // Generate forms
        $template->objEventBlogTextAndYoutubeForm = $this->generateTextAndYoutubeForm($objBlog);
        $template->objEventBlogImageUploadForm = $this->generatePictureUploadForm($objBlog, $model);

        // Image dimension and max upload file size restrictions
        $template->maxImageWidth = $model->eventBlogMaxImageWidth;
        $template->maxImageHeight = $model->eventBlogMaxImageHeight;
        $template->maxImageFileSize = $model->eventBlogMaxImageFileSize;

        // Get the preview link
        $template->previewLink = $this->getPreviewLink($objBlog, $model);

        // Twig callable
        $template->binToUuid = static fn (string $uuid): string => StringUtil::binToUuid($uuid);

        // Check if all images are labeled with a legend and a photographer name
        if (PublishState::STILL_IN_PROGRESS === (int) $objBlog->publishState) {
            if (!$this->validateImageUploads($objBlog)) {
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
        $calendarEventsUtilAdapter = $this->framework->getAdapter(CalendarEventsUtil::class);
        $calendarEventsModelAdapter = $this->framework->getAdapter(CalendarEventsModel::class);

        if (!empty($objEventBlogModel->tourProfile)) {
            return $objEventBlogModel->tourProfile;
        }

        $objEvent = $calendarEventsModelAdapter->findByPk($objEventBlogModel->eventId);

        if (null !== $objEvent) {
            $arrData = $calendarEventsUtilAdapter->getTourProfileAsArray($objEvent);

            return implode("\r\n", $arrData);
        }

        return '';
    }

    private function getTourTechDifficulties(CalendarEventsBlogModel $objEventBlogModel): string
    {
        $calendarEventsUtilAdapter = $this->framework->getAdapter(CalendarEventsUtil::class);
        $calendarEventsModelAdapter = $this->framework->getAdapter(CalendarEventsModel::class);

        if (!empty($objEventBlogModel->tourTechDifficulty)) {
            return $objEventBlogModel->tourTechDifficulty;
        }

        $objEvent = $calendarEventsModelAdapter->findByPk($objEventBlogModel->eventId);

        if (null !== $objEvent) {
            $arrData = $calendarEventsUtilAdapter->getTourTechDifficultiesAsArray($objEvent);

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

        /** @var Dbafs $dbafsAdapter */
        $dbafsAdapter = $this->framework->getAdapter(Dbafs::class);

        $logger = System::getContainer()->get('monolog.logger.contao');

        $fs = new Filesystem();

        $destDir = Path::join($this->projectDir, $this->eventBlogAssetDir, (string) $objEventBlogModel->id);

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

        $allowedExtensions = ['jpg', 'JPG', 'jpeg', 'JPEG'];

        // Add the Filepond uploader to the form
        $objForm->addFormField('fileUpload', [
            'label' => $this->translator->trans('FORM.md_write_event_blog_imageUpload', [], 'contao_default'),
            'inputType' => FrontendWidget::TYPE,
            'eval' => [
                'maxlength' => $moduleModel->eventBlogMaxImageFileSize,
                'extensions' => implode(',', $allowedExtensions),
                'storeFile' => true,
                'multiple' => true,
                'mSize' => 0, // infinite
                // Enable client side image resizing
                'allowImageResize' => true,
                'imageResizeTargetWidth' => max($moduleModel->eventBlogMaxImageWidth, $moduleModel->eventBlogMaxImageHeight),
                'imageResizeTargetHeight' => max($moduleModel->eventBlogMaxImageWidth, $moduleModel->eventBlogMaxImageHeight),
                'imageResizeMode' => 'contain',
                'imageResizeUpscale' => false,
            ],
        ]);

        // Add the submit button to the form
        $objForm->addFormField('submitImageUploadFormBtn', [
            'label' => $this->translator->trans('FORM.md_write_event_blog_addImagesToBlog', [], 'contao_default'),
            'inputType' => 'submit',
        ]);

        // validate() also checks whether the form has been submitted
        if ($objForm->validate() && $inputAdapter->post('FORM_SUBMIT') === $objForm->getFormId() && $inputAdapter->post('fileUpload')) {
            // $_POST['fileUpload'] will contain the transfer keys
            $arrTransferKeys = (array) $inputAdapter->post('fileUpload');

            // Filter empty/invalid values
            $arrTransferKeys = array_filter($arrTransferKeys, static fn ($v) => !empty($v) && \is_string($v) && 0 === strrpos($v, 'filepond_'));

            $arrPaths = array_map(fn ($dir) => Path::join($this->projectDir, $this->tmpPath, $dir), $arrTransferKeys);

            if (empty($arrPaths)) {
                $messageAdapter->addInfo($this->translator->trans('ERR.md_write_event_noValidImagesSelectedForUpload', [], 'contao_default'));

                $controllerAdapter->reload();
            }

            $finder = new Finder();
            $files = $finder
                ->in($arrPaths)
                ->files()
                ->ignoreDotFiles(true)
                // allow *.jpg, *.JPG, *.jpeg, *.JPEG
                ->name(array_map(static fn ($v) => '*.'.$v, $allowedExtensions))
            ;

            if (!$files->hasResults()) {
                $messageAdapter->addInfo($this->translator->trans('ERR.md_write_event_noValidImagesSelectedForUpload', [], 'contao_default'));

                $controllerAdapter->reload();
            }

            foreach ($files as $file) {
                $this->connection->beginTransaction();

                try {
                    // Validate upload
                    $this->imageUploadValidator->validateFileExists($file);
                    $this->imageUploadValidator->validateImageDimensions($file, $moduleModel->eventBlogMaxImageWidth, $moduleModel->eventBlogMaxImageHeight);
                    $this->imageUploadValidator->validateSize($file, $moduleModel->eventBlogMaxImageFileSize);

                    // Move file to the target directory, add meta information to the image and append the image to the event blog gallery.
                    $objFilesModel = $this->imageUploadHandler->moveToTarget($file, $objEventBlogModel, $destDir);
                    $this->imageUploadHandler->addMetaData($objFilesModel, $this->user, $this->page);
                    $this->imageUploadHandler->addUploadedImageToGallery($objFilesModel, $objEventBlogModel);

                    $messageAdapter->addInfo($this->translator->trans('FORM.md_write_event_confirmImageUploadSuccessful', [$file->getFilename()], 'contao_default'));
                } catch (ImageUploadException $e) {
                    $logger?->log(LogLevel::ERROR, $e->getMessage(), ['contao' => new ContaoContext(__METHOD__, 'EVENT STORY PICTURE UPLOAD')]);
                    $messageAdapter->addError($e->getTranslatableText());

                    $this->connection->rollBack();
                    continue;
                } catch (\Exception $e) {
                    $logger?->log(LogLevel::ERROR, $e->getMessage(), ['contao' => new ContaoContext(__METHOD__, 'EVENT STORY PICTURE UPLOAD')]);
                    $messageAdapter->addError($this->translator->trans('ERR.md_write_event_blog_generalUploadError', [], 'contao_default'));

                    $this->connection->rollBack();
                    continue;
                }

                $this->connection->commit();

                // Log
                $strText = sprintf('User with username %s has uploaded a new picture ("%s").', $this->user->username, $objFilesModel->path);
                $logger?->log(LogLevel::INFO, $strText, ['contao' => new ContaoContext(__METHOD__, 'EVENT STORY PICTURE UPLOAD')]);
            }

            $controllerAdapter->reload();
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

    private function validateImageUploads(CalendarEventsBlogModel $objBlog): bool
    {
        /** @var StringUtil $stringUtilAdapter */
        $stringUtilAdapter = $this->framework->getAdapter(StringUtil::class);

        /** @var FilesModel $filesModelAdapter */
        $filesModelAdapter = $this->framework->getAdapter(FilesModel::class);

        // Check for a valid photographer name an existing image legends
        if (!empty($objBlog->multiSRC) && !empty($stringUtilAdapter->deserialize($objBlog->multiSRC, true))) {
            $arrUuids = $stringUtilAdapter->deserialize($objBlog->multiSRC, true);
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
