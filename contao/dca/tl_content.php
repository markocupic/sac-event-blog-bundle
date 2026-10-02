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

use Markocupic\SacEventBlogBundle\Controller\ContentElement\EventBlogListController;
use Markocupic\SacEventBlogBundle\Controller\ContentElement\EventBlogReaderController;
use Markocupic\SacEventBlogBundle\Controller\ContentElement\MemberDashboardEventBlogListController;
use Markocupic\SacEventBlogBundle\Controller\ContentElement\MemberDashboardEventBlogWriteController;

// Palettes
$GLOBALS['TL_DCA']['tl_content']['palettes'][EventBlogListController::TYPE] = '{type_legend},type,headline;{config_legend},eventBlogOrganizers,eventBlogJumpTo,eventBlogReaderElement,eventBlogLimit,perPage;{template_legend:hide},customTpl;{protected_legend:hide},protected;{expert_legend:hide},cssID;{invisible_legend:hide},invisible,start,stop';
$GLOBALS['TL_DCA']['tl_content']['palettes'][EventBlogReaderController::TYPE] = '{type_legend},type;{template_legend:hide},customTpl;{protected_legend:hide},protected;{expert_legend:hide},cssID;{invisible_legend:hide},invisible,start,stop';
$GLOBALS['TL_DCA']['tl_content']['palettes'][MemberDashboardEventBlogListController::TYPE] = '{type_legend},type,headline;{events_blog_legend},eventBlogTimeSpanForCreatingNew,eventBlogFormJumpTo;{template_legend:hide},customTpl;{protected_legend:hide},protected;{expert_legend:hide},cssID;{invisible_legend:hide},invisible,start,stop';
$GLOBALS['TL_DCA']['tl_content']['palettes'][MemberDashboardEventBlogWriteController::TYPE] = '{type_legend},type,headline;{events_blog_legend},eventBlogReaderPage,eventBlogMaxImageWidth,eventBlogMaxImageHeight,eventBlogMaxImageFileSize,eventBlogTimeSpanForCreatingNew,eventBlogOnPublishNotification;{template_legend:hide},customTpl;{protected_legend:hide},protected;{expert_legend:hide},cssID;{invisible_legend:hide},invisible,start,stop';

// Fields
$GLOBALS['TL_DCA']['tl_content']['fields']['eventBlogOrganizers'] = [
    'exclude'    => true,
    'inputType'  => 'checkbox',
    'foreignKey' => 'tl_event_organizer.title',
    'relation'   => ['type' => 'hasMany', 'load' => 'lazy'],
    'eval'       => ['multiple' => true, 'mandatory' => false, 'tl_class' => 'clr m12'],
    'sql'        => 'blob NULL',
];

$GLOBALS['TL_DCA']['tl_content']['fields']['eventBlogJumpTo'] = [
    'exclude'    => true,
    'inputType'  => 'pageTree',
    'foreignKey' => 'tl_page.title',
    'eval'       => ['fieldType' => 'radio', 'tl_class' => 'clr'],
    'sql'        => "int(10) unsigned NOT NULL default 0",
    'relation'   => ['type' => 'hasOne', 'load' => 'lazy'],
];

// The options are provided by Markocupic\SacEventBlogBundle\DataContainer\Content::getEventBlogReaderElementOptions()
$GLOBALS['TL_DCA']['tl_content']['fields']['eventBlogReaderElement'] = [
    'exclude'   => true,
    'inputType' => 'select',
    'eval'      => ['mandatory' => true, 'includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'clr'],
    'sql'       => "int(10) unsigned NOT NULL default 0",
];

$GLOBALS['TL_DCA']['tl_content']['fields']['eventBlogLimit'] = [
    'exclude'   => true,
    'inputType' => 'text',
    'eval'      => ['rgxp' => 'natural', 'tl_class' => 'w50'],
    'sql'       => "smallint(5) unsigned NOT NULL default 0",
];

$GLOBALS['TL_DCA']['tl_content']['fields']['eventBlogTimeSpanForCreatingNew'] = [
    'exclude'   => true,
    'inputType' => 'select',
    'options'   => range(5, 365),
    'eval'      => ['mandatory' => true, 'includeBlankOption' => false, 'tl_class' => 'clr', 'rgxp' => 'natural'],
    'sql'       => "int(10) unsigned NOT NULL default 0",
];

$GLOBALS['TL_DCA']['tl_content']['fields']['eventBlogFormJumpTo'] = [
    'exclude'    => true,
    'inputType'  => 'pageTree',
    'foreignKey' => 'tl_page.title',
    'eval'       => ['mandatory' => true, 'fieldType' => 'radio', 'tl_class' => 'clr'],
    'sql'        => "int(10) unsigned NOT NULL default 0",
    'relation'   => ['type' => 'hasOne', 'load' => 'lazy'],
];

$GLOBALS['TL_DCA']['tl_content']['fields']['eventBlogReaderPage'] = [
    'exclude'    => true,
    'inputType'  => 'pageTree',
    'foreignKey' => 'tl_page.title',
    'eval'       => ['mandatory' => true, 'fieldType' => 'radio', 'tl_class' => 'clr'],
    'sql'        => "int(10) unsigned NOT NULL default 0",
    'relation'   => ['type' => 'hasOne', 'load' => 'lazy'],
];

$GLOBALS['TL_DCA']['tl_content']['fields']['eventBlogMaxImageWidth'] = [
    'exclude'   => true,
    'inputType' => 'select',
    'options'   => range(100, 4000, 100),
    'eval'      => ['rgxp' => 'natural', 'tl_class' => 'w33'],
    'sql'       => "smallint(5) unsigned NOT NULL default 2500",
];

$GLOBALS['TL_DCA']['tl_content']['fields']['eventBlogMaxImageHeight'] = [
    'exclude'   => true,
    'inputType' => 'select',
    'options'   => range(100, 4000, 100),
    'eval'      => ['rgxp' => 'natural', 'tl_class' => 'w33'],
    'sql'       => "smallint(5) unsigned NOT NULL default 1500",
];

$GLOBALS['TL_DCA']['tl_content']['fields']['eventBlogMaxImageFileSize'] = [
    'exclude'   => true,
    'inputType' => 'select',
    'options'   => range(1000000, 30000000, 1000000),
    'eval'      => ['rgxp' => 'natural', 'tl_class' => 'w33'],
    'sql'       => "int(10) unsigned NOT NULL default 12000000",
];

$GLOBALS['TL_DCA']['tl_content']['fields']['eventBlogOnPublishNotification'] = [
    'exclude'    => true,
    'inputType'  => 'select',
    'foreignKey' => 'tl_nc_notification.title',
    'eval'       => ['mandatory' => true, 'includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'clr'],
    'sql'        => "int(10) unsigned NOT NULL default 0",
    'relation'   => ['type' => 'hasOne', 'load' => 'lazy'],
];
