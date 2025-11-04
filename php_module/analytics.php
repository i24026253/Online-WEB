<?php
include 'connect.php'; 

// Calculate the student's attendance rate in a certain course (or all courses)
function calculateAttendancePercentage($conn, $studentId, $courseId = null) {
    $query = "SELECT 
                COUNT(*) AS total_sessions,
                SUM(CASE WHEN ar.Status = 'Present' THEN 1 ELSE 0 END) AS present_count
              FROM Attendance_Records ar
              JOIN Attendance_Sessions s ON ar.SessionID = s.SessionID
              WHERE ar.StudentID = ?";
    $params = [$studentId];
    if ($courseId) {
        $query .= " AND s.CourseID = ?";
        $params[] = $courseId;
    }
    $stmt = sqlsrv_query($conn, $query, $params);
    if ($stmt === false) return 0;
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    $total = $row['total_sessions'];
    $present = $row['present_count'];
    return ($total > 0) ? round(($present / $total) * 100, 2) : 0;
}


// Course statistics (average attendance, number of enrollees)
function getCourseStats($conn, $courseId) {
    $query = "SELECT 
                AVG(CASE WHEN ar.Status = 'Present' THEN 1.0 ELSE 0 END) * 100 AS avg_attendance,
                COUNT(DISTINCT ar.StudentID) AS enrolled_students
              FROM Attendance_Records ar
              JOIN Attendance_Sessions s ON ar.SessionID = s.SessionID
              WHERE s.CourseID = ?";
    $stmt = sqlsrv_query($conn, $query, [$courseId]);
    if ($stmt === false) return ['avg_attendance' => 0, 'enrolled_students' => 0];
    return sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
}


// Global statistics (for administrators)
function getOverallStats($conn) {
    $query = "SELECT 
                AVG(CASE WHEN ar.Status = 'Present' THEN 1.0 ELSE 0 END) * 100 AS system_avg_attendance,
                COUNT(DISTINCT ar.StudentID) AS total_students,
                COUNT(DISTINCT s.CourseID) AS total_courses
              FROM Attendance_Records ar
              JOIN Attendance_Sessions s ON ar.SessionID = s.SessionID";
    $stmt = sqlsrv_query($conn, $query);
    if ($stmt === false) return ['system_avg_attendance' => 0, 'total_students' => 0, 'total_courses' => 0];
    return sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
}


// Low attendance alert (below threshold, default 75%)
function getLowAttendanceAlerts($conn, $threshold = 75) {
    $query = "SELECT 
                s.StudentID, u.FirstName + ' ' + u.LastName AS student_name,
                c.CourseID, c.CourseName,
                (SUM(CASE WHEN ar.Status = 'Present' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)) AS percentage
              FROM Attendance_Records ar
              JOIN Students s ON ar.StudentID = s.StudentID
              JOIN Users u ON s.UserID = u.UserID
              JOIN Attendance_Sessions ses ON ar.SessionID = ses.SessionID
              JOIN Courses c ON ses.CourseID = c.CourseID
              GROUP BY s.StudentID, u.FirstName, u.LastName, c.CourseID, c.CourseName
              HAVING (SUM(CASE WHEN ar.Status = 'Present' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)) < ?
              ORDER BY percentage ASC";
    $stmt = sqlsrv_query($conn, $query, [$threshold]);
    $alerts = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $alerts[] = $row;
    }
    return $alerts;
}

// API interface
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    header('Content-Type: application/json');
    if (isset($_GET['action'])) {
        if ($_GET['action'] == 'percentage') {
            $studentId = $_GET['student_id'] ?? null;
            $courseId = $_GET['course_id'] ?? null;
            echo json_encode(['percentage' => calculateAttendancePercentage($conn, $studentId, $courseId)]);
        } elseif ($_GET['action'] == 'course_stats') {
            $courseId = $_GET['course_id'] ?? null;
            echo json_encode(getCourseStats($conn, $courseId));
        } elseif ($_GET['action'] == 'overall_stats') {
            echo json_encode(getOverallStats($conn));
        } elseif ($_GET['action'] == 'alerts') {
            $threshold = $_GET['threshold'] ?? 75;
            echo json_encode(getLowAttendanceAlerts($conn, $threshold));
        }
    }
    sqlsrv_close($conn); 
}
?>

