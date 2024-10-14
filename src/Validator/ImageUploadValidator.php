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

namespace Markocupic\SacEventBlogBundle\Validator;

use Contao\File;
use Markocupic\SacEventBlogBundle\Upload\Exception\ImageUploadException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Contracts\Translation\TranslatorInterface;

readonly class ImageUploadValidator
{
    public function __construct(
        private TranslatorInterface $translator,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    public function validateFileExists(SplFileInfo $file): void
    {
        if (!is_file($file->getRealPath())) {
            throw new ImageUploadException(sprintf('Could not find file "%s".', $file->getRealPath()), $this->translator->trans('ERR.md_write_event_blog_couldNotFindUploadedImage', [$file->getFilename()], 'contao_default'));
        }
    }

    /**
     * Validate the image dimensions.
     */
    public function validateImageDimensions(SplFileInfo $splFileInfo, int $maxWidth, int $maxHeight): void
    {
        $file = new File(Path::makeRelative($splFileInfo->getRealPath(), $this->projectDir));

        if (!$file->isImage) {
            throw new ImageUploadException('Uploaded file is not an image.', 'ERR.md_write_event_blog_uploadedFileIsNotAnImage');
        }

        // Image exceeds max. image width
        if ($maxWidth > 0 && $file->width > $maxWidth) {
            throw new ImageUploadException(sprintf('Image "%s" exceeds max. width.', $splFileInfo->getRealPath()), $this->translator->trans('ERR.filewidth', [$splFileInfo->getFilename()], 'contao_default'));
        }

        // Image exceeds max. image height
        if ($maxHeight > 0 && $file->height > $maxHeight) {
            throw new ImageUploadException(sprintf('Image "%s" exceeds max. height.', $splFileInfo->getRealPath()), $this->translator->trans('ERR.fileheight', [$splFileInfo->getFilename()], 'contao_default'));
        }
    }

    /**
     * Validate max. file size.
     */
    public function validateSize(SplFileInfo $splFileInfo, int $maxSize): void
    {
        if (false === $splFileInfo->getSize() || $splFileInfo->getSize() > $maxSize) {
            throw new ImageUploadException(sprintf('Uploaded image "%s" exceeds max. file size of "%s" bytes.', $splFileInfo->getRealPath(), $maxSize), $this->translator->trans('ERR.md_write_event_blog_uploadedFileExceedsMaxSize', [$splFileInfo->getFilename(), $maxSize], 'contao_default'));
        }
    }
}
