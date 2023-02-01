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

// Frontend member dashboard write event blog frontend module - error messages
$GLOBALS['TL_LANG']['ERR']['md_write_event_blog_mailAddressNotFound'] = 'Leider wurde für dieses Konto in der Datenbank keine E-Mail-Adresse gefunden. Daher stehen einige Funktionen nur eingeschränkt zur Verfügung. Bitte hinterle auf der Webseite des Zentralverbands deine E-Mail-Adresse.';
$GLOBALS['TL_LANG']['ERR']['md_write_event_blog_eventNotFound'] = 'Event mit ID %s nicht gefunden.';
$GLOBALS['TL_LANG']['ERR']['md_write_event_blog_createBlogDeadlineExpired'] = 'Für diesen Event kann kein Bericht mehr erstellt werden. Das Eventdatum liegt bereits zu lange zurück.';
$GLOBALS['TL_LANG']['ERR']['md_write_event_blog_writingPermissionDenied'] = 'Du hast keine Berechtigung für diesen Event einen Bericht zu verfassen.';
$GLOBALS['TL_LANG']['ERR']['md_write_event_blog_missingImageLegend'] = 'Es fehlen noch eine oder mehrere Bildlegenden oder der Fotografen-Name. Bitte ergänze diese Pflichtangaben, damit der Bericht veröffentlicht werden kann.';
$GLOBALS['TL_LANG']['ERR']['md_write_event_blog_uploadDirNotFound'] = 'Bild-Upload-Verzeichnis nicht gefunden.';
$GLOBALS['TL_LANG']['ERR']['md_write_event_blog_notSpecified'] = 'keine Angabe';
$GLOBALS['TL_LANG']['ERR']['md_write_event_blog_writeSomethingAboutTheEvent'] = 'Bitte schreibe in einigen Sätzen etwas zum Event.';
$GLOBALS['TL_LANG']['ERR']['md_write_event_blog_emailAddressNotFound'] = 'Leider wurde für dieses Konto in der Datenbank keine E-Mail-Adresse gefunden. Daher stehen einige Funktionen nur eingeschränkt zur Verfügung. Bitte hinterlegen Sie auf der Internetseite des Zentralverbands Ihre E-Mail-Adresse.';

// Frontend member dashboard write event blog frontend module - form text and YouTube
$GLOBALS['TL_LANG']['FORM']['md_write_event_blog_title'] = 'Geben Sie den Titel ein. Falls der Titel von der Eventbezeichnung abweicht, wird beides angezeigt, Titel und Eventbezeichnung.';
$GLOBALS['TL_LANG']['FORM']['md_write_event_blog_text'] = 'Touren-/Lager-/Kursbericht (max. %d Zeichen, inkl. Leerzeichen)';
$GLOBALS['TL_LANG']['FORM']['md_write_event_blog_tourWaypoints'] = 'Tourenstationen mit Höhenangaben (nur stichwortartig)';
$GLOBALS['TL_LANG']['FORM']['md_write_event_blog_tourProfile'] = 'Höhenmeter und Zeitangabe pro Tag';
$GLOBALS['TL_LANG']['FORM']['md_write_event_blog_tourTechDifficulty'] = 'Technische Schwierigkeiten';
$GLOBALS['TL_LANG']['FORM']['md_write_event_blog_tourHighlights'] = 'Highlights/Bemerkungen (max. 3 Sätze)';
$GLOBALS['TL_LANG']['FORM']['md_write_event_blog_tourPublicTransportInfo'] = 'Mögliche ÖV-Verbindung';
$GLOBALS['TL_LANG']['FORM']['md_write_event_blog_youTubeId'] = 'YouTube Film-Id';
$GLOBALS['TL_LANG']['FORM']['md_write_event_blog_submit'] = 'Änderungen speichern';

// Frontend member dashboard write event blog frontend module - image upload
$GLOBALS['TL_LANG']['FORM']['md_write_event_blog_imageUpload'] = 'Bildupload';
$GLOBALS['TL_LANG']['FORM']['md_write_event_blog_startImageUpload'] = 'Bildupload starten';

// Miscellaneous
$GLOBALS['TL_LANG']['MSC']['md_write_event_blog_instructorNameNotSpecified'] = 'keine Angabe';
