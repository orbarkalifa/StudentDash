<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * custom dashboard ui page.
 *
 * @package     local_studentdash
 * @copyright   2024 Or Bar Califa <you@example.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');

$PAGE->set_context(context_system::instance());


$PAGE->set_url('/local/studentdash/dashboard.php');
$PAGE->set_heading('');
$PAGE->set_title('StudentDash');

$data = (object)[
    'name' => $USER->firstname,
];

echo $OUTPUT->header();

echo $OUTPUT->render_from_template('local_studentdash/dashboard', $data);

echo $OUTPUT->footer();


