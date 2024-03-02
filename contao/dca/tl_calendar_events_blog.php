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

use Contao\DC_Table;
use Contao\DataContainer;
use Contao\Input;
use Contao\System;
use Markocupic\SacEventBlogBundle\Config\PublishState;

$GLOBALS['TL_DCA']['tl_calendar_events_blog'] = [
    'config'   => [
        'dataContainer'    => DC_Table::class,
        'enableVersioning' => true,
        'notCopyable'      => true,
        'closed'           => true,
        'sql'              => [
            'keys' => [
                'id'      => 'primary',
                'eventId' => 'index',
            ],
        ],
    ],
    'list'     => [
        'sorting'           => [
            'mode'        => DataContainer::MODE_SORTABLE,
            'fields'      => ['eventStartDate DESC'],
            'panelLayout' => 'filter;sort,search,limit',
        ],
        'label'             => [
            'fields'      => [
                'publishState',
                'checkedByInstructor',
                'title',
                'authorName',
            ],
            'showColumns' => true,
        ],
        'global_operations' => [
            'all' => [
                'label'      => &$GLOBALS['TL_LANG']['MSC']['all'],
                'href'       => 'act=select',
                'class'      => 'header_edit_all',
                'attributes' => 'onclick="Backend.getScrollOffset()" accesskey="e"',
            ],
        ],
        'operations'        => [
            'edit'       => [
                'label' => &$GLOBALS['TL_LANG']['tl_calendar_events_blog']['edit'],
                'href'  => 'act=edit',
                'icon'  => 'edit.svg',
            ],
            'delete'     => [
                'label'      => &$GLOBALS['TL_LANG']['tl_calendar_events_blog']['delete'],
                'href'       => 'act=delete',
                'icon'       => 'delete.svg',
                'attributes' => 'onclick="if(!confirm(\''.($GLOBALS['TL_LANG']['MSC']['deleteConfirm'] ?? null).'\'))return false;Backend.getScrollOffset()"',
            ],
            'show'       => [
                'label' => &$GLOBALS['TL_LANG']['tl_calendar_events_blog']['show'],
                'href'  => 'act=show',
                'icon'  => 'show.svg',
            ],
            'exportBlog' => [
                'label' => &$GLOBALS['TL_LANG']['tl_calendar_events_blog']['exportBlog'],
                'href'  => 'action=exportBlog',
                'icon'  => 'bundles/markocupicsaceventblog/icons/file-word-regular.svg',
            ],
        ],
    ],
    'palettes' => [
        'default' => '
		{publishState_legend},publishState,checkedByInstructor;
		{author_legend},dateAdded,sacMemberId,authorName;
		{event_legend},eventId,title,eventTitle,eventSubstitutionText,organizers,tourWaypoints,tourProfile,tourTechDifficulty,text,tourHighlights,tourPublicTransportInfo,youTubeId,multiSRC',
    ],
    'fields'   => [
        'id'                      => [
            'sql' => 'int(10) unsigned NOT NULL auto_increment',
        ],
        'eventId'                 => [
            'label'      => &$GLOBALS['TL_LANG']['tl_calendar_events_blog']['eventId'],
            'foreignKey' => 'tl_calendar_events.title',
            'sql'        => "int(10) unsigned NOT NULL default '0'",
            'relation'   => ['type' => 'belongsTo', 'load' => 'eager'],
            'eval'       => ['readonly' => true],
        ],
        'tstamp'                  => [
            'sql' => "int(10) unsigned NOT NULL default '0'",
        ],
        'publishState'            => [
            'filter'    => true,
            'sort'      => true,
            'default'   => 1,
            'exclude'   => true,
            'reference' => &$GLOBALS['TL_LANG']['tl_calendar_events_blog']['publishStateRef'],
            'inputType' => 'select',
            'options'   => [PublishState::STILL_IN_PROGRESS, PublishState::APPROVED_FOR_REVIEW, PublishState::PUBLISHED],
            'eval'      => ['tl_class' => 'clr', 'submitOnChange' => true],
            'sql'       => "char(1) NOT NULL default '1'",
        ],
        'checkedByInstructor'     => [
            'filter'    => true,
            'sort'      => true,
            'default'   => 1,
            'exclude'   => true,
            'inputType' => 'checkbox',
            'eval'      => ['tl_class' => 'clr', 'submitOnChange' => false],
            'sql'       => "char(1) NOT NULL default ''",
        ],
        'authorName'              => [
            'filter'    => true,
            'sorting'   => true,
            'search'    => true,
            'exclude'   => true,
            'inputType' => 'text',
            'eval'      => ['doNotCopy' => true, 'mandatory' => true, 'maxlength' => 255, 'tl_class' => 'w50', 'readonly' => true],
            'sql'       => "varchar(255) NOT NULL default ''",
        ],
        'eventTitle'              => [
            'filter'    => true,
            'sorting'   => true,
            'search'    => true,
            'exclude'   => true,
            'inputType' => 'text',
            'eval'      => ['doNotCopy' => true, 'mandatory' => true, 'readonly' => true, 'maxlength' => 255, 'tl_class' => 'clr'],
            'sql'       => "varchar(255) NOT NULL default ''",
        ],
        'eventSubstitutionText'   => [
            'filter'    => true,
            'sorting'   => true,
            'search'    => true,
            'inputType' => 'textarea',
            'exclude'   => true,
            'eval'      => ['doNotCopy' => true, 'mandatory' => false, 'readonly' => true, 'maxlength' => 128, 'tl_class' => 'clr'],
            'sql'       => 'text NULL',
        ],
        'eventStartDate'          => [
            'sorting' => true,
            'flag'    => DataContainer::SORT_DAY_DESC,
            'sql'     => "int(10) unsigned NOT NULL default '0'",
        ],
        'eventEndDate'            => [
            'sql' => "int(10) unsigned NOT NULL default '0'",
        ],
        'eventDates'              => [
            'sql' => 'blob NULL',
        ],
        'title'                   => [
            'exclude'   => true,
            'inputType' => 'text',
            'search'    => true,
            'eval'      => ['doNotCopy' => true, 'mandatory' => true, 'maxlength' => 255, 'tl_class' => 'clr'],
            'sql'       => "varchar(255) NOT NULL default ''",
        ],
        'text'                    => [
            'exclude'   => true,
            'search'    => true,
            'inputType' => 'textarea',
            'eval'      => ['doNotCopy' => true, 'max-length' => 1700, 'mandatory' => true, 'tl_class' => 'clr'],
            'sql'       => 'mediumtext NULL',
        ],
        'youTubeId'               => [
            'filter'    => true,
            'sort'      => true,
            'search'    => true,
            'exclude'   => true,
            'inputType' => 'text',
            'eval'      => ['doNotCopy' => true, 'mandatory' => false, 'maxlength' => 11, 'tl_class' => 'clr'],
            'sql'       => "varchar(11) NOT NULL default ''",
        ],
        'sacMemberId'             => [
            'filter'    => true,
            'sort'      => true,
            'search'    => true,
            'exclude'   => true,
            'inputType' => 'text',
            'eval'      => ['mandatory' => true, 'doNotShow' => true, 'doNotCopy' => true, 'maxlength' => 255, 'tl_class' => 'w50', 'readonly' => true],
            'sql'       => "varchar(255) NOT NULL default ''",
        ],
        'multiSRC'                => [
            'exclude'   => true,
            'inputType' => 'fileTree',
            'eval'      => [
                'path'       => System::getContainer()->getParameter('sac_event_blog.asset_dir').'/'.Input::get('id'),
                'doNotCopy'  => true,
                'isGallery'  => true,
                'extensions' => 'jpg,jpeg',
                'multiple'   => true,
                'fieldType'  => 'checkbox',
                'orderField' => 'orderSRC',
                'files'      => true,
                'mandatory'  => false,
                'tl_class'   => 'clr',
            ],
            'sql'       => 'blob NULL',
        ],
        'orderSRC'                => [
            'eval' => ['doNotCopy' => true],
            'sql'  => 'blob NULL',
        ],
        'organizers'              => [
            'search'     => true,
            'filter'     => true,
            'sorting'    => true,
            'exclude'    => true,
            'inputType'  => 'select',
            'foreignKey' => 'tl_event_organizer.title',
            'relation'   => ['type' => 'hasMany', 'load' => 'lazy'],
            'eval'       => ['multiple' => true, 'chosen' => true, 'mandatory' => true, 'includeBlankOption' => false, 'tl_class' => 'clr m12'],
            'sql'        => 'blob NULL',
        ],
        'securityToken'           => [
            'sql' => "varchar(255) NOT NULL default ''",
        ],
        'dateAdded'               => [
            'default'   => time(),
            'flag'      => DataContainer::SORT_MONTH_DESC,
            'sorting'   => true,
            'exclude'   => true,
            'inputType' => 'text',
            'eval'      => ['rgxp' => 'date', 'mandatory' => true, 'doNotCopy' => false, 'datepicker' => true, 'tl_class' => 'w50 wizard'],
            'sql'       => 'int(10) unsigned NOT NULL default 0',
        ],
        'tourWaypoints'           => [
            'filter'    => true,
            'sort'      => true,
            'search'    => true,
            'exclude'   => true,
            'inputType' => 'textarea',
            'eval'      => ['doNotCopy' => true, 'max-length' => 300, 'mandatory' => false, 'tl_class' => 'clr'],
            'sql'       => 'mediumtext NULL',
        ],
        'tourProfile'             => [
            'filter'    => true,
            'sort'      => true,
            'search'    => true,
            'exclude'   => true,
            'inputType' => 'textarea',
            'eval'      => ['doNotCopy' => true, 'mandatory' => false, 'tl_class' => 'clr'],
            'sql'       => 'mediumtext NULL',
        ],
        'tourTechDifficulty'      => [
            'filter'    => true,
            'sort'      => true,
            'search'    => true,
            'exclude'   => true,
            'inputType' => 'textarea',
            'eval'      => ['doNotCopy' => true, 'mandatory' => false, 'tl_class' => 'clr'],
            'sql'       => 'mediumtext NULL',
        ],
        'tourHighlights'          => [
            'filter'    => true,
            'sort'      => true,
            'search'    => true,
            'exclude'   => true,
            'inputType' => 'textarea',
            'eval'      => ['doNotCopy' => true, 'mandatory' => false, 'tl_class' => 'clr'],
            'sql'       => 'mediumtext NULL',
        ],
        'tourPublicTransportInfo' => [
            'filter'    => true,
            'sort'      => true,
            'search'    => true,
            'exclude'   => true,
            'inputType' => 'textarea',
            'eval'      => ['doNotCopy' => true, 'mandatory' => false, 'tl_class' => 'clr'],
            'sql'       => 'mediumtext NULL',
        ],
    ],
];
