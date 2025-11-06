<?php
/**
 * Test Script for Alert System
 * Place this in: C:\xampp\htdocs\php_module\test_alert_system.php
 * Access via: http://localhost/php_module/test_alert_system.php
 */

require_once 'connect.php';

echo "<h1>Alert System Test</h1>";
echo "<hr>";

// Test 1: Check database connection
echo "<h2>Test 1: Database Connection</h2>";
if ($conn) {
    echo "✅ Database connected successfully<br>";
} else {
    echo "❌ Database connection failed<br>";
    die();
}
echo "<hr>";

// Test 2: Check for students with low attendance
echo "<h2>Test 2: Students with Low Attendance (< 75%)</h2>";
$query = "
    SELECT 
        s.StudentID,
        s.StudentNumber,
        u.FirstName + ' ' + u.LastName as StudentName,
        c.CourseID,
        c.CourseCode,
        c.CourseName,
        COUNT(ar.AttendanceID) as TotalSessions,
        SUM(CASE WHEN ar.Status = 'Present' THEN 1 ELSE 0 END) as PresentCount,
        CAST(SUM(CASE WHEN ar.Status = 'Present' THEN 1 ELSE 0 END) AS FLOAT) / 
        NULLIF(COUNT(ar.AttendanceID), 0) * 100 as AttendancePercentage
    FROM dbo.Students s
    INNER JOIN dbo.Users u ON s.UserID = u.UserID
    INNER JOIN dbo.Enrollments e ON s.StudentID = e.StudentID
    INNER JOIN dbo.Courses c ON e.CourseID = c.CourseID
    INNER JOIN dbo.Attendance_Mark am ON c.CourseID = am.CourseID
    LEFT JOIN dbo.Attendance_Records ar ON am.MarkID = ar.MarkID AND ar.StudentID = s.StudentID
    WHERE e.Status = 'Active'
    AND s.IsActive = 1
    AND c.IsActive = 1
    GROUP BY s.StudentID, s.StudentNumber, u.FirstName, u.LastName, c.CourseID, c.CourseCode, c.CourseName
    HAVING CAST(SUM(CASE WHEN ar.Status = 'Present' THEN 1 ELSE 0 END) AS FLOAT) / 
           NULLIF(COUNT(ar.AttendanceID), 0) * 100 < 75
    ORDER BY AttendancePercentage ASC
";

$stmt = sqlsrv_query($conn, $query);
if ($stmt === false) {
    echo "❌ Query failed: " . print_r(sqlsrv_errors(), true) . "<br>";
} else {
    echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
    echo "<tr style='background: #f0f0f0;'>";
    echo "<th>Student Number</th><th>Name</th><th>Course</th><th>Attendance %</th><th>Sessions</th><th>Present</th>";
    echo "</tr>";
    
    $count = 0;
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $count++;
        $percentage = round($row['AttendancePercentage'], 2);
        $color = $percentage < 50 ? '#ffcccc' : '#ffffcc';
        
        echo "<tr style='background: $color;'>";
        echo "<td>{$row['StudentNumber']}</td>";
        echo "<td>{$row['StudentName']}</td>";
        echo "<td>{$row['CourseCode']} - {$row['CourseName']}</td>";
        echo "<td><strong>{$percentage}%</strong></td>";
        echo "<td>{$row['TotalSessions']}</td>";
        echo "<td>{$row['PresentCount']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    if ($count == 0) {
        echo "<p>✅ No students with low attendance found (all above 75%)</p>";
    } else {
        echo "<p>Found <strong>$count</strong> student-course combinations with low attendance</p>";
    }
    
    sqlsrv_free_stmt($stmt);
}
echo "<hr>";

// Test 3: Check existing alerts
echo "<h2>Test 3: Existing Alerts in Database</h2>";
$alertQuery = "
    SELECT 
        a.AlertID,
        a.StudentID,
        s.StudentNumber,
        u.FirstName + ' ' + u.LastName as StudentName,
        c.CourseCode,
        a.AlertType,
        a.IsRead,
        a.CreatedDate,
        a.Message
    FROM dbo.Alerts a
    JOIN dbo.Students s ON a.StudentID = s.StudentID
    JOIN dbo.Users u ON s.UserID = u.UserID
    LEFT JOIN dbo.Courses c ON a.CourseID = c.CourseID
    WHERE a.CreatedDate >= DATEADD(day, -7, GETDATE())
    ORDER BY a.CreatedDate DESC
";

