<?php

/*
 * This file is part of SAC Event Blog Bundle.
 *
 * (c) Marko Cupic 2022 <m.cupic@gmx.ch>
 * @license GPL-3.0-or-later
 * For the full copyright and license information,
 * please view the LICENSE file that was distributed with this source code.
 * @link https://github.com/markocupic/sac-event-blog-bundle
 */
declare(strict_types=1);

namespace Markocupic\SacEventBlogBundle\Tests\ContaoManager;

use Contao\ManagerPlugin\Bundle\Config\BundleConfig;
use Contao\ManagerPlugin\Bundle\Parser\DelegatingParser;
use Contao\TestCase\ContaoTestCase;
use Markocupic\SacEventBlogBundle\ContaoManager\Plugin;
use Markocupic\SacEventBlogBundle\MarkocupicSacEventBlogBundle;
use Markocupic\SacEventToolBundle\MarkocupicSacEventToolBundle;

/**
 * Class PluginTest
 *
 * @package Markocupic\SacEventBlogBundle\Tests\ContaoManager
 */
class PluginTest extends ContaoTestCase
{
    /**
     * Test Contao manager plugin class instantiation
     */
    public function testInstantiation(): void
    {
        $this->assertInstanceOf(Plugin::class, new Plugin());
    }

    /**
     * Test returns the bundles
     */
    public function testGetBundles(): void
    {
        $plugin = new Plugin();

        /** @var array $bundles */
        $bundles = $plugin->getBundles(new DelegatingParser());

        $this->assertCount(1, $bundles);
        $this->assertInstanceOf(BundleConfig::class, $bundles[0]);
        $this->assertSame(MarkocupicSacEventBlogBundle::class, $bundles[0]->getName());
        $this->assertSame([MarkocupicSacEventToolBundle::class], $bundles[0]->getLoadAfter());
    }

}
