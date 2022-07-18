<?php

declare(strict_types=1);

/*
 * This file is part of SAC Event Tool Bundle.
 *
 * (c) Marko Cupic 2022 <m.cupic@gmx.ch>
 * @license GPL-3.0-or-later
 * For the full copyright and license information,
 * please view the LICENSE file that was distributed with this source code.
 * @link https://github.com/markocupic/sac-event-tool-bundle
 */

namespace Markocupic\SacEventBlogBundle\Controller\FrontendModule;

use Contao\CalendarEventsMemberModel;
use Contao\CalendarEventsModel;
use Contao\Config;
use Contao\Controller;
use Contao\CoreBundle\Controller\FrontendModule\AbstractFrontendModuleController;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\ServiceAnnotation\FrontendModule;
use Contao\Database;
use Contao\Date;
use Contao\Environment;
use Contao\FrontendUser;
use Contao\Input;
use Contao\Message;
use Contao\ModuleModel;
use Contao\PageModel;
use Contao\System;
use Contao\Template;
use Contao\Validator;
use Haste\Form\Form;
use Haste\Util\Url;
use Markocupic\SacEventToolBundle\CalendarEventsHelper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Security\Core\Security;

/**
 * @FrontendModule(MemberDashboardEventBlogListController::TYPE, category="sac_event_tool_frontend_modules")
 */
class MemberDashboardEventBlogListController extends AbstractFrontendModuleController
{
    public const TYPE = 'member_dashboard_event_blog_list';

    private ContaoFramework $framework;
    private Security $security;
    protected FrontendUser|null $user;

    public function __construct(ContaoFramework $framework, Security $security)
    {
        $this->framework = $framework;
        $this->security = $security;

        // Get logged in member
        if (($user = $this->security->getUser()) instanceof FrontendUser) {
            $this->user = $user;
        }
    }

    public function __invoke(Request $request, ModuleModel $model, string $section, array $classes = null, PageModel $page = null): Response
    {
        if (null !== $page) {
            // Neither cache nor search page
            $page->noSearch = 1;
            $page->cache = 0;
        }

        // Call the parent method
        return parent::__invoke($request, $model, $section, $classes);
    }

    protected function getResponse(Template $template, ModuleModel $model, Request $request): Response|null
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
            $messageAdapter->addInfo('Leider wurde für dieses Konto in der Datenbank keine E-Mail-Adresse gefunden. Daher stehen einige Funktionen nur eingeschränkt zur Verfügung. Bitte hinterlegen Sie auf der Internetseite des Zentralverbands Ihre E-Mail-Adresse.');
        }

        // Get the time span for creating a new event blog
        $template->eventBlogTimeSpanForCreatingNew = $model->eventBlogTimeSpanForCreatingNew;

        // Add messages to template
        $this->addMessagesToTemplate($template);
        $objForm = $this->generateCreateNewEventBlogForm($model);
        $template->newEventBlogForm = $objForm->generate();

        // Get event report list
        $template->arrEventBlogs = $this->getEventBlogs($model);

        return $template->getResponse();
    }

    protected function getEventBlogs(ModuleModel $model): array
    {
        // Adapters
        $calendarEventsModelAdapter = $this->framework->getAdapter(CalendarEventsModel::class);
        $dateAdapter = $this->framework->getAdapter(Date::class);
        $calendarEventsHelperAdapter = $this->framework->getAdapter(CalendarEventsHelper::class);
        $configAdapter = $this->framework->getAdapter(Config::class);
        $databaseAdapter = $this->framework->getAdapter(Database::class);
        $pageModelAdapter = $this->framework->getAdapter(PageModel::class);
        $urlAdapter = $this->framework->getAdapter(Url::class);

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
                    $arrEventBlog['date'] = $calendarEventsHelperAdapter->getEventPeriod($objEvent, $configAdapter->get('dateFormat'), false);
                    $objPage = $pageModelAdapter->findByPk($model->eventBlogFormJumpTo);

                    if (null !== $objPage) {
                        $arrEventBlog['blogLink'] = $urlAdapter->addQueryString('eventId='.$objEventBlog->eventId, $objPage->getFrontendUrl());
                    }
                }
                $arrEventBlogs[] = $arrEventBlog;
            }
        }

        return $arrEventBlogs;
    }

    protected function generateCreateNewEventBlogForm(ModuleModel $model): Form
    {
        // Adapters
        $calendarEventsMemberModelAdapter = $this->framework->getAdapter(CalendarEventsMemberModel::class);
        $environmentAdapter = $this->framework->getAdapter(Environment::class);
        $controllerAdapter = $this->framework->getAdapter(Controller::class);
        $urlAdapter = $this->framework->getAdapter(Url::class);
        $inputAdapter = $this->framework->getAdapter(Input::class);
        $pageModelAdapter = $this->framework->getAdapter(PageModel::class);

        $objForm = new Form(
            'form-create-new-event-blog',
            'POST',
            function ($objHaste) {
                $inputAdapter = $this->framework->getAdapter(Input::class);

                return $inputAdapter->post('FORM_SUBMIT') === $objHaste->getFormId();
            }
        );

        $objForm->setFormActionFromUri($environmentAdapter->get('uri'));

        $arrOptions = [];
        $intStartDateMin = $model->eventBlogTimeSpanForCreatingNew > 0 ? time() - $model->eventBlogTimeSpanForCreatingNew * 24 * 3600 : time();
        $arrEvents = $calendarEventsMemberModelAdapter->findEventsByMemberId($this->user->id, [], $intStartDateMin, time(), true);

        if (!empty($arrEvents) && \is_array($arrEvents)) {
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
            'eval' => ['mandatory' => true],
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
                    $href = $urlAdapter->addQueryString('eventId='.$objWidget->value, $objPage->getFrontendUrl());
                }
                $controllerAdapter->redirect($href);
            }
        }

        return $objForm;
    }

    /**
     * Add messages from session to template.
     */
    private function addMessagesToTemplate(Template $template): void
    {
        // Adapters
        $messageAdapter = $this->framework->getAdapter(Message::class);
        $systemAdapter = $this->framework->getAdapter(System::class);

        if ($messageAdapter->hasInfo()) {
            $template->hasInfoMessage = true;
            $session = $systemAdapter->getContainer()->get('session')->getFlashBag()->get('contao.FE.info');
            $template->infoMessage = $session[0];
        }

        if ($messageAdapter->hasError()) {
            $template->hasErrorMessage = true;
            $session = $systemAdapter->getContainer()->get('session')->getFlashBag()->get('contao.FE.error');
            $template->errorMessage = $session[0];
            $template->errorMessages = $session;
        }

        $messageAdapter->reset();
    }
}
