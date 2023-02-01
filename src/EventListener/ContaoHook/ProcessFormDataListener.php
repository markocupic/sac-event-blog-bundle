<?php

declare(strict_types=1);

namespace Markocupic\SacEventBlogBundle\EventListener\ContaoHook;

use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\Form;
use JetBrains\PhpStorm\NoReturn;

#[AsHook('processFormData')]
class ProcessFormDataListener
{
    #[NoReturn]
 public function __invoke(array $submittedData, array $formData, array|null $files, array $labels, Form $form): void
 {
     //die(print_r($files, true));
 }
}
