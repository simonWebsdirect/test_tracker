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
 * @package mod_tracker
 * @category mod
 * @author Clifford Tham, Valery Fremaux from Moodle 1.8 ahead
 * @copyright  2007 MyLearningFactory (http://www.mylearningfactory.com)
 * @date 02/12/2007
 *
 * This page prints a particular instance of a tracker and handles
 * top level interactions
 */
require('../../config.php');
require_once($CFG->dirroot.'/mod/tracker/lib.php');
require_once($CFG->dirroot.'/mod/tracker/locallib.php');

$PAGE->requires->js('/mod/tracker/js/trackerview.js');

// Check for required parameters.
$id = optional_param('id', 0, PARAM_INT); // Course Module ID
$t  = optional_param('t', 0, PARAM_INT);  // Tracker instance ID.
$issueid = optional_param('issueid', '', PARAM_INT);  // Ticket number.
$action = optional_param('what', '', PARAM_ALPHA);

if ($id) {
    if (! $cm = get_coursemodule_from_id('tracker', $id)) {
        print_error('errorcoursemodid', 'tracker');
    }
    if (! $course = $DB->get_record('course', array('id' => $cm->course))) {
        print_error('errorcoursemisconfigured', 'tracker');
    }
    if (! $tracker = $DB->get_record('tracker', array('id' => $cm->instance))) {
        print_error('errormoduleincorrect', 'tracker');
    }
} else {
    if (! $tracker = $DB->get_record('tracker', array('id' => $t))) {
        print_error('errormoduleincorrect', 'tracker');
    }
    if (! $course = $DB->get_record('course', array('id' => $tracker->course))) {
        print_error('errorcoursemisconfigured', 'tracker');
    }
    if (! $cm = get_coursemodule_from_instance("tracker", $tracker->id, $course->id)) {
        print_error('errorcoursemodid', 'tracker');
    }
}

$screen = tracker_resolve_screen($tracker, $cm);
$view = tracker_resolve_view($tracker, $cm);

$url = new moodle_url('/mod/tracker/view.php', array('id' => $cm->id, 'view' => $view, 'screen' => $screen));

// Redirect (before outputting) traps.
if ($view == "view" && (empty($screen) || $screen == 'viewanissue' || $screen == 'editanissue') && empty($issueid)) {
    redirect(new moodle_url('/mod/tracker/view.php', array('id' => $cm->id, 'view' => 'view', 'screen' => 'browse')));
}
if ($view == 'reportanissue') {
    redirect(new moodle_url('/mod/tracker/reportissue.php', array('id'=> $id)));
}

// Implicit routing.

if ($issueid) {
    $view = 'view';
    if (empty($screen)) {
        $screen = 'viewanissue';
    }
}

// Security.

require_course_login($course->id, true, $cm);

$context = context_module::instance($cm->id);

// Trigger module viewed event.
$eventparams = array(
    'objectid' => $tracker->id,
    'context' => $context,
);

// require_once($CFG->dirroot.'/mod/tracker/classes/event/course_module_viewed.php');
$event = \mod_tracker\event\course_module_viewed::create($eventparams);
$event->add_record_snapshot('tracker', $tracker);
$event->trigger();

tracker_loadpreferences($tracker->id, $USER->id);

// Search controller - special implementation.
// TODO : consider incorporing this controller back into standard MVC

if ($action == 'searchforissues') {
    $search = optional_param('search', null, PARAM_CLEANHTML);
    $saveasreport = optional_param('saveasreport', null, PARAM_CLEANHTML);

    // Search for issues.
    if (!empty($search)) {
        tracker_searchforissues($tracker, $cm->id);
    } elseif (!empty ($saveasreport)) {
        // Save search as a report.
        tracker_saveasreport($tracker->id);
    }
} elseif ($action == 'viewreport') {
    tracker_viewreport($tracker->id);
} elseif ($action == 'clearsearch') {
    if (tracker_clearsearchcookies($tracker->id)) {
        $returnview = ($tracker->supportmode == 'bugtracker') ? 'browse' : 'mytickets';
        redirect("view.php?id={$cm->id}&amp;view=view&amp;screen={$returnview}");
    }
}

$strtrackers = get_string('modulenameplural', 'tracker');
$strtracker  = get_string('modulename', 'tracker');

if ($view == 'reports') {
    require_once($CFG->dirroot.'/mod/tracker/js/jqplotlib.php');
    tracker_require_jqplot_libs();
}

$PAGE->set_context($context);
$PAGE->set_title(format_string($tracker->name));
$PAGE->set_heading(format_string($tracker->name));
$PAGE->set_url($url);
//$PAGE->set_button($OUTPUT->update_module_button($cm->id, 'tracker'));

if ($screen == 'print') {
    $PAGE->set_pagelayout('embedded');
}

$renderer = $PAGE->get_renderer('tracker');

// Process controllers

$result = 0 ;
if ($view == 'view') {
    if ($action != '') {
        $result = include($CFG->dirroot.'/mod/tracker/views/view.controller.php');
    }
} elseif ($view == 'resolved') {
    if ($action != '') {
        $result = include($CFG->dirroot.'/mod/tracker/views/view.controller.php');
    }
} elseif ($view == 'admin') {
    if ($action != '') {
        $result = include($CFG->dirroot.'/mod/tracker/views/admin.controller.php');
    }
} elseif ($view == 'profile') {
    if ($action != '') {
        $result = include($CFG->dirroot.'/mod/tracker/views/profile.controller.php');
    }
}

echo $OUTPUT->header();

echo $OUTPUT->box_start('', 'tracker-view');
echo $renderer->tabs($view, $screen, $tracker, $cm);

// A pre-buffer that may be a controller output.
if (!empty($out)) {
    echo $out;
}

/*
 * Print the main part of the page
 *
 * routing to appropriate view against situation
 */

if ($view == 'view') {
    if ($result != -1) {
        switch ($screen) {
            case 'mytickets':
                $resolved = 0;
                include($CFG->dirroot.'/mod/tracker/views/viewmyticketslist.php');
                break;
            case 'mywork':
                $resolved = 0;
                include($CFG->dirroot.'/mod/tracker/views/viewmyassignedticketslist.php');
                break;
            case 'browse':
                if (!has_capability('mod/tracker:viewallissues', $context)) {
                    print_error ('errornoaccessallissues', 'tracker');
                } else {
                    $resolved = 0;
                    include($CFG->dirroot.'/mod/tracker/views/viewissuelist.php');
                }
                break;
            case 'search':
                include($CFG->dirroot.'/mod/tracker/views/searchform.html');
                break;
            case 'viewanissue' :
                // If user it trying to view an issue, check to see if user has privileges to view this issue
                if (!has_any_capability(array('mod/tracker:seeissues','mod/tracker:resolve','mod/tracker:develop','mod/tracker:manage'), $context)) {
                    print_error('errornoaccessissue', 'tracker');
                } else {
                    include($CFG->dirroot.'/mod/tracker/views/viewanissue.html');
                }
                break;
            case 'editanissue' :
                if (!has_capability('mod/tracker:manage', $context)) {
                    print_error('errornoaccessissue', 'tracker');
                } else {
                    include($CFG->dirroot.'/mod/tracker/views/editanissue.html');
                }
                break;
        }
    }
} elseif ($view == 'resolved') {
    if ($result != -1) {
        switch ($screen) {
            case 'mytickets':
                $resolved = 1;
                include($CFG->dirroot.'/mod/tracker/views/viewmyticketslist.php');
                break;

            case 'mywork':
                $resolved = 1;
                include($CFG->dirroot.'/mod/tracker/views/viewmyassignedticketslist.php');
                break;

            case 'browse':
                if (!has_capability('mod/tracker:viewallissues', $context)) {
                    print_error('errornoaccessallissues', 'tracker');
                } else {
                    $resolved = 1;
                    include($CFG->dirroot.'/mod/tracker/views/viewissuelist.php');
                }
                break;
        }
    }
} elseif ($view == 'reports') {
    $result = 0;
    if ($result != -1) {
        switch ($screen) {
            case 'status':
                include($CFG->dirroot.'/mod/tracker/report/status.html');
                break;
            case 'evolution':
                include($CFG->dirroot.'/mod/tracker/report/evolution.html');
                break;
            case 'print':
                include($CFG->dirroot.'/mod/tracker/report/print.html');
                break;
        }
    }
} elseif ($view == 'admin') {
    if ($result != -1) {
        switch ($screen) {
            case 'summary':
                include($CFG->dirroot.'/mod/tracker/views/admin_summary.html');
                break;
            case 'manageelements':
                include($CFG->dirroot.'/mod/tracker/views/admin_manageelements.html');
                break;
            case 'managenetwork':
                include($CFG->dirroot.'/mod/tracker/views/admin_mnetwork.html');
                break;
        }
    }
} elseif ($view == 'profile') {
    if ($result != -1) {
        switch ($screen) {
            case 'myprofile' :
                include($CFG->dirroot.'/mod/tracker/views/profile.html');
                break;
            case 'mypreferences' :
                include($CFG->dirroot.'/mod/tracker/views/mypreferences.html');
                break;
            case 'mywatches' :
                include($CFG->dirroot.'/mod/tracker/views/mywatches.html');
                break;
            case 'myqueries':
                include($CFG->dirroot.'/mod/tracker/views/myqueries.html');
                break;
        }
    }
} else {
    print_error('errorfindingaction', 'tracker', $action);
}

echo $OUTPUT->box_end();

if ($course->format == 'page') {
    include_once($CFG->dirroot.'/course/format/page/xlib.php');
    page_print_page_format_navigation($cm, $backtocourse = false);
} else {
    if ($COURSE->format != 'singleactivity') {
        echo '<div style="text-align:center;margin:8px">';
        echo $OUTPUT->single_button(new moodle_url('/course/view.php', array('id' => $course->id)), get_string('backtocourse', 'tracker'), 'post', array('class' => 'backtocourse'));
        echo '</div>';
    }
}

echo $OUTPUT->footer();
