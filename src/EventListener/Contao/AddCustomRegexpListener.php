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
use Contao\Validator;
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
     * Use the maxlengthDecoded1800, maxlengthDecoded1700,
     * maxlengthDecodedAlpha1700, maxlengthDecodedAlnum1700, maxlengthDecodedExtnd1700 rgxp etc.
     * to validate the max length of a decoded input.
     */
    public function __invoke(string $strRegexp, mixed $varInput, Widget $objWidget): bool
    {
        if (!\is_string($varInput)) {
            return false;
        }

        if (preg_match('/^maxlengthDecoded(\d+)/', $strRegexp, $matches)) {
            if (!empty($matches[1]) && $matches[1] > 0) {
                $intMaxLength = $matches[1];
                $intStrLen = $this->getDecodedStringLength($varInput);

                if ($intMaxLength < $intStrLen) {
                    $errMsg = $this->translator->trans('ERR.maxlengthDecodedRgxp', [$intStrLen, $intMaxLength], 'contao_default');
                    $objWidget->addError($errMsg);
                }

                return false;
            }
        }

        if (preg_match('/^maxlengthDecoded(Alpha|Alnum|Extnd)(\d+)/', $strRegexp, $matches)) {
            if (!empty($matches[2]) && $matches[2] > 0) {
                $rgxp = $matches[1];
                $intMaxLength = $matches[2];
                $intStrLen = $this->getDecodedStringLength($varInput);

                if ($intMaxLength < $intStrLen) {
                    $errMsg = $this->translator->trans('ERR.maxlengthDecodedRgxp', [$intStrLen, $intMaxLength], 'contao_default');
                    $objWidget->addError($errMsg);

                    return false;
                }

                switch ($rgxp) {
                    case 'Alpha':
                        if (!Validator::isAlphabetic($varInput)) {
                            $errMsg = $this->translator->trans('ERR.alpha', [$objWidget->label], 'contao_default');

                            $objWidget->addError($errMsg);
                        }
                        break;

                    case 'Alnum':
                        if (!Validator::isAlphaNumeric($varInput)) {
                            $errMsg = $this->translator->trans('ERR.alnum', [$objWidget->label], 'contao_default');
                            $objWidget->addError($errMsg);
                        }
                        break;

                    case 'Extnd':
                        if (!Validator::isExtendedAlphanumeric(html_entity_decode($varInput, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5))) {
                            $errMsg = $this->translator->trans('ERR.extnd', [$objWidget->label], 'contao_default');
                            $objWidget->addError($errMsg);
                        }
                        break;
                }

                return false;
            }
        }

        return false;
    }

    private function getDecodedStringLength(string $varInput): int
    {
        $strLen = iconv_strlen(StringUtil::revertInputEncoding($varInput));

        if (false === $strLen) {
            throw new \RuntimeException('Could not decode string.');
        }

        return $strLen;
    }
}
