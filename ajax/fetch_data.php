<?php
// Include Moodle configuration
require_once('../../../config.php');
require_once($CFG->dirroot . '/calendar/lib.php'); // for fetching calendar events

// Authenticate user (if necessary)
require_login();

global $DB, $USER;

// Ensure the personal_activities table exists
ensure_personal_activities_table_exists();

// Set the appropriate headers to indicate JSON response and allow cross-origin requests
set_json_headers();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Fetch user details
    $user = $DB->get_record('user', array('id' => $USER->id));

    // Fetch grades for the current user
    $grades = fetch_user_grades($USER->id);

    // Calculate average grade for the user
    $averageGrade = calculate_average_grade($grades);

    // Fetch personal activities for the user
    $courseId = $_GET['courseId'] ?? null;
    $personalActivities = $DB->get_records('personal_activities', ['userid' => $USER->id, 'courseid' => $courseId]) ?? [];

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
        'personalActivities' => array_values($personalActivities)
    );

    // Output the response data as JSON
    echo json_encode($data);

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    // Validate the input
    if (empty($input['courseId']) || empty($input['taskName']) || empty($input['dueDate']) || empty($input['modifyDate']) || empty($input['status'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid input']);
        exit;
    }

    $courseId = $input['courseId'];
    $taskName = $input['taskName'];
    $dueDate = strtotime($input['dueDate']); // Convert to Unix timestamp
    $modifyDate = strtotime($input['modifyDate']); // Convert to Unix timestamp
    $status = $input['status'];

    // Insert the new personal activity into the personal_activities table
    $task = new stdClass();
    $task->userid = $USER->id;
    $task->courseid = $courseId;
    $task->taskname = $taskName;
    $task->duedate = $dueDate;
    $task->modifydate = $modifyDate;
    $task->status = $status;

    try {
        $taskId = $DB->insert_record('personal_activities', $task);
        echo json_encode(['success' => true, 'task_id' => $taskId]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['taskId'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid input']);
        exit;
    }

    $taskId = $input['taskId'];

    try {
        $DB->delete_records('personal_activities', ['id' => $taskId, 'userid' => $USER->id]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} else {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
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
            'exams' => fetch_course_exams($course->id)
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
            q.id AS exam_id,
            q.name AS exam_name,
            DATE(FROM_UNIXTIME(q.timeclose)) AS exam_date,
            TIME_FORMAT(FROM_UNIXTIME(q.timeclose), '%H:%i') AS exam_time,
            SEC_TO_TIME(q.timelimit) AS exam_duration,
            q.intro AS exam_location
        FROM
            {quiz} q
        WHERE
            q.course = :courseid
    ";
    $exams = $DB->get_records_sql($examSQL, array('courseid' => $courseId));

    foreach ($exams as $exam) $exam->exam_location = "Sapir College";

    return array_values($exams); // Ensure the result is returned as an array
}

function set_json_headers()
{
    header('Access-Control-Allow-Origin: http://localhost:3000');
    header('Access-Control-Allow-Methods: GET, POST, DELETE'); // Adjusted to allow DELETE
    header('Access-Control-Allow-Headers: Content-Type'); // Adjust if needed
    header('Content-Type: application/json');
}

function handle_invalid_request()
{
    http_response_code(405); // Method Not Allowed
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
}

