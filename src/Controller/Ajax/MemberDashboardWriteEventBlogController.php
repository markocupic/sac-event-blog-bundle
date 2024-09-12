<?php

declare(strict_types=1);

/*
 * This file is part of SAC Event Blog Bundle.
 *
 * (c) Marko Cupic 2023 <m.cupic@gmx.ch>
 * @license GPL-3.0-or-later
 * For the full copyright and license information,
 * please view the LICENSE file that was distributed with this source code.
 * @link https://github.com/markocupic/sac-event-blog-bundle
 */

namespace Markocupic\SacEventBlogBundle\Controller\Ajax;

use Codefog\HasteBundle\UrlParser;
use Contao\CalendarEventsModel;
use Contao\CoreBundle\Exception\InvalidRequestTokenException;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Environment;
use Contao\FilesModel;
use Contao\FrontendUser;
use Contao\ModuleModel;
use Contao\PageModel;
use Contao\StringUtil;
use Contao\UserModel;
use Contao\Validator;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Types\Types;
use Markocupic\SacEventBlogBundle\Config\PublishState;
use Markocupic\SacEventBlogBundle\Model\CalendarEventsBlogModel;
use Markocupic\SacEventBlogBundle\NotificationType\OnNewEventBlogType;
use Markocupic\SacEventToolBundle\Image\RotateImage;
use Markocupic\SacEventToolBundle\Model\EventOrganizerModel;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Terminal42\NotificationCenterBundle\NotificationCenter;

