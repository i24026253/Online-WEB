<?php

include 'connect.php';

function generateReportData($conn, $period, $startDate, $endDate, $courseId = null, $studentId = null, $lecturerId = null, $studentNumber = null) {
    
    $query = "
        SELECT
            s.StudentNumber,
            u.FirstName + ' ' + u.LastName AS student_name,
            c.CourseName,
            c.CourseCode,
            am.Date as SessionDate,
            ar.Status,
            ar.MarkedTime
        FROM dbo.Attendance_Records ar
        JOIN dbo.Students s ON ar.StudentID = s.StudentID
        JOIN dbo.Users u ON s.UserID = u.UserID
        JOIN dbo.Attendance_Mark am ON ar.MarkID = am.MarkID
        JOIN dbo.Courses c ON am.CourseID = c.CourseID
        WHERE 1=1
    ";
    
    $params = [];
    
    if ($period === 'monthly' && $startDate) {
        if (strlen($startDate) === 7) {
            $startDate = $startDate . '-01';
        }
        
        $firstDay = date('Y-m-01', strtotime($startDate));
        $lastDay = date('Y-m-t', strtotime($startDate));

        $query .= " AND ar.MarkedTime >= ? AND ar.MarkedTime < ?";
        $params[] = $firstDay . ' 00:00:00';

        $params[] = date('Y-m-01 00:00:00', strtotime($firstDay . ' +1 month'));
        
    } elseif ($period === 'weekly' && $startDate) {
        $endDate = date('Y-m-d', strtotime($startDate . ' +6 days'));
        
        $query .= " AND ar.MarkedTime >= ? AND ar.MarkedTime < ?";
        $params[] = $startDate . ' 00:00:00';
        $params[] = date('Y-m-d 00:00:00', strtotime($endDate . ' +1 day'));
        
    } elseif ($startDate && $endDate) {
        $query .= " AND ar.MarkedTime >= ? AND ar.MarkedTime < ?";
        $params[] = $startDate . ' 00:00:00';
        $params[] = date('Y-m-d 00:00:00', strtotime($endDate . ' +1 day'));
    }
    
    if ($courseId) {
        $query .= " AND c.CourseID = ?";
        $params[] = $courseId;
    }
    
    if ($studentId) {
        $query .= " AND s.StudentID = ?";
        $params[] = $studentId;
    }
    
    if ($studentNumber) {
        $query .= " AND s.StudentNumber = ?";
        $params[] = trim($studentNumber);
    }
    
    if ($lecturerId) {
        $query .= " AND EXISTS (
            SELECT 1 FROM dbo.Course_Assignments ca 
            WHERE ca.CourseID = c.CourseID 
            AND ca.LecturerID = ? 
            AND ca.IsActive = 1
        )";
        $params[] = $lecturerId;
    }
    
    $query .= " ORDER BY ar.MarkedTime DESC";
    
    $stmt = sqlsrv_query($conn, $query, $params);
    
    if ($stmt === false) {
        error_log("SQL Error: " . print_r(sqlsrv_errors(), true));
        return [
            'records' => [],
            'summary' => [
                'total_records' => 0,
                'present_count' => 0,
                'absent_count' => 0,
                'late_count' => 0,
                'avg_percentage' => 0
            ]
        ];
    }
    
    $report = [];
    $totalRecords = 0;
    $presentCount = 0;
    $absentCount = 0;
    $lateCount = 0;
    
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $totalRecords++;
        
        if ($row['Status'] === 'Present') {
            $presentCount++;
        } elseif ($row['Status'] === 'Absent') {
            $absentCount++;
        } elseif ($row['Status'] === 'Late') {
            $lateCount++;
        }
        

        $markedTime = $row['MarkedTime'];
        if (is_object($markedTime) && method_exists($markedTime, 'format')) {
            $markedTime = $markedTime->format('Y-m-d H:i:s');
        }
        
        $sessionDate = $row['SessionDate'];
        if (is_object($sessionDate) && method_exists($sessionDate, 'format')) {
            $sessionDate = $sessionDate->format('Y-m-d');
        }
        
        $report[] = [
            'StudentNumber' => $row['StudentNumber'],
            'student_name' => $row['student_name'],
            'CourseName' => $row['CourseName'],
            'CourseCode' => $row['CourseCode'],
            'SessionDate' => $sessionDate,
            'Status' => $row['Status'],
            'MarkedTime' => $markedTime
        ];
    }
    
    sqlsrv_free_stmt($stmt);
    
    $avgPercentage = $totalRecords > 0 ? round(($presentCount / $totalRecords) * 100, 2) : 0;
    
    return [
        'records' => $report,
        'summary' => [
            'total_records' => $totalRecords,
            'present_count' => $presentCount,
            'absent_count' => $absentCount,
            'late_count' => $lateCount,
            'avg_percentage' => $avgPercentage
        ]
    ];
}

// API Interface
header('Content-Type: application/json');

$period = $_GET['period'] ?? 'monthly';
$startDate = $_GET['start'] ?? null;
$endDate = $_GET['end'] ?? null;

// 默认当前月
if ($period === 'monthly' && !$startDate) {
    $startDate = date('Y-m');
}

// 默认最近30天
if (!$startDate && $period !== 'monthly') {
    $startDate = date('Y-m-d', strtotime('-30 days'));
}
if (!$endDate && $period === 'daily') {
    $endDate = date('Y-m-d');
}

$courseId = isset($_GET['course_id']) && $_GET['course_id'] !== '' ? (int)$_GET['course_id'] : null;
$studentId = isset($_GET['student_id']) && $_GET['student_id'] !== '' ? (int)$_GET['student_id'] : null;
$lecturerId = isset($_GET['lecturer_id']) && $_GET['lecturer_id'] !== '' ? (int)$_GET['lecturer_id'] : null;
$studentNumber = isset($_GET['student_number']) && $_GET['student_number'] !== '' ? $_GET['student_number'] : null;

$reportData = generateReportData($conn, $period, $startDate, $endDate, $courseId, $studentId, $lecturerId, $studentNumber);

echo json_encode($reportData);

sqlsrv_close($conn);
?>






