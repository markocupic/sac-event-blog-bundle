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

namespace Markocupic\SacEventBlogBundle\ContaoBackendMaintenance;

use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\Folder;
use Contao\Message;
use Contao\PurgeData;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

readonly class MaintainModuleEventBlog
{
    public function __construct(
        private Connection $connection,
        private string $projectDir,
        private string $eventBlogAssetDir,
        private LoggerInterface|null $logger,
    ) {
    }

    /**
     * Remove image upload folders that aren't assigned to an event blog entry.
     *
     * @throws Exception
     */
    public function run(): void
    {
        $fs = new Filesystem();

        $finder = (new Finder())
            ->directories()
            ->depth('< 1')
            ->notName('tmp')
            ->in($this->projectDir.'/'.$this->eventBlogAssetDir)
        ;

        if ($finder->hasResults()) {
            foreach ($finder as $objFolder) {
                $path = $objFolder->getRealPath();
                $basename = $objFolder->getBasename();

                if (!$this->connection->fetchOne('SELECT id FROM tl_calendar_events_blog WHERE id = ?', [(int) $basename])) {
                    // Log
                    $strText = sprintf('Successfully deleted orphaned event blog media folder "%s".', $path);
                    $this->logger->log(LogLevel::INFO, $strText, ['contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL)]);

                    // Display the confirmation message in the Contao backend maintenance module.
                    Message::addConfirmation($strText, PurgeData::class);

                    // Remove folder from filesystem.
                    $fs->remove($path);
                }
            }
        }

        // Purge the tmp folder
        if (is_dir($this->projectDir.'/'.$this->eventBlogAssetDir.'/tmp')) {
            $objFolder = new Folder($this->eventBlogAssetDir.'/tmp');
            $objFolder->purge();
        }
    }
}
