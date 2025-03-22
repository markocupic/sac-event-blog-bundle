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

namespace Markocupic\SacEventBlogBundle\Cron;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Markocupic\SacEventBlogBundle\Config\PublishState;
use Psr\Log\LoggerInterface;

class DeleteUncompletedBlogsCron
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface|null $contaoGeneralLogger = null,
    ) {
    }

    #[AsCronJob('daily')]
    public function deleteUncompletedBlogs(): void
    {
        // Deleted uncompleted and unpublished event blogs (limit 365 d)
        $limit = time() - 60 * 60 * 24 * 365;

        $arrIds = $this->connection->fetchFirstColumn(
            'SELECT id FROM tl_calendar_events_blog WHERE tstamp < ? AND publishState = ?',
            [$limit, PublishState::STILL_IN_PROGRESS],
            [Types::INTEGER, Types::INTEGER],
        );

        foreach ($arrIds as $id) {
            $this->connection->delete('tl_calendar_events_blog', ['id' => $id]);
            $this->contaoGeneralLogger?->info(sprintf('Deleted uncompleted and unpublished event blog with ID %d.', $id));
        }

        // Deleted empty and uncompleted and unpublished event blogs (limit 60 d)
        $limit = time() - 60 * 60 * 24 * 60;

        $arrIds = $this->connection->fetchFirstColumn(
            'SELECT id FROM tl_calendar_events_blog WHERE tstamp < ? AND text = ? AND youTubeId = ? AND multiSRC = NULL',
            [$limit, '', ''],
            [Types::INTEGER, Types::STRING, Types::STRING],
        );

        foreach ($arrIds as $id) {
            $this->connection->delete('tl_calendar_events_blog', ['id' => $id]);
            $this->contaoGeneralLogger?->info(sprintf('Deleted empty and uncompleted and unpublished event blog with ID %d.', $id));
        }
    }
}
