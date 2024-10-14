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

namespace Markocupic\SacEventBlogBundle\Upload;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Dbafs;
use Contao\File;
use Contao\FilesModel;
use Contao\FrontendUser;
use Contao\PageModel;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Markocupic\SacEventBlogBundle\Model\CalendarEventsBlogModel;
use Markocupic\SacEventBlogBundle\Upload\Exception\ImageUploadException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Contracts\Translation\TranslatorInterface;

readonly class ImageUploadHandler
{
    public function __construct(
        private Connection $connection,
        private ContaoFramework $framework,
        private TranslatorInterface $translator,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    public function moveToTarget(SplFileInfo $splFileInfo, CalendarEventsBlogModel $blogModel, string $destDir): FilesModel
    {
        /** @var FilesModel $filesModelAdapter */
        $filesModelAdapter = $this->framework->getAdapter(FilesModel::class);

        $newId = $this->connection->fetchOne('SELECT MAX(id) AS maxId FROM tl_files') + 1;

        // Generate target Path
        $targetPath = sprintf(
            '%s/event-blog-%s-img-%s.%s',
            Path::makeRelative($destDir, $this->projectDir),
            $blogModel->id,
            $newId,
            strtolower($splFileInfo->getExtension()),
        );

        $tempDir = \dirname($splFileInfo->getRealPath());

        $objFile = new File(Path::makeRelative($splFileInfo->getRealPath(), $this->projectDir));

        // Copy image from temp directory to the target directory
        if (!$objFile->renameTo($targetPath)) {
            throw new ImageUploadException(sprintf('Could not move the uploaded image "%s" to the target "%s" directory.', $splFileInfo->getRealPath(), $targetPath), $this->translator->trans('ERR.md_write_event_blog_couldNotMoveImageToTargetDir', [$splFileInfo->getFilename()], 'contao_default'));
        }

        $this->removeTempDir($tempDir);
        $this->addToDbafs($objFile);

        $filesModel = $filesModelAdapter->findByPath($targetPath);

        if (null === $filesModel) {
            throw new ImageUploadException(sprintf('Could not find image "%s" in the target directory.', $objFile->path), $this->translator->trans('ERR.md_write_event_blog_couldNotFindImageInTargetDir', [$splFileInfo->getFilename()], 'contao_default'));
        }

        $this->updateTargetFolderHashes($objFile);

        return $filesModel;
    }

    /**
     * Add photographer name to meta field.
     */
    public function addMetaData(FilesModel $objFilesModel, FrontendUser $user, PageModel $page): void
    {
        $stringUtilAdapter = $this->framework->getAdapter(StringUtil::class);

        $arrMeta = $stringUtilAdapter->deserialize($objFilesModel->meta, true);

        if (!isset($arrMeta[$page->language])) {
            $arrMeta[$page->language] = [
                'title' => '',
                'alt' => '',
                'link' => '',
                'caption' => '',
                'photographer' => '',
            ];
        }

        $arrMeta[$page->language]['photographer'] = $user->firstname.' '.$user->lastname;
        $objFilesModel->meta = serialize($arrMeta);
        $objFilesModel->tstamp = time();

        $objFilesModel->save();
    }

    public function addUploadedImageToGallery(FilesModel $filesModel, CalendarEventsBlogModel $blogModel): void
    {
        $stringUtilAdapter = $this->framework->getAdapter(StringUtil::class);

        // Save gallery data to tl_calendar_events_blog
        $multiSRC = $stringUtilAdapter->deserialize($blogModel->multiSRC, true);
        $multiSRC[] = $filesModel->uuid;
        $blogModel->multiSRC = serialize($multiSRC);
        $blogModel->save();
    }

    private function removeTempDir(string $tempDir): void
    {
        $fs = new Filesystem();

        $fs->remove($tempDir);
    }

    private function addToDbafs(File $file): void
    {
        /** @var Dbafs $dbafsAdapter */
        $dbafsAdapter = $this->framework->getAdapter(Dbafs::class);

        // Add image to DBAFS
        $dbafsAdapter->addResource($file->path);
    }

    private function updateTargetFolderHashes(File $file): void
    {
        /** @var Dbafs $dbafsAdapter */
        $dbafsAdapter = $this->framework->getAdapter(Dbafs::class);

        $dbafsAdapter->updateFolderHashes(\dirname($file->path));
    }
}
