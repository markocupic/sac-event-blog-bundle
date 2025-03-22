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

namespace Markocupic\SacEventBlogBundle\EventListener\Contao;

use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\StringUtil;
use Contao\Widget;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsHook('addCustomRegexp', priority: 100)]
readonly class AddCustomRegexpListener
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * Use the maxlength1800, maxlength1700, rgxp etc.
     * to validate the max length of an input.
     */
    public function __invoke(string $strRegexp, mixed $varValue, Widget $objWidget): bool
    {
        if (\is_string($varValue) && preg_match('/^maxlength(\d+)/', $strRegexp, $matches)) {
            if (!empty($matches[1]) && $matches[1] > 0) {
                $intMaxLength = $matches[1];
                $intLength = iconv_strlen(StringUtil::revertInputEncoding($varValue));

                if ($intMaxLength < $intLength) {
                    $errMsg = $this->translator->trans('ERR.maxlengthRgxp', [$intLength, $intMaxLength], 'contao_default');
                    $objWidget->addError($errMsg);
                }

                return true;
            }
        }

        return false;
    }
}