$alertStmt = sqlsrv_query($conn, $alertQuery);
if ($alertStmt === false) {
    echo "❌ Query failed: " . print_r(sqlsrv_errors(), true) . "<br>";
} else {
    echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
    echo "<tr style='background: #f0f0f0;'>";
    echo "<th>Alert ID</th><th>Student</th><th>Course</th><th>Type</th><th>Status</th><th>Created</th><th>Message</th>";
    echo "</tr>";
    
    $alertCount = 0;
    $unreadCount = 0;
    
    while ($row = sqlsrv_fetch_array($alertStmt, SQLSRV_FETCH_ASSOC)) {
        $alertCount++;
        $isRead = $row['IsRead'];
        if (!$isRead) $unreadCount++;
        
        $statusColor = $isRead ? '#ccffcc' : '#ffcccc';
        $statusText = $isRead ? 'Read ✓' : 'Unread ✗';
        
        echo "<tr style='background: $statusColor;'>";
        echo "<td>{$row['AlertID']}</td>";
        echo "<td>{$row['StudentNumber']} - {$row['StudentName']}</td>";
        echo "<td>{$row['CourseCode']}</td>";
        echo "<td>{$row['AlertType']}</td>";
        echo "<td><strong>$statusText</strong></td>";
        echo "<td>" . $row['CreatedDate']->format('Y-m-d H:i') . "</td>";
        echo "<td>" . substr($row['Message'], 0, 50) . "...</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    if ($alertCount == 0) {
        echo "<p>ℹ️ No alerts found in the last 7 days</p>";
    } else {
        echo "<p>Total: <strong>$alertCount</strong> alerts | ";
        echo "Unread: <strong style='color: red;'>$unreadCount</strong> | ";
        echo "Read: <strong style='color: green;'>" . ($alertCount - $unreadCount) . "</strong></p>";
    }
    
    sqlsrv_free_stmt($alertStmt);
}
echo "<hr>";

// Test 4: Test alert generation
echo "<h2>Test 4: Generate Alerts</h2>";
echo "<p><a href='alert_generator.php?action=generate&threshold=75' target='_blank' style='padding: 10px 20px; background: #4CAF50; color: white; text-decoration: none; border-radius: 5px;'>🔄 Generate Alerts Now</a></p>";
echo "<p><small>Click the button above to generate alerts. Then refresh this page to see results.</small></p>";
echo "<hr>";

// Test 5: Recommendations
echo "<h2>Test 5: System Recommendations</h2>";
echo "<ul>";

// Check if there are students with low attendance but no alerts
$noAlertQuery = "
    SELECT COUNT(*) as MissingAlerts
    FROM (
        SELECT DISTINCT s.StudentID, c.CourseID
        FROM dbo.Students s
        INNER JOIN dbo.Enrollments e ON s.StudentID = e.StudentID
        INNER JOIN dbo.Courses c ON e.CourseID = c.CourseID
        INNER JOIN dbo.Attendance_Mark am ON c.CourseID = am.CourseID
        LEFT JOIN dbo.Attendance_Records ar ON am.MarkID = ar.MarkID AND ar.StudentID = s.StudentID
        WHERE e.Status = 'Active'
        AND s.IsActive = 1
        AND c.IsActive = 1
        GROUP BY s.StudentID, c.CourseID
        HAVING CAST(SUM(CASE WHEN ar.Status = 'Present' THEN 1 ELSE 0 END) AS FLOAT) / 
               NULLIF(COUNT(ar.AttendanceID), 0) * 100 < 75
        AND NOT EXISTS (
            SELECT 1 FROM dbo.Alerts a
            WHERE a.StudentID = s.StudentID
            AND a.CourseID = c.CourseID
            AND a.CreatedDate >= DATEADD(day, -7, GETDATE())
        )
    ) AS Missing
";

$noAlertStmt = sqlsrv_query($conn, $noAlertQuery);
$missingAlerts = sqlsrv_fetch_array($noAlertStmt, SQLSRV_FETCH_ASSOC)['MissingAlerts'];

if ($missingAlerts > 0) {
    echo "<li>⚠️ <strong>$missingAlerts</strong> student-course combinations need alerts - Run the alert generator!</li>";
} else {
    echo "<li>✅ All low-attendance students have been notified</li>";
}

// Check for old read alerts that can be cleaned up
$oldAlertsQuery = "SELECT COUNT(*) as OldAlerts FROM dbo.Alerts WHERE IsRead = 1 AND CreatedDate < DATEADD(day, -30, GETDATE())";
$oldAlertsStmt = sqlsrv_query($conn, $oldAlertsQuery);
$oldAlerts = sqlsrv_fetch_array($oldAlertsStmt, SQLSRV_FETCH_ASSOC)['OldAlerts'];

if ($oldAlerts > 0) {
    echo "<li>🧹 <strong>$oldAlerts</strong> old read alerts can be cleaned up</li>";
    echo "<li><a href='alert_generator.php?action=cleanup' target='_blank'>Clean up old alerts</a></li>";
} else {
    echo "<li>✅ No old alerts to clean up</li>";
}

echo "</ul>";
echo "<hr>";

echo "<h2>Quick Links</h2>";
echo "<ul>";
echo "<li><a href='alert_generator.php?action=generate&threshold=75' target='_blank'>Generate Alerts (75% threshold)</a></li>";
echo "<li><a href='alert_generator.php?action=cleanup' target='_blank'>Cleanup Old Alerts</a></li>";
echo "<li><a href='http://127.0.0.1:8000/student-dashboard/' target='_blank'>Student Dashboard</a></li>";
echo "</ul>";

sqlsrv_close($conn);

echo "<hr>";
echo "<p><small>Test completed at: " . date('Y-m-d H:i:s') . "</small></p>";
?>