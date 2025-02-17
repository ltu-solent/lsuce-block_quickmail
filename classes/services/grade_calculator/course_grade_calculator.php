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
 * @package    block_quickmail
 * @copyright  2008 onwards Louisiana State University
 * @copyright  2008 onwards Chad Mazilly, Robert Russo, Jason Peak, Dave Elliott, Adam Zapletal, Philip Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_quickmail\services\grade_calculator;

defined('MOODLE_INTERNAL') || die();

use block_quickmail\services\grade_calculator\course_grade_report;
use block_quickmail\services\grade_calculator\calculation_exception;

require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/grade/report/lib.php');

class course_grade_calculator {

    public $courseid;
    public $coursecontext;
    public $coursegradeitem;

    /**
     * Constructs the course grade calculator
     *
     * @param int  $courseid    course id
     */
    public function __construct($courseid) {
        $this->courseid = $courseid;
        $this->set_context();
        $this->set_grade_item();
    }

    /**
     * Returns a grade calculator for the given course, defaulting to null if cannot be
     * calculated
     *
     * @param  int  $courseid
     * @return self|null
     */
    public static function for_course($courseid) {
        try {
            return new self($courseid);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Returns a final course total grade value for the given user in the given format
     *
     * @param  int     $userid
     * @param  string  $displaytype  real|percentage|letter|round
     * @return mixed
     */
    public function get_user_course_grade($userid, $displaytype = 'round') {
        if ($this->coursegradeitem->hidden) {
            $this->throw_calculation_exception($userid);
        }

        $usergradegrade = new \grade_grade([
            'itemid' => $this->coursegradeitem->id,
            'userid' => $userid
        ]);

        $usergradegrade->grade_item =& $this->coursegradeitem;

        $finalgrade = $usergradegrade->finalgrade;

        $report = $this->get_course_grade_report_for_user($userid);

        if (!has_capability('moodle/grade:viewhidden', $this->coursecontext, $userid) && !is_null($finalgrade)) {
            $adjustedgrade = $report->get_blank_hidden_total_and_adjust_bounds(
                $this->courseid,
                $this->coursegradeitem,
                $finalgrade
            );

            $this->coursegradeitem->grademax = $adjustedgrade['grademax'];
            $this->coursegradeitem->grademin = $adjustedgrade['grademin'];
        } else if (!is_null($finalgrade)) {
            $adjustedgrade = $report->get_blank_hidden_total_and_adjust_bounds(
                $this->courseid,
                $this->coursegradeitem,
                $finalgrade
            );

            $this->coursegradeitem->grademin = $usergradegrade->get_grade_min();
            $this->coursegradeitem->grademax = $usergradegrade->get_grade_max();
        }

        if (!isset($adjustedgrade)) {
            $this->throw_calculation_exception($userid);
        }

        $uselocalizeddecimal = false;
        $displaydecimals = null;

        $totalgrade = grade_format_gradevalue($adjustedgrade['grade'],
                          $this->coursegradeitem,
                          $uselocalizeddecimal,
                          $this->get_display_type($displaytype),
                          $displaydecimals);

        // If the requested display type is "round", round the value to no decimal places.
        if ($displaytype == 'round') {
            $explode = explode(' ', $totalgrade);
            $first = reset($explode);
            $totalgrade = (int) round($first, 0, PHP_ROUND_HALF_DOWN);
        }

        return $totalgrade;
    }

    /**
     * Sets the course context
     */
    private function set_context() {
        $this->coursecontext = \context_course::instance($this->courseid);
    }

    /**
     * Sets a the course total grade item for this course
     *
     * @throws calculation_exception
     */
    private function set_grade_item() {
        try {
            if (!$coursegradeitem = \grade_item::fetch_course_item($this->courseid)) {
                throw new \Exception;
            }

            $this->coursegradeitem = $coursegradeitem;
        } catch (\Exception $e) {
            $this->throw_calculation_exception(null, 'Could not fetch the grade item for the course.');
        }
    }

    /**
     * Returns an instantiated extension of the grade report for this course and user
     *
     * @param  int  $userid
     * @return course_grade_report
     * @throws calculation_exception
     */
    private function get_course_grade_report_for_user($userid) {
        try {
            return new course_grade_report($this->courseid, $this->coursecontext, $userid);
        } catch (\Exception $e) {
            $this->throw_calculation_exception($userid);
        }
    }

    /**
     * Returns the moodle constant for the given short type display
     *
     * @param  string  $type  real|percentage|letter|round
     * @return const
     */
    private function get_display_type($type) {
        switch ($type) {
            case 'real':
                return GRADE_DISPLAY_TYPE_REAL;
                break;

            case 'letter':
                return GRADE_DISPLAY_TYPE_LETTER;
                break;

            case 'percentage':
            case 'round':
            default:
                return GRADE_DISPLAY_TYPE_PERCENTAGE;
                break;
        }
    }

    /**
     * Throw a calculation exception with the given message
     *
     * @param  mixed|int  $userid   optional, defaulting to null
     * @param  string     $message
     * @return void
     * @throws calculation_exception
     */
    private function throw_calculation_exception(
                         $userid = null,
                         // TODO: Localize these strings.
                         $message = 'Could not calculate final course grade for this user.') {
        throw new calculation_exception('Could not fetch the grade item for the course.', $this->courseid, $userid);
    }

}
