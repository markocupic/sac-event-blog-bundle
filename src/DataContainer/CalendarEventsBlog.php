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

namespace Markocupic\SacEventBlogBundle\DataContainer;

use Contao\CalendarEventsModel;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Exception\ResponseException;
use Contao\DataContainer;
use Contao\Environment;
use Contao\Files;
use Contao\FilesModel;
use Contao\Folder;
use Contao\Image;
use Contao\MemberModel;
use Contao\StringUtil;
use Contao\UserModel;
use Doctrine\DBAL\Connection;
use Markocupic\PhpOffice\PhpWord\MsWordTemplateProcessor;
use Markocupic\SacEventBlogBundle\Config\PublishState;
use Markocupic\SacEventBlogBundle\Model\CalendarEventsBlogModel;
use Markocupic\SacEventToolBundle\CalendarEventsHelper;
use Markocupic\SacEventToolBundle\Config\EventExecutionState;
use Markocupic\SacEventToolBundle\Download\BinaryFileDownload;
use Markocupic\ZipBundle\Zip\Zip;
use PhpOffice\PhpWord\Exception\CopyFileException;
use PhpOffice\PhpWord\Exception\CreateTemporaryFileException;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use use Symfony\Bundle\SecurityBundle\Security;

class CalendarEventsBlog
{
    public function __construct(
        private readonly Security $security,
        private readonly Connection $connection,
        private readonly RequestStack $requestStack,
        private readonly BinaryFileDownload $binaryFileDownload,
        private readonly string $projectDir,
        private readonly string $tempDir,
        private readonly string $eventBlogDocxExportTemplate,
        private readonly string $locale,
    ) {
    }

    /**
     * @throws \Exception
     */
    #[AsCallback(table: 'tl_calendar_events_blog', target: 'config.onload')]
    public function route(): void
    {
        $request = $this->requestStack->getCurrentRequest();

        $id = $request->query->get('id');

        if ($id && 'exportBlog' === $request->query->get('action')) {
            if (null !== ($objBlog = CalendarEventsBlogModel::findByPk($id))) {
                throw new ResponseException($this->exportBlog($objBlog));
            }
        }
    }

    #[AsCallback(table: 'tl_calendar_events_blog', target: 'config.onload')]
    public function adjustDcaFields(): void
    {
        // Overwrite readonly attribute for admins
        if ($this->security->isGranted('ROLE_ADMIN')) {
            $fields = $GLOBALS['TL_DCA']['tl_calendar_events_blog']['fields'];

            foreach ($fields as $strFieldName => $arrField) {
                if (isset($arrField['eval']['readonly']) && $arrField['eval']['readonly']) {
                    $GLOBALS['TL_DCA']['tl_calendar_events_blog']['fields'][$strFieldName]['eval']['readonly'] = false;
                }
            }
        }
    }

    /**
     * @throws \Exception
     */
    #[AsCallback(table: 'tl_calendar_events_blog', target: 'config.onload')]
    public function keepBlogUpToDate(): void
    {
        // Delete old and unpublished blogs
        $limit = time() - 60 * 60 * 24 * 30;

        $this->connection->executeStatement(
            'DELETE FROM tl_calendar_events_blog WHERE tstamp < ? AND publishState < ?',
            [$limit, PublishState::PUBLISHED],
        );

        // Delete unfinished blogs older the 14 days
        $limit = time() - 60 * 60 * 24 * 14;

        $this->connection->executeStatement(
            'DELETE FROM tl_calendar_events_blog WHERE tstamp < ? AND text = ? AND youTubeId = ? AND multiSRC = ?',
            [$limit, '', '', null]
        );

        // Keep blogs up to date, if e.g. events have been renamed
        $stmt = $this->connection->executeQuery('SELECT * FROM tl_calendar_events_blog', []);

        while (false !== ($arrBlog = $stmt->fetchAssociative())) {
            $blog = CalendarEventsBlogModel::findByPk($arrBlog['id']);
            $event = $blog->getRelated('eventId');

            if (null === $event) {
                continue;
            }

            $blog->eventTitle = $event->title;
            $blog->substitutionEvent = EventExecutionState::STATE_NOT_EXECUTED_LIKE_PREDICTED === $event->executionState ? $event->eventSubstitutionText : '';
            $blog->eventSubstitutionText = EventExecutionState::STATE_NOT_EXECUTED_LIKE_PREDICTED === $event->executionState ? $event->eventSubstitutionText : '';
            $blog->eventStartDate = $event->startDate;
            $blog->eventEndDate = $event->endDate;
            $blog->organizers = $event->organizers;

            $aDates = [];
            $arrDates = StringUtil::deserialize($event->eventDates, true);

            foreach ($arrDates as $arrDate) {
                $aDates[] = $arrDate['new_repeat'];
            }

            $blog->eventDates = serialize($aDates);
            $blog->save();
        }
    }

