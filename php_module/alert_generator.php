<?php
/**
 * alert_generator.php
 * Automatically generates alerts for students with low attendance
 * This can be run as a cron job or called manually
 */

require_once 'connect.php';

// Configuration
$LOW_ATTENDANCE_THRESHOLD = 75; // Alert if attendance falls below 75%

/**
 * Generate low attendance alerts for all students
 */
function generateLowAttendanceAlerts($conn, $threshold = 75) {
    // Find students with low attendance per course
    $query = "
        SELECT 
            s.StudentID,
            c.CourseID,
            c.CourseCode,
            c.CourseName,
            COUNT(ar.AttendanceID) as TotalSessions,
            SUM(CASE WHEN ar.Status = 'Present' THEN 1 ELSE 0 END) as PresentCount,
            CAST(SUM(CASE WHEN ar.Status = 'Present' THEN 1 ELSE 0 END) AS FLOAT) / 
            NULLIF(COUNT(ar.AttendanceID), 0) * 100 as AttendancePercentage
        FROM dbo.Students s
        INNER JOIN dbo.Enrollments e ON s.StudentID = e.StudentID
        INNER JOIN dbo.Courses c ON e.CourseID = c.CourseID
        INNER JOIN dbo.Attendance_Sessions ats ON c.CourseID = ats.CourseID
        LEFT JOIN dbo.Attendance_Records ar ON ats.SessionID = ar.SessionID AND ar.StudentID = s.StudentID
        WHERE e.Status = 'Active'
        AND s.IsActive = 1
        AND c.IsActive = 1
        GROUP BY s.StudentID, c.CourseID, c.CourseCode, c.CourseName
        HAVING CAST(SUM(CASE WHEN ar.Status = 'Present' THEN 1 ELSE 0 END) AS FLOAT) / 
               NULLIF(COUNT(ar.AttendanceID), 0) * 100 < ?
    ";
    
    $stmt = sqlsrv_query($conn, $query, [$threshold]);
    
    if ($stmt === false) {
        error_log("Failed to get low attendance students: " . print_r(sqlsrv_errors(), true));
        return false;
    }
    
    $alertsCreated = 0;
    $alertsUpdated = 0;
    
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $studentId = $row['StudentID'];
        $courseId = $row['CourseID'];
        $percentage = round($row['AttendancePercentage'], 2);
        $courseName = $row['CourseName'];
        
        // Check if alert already exists for this student and course (within last 7 days)
        $checkQuery = "
            SELECT AlertID, IsRead 
            FROM dbo.Alerts 
            WHERE StudentID = ? 
            AND CourseID = ? 
            AND AlertType = 'Low Attendance'
            AND CreatedDate >= DATEADD(day, -7, GETDATE())
        ";
        
        $checkStmt = sqlsrv_query($conn, $checkQuery, [$studentId, $courseId]);
        $existingAlert = sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC);
        
        if ($existingAlert) {
            // Update existing alert
            $updateQuery = "
                UPDATE dbo.Alerts 
                SET Message = ?, 
                    IsRead = 0,
                    CreatedDate = GETDATE()
                WHERE AlertID = ?
            ";
            
            $message = "Your attendance in $courseName has dropped to {$percentage}%. Minimum required: {$threshold}%. Please improve your attendance.";
            
            $updateStmt = sqlsrv_query($conn, $updateQuery, [$message, $existingAlert['AlertID']]);
            
            if ($updateStmt) {
                $alertsUpdated++;
            }
            
            sqlsrv_free_stmt($checkStmt);
        } else {
            // Create new alert
            $insertQuery = "
                INSERT INTO dbo.Alerts (StudentID, CourseID, AlertType, Message, IsRead, CreatedDate)
                VALUES (?, ?, 'Low Attendance', ?, 0, GETDATE())
            ";
            
            $message = "Your attendance in $courseName has dropped to {$percentage}%. Minimum required: {$threshold}%. Please improve your attendance.";
            
            $insertStmt = sqlsrv_query($conn, $insertQuery, [$studentId, $courseId, $message]);
            
            if ($insertStmt) {
                $alertsCreated++;
            } else {
                error_log("Failed to create alert for Student $studentId, Course $courseId: " . print_r(sqlsrv_errors(), true));
            }
        }
    }
    
    sqlsrv_free_stmt($stmt);
    
    return [
        'created' => $alertsCreated,
        'updated' => $alertsUpdated
    ];
}

