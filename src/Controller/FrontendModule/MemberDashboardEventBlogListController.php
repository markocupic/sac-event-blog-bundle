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

namespace Markocupic\SacEventBlogBundle\Controller\FrontendModule;

use Codefog\HasteBundle\Form\Form;
use Codefog\HasteBundle\UrlParser;
use Contao\CalendarEventsModel;
use Contao\Config;
use Contao\Controller;
use Contao\CoreBundle\Controller\FrontendModule\AbstractFrontendModuleController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsFrontendModule;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ScopeMatcher;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Contao\Database;
use Contao\Date;
use Contao\Environment;
use Contao\FrontendUser;
use Contao\Input;
use Contao\Message;
use Contao\ModuleModel;
use Contao\PageModel;
use Contao\Validator;
use Markocupic\SacEventToolBundle\Model\CalendarEventsMemberModel;
use Markocupic\SacEventToolBundle\Util\CalendarEventsUtil;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsFrontendModule(MemberDashboardEventBlogListController::TYPE, category:'sac_event_tool_frontend_modules', template:'mod_member_dashboard_event_blog_list')]
class MemberDashboardEventBlogListController extends AbstractFrontendModuleController
{
    public const string TYPE = 'member_dashboard_event_blog_list';
    protected FrontendUser|null $user;

    public function __construct(
        private readonly CalendarEventsUtil $calendarEventsUtil,
        private readonly ContaoFramework $framework,
        private readonly ScopeMatcher $scopeMatcher,
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
        private readonly UrlParser $urlParser,
    ) {
        // Get logged in member
        if (($user = $this->security->getUser()) instanceof FrontendUser) {
            $this->user = $user;
        }
    }

    public function __invoke(Request $request, ModuleModel $model, string $section, array|null $classes = null, PageModel|null $page = null): Response
    {
        if ($this->scopeMatcher->isFrontendRequest($request)) {
            if (null !== $page) {
                // Neither cache nor search page
                $page->noSearch = 1;
                $page->cache = 0;
            }
        }

        return parent::__invoke($request, $model, $section, $classes);
    }

    protected function getResponse(FragmentTemplate $template, ModuleModel $model, Request $request): Response
    {
        // Do not allow for not authorized users
        if (null === $this->user) {
            throw new UnauthorizedHttpException('Not authorized. Please log in as frontend user.');
        }

        // Set adapters
        $messageAdapter = $this->framework->getAdapter(Message::class);
        $validatorAdapter = $this->framework->getAdapter(Validator::class);

        // Handle messages
        if (empty($this->user->email) || !$validatorAdapter->isEmail($this->user->email)) {
            $messageAdapter->addInfo($this->translator->trans('ERR.md_write_event_blog_emailAddressNotFound', [], 'contao_default'));
        }

        // Get the time span for creating a new event blog
        $template->set('eventBlogTimeSpanForCreatingNew', $model->eventBlogTimeSpanForCreatingNew);

        // Add messages to template
        $this->addMessagesToTemplate($request, $template);
        $objForm = $this->generateCreateNewEventBlogForm($model);
        $template->set('newEventBlogForm', $objForm->generate());

        // Get event report list
        $template->set('arrEventBlogs', $this->getEventBlogs($model));

        return $template->getResponse();
    }

    private function getEventBlogs(ModuleModel $model): array
    {
        // Adapters
        $calendarEventsModelAdapter = $this->framework->getAdapter(CalendarEventsModel::class);
        $dateAdapter = $this->framework->getAdapter(Date::class);
        $configAdapter = $this->framework->getAdapter(Config::class);
        $databaseAdapter = $this->framework->getAdapter(Database::class);
        $pageModelAdapter = $this->framework->getAdapter(PageModel::class);

        $arrEventBlogs = [];

        if (null !== $this->user) {
            // Event blogs
            $objEventBlog = $databaseAdapter->getInstance()
                ->prepare('SELECT * FROM tl_calendar_events_blog WHERE sacMemberId = ? ORDER BY eventStartDate DESC')
                ->execute($this->user->sacMemberId)
            ;

            while ($objEventBlog->next()) {
                $arrEventBlog = $objEventBlog->row();

                // Defaults
                $arrEventBlog['date'] = $dateAdapter->parse($configAdapter->get('dateFormat'), $objEventBlog->eventStartDate);
                $arrEventBlog['canEditBlog'] = false;
                $arrEventBlog['blogLink'] = '';

                // Check if the event blog is still editable
                if ($objEventBlog->eventEndDate + $model->eventBlogTimeSpanForCreatingNew * 24 * 60 * 60 > time()) {
                    if ('1' === $objEventBlog->publishState) {
                        $arrEventBlog['canEditBlog'] = true;
                    }
                }

                // Check if event still exists
                if (($objEvent = $calendarEventsModelAdapter->findByPk($objEventBlog->eventId)) !== null) {
                    // Overwrite date if event still exists in tl_calendar_events
                    $arrEventBlog['date'] = $this->calendarEventsUtil->getEventPeriod($objEvent, $configAdapter->get('dateFormat'), false);
                    $objPage = $pageModelAdapter->findByPk($model->eventBlogFormJumpTo);

                    if (null !== $objPage) {
                        $arrEventBlog['blogLink'] = $this->urlParser->addQueryString('eventId='.$objEventBlog->eventId, $objPage->getFrontendUrl());
                    }
                }
                $arrEventBlogs[] = $arrEventBlog;
            }
        }

        return $arrEventBlogs;
    }

