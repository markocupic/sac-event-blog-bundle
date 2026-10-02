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

namespace Markocupic\SacEventBlogBundle\Migration;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Markocupic\SacEventBlogBundle\Controller\ContentElement\EventBlogListController;
use Markocupic\SacEventBlogBundle\Controller\ContentElement\EventBlogReaderController;
use Markocupic\SacEventBlogBundle\Controller\ContentElement\MemberDashboardEventBlogListController;
use Markocupic\SacEventBlogBundle\Controller\ContentElement\MemberDashboardEventBlogWriteController;

/**
 * The event blog frontend modules have been converted to content elements.
 *
 * This migration converts every content element of type "module" that embeds one
 * of the former event blog frontend modules into the corresponding event blog
 * content element (the type names did not change) and copies the module settings.
 *
 * Modules that are embedded in a page layout or by an insert tag can not be converted
 * automatically. They are listed in the migration message. The tl_module records are
 * never deleted by this migration.
 */
class FrontendModulesToContentElementsMigration extends AbstractMigration
{
    private const array TYPES = [
        EventBlogListController::TYPE,
        EventBlogReaderController::TYPE,
        MemberDashboardEventBlogListController::TYPE,
        MemberDashboardEventBlogWriteController::TYPE,
    ];

    /**
     * tl_content field => tl_module field.
     */
    private const array FIELD_MAPPING = [
        EventBlogListController::TYPE => [
            'eventBlogOrganizers' => 'eventBlogOrganizers',
            'eventBlogJumpTo' => 'jumpTo',
            'eventBlogLimit' => 'eventBlogLimit',
            'perPage' => 'perPage',
        ],
        EventBlogReaderController::TYPE => [],
        MemberDashboardEventBlogListController::TYPE => [
            'eventBlogTimeSpanForCreatingNew' => 'eventBlogTimeSpanForCreatingNew',
            'eventBlogFormJumpTo' => 'eventBlogFormJumpTo',
        ],
        MemberDashboardEventBlogWriteController::TYPE => [
            'eventBlogReaderPage' => 'eventBlogReaderPage',
            'eventBlogMaxImageWidth' => 'eventBlogMaxImageWidth',
            'eventBlogMaxImageHeight' => 'eventBlogMaxImageHeight',
            'eventBlogMaxImageFileSize' => 'eventBlogMaxImageFileSize',
            'eventBlogTimeSpanForCreatingNew' => 'eventBlogTimeSpanForCreatingNew',
            'eventBlogOnPublishNotification' => 'eventBlogOnPublishNotification',
        ],
    ];

