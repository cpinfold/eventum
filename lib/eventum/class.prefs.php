<?php

/* vim: set expandtab tabstop=4 shiftwidth=4 encoding=utf-8: */
// +----------------------------------------------------------------------+
// | Eventum - Issue Tracking System                                      |
// +----------------------------------------------------------------------+
// | Copyright (c) 2003 - 2008 MySQL AB                                   |
// | Copyright (c) 2008 - 2010 Sun Microsystem Inc.                       |
// | Copyright (c) 2011 - 2015 Eventum Team.                              |
// |                                                                      |
// | This program is free software; you can redistribute it and/or modify |
// | it under the terms of the GNU General Public License as published by |
// | the Free Software Foundation; either version 2 of the License, or    |
// | (at your option) any later version.                                  |
// |                                                                      |
// | This program is distributed in the hope that it will be useful,      |
// | but WITHOUT ANY WARRANTY; without even the implied warranty of       |
// | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the        |
// | GNU General Public License for more details.                         |
// |                                                                      |
// | You should have received a copy of the GNU General Public License    |
// | along with this program; if not, write to:                           |
// |                                                                      |
// | Free Software Foundation, Inc.                                       |
// | 51 Franklin Street, Suite 330                                        |
// | Boston, MA 02110-1301, USA.                                          |
// +----------------------------------------------------------------------+

/**
 * Class to handle the business logic related to the user preferences
 * available in the application.
 */

class Prefs
{
    /**
     * Method used to get the system-wide default preferences.
     *
     * @param   array $projects An array of projects this user will have access too.
     * @return  array of the default preferences
     */
    public static function getDefaults($projects = null)
    {
        $prefs = array(
            'receive_assigned_email'  => array(),
            'receive_new_issue_email' => array(),
            'timezone'                => Date_Helper::getDefaultTimezone(),
            'week_firstday'           => Date_Helper::getDefaultWeekday(),
            'list_refresh_rate'       => APP_DEFAULT_REFRESH_RATE,
            'email_refresh_rate'     => APP_DEFAULT_REFRESH_RATE,
            'email_signature'         => '',
            'auto_append_email_sig'         => 'no',
            'auto_append_note_sig'    => 'no',
            'close_popup_windows'     => 0,
        );

        if (is_array($projects)) {
            foreach ($projects as $prj_id) {
                $prefs['receive_assigned_email'][$prj_id] = APP_DEFAULT_ASSIGNED_EMAILS;
                $prefs['receive_new_issue_email'][$prj_id] = APP_DEFAULT_NEW_EMAILS;
                $prefs['receive_copy_of_own_action'][$prj_id] = APP_DEFAULT_COPY_OF_OWN_ACTION;
            }
        }

        return $prefs;
    }

