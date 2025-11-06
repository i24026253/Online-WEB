<?php

/* Automatically generates alerts for students with low attendance*/

require_once 'connect.php';

// Configuration
$LOW_ATTENDANCE_THRESHOLD = 75; // Alert if attendance falls below 75%


/* Generate low attendance alerts for all students*/
function generateLowAttendanceAlerts($conn, $threshold = 75) {
    error_log("=== Starting Alert Generation (Threshold: {$threshold}%) ===");
    
    // ✅ Find students with low attendance per course
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
        INNER JOIN dbo.Attendance_Mark am ON c.CourseID = am.CourseID
        LEFT JOIN dbo.Attendance_Records ar ON am.MarkID = ar.MarkID AND ar.StudentID = s.StudentID
        WHERE e.Status = 'Active'
        AND s.IsActive = 1
        AND c.IsActive = 1
        GROUP BY s.StudentID, c.CourseID, c.CourseCode, c.CourseName
        HAVING CAST(SUM(CASE WHEN ar.Status = 'Present' THEN 1 ELSE 0 END) AS FLOAT) / 
               NULLIF(COUNT(ar.AttendanceID), 0) * 100 < ?
    ";
    
    $stmt = sqlsrv_query($conn, $query, [$threshold]);
    
    if ($stmt === false) {
        error_log("❌ Failed to get low attendance students: " . print_r(sqlsrv_errors(), true));
        return false;
    }
    
    $alertsCreated = 0;
    $alertsSkippedRead = 0;
    $alertsSkippedRecent = 0;
    
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $studentId = $row['StudentID'];
        $courseId = $row['CourseID'];
        $percentage = round($row['AttendancePercentage'], 2);
        $courseName = $row['CourseName'];
        $courseCode = $row['CourseCode'];
        
        error_log("Checking Student $studentId, Course $courseId ($courseCode): {$percentage}%");
        
        // ✅ Check for ANY existing alert (read OR unread) within last 7 days
        $checkQuery = "
            SELECT TOP 1
                AlertID, 
                IsRead, 
                CreatedDate,
                Message
            FROM dbo.Alerts 
            WHERE StudentID = ? 
            AND CourseID = ? 
            AND AlertType = 'Low Attendance'
            AND CreatedDate >= DATEADD(day, -7, GETDATE())
            ORDER BY CreatedDate DESC
        ";
        
        $checkStmt = sqlsrv_query($conn, $checkQuery, [$studentId, $courseId]);
        
        if ($checkStmt === false) {
            error_log("❌ Check query failed: " . print_r(sqlsrv_errors(), true));
            continue;
        }
        
        $existingAlert = sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($checkStmt);
        
        if ($existingAlert) {
            $alertId = $existingAlert['AlertID'];
            $isRead = $existingAlert['IsRead'];
            $createdDate = $existingAlert['CreatedDate']->format('Y-m-d H:i:s');
            
            
            if ($isRead == 1) {
                error_log("⏭️  SKIP: Alert $alertId for Student $studentId, Course $courseId is READ (created: $createdDate) - DO NOT UPDATE");
                $alertsSkippedRead++;
                continue; 
            }
            
            error_log("🔄 Alert $alertId exists and is UNREAD - will update message only");
            
            $newMessage = "Your attendance in $courseName is {$percentage}%. Minimum required: {$threshold}%. Please improve your attendance.";            
    
            $updateQuery = "
                UPDATE dbo.Alerts 
                SET Message = ?, 
                    CreatedDate = GETDATE()
                WHERE AlertID = ?
                AND IsRead = 0
            ";
            
            $updateStmt = sqlsrv_query($conn, $updateQuery, [$newMessage, $alertId]);
            
            if ($updateStmt === false) {
                error_log("❌ Failed to update alert: " . print_r(sqlsrv_errors(), true));
            } else {
                $rowsAffected = sqlsrv_rows_affected($updateStmt);
                if ($rowsAffected > 0) {
                    error_log("✅ Updated unread alert $alertId for Student $studentId, Course $courseId");
                } else {
                    error_log("⚠️  Alert $alertId was not updated (may have been read between checks)");
                }
                sqlsrv_free_stmt($updateStmt);
            }
            
            $alertsSkippedRecent++;
            
        } else {

            error_log("➕ Creating NEW alert for Student $studentId, Course $courseId");
            
            $insertQuery = "
                INSERT INTO dbo.Alerts (StudentID, CourseID, AlertType, Message, IsRead, CreatedDate)
                VALUES (?, ?, 'Low Attendance', ?, 0, GETDATE())
            ";
            
            $message = "Your attendance in $courseName has dropped to {$percentage}%. Minimum required: {$threshold}%. Please improve your attendance.";
            
            $insertStmt = sqlsrv_query($conn, $insertQuery, [$studentId, $courseId, $message]);
            
            if ($insertStmt === false) {
                error_log("❌ Failed to create alert: " . print_r(sqlsrv_errors(), true));
            } else {
                $alertsCreated++;
                error_log("✅ Created new alert for Student $studentId, Course $courseId (attendance: {$percentage}%)");
                sqlsrv_free_stmt($insertStmt);
            }
        }
    }
    
    sqlsrv_free_stmt($stmt);
    
    error_log("=== Alert Generation Complete ===");
    error_log("Created: $alertsCreated new alerts");
    error_log("Skipped (already read): $alertsSkippedRead");
    error_log("Skipped (recent unread): $alertsSkippedRecent");
    
    return [
        'created' => $alertsCreated,
        'skipped_read' => $alertsSkippedRead,
        'skipped_recent' => $alertsSkippedRecent
    ];
}

