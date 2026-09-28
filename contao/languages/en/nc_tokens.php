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

use Markocupic\SacEventBlogBundle\NotificationType\OnNewEventBlogNotificationType;

$type = OnNewEventBlogNotificationType::NAME;

// Blog
$GLOBALS['TL_LANG']['nc_tokens'][$type]['blog_title'] = 'Titel des Tourenberichts.';
$GLOBALS['TL_LANG']['nc_tokens'][$type]['blog_text'] = 'Text des Tourenberichts.';
$GLOBALS['TL_LANG']['nc_tokens'][$type]['blog_link_backend'] = 'Absoluter Link zur Bearbeitung des Tourenberichts im Backend.';
$GLOBALS['TL_LANG']['nc_tokens'][$type]['blog_link_frontend'] = 'Absoluter Link zur Vorschau des Tourenberichts im Frontend. Leer, wenn im Modul keine Leserseite hinterlegt ist.';
$GLOBALS['TL_LANG']['nc_tokens'][$type]['hostname'] = 'Hostname der Website (z.B. "www.sac-pilatus.ch").';

// Event
$GLOBALS['TL_LANG']['nc_tokens'][$type]['event_id'] = 'ID des Events, zu dem der Tourenbericht verfasst wurde.';
$GLOBALS['TL_LANG']['nc_tokens'][$type]['event_title'] = 'Titel des Events, zu dem der Tourenbericht verfasst wurde.';

// Author
$GLOBALS['TL_LANG']['nc_tokens'][$type]['author_name'] = 'Vor- und Nachname des Autors.';
$GLOBALS['TL_LANG']['nc_tokens'][$type]['author_email'] = 'E-Mail-Adresse des Autors.';
$GLOBALS['TL_LANG']['nc_tokens'][$type]['author_sac_member_id'] = 'SAC-Mitgliedernummer des Autors.';

// Instructor
$GLOBALS['TL_LANG']['nc_tokens'][$type]['instructor_name'] = 'Name des Hauptleiters des Events. Ein Hinweistext, wenn kein Hauptleiter hinterlegt ist.';
$GLOBALS['TL_LANG']['nc_tokens'][$type]['instructor_email'] = 'E-Mail-Adresse des Hauptleiters des Events. Leer, wenn kein Hauptleiter hinterlegt ist.';

// Webmaster
$GLOBALS['TL_LANG']['nc_tokens'][$type]['webmaster_email'] = 'E-Mail-Adressen der Webmaster, die beim Organisator für neue Tourenberichte hinterlegt sind, kommasepariert. Leer, wenn keine hinterlegt sind.';
