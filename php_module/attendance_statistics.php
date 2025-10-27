<?php
// Include header component
require_once 'header.php';

// Include the database connection
require_once 'connect.php';

// Get username from URL
$username = isset($_GET['username']) ? $_GET['username'] : null;

if (!$username) {
    die("<p style='color:red;'>❌ Please log in.</p>");
}

// Get user info
$user_query = "SELECT u.UserID, u.Role, u.FirstName, u.LastName,
                      s.StudentID, l.LecturerID
               FROM dbo.Users u
               LEFT JOIN dbo.Students s ON u.UserID = s.UserID
               LEFT JOIN dbo.Lecturers l ON u.UserID = l.UserID
               WHERE u.Username = ?";
$params = array($username);
$user_result = sqlsrv_query($conn, $user_query, $params);

if ($user_result === false) {
    error_log("User query error: " . print_r(sqlsrv_errors(), true));
    die("<p style='color:red;'>❌ Database error: " . print_r(sqlsrv_errors(), true) . "</p>");
}

$user_row = sqlsrv_fetch_array($user_result, SQLSRV_FETCH_ASSOC);
if (!$user_row) {
    die("<p style='color:red;'>❌ User not found.</p>");
}

$user_role = strtolower($user_row['Role']);
$student_id = $user_row['StudentID'];
$lecturer_id = $user_row['LecturerID'];

// Get course filter
$course_filter = isset($_GET['course_id']) ? (int)$_GET['course_id'] : null;

