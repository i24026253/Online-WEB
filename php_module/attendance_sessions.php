<?php
// Include header component
require_once 'header.php';

// Include the database connection
require_once 'connect.php';

function successAlert($text) {
    return '<div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>' . htmlspecialchars($text) . '
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>';
}

function errorAlert($text) {
    return '<div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-times-circle me-2"></i>' . htmlspecialchars($text) . '
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>';
}

// Get username from URL
$username = isset($_GET['username']) ? $_GET['username'] : null;

if (!$username) {
    die("<p style='color:red;'>Please log in as a lecturer.</p>");
}

// Verify lecturer role and get user info
$lecturer_query = "SELECT u.UserID, u.Role, l.LecturerID FROM dbo.Users u 
                   JOIN dbo.Lecturers l ON u.UserID = l.UserID 
                   WHERE u.Username = ?";
$params = array($username);

$lecturer_result = sqlsrv_query($conn, $lecturer_query, $params);

if ($lecturer_result === false) {
    error_log("Lecturer query error: " . print_r(sqlsrv_errors(), true));
    die("<p style='color:red;'>Database error: " . print_r(sqlsrv_errors(), true) . "</p>");
}

$lecturer_row = sqlsrv_fetch_array($lecturer_result, SQLSRV_FETCH_ASSOC);
if (!$lecturer_row || $lecturer_row['Role'] !== 'Lecturer') {
    error_log("User $username is not a lecturer");
    die("<p style='color:red;'>Access denied: Lecturer privileges required.</p>");
}

$lecturer_id = $lecturer_row['LecturerID'];
$user_role = strtolower($lecturer_row['Role']);

// Handle create attendance mark
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_attendance'])) {
    $course_id = (int)$_POST['course_id'];
    $mark_date = $_POST['mark_date'];

    $errors = [];

    if ($course_id <= 0) {
        $errors[] = "Please select a <strong>course</strong>.";
    }
    if (empty($mark_date)) {
        $errors[] = "Please select an <strong>attendance date</strong>.";
    }

    if (!empty($errors)) {
        $message = '<div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <strong><i class="fas fa-exclamation-circle me-2"></i> Please fix the following:</strong>
                        <ul class="mb-0 mt-2">';
        foreach ($errors as $e) $message .= "<li>$e</li>";
        $message .= '    </ul>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>';
    } else {
        // Check if course is assigned to lecturer
        $check_sql = "SELECT CourseID FROM dbo.Course_Assignments 
                      WHERE CourseID = ? AND LecturerID = ? AND IsActive = 1";
        $check_stmt = sqlsrv_query($conn, $check_sql, [$course_id, $lecturer_id]);
        
        if ($check_stmt === false) {
            error_log("Check course error: " . print_r(sqlsrv_errors(), true));
            $message = errorAlert("Database error while validating course.");
        } elseif (sqlsrv_has_rows($check_stmt) === false) {
            $message = errorAlert("Invalid course selected.");
        } else {
            // Check if already exists for this date
            $dup_sql = "SELECT MarkID FROM dbo.Attendance_Mark WHERE CourseID = ? AND [Date] = ?";
            $dup_stmt = sqlsrv_query($conn, $dup_sql, [$course_id, $mark_date]);
            
            if ($dup_stmt && sqlsrv_has_rows($dup_stmt)) {
                $message = errorAlert("Attendance record for this course and date already exists.");
            } else {
                $ins_sql = "INSERT INTO dbo.Attendance_Mark (CourseID, [Date]) VALUES (?, ?)";
                $ins_stmt = sqlsrv_query($conn, $ins_sql, [$course_id, $mark_date]);

                if ($ins_stmt) {
                    $message = successAlert("Attendance record created successfully.");
                    header("Location: attendance_sessions.php?username=" . urlencode($username));
                    exit;
                } else {
                    error_log("Insert error: " . print_r(sqlsrv_errors(), true));
                    $message = errorAlert("Failed to create attendance record.");
                }
            }
            if (isset($dup_stmt) && $dup_stmt !== false) sqlsrv_free_stmt($dup_stmt);
        }
        if (isset($check_stmt) && $check_stmt !== false) sqlsrv_free_stmt($check_stmt);
        if (isset($ins_stmt) && $ins_stmt !== false) sqlsrv_free_stmt($ins_stmt);
    }
}

