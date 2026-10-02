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

namespace Markocupic\SacEventBlogBundle\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Doctrine\DBAL\Connection;
use Markocupic\SacEventBlogBundle\Controller\ContentElement\EventBlogReaderController;

class Content
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * List all "event blog reader" content elements
     * that can be used by the "event blog list" content element.
     */
    #[AsCallback(table: 'tl_content', target: 'fields.eventBlogReaderElement.options')]
    public function getEventBlogReaderElementOptions(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT c.id, c.invisible, a.title AS articleTitle, p.title AS pageTitle
             FROM tl_content c
             LEFT JOIN tl_article a ON a.id = c.pid AND c.ptable = :ptable
             LEFT JOIN tl_page p ON p.id = a.pid
             WHERE c.type = :type
             ORDER BY p.title, a.title, c.id',
            [
                'ptable' => 'tl_article',
                'type' => EventBlogReaderController::TYPE,
            ],
        );

        $options = [];

        foreach ($rows as $row) {
            $path = array_filter([$row['pageTitle'] ?? null, $row['articleTitle'] ?? null]);
            $label = ($path ? implode(' › ', $path).' ' : '').'[ID '.$row['id'].']';

            if ($row['invisible']) {
                $label .= ' (versteckt)';
            }

            $options[$row['id']] = $label;
        }

        return $options;
    }
}