    /**
     * Add an image to each record.
     */
    #[AsCallback(table: 'tl_calendar_events_blog', target: 'list.label.label')]
    public function addIcon(array $row, string $label, DataContainer $dc, array $args): array
    {
        $image = 'member';
        $disabled = false;

        if (PublishState::PUBLISHED !== (int) $row['publishState']) {
            $image .= '_';
            $disabled = true;
        }

        $args[0] = sprintf(
            '<div class="list_icon_new" style="background-image:url(\'%s\')" data-icon="%s" data-icon-disabled="%s">&nbsp;</div>',
            Image::getPath($image),
            Image::getPath($disabled ? $image : rtrim($image, '_')),
            Image::getPath(rtrim($image, '_').'_')
        );

        return $args;
    }

    /**
     * @throws CopyFileException
     * @throws CreateTemporaryFileException
     */
    private function exportBlog(CalendarEventsBlogModel $objBlog): BinaryFileResponse
    {
        $objEvent = CalendarEventsModel::findByPk($objBlog->eventId);

        if (null === $objEvent) {
            throw new \Exception('Event not found.');
        }

        $docxTemplateSrc = Path::makeAbsolute($this->eventBlogDocxExportTemplate, $this->projectDir);

        if (!is_file($docxTemplateSrc)) {
            throw new \Exception('Template file not found.');
        }

        // target dir & file
        $targetDir = sprintf('system/tmp/blog_%s_%s', $objBlog->id, time());
        $imageDir = sprintf('%s/images', $targetDir);

        // Create folder
        new Folder($imageDir);

        $targetFile = sprintf('%s/event_blog_%s.docx', $targetDir, $objBlog->id);
        $targetFile = Path::makeAbsolute($targetFile, $this->projectDir);
        $objPhpWord = new MsWordTemplateProcessor($docxTemplateSrc, $targetFile);

        // Organizers
        $arrOrganizers = CalendarEventsHelper::getEventOrganizersAsArray($objEvent);
        $strOrganizers = implode(', ', $arrOrganizers);

        // Instructors
        $mainInstructorName = CalendarEventsHelper::getMainInstructorName($objEvent);
        $mainInstructorEmail = '';

        if (null !== ($objInstructor = UserModel::findByPk($objEvent->mainInstructor))) {
            $mainInstructorEmail = $objInstructor->email;
        }

        $objMember = MemberModel::findBySacMemberId($objBlog->sacMemberId);
        $strAuthorEmail = '';

        if (null !== $objMember) {
            $strAuthorEmail = $objMember->email;
        }

        // Event dates
        $arrEventDates = CalendarEventsHelper::getEventTimestamps($objEvent);
        $arrEventDates = array_map(
            static fn ($tstamp) => date('Y-m-d', (int) $tstamp),
            $arrEventDates
        );
        $strEventDates = implode("\r\n", $arrEventDates);

        // Checked by instructor
        $strCheckedByInstructor = $objBlog->checkedByInstructor ? 'Ja' : 'Nein';

        // Backend url
        $strUrlBackend = sprintf(
            '%s/contao?do=sac_calendar_events_blog_tool&act=edit&id=%s',
            Environment::get('url'),
            $objBlog->id
        );

        // Key data
        $arrKeyData = [];

        if (!empty($objBlog->tourTechDifficulty)) {
            $arrKeyData[] = $objBlog->tourTechDifficulty;
        }

        if (!empty($objBlog->tourProfile)) {
            $arrKeyData[] = $objBlog->tourProfile;
        }

        // tourTypes
        $arrTourTypes = CalendarEventsHelper::getTourTypesAsArray($objEvent, 'title');

        $options = ['multiline' => true];
        $objPhpWord->replace('checkedByInstructor', $strCheckedByInstructor, $options);
        $objPhpWord->replace('title', $objBlog->title, $options);
        $objPhpWord->replace('text', $objBlog->text, $options);
        $objPhpWord->replace('authorName', $objBlog->authorName, $options);
        $objPhpWord->replace('sacMemberId', $objBlog->sacMemberId, $options);
        $objPhpWord->replace('authorEmail', $strAuthorEmail, $options);
        $objPhpWord->replace('dateAdded', date('Y-m-d', (int) $objBlog->dateAdded), $options);
        $objPhpWord->replace('tourTypes', implode(', ', $arrTourTypes), $options);
        $objPhpWord->replace('organizers', $strOrganizers, $options);
        $objPhpWord->replace('eventSubstitutionText', $objBlog->eventSubstitutionText ?: 'nein', $options);
        $objPhpWord->replace('mainInstructorName', $mainInstructorName, $options);
        $objPhpWord->replace('mainInstructorEmail', $mainInstructorEmail, $options);
        $objPhpWord->replace('eventDates', $strEventDates, $options);
        $objPhpWord->replace('tourWaypoints', $objBlog->tourWaypoints, $options);
        $objPhpWord->replace('keyData', implode("\r\n", $arrKeyData), $options);
        $objPhpWord->replace('tourHighlights', $objBlog->tourHighlights, $options);
        $objPhpWord->replace('tourPublicTransportInfo', $objBlog->tourPublicTransportInfo, $options);

        // Footer
        $objPhpWord->replace('eventId', $objEvent->id);
        $objPhpWord->replace('blogId', $objBlog->id);
        $objPhpWord->replace('urlBackend', htmlentities($strUrlBackend));

        // Images
        $arrImages = StringUtil::deserialize($objBlog->multiSRC, true);

        $i = 0;

        if (!empty($arrImages)) {
            $objPhpWord->cloneBlock('BLOCK_IMAGES');

            $objFiles = FilesModel::findMultipleByUuids($arrImages);

            while ($objFiles->next()) {
                if (!is_file($this->projectDir.'/'.$objFiles->path)) {
                    continue;
                }

                ++$i;

                Files::getInstance()->copy($objFiles->path, $imageDir.'/'.$objFiles->name);

                $options = ['multiline' => false];

                $objPhpWord->createClone('i');
                $objPhpWord->addToClone('i', 'i', $i, $options);
                $objPhpWord->addToClone('i', 'fileName', $objFiles->name, $options);

                $arrMeta = $this->getMeta($objFiles->current(), $this->locale);
                $objPhpWord->addToClone('i', 'photographerName', $arrMeta['photographer'], $options);
                $objPhpWord->addToClone('i', 'imageCaption', $arrMeta['caption'], $options);
            }
        }

        if (!$i) {
            $objPhpWord->deleteBlock('BLOCK_IMAGES');
        }

        $objPhpWord->generate();

        $zipSrc = sprintf(
            '%s/%s/blog_%s_%s.zip',
            $this->projectDir,
            $this->tempDir,
            $objBlog->id,
            time()
        );

        // Create zip archive
        (new Zip())
            ->ignoreDotFiles(false)
            ->stripSourcePath($this->projectDir.'/'.$targetDir)
            ->addDirRecursive($this->projectDir.'/'.$targetDir)
            ->run($zipSrc)
        ;

        return $this->binaryFileDownload->sendFileToBrowser($zipSrc, basename($zipSrc));
    }

    private function getMeta(FilesModel $objFile, string $lang = 'en'): array
    {
        $arrMeta = StringUtil::deserialize($objFile->meta, true);

        if (!isset($arrMeta[$lang]['caption'])) {
            $arrMeta[$lang]['caption'] = '';
        }

        if (!isset($arrMeta[$lang]['photographer'])) {
            $arrMeta[$lang]['photographer'] = '';
        }

        return $arrMeta[$lang];
    }
}
