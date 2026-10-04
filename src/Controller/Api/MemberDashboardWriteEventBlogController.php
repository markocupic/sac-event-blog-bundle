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

namespace Markocupic\SacEventBlogBundle\Controller\Api;

use Codefog\HasteBundle\UrlParser;
use Contao\CalendarEventsModel;
use Contao\ContentModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Environment;
use Contao\FilesModel;
use Contao\FrontendUser;
use Contao\PageModel;
use Contao\StringUtil;
use Contao\UserModel;
use Contao\Validator;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Types\Types;
use Markocupic\SacEventBlogBundle\Config\PublishState;
use Markocupic\SacEventBlogBundle\Model\CalendarEventsBlogModel;
use Markocupic\SacEventBlogBundle\NotificationType\OnNewEventBlogNotificationType;
use Markocupic\SacEventToolBundle\Image\RotateImage;
use Markocupic\SacEventToolBundle\Model\EventOrganizerModel;
use Markocupic\SacEventToolBundle\Model\UserRoleModel;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
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
        private readonly Connection $connection,
        private readonly ContaoFramework $framework,
        private readonly RequestStack $requestStack,
        private readonly RotateImage $rotateImage,
        private readonly RouterInterface $router,
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
        private readonly UrlParser $urlParser,
        private readonly NotificationCenter $notificationCenter,
        private readonly string $projectDir,
        private readonly string $locale,
    ) {
    }

    /**
     * @throws Exception
     */
    #[Route('/ajaxMemberDashboardWriteEventBlog/setPublishState', name: 'sac_event_tool_ajax_member_dashboard_write_event_blog_set_publish_state', defaults: ['_scope' => 'frontend', '_token_check' => true], methods: ['POST'])]
    public function setPublishStateAction(): JsonResponse
    {
        $this->framework->initialize();
        $this->checkHasLoggedInFrontendUser();
        $this->checkIsXmlHttpRequest();

        $request = $this->requestStack->getCurrentRequest();

        // Adapters
        $filesModelAdapter = $this->framework->getAdapter(FilesModel::class);
        $calendarEventsModelAdapter = $this->framework->getAdapter(CalendarEventsModel::class);
        $calendarEventsBlogModelAdapter = $this->framework->getAdapter(CalendarEventsBlogModel::class);
        $stringUtilAdapter = $this->framework->getAdapter(StringUtil::class);
        $userModelAdapter = $this->framework->getAdapter(UserModel::class);
        $userRoleModelAdapter = $this->framework->getAdapter(UserRoleModel::class);
        $contentModelAdapter = $this->framework->getAdapter(ContentModel::class);
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

        $objBlog = $calendarEventsBlogModelAdapter->findById($id);

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
        if (PublishState::APPROVED_FOR_REVIEW === (int) $request->request->get('publishState') && $objBlog->publishState < PublishState::APPROVED_FOR_REVIEW && $request->request->get('contentId')) {
            $objContent = $contentModelAdapter->findById($request->request->get('contentId'));

            $notificationId = false;

            if (null !== $objContent) {
                $notificationId = $this->connection->fetchOne('SELECT id FROM tl_nc_notification WHERE type = :type', ['type' => OnNewEventBlogNotificationType::NAME], ['type' => Types::STRING]);
            }

            if (false !== $notificationId && $request->request->get('eventId') > 0) {
                $objEvent = $calendarEventsModelAdapter->findById($request->request->get('eventId'));
                $objInstructor = $userModelAdapter->findById($objEvent->mainInstructor);
                $instructorName = '';
                $instructorEmail = '';

                if (null !== $objInstructor) {
                    $instructorName = $objInstructor->name;
                    $instructorEmail = $objInstructor->email;
                }

                // Generate frontend preview link
                $previewLink = '';

                if ($objContent->eventBlogReaderPage > 0) {
                    $objTarget = $pageModelAdapter->findById($objContent->eventBlogReaderPage);

                    if (null !== $objTarget) {
                        $previewLink = $stringUtilAdapter->ampersand($objTarget->getAbsoluteUrl('/'.$objBlog->id));
                        $previewLink = $this->urlParser->addQueryString('securityToken='.$objBlog->securityToken, $previewLink);
                    }
                }

                // Notify webmaster
                $arrRecipients = [];
                $arrOrganizers = $stringUtilAdapter->deserialize($objEvent->organizers, true);

                foreach ($arrOrganizers as $orgId) {
                    $objEventOrganizer = $eventOrganizerModelAdapter->findById($orgId);

                    if (null !== $objEventOrganizer) {
                        $recipients = $stringUtilAdapter->deserialize($objEventOrganizer->notifyWebmasterOnNewEventBlog, true);

                        foreach ($recipients as $recipient) {
                            $email = '';

                            if (preg_match('/^user_id:(\d+)$/', $recipient, $matches)) {
                                $email = $userModelAdapter->findById((int) $matches[1])?->email;
                            } elseif (preg_match('/^user_role_id:(\d+)$/', $recipient, $matches)) {
                                $email = $userRoleModelAdapter->findById((int) $matches[1])?->email;
                            }

                            if ($validatorAdapter->isEmail($email)) {
                                $arrRecipients[] = $email;
                            }
                        }
                    }
                }

                $webmasterEmail = implode(',', $arrRecipients);

                $arrTokens = [];

                if (null !== $objEvent) {
                    $arrTokens = array_merge($arrTokens, [
                        'event_title' => $this->decode($objEvent->title),
                        'event_id' => $objEvent->id,
                        'instructor_name' => '' !== $instructorName ? $this->decode($instructorName) : $this->translator->trans('MSC.md_write_event_blog_instructorNameNotSpecified', [], 'contao_default'),
                        'instructor_email' => $instructorEmail,
                        'webmaster_email' => $webmasterEmail,
                        'author_name' => $this->decode($objUser->firstname.' '.$objUser->lastname),
                        'author_email' => $objUser->email,
                        'author_sac_member_id' => $objUser->sacMemberId,
                        'hostname' => $environmentAdapter->get('host'),
                        'blog_link_backend' => $this->router->generate('contao_backend', ['do' => 'sac_calendar_events_blog_tool', 'act' => 'edit', 'id' => $objBlog->id], UrlGeneratorInterface::ABSOLUTE_URL),
                        'blog_link_frontend' => $previewLink,
                        'blog_title' => $this->decode($objBlog->title),
                        'blog_text' => $this->decode($objBlog->text),
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
    #[Route('/ajaxMemberDashboardWriteEventBlog/sortGallery', name: 'sac_event_tool_ajax_member_dashboard_write_event_blog_sort_gallery', defaults: ['_scope' => 'frontend', '_token_check' => true], methods: ['POST'])]
    public function sortGalleryAction(): JsonResponse
    {
        $this->framework->initialize();
        $this->checkHasLoggedInFrontendUser();
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

        $objBlog = $calendarEventsBlogModelAdapter->findById($id);

        $arrSorting = json_decode($request->request->get('uuids'));
        $arrSorting = array_map(
            static fn ($uuid) => $stringUtilAdapter->uuidToBin($uuid),
            $arrSorting,
        );

        $objBlog->multiSRC = serialize($arrSorting);

        $objBlog->save();

        return new JsonResponse(['status' => 'success']);
    }

    /**
     * @throws \Exception
     */
    #[Route('/ajaxMemberDashboardWriteEventBlog/removeImage', name: 'sac_event_tool_ajax_member_dashboard_write_event_blog_remove_image', defaults: ['_scope' => 'frontend', '_token_check' => true], methods: ['POST'])]
    public function removeImageAction(): JsonResponse
    {
        $this->framework->initialize();
        $this->checkHasLoggedInFrontendUser();
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
            ],
        );

        if (!$id) {
            return new JsonResponse(['status' => 'error']);
        }

        $objBlog = $calendarEventsBlogModelAdapter->findById($id);

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
    #[Route('/ajaxMemberDashboardWriteEventBlog/rotateImage', name: 'sac_event_tool_ajax_member_dashboard_write_event_blog_rotate_image', defaults: ['_scope' => 'frontend', '_token_check' => true], methods: ['POST'])]
    public function rotateImageAction(): JsonResponse
    {
        $this->framework->initialize();
        $this->checkHasLoggedInFrontendUser();
        $this->checkIsXmlHttpRequest();

        $request = $this->requestStack->getCurrentRequest();

        $fileId = $request->request->get('fileId');

        $filesModelAdapter = $this->framework->getAdapter(FilesModel::class);

        $objFiles = $filesModelAdapter->findOneById($fileId);

        if (null === $objFiles) {
            return new JsonResponse(['status' => 'error']);
        }

        try {
            $this->rotateImage->rotate(Path::join($this->projectDir, $objFiles->path), 270);
        } catch (\InvalidArgumentException|\RuntimeException) {
            return new JsonResponse(['status' => 'error']);
        }

        return new JsonResponse(['status' => 'success']);
    }

    /**
     * @throws \Exception
     */
    #[Route('/ajaxMemberDashboardWriteEventBlog/getCaption', name: 'sac_event_tool_ajax_member_dashboard_write_event_blog_get_caption', defaults: ['_scope' => 'frontend', '_token_check' => true], methods: ['POST'])]
    public function getCaptionAction(): JsonResponse
    {
        $this->framework->initialize();
        $this->checkHasLoggedInFrontendUser();
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
    #[Route('/ajaxMemberDashboardWriteEventBlog/setCaption', name: 'sac_event_tool_ajax_member_dashboard_write_event_blog_set_caption', defaults: ['_scope' => 'frontend', '_token_check' => true], methods: ['POST'])]
    public function setCaptionAction(): JsonResponse
    {
        $this->framework->initialize();
        $this->checkHasLoggedInFrontendUser();
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

    private function checkIsXmlHttpRequest(): void
    {
        $request = $this->requestStack->getCurrentRequest();

        if (!$request->isXmlHttpRequest()) {
            throw $this->createNotFoundException('The route "/ajaxMemberDashboardWriteEventBlog" is allowed to XMLHttpRequest requests only.');
        }
    }

    private function decode(string $value): string
    {
        return trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
