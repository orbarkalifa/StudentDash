<?php
namespace local_studentdash\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/moodlelib.php'); // For accessing Moodle database
require_once($CFG->dirroot . '/vendor/autoload.php'); // Include SendGrid library

use SendGrid;
use SendGrid\Mail\Mail;

class send_exam_alerts_task extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('send_exam_alerts', 'local_studentdash');
    }

    public function execute() {
        global $DB;

        mtrace("Starting send_exam_alerts_task...");

        $now = time();
        $one_day = 86400; // One day in seconds
        $alert_days = [7, 3, 1]; // Days before the exam to send alerts

        foreach ($alert_days as $days_before) {
            $start_date = $now + ($days_before * $one_day);
            $end_date = $start_date + $one_day - 1;

            mtrace("Checking exams between {$start_date} and {$end_date}...");

            $sql = "
                SELECT e.*, c.fullname as coursename, u.email
                FROM {exams} e
                JOIN {course} c ON e.courseid = c.id
                JOIN {enrol} en ON en.courseid = e.courseid
                JOIN {user_enrolments} ue ON ue.enrolid = en.id
                JOIN {user} u ON u.id = ue.userid
                WHERE e.exam_date BETWEEN :start_date AND :end_date
            ";

            $upcoming_exams = $DB->get_records_sql($sql, [
                'start_date' => $start_date,
                'end_date' => $end_date,
            ]);

            foreach ($upcoming_exams as $exam) {
                mtrace("Exam ID: {$exam->id}, Course: {$exam->coursename}, Email: {$exam->email}, Exam Date: {$exam->exam_date}");

                $days_left = ceil(($exam->exam_date - $now) / $one_day);
                $subject = "Upcoming Exam Date";
                $body = "
                    Please note,
                    In the {$exam->coursename} course, the {$exam->exam_type} will take place in {$days_left} days.
                    Best regards,
                    The StudentDash Team
                ";

                // Send email using SendGrid
                $email = new Mail();
                $email->setFrom("conx@mail.sapir.ac.il", "StudentDash Team");
                $email->setSubject($subject);
                $email->addTo($exam->email);
                $email->addContent("text/plain", strip_tags($body));
                $email->addContent("text/html", nl2br($body));

                $sendgrid = new SendGrid('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'); // Replace with your SendGrid API key
                try {
                    $response = $sendgrid->send($email);
                    if ($response->statusCode() >= 200 && $response->statusCode() < 300) {
                        mtrace("Email sent to: {$exam->email}");
                    } else {
                        mtrace("Failed to send email to: {$exam->email}. Status Code: " . $response->statusCode());
                        mtrace("Response: " . print_r($response->body(), true));
                    }
                } catch (Exception $e) {
                    mtrace("Error sending email to: {$exam->email}. Error: {$e->getMessage()}");
                }
            }
        }

        mtrace("Finished send_exam_alerts_task.");
    }
}
?>
