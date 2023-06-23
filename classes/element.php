<?php
// This file is part of the customcert module for Moodle - http://moodle.org/
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
 * This file contains the customcert element period API.
 *
 * @package    customcertelement_period
 * @copyright  2023 David Herney @ BambuCo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace customcertelement_period;

defined('MOODLE_INTERNAL') || die();

/**
 * Active period.
 */
define('CUSTOMCERT_PERIOD_ACTIVE', '0');

/**
 * The customcert element period API.
 *
 * @package    customcertelement_period
 * @copyright  2023 David Herney @ BambuCo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class element extends \mod_customcert\element {

    /**
     * The example period.
     * Defined as 6 months.
     *
     * @var int
     */
    private static $exampleperiod = 15768000;

    /**
     * This function renders the form elements when adding a customcert element.
     *
     * @param \MoodleQuickForm $mform the edit_form instance
     */
    public function render_form_elements($mform) {
        global $CFG, $COURSE;

        // Get the possible date options.
        $dateoptions = [];
        $completionenabled = $CFG->enablecompletion && ($COURSE->id == SITEID || $COURSE->enablecompletion);
        if ($completionenabled) {
            $dateoptions[CUSTOMCERT_PERIOD_ACTIVE] = get_string('activeperiod', 'customcertelement_period');
        }

        $mform->addElement('select', 'perioditem', get_string('perioditem', 'customcertelement_period'), $dateoptions);
        $mform->addHelpButton('perioditem', 'perioditem', 'customcertelement_period');

        $mform->addElement('select', 'periodformat', get_string('periodformat', 'customcertelement_period'), self::get_formats());
        $mform->addHelpButton('periodformat', 'periodformat', 'customcertelement_period');

        parent::render_form_elements($mform);
    }

    /**
     * This will handle how form data will be saved into the data column in the
     * customcert_elements table.
     *
     * @param \stdClass $data the form data
     * @return string the json encoded array
     */
    public function save_unique_data($data) {
        // Array of data we will be storing in the database.
        $arrtostore = array(
            'perioditem' => $data->perioditem,
            'periodformat' => $data->periodformat
        );

        // Encode these variables before saving into the DB.
        return json_encode($arrtostore);
    }

    /**
     * Handles rendering the element on the pdf.
     *
     * @param \pdf $pdf the pdf object
     * @param bool $preview true if it is a preview, false otherwise
     * @param \stdClass $user the user we are rendering this for
     */
    public function render($pdf, $preview, $user) {
        global $DB;

        // If there is no element data, we have nothing to display.
        if (empty($this->get_data())) {
            return;
        }

        $courseid = \mod_customcert\element_helper::get_courseid($this->id);

        // Decode the information stored in the database.
        $periodinfo = json_decode($this->get_data());
        $perioditem = $periodinfo->perioditem;
        $periodformat = $periodinfo->periodformat;

        // If we are previewing this certificate then just show a demonstration period.
        if ($preview) {
            $period = self::$exampleperiod;
        } else {
            // Get the page.
            $page = $DB->get_record('customcert_pages', ['id' => $this->get_pageid()], '*', MUST_EXIST);
            // Get the customcert this page belongs to.
            $customcert = $DB->get_record('customcert', ['templateid' => $page->templateid], '*', MUST_EXIST);
            // Now we can get the issue for this user.
            $issue = $DB->get_record('customcert_issues', ['userid' => $user->id, 'customcertid' => $customcert->id],
                                    '*', IGNORE_MULTIPLE);

            if ($perioditem == CUSTOMCERT_PERIOD_ACTIVE) {
                // Get the first module completion date.
                $sql = "SELECT MIN(timemodified) as timemodified
                            FROM {course_modules_completion} c
                            JOIN {course_modules} cm ON cm.id = c.coursemoduleid
                            WHERE c.userid = :userid
                            AND cm.course = :courseid";

                $firstmodview = $DB->get_record_sql($sql, ['userid' => $issue->userid, 'courseid' => $courseid]);

                $firstmodview = $firstmodview && !empty($firstmodview->timemodified) ? $firstmodview->timemodified : null;

                // Get the course completion date.
                $sql = "SELECT MAX(c.timecompleted) as timecompleted
                            FROM {course_completions} c
                            WHERE c.userid = :userid
                            AND c.course = :courseid";

                $coursecomplete = null;
                $timecompleted = $DB->get_record_sql($sql, ['userid' => $issue->userid, 'courseid' => $courseid]);

                if ($timecompleted && !empty($timecompleted->timecompleted)) {
                    $coursecomplete = $timecompleted->timecompleted;
                }

                if ($coursecomplete) {
                    $period = $coursecomplete - $firstmodview;
                }
            }
        }

        // Ensure that a period has been set.
        if (!empty($period)) {
            \mod_customcert\element_helper::render_content($pdf, $this, $this->get_format_string($period, $periodformat));
        }
    }

    /**
     * Render the element in html.
     *
     * This function is used to render the element when we are using the
     * drag and drop interface to position it.
     *
     * @return string the html
     */
    public function render_html() {
        // If there is no element data, we have nothing to display.
        if (empty($this->get_data())) {
            return;
        }

        // Decode the information stored in the database.
        $periodinfo = json_decode($this->get_data());
        $perioditem = $periodinfo->perioditem;
        $periodformat = $periodinfo->periodformat;

        $example = self::$exampleperiod;
        return \mod_customcert\element_helper::render_html_content($this, $this->get_format_string($example, $periodformat));
    }

    /**
     * Sets the data on the form when editing an element.
     *
     * @param \MoodleQuickForm $mform the edit_form instance
     */
    public function definition_after_data($mform) {
        // Set the item and format for this element.
        if (!empty($this->get_data())) {
            $periodinfo = json_decode($this->get_data());

            $element = $mform->getElement('perioditem');
            $element->setValue($periodinfo->perioditem);

            $element = $mform->getElement('periodformat');
            $element->setValue($periodinfo->periodformat);
        }

        parent::definition_after_data($mform);
    }

    /**
     * This function is responsible for handling the restoration process of the element.
     *
     * We will want to update the course module the date element is pointing to as it will
     * have changed in the course restore.
     *
     * @param \restore_customcert_activity_task $restore
     */
    public function after_restore($restore) {
        global $DB;

        $periodinfo = json_decode($this->get_data());
        if ($newitem = \restore_dbops::get_backup_ids_record($restore->get_restoreid(), 'course_module', $periodinfo->perioditem)) {
            $periodinfo->perioditem = $newitem->newitemid;
            $DB->set_field('customcert_elements', 'data', $this->save_unique_data($periodinfo), ['id' => $this->get_id()]);
        }
    }

    /**
     * Helper function to return all the available formats.
     *
     * @return array the list of formats
     */
    public static function get_formats() {
        $period = self::$exampleperiod;

        $periodformats = [
            'hours' => round($period / HOURSECS),
            'days' => round($period / DAYSECS),
            'weeks' => round($period / WEEKSECS),
            'hoursmin' => round($period / HOURSECS),
            'daysmin' => round($period / DAYSECS),
            'weeksmin' => round($period / WEEKSECS),
        ];

        foreach ($periodformats as $type => $value) {
            $periodformats[$type] = get_string('periodtype_' . $type, 'customcertelement_period', $value);
        }

        return $periodformats;
    }

    /**
     * Returns the period in a readable format.
     *
     * @param int $period
     * @param string $periodformat
     * @return string
     */
    protected function get_format_string($period, $periodformat) {

        if (strpos($periodformat, 'hours') !== false) {
            $value = round($period / HOURSECS);
        } else if (strpos($periodformat, 'days') !== false) {
            $value = round($period / DAYSECS);
        } else if (strpos($periodformat, 'weeks') !== false) {
            $value = round($period / WEEKSECS);
        } else {
            $value = $period;
        }

        return get_string('periodtype_' . $periodformat, 'customcertelement_period', $value);
    }

}
