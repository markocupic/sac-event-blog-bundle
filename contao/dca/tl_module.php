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

use Markocupic\SacEventBlogBundle\Controller\FrontendModule\EventBlogListController;
use Markocupic\SacEventBlogBundle\Controller\FrontendModule\EventBlogReaderController;
use Markocupic\SacEventBlogBundle\Controller\FrontendModule\MemberDashboardEventBlogListController;
use Markocupic\SacEventBlogBundle\Controller\FrontendModule\MemberDashboardEventBlogWriteController;

// Contao frontend modules
$GLOBALS['TL_DCA']['tl_module']['palettes'][EventBlogListController::TYPE] = '{title_legend},name,headline,type;{config_legend},eventBlogOrganizers,jumpTo,numberOfItems,skipFirst,perPage;{template_legend:hide},eventBlogListTemplate;{image_legend:hide},imgSize;{protected_legend:hide},protected;{expert_legend:hide},guests,cssID';
$GLOBALS['TL_DCA']['tl_module']['palettes'][EventBlogReaderController::TYPE] = '{title_legend},name,headline,type;{template_legend:hide},customTpl;{protected_legend:hide},protected;{expert_legend:hide},guests,cssID';
$GLOBALS['TL_DCA']['tl_module']['palettes'][MemberDashboardEventBlogListController::TYPE] = '{title_legend},name,headline,type;{events_blog_legend},eventBlogTimeSpanForCreatingNew,eventBlogFormJumpTo;{template_legend:hide},customTpl;{protected_legend:hide},protected;{expert_legend:hide},guests,cssID';
$GLOBALS['TL_DCA']['tl_module']['palettes'][MemberDashboardEventBlogWriteController::TYPE] = '{title_legend},name,headline,type;{events_blog_legend},eventBlogReaderPage,eventBlogMaxImageWidth,eventBlogMaxImageHeight,eventBlogMaxImageFileSize,eventBlogTimeSpanForCreatingNew,eventBlogOnPublishNotification;{template_legend:hide},customTpl;{protected_legend:hide},protected;{expert_legend:hide},guests,cssID';

// Add fields to tl_module
$GLOBALS['TL_DCA']['tl_module']['fields']['eventBlogOnPublishNotification'] = [
    'exclude'    => true,
    'search'     => true,
    'inputType'  => 'select',
    'foreignKey' => 'tl_nc_notification.title',
    'eval'       => ['mandatory' => true, 'includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'clr'],
    'sql'        => "int(10) unsigned NOT NULL default '0'",
    'relation'   => ['type' => 'hasOne', 'load' => 'lazy'],
];

$GLOBALS['TL_DCA']['tl_module']['fields']['eventBlogFormJumpTo'] = [
    'exclude'    => true,
    'inputType'  => 'pageTree',
    'foreignKey' => 'tl_page.title',
    'eval'       => ['mandatory' => true, 'fieldType' => 'radio', 'tl_class' => 'clr'],
    'sql'        => "int(10) unsigned NOT NULL default '0'",
    'relation'   => ['type' => 'hasOne', 'load' => 'eager'],
];

$GLOBALS['TL_DCA']['tl_module']['fields']['eventBlogReaderPage'] = [
    'exclude'    => true,
    'inputType'  => 'pageTree',
    'foreignKey' => 'tl_page.title',
    'eval'       => ['mandatory' => true, 'fieldType' => 'radio', 'tl_class' => 'clr'],
    'sql'        => "int(10) unsigned NOT NULL default '0'",
    'relation'   => ['type' => 'hasOne', 'load' => 'eager'],
];

$GLOBALS['TL_DCA']['tl_module']['fields']['eventBlogMaxImageWidth'] = [
    'exclude'   => true,
    'inputType' => 'select',
    'options'   => range(100, 4000, 100),
    'eval'      => ['rgxp' => 'natural', 'tl_class' => 'w33'],
    'sql'       => "smallint(5) unsigned NOT NULL default 2500",
];

$GLOBALS['TL_DCA']['tl_module']['fields']['eventBlogMaxImageHeight'] = [
    'exclude'   => true,
    'inputType' => 'select',
    'options'   => range(100, 4000, 100),
    'eval'      => ['rgxp' => 'natural', 'tl_class' => 'w33'],
    'sql'       => "smallint(5) unsigned NOT NULL default 1500",
];

$GLOBALS['TL_DCA']['tl_module']['fields']['eventBlogMaxImageFileSize'] = [
    'exclude'   => true,
    'inputType' => 'select',
    'options'   => range(1000000, 30000000, 1000000),
    'eval'      => ['rgxp' => 'natural', 'tl_class' => 'w33'],
    'sql'       => "int(10) unsigned NOT NULL default 12000000",
];

$GLOBALS['TL_DCA']['tl_module']['fields']['eventBlogTimeSpanForCreatingNew'] = [
    'exclude'   => true,
    'inputType' => 'select',
    'options'   => range(5, 365),
    'eval'      => ['mandatory' => true, 'includeBlankOption' => false, 'tl_class' => 'clr', 'rgxp' => 'natural'],
    'sql'       => "int(10) unsigned NOT NULL default 0",
];

$GLOBALS['TL_DCA']['tl_module']['fields']['eventBlogLimit'] = [
    'exclude'   => true,
    'inputType' => 'text',
    'eval'      => ['rgxp' => 'natural', 'tl_class' => 'w50'],
    'sql'       => "smallint(5) unsigned NOT NULL default '0'",
];

$GLOBALS['TL_DCA']['tl_module']['fields']['eventBlogOrganizers'] = [
    'exclude'    => true,
    'search'     => true,
    'filter'     => true,
    'sorting'    => true,
    'inputType'  => 'checkbox',
    'foreignKey' => 'tl_event_organizer.title',
    'relation'   => ['type' => 'hasMany', 'load' => 'lazy'],
    'eval'       => ['multiple' => true, 'mandatory' => false, 'tl_class' => 'clr m12'],
    'sql'        => 'blob NULL',
];
