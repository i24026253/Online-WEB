

<?php
include 'connect.php';

function generateReportData($conn, $period, $startDate, $endDate, $courseId = null, $studentId = null, $lecturerId = null, $studentNumber = null) {
    $query = "
        SELECT
            s.StudentNumber,
            u.FirstName + ' ' + u.LastName AS student_name,
            c.CourseName,
            c.CourseCode,
            ats.SessionDate,
            ar.Status,
            ar.MarkedTime
        FROM dbo.Attendance_Records ar
        JOIN dbo.Students s ON ar.StudentID = s.StudentID
        JOIN dbo.Users u ON s.UserID = u.UserID
        JOIN dbo.Attendance_Sessions ats ON ar.SessionID = ats.SessionID
        JOIN dbo.Courses c ON ats.CourseID = c.CourseID
        WHERE 1=1
    ";
    
    $params = [];
    
    // 🔧 FIXED: Date filtering with better monthly handling
    if ($period === 'monthly' && $startDate) {
        // Handle both YYYY-MM and YYYY-MM-DD formats
        if (strlen($startDate) === 7) {
            // Format: YYYY-MM
            $startDate = $startDate . '-01';
        }
        
        $year = substr($startDate, 0, 4);
        $month = substr($startDate, 5, 2);
        $firstDay = "$year-$month-01";
        $lastDay = date("Y-m-t", strtotime($firstDay));
        
        error_log("Monthly filter - First day: $firstDay, Last day: $lastDay");
        
        // Use SessionDate instead of MarkedTime for more accurate filtering
        $query .= " AND ats.SessionDate BETWEEN ? AND ?";
        $params[] = $firstDay;
        $params[] = $lastDay;
    } elseif ($period === 'weekly' && $startDate) {
        $endDate = date('Y-m-d', strtotime($startDate . ' +6 days'));
        
        error_log("Weekly filter - Start: $startDate, End: $endDate");
        
        $query .= " AND ats.SessionDate BETWEEN ? AND ?";
        $params[] = $startDate;
        $params[] = $endDate;
    } elseif ($startDate && $endDate) {
        error_log("Daily filter - Start: $startDate, End: $endDate");
        
        $query .= " AND ats.SessionDate BETWEEN ? AND ?";
        $params[] = $startDate;
        $params[] = $endDate;
    }
    
    // Course filter
    if ($courseId) {
        $query .= " AND c.CourseID = ?";
        $params[] = $courseId;
    }
    
    // Student ID filter
    if ($studentId) {
        $query .= " AND s.StudentID = ?";
        $params[] = $studentId;
    }
    
    // Student Number filter - exact match
    if ($studentNumber) {
        $query .= " AND s.StudentNumber = ?";
        $params[] = trim($studentNumber);
    }
    
    // Lecturer filter
    if ($lecturerId) {
        $query .= " AND ats.LecturerID = ?";
        $params[] = $lecturerId;
    }
    
    $query .= " ORDER BY ar.MarkedTime DESC";
    
    error_log("=== REPORT QUERY DEBUG ===");
    error_log("SQL Query: " . $query);
    error_log("Parameters: " . print_r($params, true));
    error_log("Period: $period");
    
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
    
    // Calculate average percentage
    $avgPercentage = $totalRecords > 0 ? round(($presentCount / $totalRecords) * 100, 2) : 0;
    
    error_log("Total records found: $totalRecords");
    
    sqlsrv_free_stmt($stmt);
    
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

// 🔧 FIXED: If no start date provided for monthly, use current month
if ($period === 'monthly' && !$startDate) {
    $startDate = date('Y-m-01');
}

// Default date range for other periods
if (!$startDate) {
    $startDate = date('Y-m-d', strtotime('-30 days'));
}
if (!$endDate && $period === 'daily') {
    $endDate = date('Y-m-d');
}

$courseId = isset($_GET['course_id']) && $_GET['course_id'] !== '' ? (int)$_GET['course_id'] : null;
$studentId = isset($_GET['student_id']) && $_GET['student_id'] !== '' ? (int)$_GET['student_id'] : null;
$lecturerId = isset($_GET['lecturer_id']) && $_GET['lecturer_id'] !== '' ? (int)$_GET['lecturer_id'] : null;
$studentNumber = isset($_GET['student_number']) && $_GET['student_number'] !== '' ? $_GET['student_number'] : null;

error_log("=== REPORT REQUEST ===");
error_log("Period: $period");
error_log("Start Date: $startDate");
error_log("End Date: " . ($endDate ?? 'null'));
error_log("Student Number: " . ($studentNumber ?? 'null'));
error_log("Course ID: " . ($courseId ?? 'null'));
error_log("Lecturer ID: " . ($lecturerId ?? 'null'));

$reportData = generateReportData($conn, $period, $startDate, $endDate, $courseId, $studentId, $lecturerId, $studentNumber);

echo json_encode($reportData);

sqlsrv_close($conn);
?>