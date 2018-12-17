<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * This is the external API for the supporter plugin.
 *
 * @package    tool_supporter
 * @copyright  2017 Benedikt Schneider, Klara Saary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace tool_supporter;

defined('MOODLE_INTERNAL') || die;

global $CFG;

require_once("$CFG->libdir/externallib.php");
require_once("$CFG->dirroot/webservice/externallib.php");
require_once("$CFG->dirroot/course/lib.php");
require_once("$CFG->dirroot/user/lib.php");
require_once("$CFG->libdir/adminlib.php");
require_once("$CFG->libdir/coursecatlib.php");

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;
use invalid_parameter_exception;

/**
 * Class external defines several functions to prepare data for further use
 * @package tool_supporter
 * @copyright  2017 Benedikt Schneider, Klara Saary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class external extends external_api {

    /**
     * Returns description of input parameters
     * @return external_function_parameters
     */
    public static function create_new_course_parameters() {
        return new external_function_parameters (
        array (
            'shortname' => new external_value ( PARAM_TEXT, 'The short name of the course to be created' ),
            'fullname' => new external_value ( PARAM_TEXT, 'The full name of the course to be created' ),
            'visible' => new external_value ( PARAM_BOOL, 'Toggles visibility of course' ),
            'categoryid' => new external_value ( PARAM_INT, 'ID of category the course should be created in' ),
            'activateselfenrol' => new external_value ( PARAM_BOOL, 'Toggles if self_enrolment should be activated' ),
            'selfenrolpassword' => new external_value ( PARAM_TEXT, 'Passowrd of self enrolment' ),
        ));
    }

    /**
     * Wrap the core function create_new_course.
     * @param string $shortname Desired shortname. Has to be unique or error is returned
     * @param string $fullname Desired fullname
     * @param int $visible Visibility
     * @param int $categoryid Id of the category
     * @return array Course characteristics
     */
    public static function create_new_course($shortname, $fullname, $visible, $categoryid, $activateselfenrol, $selfenrolpassword) {

        global $DB, $CFG;

        $catcontext = \context_coursecat::instance($categoryid);
        self::validate_context($catcontext);
        \require_capability('moodle/course:create', $catcontext);

        $array = array (
            'shortname' => $shortname,
            'fullname' => $fullname,
            'visible' => $visible,
            'categoryid' => $categoryid,
            'activateselfenrol' => $activateselfenrol,
            'selfenrolpassword' => $selfenrolpassword
        );

        // Parameters validation.
        $params = self::validate_parameters(self::create_new_course_parameters (), $array );

        $data = new \stdClass();
        $data->shortname = $params ['shortname'];
        $data->fullname = $params ['fullname'];
        $data->category = $params ['categoryid'];
        $data->visible = $params ['visible'];

        if (trim($params['shortname']) == '') {
            throw new invalid_parameter_exception('Invalid short name');
        }
        if (trim($params['fullname']) == '') {
            throw new invalid_parameter_exception('Invalid full name');
        }
        if ($DB->record_exists('course', array('shortname' => $data->shortname))) {
            throw new invalid_parameter_exception('shortnametaken already taken');
        }

        // Set Start date to 1.4. or 1.10.
        if (strpos($params['shortname'], 'WiSe') !== false) {
            $arrayaftersemester = explode('WiSe', shortname);
            $year = substr($arrayaftersemester[1], 1, 4);
            $data->startdate = mktime(24, 0, 0, 10, 1, $year); // Syntax: hour, minute, second, month, day, year.
        } else if (strpos($shortname, 'SoSe') !== false) {
            $arrayaftersemester = explode('SoSe', $shortname);
            $year = substr($arrayaftersemester[1], 1, 4);
            $data->startdate = mktime(24, 0, 0, 4, 1, $year);
        } else {
            $data->startdate = time();
        }

        $data->enddate = strtotime("+6 month", $data->startdate);

        $createdcourse = create_course($data);

        if ($activateselfenrol) {
            $selfenrolment = $DB->get_record("enrol", array ('courseid' => $createdcourse->id, 'enrol' => 'self'), $fields = '*');

            if (empty($selfenrolment)) {
                // If self enrolment is NOT activated for new courses, add one.
                $plugin = enrol_get_plugin('self');
                $plugin->add_instance($createdcourse, array("password" => $selfenrolpassword));
            } else {
                // If self enrolment is activated for new courses, activaten and update it.
                $selfenrolment->status = 0; // 0 is active!
                $selfenrolment->password = $selfenrolpassword; // The PW is safed as plain text.
                $DB->update_record("enrol", $selfenrolment);
            }
        }

        $returndata = array(
            'id' => $createdcourse->id,
            'category' => $createdcourse->category,
            'fullname' => $createdcourse->fullname,
            'shortname' => $createdcourse->shortname,
            'startdate' => $createdcourse->startdate,
            'visible' => $createdcourse->visible,
            'timecreated' => $createdcourse->timecreated,
            'timemodified' => $createdcourse->timemodified
        );

        return $returndata;
    }

    /**
     * Specifies the return value
     * @return external_single_structure the created course
     */
    public static function create_new_course_returns() {
        return new external_single_structure (
            array (
                'id' => new external_value ( PARAM_INT, 'The id of the newly created course' ),
                'category' => new external_value ( PARAM_INT, 'The category of the newly created course' ),
                'fullname' => new external_value ( PARAM_TEXT, 'The fullname of the newly created course' ),
                'shortname' => new external_value ( PARAM_TEXT, 'The shortname of the newly created course' ),
                'startdate' => new external_value ( PARAM_INT, 'The startdate of the newly created course' ),
                'visible' => new external_value ( PARAM_BOOL, 'The visible of the newly created course' ),
                'timecreated' => new external_value ( PARAM_INT, 'The id of the newly created course' ),
                'timemodified' => new external_value ( PARAM_INT, 'The id of the newly created course' )
        ));
    }

    // ------------------------------------------------------------------------------------------------------------------------

    /**
     * Returns description of input parameters
     * @return external_function_parameters
     */
    public static function enrol_user_into_course_parameters() {
        return new external_function_parameters(
            array(
                'userid' => new external_value (PARAM_INT, 'The id of the user to be enrolled'),
                'courseid' => new external_value (PARAM_INT, 'The id of the course to be enrolled into'),
                'roleid' => new external_value (PARAM_INT, 'The id of the role the user should be enrolled with')
        ));
    }

    /**
     * Wrap the core function enrol_user_into_course.
     * Enrols a user into a course
     *
     * @param int $userid Id of the user to enrol
     * @param int $courseid Id of course to enrol into
     * @param int $roleid Id of the role with which the user should be enrolled
     *
     * @return array Course info user was enrolled to
     */
    public static function enrol_user_into_course($userid, $courseid, $roleid) {
        global $DB;
        global $CFG;
        require_once("$CFG->dirroot/enrol/manual/externallib.php");

        $context = \context_course::instance($courseid);
        self::validate_context($context);
        // Check that the user has the permission to manual enrol.
        \require_capability('enrol/manual:enrol', $context);

        $params = array(
            'userid' => $userid,
            'courseid' => $courseid,
            'roleid' => $roleid
        );

        // Parameters validation.
        $params = self::validate_parameters(self::enrol_user_into_course_parameters(), $params);

        $enrolment = array('courseid' => $courseid, 'userid' => $userid, 'roleid' => $roleid);
        $enrolments[] = $enrolment;
        \enrol_manual_external::enrol_users($enrolments);

        $course = self::get_course_info($courseid);

        return $course;
    }

    /**
     * Specifies the return values
     *
     * @return external_single_structure returns a course
     */
    public static function enrol_user_into_course_returns() {
        return self::get_course_info_returns();
    }

    // ------------------------------------------------------------------------------------------------------------------------

    /**
     * Returns description of input parameters
     * @return external_function_parameters
     */
    public static function get_user_information_parameters() {
        return new external_function_parameters(
            array(
                'userid' => new external_value ( PARAM_INT, 'The id of the user' )
        ));
    }

    /**
     * Wrap the core function get_user_information.
     *
     * Gets and transforms the information of the given user
     * @param int $userid The id of the user
     */
    public static function get_user_information($userid) {
        global $DB, $CFG, $USER;

        $context = \context_system::instance();
        self::validate_context($context);
        \require_capability('moodle/user:viewdetails', $context);

        // Parameters validation.
        $params = self::validate_parameters(self::get_user_information_parameters (), array('userid' => $userid));

        $userinformation = user_get_users_by_id(array('userid' => $userid));

        $userinformationarray = [];
        foreach ($userinformation as $info) {
            // Example: Monday, 15-Aug-05 15:52:01 UTC.
            $info->timecreated = date('d.m.Y m:h', $info->timecreated);
            $info->timemodified = date('d.m.Y m:h', $info->timemodified);
            $info->lastlogin = date('d.m.Y m:h', $info->lastlogin);
            // Cast as an array.
            $userinformationarray[] = (array)$info;
        }
        $data['userinformation'] = $userinformationarray[0]; // We only retrieved one user.

        $usercourses = enrol_get_users_courses($userid, false, $fields = '*');

        // Get assignable roles with correct role name.
        $coursecontext = \context_course::instance(1);
        $assignableroles = \get_assignable_roles($coursecontext);

        $categories = $DB->get_records("course_categories", $conditions = null, $sort = 'sortorder ASC', $fields = 'id, name, parent, depth, path');
        // Used for unenrolling users.
        $userenrolments = $DB->get_records_sql('SELECT e.courseid, ue.id FROM {user_enrolments} ue, {enrol} e WHERE e.id = ue.enrolid AND ue.userid = ?', array($userid));

        $data['uniquelevelones'] = [];
        $data['uniqueleveltwoes'] = [];
        $coursesarray = [];
        foreach ($usercourses as $course) {
            if ($course->category != 0) {
                $category = $categories[$course->category];
                $patharray = explode("/", $category->path);
                if (isset($patharray[1])) {
                    $patharray[1] = $categories[$patharray[1]]->name;
                    $course->level_one = $patharray[1];
                    array_push($data['uniquelevelones'], $patharray[1]);
                } else {
                    $course->level_one = "";
                }

                if (isset($patharray[2])) {
                    $patharray[2] = $categories[$patharray[2]]->name;
                    $course->level_two = $patharray[2];
                    array_push($data['uniqueleveltwoes'], $patharray[2]);
                } else {
                    $course->level_two = "";
                }

                // Get the used Roles the user is enrolled as (teacher, student, ...).
                $usedroles = get_user_roles(\context_course::instance($course->id), $userid, false);
                $course->roles = [];
                foreach ($usedroles as $role) {
                    $course->roles[] = $assignableroles[$role->roleid];
                }

                // Used for unenrolling users.
                $course->enrol_id = $userenrolments[$course->id]->id;

                $coursesarray[] = (array)$course;
            }
        }
        $data['userscourses'] = $coursesarray;

        // Filters should only appear once in the dropdown-menues.
        $data['uniquelevelones'] = array_filter(array_unique($data['uniquelevelones']));
        $data['uniqueleveltwoes'] = array_filter(array_unique($data['uniqueleveltwoes']));

        $context = \context_system::instance();
        if (\has_capability('moodle/user:loginas', $context) ) {
            $link = $CFG->wwwroot."/course/loginas.php?id=1&user=".$data['userinformation']['id']."&sesskey=".$USER->sesskey;
            $data['loginaslink'] = $link;
        } else {
            $data['loginaslink'] = false;
        }

        $link = $CFG->wwwroot."/user/profile.php?id=".$data['userinformation']['id'];
        $data['profilelink'] = $link;

        $link = $CFG->wwwroot."/admin/user.php?delete=".$data['userinformation']['id']."&sesskey=".$USER->sesskey;
        $data['deleteuserlink'] = $link;

        $link = $CFG->wwwroot."/user/editadvanced.php?id=".$data['userinformation']['id'];
        $data['edituserlink'] = $link;

        if (\has_capability('moodle/user:update', $context) ) {
            $data['isallowedtoupdateusers'] = true;
        } else {
            $data['isallowedtoupdateusers'] = false;
        }

        $data['config'] = array(
            'showusername' => $CFG->tool_supporter_user_details_showusername,
            'showidnumber' => $CFG->tool_supporter_user_details_showidnumber,
            'showfirstname' => $CFG->tool_supporter_user_details_showfirstname,
            'showlastname' => $CFG->tool_supporter_user_details_showlastname,
            'showmailadress' => $CFG->tool_supporter_user_details_showmailadress,
            'showtimecreated' => $CFG->tool_supporter_user_details_showtimecreated,
            'showtimemodified' => $CFG->tool_supporter_user_details_showtimemodified,
            'showlastlogin' => $CFG->tool_supporter_user_details_showlastlogin,
        );

        // Get level labels.
        $labels = $CFG->tool_supporter_level_labels;
        $count = 1; // Root is level 0, so we begin at 1.
        foreach (explode(';', $labels) as $label) {
            $data['label_level_'.$count] = $label; // Each label will be available under {{label_level_0}}, {{label_level_1}}, etc.
            $count++;
        }

        return array($data);
    }

    /**
     * Specifies the return values
     *
     * @return external_multiple_structure the user's courses and information
     */
    public static function get_user_information_returns() {
        return new external_multiple_structure (new external_single_structure (array (
            'userinformation' => new external_single_structure ( array (
                'id' => new external_value (PARAM_INT, 'id of the user'),
                'username' => new external_value (PARAM_TEXT, 'username of the user'),
                'firstname' => new external_value (PARAM_TEXT, 'firstname of the user'),
                'lastname' => new external_value (PARAM_TEXT, 'lastname of the user'),
                'email' => new external_value (PARAM_TEXT, 'email of the user'),
                'timecreated' => new external_value (PARAM_TEXT, 'timecreated of the user as date'),
                'timemodified' => new external_value (PARAM_TEXT, 'timemodified of the user as date'),
                'lastlogin' => new external_value (PARAM_TEXT, 'last login of the user as date'),
                'lang' => new external_value (PARAM_TEXT, 'lang of the user'),
                'auth' => new external_value (PARAM_TEXT, 'auth of the user'),
                'idnumber' => new external_value (PARAM_TEXT, 'idnumber of the user'),
            )),
            'config' => new external_single_structure( (array (
                'showusername' => new external_value(PARAM_BOOL, "Is the user allowed to update users' globally?"),
                'showidnumber' => new external_value(PARAM_BOOL, "Is the user allowed to update users' globally?"),
                'showfirstname' => new external_value(PARAM_BOOL, "Is the user allowed to update users' globally?"),
                'showlastname' => new external_value(PARAM_BOOL, "Is the user allowed to update users' globally?"),
                'showmailadress' => new external_value(PARAM_BOOL, "Is the user allowed to update users' globally?"),
                'showtimecreated' => new external_value(PARAM_BOOL, "Is the user allowed to update users' globally?"),
                'showtimemodified' => new external_value(PARAM_BOOL, "Is the user allowed to update users' globally?"),
                'showlastlogin' => new external_value(PARAM_BOOL, "Is the user allowed to update users' globally?"),
            ))),
            'userscourses' => new external_multiple_structure (new external_single_structure (array (
                'id' => new external_value (PARAM_INT, 'id of course'),
                'category' => new external_value (PARAM_INT, 'category id of the course'),
                'shortname' => new external_value (PARAM_TEXT, 'short name of the course'),
                'fullname' => new external_value (PARAM_TEXT, 'long name of the course'),
                'startdate' => new external_value (PARAM_INT, 'starting date of the course'),
                'visible' => new external_value(PARAM_INT, 'Is the course visible'),
                'level_one' => new external_value (PARAM_TEXT, 'the parent category name of the course'),
                'level_two' => new external_value (PARAM_TEXT, 'the direkt name of the course category'),
                'roles' => new external_multiple_structure (new external_value(PARAM_TEXT, 'array with roles for each course')),
                'enrol_id' => new external_value (PARAM_INT, 'id of user enrolment')
                // Additional information which could be added: idnumber, sortorder, defaultgroupingid, groupmode, groupmodeforce,
                // And: ctxid, ctxpath, ctsdepth, ctxinstance, ctxlevel.
            ))),
            'loginaslink' => new external_value(PARAM_TEXT, 'The link to login as the user', VALUE_OPTIONAL),
            'profilelink' => new external_value(PARAM_TEXT, 'The link to the users profile page'),
            'edituserlink' => new external_value(PARAM_TEXT, 'The link to edit the user'),
            'deleteuserlink' => new external_value(PARAM_TEXT, 'The link to delete the user, confirmation required'),
            'uniquelevelones' => new external_multiple_structure (
                    new external_value(PARAM_TEXT, 'array with unique first level categories')),
            'uniqueleveltwoes' => new external_multiple_structure (
                    new external_value(PARAM_TEXT, 'array with unique second level categories')),
            'isallowedtoupdateusers' => new external_value(PARAM_BOOL, "Is the user allowed to update users' globally?"),
            // For now, it is limited to 5 levels and this implementation is ugly.
            'label_level_1' => new external_value(PARAM_TEXT, 'label of first level', VALUE_OPTIONAL),
            'label_level_2' => new external_value(PARAM_TEXT, 'label of second level', VALUE_OPTIONAL),
            'label_level_3' => new external_value(PARAM_TEXT, 'label of third level', VALUE_OPTIONAL),
            'label_level_4' => new external_value(PARAM_TEXT, 'label of fourth level', VALUE_OPTIONAL),
            'label_level_5' => new external_value(PARAM_TEXT, 'label of fifth level', VALUE_OPTIONAL),
        )));
    }

    // ------------------------------------------------------------------------------------------------------------------------

    /**
     * Returns description of input parameters
     * @return external_function_parameters
     */
    public static function get_users_parameters() {
        return new external_function_parameters(
            array());
    }

    /**
     * Wrapper for core function get_users
     * Gets every moodle user
     */
    public static function get_users() {
        global $DB;

        $systemcontext = \context_system::instance();
        self::validate_context($systemcontext);
        \require_capability('moodle/site:viewparticipants', $systemcontext);
        $data = array();
        $data['users'] = $DB->get_records('user', array('deleted' => '0'), null, 'id, idnumber, username, firstname, lastname, email');

        // Returns fields: id, username, firstname, lastname without guest and deleted users.
        //$data['users'] = get_users_listing();

        // Returns fields: id, auth, confirmed, policyagree, deleted, suspended, mnethostid, username, password, idnumber without guest.
        //$data['users'] = get_users(); // Gives warning about possible out of memory error. But because it is processed server-side, it should not be a problem.

        //error_log(print_r('data -------------', TRUE));
        //error_log(str_replace("stdClass Object", "Array",str_replace("\n", "", print_r($data, TRUE))));

        return $data;
    }

    /**
     * Specifies return value
     *
     * @return external_single_structure of array of users
     **/
    public static function get_users_returns() {
        return new external_single_structure(
            array(
                'users' => new external_multiple_structure(
                    new external_single_structure(
                        array(
                            'id' => new external_value(PARAM_INT, 'id of user'),
                            'idnumber' => new external_value(PARAM_RAW, 'idnumber of user'),
                            'username' => new external_value(PARAM_TEXT, 'username of user'),
                            'firstname' => new external_value(PARAM_TEXT, 'firstname of user'),
                            'lastname' => new external_value(PARAM_TEXT, 'lastname of user'),
                            'email' => new external_value(PARAM_TEXT, 'email adress of user')
                        )
                    )
                )
            ));
    }

    // ------------------------------------------------------------------------------------------------------------------------

    /**
     * Returns description of input parameters
     * @return external_function_parameters
     */
    public static function get_courses_parameters() {
        return new external_function_parameters(
            array());
    }
    /**
     * Wrapper for core function get_courses
     *
     * Gets every moodle course
     */
    public static function get_courses() {
        global $DB, $CFG;

        self::validate_parameters(self::get_courses_parameters(), array());
        $context = \context_system::instance();
        self::validate_context($context);
        // Is the closest to the needed capability. Is used in /course/management.php.
        \require_capability('moodle/course:viewhiddencourses', $context);

        $categories = $DB->get_records("course_categories", array("visible" => "1"), $sort = 'sortorder ASC', $fields = 'id, name, parent, depth, path');
        $courses = $DB->get_records("course", $conditions = null, $sort = '', $fields = 'id, shortname, fullname, visible, category');

        foreach ($courses as $course) {
            if ($course->category != 0) {
                $category = $categories[$course->category];
                $patharray = explode("/", $category->path);
                if (isset($patharray[1])) {
                    $patharray[1] = $categories[$patharray[1]]->name;
                    $course->level_one = $patharray[1];
                } else {
                    $course->level_one = "";
                }
                if (isset($patharray[2])) {
                    $patharray[2] = $categories[$patharray[2]]->name;
                    $course->level_two = $patharray[2];
                } else {
                    $course->level_two = "";
                }
                $coursesarray[] = (array)$course;

            }
        }
        $data['courses'] = $coursesarray;

        $data['uniquelevelones'] = [];
        $data['uniqueleveltwoes'] = [];
        foreach ($categories as $category) {
            if ($category->depth == 1) {
                array_push($data['uniquelevelones'], $category->name);
            }
            if ($category->depth == 2) {
                array_push($data['uniqueleveltwoes'], $category->name);
            }
        }

        // Filters should only appear once in the dropdown-menues.
        $data['uniquelevelones'] = array_filter(array_unique($data['uniquelevelones']));
        $data['uniqueleveltwoes'] = array_filter(array_unique($data['uniqueleveltwoes']));

        // Get level labels.
        $labels = $CFG->tool_supporter_level_labels;
        $count = 1; // Root is level 0, so we begin at 1.
        foreach (explode(';', $labels) as $label) {
            $data['label_level_'.$count] = $label; // Each label will be available under {{label_level_0}}, {{label_level_1}}, etc.
            $count++;
        }

        return $data;
    }

    /**
     * Specifies return values
     *
     * @return external_single_structure of array of courses
     */
    public static function get_courses_returns() {
        return new external_single_structure (array (
            'courses' => new external_multiple_structure(
                new external_single_structure(
                    array(
                        'id' => new external_value(PARAM_INT, 'id of course'),
                        'shortname' => new external_value(PARAM_RAW, 'shortname of course'),
                        'fullname' => new external_value(PARAM_RAW, 'course name'),
                        'level_two' => new external_value(PARAM_RAW,  'parent category'),
                        'level_one' => new external_value(PARAM_RAW, 'course category'),
                        'visible' => new external_value(PARAM_INT, 'Is the course visible')
                    )
                )
            ),
            'uniqueleveltwoes' => new external_multiple_structure (
                new external_value(PARAM_TEXT, 'array with unique category names of all first levels')
            ),
            'uniquelevelones' => new external_multiple_structure (
                new external_value(PARAM_TEXT, 'array with unique category names of all second levels')
            ),
            // For now, it is limited to 5 levels and this implementation is ugly.
            'label_level_1' => new external_value(PARAM_TEXT, 'label of first level', VALUE_OPTIONAL),
            'label_level_2' => new external_value(PARAM_TEXT, 'label of second level', VALUE_OPTIONAL),
            'label_level_3' => new external_value(PARAM_TEXT, 'label of third level', VALUE_OPTIONAL),
            'label_level_4' => new external_value(PARAM_TEXT, 'label of fourth level', VALUE_OPTIONAL),
            'label_level_5' => new external_value(PARAM_TEXT, 'label of fifth level', VALUE_OPTIONAL),
        ));
    }

    // ------------------------------------------------------------------------------------------------------------------------

    /**
     * Returns description of input parameters
     * @return external_function_parameters
     */
    public static function get_course_info_parameters() {
        return new external_function_parameters(
            array(
                'courseID' => new external_value(PARAM_RAW, 'id of course you want to show')
        ));
    }

    /**
     * Wrapper of core function get_course_info
     *
     * Accumulates and transforms course data to be displayed
     *
     * @param int $courseid Id of the course which needs to be displayed
     */
    public static function get_course_info($courseid) {
        global $DB, $CFG, $COURSE;

        // Check parameters.
        $params = self::validate_parameters(self::get_course_info_parameters(), array('courseID' => $courseid));
        $courseid = $params['courseID'];

        $coursecontext = \context_course::instance($courseid);
        self::validate_context($coursecontext);
        // Is the user allowed to change course_settings?
        \require_capability('moodle/course:view', $coursecontext);

        // Get information about the course.
        $select = "SELECT c.id, c.shortname, c.fullname, c.visible, c.timecreated, cat.path FROM {course} c, ".
                  "{course_categories} cat WHERE c.category = cat.id AND c.id = ".$courseid;
        $coursedetails = $DB->get_record_sql($select);
        $coursedetails = (array)$coursedetails;
        $coursedetails['timecreated'] = date('d.m.Y m:h', $coursedetails['timecreated']); // Convert timestamp to readable format.

        // Get whole course-path.
        // Extract IDs from path and remove empty values by using array_filter.
        $parentcategoriesids = array_filter(explode('/', $coursedetails['path']));

        // Select the name of all parent categories.
        $parentcategoriesnames = $DB->get_records_list('course_categories', 'id', $parentcategoriesids, null, 'id,name');
        $pathcategories = [];
        foreach ($parentcategoriesnames as $val) {
            $pathcategories[] = $val->name;
        }
        $coursedetails['level_one'] = $pathcategories[0];
        isset($pathcategories[1]) ? $coursedetails['level_two'] = $pathcategories[1] : $coursedetails['level_two'] = "";
        $coursedetails['path'] = implode('/', $pathcategories);

        // How many students are enrolled in the course?
        $coursedetails['enrolledUsers'] = \count_enrolled_users($coursecontext, $withcapability = '', $groupid = '0');

        // Get assignable roles in the course.
        $usedrolesincourse = get_assignable_roles($coursecontext);

        // Which roles are used and how many users have this role?
        $roles = array();
        $rolesincourse = [];

        foreach ($usedrolesincourse as $rid => $rname) {
            $rolename = $rname;
            $rolenumber = \count_role_users($rid, $coursecontext);
            if ($rolenumber != 0) {
                $roles[] = ['roleName' => $rolename, 'roleNumber' => $rolenumber];
                $rolesincourse[] = $rolename;
            }
        }
        asort($rolesincourse);

        // Get userinformation about users in course.
        $usersraw = \get_enrolled_users($coursecontext, $withcapability = '', $groupid = 0,
        $userfields = 'u.id,u.username,u.firstname, u.lastname', $orderby = '', $limitfrom = 0, $limitnum = 0);
        $users = array();
        $userenrolments = $DB->get_records_sql('SELECT ue.userid, ue.id FROM {user_enrolments} ue, {enrol} e WHERE e.id = ue.enrolid AND e.courseid = ?', array($courseid));
        foreach ($usersraw as $u) {
            $u = (array)$u;
            $u['lastaccess'] = date('d.m.Y m:h', $DB->get_field('user_lastaccess', 'timeaccess', array('courseid' => $courseid, 'userid' => $u['id'])));
            // Find user specific roles, but without parent context (no global roles).
            $rolesofuser = get_user_roles($coursecontext, $u['id'], false);
            $userroles = [];
            foreach ($rolesofuser as $role) {
                $userroles[] = $usedrolesincourse[$role->roleid];
            }
            $u['roles'] = $userroles;
            $u['enrol_id'] = $userenrolments[$u['id']]->id;
            $users[] = $u;
        }

        // Get Activities in course.
        $activities = array();
        $modules = \get_array_of_activities($courseid);
        foreach ($modules as $mo) {
            $section = \get_section_name($courseid, $mo->section);
            $activity = ['section' => $section, 'activity' => $mo->mod, 'name' => $mo->name, 'visible' => $mo->visible];
            $activities[] = $activity;
        }

        // Get Enrolment Methods in course.
        $enrolmentmethods = array();
        $instances = enrol_get_instances($courseid, false);
        $plugins   = enrol_get_plugins(false);
        // Iterate through enrol plugins and add to the display table.
        foreach ($instances as $instance) {
            $plugin = $plugins[$instance->enrol];

            $enrolmentmethod['methodname'] = $plugin->get_instance_name($instance);
            $enrolmentmethod['enabled'] = false;
            if (!enrol_is_enabled($instance->enrol) or $instance->status != ENROL_INSTANCE_ENABLED) {
                $enrolmentmethod['enabled'] = true;
            }

            $enrolmentmethod['users'] = $DB->count_records('user_enrolments', array('enrolid' => $instance->id));
            $enrolmentmethods[] = $enrolmentmethod;
        }

        // Get links for navigation.
        $settingslink = $CFG->wwwroot."/course/edit.php?id=".$courseid;
        if (\has_capability('moodle/course:delete', $coursecontext) ) {
            $deletelink = $CFG->wwwroot."/course/delete.php?id=".$courseid;
        } else {
            $deletelink = false;
        }

        if (\has_capability('moodle/course:update', \context_system::instance()) ) {
            $isallowedtoupdatecourse = true;
        } else {
            $isallowedtoupdatecourse = false;
        }

        $courselink = $CFG->wwwroot."/course/view.php?id=".$courseid;

        $links = array(
            'settingslink' => $settingslink,
            'deletelink' => $deletelink,
            'courselink' => $courselink
        );
        $data = array(
            'courseDetails' => (array)$coursedetails,
            'rolesincourse' => (array)$rolesincourse,
            'roles' => (array)$roles,
            'users' => (array)$users,
            'activities' => (array)$activities,
            'links' => $links,
            'enrolmentMethods' => (array)$enrolmentmethods,
            'isallowedtoupdatecourse' => $isallowedtoupdatecourse
        );

        $data['config'] = array(
            'showshortname' => $CFG->tool_supporter_course_details_showshortname,
            'showfullname'  => $CFG->tool_supporter_course_details_showfullname,
            'showvisible'  => $CFG->tool_supporter_course_details_showvisible,
            'showpath'  => $CFG->tool_supporter_course_details_showpath,
            'showtimecreated'  => $CFG->tool_supporter_course_details_showtimecreated,
            'showusersamount'  => $CFG->tool_supporter_course_details_showusersamount,
            'showrolesandamount'  => $CFG->tool_supporter_course_details_showrolesandamount,
        );

        return (array)$data;
    }

    /**
     * Specifies return values
     * @return external_single_structure a course with addition information
     */
    public static function get_course_info_returns() {
        return new external_single_structure( array(
            'courseDetails' => new external_single_structure( array(
                'id' => new external_value(PARAM_INT, 'id of course'),
                'shortname' => new external_value(PARAM_RAW, 'shortname of course'),
                'fullname' => new external_value(PARAM_RAW, 'course name'),
                'visible' => new external_value(PARAM_BOOL, 'Is the course visible?'),
                'path' => new external_value(PARAM_RAW, 'path to course'),
                'enrolledUsers' => new external_value(PARAM_INT, 'number of users, without teachers'),
                'timecreated' => new external_value(PARAM_TEXT, 'time the course was created as readable date format'),
                'level_one' => new external_value(PARAM_TEXT, 'first level of the course'),
                'level_two' => new external_value(PARAM_TEXT, 'second level of the course')
            )),
            'config' => new external_single_structure( (array (
                'showshortname' => new external_value(PARAM_BOOL, "Config setting if courses shortname should be displayed"),
                'showfullname' => new external_value(PARAM_BOOL, "Config setting if courses fullname should be displayed"),
                'showvisible' => new external_value(PARAM_BOOL, "Config setting if courses visible status should be displayed"),
                'showpath' => new external_value(PARAM_BOOL, "Config setting if courses path should be displayed"),
                'showtimecreated' => new external_value(PARAM_BOOL, "Config setting if courses timecreated should be displayed"),
                'showusersamount' => new external_value(PARAM_BOOL, "Config setting if courses total amount of users should be displayed"),
                'showrolesandamount' => new external_value(PARAM_BOOL, "Config setting if courses roles and their amount should be displayed"),
            ))),
            'rolesincourse' => new external_multiple_structure (new external_value(PARAM_TEXT, 'array with roles used in course')),
            'roles' => new external_multiple_structure(
            new external_single_structure( array(
                'roleName' => new external_value(PARAM_RAW, 'name of one role in course'),
                'roleNumber' => new external_value(PARAM_INT, 'number of participants with role = roleName')
            ))),
            'users' => new external_multiple_structure(
                new external_single_structure( array(
                    'id' => new external_value(PARAM_INT, 'id of user'),
                    'username' => new external_value(PARAM_RAW, 'name of user'),
                    'firstname' => new external_value(PARAM_RAW, 'firstname of user'),
                    'lastname' => new external_value(PARAM_RAW, 'lastname of user'),
                    'lastaccess' => new external_value(PARAM_RAW, 'lastaccess of the user to the course'),
                    'roles' => new external_multiple_structure (new external_value(PARAM_TEXT, 'array with roles for each user')),
                    'enrol_id' => new external_value(PARAM_INT, 'id of user enrolment to course')
                ))),
                'activities' => new external_multiple_structure(
                new external_single_structure( array(
                    'section' => new external_value(PARAM_RAW, 'Name of section, in which the activity appears'),
                    'activity' => new external_value(PARAM_RAW, 'kind of activity'),
                    'name' => new external_value(PARAM_RAW, 'Name of this activity'),
                    'visible' => new external_value(PARAM_INT, 'Is the activity visible? 1: yes, 0: no')
                ))),
                'links' => new external_single_structure( array(
                    'settingslink' => new external_value(PARAM_RAW, 'link to the settings of the course'),
                    'deletelink' => new external_value(PARAM_RAW, 'link to delete the course if allowed, '
                        . 'additional affirmation needed afterwards', VALUE_OPTIONAL),
                    'courselink' => new external_value(PARAM_RAW, 'link to the course')
                )),
            'enrolmentMethods' => new external_multiple_structure(
                new external_single_structure( array(
                    'methodname' => new external_value(PARAM_TEXT, 'Name of the enrolment method'),
                    'enabled' => new external_value(PARAM_BOOL, 'Is method enabled'),
                    'users' => new external_value(PARAM_INT, 'Amount of users enrolled with this method')
                ))),
                'isallowedtoupdatecourse' => new external_value(PARAM_BOOL, "Is the user allowed to update the course globally?")
        ));
    }


    // ------------------------------------------------------------------------------------------------------------------------

    /**
     * Returns description of input parameters
     * @return external_function_parameters
     */
    public static function get_assignable_roles_parameters() {
        return new external_function_parameters( array(
            'courseID' => new external_value(PARAM_RAW, 'id of course you want to show')
        ));
    }

    /**
     * Wrapper for core function get_assignable_roles
     *
     * @param int $courseid Id of the course the roles are present
     */
    public static function get_assignable_roles($courseid) {
        global $CFG, $PAGE;

        $coursecontext = \context_course::instance($courseid);
        self::validate_context($coursecontext);
        // Is the user allowed to enrol a student into this course?
        \require_capability('enrol/manual:enrol', $coursecontext);

        // Parameter validation.
        $params = self::validate_parameters(self::get_course_info_parameters(), array('courseID' => $courseid));

        // Get assignable roles in the course.
        require_once($CFG->dirroot.'/enrol/locallib.php');
        $course = get_course($courseid);
        $manager = new \course_enrolment_manager($PAGE, $course);
        $usedroles = $manager->get_assignable_roles();

        $count = 0;
        $arrayofroles = [];
        foreach ($usedroles as $roleid => $rolename) {
            $arrayofroles[$count]['id'] = $roleid;
            $arrayofroles[$count]['name'] = $rolename;
            $count++;
        }

        // Put the student role in first place.
        $studentrole = array_values(get_archetype_roles('student'))[0];
        $count = 0;
        foreach ($arrayofroles as $role) {
            if ($role['id'] == $studentrole->id) {
                unset($arrayofroles[$count]);
                array_unshift($arrayofroles, $role);
            }
            $count++;
        }

        $data = array(
        'assignableRoles' => (array)$arrayofroles
        );

        return $data;
    }

    /**
     * Specifies return parameters
     * @return external_single_structure the assignable Roles
     */
    public static function get_assignable_roles_returns() {
        return new external_single_structure( array(
            'assignableRoles' => new external_multiple_structure(
                new external_single_structure( array(
                    'id' => new external_value(PARAM_INT, 'id of the role'),
                    'name' => new external_value(PARAM_RAW, 'Name of the role')
                ))
            )
        ));
    }

    // ------------------------------------------------------------------------------------------------------------------------

    /**
     * Returns description of input parameters
     * @return external_function_parameters
     */
    public static function toggle_course_visibility_parameters() {
        return new external_function_parameters(array(
            'courseID' => new external_value(PARAM_INT, 'id of course')
        ));
    }

    /**
     * Wrapper for core function toggle_course_visibility
     *
     * @param int $courseid Id of the course which is to be toggled
     */
    public static function toggle_course_visibility($courseid) {

        $coursecontext = \context_course::instance($courseid);
        self::validate_context($coursecontext);
        // Is the user allowed to change course_settings?
        \require_capability('moodle/course:update', $coursecontext);

        // Checking parameters.
        self::validate_parameters(self::toggle_course_visibility_parameters(), array('courseID' => $courseid));
        // Security checks.
        $coursecontext = \context_course::instance($courseid);
        self::validate_context($coursecontext);
        // Is the user allowed to change the visibility?
        \require_capability('moodle/course:visibility', $coursecontext);

        $course = self::get_course_info($courseid);
        // Second param is the desired visibility value.
        course_change_visibility($courseid, !($course['courseDetails']['visible']));
        $course['courseDetails']['visible'] = !$course['courseDetails']['visible'];

        return $course;
    }

    /**
     * Specifies return parameters
     * @return external_single_structure a course with toggled visibility
     */
    public static function toggle_course_visibility_returns() {
        return self::get_course_info_returns();
    }

    // ------------------------------------------------------------------------------------------------------------------------

    /**
     * Returns description of input parameters
     * @return external_function_parameters
     */
    public static function get_settings_parameters() {
        return new external_function_parameters(array());
    }

    /**
     * Wrapper for core function toggle_course_visibility
     *
     * @return array: See return-function
     */
    public static function get_settings() {

        global $CFG;

        $systemcontext = \context_system::instance();
        self::validate_context($systemcontext);

        $data = array (
            'tool_supporter_user_details_pagelength' => $CFG->tool_supporter_user_details_pagelength,
            'tool_supporter_user_details_order' => $CFG->tool_supporter_user_details_order,
            'tool_supporter_course_details_pagelength' => $CFG->tool_supporter_course_details_pagelength,
            'tool_supporter_course_details_order' => $CFG->tool_supporter_course_details_order,
            'tool_supporter_user_table_pagelength' => $CFG->tool_supporter_user_table_pagelength,
            'tool_supporter_user_table_order' => $CFG->tool_supporter_user_table_order,
            'tool_supporter_course_table_pagelength' => $CFG->tool_supporter_course_table_pagelength,
            'tool_supporter_course_table_order' => $CFG->tool_supporter_course_table_order,
        );

        return $data;
    }

    /**
     * Specifies return parameters
     * @return external_single_structure a course with toggled visibility
     */
    public static function get_settings_returns() {
        return new external_function_parameters(array(
            'tool_supporter_user_details_pagelength' => new external_value(PARAM_INT, 'Amount shown per page as detailed in settings/config'),
            'tool_supporter_user_details_order' => new external_value(PARAM_TEXT, 'Sorting of ID-Column, either ASC or DESC '),
            'tool_supporter_course_details_pagelength' => new external_value(PARAM_INT, 'Amount shown per page as detailed in settings/config'),
            'tool_supporter_course_details_order' => new external_value(PARAM_TEXT, 'Sorting of ID-Column, either ASC or DESC '),
            'tool_supporter_user_table_pagelength' => new external_value(PARAM_INT, 'Amount shown per page as detailed in settings/config'),
            'tool_supporter_user_table_order' => new external_value(PARAM_TEXT, 'Sorting of ID-Column, either ASC or DESC '),
            'tool_supporter_course_table_pagelength' => new external_value(PARAM_INT, 'Amount shown per page as detailed in settings/config'),
            'tool_supporter_course_table_order' => new external_value(PARAM_TEXT, 'Sorting of ID-Column, either ASC or DESC '),
        ));
    }
}