// Fetch statistics based on role
if ($user_role === 'student' && $student_id) {
    // Student's courses
    $courses_query = "SELECT c.CourseID, c.CourseCode, c.CourseName
                      FROM dbo.Courses c
                      JOIN dbo.Enrollments e ON c.CourseID = e.CourseID
                      WHERE e.StudentID = ? AND e.Status = 'Active'
                      ORDER BY c.CourseCode";
    $courses_params = array($student_id);
    $courses_result = sqlsrv_query($conn, $courses_query, $courses_params);
    
    // Student's attendance statistics
    $stats_query = "SELECT 
                        c.CourseID,
                        c.CourseCode,
                        c.CourseName,
                        COUNT(DISTINCT s.SessionID) as TotalSessions,
                        SUM(CASE WHEN ar.Status = 'Present' THEN 1 ELSE 0 END) as PresentCount,
                        SUM(CASE WHEN ar.Status = 'Absent' THEN 1 ELSE 0 END) as AbsentCount,
                        SUM(CASE WHEN ar.Status = 'Late' THEN 1 ELSE 0 END) as LateCount,
                        SUM(CASE WHEN ar.Status = 'Excused' THEN 1 ELSE 0 END) as ExcusedCount,
                        CASE 
                            WHEN COUNT(ar.AttendanceID) > 0 
                            THEN CAST(ROUND((CAST(SUM(CASE WHEN ar.Status IN ('Present', 'Late') THEN 1 ELSE 0 END) AS FLOAT) / COUNT(ar.AttendanceID)) * 100, 1) AS DECIMAL(5,1))
                            ELSE 0 
                        END as AttendancePercentage
                    FROM dbo.Courses c
                    JOIN dbo.Enrollments e ON c.CourseID = e.CourseID
                    LEFT JOIN dbo.Attendance_Sessions s ON c.CourseID = s.CourseID
                    LEFT JOIN dbo.Attendance_Records ar ON s.SessionID = ar.SessionID AND ar.StudentID = e.StudentID
                    WHERE e.StudentID = ? AND e.Status = 'Active'";
    $stats_params = array($student_id);
    
    if ($course_filter) {
        $stats_query .= " AND c.CourseID = ?";
        $stats_params[] = $course_filter;
    }
    
    $stats_query .= " GROUP BY c.CourseID, c.CourseCode, c.CourseName
                     ORDER BY c.CourseCode";
    
} elseif ($user_role === 'lecturer' && $lecturer_id) {
    // Lecturer's courses
    $courses_query = "SELECT c.CourseID, c.CourseCode, c.CourseName
                      FROM dbo.Courses c
                      JOIN dbo.Course_Assignments ca ON c.CourseID = ca.CourseID
                      WHERE ca.LecturerID = ? AND ca.IsActive = 1
                      ORDER BY c.CourseCode";
    $courses_params = array($lecturer_id);
    $courses_result = sqlsrv_query($conn, $courses_query, $courses_params);
    
    // Lecturer's course statistics
    $stats_query = "SELECT 
                        c.CourseID,
                        c.CourseCode,
                        c.CourseName,
                        COUNT(DISTINCT s.SessionID) as TotalSessions,
                        COUNT(DISTINCT e.StudentID) as EnrolledStudents,
                        COUNT(ar.AttendanceID) as TotalRecords,
                        SUM(CASE WHEN ar.Status = 'Present' THEN 1 ELSE 0 END) as PresentCount,
                        SUM(CASE WHEN ar.Status = 'Absent' THEN 1 ELSE 0 END) as AbsentCount,
                        SUM(CASE WHEN ar.Status = 'Late' THEN 1 ELSE 0 END) as LateCount,
                        SUM(CASE WHEN ar.Status = 'Excused' THEN 1 ELSE 0 END) as ExcusedCount,
                        CASE 
                            WHEN COUNT(ar.AttendanceID) > 0 
                            THEN CAST(ROUND((CAST(SUM(CASE WHEN ar.Status IN ('Present', 'Late') THEN 1 ELSE 0 END) AS FLOAT) / COUNT(ar.AttendanceID)) * 100, 1) AS DECIMAL(5,1))
                            ELSE 0 
                        END as AttendancePercentage
                    FROM dbo.Courses c
                    JOIN dbo.Course_Assignments ca ON c.CourseID = ca.CourseID
                    LEFT JOIN dbo.Enrollments e ON c.CourseID = e.CourseID AND e.Status = 'Active'
                    LEFT JOIN dbo.Attendance_Sessions s ON c.CourseID = s.CourseID AND s.LecturerID = ca.LecturerID
                    LEFT JOIN dbo.Attendance_Records ar ON s.SessionID = ar.SessionID
                    WHERE ca.LecturerID = ? AND ca.IsActive = 1";
    $stats_params = array($lecturer_id);
    
    if ($course_filter) {
        $stats_query .= " AND c.CourseID = ?";
        $stats_params[] = $course_filter;
    }
    
    $stats_query .= " GROUP BY c.CourseID, c.CourseCode, c.CourseName
                     ORDER BY c.CourseCode";
    
} elseif ($user_role === 'admin') {
    // All courses
    $courses_query = "SELECT CourseID, CourseCode, CourseName
                      FROM dbo.Courses
                      WHERE IsActive = 1
                      ORDER BY CourseCode";
    $courses_result = sqlsrv_query($conn, $courses_query);
    
    // Overall statistics
    $stats_query = "SELECT 
                        c.CourseID,
                        c.CourseCode,
                        c.CourseName,
                        COUNT(DISTINCT s.SessionID) as TotalSessions,
                        COUNT(DISTINCT e.StudentID) as EnrolledStudents,
                        COUNT(ar.AttendanceID) as TotalRecords,
                        SUM(CASE WHEN ar.Status = 'Present' THEN 1 ELSE 0 END) as PresentCount,
                        SUM(CASE WHEN ar.Status = 'Absent' THEN 1 ELSE 0 END) as AbsentCount,
                        SUM(CASE WHEN ar.Status = 'Late' THEN 1 ELSE 0 END) as LateCount,
                        SUM(CASE WHEN ar.Status = 'Excused' THEN 1 ELSE 0 END) as ExcusedCount,
                        CASE 
                            WHEN COUNT(ar.AttendanceID) > 0 
                            THEN CAST(ROUND((CAST(SUM(CASE WHEN ar.Status IN ('Present', 'Late') THEN 1 ELSE 0 END) AS FLOAT) / COUNT(ar.AttendanceID)) * 100, 1) AS DECIMAL(5,1))
                            ELSE 0 
                        END as AttendancePercentage
                    FROM dbo.Courses c
                    LEFT JOIN dbo.Enrollments e ON c.CourseID = e.CourseID AND e.Status = 'Active'
                    LEFT JOIN dbo.Attendance_Sessions s ON c.CourseID = s.CourseID
                    LEFT JOIN dbo.Attendance_Records ar ON s.SessionID = ar.SessionID
                    WHERE c.IsActive = 1";
    $stats_params = array();
    
    if ($course_filter) {
        $stats_query .= " AND c.CourseID = ?";
        $stats_params[] = $course_filter;
    }
    
    $stats_query .= " GROUP BY c.CourseID, c.CourseCode, c.CourseName
                     ORDER BY c.CourseCode";
} else {
    die("<p style='color:red;'>❌ Invalid user role.</p>");
}

// Execute queries
$courses = [];
if (isset($courses_result) && $courses_result !== false) {
    while ($course = sqlsrv_fetch_array($courses_result, SQLSRV_FETCH_ASSOC)) {
        $courses[] = $course;
    }
    sqlsrv_free_stmt($courses_result);
}

$stats_result = sqlsrv_query($conn, $stats_query, $stats_params);

if ($stats_result === false) {
    error_log("Statistics query error: " . print_r(sqlsrv_errors(), true));
    die("<p style='color:red;'>❌ Database error: " . print_r(sqlsrv_errors(), true) . "</p>");
}

$statistics = [];
while ($stat = sqlsrv_fetch_array($stats_result, SQLSRV_FETCH_ASSOC)) {
    $statistics[] = $stat;
}

// Calculate overall statistics
$total_sessions = 0;
$total_present = 0;
$total_absent = 0;
$total_late = 0;
$total_excused = 0;
$total_records = 0;

foreach ($statistics as $stat) {
    $total_sessions += $stat['TotalSessions'] ?? 0;
    $total_present += $stat['PresentCount'] ?? 0;
    $total_absent += $stat['AbsentCount'] ?? 0;
    $total_late += $stat['LateCount'] ?? 0;
    $total_excused += $stat['ExcusedCount'] ?? 0;
    $total_records += $stat['TotalRecords'] ?? ($stat['PresentCount'] + $stat['AbsentCount'] + $stat['LateCount'] + $stat['ExcusedCount']);
}

