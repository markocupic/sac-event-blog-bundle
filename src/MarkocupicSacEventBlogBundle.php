<?php

declare(strict_types=1);

/*
 * This file is part of SAC Event Blog Bundle.
 *
 * (c) Marko Cupic 2022 <m.cupic@gmx.ch>
 * @license GPL-3.0-or-later
 * For the full copyright and license information,
 * please view the LICENSE file that was distributed with this source code.
 * @link https://github.com/markocupic/sac-event-blog-bundle
 */

namespace Markocupic\SacEventBlogBundle;

use Markocupic\SacEventBlogBundle\DependencyInjection\MarkocupicSacEventBlogExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Class MarkocupicSacEventBlogBundle.
 */
class MarkocupicSacEventBlogBundle extends Bundle
{
    public function getContainerExtension(): MarkocupicSacEventBlogExtension
    {
        return new MarkocupicSacEventBlogExtension();
    }

    /**
     * {@inheritdoc}
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
    }
}
