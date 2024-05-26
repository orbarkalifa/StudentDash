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
    $user =fetch_user_with_custom_fields($USER->id);

    // Fetch grades for the current user
    $grades = fetch_user_grades($USER->id);

    // Calculate average grade for the user
    $averageGrade = calculate_average_grade($grades);

    // Fetch personal activities for the user
    $courseId = $_GET['courseId'] ?? null;
    $personalActivities = $DB->get_records('personal_activities', ['userid' => $USER->id, 'courseid' => $courseId]) ??[];
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
function fetch_user_with_custom_fields($userId) {
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
    $semesterStart = new DateTime('2024-02-02');  // Example start date of the semester
    $semesterDuration = 20;  // Duration of the semester in weeks
    $currentDate = new DateTime();  // Today's date
    // Calculate the number of weeks since the semester started
    $weeksElapsed = $semesterStart->diff($currentDate)->days / 7;

    // Calculate the progression as a percentage
    $progression = min(100, ($weeksElapsed / $semesterDuration) * 100);

    foreach ($courses as $course) {
        // Fetch lecturer details
        $context = context_course::instance($course->id);
        $roles = get_role_users(3, $context);  // Assuming role id 3 for lecturers
        $lecturer = reset($roles);  // Get the first lecturer found

        $secondary_teachers = fetch_course_role_users($course->id, 9);  // Assuming 9 is for secondary teachers
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

            'secondary_teachers' => $secondary_teachers,
            'progression' => round($progression)
        );
        $userCourses[] = $courseData;
    }

    return $userCourses;
}
function fetch_course_role_users($courseId, $roleId) {
    global $DB;

    $sql = "SELECT u.id, u.firstname, u.lastname, u.email
            FROM {role_assignments} ra
            JOIN {user} u ON ra.userid = u.id
            JOIN {context} ctx ON ra.contextid = ctx.id
            WHERE ctx.instanceid = :courseid AND ctx.contextlevel = 50 AND ra.roleid = :roleid";

    return $DB->get_records_sql($sql, ['courseid' => $courseId, 'roleid' => $roleId]);
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
            'url' => (new moodle_url('/mod/assign/view.php', array('id' => $cm->id)))->out(false),
            'fileurl' => get_assignment_file_url($cm->id)
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

function fetch_course_events($userId, $courseId) {
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
function get_assignment_file_url($courseModuleId) {
    global $CFG;

    require_once($CFG->dirroot . '/mod/assign/locallib.php');

    $cm = get_coursemodule_from_id('assign', $courseModuleId, 0, false, MUST_EXIST);
    $context = context_module::instance($cm->id);

    $fs = get_file_storage();
    $files = $fs->get_area_files($context->id, 'mod_assign', 'intro', false, 'itemid, filepath, filename', false);

    foreach ($files as $file) {
        if ($file->is_directory()) continue; // Skip directories

        // Construct the URL for downloading the file
        $url = moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            $file->get_component(),
            $file->get_filearea(),
            $file->get_itemid(),
            $file->get_filepath(),
            $file->get_filename(),
            true // Forces the download
        );

        // Return the first file's URL for simplicity
        return $url->out(false);
    }

    return null; // Return null if no files were found
}

function fetch_course_schedule($courseId)
{
    global $DB;

    // Adjusted SQL query to ensure the first column is unique
    $scheduleSQL = "
        SELECT
            e.id AS event_id,  
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
    return array_values($schedule); // Return as a numerically indexed array instead of using event_id as keys
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

