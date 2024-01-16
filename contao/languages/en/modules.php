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

use Markocupic\SacEventBlogBundle\Controller\FrontendModule\EventBlogListController;
use Markocupic\SacEventBlogBundle\Controller\FrontendModule\EventBlogReaderController;
use Markocupic\SacEventBlogBundle\Controller\FrontendModule\MemberDashboardEventBlogListController;
use Markocupic\SacEventBlogBundle\Controller\FrontendModule\MemberDashboardEventBlogWriteController;

// Backend Modules
$GLOBALS['TL_LANG']['MOD']['sac_calendar_events_blog_tool'] = ['Touren-/Kursberichte Tool'];

// Frontend modules
$GLOBALS['TL_LANG']['FMD'][MemberDashboardEventBlogListController::TYPE] = ['SAC Mitgliederkonto Dashboard - Meine Tourenberichte'];
$GLOBALS['TL_LANG']['FMD'][MemberDashboardEventBlogWriteController::TYPE] = ['SAC Mitgliederkonto Dashboard - Tourenbericht schreiben'];
$GLOBALS['TL_LANG']['FMD'][EventBlogListController::TYPE] = ['SAC Tourenberichte Listen Modul'];
$GLOBALS['TL_LANG']['FMD'][EventBlogReaderController::TYPE] = ['SAC Tourenberichte Reader Modul'];
