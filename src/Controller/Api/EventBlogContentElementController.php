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

use Contao\ContentModel;
use Contao\Controller;
use Contao\CoreBundle\Exception\PageNotFoundException;
use Contao\CoreBundle\Framework\ContaoFramework;
use Markocupic\SacEventBlogBundle\Controller\ContentElement\EventBlogListController;
use Markocupic\SacEventBlogBundle\Controller\ContentElement\EventBlogReaderController;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Renders the "event blog list" and the "event blog reader" content elements
 * and returns the HTML as JSON. Used by assets/js/event_blog_list_vue.js.
 *
 * Replaces the former markocupic/contao-api-bundle endpoint (/_api/{apiKey}/{moduleId}).
 */
class EventBlogContentElementController extends AbstractController
{
    public const string ROUTE = 'sac_event_blog_content_element';

    public function __construct(private readonly ContaoFramework $framework)
    {
    }

    #[Route('/_event_blog/element/{id}', name: self::ROUTE, requirements: ['id' => '\d+'], defaults: ['_scope' => 'frontend', '_token_check' => false], methods: ['GET'])]
    public function __invoke(int $id, Request $request): JsonResponse
    {
        $this->framework->initialize();

        // The Vue.js app passes the page language as query parameter
        $locale = (string) $request->query->get('_locale', '');

        if (preg_match('/^[a-z]{2}([_-][a-zA-Z]{2})?$/', $locale)) {
            $request->setLocale(str_replace('-', '_', $locale));
        }

        $contentModelAdapter = $this->framework->getAdapter(ContentModel::class);
        $controllerAdapter = $this->framework->getAdapter(Controller::class);

        $model = $contentModelAdapter->findById($id);

        if (null === $model || !\in_array($model->type, [EventBlogListController::TYPE, EventBlogReaderController::TYPE], true)) {
            return $this->createJsonResponse($id, null, Response::HTTP_NOT_FOUND);
        }

        if (EventBlogListController::TYPE === $model->type && !$this->isPublished($model)) {
            return $this->createJsonResponse($id, null, Response::HTTP_NOT_FOUND);
        }

        if (EventBlogReaderController::TYPE === $model->type) {
            // The reader element is only a configuration container for the modal window
            // of the list element and may therefore be hidden in the article.
            // This change is never saved to the database.
            $model->invisible = false;
            $model->start = '';
            $model->stop = '';
        }

        try {
            $html = $controllerAdapter->getContentElement($model);
        } catch (PageNotFoundException) {
            return $this->createJsonResponse($id, null, Response::HTTP_NOT_FOUND);
        }

        return $this->createJsonResponse($id, '' !== trim((string) $html) ? (string) $html : null);
    }

    private function isPublished(ContentModel $model): bool
    {
        if ($model->invisible) {
            return false;
        }

        $time = time();

        if ($model->start && (int) $model->start > $time) {
            return false;
        }

        return !($model->stop && (int) $model->stop <= $time);
    }

    private function createJsonResponse(int $id, string|null $html, int $status = Response::HTTP_OK): JsonResponse
    {
        return new JsonResponse(
            [
                'id' => $id,
                'compiledHTML' => $html,
            ],
            $status,
        );
    }
}
