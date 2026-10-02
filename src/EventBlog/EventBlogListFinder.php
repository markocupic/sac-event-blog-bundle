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

namespace Markocupic\SacEventBlogBundle\EventBlog;

use Contao\ContentModel;
use Doctrine\DBAL\Connection;
use Markocupic\SacEventBlogBundle\Config\PublishState;

/**
 * Finds the event blogs of an "event blog list" content element in the order they are listed.
 * Used by the list element (items and pagination) and by the reader element (prev/next navigation in the modal window).
 */
class EventBlogListFinder
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Return the IDs of all published event blogs of the given list element,
     * newest first and limited to the configured total number of items.
     *
     * @return list<int>
     */
    public function findIds(ContentModel $listElement): array
    {
        $organizers = $this->deserialize($listElement->eventBlogOrganizers);

        if ([] === $organizers) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, organizers FROM tl_calendar_events_blog WHERE publishState = ? ORDER BY dateAdded DESC, id DESC',
            [PublishState::PUBLISHED],
        );

        $ids = [];

        foreach ($rows as $row) {
            if ([] !== array_intersect($this->deserialize($row['organizers']), $organizers)) {
                $ids[] = (int) $row['id'];
            }
        }

        $limit = (int) $listElement->eventBlogLimit;

        if ($limit > 0) {
            $ids = \array_slice($ids, 0, $limit);
        }

        return $ids;
    }

    /**
     * @return array{prev: int|null, next: int|null}
     */
    public function findSiblings(ContentModel $listElement, int $blogId): array
    {
        $ids = $this->findIds($listElement);
        $index = array_search($blogId, $ids, true);

        if (false === $index) {
            return ['prev' => null, 'next' => null];
        }

        return [
            'prev' => $ids[$index - 1] ?? null,
            'next' => $ids[$index + 1] ?? null,
        ];
    }

    /**
     * @return list<string>
     */
    private function deserialize(mixed $value): array
    {
        if (!\is_string($value) || '' === $value) {
            return [];
        }

        $array = @unserialize($value, ['allowed_classes' => false]);

        return \is_array($array) ? array_map(strval(...), array_values($array)) : [];
    }
}