/**
 * Mark alerts as read
 */
function markAlertAsRead($conn, $alertId) {
    $query = "UPDATE dbo.Alerts SET IsRead = 1 WHERE AlertID = ?";
    $stmt = sqlsrv_query($conn, $query, [$alertId]);
    
    if ($stmt === false) {
        return false;
    }
    
    return true;
}

/**
 * Delete old read alerts (older than 3 days)
 */
function cleanupOldAlerts($conn) {
    $query = "
        DELETE FROM dbo.Alerts 
        WHERE IsRead = 1 
        AND CreatedDate < DATEADD(day, -3, GETDATE())
    ";
    
    $stmt = sqlsrv_query($conn, $query);
    
    if ($stmt === false) {
        error_log("Failed to cleanup old alerts: " . print_r(sqlsrv_errors(), true));
        return false;
    }
    
    $rowsAffected = sqlsrv_rows_affected($stmt);
    sqlsrv_free_stmt($stmt);
    
    return $rowsAffected;
}

// API endpoints
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    header('Content-Type: application/json');
    
    if (isset($_GET['action'])) {
        $action = $_GET['action'];
        
        switch ($action) {
            case 'generate':
                // Generate new alerts
                $threshold = $_GET['threshold'] ?? 75;
                $result = generateLowAttendanceAlerts($conn, $threshold);
                
                if ($result) {
                    echo json_encode([
                        'success' => true,
                        'message' => "Alerts generated successfully",
                        'created' => $result['created'],
                        'updated' => $result['updated']
                    ]);
                } else {
                    echo json_encode([
                        'success' => false,
                        'message' => "Failed to generate alerts"
                    ]);
                }
                break;
                
            case 'mark_read':
                // Mark alert as read
                $alertId = $_GET['alert_id'] ?? null;
                
                if ($alertId) {
                    $result = markAlertAsRead($conn, $alertId);
                    echo json_encode([
                        'success' => $result,
                        'message' => $result ? "Alert marked as read" : "Failed to mark alert as read"
                    ]);
                } else {
                    echo json_encode([
                        'success' => false,
                        'message' => "Alert ID is required"
                    ]);
                }
                break;
                
            case 'cleanup':
                // Clean up old alerts
                $deleted = cleanupOldAlerts($conn);
                echo json_encode([
                    'success' => true,
                    'message' => "Old alerts cleaned up",
                    'deleted' => $deleted
                ]);
                break;
                
            case 'get_student_alerts':
                // Get alerts for a specific student
                $studentId = $_GET['student_id'] ?? null;
                
                if ($studentId) {
                    $query = "
                        SELECT 
                            a.AlertID,
                            a.AlertType,
                            a.Message,
                            a.IsRead,
                            a.CreatedDate,
                            c.CourseName,
                            c.CourseCode
                        FROM dbo.Alerts a
                        LEFT JOIN dbo.Courses c ON a.CourseID = c.CourseID
                        WHERE a.StudentID = ?
                        ORDER BY a.CreatedDate DESC
                    ";
                    
                    $stmt = sqlsrv_query($conn, $query, [$studentId]);
                    $alerts = [];
                    
                    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                        $alerts[] = [
                            'AlertID' => $row['AlertID'],
                            'AlertType' => $row['AlertType'],
                            'Message' => $row['Message'],
                            'IsRead' => $row['IsRead'],
                            'CreatedDate' => $row['CreatedDate']->format('Y-m-d H:i:s'),
                            'CourseName' => $row['CourseName'],
                            'CourseCode' => $row['CourseCode']
                        ];
                    }
                    
                    echo json_encode([
                        'success' => true,
                        'alerts' => $alerts
                    ]);
                    
                    sqlsrv_free_stmt($stmt);
                } else {
                    echo json_encode([
                        'success' => false,
                        'message' => "Student ID is required"
                    ]);
                }
                break;
                
            default:
                echo json_encode([
                    'success' => false,
                    'message' => "Invalid action"
                ]);
        }
    } else {
        // Default: Run alert generation
        $result = generateLowAttendanceAlerts($conn, $LOW_ATTENDANCE_THRESHOLD);
        
        echo json_encode([
            'success' => true,
            'message' => "Alert generation completed",
            'created' => $result['created'],
            'updated' => $result['updated']
        ]);
    }
    
    sqlsrv_close($conn);
}
?>