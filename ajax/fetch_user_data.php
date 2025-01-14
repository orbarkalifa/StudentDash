<?php
// Include Moodle configuration
require_once('../../../config.php');

// Authenticate user (if necessary)
require_login();

// Handle the AJAX request
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Perform any necessary operations here

    header('Access-Control-Allow-Origin: *');

    $user = $DB->get_record('user', array('idnumber' => $USER->idnumber));

    $data = array(
        'studentID' => $user->idnumber,
        'firstname' => $user->firstname,
        'lastname' => $user->lastname,
        'institution' => $user->institution,
        'department' => $user->department
    );

    // Set the appropriate headers to indicate JSON response and allow cross-origin requests
    header('Access-Control-Allow-Origin: http://localhost:3000');
    header('Access-Control-Allow-Methods: GET'); // Adjust if needed (POST, PUT, etc.)
    header('Access-Control-Allow-Headers: Content-Type'); // Adjust if needed
    header('Content-Type: application/json');


    // Output the user data as JSON
    echo json_encode($data);
} else {
    // Handle unsupported request methods (e.g., POST, PUT, DELETE)
    http_response_code(405); // Method Not Allowed
}
