<?php
// Include the database connection
require_once 'connect.php';

header('Content-Type: application/json');

// Get parameters
$course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : null;
$username = isset($_GET['username']) ? $_GET['username'] : null;

if (!$course_id || !$username) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit;
}

// Verify lecturer
$lecturer_query = "SELECT l.LecturerID FROM dbo.Users u 
                   JOIN dbo.Lecturers l ON u.UserID = l.UserID 
                   WHERE u.Username = ?";
$params = array($username);
$lecturer_result = sqlsrv_query($conn, $lecturer_query, $params);

if ($lecturer_result === false || !($lecturer_row = sqlsrv_fetch_array($lecturer_result, SQLSRV_FETCH_ASSOC))) {
    echo json_encode(['success' => false, 'message' => 'Lecturer not found']);
    exit;
}

$lecturer_id = $lecturer_row['LecturerID'];

// Fetch sessions for the course
$sessions_query = "SELECT SessionID, SessionDate, SessionStartTime, SessionEndTime, 
                         SessionType, Location 
                  FROM dbo.Attendance_Sessions 
                  WHERE CourseID = ? AND LecturerID = ? AND IsActive = 1
                  ORDER BY SessionDate DESC, SessionStartTime DESC";
$sessions_params = array($course_id, $lecturer_id);
$sessions_result = sqlsrv_query($conn, $sessions_query, $sessions_params);

if ($sessions_result === false) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

$sessions = [];
while ($session = sqlsrv_fetch_array($sessions_result, SQLSRV_FETCH_ASSOC)) {
    $sessions[] = [
        'SessionID' => $session['SessionID'],
        'SessionDate' => $session['SessionDate']->format('M d, Y'),
        'SessionStartTime' => $session['SessionStartTime']->format('h:i A'),
        'SessionEndTime' => $session['SessionEndTime']->format('h:i A'),
        'SessionType' => $session['SessionType'],
        'Location' => $session['Location']
    ];
}

echo json_encode(['success' => true, 'sessions' => $sessions]);

sqlsrv_free_stmt($lecturer_result);
sqlsrv_free_stmt($sessions_result);
sqlsrv_close($conn);
?>