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

namespace Markocupic\SacEventBlogBundle\Upload\Exception;

class ImageUploadException extends \RuntimeException
{
    public function __construct(
        string $reason,
        private string $translatableText,
    ) {
        parent::__construct($reason);
    }

    public function getTranslatableText(): string
    {
        return $this->translatableText;
    }

    public function setTranslatableText(string $translatableText): void
    {
        $this->translatableText = $translatableText;
    }
}