// Handle delete attendance mark
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_attendance'])) {
    $mark_id = (int)$_POST['mark_id'];

    if ($mark_id <= 0) {
        $message = errorAlert("Invalid attendance record.");
    } else {
        // Verify ownership through Course_Assignments
        $delete_sql = "DELETE FROM dbo.Attendance_Mark 
                       WHERE MarkID = ? 
                       AND CourseID IN (SELECT CourseID FROM dbo.Course_Assignments WHERE LecturerID = ? AND IsActive = 1)";
        $delete_result = sqlsrv_query($conn, $delete_sql, [$mark_id, $lecturer_id]);

        if ($delete_result) {
            $message = successAlert("Attendance record deleted successfully.");
            header("Location: attendance_sessions.php?username=" . urlencode($username));
            exit;
        } else {
            error_log("Delete Attendance_Mark error: " . print_r(sqlsrv_errors(), true));
            $message = errorAlert("Failed to delete attendance record.");
        }
    }
}

// Fetch lecturer's assigned courses
$courses_query = "SELECT DISTINCT c.CourseID, c.CourseCode, c.CourseName, c.Department
                  FROM dbo.Courses c
                  JOIN dbo.Course_Assignments ca ON c.CourseID = ca.CourseID
                  WHERE ca.LecturerID = ? AND ca.IsActive = 1 AND c.IsActive = 1
                  ORDER BY c.CourseCode";
$courses_result = sqlsrv_query($conn, $courses_query, [$lecturer_id]);

if ($courses_result === false) {
    error_log("Courses query error: " . print_r(sqlsrv_errors(), true));
    die("<p style='color:red;'>Database error: " . print_r(sqlsrv_errors(), true) . "</p>");
}

$courses = [];
while ($course = sqlsrv_fetch_array($courses_result, SQLSRV_FETCH_ASSOC)) {
    $courses[] = $course;
}
sqlsrv_free_stmt($courses_result);

// Fetch all attendance marks
$marks_query = "
    SELECT am.MarkID,
           am.[Date] AS AttendanceDate,
           am.MarkedTime,
           am.EditedTime,
           c.CourseCode,
           c.CourseName,
           c.Department,
           c.CourseID
    FROM dbo.Attendance_Mark am
    JOIN dbo.Courses c ON am.CourseID = c.CourseID
    JOIN dbo.Course_Assignments ca ON c.CourseID = ca.CourseID
    WHERE ca.LecturerID = ? AND ca.IsActive = 1
    ORDER BY am.[Date] DESC, c.CourseCode";
$marks_result = sqlsrv_query($conn, $marks_query, [$lecturer_id]);

if ($marks_result === false) {
    error_log("Marks query error: " . print_r(sqlsrv_errors(), true));
    die("<p style='color:red;'>Database error: " . print_r(sqlsrv_errors(), true) . "</p>");
}

$all_marks = [];
while ($mark = sqlsrv_fetch_array($marks_result, SQLSRV_FETCH_ASSOC)) {
    $all_marks[] = $mark;
}
sqlsrv_free_stmt($marks_result);

// Render header with navigation
renderHeader($username, $user_role, 'attendance');
?>

<!-- Page Content Starts Here -->
<div class="mb-4 d-flex justify-content-between align-items-center">
    <h1 class="h2"><i class="fas fa-calendar-check me-2"></i>Attendance Records</h1>
    <a class="nav-link" href="http://127.0.0.1:8000/lecturer-dashboard/">
        <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
    </a>
</div>

<?php if (isset($message)) echo $message; ?>

<!-- Create Attendance Button -->
<button type="button" class="btn btn-success mb-3" data-bs-toggle="modal" data-bs-target="#createAttendanceModal">
    <i class="fas fa-plus-circle me-2"></i>Create New Attendance Record
</button>

