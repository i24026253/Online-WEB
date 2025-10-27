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

// Get filter parameters
$course_filter = isset($_GET['course_id']) ? (int)$_GET['course_id'] : null;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : null;
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : null;

// Build query based on user role
if ($user_role === 'student') {
    // Fetch student's courses
    $courses_query = "SELECT DISTINCT c.CourseID, c.CourseCode, c.CourseName
                      FROM dbo.Courses c
                      JOIN dbo.Enrollments e ON c.CourseID = e.CourseID
                      WHERE e.StudentID = ? AND e.Status = 'Active'
                      ORDER BY c.CourseCode";
    $courses_params = array($student_id);
    $courses_result = sqlsrv_query($conn, $courses_query, $courses_params);
    
    // Fetch attendance records for student
    $attendance_query = "SELECT ar.AttendanceID, ar.Status, ar.MarkedTime, ar.Remarks,
                               s.SessionDate, s.SessionStartTime, s.SessionEndTime, 
                               s.SessionType, s.Location,
                               c.CourseCode, c.CourseName,
                               u.FirstName + ' ' + u.LastName as LecturerName
                        FROM dbo.Attendance_Records ar
                        JOIN dbo.Attendance_Sessions s ON ar.SessionID = s.SessionID
                        JOIN dbo.Courses c ON s.CourseID = c.CourseID
                        JOIN dbo.Lecturers l ON s.LecturerID = l.LecturerID
                        JOIN dbo.Users u ON l.UserID = u.UserID
                        WHERE ar.StudentID = ?";
    $attendance_params = array($student_id);
    
    if ($course_filter) {
        $attendance_query .= " AND c.CourseID = ?";
        $attendance_params[] = $course_filter;
    }
    if ($date_from) {
        $attendance_query .= " AND s.SessionDate >= ?";
        $attendance_params[] = $date_from;
    }
    if ($date_to) {
        $attendance_query .= " AND s.SessionDate <= ?";
        $attendance_params[] = $date_to;
    }
    
    $attendance_query .= " ORDER BY s.SessionDate DESC, s.SessionStartTime DESC";
    
} elseif ($user_role === 'lecturer') {
    // Fetch lecturer's courses
    $courses_query = "SELECT DISTINCT c.CourseID, c.CourseCode, c.CourseName
                      FROM dbo.Courses c
                      JOIN dbo.Course_Assignments ca ON c.CourseID = ca.CourseID
                      WHERE ca.LecturerID = ? AND ca.IsActive = 1
                      ORDER BY c.CourseCode";
    $courses_params = array($lecturer_id);
    $courses_result = sqlsrv_query($conn, $courses_query, $courses_params);
    
    // Fetch attendance records for lecturer's sessions
    $attendance_query = "SELECT ar.AttendanceID, ar.Status, ar.MarkedTime, ar.Remarks,
                               s.SessionDate, s.SessionStartTime, s.SessionEndTime, 
                               s.SessionType, s.Location,
                               c.CourseCode, c.CourseName,
                               st.StudentNumber, u.FirstName + ' ' + u.LastName as StudentName
                        FROM dbo.Attendance_Records ar
                        JOIN dbo.Attendance_Sessions s ON ar.SessionID = s.SessionID
                        JOIN dbo.Courses c ON s.CourseID = c.CourseID
                        JOIN dbo.Students st ON ar.StudentID = st.StudentID
                        JOIN dbo.Users u ON st.UserID = u.UserID
                        WHERE s.LecturerID = ?";
    $attendance_params = array($lecturer_id);
    
    if ($course_filter) {
        $attendance_query .= " AND c.CourseID = ?";
        $attendance_params[] = $course_filter;
    }
    if ($date_from) {
        $attendance_query .= " AND s.SessionDate >= ?";
        $attendance_params[] = $date_from;
    }
    if ($date_to) {
        $attendance_query .= " AND s.SessionDate <= ?";
        $attendance_params[] = $date_to;
    }
    
    $attendance_query .= " ORDER BY s.SessionDate DESC, s.SessionStartTime DESC, st.StudentNumber";
    
} elseif ($user_role === 'admin') {
    // Fetch all courses
    $courses_query = "SELECT CourseID, CourseCode, CourseName
                      FROM dbo.Courses
                      WHERE IsActive = 1
                      ORDER BY CourseCode";
    $courses_result = sqlsrv_query($conn, $courses_query);
    
    // Fetch all attendance records
    $attendance_query = "SELECT ar.AttendanceID, ar.Status, ar.MarkedTime, ar.Remarks,
                               s.SessionDate, s.SessionStartTime, s.SessionEndTime, 
                               s.SessionType, s.Location,
                               c.CourseCode, c.CourseName,
                               st.StudentNumber, u1.FirstName + ' ' + u1.LastName as StudentName,
                               u2.FirstName + ' ' + u2.LastName as LecturerName
                        FROM dbo.Attendance_Records ar
                        JOIN dbo.Attendance_Sessions s ON ar.SessionID = s.SessionID
                        JOIN dbo.Courses c ON s.CourseID = c.CourseID
                        JOIN dbo.Students st ON ar.StudentID = st.StudentID
                        JOIN dbo.Users u1 ON st.UserID = u1.UserID
                        JOIN dbo.Lecturers l ON s.LecturerID = l.LecturerID
                        JOIN dbo.Users u2 ON l.UserID = u2.UserID
                        WHERE 1=1";
    $attendance_params = array();
    
    if ($course_filter) {
        $attendance_query .= " AND c.CourseID = ?";
        $attendance_params[] = $course_filter;
    }
    if ($date_from) {
        $attendance_query .= " AND s.SessionDate >= ?";
        $attendance_params[] = $date_from;
    }
    if ($date_to) {
        $attendance_query .= " AND s.SessionDate <= ?";
        $attendance_params[] = $date_to;
    }
    
    $attendance_query .= " ORDER BY s.SessionDate DESC, s.SessionStartTime DESC, st.StudentNumber";
}

