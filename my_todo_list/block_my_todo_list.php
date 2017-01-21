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
 * My Todo List Block.
 *
 * @package   block_my_todo_list
 * @author    Larry Herbison
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once("{$CFG->libdir}/modinfolib.php");

class block_my_todo_list extends block_base {
    public function init() {
        $this->title = get_string('my_todo_list', 'block_my_todo_list');
    }

    public function get_content() {
        global $CFG, $DB, $USER, $COURSE;

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content	 =  new stdClass;
        $this->content->text = "";

        if(!$CFG->enablecompletion) {
            return $this->content;
        }
        
        $this->content->text = $this->get_course_content($USER, $COURSE);
        return $this->content;
    }

    private function get_course_content($user, $course) {
        global $DB;
        
        // Completion tracking needs to be enabled for the course
        if(!$course->enablecompletion) {
            return '';
        }
        
        // Check for student role
        $sql = "SELECT COUNT(*) cnt
                FROM {user_enrolments} ue
                JOIN {enrol} e ON e.id = ue.enrolid
                JOIN {role_assignments} ra ON ra.userid = ue.userid
                JOIN {context} ct ON ct.id = ra.contextid AND ct.contextlevel = 50 AND ct.instanceid = ?
                JOIN {role} r ON r.id = ra.roleid AND r.shortname = 'student'
                WHERE ue.userid = ?";
        $params = array($course->id, $user->id);
        $rec = $DB->get_record_sql($sql, $params);
        if(!$rec->cnt) {
            return '';
        }
        
        // Check for enroll end date
        $sql = "SELECT ue.timeend
                FROM {user_enrolments} ue
                JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = ?
                WHERE ue.userid = ?";
        $rec = $DB->get_record_sql($sql, $params);
        if(!$rec->timeend) {
            return '';
        }
        
        if(!($calendar_keyword = get_config('my_todo_list', 'calendarkeyword'))) {
            $calendar_keyword = 'School Day';
        }

        $content = '<table>';

        // Count "$calendar_keyword" event recs from today to enrol end date = $days
        $sql = "SELECT COUNT(*) AS days
                FROM {event} ev
                WHERE 
                    ev.name LIKE ?
                    AND ev.eventtype = 'site'
                    AND DATE(FROM_UNIXTIME(ev.timestart)) >= CURDATE()
                    AND DATE(FROM_UNIXTIME(ev.timestart)) <= 
                        (SELECT DATE(FROM_UNIXTIME(ue.timeend))
                         FROM {user_enrolments} ue
                         JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = ?
                         WHERE ue.userid = ?
                         LIMIT 1)";
        $params = array($calendar_keyword, $course->id, $user->id);
        $days = $DB->get_record_sql($sql, $params);

        // Find course_module items undone or done today; add up points = $total_points
        //   items must have completion tracking turned on to be included
        $midnight = usergetmidnight(time());
        $cmi = get_fast_modinfo($course->id);
        $cms = $cmi->get_cms();
        $left_off = 0;
        $looking_for_left_off = true;
        $complete = array();
        $points = array();
        foreach($cms as $cmkey => $cm) {
            $completion = $DB->get_record('course_modules_completion', 
                                          array('coursemoduleid' => $cm->id, 'userid' => $user->id));
            $sql = "SELECT gi.grademax
                    FROM {modules} m
                    JOIN {grade_items} gi ON m.name = gi.itemmodule
                    WHERE m.id = ? AND gi.iteminstance = ?";
            $params = array($cm->module, $cm->instance);
            $gi = $DB->get_record_sql($sql, $params);
            if($cm->completion != COMPLETION_TRACKING_NONE) {
                $complete[$cmkey] = !empty($completion) && $completion->completionstate;
                if(!empty($gi)) {
                    $points[$cmkey] = $gi->grademax;
                } else {
                    $points[$cmkey] = 0;
                }
                if(!$complete[$cmkey] || $completion->timemodified >= $midnight) {
                    $looking_for_left_off = false;
                } else {
                    if($this->config->continuefromlastcomplete || $looking_for_left_off) {
                        $left_off = $cm->id;
                    }
                }
            }
        }

        $total_points = 0;
        $counting = !$left_off;
        foreach($cms as $cmkey => $cm) {
            if($cm->completion != COMPLETION_TRACKING_NONE) {
                if($counting) {
                    $total_points += $points[$cmkey];
                } else {
                    $counting = $cm->id == $left_off;
                }
            }
        }

        // Divide $total_points by $days = $today_points
        $today_points = round($total_points / $days->days);

        // Choose first course items after last completed item that >= $today_points
        $selected_points = 0;
        $selecting = !$left_off;
        foreach($cms as $cmkey => $cm) {
            if($cm->completion != COMPLETION_TRACKING_NONE) {
                if($selecting) {
                    $content .= $this->insert_content($cm, $complete[$cmkey]);
                    $selected_points += $points[$cmkey];
                    if($selected_points >= $today_points) {
                        break;
                    }
                } else {
                    $selecting = $cm->id == $left_off;
                }
            }
        }
        $content .= '</table>';
        return $content;
    }

    private function insert_content($cm, $complete) {
        global $CFG, $OUTPUT;
        if($complete) {
            $checkmark = '<img class="icon" src="' . $OUTPUT->pix_url('i/valid') . '" />';
        } else {
            $checkmark = '';
        }
        return '<tr>' .
               '<td><img class="icon" src="' . $cm->get_icon_url() . '" /></td>' .
               '<td><a href="' . $cm->url . '">' . $cm->name . '</a></td>' .
               '<td>' . $checkmark . '</td>' .
               '</tr>';
    }

    public function instance_allow_multiple() {
        return false;
    }

    public function has_config() {
        return true;
    }

    public function html_attributes() {
        $attributes = parent::html_attributes(); // Get default values
        $attributes['class'] .= ' block_'. $this->name(); // Append our class to class attribute
        return $attributes;
    }
}

?>