<!-- FILTERS -->
<div class="card mb-3 shadow-sm">
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-md-10">
                <label class="form-label fw-semibold">
                    <i class="fas fa-book me-1"></i>Filter by Course
                </label>
                <select class="form-select" id="filterCourse">
                    <option value="">All Courses</option>
                    <?php foreach ($courses as $c) { ?>
                        <option value="<?php echo $c['CourseID']; ?>">
                            <?php echo htmlspecialchars($c['CourseCode'] . ' – ' . $c['CourseName']); ?>
                        </option>
                    <?php } ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="button" class="btn btn-outline-secondary w-100" id="clearFilter">
                    <i class="fas fa-redo me-1"></i> Reset
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Attendance Records Table -->
<div class="card shadow-sm">
    <div class="card-header bg-white border-bottom">
        <h5 class="mb-0"><i class="fas fa-list me-2"></i>All Attendance Records</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4 py-3">Course</th>
                        <th class="py-3">Department</th>
                        <th class="py-3">Date</th>
                        <th class="text-center py-3" style="width: 200px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="marksBody">
                    <!-- Filled by JavaScript -->
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Create Attendance Modal -->
<div class="modal fade" id="createAttendanceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-calendar-plus me-2"></i>Create Attendance Record
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="attendance_sessions.php?username=<?php echo urlencode($username); ?>">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Course <span class="text-danger">*</span></label>
                        <select class="form-control" name="course_id" required>
                            <option value="">-- Select Course --</option>
                            <?php foreach ($courses as $course) { ?>
                                <option value="<?php echo $course['CourseID']; ?>">
                                    <?php echo htmlspecialchars($course['CourseCode'] . ' - ' . $course['CourseName']); ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Attendance Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="mark_date" required>
                    </div>

                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <small>This will create a new attendance record. You can mark student attendance after creation.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="create_attendance" class="btn btn-success">
                        <i class="fas fa-check me-2"></i>Create
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// === TABLE RENDERING & FILTERING ===
const allRows = <?php echo json_encode($all_marks); ?>;
const tbody   = document.getElementById('marksBody');

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, m => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[m]);
}

function formatDate(dateObj) {
    if (!dateObj || !dateObj.date) return '—';
    const d = new Date(dateObj.date);
    return d.toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' });
}

function renderRow(m) {
    const marked = m.MarkedTime !== null;
    const edited = m.EditedTime !== null;
    
    let btnClass, btnText, btnIcon;
    
    if (!marked) {
        // Not yet marked
        btnClass = 'btn-success';
        btnText = 'Mark';
        btnIcon = 'fa-clipboard-check';
    } else if (edited) {
        // Marked and edited
        btnClass = 'btn-primary';
        btnText = 'Edit';
        btnIcon = 'fa-edit';
    } else {
        // Just marked
        btnClass = 'btn-primary';
        btnText = 'Edit';
        btnIcon = 'fa-edit';
    }

    return `
        <tr data-course="${m.CourseID}">
            <td class="px-4 py-3">
                <div class="fw-semibold">${escapeHtml(m.CourseCode)}</div>
                <small class="text-muted">${escapeHtml(m.CourseName)}</small>
            </td>
            <td class="py-3">
                <small class="text-muted">${escapeHtml(m.Department)}</small>
            </td>
            <td class="py-3">
                <strong>${formatDate(m.AttendanceDate)}</strong>
            </td>
            <td class="text-center py-3">
                <div class="btn-group" role="group">
                    <a href="mark_attendance.php?username=<?php echo urlencode($username); ?>
                        &course_id=${m.CourseID}&mark_id=${m.MarkID}"
                       class="btn ${btnClass} btn-sm" title="${btnText} Attendance">
                        <i class="fas ${btnIcon} me-1"></i>${btnText}
                    </a>
                    <form method="POST" style="display:inline;" 
                          onsubmit="return confirm('Delete this attendance record? All student attendance data for this date will be removed.');">
                        <input type="hidden" name="mark_id" value="${m.MarkID}">
                        <button type="submit" name="delete_attendance"
                                class="btn btn-outline-danger btn-sm" 
                                title="Delete attendance record">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </form>
                </div>
            </td>
        </tr>`;
}

function renderAll() {
    if (allRows.length === 0) {
        tbody.innerHTML = `<tr><td colspan="4" class="text-center text-muted py-5">
                              <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                              No attendance records created yet.
                           </td></tr>`;
        return;
    }
    tbody.innerHTML = allRows.map(renderRow).join('');
}
renderAll();

// === FILTER LOGIC ===
const filterCourse = document.getElementById('filterCourse');
const clearBtn = document.getElementById('clearFilter');

filterCourse.addEventListener('change', applyFilters);
clearBtn.addEventListener('click', () => {
    filterCourse.value = '';
    applyFilters();
});

function applyFilters() {
    const courseId = filterCourse.value;

    const filtered = allRows.filter(row => {
        return !courseId || row.CourseID == courseId;
    });

    if (filtered.length === 0) {
        tbody.innerHTML = `<tr><td colspan="4" class="text-center text-muted py-5">
                              <i class="fas fa-search fa-2x mb-2 d-block"></i>
                              No records match the selected filters.
                           </td></tr>`;
        return;
    }
    tbody.innerHTML = filtered.map(renderRow).join('');
}
</script>

<?php
// Render footer
renderFooter();

// Clean up
sqlsrv_close($conn);
?>