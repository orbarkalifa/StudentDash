<?php
// Include Moodle configuration
require_once('../../../config.php');
require_once($CFG->dirroot . '/calendar/lib.php'); // for fetching calendar events

// Authenticate user (if necessary)
require_login();

global $DB, $USER;

// Ensure the necessary tables exist
ensure_personal_activities_table_exists();
ensure_exams_table_exists();
ensure_zoom_records_table_exists();

// Delete past exams
delete_past_exams();

// Set the appropriate headers to indicate JSON response and allow cross-origin requests
set_json_headers();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['action']) && $_GET['action'] == 'download' && isset($_GET['taskid'])) {
        // Handle file download
        $taskId = $_GET['taskid'];
        $url = get_assignment_file_url($taskId);
        echo json_encode(['url' => $url]);
        exit;
    }
    // Fetch user details
    $user = $DB->get_record('user', array('id' => $USER->id));

    // Fetch grades for the current user
    $grades = fetch_user_grades($USER->id);

    $user = fetch_user_with_custom_fields($USER->id);
    // Calculate average grade for the user
    $averageGrade = calculate_average_grade($grades);

    // Fetch personal activities, exams, and zoom records for the user
    $courseId = $_GET['courseId'] ?? null;
    $personalActivities = $DB->get_records('personal_activities', ['userid' => $USER->id, 'courseid' => $courseId]);
    $exams = $DB->get_records('exams', ['courseid' => $courseId]);
    $zoomRecords = $DB->get_records('zoom_records', ['courseid' => $courseId]);

    // Initialize data array
    $data = array(
        'studentID' => $user->idnumber,
        'firstname' => $user->firstname,
        'lastname' => $user->lastname,
        'institution' => $user->institution,
        'department' => $user->department,
        'email' => $user->email,
        'phone' => $user->phone1,
        'gradesAverage' => $averageGrade,
        'major' => $user->major,
        'academic_year' => $user->academic_year,
        'courses' => fetch_user_courses($USER->id),
        'personalActivities' => array_values($personalActivities),
        'exams' => array_values($exams),
        'zoomRecords' => array_values($zoomRecords)
    );

    // Output the response data as JSON
    echo json_encode($data);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle POST request for adding personal activities and zoom records
    $input = json_decode(file_get_contents('php://input'), true);

    if (isset($input['personalActivity'])) {
        // Validate the input
        $activity = $input['personalActivity'];
        if (empty($activity['courseId']) || empty($activity['taskName']) || empty($activity['dueDate']) || empty($activity['status'])) {
            echo json_encode(['success' => false, 'error' => 'Invalid input']);
            exit;
        }

        // Insert the new personal activity into the personal_activities table
        $task = new stdClass();
        $task->userid = $USER->id;
        $task->courseid = $activity['courseId'];
        $task->taskname = $activity['taskName'];
        $task->duedate = strtotime($activity['dueDate']);
        $task->modifydate = strtotime($activity['dueDate']);
        $task->status = $activity['status'];

        try {
            $taskId = $DB->insert_record('personal_activities', $task);
            echo json_encode(['success' => true, 'task_id' => $taskId]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    if (isset($input['zoomRecord'])) {
        // Validate the input
        $record = $input['zoomRecord'];
        if (empty($record['courseId']) || empty($record['recordingType']) || empty($record['recordingName']) || empty($record['recordingDate']) || empty($record['status'])) {
            echo json_encode(['success' => false, 'error' => 'Invalid input']);
            exit;
        }

        // Insert the new Zoom record into the mdl_zoom_records table
        $zoomRecord = new stdClass();
        $zoomRecord->courseid = $record['courseId'];
        $zoomRecord->recording_type = $record['recordingType'];
        $zoomRecord->recording_name = $record['recordingName'];
        $zoomRecord->recording_date = strtotime($record['recordingDate']);
        $zoomRecord->status = $record['status'];

        try {
            $zoomRecordId = $DB->insert_record('mdl_zoom_records', $zoomRecord);
            echo json_encode(['success' => true, 'zoomRecordId' => $zoomRecordId]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    // Handle PATCH request for updating Zoom record status
    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['zoomRecordId']) || empty($input['status'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid input']);
        exit;
    }

    $zoomRecordId = $input['zoomRecordId'];
    $status = $input['status'];

    try {
        $DB->update_record('zoom_records', (object)['id' => $zoomRecordId, 'status' => $status]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    // Handle DELETE request for deleting personal activities
    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['taskId'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid input']);
        exit;
    }

    $taskId = $input['taskId'];

    try {
        $DB->delete_records('personal_activities', ['id' => $taskId]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} else {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    handle_invalid_request();
}

function fetch_user_with_custom_fields($userId)
{
    global $DB;

    $sql = "SELECT u.*,
                   max(CASE WHEN uf.shortname = 'major' THEN uid.data ELSE NULL END) AS major,
                   max(CASE WHEN uf.shortname = 'academic_year' THEN uid.data ELSE NULL END) AS academic_year
            FROM {user} u
            LEFT JOIN {user_info_data} uid ON uid.userid = u.id
            LEFT JOIN {user_info_field} uf ON uf.id = uid.fieldid
            WHERE u.id = :userid
            GROUP BY u.id";

    return $DB->get_record_sql($sql, ['userid' => $userId]);
}

function ensure_personal_activities_table_exists()
{
    global $DB;

    $table = new xmldb_table('personal_activities');

    if (!$DB->get_manager()->table_exists($table)) {
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('taskname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('duedate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('modifydate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        $DB->get_manager()->create_table($table);
    }
}

function ensure_exams_table_exists()
{
    global $DB;

    $table = new xmldb_table('exams');

    if (!$DB->get_manager()->table_exists($table)) {
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('exam_type', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('exam_date', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('exam_time', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('duration', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('location', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        $DB->get_manager()->create_table($table);
    }
}

function ensure_zoom_records_table_exists()
{
    global $DB;

    $table = new xmldb_table('zoom_records');

    if (!$DB->get_manager()->table_exists($table)) {
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('recording_type', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('recording_name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('recording_date', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('zoomurl', XMLDB_TYPE_CHAR, '255', null, null, null, null); // Add the new column
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        $DB->get_manager()->create_table($table);
    } else {
        // Add the new column if the table already exists
        if (!$DB->get_manager()->field_exists($table, 'zoomurl')) {
            $field = new xmldb_field('zoomurl', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'status');
            $DB->get_manager()->add_field($table, $field);
        }
    }
}

function fetch_user_grades($userId)
{
    global $DB;

    $gradesSQL = "
        SELECT
            gg.finalgrade AS grade
        FROM
            {grade_grades} gg
        JOIN
            {grade_items} gi ON gi.id = gg.itemid
        WHERE
            gi.itemtype = 'course'
            AND gg.userid = :userid
    ";

    return $DB->get_records_sql($gradesSQL, array('userid' => $userId));
}

function calculate_average_grade($grades)
{
    $totalGrades = 0;
    $count = count($grades);

    if ($count > 0) {
        foreach ($grades as $grade) {
            $totalGrades += $grade->grade;
        }
        return round($totalGrades / $count, 2);
    } else {
        return 0;
    }
}

function fetch_user_courses($userId)
{
    global $DB;

    $courses = enrol_get_all_users_courses($userId);
    $userCourses = array();

    foreach ($courses as $course) {
        // Fetch lecturer details
        $context = context_course::instance($course->id);
        $roles_lecturers = get_role_users(3, $context);  // Assuming role id 3 for lecturers
        $lecturer = reset($roles_lecturers);  // Get the first lecturer found

        // Fetch practitioner details
        $roles_practitioners = get_role_users(4, $context);  // Assuming role id 4 for practitioners
        $practitioner = reset($roles_practitioners);  // Get the first practitioner found

        $courseData = array(
            'id' => $course->id,
            'fullname' => $course->fullname,
            'lecturer' => fullname($lecturer),
            'lectureremail' => $lecturer->email,
            'practitioner' => fullname($practitioner),
            'url' => (new moodle_url('/course/view.php', array('id' => $course->id)))->out(false),
            'tasks' => fetch_course_tasks($userId, $course->id),
            'events' => fetch_course_events($userId, $course->id),
            'schedule' => fetch_course_schedule($course->id),
            'exams' => fetch_course_exams($course->id),
            'zoomRecords' => fetch_course_zoom_records($course->id)
        );
        $userCourses[] = $courseData;
    }

    return $userCourses;
}

function fetch_course_tasks($userId, $courseId)
{
    global $DB;

    $tasks = array();

    $assignments = $DB->get_records('assign', ['course' => $courseId]);

    foreach ($assignments as $assignment) {
        $context = context_course::instance($courseId);
        $cm = get_coursemodule_from_instance('assign', $assignment->id, $courseId);

        if (!$cm) {
            continue; // Skip this assignment if course module is not found
        }

        $submissions = $DB->get_records('assign_submission', ['assignment' => $assignment->id, 'status' => 'submitted']);
        $students = get_enrolled_users($context, 'mod/assign:submit');
        $submission_percentage = count($students) > 0 ? count($submissions) / count($students) * 100 : 100;

        $user_submission = $DB->get_record('assign_submission', ['assignment' => $assignment->id, 'userid' => $userId]);

        $has_submitted = empty($user_submission) ? "Not Submitted" : "Submitted";

        $tasks[] = [
            'task_id' => $assignment->id,
            'task_type' => 'Assignment',
            'task_name' => $assignment->name,
            'due_date' => gmdate("d/m/Y, H:i", $assignment->duedate),
            'task_status' => $has_submitted,
            'modify_date' => !empty($user_submission) ? gmdate("d/m/Y", $user_submission->timemodified) : null,
            'submission_percentage' => $submission_percentage,
            'url' => (new moodle_url('/mod/assign/view.php', array('id' => $cm->id)))->out(false),
            'fileurl' => get_assignment_file_url($cm->id)
        ];
    }

    $quizzes = $DB->get_records('quiz', ['course' => $courseId]);

    foreach ($quizzes as $quiz) {
        $context = context_course::instance($courseId);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $courseId);

        if (!$cm) {
            continue; // Skip this quiz if course module is not found
        }

        $attempts = $DB->get_records('quiz_attempts', ['quiz' => $quiz->id, 'state' => 'finished']);
        $students = get_enrolled_users($context, 'mod/quiz:attempt');
        $submission_percentage = count($attempts) / count($students) * 100;

        $user_attempt = $DB->get_record('quiz_attempts', ['quiz' => $quiz->id, 'userid' => $userId, 'state' => 'finished']);
        $has_attempted = empty($user_attempt) ? "Not Attempted" : "Attempted";

        $tasks[] = [
            'task_id' => $quiz->id,
            'task_type' => 'Quiz',
            'task_name' => $quiz->name,
            'due_date' => gmdate("d/m/Y, H:i", $quiz->timeclose),
            'task_status' => $has_attempted,
            'modify_date' => !empty($user_attempt) ? gmdate("d/m/Y", $user_attempt->timemodified) : null,
            'submission_percentage' => $submission_percentage,
            'url' => (new moodle_url('/mod/quiz/view.php', ['id' => $cm->id]))->out(false)
        ];
    }

    return $tasks;
}

function fetch_course_events($userId, $courseId)
{
    global $DB;

    $start = strtotime('today');
    $end = strtotime('+365 days', $start);
    $calendarEvents = calendar_get_legacy_events($start, $end, array($userId), false, $courseId);

    $courseEvents = array();
    foreach ($calendarEvents as $event) {
        $courseEvents[] = array(
            'id' => $event->id,
            'name' => $event->name,
            'description' => $event->description,
            'timestart' => date("F j, Y", $event->timestart),
            'timeduration' => $event->timeduration
        );
    }

    return $courseEvents;
}

function get_assignment_file_url($courseModuleId)
{
    global $CFG;

    require_once($CFG->dirroot . '/mod/assign/locallib.php');

    // Get the course module using the provided ID
    $cm = get_coursemodule_from_id('assign', $courseModuleId, 0, false, MUST_EXIST);

    if (!$cm) {
        return null; // Return null if no course module found
    }

    // Obtain context for the course module
    $context = context_module::instance($cm->id);

    // Obtain the file storage instance
    $fileStorage = get_file_storage();
    // Retrieve files from the specified area
    $assignmentFiles = $fileStorage->get_area_files($context->id, 'mod_assign', 'introattachment', 0, 'itemid, filepath, filename', false);

    // Process files to find the first valid file URL
    return find_first_file_url($assignmentFiles);
}

function find_first_file_url($files)
{
    foreach ($files as $file) {
        if (!$file->is_directory()) { // Ensure the file is not a directory
            // Generate and return the URL for the file
            return generate_file_url($file)->out(false);
        }
    }
    return null; // Return null if no suitable file is found
}

function generate_file_url($file)
{
    // Generate a Moodle URL for the specified file
    return moodle_url::make_pluginfile_url(
        $file->get_contextid(),
        $file->get_component(),
        $file->get_filearea(),
        $file->get_itemid(),
        $file->get_filepath(),
        $file->get_filename(),
        true // Forces the download
    );
}

function fetch_course_schedule($courseid)
{
    global $DB;

    // SQL query to fetch schedule data
    $sql = "
    SELECT
        e.id,
        e.name,
        CASE 
            WHEN e.name LIKE 'Lecture%' THEN 'Lecture'
            WHEN e.name LIKE 'Practice%' THEN 'Practice'
            ELSE 'Other'
        END AS type,
        DAYNAME(FROM_UNIXTIME(e.timestart)) AS day_of_week,
        TIME_FORMAT(FROM_UNIXTIME(e.timestart), '%H:%i') AS start_time,
        TIME_FORMAT(FROM_UNIXTIME(e.timestart + e.timeduration), '%H:%i') AS end_time,
        e.timestart < UNIX_TIMESTAMP() AS past_event
    FROM
        {event} e
    JOIN
        {user} u ON u.id = e.userid
    WHERE
        e.courseid = :courseid
    ORDER BY
        e.timestart
    ";

    $params = ['courseid' => $courseid];
    $schedule = $DB->get_records_sql($sql, $params);

    $result = [
        'lectures' => [
            'day' => '',
            'time' => '',
            'done' => 0
        ],
        'practices' => [
            'day' => '',
            'time' => '',
            'done' => 0
        ]
    ];

    foreach ($schedule as $event) {
        $event_data = [
            'day' => $event->day_of_week,
            'time' => $event->start_time . ' - ' . $event->end_time,
        ];

        if ($event->type == 'Lecture') {
            $result['lectures']['day'] = $event->day_of_week;
            $result['lectures']['time'] = $event->start_time . ' - ' . $event->end_time;
            if ($event->past_event) {
                $result['lectures']['done']++;
            }
        } elseif ($event->type == 'Practice') {
            $result['practices']['day'] = $event->day_of_week;
            $result['practices']['time'] = $event->start_time . ' - ' . $event->end_time;
            if ($event->past_event) {
                $result['practices']['done']++;
            }
        }
    }

    return $result;
}

function fetch_course_exams($courseId)
{
    global $DB;

    $examSQL = "
        SELECT
            id,
            courseid,
            exam_type,
            exam_date,
            exam_time,
            duration,
            location
        FROM
            {exams}
        WHERE
            courseid = :courseid
    ";
    $exams = $DB->get_records_sql($examSQL, array('courseid' => $courseId));
    return array_values($exams); // Ensure the result is returned as an array
}

function fetch_course_zoom_records($courseId)
{
    global $DB;

    $zoomSQL = "
        SELECT
            id,
            courseid,
            recording_type,
            recording_name,
            recording_date,
            status
        FROM
            {zoom_records}
        WHERE
            courseid = :courseid
    ";
    $zoomRecords = $DB->get_records_sql($zoomSQL, array('courseid' => $courseId));
    return array_values($zoomRecords); // Ensure the result is returned as an array
}

function fetch_course_role_users($courseId, $roleId)
{
    global $DB;

    $sql = "SELECT u.id, u.firstname, u.lastname, u.email
            FROM {role_assignments} ra
            JOIN {user} u ON ra.userid = u.id
            JOIN {context} ctx ON ra.contextid = ctx.id
            WHERE ctx.instanceid = :courseid AND ctx.contextlevel = 50 AND ra.roleid = :roleid";

    return $DB->get_records_sql($sql, ['courseid' => $courseId, 'roleid' => $roleId]);
}

function delete_past_exams()
{
    global $DB;

    $now = time();
    $DB->delete_records_select('exams', 'exam_date < ?', [$now]);
}

function set_json_headers()
{
    header('Access-Control-Allow-Origin: http://localhost:3000');
    header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE'); // Adjusted to allow PATCH and DELETE
    header('Access-Control-Allow-Headers: Content-Type'); // Adjust if needed
    header('Content-Type: application/json');
}

function handle_invalid_request()
{
    http_response_code(405); // Method Not Allowed
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
}
?>