// Execute queries
$courses = [];
if (isset($courses_result) && $courses_result !== false) {
    while ($course = sqlsrv_fetch_array($courses_result, SQLSRV_FETCH_ASSOC)) {
        $courses[] = $course;
    }
    sqlsrv_free_stmt($courses_result);
}

$attendance_result = sqlsrv_query($conn, $attendance_query, $attendance_params);

if ($attendance_result === false) {
    error_log("Attendance query error: " . print_r(sqlsrv_errors(), true));
    die("<p style='color:red;'>❌ Database error: " . print_r(sqlsrv_errors(), true) . "</p>");
}

// Calculate statistics
$total_records = 0;
$present_count = 0;
$absent_count = 0;
$late_count = 0;
$excused_count = 0;

$attendance_records = [];
while ($record = sqlsrv_fetch_array($attendance_result, SQLSRV_FETCH_ASSOC)) {
    $attendance_records[] = $record;
    $total_records++;
    
    switch ($record['Status']) {
        case 'Present': $present_count++; break;
        case 'Absent': $absent_count++; break;
        case 'Late': $late_count++; break;
        case 'Excused': $excused_count++; break;
    }
}

$attendance_percentage = $total_records > 0 ? 
    round((($present_count + $late_count) / $total_records) * 100, 1) : 0;

// Render header with navigation
renderHeader($username, $user_role, 'attendance');
?>

<!-- Page Content Starts Here -->
<div class="mb-4">
    <h1 class="h2"><i class="fas fa-chart-line me-2"></i>View Attendance Records</h1>