    /**
     * Method used to get the preferences set by a specific user.
     *
     * @param integer $usr_id The user ID
     * @param bool $force Set to true to force database refresh
     * @return array The preferences
     */
    public static function get($usr_id, $force = false)
    {
        static $returns;

        if (!$force && !empty($returns[$usr_id])) {
            return $returns[$usr_id];
        }

        $sql = 'SELECT
                    upr_timezone as timezone,
                    upr_week_firstday as week_firstday,
                    upr_list_refresh_rate as list_refresh_rate,
                    upr_email_refresh_rate as email_refresh_rate,
                    upr_email_signature as email_signature,
                    upr_auto_append_email_sig as auto_append_email_sig,
                    upr_auto_append_note_sig as auto_append_note_sig,
                    upr_auto_close_popup_window as close_popup_windows
                FROM
                    {{%user_preference}}
                WHERE
                    upr_usr_id=?';
        try {
            $res = DB_Helper::getInstance()->getRow($sql, array($usr_id));
        } catch (DbException $e) {
            return self::getDefaults(array_keys(Project::getAssocList($usr_id, false, true)));
        }
        if ($res === null) {
            return self::getDefaults(array_keys(Project::getAssocList($usr_id, false, true)));
        }

        $returns[$usr_id] = $res;
        $returns[$usr_id]['receive_assigned_email'] = array();
        $returns[$usr_id]['receive_new_issue_email'] = array();
        $returns[$usr_id]['receive_copy_of_own_action'] = array();

        // check for the refresh rate variables, and use the default values if appropriate
        if (empty($returns[$usr_id]['list_refresh_rate'])) {
            $returns[$usr_id]['list_refresh_rate'] = APP_DEFAULT_REFRESH_RATE;
        }
        if (empty($returns[$usr_id]['email_refresh_rate'])) {
            $returns[$usr_id]['email_refresh_rate'] = APP_DEFAULT_REFRESH_RATE;
        }

        // get per project preferences
        $sql = "SELECT
                    upp_prj_id as prj_id,
                    upp_receive_assigned_email as receive_assigned_email,
                    upp_receive_new_issue_email as receive_new_issue_email,
                    upp_receive_copy_of_own_action as receive_copy_of_own_action
                FROM
                    {{%user_project_preference}}
                WHERE
                    upp_usr_id = $usr_id";
        try {
            $res = DB_Helper::getInstance()->fetchAssoc($sql, array(), DbInterface::DB_FETCHMODE_ASSOC);
        } catch (DbException $e) {
            return $returns[$usr_id];
        }

        foreach ($res as $prj_id => $project_prefs) {
            $returns[$usr_id]['receive_assigned_email'][$prj_id] = $project_prefs['receive_assigned_email'];
            $returns[$usr_id]['receive_new_issue_email'][$prj_id] = $project_prefs['receive_new_issue_email'];
            $returns[$usr_id]['receive_copy_of_own_action'][$prj_id] = $project_prefs['receive_copy_of_own_action'];
        }

        return $returns[$usr_id];
    }

    /**
     * Method used to set the preferences for a specific user.
     *
     * @param   integer $usr_id The user ID
     * @param   array   $preferences An array of preferences
     * @return  integer 1 if the update worked, -1 otherwise
     */
    public static function set($usr_id, $preferences)
    {
        // set global preferences
        $sql = 'REPLACE INTO
                    {{%user_preference}}
                SET
                    upr_usr_id = ?,
                    upr_timezone = ?,
                    upr_week_firstday = ?,
                    upr_list_refresh_rate = ?,
                    upr_email_refresh_rate = ?,
                    upr_email_signature = ?,
                    upr_auto_append_email_sig = ?,
                    upr_auto_append_note_sig = ?,
                    upr_auto_close_popup_window = ?';
        try {
            DB_Helper::getInstance()->query($sql, array(
                $usr_id,
                @$preferences['timezone'],
                @$preferences['week_firstday'],
                @$preferences['list_refresh_rate'],
                @$preferences['email_refresh_rate'],
                @$preferences['email_signature'],
                @$preferences['auto_append_email_sig'],
                @$preferences['auto_append_note_sig'],
                @$preferences['close_popup_windows'],
            ));
        } catch (DbException $e) {
            return -1;
        }

        // set per project preferences
        $projects = Project::getAssocList($usr_id);
        foreach ($projects as $prj_id => $project_name) {
            $sql = 'REPLACE INTO
                        {{%user_project_preference}}
                    SET
                        upp_usr_id = ?,
                        upp_prj_id = ?,
                        upp_receive_assigned_email = ?,
                        upp_receive_new_issue_email = ?,
                        upp_receive_copy_of_own_action = ?';

            try {
                DB_Helper::getInstance()->query($sql, array(
                    $usr_id,
                    $prj_id,
                    $preferences['receive_assigned_email'][$prj_id],
                    $preferences['receive_new_issue_email'][$prj_id],
                    $preferences['receive_copy_of_own_action'][$prj_id],
                ));
            } catch (DbException $e) {
                return -1;
            }
        }

        return 1;
    }
}
