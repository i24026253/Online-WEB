<?php
if (!isset($conn)) {
    include 'connect.php';
}

include_once 'analytics.php';

// Generate a report for a specified time period
function generateReport($conn, $period, $startDate, $endDate, $courseId = null, $studentId = null) {
    $query = "SELECT 
                u.FirstName + ' ' + u.LastName AS student_name,
                c.CourseName,
                ses.SessionDate,
                ar.Status
              FROM Attendance_Records ar
              JOIN Students s ON ar.StudentID = s.StudentID
              JOIN Users u ON s.UserID = u.UserID
              JOIN Attendance_Sessions ses ON ar.SessionID = ses.SessionID
              JOIN Courses c ON ses.CourseID = c.CourseID
              WHERE ses.SessionDate BETWEEN ? AND ?";
    $params = [$startDate, $endDate];
    if ($courseId) {
        $query .= " AND c.CourseID = ?";
        $params[] = $courseId;
    }
    if ($studentId) {
        $query .= " AND s.StudentID = ?";
        $params[] = $studentId;
    }
    $query .= " ORDER BY ses.SessionDate DESC";
    
    $stmt = sqlsrv_query($conn, $query, $params);
    
    if ($stmt === false) {
        die(json_encode(['error' => 'Query failed', 'details' => sqlsrv_errors()]));
    }
    
    $report = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        if (is_object($row['SessionDate'])) {
            $row['SessionDate'] = $row['SessionDate']->format('Y-m-d');
        }
        $report[] = $row;
    }
    
    // Summary statistics
    $avgPercentage = 0;
    if ($studentId) {
        $avgPercentage = calculateAttendancePercentage($conn, $studentId, $courseId);
    }
    
    $result = $report;
    $result['summary'] = [
        'total_records' => count($report),
        'avg_percentage' => $avgPercentage
    ];
    
    return $result;
}

// API interface
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    header('Content-Type: application/json');
    if (isset($_GET['period'])) {
        $period = $_GET['period'];
        $courseId = $_GET['course_id'] ?? null;
        $studentId = $_GET['student_id'] ?? null;
        $endDate = date('Y-m-d');
        
        if ($period == 'daily') {
            $startDate = date('Y-m-d', strtotime('-1 day'));
        } elseif ($period == 'weekly') {
            $startDate = date('Y-m-d', strtotime('-1 week'));
        } elseif ($period == 'monthly') {
            $startDate = date('Y-m-d', strtotime('-1 month'));
        } else {
            $startDate = date('Y-m-d', strtotime('-1 month'));
        }
        
        echo json_encode(generateReport($conn, $period, $startDate, $endDate, $courseId, $studentId));
    }
    sqlsrv_close($conn); 
}
?>

