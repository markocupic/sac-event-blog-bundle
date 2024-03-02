<?php

declare(strict_types=1);

/*
 * This file is part of SAC Event Blog Bundle.
 *
 * (c) Marko Cupic 2024 <m.cupic@gmx.ch>
 * @license GPL-3.0-or-later
 * For the full copyright and license information,
 * please view the LICENSE file that was distributed with this source code.
 * @link https://github.com/markocupic/sac-event-blog-bundle
 */

use Markocupic\SacEventBlogBundle\ContaoBackendMaintenance\MaintainModuleEventBlog;
use Markocupic\SacEventBlogBundle\Model\CalendarEventsBlogModel;

/*
 * Backend modules
 */
$GLOBALS['BE_MOD']['sac_be_modules']['sac_calendar_events_blog_tool'] = [
    'tables' => ['tl_calendar_events_blog'],
];

/*
 * Register the models
 */
$GLOBALS['TL_MODELS']['tl_calendar_events_blog'] = CalendarEventsBlogModel::class;

/*
 * Backend maintenance: Delete unused event-blog folders
 */
$GLOBALS['TL_PURGE']['custom']['sac_event_blog'] = [
    'callback' => [MaintainModuleEventBlog::class, 'run'],
];
