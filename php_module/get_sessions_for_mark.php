<?php
require_once 'connect.php';

$course_id = $_GET['course_id'] ?? 0;
$username  = $_GET['username']  ?? '';

// ---- Verify lecturer -------------------------------------------------
$q = "SELECT l.LecturerID 
      FROM dbo.Lecturers l 
      JOIN dbo.Users u ON l.UserID = u.UserID 
      WHERE u.Username = ?";
$stmt = sqlsrv_query($conn, $q, [$username]);
if (!$stmt || !($lect = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC))) {
    echo json_encode(['success' => false, 'error' => 'Lecturer not found']);
    exit;
}
sqlsrv_free_stmt($stmt);

// ---- Get **all** sessions for this course (CourseID + LecturerID) ----
$q = "SELECT SessionID, Session, SessionStartTime, SessionEndTime, Location
      FROM dbo.Attendance_Sessions 
      WHERE CourseID = ? AND LecturerID = ?
      ORDER BY SessionStartTime";
$stmt = sqlsrv_query($conn, $q, [$course_id, $lect['LecturerID']]);

$sessions = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    // Format time for display
    $row['SessionStartTime'] = $row['SessionStartTime'] instanceof DateTime
        ? $row['SessionStartTime']->format('h:i A')
        : '';
    $row['SessionEndTime']   = $row['SessionEndTime'] instanceof DateTime
        ? $row['SessionEndTime']->format('h:i A')
        : '';
    $sessions[] = $row;
}
sqlsrv_free_stmt($stmt);
sqlsrv_close($conn);

echo json_encode(['success' => true, 'sessions' => $sessions]);
?>