$overall_percentage = $total_records > 0 ? 
    round((($total_present + $total_late) / $total_records) * 100, 1) : 0;

// Render header with navigation
renderHeader($username, $user_role, 'attendance');
?>

<!-- Page Content Starts Here -->
<div class="mb-4">
    <h1 class="h2"><i class="fas fa-chart-bar me-2"></i>Attendance Statistics</h1>
</div>

<!-- Overall Statistics Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card stat-card card-primary h-100">
            <div class="card-body text-center">
                <i class="fas fa-calendar-check fa-3x text-primary mb-3"></i>
                <h5><?php echo $total_sessions; ?></h5>
                <p class="text-muted">Total Sessions</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card card-success h-100">
            <div class="card-body text-center">
                <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                <h5><?php echo $total_present; ?></h5>
                <p class="text-muted">Present</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card card-danger h-100">
            <div class="card-body text-center">
                <i class="fas fa-times-circle fa-3x text-danger mb-3"></i>
                <h5><?php echo $total_absent; ?></h5>
                <p class="text-muted">Absent</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card card-info h-100">
            <div class="card-body text-center">
                <i class="fas fa-percentage fa-3x text-info mb-3"></i>
                <h5><?php echo $overall_percentage; ?>%</h5>
                <p class="text-muted">Overall Attendance</p>
            </div>
        </div>
    </div>
</div>

<!-- Course Filter -->
<div class="card mb-4">
    <div class="card-header bg-white">
        <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filter by Course</h5>
    </div>
    <div class="card-body">
        <form method="GET" action="attendance_statistics.php" class="row g-3">
            <input type="hidden" name="username" value="<?php echo htmlspecialchars($username); ?>">
            
            <div class="col-md-10">
                <select class="form-control" name="course_id">
                    <option value="">All Courses</option>
                    <?php foreach ($courses as $course) { ?>
                        <option value="<?php echo $course['CourseID']; ?>" 
                                <?php echo $course_filter == $course['CourseID'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($course['CourseCode'] . ' - ' . $course['CourseName']); ?>
                        </option>
                    <?php } ?>
                </select>
            </div>
            
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-search me-2"></i>Filter
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Course-wise Statistics -->
<div class="card">
    <div class="card-header bg-white">
        <h5 class="mb-0"><i class="fas fa-list me-2"></i>Course-wise Attendance Statistics</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead class="table-light">
                    <tr>
                        <th>Course Code</th>
                        <th>Course Name</th>
                        <th>Sessions</th>
                        <?php if ($user_role !== 'student') { ?>
                            <th>Students</th>
                        <?php } ?>
                        <th>Present</th>
                        <th>Absent</th>
                        <th>Late</th>
                        <th>Excused</th>
                        <th>Attendance %</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($statistics)) { ?>
                        <tr>
                            <td colspan="<?php echo $user_role === 'student' ? 8 : 10; ?>" 
                                class="text-center text-muted">
                                No statistics available.
                            </td>
                        </tr>
                    <?php } else {
                        foreach ($statistics as $stat) { 
                            $percentage = $stat['AttendancePercentage'];
                            $status_class = '';
                            $status_text = '';
                            
                            if ($percentage >= 80) {
                                $status_class = 'success';
                                $status_text = 'Good';
                            } elseif ($percentage >= 60) {
                                $status_class = 'warning';
                                $status_text = 'Warning';
                            } else {
                                $status_class = 'danger';
                                $status_text = 'Critical';
                            }
                    ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($stat['CourseCode']); ?></strong></td>
                            <td><?php echo htmlspecialchars($stat['CourseName']); ?></td>
                            <td><?php echo $stat['TotalSessions']; ?></td>
                            <?php if ($user_role !== 'student') { ?>
                                <td><?php echo $stat['EnrolledStudents'] ?? 0; ?></td>
                            <?php } ?>
                            <td><span class="badge bg-success"><?php echo $stat['PresentCount']; ?></span></td>
                            <td><span class="badge bg-danger"><?php echo $stat['AbsentCount']; ?></span></td>
                            <td><span class="badge bg-warning text-dark"><?php echo $stat['LateCount']; ?></span></td>
                            <td><span class="badge bg-info"><?php echo $stat['ExcusedCount']; ?></span></td>
                            <td>
                                <div class="progress" style="height: 20px;">
                                    <div class="progress-bar bg-<?php echo $status_class; ?>" 
                                         style="width: <?php echo $percentage; ?>%">
                                        <?php echo $percentage; ?>%
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge bg-<?php echo $status_class; ?>">
                                    <?php echo $status_text; ?>
                                </span>
                            </td>
                        </tr>
                    <?php } 
                    } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
// Render footer
renderFooter();

// Clean up
sqlsrv_free_stmt($user_result);
sqlsrv_free_stmt($stats_result);
sqlsrv_close($conn);
?>