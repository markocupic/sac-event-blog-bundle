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

/*
 * Notification center
 */
$GLOBALS['NOTIFICATION_CENTER']['NOTIFICATION_TYPE']['sac_event_blog_tool'] = [
    'notify_on_new_event_blog' => [
        // Field in tl_nc_language
        'email_sender_name' => [],
        'email_sender_address' => [],
        'recipients' => ['author_email', 'instructor_email', 'webmaster_email'],
        'email_recipient_cc' => ['author_email', 'instructor_email', 'webmaster_email'],
        'email_replyTo' => [],
        'email_subject' => ['hostname', 'blog_title', 'blog_text', 'blog_link_backend', 'blog_link_frontend', 'event_title', 'author_name', 'author_name', 'author_email', 'author_sac_member_id', 'instructor_name', 'instructor_email', 'webmaster_email'],
        'email_text' => ['hostname', 'blog_title', 'blog_text', 'blog_link_backend', 'blog_link_frontend', 'event_title', 'author_name', 'author_name', 'author_email', 'author_sac_member_id', 'instructor_name', 'instructor_email', 'webmaster_email'],
        'email_html' => ['hostname', 'blog_title', 'blog_text', 'blog_link_backend', 'blog_link_frontend', 'event_title', 'author_name', 'author_name', 'author_email', 'author_sac_member_id', 'instructor_name', 'instructor_email', 'webmaster_email'],
        'attachment_tokens' => [],
    ],
];