</div>

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card stat-card card-info h-100">
            <div class="card-body text-center">
                <i class="fas fa-clipboard-list fa-3x text-info mb-3"></i>
                <h5><?php echo $total_records; ?></h5>
                <p class="text-muted">Total Records</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card card-success h-100">
            <div class="card-body text-center">
                <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                <h5><?php echo $present_count; ?></h5>
                <p class="text-muted">Present</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card card-danger h-100">
            <div class="card-body text-center">
                <i class="fas fa-times-circle fa-3x text-danger mb-3"></i>
                <h5><?php echo $absent_count; ?></h5>
                <p class="text-muted">Absent</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card card-primary h-100">
            <div class="card-body text-center">
                <i class="fas fa-percentage fa-3x text-primary mb-3"></i>
                <h5><?php echo $attendance_percentage; ?>%</h5>
                <p class="text-muted">Attendance Rate</p>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-header bg-white">
        <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filters</h5>
    </div>
    <div class="card-body">
        <form method="GET" action="view_attendance.php" class="row g-3">
            <input type="hidden" name="username" value="<?php echo htmlspecialchars($username); ?>">
            
            <div class="col-md-4">
                <label class="form-label">Course</label>
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
            
            <div class="col-md-3">
                <label class="form-label">Date From</label>
                <input type="date" class="form-control" name="date_from" 
                       value="<?php echo htmlspecialchars($date_from ?? ''); ?>">
            </div>
            
            <div class="col-md-3">
                <label class="form-label">Date To</label>
                <input type="date" class="form-control" name="date_to" 
                       value="<?php echo htmlspecialchars($date_to ?? ''); ?>">
            </div>
            
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-search me-2"></i>Filter
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Attendance Records Table -->
<div class="card">
    <div class="card-header bg-white">
        <h5 class="mb-0"><i class="fas fa-list me-2"></i>Attendance Records</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead class="table-light">
                    <tr>
                        <?php if ($user_role === 'lecturer' || $user_role === 'admin') { ?>
                            <th>Student No.</th>
                            <th>Student Name</th>
                        <?php } ?>
                        <th>Course</th>
                        <th>Session Date</th>
                        <th>Time</th>
                        <th>Type</th>
                        <th>Location</th>
                        <?php if ($user_role === 'admin') { ?>
                            <th>Lecturer</th>
                        <?php } ?>
                        <th>Status</th>
                        <th>Marked Time</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($attendance_records)) { ?>
                        <tr>
                            <td colspan="<?php echo ($user_role === 'admin' ? 11 : ($user_role === 'lecturer' ? 10 : 8)); ?>" 
                                class="text-center text-muted">
                                No attendance records found. Try adjusting your filters.
                            </td>
                        </tr>
                    <?php } else {
                        foreach ($attendance_records as $record) { ?>
                        <tr>
                            <?php if ($user_role === 'lecturer' || $user_role === 'admin') { ?>
                                <td><strong><?php echo htmlspecialchars($record['StudentNumber']); ?></strong></td>
                                <td><?php echo htmlspecialchars($record['StudentName']); ?></td>
                            <?php } ?>
                            <td>
                                <strong><?php echo htmlspecialchars($record['CourseCode']); ?></strong><br>
                                <small class="text-muted"><?php echo htmlspecialchars($record['CourseName']); ?></small>
                            </td>
                            <td><?php echo $record['SessionDate']->format('M d, Y'); ?></td>
                            <td>
                                <small>
                                    <?php echo $record['SessionStartTime']->format('h:i A'); ?> -
                                    <?php echo $record['SessionEndTime']->format('h:i A'); ?>
                                </small>
                            </td>
                            <td>
                                <span class="badge bg-info">
                                    <?php echo htmlspecialchars($record['SessionType']); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($record['Location']); ?></td>
                            <?php if ($user_role === 'admin') { ?>
                                <td><?php echo htmlspecialchars($record['LecturerName']); ?></td>
                            <?php } elseif ($user_role === 'student') { ?>
                                <!-- Student view shows lecturer name in a different column -->
                            <?php } ?>
                            <td>
                                <?php
                                $status_class = '';
                                $status_icon = '';
                                switch ($record['Status']) {
                                    case 'Present':
                                        $status_class = 'success';
                                        $status_icon = 'check';
                                        break;
                                    case 'Absent':
                                        $status_class = 'danger';
                                        $status_icon = 'times';
                                        break;
                                    case 'Late':
                                        $status_class = 'warning';
                                        $status_icon = 'clock';
                                        break;
                                    case 'Excused':
                                        $status_class = 'info';
                                        $status_icon = 'user-check';
                                        break;
                                }
                                ?>
                                <span class="badge bg-<?php echo $status_class; ?>">
                                    <i class="fas fa-<?php echo $status_icon; ?> me-1"></i>
                                    <?php echo htmlspecialchars($record['Status']); ?>
                                </span>
                            </td>
                            <td>
                                <small class="text-muted">
                                    <?php echo $record['MarkedTime']->format('M d, Y h:i A'); ?>
                                </small>
                            </td>
                            <td>
                                <?php if ($record['Remarks']) { ?>
                                    <span class="badge bg-secondary" data-bs-toggle="tooltip" 
                                          title="<?php echo htmlspecialchars($record['Remarks']); ?>">
                                        <i class="fas fa-comment me-1"></i>View
                                    </span>
                                <?php } else { ?>
                                    <span class="text-muted">-</span>
                                <?php } ?>
                            </td>
                        </tr>
                    <?php } 
                    } ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer bg-white">
        <div class="row align-items-center">
            <div class="col">
                <small class="text-muted">
                    Showing <?php echo count($attendance_records); ?> record(s)
                </small>
            </div>
            <div class="col-auto">
                <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-print me-1"></i>Print Report
                </button>
                <a href="export_attendance.php?username=<?php echo urlencode($username); ?>&course_id=<?php echo $course_filter ?? ''; ?>&date_from=<?php echo $date_from ?? ''; ?>&date_to=<?php echo $date_to ?? ''; ?>" 
                   class="btn btn-outline-success btn-sm">
                    <i class="fas fa-file-excel me-1"></i>Export to Excel
                </a>
            </div>
        </div>
    </div>
</div>

<script>
// Initialize tooltips
var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
    return new bootstrap.Tooltip(tooltipTriggerEl)
});
</script>

<style>
@media print {
    .btn, .card-footer, .card-header h5 i, nav, .filter-section {
        display: none !important;
    }
    .card {
        border: none !important;
        box-shadow: none !important;
    }
    .table {
        font-size: 10px;
    }
}
</style>

<?php
// Render footer
renderFooter();

// Clean up
sqlsrv_free_stmt($user_result);
sqlsrv_free_stmt($attendance_result);
sqlsrv_close($conn);
?>