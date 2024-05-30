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

// Set the appropriate headers to indicate JSON response and allow cross-origin requests
set_json_headers();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Fetch user details
    $user = $DB->get_record('user', array('id' => $USER->id));

    // Fetch grades for the current user
    $grades = fetch_user_grades($USER->id);

    // Calculate average grade for the user
    $averageGrade = calculate_average_grade($grades);

    // Fetch personal activities, exams, and zoom records for the user
    $courseId = $_GET['courseId'];
    $personalActivities = $DB->get_records('personal_activities', ['userid' => $USER->id, 'courseid' => $courseId]);
    $exams = $DB->get_records('exams', ['courseid' => $courseId]);
    $zoomRecords = $DB->get_records('zoom_records', ['courseid' => $courseId]);

    // Debugging: Log fetched data to error log
    error_log("Personal Activities: " . json_encode($personalActivities));
    error_log("Exams: " . json_encode($exams));
    error_log("Zoom Records: " . json_encode($zoomRecords));

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
        if (empty($activity['courseId']) || empty($activity['taskName']) || empty($activity['dueDate']) || empty($activity['modifyDate']) || empty($activity['status'])) {
            echo json_encode(['success' => false, 'error' => 'Invalid input']);
            exit;
        }

        // Insert the new personal activity into the personal_activities table
        $task = new stdClass();
        $task->userid = $USER->id;
        $task->courseid = $activity['courseId'];
        $task->taskname = $activity['taskName'];
        $task->duedate = strtotime($activity['dueDate']);
        $task->modifydate = strtotime($activity['ModifyDate']);
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
    handle_invalid_request();
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
        $roles = get_role_users(3, $context);  // Assuming role id 3 for lecturers
        $lecturer = reset($roles);  // Get the first lecturer found

        $courseData = array(
            'id' => $course->id,
            'fullname' => $course->fullname,
            'lecturer' => fullname($lecturer),
            'lectureremail' => $lecturer->email,
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

        $submissions = $DB->get_records('assign_submission', ['assignment' => $assignment->id, 'status' => 'submitted']);
        $students = get_enrolled_users($context, 'mod/assign:submit');
        $submission_percentage = count($submissions) / count($students) * 100;

        $assignment->$submission_percentage = $submission_percentage;

        $user_submission = $DB->get_record('assign_submission', ['assignment' => $assignment->id, 'userid' => $userId]);

        $has_submitted = empty($user_submission) ? "Not Submitted" : "Submitted";

        $tasks[] = [
            'task_id' => $assignment->id,
            'task_type' => 'Assignment',
            'task_name' => $assignment->name,
            'due_date' => gmdate("d-m-Y H:i", $assignment->duedate),
            'task_status' => $has_submitted,
            'modify_date' => !empty($user_submission) ? gmdate("d-m-Y", $user_submission->timemodified) : null,
            'submission_percentage' => $submission_percentage,
            'url' => (new moodle_url('/mod/assign/view.php', array('id' => $cm->id)))->out(false)
        ];
    }

    $quizzes = $DB->get_records('quiz', ['course' => $courseId]);

    foreach ($quizzes as $quiz) {
        $context = context_course::instance($courseId);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $courseId);

        $attempts = $DB->get_records('quiz_attempts', ['quiz' => $quiz->id, 'state' => 'finished']);
        $students = get_enrolled_users($context, 'mod/quiz:attempt');
        $submission_percentage = count($attempts) / count($students) * 100;

        $user_attempt = $DB->get_record('quiz_attempts', ['quiz' => $quiz->id, 'userid' => $userId, 'state' => 'finished']);
        $has_attempted = empty($user_attempt) ? "Not Attempted" : "Attempted";

        $tasks[] = [
            'task_id' => $quiz->id,
            'task_type' => 'Quiz',
            'task_name' => $quiz->name,
            'due_date' => gmdate("d-m-Y H:i", $quiz->timeclose),
            'task_status' => $has_attempted,
            'modify_date' => !empty($user_attempt) ? gmdate("d-m-Y", $user_attempt->timemodified) : null,
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

function fetch_course_schedule($courseId)
{
    global $DB;

    // Assuming events in mdl_event table are used to schedule lectures and classes
    $scheduleSQL = "
        SELECT
            CONCAT(u.firstname, ' ', u.lastname) AS lecturer_name,
            DAYNAME(FROM_UNIXTIME(e.timestart)) AS day_of_week,
            TIME_FORMAT(FROM_UNIXTIME(e.timestart), '%H:%i') AS start_time,
            TIME_FORMAT(FROM_UNIXTIME(e.timestart + e.timeduration), '%H:%i') AS end_time,
            CASE 
                WHEN e.eventtype = 'course' THEN 'lecture'
                WHEN e.eventtype = 'user' THEN 'practice'
                ELSE 'other'
            END AS type
        FROM
            {event} e
        JOIN
            {user} u ON u.id = e.userid
        WHERE
            e.courseid = :courseid
        ORDER BY
            e.timestart
    ";
    $schedule = $DB->get_records_sql($scheduleSQL, array('courseid' => $courseId));
    return array_values($schedule); // Ensure the result is returned as an array
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