    private function generateCreateNewEventBlogForm(ModuleModel $model): Form
    {
        // Adapters
        $calendarEventsMemberModelAdapter = $this->framework->getAdapter(CalendarEventsMemberModel::class);
        $environmentAdapter = $this->framework->getAdapter(Environment::class);
        $controllerAdapter = $this->framework->getAdapter(Controller::class);
        $inputAdapter = $this->framework->getAdapter(Input::class);
        $pageModelAdapter = $this->framework->getAdapter(PageModel::class);

        $objForm = new Form(
            'form-create-new-event-blog',
            'POST',
        );

        $objForm->setAction($environmentAdapter->get('uri'));

        $arrOptions = [];
        $intStartDateMin = $model->eventBlogTimeSpanForCreatingNew > 0 ? time() - $model->eventBlogTimeSpanForCreatingNew * 24 * 3600 : time();

        $options = [
            'startTstamp' => $intStartDateMin,
            'endTstamp' => time(),
            'blnInstructorRole' => true,
            'blnShowEventsWithParticipationOnly' => true,
        ];

        $arrEvents = $calendarEventsMemberModelAdapter->findEventsByMemberId($this->user->id, $options);

        if (!empty($arrEvents)) {
            foreach ($arrEvents as $event) {
                if (null !== $event['objEvent']) {
                    $objEvent = $event['objEvent'];
                    $arrOptions[$event['id']] = $objEvent->title;
                }
            }
        }

        // Now let's add form fields:
        $objForm->addFormField('event', [
            'label' => 'Tourenbericht zu einem Event erstellen',
            'inputType' => 'select',
            'options' => $arrOptions,
            'eval' => ['mandatory' => true, 'includeBlankOption' => true, 'blankOptionLabel' => 'Bitte wählen...'],
        ]);

        // Let's add  a submit button
        $objForm->addFormField('submit', [
            'label' => 'Weiter',
            'inputType' => 'submit',
        ]);

        if ($objForm->validate()) {
            // Redirect to the page with the event report form
            if ('form-create-new-event-blog' === $inputAdapter->post('FORM_SUBMIT')) {
                $href = '';
                $objWidget = $objForm->getWidget('event');
                $objPage = $pageModelAdapter->findByPk($model->eventBlogFormJumpTo);

                if (null !== $objPage) {
                    $href = $this->urlParser->addQueryString('eventId='.$objWidget->value, $objPage->getFrontendUrl());
                }
                $controllerAdapter->redirect($href);
            }
        }

        return $objForm;
    }

    /**
     * Add messages from session to template.
     */
    private function addMessagesToTemplate(Request $request, FragmentTemplate $template): void
    {
        // Adapters
        $messageAdapter = $this->framework->getAdapter(Message::class);

        $session = $request->getSession();

        if ($messageAdapter->hasInfo()) {
            $template->set('hasInfoMessage', true);
            $message = $session->getFlashBag()->get('contao.FE.info');
            $template->set('infoMessage', $message[0]);
        }

        if ($messageAdapter->hasError()) {
            $template->set('hasErrorMessage', true);
            $message = $session->getFlashBag()->get('contao.FE.error');
            $template->set('errorMessage', $message[0]);
            $template->set('errorMessages', $message);
        }

        $messageAdapter->reset();
    }
}