    /**
     * The new tl_content columns (same definitions as in contao/dca/tl_content.php).
     * They are created here, because the migration runs before the database schema update.
     */
    private const array CONTENT_COLUMNS = [
        'eventBlogOrganizers' => 'blob NULL',
        'eventBlogJumpTo' => 'int(10) unsigned NOT NULL default 0',
        'eventBlogReaderElement' => 'int(10) unsigned NOT NULL default 0',
        'eventBlogLimit' => 'smallint(5) unsigned NOT NULL default 0',
        'eventBlogTimeSpanForCreatingNew' => 'int(10) unsigned NOT NULL default 0',
        'eventBlogFormJumpTo' => 'int(10) unsigned NOT NULL default 0',
        'eventBlogReaderPage' => 'int(10) unsigned NOT NULL default 0',
        'eventBlogMaxImageWidth' => 'smallint(5) unsigned NOT NULL default 2500',
        'eventBlogMaxImageHeight' => 'smallint(5) unsigned NOT NULL default 1500',
        'eventBlogMaxImageFileSize' => 'int(10) unsigned NOT NULL default 12000000',
        'eventBlogOnPublishNotification' => 'int(10) unsigned NOT NULL default 0',
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function getName(): string
    {
        return 'SAC Event Blog Bundle: Convert the event blog frontend modules into content elements';
    }

    public function shouldRun(): bool
    {
        $schemaManager = $this->connection->createSchemaManager();

        if (!$schemaManager->tablesExist(['tl_module', 'tl_content'])) {
            return false;
        }

        return [] !== $this->getContentElementsToConvert();
    }

    public function run(): MigrationResult
    {
        $this->addMissingContentColumns();

        $moduleColumns = array_change_key_case($this->connection->createSchemaManager()->listTableColumns('tl_module'));
        $modules = $this->getModules();

        // Former module ID => new content element ID
        $moduleToContent = [];

        // New list content element ID => former reader module ID
        $listElements = [];

        $messages = [];
        $converted = 0;

        foreach ($this->getContentElementsToConvert() as $content) {
            $module = $modules[(int) $content['module']];
            $type = $module['type'];

            $set = [
                'type' => $type,
                'module' => 0,
                'tstamp' => time(),
            ];

            foreach (self::FIELD_MAPPING[$type] as $contentField => $moduleField) {
                if (isset($moduleColumns[strtolower($moduleField)])) {
                    $set[$contentField] = $module[$moduleField];
                }
            }

            // Take over the headline from the module if the content element has none
            if ('' === $this->getHeadlineValue($content['headline'] ?? null) && '' !== $this->getHeadlineValue($module['headline'] ?? null)) {
                $set['headline'] = $module['headline'];
            }

            // Take over the CSS id/class from the module if the content element has none
            if (!$this->hasCssId($content['cssID'] ?? null) && $this->hasCssId($module['cssID'] ?? null)) {
                $set['cssID'] = $module['cssID'];
            }

            // Take over the member group protection from the module
            if (!$content['protected'] && !empty($module['protected'])) {
                $set['protected'] = $module['protected'];
                $set['groups'] = $module['groups'];
            }

            $this->connection->update('tl_content', $set, ['id' => $content['id']]);
            ++$converted;

            $moduleToContent[(int) $module['id']] ??= (int) $content['id'];

            if (EventBlogListController::TYPE === $type) {
                $listElements[(int) $content['id']] = (int) ($module['eventBlogReaderModule'] ?? 0);
            }

            if (!empty($module['customTpl'])) {
                $messages[] = \sprintf('Modul "%s" (ID %d) verwendet das eigene Template "%s". Bitte als Variante unter "content_element/%s/" neu anlegen und im Inhaltselement ID %d zuweisen.', $module['name'], $module['id'], $module['customTpl'], $type, $content['id']);
            }
        }

        // Link the list elements to their reader element
        foreach ($listElements as $listElementId => $readerModuleId) {
            $readerElementId = $moduleToContent[$readerModuleId] ?? null;

            // The reader module was only used by the API and was not embedded in an article:
            // Create a hidden reader content element next to the list element.
            if (null === $readerElementId && isset($modules[$readerModuleId]) && EventBlogReaderController::TYPE === $modules[$readerModuleId]['type']) {
                $listElement = $this->connection->fetchAssociative('SELECT pid, ptable, sorting FROM tl_content WHERE id = ?', [$listElementId]);

                $this->connection->insert('tl_content', [
                    'pid' => $listElement['pid'],
                    'ptable' => $listElement['ptable'],
                    'sorting' => (int) $listElement['sorting'] + 1,
                    'tstamp' => time(),
                    'type' => EventBlogReaderController::TYPE,
                    'invisible' => '1',
                ]);

                $readerElementId = (int) $this->connection->lastInsertId();
                $moduleToContent[$readerModuleId] = $readerElementId;

                $messages[] = \sprintf('Für das Listen-Element ID %d wurde das versteckte Reader-Element ID %d angelegt.', $listElementId, $readerElementId);
            }

            if (null === $readerElementId) {
                $messages[] = \sprintf('Für das Listen-Element ID %d konnte kein Reader-Element ermittelt werden. Bitte im Backend auswählen.', $listElementId);

                continue;
            }

            $this->connection->update('tl_content', ['eventBlogReaderElement' => $readerElementId], ['id' => $listElementId]);
        }

        // Modules that are embedded elsewhere (page layout, insert tag) have not been converted
        foreach ($modules as $module) {
            if (!isset($moduleToContent[(int) $module['id']])) {
                $messages[] = \sprintf('Modul "%s" (ID %d, Typ %s) wurde nicht automatisch migriert (eingebunden im Seitenlayout, per Insert-Tag oder nicht verwendet).', $module['name'], $module['id'], $module['type']);
            }
        }

        $messages[] = 'Die Frontend-Module in tl_module wurden nicht gelöscht. Bitte nach der Kontrolle manuell entfernen.';

        return $this->createResult(
            true,
            \sprintf('%d Inhaltselement(e) vom Typ "Modul" wurden in SAC-Tourenberichte-Inhaltselemente umgewandelt. ', $converted).implode(' ', $messages),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getModules(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM tl_module WHERE type IN (?)',
            [self::TYPES],
            [ArrayParameterType::STRING],
        );

        $modules = [];

        foreach ($rows as $row) {
            $modules[(int) $row['id']] = $row;
        }

        return $modules;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getContentElementsToConvert(): array
    {
        return $this->connection->fetchAllAssociative(
            "SELECT c.* FROM tl_content c INNER JOIN tl_module m ON m.id = c.module WHERE c.type = 'module' AND m.type IN (?) ORDER BY c.id",
            [self::TYPES],
            [ArrayParameterType::STRING],
        );
    }

    private function addMissingContentColumns(): void
    {
        $columns = array_change_key_case($this->connection->createSchemaManager()->listTableColumns('tl_content'));

        foreach (self::CONTENT_COLUMNS as $name => $definition) {
            if (!isset($columns[strtolower($name)])) {
                $this->connection->executeStatement(\sprintf('ALTER TABLE tl_content ADD %s %s', $this->connection->quoteIdentifier($name), $definition));
            }
        }
    }

    private function getHeadlineValue(string|null $headline): string
    {
        $value = $this->unserialize($headline);

        if (\is_array($value)) {
            return trim((string) ($value['value'] ?? ''));
        }

        return trim((string) $headline);
    }

    private function hasCssId(string|null $cssId): bool
    {
        $value = $this->unserialize($cssId);

        return \is_array($value) && '' !== trim(implode('', array_map(strval(...), $value)));
    }

    private function unserialize(string|null $value): mixed
    {
        if (null === $value || '' === $value || !str_starts_with($value, 'a:')) {
            return null;
        }

        $result = @unserialize($value, ['allowed_classes' => false]);

        return false === $result ? null : $result;
    }
}