class MemberDashboardWriteEventBlogController extends AbstractController
{
    /**
     * Handles ajax requests.
     * Allow if ...
     * - user is a logged in frontend user
     * - is XmlHttpRequest
     * - csrf token is valid.
     *
     * @throws \Exception
     */
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
        private readonly CsrfTokenManagerInterface $tokenManager,
        private readonly RequestStack $requestStack,
        private readonly Security $security,
        private readonly RouterInterface $router,
        private readonly TranslatorInterface $translator,
        private readonly RotateImage $rotateImage,
        private readonly UrlParser $urlParser,
        private readonly NotificationCenter $notificationCenter,
        private readonly string $projectDir,
        private readonly string $tokenName,
        private readonly string $locale,
    ) {
    }

    /**
     * @throws Exception
     */
    #[Route('/ajaxMemberDashboardWriteEventBlog/setPublishState', name: 'sac_event_tool_ajax_member_dashboard_write_event_blog_set_publish_state', defaults: ['_scope' => 'frontend'], methods: ['POST'])]
    public function setPublishStateAction(): JsonResponse
    {
        $this->framework->initialize();
        $this->checkHasLoggedInFrontendUser();
        $this->checkIsTokenValid();
        $this->checkIsXmlHttpRequest();

        $request = $this->requestStack->getCurrentRequest();

        // Adapters
        $filesModelAdapter = $this->framework->getAdapter(FilesModel::class);
        $calendarEventsModelAdapter = $this->framework->getAdapter(CalendarEventsModel::class);
        $calendarEventsBlogModelAdapter = $this->framework->getAdapter(CalendarEventsBlogModel::class);
        $stringUtilAdapter = $this->framework->getAdapter(StringUtil::class);
        $userModelAdapter = $this->framework->getAdapter(UserModel::class);
        $moduleModelAdapter = $this->framework->getAdapter(ModuleModel::class);
        $pageModelAdapter = $this->framework->getAdapter(PageModel::class);
        $environmentAdapter = $this->framework->getAdapter(Environment::class);
        $eventOrganizerModelAdapter = $this->framework->getAdapter(EventOrganizerModel::class);
        $validatorAdapter = $this->framework->getAdapter(Validator::class);

        if (!$request->request->get('eventId')) {
            return new JsonResponse(['status' => 'error']);
        }

        /** @var FrontendUser|null $objUser */
        $objUser = $this->security->getUser();

        if (null === $objUser) {
            throw new \RuntimeException('No logged in frontend user found!');
        }

        $id = $this->connection->fetchOne(
            'SELECT id FROM tl_calendar_events_blog WHERE sacMemberId = ? AND eventId = ? AND publishState < ?',
            [
                $objUser->sacMemberId,
                $request->request->get('eventId'),
                PublishState::PUBLISHED,
            ],
        );

        if (!$id) {
            return new JsonResponse(['status' => 'error']);
        }

        $objBlog = $calendarEventsBlogModelAdapter->findByPk($id);

        // Check for a valid photographer name an existing image legends
        if (!empty($objBlog->multiSRC) && !empty($stringUtilAdapter->deserialize($objBlog->multiSRC, true))) {
            $arrUuids = $stringUtilAdapter->deserialize($objBlog->multiSRC, true);
            $objFiles = $filesModelAdapter->findMultipleByUuids($arrUuids);
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
                return new JsonResponse(['status' => 'error']);
            }
        }

        // Notify back office via terminal42/notification_center if there is a new blog entry.
        if (PublishState::APPROVED_FOR_REVIEW === (int) $request->request->get('publishState') && $objBlog->publishState < PublishState::APPROVED_FOR_REVIEW && $request->request->get('moduleId')) {
            $objModule = $moduleModelAdapter->findByPk($request->request->get('moduleId'));

            $notificationId = false;

            if (null !== $objModule) {
                $notificationId = $this->connection->fetchOne('SELECT id FROM tl_nc_notification WHERE type = :type', ['type' => OnNewEventBlogType::NAME], ['type' => Types::STRING]);
            }

            if (false !== $notificationId && $request->request->get('eventId') > 0) {
                $objEvent = $calendarEventsModelAdapter->findByPk($request->request->get('eventId'));
                $objInstructor = $userModelAdapter->findByPk($objEvent->mainInstructor);
                $instructorName = '';
                $instructorEmail = '';

                if (null !== $objInstructor) {
                    $instructorName = $objInstructor->name;
                    $instructorEmail = $objInstructor->email;
                }

                // Generate frontend preview link
                $previewLink = '';

                if ($objModule->eventBlogReaderPage > 0) {
                    $objTarget = $pageModelAdapter->findByPk($objModule->eventBlogReaderPage);

                    if (null !== $objTarget) {
                        $previewLink = $stringUtilAdapter->ampersand($objTarget->getAbsoluteUrl('/'.$objBlog->id));
                        $previewLink = $this->urlParser->addQueryString('securityToken='.$objBlog->securityToken, $previewLink);
                    }
                }

                // Notify webmaster
                $arrNotifyEmail = [];
                $arrOrganizers = $stringUtilAdapter->deserialize($objEvent->organizers, true);

                foreach ($arrOrganizers as $orgId) {
                    $objEventOrganizer = $eventOrganizerModelAdapter->findByPk($orgId);

                    if (null !== $objEventOrganizer) {
                        $arrUsers = $stringUtilAdapter->deserialize($objEventOrganizer->notifyWebmasterOnNewEventBlog, true);

                        foreach ($arrUsers as $userId) {
                            $objWebmaster = $userModelAdapter->findByPk($userId);

                            if (null !== $objWebmaster) {
                                if ('' !== $objWebmaster->email) {
                                    if ($validatorAdapter->isEmail($objWebmaster->email)) {
                                        $arrNotifyEmail[] = $objWebmaster->email;
                                    }
                                }
                            }
                        }
                    }
                }

                $webmasterEmail = implode(',', $arrNotifyEmail);

                $arrTokens = [];

                if (null !== $objEvent) {
                    $arrTokens = array_merge($arrTokens, [
                        'event_title' => $objEvent->title,
                        'event_id' => $objEvent->id,
                        'instructor_name' => '' !== $instructorName ? $instructorName : $this->translator->trans('MSC.md_write_event_blog_instructorNameNotSpecified', [], 'contao_default'),
                        'instructor_email' => '' !== $instructorEmail ? $instructorEmail : $this->translator->trans('MSC.md_write_event_blog_instructorNameNotSpecified', [], 'contao_default'),
                        'webmaster_email' => '' !== $webmasterEmail ? $webmasterEmail : '',
                        'author_name' => $objUser->firstname.' '.$objUser->lastname,
                        'author_email' => $objUser->email,
                        'author_sac_member_id' => $objUser->sacMemberId,
                        'hostname' => $environmentAdapter->get('host'),
                        'blog_link_backend' => $this->router->generate('contao_backend', ['do' => 'sac_calendar_events_blog_tool', 'act' => 'edit', 'id' => $objBlog->id], UrlGeneratorInterface::ABSOLUTE_URL),
                        'blog_link_frontend' => $previewLink,
                        'blog_title' => $objBlog->title,
                        'blog_text' => $objBlog->text,
                    ]);
                }

                $this->notificationCenter->sendNotification($notificationId, $arrTokens, $this->locale);
            }
        }

        // Save publish state
        $objBlog->publishState = $request->request->get('publishState');
        $objBlog->save();

        $json = [
            'status' => 'success',
            'publishState' => $objBlog->publishState,
        ];

        return new JsonResponse($json);
    }

    /**
     * @throws \Exception
     */
    #[Route('/ajaxMemberDashboardWriteEventBlog/sortGallery', name: 'sac_event_tool_ajax_member_dashboard_write_event_blog_sort_gallery', defaults: ['_scope' => 'frontend'], methods: ['POST'])]
    public function sortGalleryAction(): JsonResponse
    {
        $this->framework->initialize();
        $this->checkHasLoggedInFrontendUser();
        $this->checkIsTokenValid();
        $this->checkIsXmlHttpRequest();

        $user = $this->security->getUser();
        $request = $this->requestStack->getCurrentRequest();

        // Adapters
        $calendarEventsBlogModelAdapter = $this->framework->getAdapter(CalendarEventsBlogModel::class);
        $stringUtilAdapter = $this->framework->getAdapter(StringUtil::class);

        if (!$request->request->get('uuids') || !$request->request->get('eventId') || !$user instanceof FrontendUser) {
            return new JsonResponse(['status' => 'error']);
        }

        /** @var FrontendUser|null $objUser */
        $objUser = $this->security->getUser();

        if (null === $objUser) {
            throw new \RuntimeException('No logged in frontend user found!');
        }

        $id = $this->connection->fetchOne(
            'SELECT id FROM tl_calendar_events_blog WHERE sacMemberId = ? AND eventId = ?',
            [
                $objUser->sacMemberId,
                $request->request->get('eventId'),
            ],
        );

        if (!$id) {
            return new JsonResponse(['status' => 'error']);
        }

        $objBlog = $calendarEventsBlogModelAdapter->findByPk($id);

        $arrSorting = json_decode($request->request->get('uuids'));
        $arrSorting = array_map(
            static fn ($uuid) => $stringUtilAdapter->uuidToBin($uuid),
            $arrSorting
        );

        $objBlog->multiSRC = serialize($arrSorting);

        $objBlog->save();

        return new JsonResponse(['status' => 'success']);
    }

    /**
     * @throws \Exception
     */
    #[Route('/ajaxMemberDashboardWriteEventBlog/removeImage', name: 'sac_event_tool_ajax_member_dashboard_write_event_blog_remove_image', defaults: ['_scope' => 'frontend'], methods: ['POST'])]
    public function removeImageAction(): JsonResponse
    {
        $this->framework->initialize();
        $this->checkHasLoggedInFrontendUser();
        $this->checkIsTokenValid();
        $this->checkIsXmlHttpRequest();

        $request = $this->requestStack->getCurrentRequest();

        // Adapters
        $calendarEventsBlogModelAdapter = $this->framework->getAdapter(CalendarEventsBlogModel::class);
        $stringUtilAdapter = $this->framework->getAdapter(StringUtil::class);
        $filesModelAdapter = $this->framework->getAdapter(FilesModel::class);
        $validatorAdapter = $this->framework->getAdapter(Validator::class);

        if (!$request->request->get('eventId') || !$request->request->get('uuid')) {
            return new JsonResponse(['status' => 'error']);
        }

        /** @var FrontendUser|null $objUser */
        $objUser = $this->security->getUser();

        if (null === $objUser) {
            throw new \RuntimeException('No logged in frontend user found!');
        }

        $id = $this->connection->fetchOne(
            'SELECT * FROM tl_calendar_events_blog WHERE sacMemberId = ? && eventId = ? && publishState < ?',
            [
                $objUser->sacMemberId,
                $request->request->get('eventId'),
                PublishState::PUBLISHED,
            ]
        );

        if (!$id) {
            return new JsonResponse(['status' => 'error']);
        }

        $objBlog = $calendarEventsBlogModelAdapter->findByPk($id);

        $multiSrc = $stringUtilAdapter->deserialize($objBlog->multiSRC, true);

        $uuid = $stringUtilAdapter->uuidToBin($request->request->get('uuid'));

        if (!$validatorAdapter->isUuid($uuid)) {
            return new JsonResponse(['status' => 'error']);
        }

        $key = array_search($uuid, $multiSrc, true);

        if (false !== $key) {
            unset($multiSrc[$key]);
            $multiSrc = array_values($multiSrc);
            $objBlog->multiSRC = serialize($multiSrc);
        }

        // Save model
        $objBlog->save();

        // Delete image from filesystem and db
        $filesModel = $filesModelAdapter->findByUuid($uuid);

        if (null !== $filesModel) {
            $fs = new Filesystem();
            $fs->remove($this->projectDir.'/'.$filesModel->path);

            $filesModel->delete();
        }

        return new JsonResponse(['status' => 'success']);
    }

    /**
     * @throws \Exception
     */
    #[Route('/ajaxMemberDashboardWriteEventBlog/rotateImage', name: 'sac_event_tool_ajax_member_dashboard_write_event_blog_rotate_image', defaults: ['_scope' => 'frontend'], methods: ['POST'])]
    public function rotateImageAction(): JsonResponse
    {
        $this->framework->initialize();
        $this->checkHasLoggedInFrontendUser();
        $this->checkIsTokenValid();
        $this->checkIsXmlHttpRequest();

        $request = $this->requestStack->getCurrentRequest();

        $fileId = $request->request->get('fileId');

        $filesModelAdapter = $this->framework->getAdapter(FilesModel::class);

        // Get the image rotate service
        $objFiles = $filesModelAdapter->findOneById($fileId);

        if ($this->rotateImage->rotate($objFiles, 270)) {
            $json = ['status' => 'success'];
        } else {
            $json = ['status' => 'error'];
        }

        return new JsonResponse($json);
    }

    /**
     * @throws \Exception
     */
    #[Route('/ajaxMemberDashboardWriteEventBlog/getCaption', name: 'sac_event_tool_ajax_member_dashboard_write_event_blog_get_caption', defaults: ['_scope' => 'frontend'], methods: ['POST'])]
    public function getCaptionAction(): JsonResponse
    {
        $this->framework->initialize();
        $this->checkHasLoggedInFrontendUser();
        $this->checkIsTokenValid();
        $this->checkIsXmlHttpRequest();

        $request = $this->requestStack->getCurrentRequest();

        // Adapters
        $stringUtilAdapter = $this->framework->getAdapter(StringUtil::class);
        $filesModelAdapter = $this->framework->getAdapter(FilesModel::class);

        $objUser = $this->security->getUser();

        if ('' !== $request->request->get('fileUuid')) {
            $objFile = $filesModelAdapter->findByUuid($request->request->get('fileUuid'));

            if (null !== $objFile) {
                $arrMeta = $stringUtilAdapter->deserialize($objFile->meta, true);

                if (!isset($arrMeta[$this->locale]['caption'])) {
                    $caption = '';
                } else {
                    $caption = $arrMeta[$this->locale]['caption'];
                }

                if (!isset($arrMeta[$this->locale]['photographer'])) {
                    $photographer = $objUser->firstname.' '.$objUser->lastname;
                } else {
                    $photographer = $arrMeta[$this->locale]['photographer'];

                    if ('' === $photographer) {
                        $photographer = $objUser->firstname.' '.$objUser->lastname;
                    }
                }

                return new JsonResponse([
                    'status' => 'success',
                    'caption' => html_entity_decode((string) $caption),
                    'photographer' => $photographer,
                ]);
            }
        }

        return new JsonResponse(['status' => 'error']);
    }

    /**
     * @throws \Exception
     */
    #[Route('/ajaxMemberDashboardWriteEventBlog/setCaption', name: 'sac_event_tool_ajax_member_dashboard_write_event_blog_set_caption', defaults: ['_scope' => 'frontend'], methods: ['POST'])]
    public function setCaptionAction(): JsonResponse
    {
        $this->framework->initialize();
        $this->checkHasLoggedInFrontendUser();
        $this->checkIsTokenValid();
        $this->checkIsXmlHttpRequest();

        $request = $this->requestStack->getCurrentRequest();

        // Adapters
        $stringUtilAdapter = $this->framework->getAdapter(StringUtil::class);
        $filesModelAdapter = $this->framework->getAdapter(FilesModel::class);

        if ('' !== $request->request->get('fileUuid')) {
            $objUser = $this->security->getUser();

            if (!$objUser instanceof FrontendUser) {
                return new JsonResponse(['status' => 'error']);
            }

            $objFile = $filesModelAdapter->findByUuid($request->request->get('fileUuid'));

            if (null !== $objFile) {
                $arrMeta = $stringUtilAdapter->deserialize($objFile->meta, true);

                if (!isset($arrMeta[$this->locale])) {
                    $arrMeta[$this->locale] = [
                        'title' => '',
                        'alt' => '',
                        'link' => '',
                        'caption' => '',
                        'photographer' => '',
                    ];
                }
                $arrMeta[$this->locale]['caption'] = $request->request->get('caption');
                $arrMeta[$this->locale]['photographer'] = $request->request->get('photographer') ?: $objUser->firstname.' '.$objUser->lastname;

                $objFile->meta = serialize($arrMeta);
                $objFile->save();

                return new JsonResponse(['status' => 'success']);
            }
        }

        return new JsonResponse(['status' => 'error']);
    }

    /**
     * @throws \Exception
     */
    private function checkHasLoggedInFrontendUser(): void
    {
        $user = $this->security->getUser();

        if (!$user instanceof FrontendUser) {
            throw new \Exception('Access denied! You have to be logged in as a Contao frontend user');
        }
    }

    private function checkIsTokenValid(): void
    {
        $request = $this->requestStack->getCurrentRequest();

        if (!$this->tokenManager->isTokenValid(new CsrfToken($this->tokenName, $request->get('REQUEST_TOKEN')))) {
            throw new InvalidRequestTokenException('Invalid CSRF token. Please reload the page and try again.');
        }
    }

    private function checkIsXmlHttpRequest(): void
    {
        $request = $this->requestStack->getCurrentRequest();

        if (!$request->isXmlHttpRequest()) {
            throw $this->createNotFoundException('The route "/ajaxMemberDashboardWriteEventBlog" is allowed to XMLHttpRequest requests only.');
        }
    }
}