/*Mark alerts as read */
function markAlertAsRead($conn, $alertId) {
    error_log("Marking alert $alertId as read");
    
    $query = "UPDATE dbo.Alerts SET IsRead = 1 WHERE AlertID = ? AND IsRead = 0";
    $stmt = sqlsrv_query($conn, $query, [$alertId]);
    
    if ($stmt === false) {
        error_log("❌ Failed to mark alert as read: " . print_r(sqlsrv_errors(), true));
        return false;
    }
    
    $rowsAffected = sqlsrv_rows_affected($stmt);
    sqlsrv_free_stmt($stmt);
    
    if ($rowsAffected > 0) {
        error_log("✅ Alert $alertId marked as read");
        return true;
    } else {
        error_log("⚠️  Alert $alertId was not updated (may already be read)");
        return true; 
    }
}

/*Delete old read alerts (older than 30 days) */
function cleanupOldAlerts($conn) {
    $query = "
        DELETE FROM dbo.Alerts 
        WHERE IsRead = 1 
        AND CreatedDate < DATEADD(day, -30, GETDATE())
    ";
    
    $stmt = sqlsrv_query($conn, $query);
    
    if ($stmt === false) {
        error_log("❌ Failed to cleanup old alerts: " . print_r(sqlsrv_errors(), true));
        return false;
    }
    
    $rowsAffected = sqlsrv_rows_affected($stmt);
    sqlsrv_free_stmt($stmt);
    
    error_log("🧹 Cleaned up $rowsAffected old read alerts");
    
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
                        'skipped_read' => $result['skipped_read'],
                        'skipped_recent' => $result['skipped_recent']
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
                $includeRead = $_GET['include_read'] ?? false;
                
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
                    ";
                    
                    // ✅ By default, only show unread alerts
                    if (!$includeRead) {
                        $query .= " AND a.IsRead = 0";
                    }
                    
                    $query .= " ORDER BY a.CreatedDate DESC";
                    
                    $stmt = sqlsrv_query($conn, $query, [$studentId]);
                    $alerts = [];
                    
                    if ($stmt !== false) {
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
                        sqlsrv_free_stmt($stmt);
                    }
                    
                    echo json_encode([
                        'success' => true,
                        'alerts' => $alerts,
                        'count' => count($alerts)
                    ]);
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
        // Run alert generation by default
        $result = generateLowAttendanceAlerts($conn, $LOW_ATTENDANCE_THRESHOLD);
        
        echo json_encode([
            'success' => true,
            'message' => "Alert generation completed",
            'created' => $result['created'],
            'skipped_read' => $result['skipped_read'],
            'skipped_recent' => $result['skipped_recent']
        ]);
    }
    
    sqlsrv_close($conn);
}
?>