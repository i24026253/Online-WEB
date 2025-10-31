<?php
/**
 * test_alert_system.php
 * Quick test script to verify alert system is working
 */

require_once 'connect.php';
require_once 'alert_generator.php';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alert System Test</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="row">
            <div class="col-md-8 mx-auto">
                <h1 class="mb-4"><i class="fas fa-vial me-2"></i>Alert System Test</h1>
                
                <!-- Test 1: Connection -->
                <div class="card mb-3">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-database me-2"></i>1. Database Connection</h5>
                    </div>
                    <div class="card-body">
                        <?php
                        if ($conn) {
                            echo '<div class="alert alert-success"><i class="fas fa-check-circle me-2"></i>✅ Connected to SQL Server successfully!</div>';
                        } else {
                            echo '<div class="alert alert-danger"><i class="fas fa-times-circle me-2"></i>❌ Connection failed!</div>';
                        }
                        ?>
                    </div>
                </div>

                <!-- Test 2: Check Alerts Table -->
                <div class="card mb-3">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="fas fa-table me-2"></i>2. Alerts Table Check</h5>
                    </div>
                    <div class="card-body">
                        <?php
                        $tableCheck = sqlsrv_query($conn, "SELECT COUNT(*) as count FROM dbo.Alerts");
                        if ($tableCheck) {
                            $row = sqlsrv_fetch_array($tableCheck, SQLSRV_FETCH_ASSOC);
                            echo '<div class="alert alert-success"><i class="fas fa-check-circle me-2"></i>✅ Alerts table exists!</div>';
                            echo '<p class="mb-0">Current alerts in database: <strong>' . $row['count'] . '</strong></p>';
                            sqlsrv_free_stmt($tableCheck);
                        } else {
                            echo '<div class="alert alert-danger"><i class="fas fa-times-circle me-2"></i>❌ Alerts table not found!</div>';
                        }
                        ?>
                    </div>
                </div>

                <!-- Test 3: Check Low Attendance Students -->
                <div class="card mb-3">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>3. Low Attendance Students</h5>
                    </div>
                    <div class="card-body">
                        <?php
                        $threshold = 75;
                        $query = "
                            SELECT 
                                s.StudentID,
                                u.FirstName + ' ' + u.LastName as StudentName,
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
                            INNER JOIN dbo.Attendance_Sessions ats ON c.CourseID = ats.CourseID
                            LEFT JOIN dbo.Attendance_Records ar ON ats.SessionID = ar.SessionID AND ar.StudentID = s.StudentID
                            WHERE e.Status = 'Active'
                            AND s.IsActive = 1
                            AND c.IsActive = 1
                            GROUP BY s.StudentID, u.FirstName, u.LastName, c.CourseCode, c.CourseName
                            HAVING CAST(SUM(CASE WHEN ar.Status = 'Present' THEN 1 ELSE 0 END) AS FLOAT) / 
                                   NULLIF(COUNT(ar.AttendanceID), 0) * 100 < ?
                        ";
                        
                        $stmt = sqlsrv_query($conn, $query, [$threshold]);
                        $lowAttendanceCount = 0;
                        
                        if ($stmt) {
                            echo '<div class="table-responsive">';
                            echo '<table class="table table-sm table-striped">';
                            echo '<thead class="table-dark">';
                            echo '<tr><th>Student</th><th>Course</th><th>Sessions</th><th>Present</th><th>%</th></tr>';
                            echo '</thead><tbody>';
                            
                            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                                $lowAttendanceCount++;
                                $percentage = round($row['AttendancePercentage'], 2);
                                echo '<tr>';
                                echo '<td>' . htmlspecialchars($row['StudentName']) . '</td>';
                                echo '<td>' . htmlspecialchars($row['CourseCode']) . '</td>';
                                echo '<td>' . $row['TotalSessions'] . '</td>';
                                echo '<td>' . $row['PresentCount'] . '</td>';
                                echo '<td><span class="badge bg-danger">' . $percentage . '%</span></td>';
                                echo '</tr>';
                            }
                            
                            echo '</tbody></table></div>';
                            
                            if ($lowAttendanceCount == 0) {
                                echo '<div class="alert alert-info"><i class="fas fa-info-circle me-2"></i>No students with attendance below ' . $threshold . '%</div>';
                            } else {
                                echo '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle me-2"></i>Found <strong>' . $lowAttendanceCount . '</strong> student(s) with low attendance</div>';
                            }
                            
                            sqlsrv_free_stmt($stmt);
                        }
                        ?>
                    </div>
                </div>

                <!-- Test 4: Generate Alerts Button -->
                <div class="card mb-3">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="fas fa-bell me-2"></i>4. Generate Alerts</h5>
                    </div>
                    <div class="card-body">
                        <p>Click the button below to generate alerts for students with low attendance:</p>
                        <button id="generateBtn" class="btn btn-success btn-lg w-100">
                            <i class="fas fa-play-circle me-2"></i>Generate Alerts Now
                        </button>
                        <div id="generateResult" class="mt-3"></div>
                    </div>
                </div>

                <!-- Test 5: View Current Alerts -->
                <div class="card mb-3">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0"><i class="fas fa-list me-2"></i>5. Current Alerts</h5>
                    </div>
                    <div class="card-body">
                        <?php
                        $alertsQuery = "
                            SELECT TOP 10
                                a.AlertID,
                                u.FirstName + ' ' + u.LastName as StudentName,
                                c.CourseName,
                                a.Message,
                                a.IsRead,
                                a.CreatedDate
                            FROM dbo.Alerts a
                            JOIN dbo.Students s ON a.StudentID = s.StudentID
                            JOIN dbo.Users u ON s.UserID = u.UserID
                            LEFT JOIN dbo.Courses c ON a.CourseID = c.CourseID
                            ORDER BY a.CreatedDate DESC
                        ";
                        
                        $alertsStmt = sqlsrv_query($conn, $alertsQuery);
                        
                        if ($alertsStmt) {
                            $alertCount = 0;
                            echo '<div class="list-group">';
                            
                            while ($alert = sqlsrv_fetch_array($alertsStmt, SQLSRV_FETCH_ASSOC)) {
                                $alertCount++;
                                $readBadge = $alert['IsRead'] ? '<span class="badge bg-secondary">Read</span>' : '<span class="badge bg-danger">Unread</span>';
                                
                                echo '<div class="list-group-item">';
                                echo '<div class="d-flex justify-content-between align-items-start">';
                                echo '<div class="flex-grow-1">';
                                echo '<h6 class="mb-1">' . htmlspecialchars($alert['StudentName']) . ' - ' . htmlspecialchars($alert['CourseName']) . '</h6>';
                                echo '<p class="mb-1 small">' . htmlspecialchars($alert['Message']) . '</p>';
                                echo '<small class="text-muted">' . $alert['CreatedDate']->format('Y-m-d H:i:s') . '</small>';
                                echo '</div>';
                                echo '<div>' . $readBadge . '</div>';
                                echo '</div></div>';
                            }
                            
                            echo '</div>';
                            
                            if ($alertCount == 0) {
                                echo '<div class="alert alert-info"><i class="fas fa-info-circle me-2"></i>No alerts in database yet</div>';
                            }
                            
                            sqlsrv_free_stmt($alertsStmt);
                        }
                        ?>
                    </div>
                </div>

                <!-- Summary -->
                <div class="card border-primary">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-clipboard-check me-2"></i>Test Summary</h5>
                    </div>
                    <div class="card-body">
                        <p><strong>System Status:</strong></p>
                        <ul>
                            <li>Database: <?php echo $conn ? '✅ Connected' : '❌ Disconnected'; ?></li>
                            <li>Alerts Table: <?php echo $tableCheck ? '✅ Available' : '❌ Not Found'; ?></li>
                            <li>Low Attendance Students: <?php echo $lowAttendanceCount; ?> found</li>
                        </ul>
                        <p class="mb-0"><strong>Next Steps:</strong></p>
                        <ol class="mb-0">
                            <li>If low attendance students exist, click "Generate Alerts Now"</li>
                            <li>Check the Admin Dashboard to see alerts</li>
                            <li>Set up Windows Task Scheduler for automatic generation</li>
                        </ol>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('generateBtn').addEventListener('click', function() {
            const btn = this;
            const resultDiv = document.getElementById('generateResult');
            
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Generating...';
            
            fetch('alert_generator.php?action=generate&threshold=75')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        resultDiv.innerHTML = `
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle me-2"></i>
                                <strong>Success!</strong><br>
                                Alerts Created: <strong>${data.created}</strong><br>
                                Alerts Updated: <strong>${data.updated}</strong><br>
                                <small>Refresh the page to see the updated alerts list</small>
                            </div>
                        `;
                    } else {
                        resultDiv.innerHTML = `
                            <div class="alert alert-danger">
                                <i class="fas fa-times-circle me-2"></i>
                                <strong>Error:</strong> ${data.message}
                            </div>
                        `;
                    }
                    
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-play-circle me-2"></i>Generate Alerts Now';
                })
                .catch(error => {
                    resultDiv.innerHTML = `
                        <div class="alert alert-danger">
                            <i class="fas fa-times-circle me-2"></i>
                            <strong>Error:</strong> ${error.message}
                        </div>
                    `;
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-play-circle me-2"></i>Generate Alerts Now';
                });
        });
    </script>
</body>
</html>

<?php
sqlsrv_close($conn);
?>