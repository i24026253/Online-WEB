<?php
// Include header component
require_once 'header.php';

// Include the database connection
require_once 'connect.php';

// Get username from URL
$username = isset($_GET['username']) ? $_GET['username'] : null;

if (!$username) {
    die("<p style='color:red;'>❌ Please log in as a lecturer.</p>");
}

// Verify lecturer role and get user info
$lecturer_query = "SELECT u.UserID, u.Role, l.LecturerID FROM dbo.Users u 
                   JOIN dbo.Lecturers l ON u.UserID = l.UserID 
                   WHERE u.Username = ?";
$params = array($username);
$lecturer_result = sqlsrv_query($conn, $lecturer_query, $params);

if ($lecturer_result === false) {
    error_log("Lecturer query error: " . print_r(sqlsrv_errors(), true));
    die("<p style='color:red;'>❌ Database error: " . print_r(sqlsrv_errors(), true) . "</p>");
}

$lecturer_row = sqlsrv_fetch_array($lecturer_result, SQLSRV_FETCH_ASSOC);
if (!$lecturer_row || $lecturer_row['Role'] !== 'Lecturer') {
    error_log("User $username is not a lecturer");
    die("<p style='color:red;'>❌ Access denied: Lecturer privileges required.</p>");
}

$lecturer_id = $lecturer_row['LecturerID'];
$user_role = strtolower($lecturer_row['Role']);

// Get session_id if provided
$session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : null;

// Handle attendance marking submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_attendance'])) {
    $session_id = (int)$_POST['session_id'];
    $marked_by = $lecturer_id;
    $marked_time = date('Y-m-d H:i:s');
    
    $success_count = 0;
    $error_count = 0;
    
    foreach ($_POST['attendance'] as $student_id => $status) {
        $remarks = isset($_POST['remarks'][$student_id]) ? trim($_POST['remarks'][$student_id]) : null;
        if (empty($remarks)) $remarks = null;
        
        // Check if attendance record already exists
        $check_query = "SELECT AttendanceID FROM dbo.Attendance_Records 
                       WHERE SessionID = ? AND StudentID = ?";
        $check_params = array($session_id, $student_id);
        $check_result = sqlsrv_query($conn, $check_query, $check_params);
        
        if ($check_result === false) {
            error_log("Check attendance error: " . print_r(sqlsrv_errors(), true));
            $error_count++;
            continue;
        }
        
        $existing = sqlsrv_fetch_array($check_result, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($check_result);
        
        if ($existing) {
            // Update existing record
            $update_query = "UPDATE dbo.Attendance_Records 
                           SET Status = ?, MarkedTime = ?, MarkedBy = ?, Remarks = ?, 
                               IsEdited = 1, EditedBy = ?, EditedDate = ? 
                           WHERE AttendanceID = ?";
            $update_params = array($status, $marked_time, $marked_by, $remarks, 
                                  $marked_by, $marked_time, $existing['AttendanceID']);
            $result = sqlsrv_query($conn, $update_query, $update_params);
        } else {
            // Insert new record
            $insert_query = "INSERT INTO dbo.Attendance_Records 
                           (SessionID, StudentID, Status, MarkedTime, MarkedBy, Remarks) 
                           VALUES (?, ?, ?, ?, ?, ?)";
            $insert_params = array($session_id, $student_id, $status, $marked_time, 
                                  $marked_by, $remarks);
            $result = sqlsrv_query($conn, $insert_query, $insert_params);
        }
        
        if ($result) {
            $success_count++;
        } else {
            error_log("Mark attendance error: " . print_r(sqlsrv_errors(), true));
            $error_count++;
        }
    }
    
    if ($success_count > 0) {
        $message = "<div class='alert alert-success alert-dismissible fade show' role='alert'>
                    <i class='fas fa-check-circle me-2'></i>Successfully marked attendance for $success_count student(s).
                    <button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
    }
    if ($error_count > 0) {
        $message .= "<div class='alert alert-warning alert-dismissible fade show' role='alert'>
                     <i class='fas fa-exclamation-triangle me-2'></i>Failed to mark $error_count student(s).
                     <button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
    }
}

// Fetch lecturer's assigned courses
$courses_query = "SELECT DISTINCT c.CourseID, c.CourseCode, c.CourseName 
                  FROM dbo.Courses c
                  JOIN dbo.Course_Assignments ca ON c.CourseID = ca.CourseID
                  WHERE ca.LecturerID = ? AND ca.IsActive = 1 AND c.IsActive = 1
                  ORDER BY c.CourseCode";
$courses_params = array($lecturer_id);
$courses_result = sqlsrv_query($conn, $courses_query, $courses_params);

if ($courses_result === false) {
    error_log("Courses query error: " . print_r(sqlsrv_errors(), true));
    die("<p style='color:red;'>❌ Database error: " . print_r(sqlsrv_errors(), true) . "</p>");
}

$courses = [];
while ($course = sqlsrv_fetch_array($courses_result, SQLSRV_FETCH_ASSOC)) {
    $courses[] = $course;
}
sqlsrv_free_stmt($courses_result);

// If session_id is provided, fetch session details and enrolled students
$session_data = null;
$enrolled_students = [];
$attendance_records = [];

if ($session_id) {
    // Fetch session details
    $session_query = "SELECT s.SessionID, s.CourseID, s.SessionDate, s.SessionStartTime, 
                             s.SessionEndTime, s.SessionType, s.Location, 
                             c.CourseCode, c.CourseName
                      FROM dbo.Attendance_Sessions s
                      JOIN dbo.Courses c ON s.CourseID = c.CourseID
                      WHERE s.SessionID = ? AND s.LecturerID = ?";
    $session_params = array($session_id, $lecturer_id);
    $session_result = sqlsrv_query($conn, $session_query, $session_params);
    
    if ($session_result === false) {
        error_log("Session query error: " . print_r(sqlsrv_errors(), true));
        die("<p style='color:red;'>❌ Database error: " . print_r(sqlsrv_errors(), true) . "</p>");
    }
    
    $session_data = sqlsrv_fetch_array($session_result, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($session_result);
    
    if (!$session_data) {
        die("<p style='color:red;'>❌ Session not found or access denied.</p>");
    }
    
    // Fetch enrolled students for the course
    $students_query = "SELECT s.StudentID, s.StudentNumber, u.FirstName, u.LastName, u.Email
                       FROM dbo.Students s
                       JOIN dbo.Users u ON s.UserID = u.UserID
                       JOIN dbo.Enrollments e ON s.StudentID = e.StudentID
                       WHERE e.CourseID = ? AND e.Status = 'Active'
                       ORDER BY s.StudentNumber";
    $students_params = array($session_data['CourseID']);
    $students_result = sqlsrv_query($conn, $students_query, $students_params);
    
    if ($students_result === false) {
        error_log("Students query error: " . print_r(sqlsrv_errors(), true));
        die("<p style='color:red;'>❌ Database error: " . print_r(sqlsrv_errors(), true) . "</p>");
    }
    
    while ($student = sqlsrv_fetch_array($students_result, SQLSRV_FETCH_ASSOC)) {
        $enrolled_students[] = $student;
    }
    sqlsrv_free_stmt($students_result);
    
    // Fetch existing attendance records for this session
    $attendance_query = "SELECT StudentID, Status, Remarks FROM dbo.Attendance_Records 
                        WHERE SessionID = ?";
    $attendance_params = array($session_id);
    $attendance_result = sqlsrv_query($conn, $attendance_query, $attendance_params);
    
    if ($attendance_result !== false) {
        while ($record = sqlsrv_fetch_array($attendance_result, SQLSRV_FETCH_ASSOC)) {
            $attendance_records[$record['StudentID']] = $record;
        }
        sqlsrv_free_stmt($attendance_result);
    }
}

// Render header with navigation
renderHeader($username, $user_role, 'attendance');
?>

<!-- Page Content Starts Here -->
<div class="mb-4">
    <h1 class="h2"><i class="fas fa-clipboard-check me-2"></i>Mark Attendance</h1>
</div>

<!-- Display messages -->
<?php if (isset($message)) echo $message; ?>

<?php if (!$session_id) { ?>
    <!-- Session Selection -->
    <div class="card">
        <div class="card-header bg-white">
            <h5 class="mb-0"><i class="fas fa-calendar-alt me-2"></i>Select Attendance Session</h5>
        </div>
        <div class="card-body">
            <p class="text-muted">Please select a session to mark attendance.</p>
            
            <?php if (empty($courses)) { ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>You are not assigned to any courses yet.
                </div>
            <?php } else { ?>
                <form method="GET" action="mark_attendance.php" class="row g-3">
                    <input type="hidden" name="username" value="<?php echo htmlspecialchars($username); ?>">
                    
                    <div class="col-md-6">
                        <label class="form-label">Course <span class="text-danger">*</span></label>
                        <select class="form-control" id="courseSelect" required>
                            <option value="">-- Select Course --</option>
                            <?php foreach ($courses as $course) { ?>
                                <option value="<?php echo $course['CourseID']; ?>">
                                    <?php echo htmlspecialchars($course['CourseCode'] . ' - ' . $course['CourseName']); ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>
                    
                    <div class="col-md-6">
                        <label class="form-label">Session <span class="text-danger">*</span></label>
                        <select class="form-control" name="session_id" id="sessionSelect" required disabled>
                            <option value="">-- Select Course First --</option>
                        </select>
                    </div>
                    
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-arrow-right me-2"></i>Proceed to Mark Attendance
                        </button>
                        <a href="attendance_sessions.php?username=<?php echo urlencode($username); ?>" class="btn btn-outline-secondary">
                            <i class="fas fa-plus-circle me-2"></i>Create New Session
                        </a>
                    </div>
                </form>
            <?php } ?>
        </div>
    </div>
<?php } else { ?>
    <!-- Attendance Marking Form -->
    <div class="card mb-3">
        <div class="card-header bg-primary text-white">
            <div class="row align-items-center">
                <div class="col">
                    <h5 class="mb-0">
                        <i class="fas fa-book me-2"></i><?php echo htmlspecialchars($session_data['CourseCode'] . ' - ' . $session_data['CourseName']); ?>
                    </h5>
                </div>
                <div class="col-auto">
                    <a href="mark_attendance.php?username=<?php echo urlencode($username); ?>" class="btn btn-light btn-sm">
                        <i class="fas fa-arrow-left me-1"></i>Back to Sessions
                    </a>
                </div>
            </div>
        </div>
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-md-3">
                    <strong><i class="fas fa-calendar me-2"></i>Date:</strong>
                    <?php echo $session_data['SessionDate']->format('M d, Y'); ?>
                </div>
                <div class="col-md-3">
                    <strong><i class="fas fa-clock me-2"></i>Time:</strong>
                    <?php echo $session_data['SessionStartTime']->format('h:i A') . ' - ' . $session_data['SessionEndTime']->format('h:i A'); ?>
                </div>
                <div class="col-md-3">
                    <strong><i class="fas fa-chalkboard me-2"></i>Type:</strong>
                    <?php echo htmlspecialchars($session_data['SessionType']); ?>
                </div>
                <div class="col-md-3">
                    <strong><i class="fas fa-map-marker-alt me-2"></i>Location:</strong>
                    <?php echo htmlspecialchars($session_data['Location']); ?>
                </div>
            </div>
        </div>
    </div>
    
    <?php if (empty($enrolled_students)) { ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>No students enrolled in this course.
        </div>
    <?php } else { ?>
        <form method="POST" action="mark_attendance.php?username=<?php echo urlencode($username); ?>&session_id=<?php echo $session_id; ?>">
            <input type="hidden" name="session_id" value="<?php echo $session_id; ?>">
            
            <div class="card">
                <div class="card-header bg-white">
                    <div class="row align-items-center">
                        <div class="col">
                            <h5 class="mb-0"><i class="fas fa-users me-2"></i>Student Attendance (<?php echo count($enrolled_students); ?> students)</h5>
                        </div>
                        <div class="col-auto">
                            <button type="button" class="btn btn-sm btn-outline-success" onclick="markAll('Present')">
                                <i class="fas fa-check-double me-1"></i>Mark All Present
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="markAll('Absent')">
                                <i class="fas fa-times-circle me-1"></i>Mark All Absent
                            </button>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 10%;">Student No.</th>
                                    <th style="width: 25%;">Name</th>
                                    <th style="width: 20%;">Email</th>
                                    <th style="width: 15%;">Status</th>
                                    <th style="width: 30%;">Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($enrolled_students as $student) { 
                                    $existing_status = isset($attendance_records[$student['StudentID']]) ? 
                                                      $attendance_records[$student['StudentID']]['Status'] : 'Present';
                                    $existing_remarks = isset($attendance_records[$student['StudentID']]) ? 
                                                       $attendance_records[$student['StudentID']]['Remarks'] : '';
                                ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($student['StudentNumber']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($student['FirstName'] . ' ' . $student['LastName']); ?></td>
                                        <td><small class="text-muted"><?php echo htmlspecialchars($student['Email']); ?></small></td>
                                        <td>
                                            <div class="btn-group btn-group-sm status-group" role="group">
                                                <input type="radio" class="btn-check" name="attendance[<?php echo $student['StudentID']; ?>]" 
                                                       id="present_<?php echo $student['StudentID']; ?>" value="Present" 
                                                       <?php echo $existing_status == 'Present' ? 'checked' : ''; ?>>
                                                <label class="btn btn-outline-success" for="present_<?php echo $student['StudentID']; ?>">
                                                    <i class="fas fa-check me-1"></i>Present
                                                </label>
                                                
                                                <input type="radio" class="btn-check" name="attendance[<?php echo $student['StudentID']; ?>]" 
                                                       id="late_<?php echo $student['StudentID']; ?>" value="Late"
                                                       <?php echo $existing_status == 'Late' ? 'checked' : ''; ?>>
                                                <label class="btn btn-outline-warning" for="late_<?php echo $student['StudentID']; ?>">
                                                    <i class="fas fa-clock me-1"></i>Late
                                                </label>
                                                
                                                <input type="radio" class="btn-check" name="attendance[<?php echo $student['StudentID']; ?>]" 
                                                       id="absent_<?php echo $student['StudentID']; ?>" value="Absent"
                                                       <?php echo $existing_status == 'Absent' ? 'checked' : ''; ?>>
                                                <label class="btn btn-outline-danger" for="absent_<?php echo $student['StudentID']; ?>">
                                                    <i class="fas fa-times me-1"></i>Absent
                                                </label>
                                                
                                                <input type="radio" class="btn-check" name="attendance[<?php echo $student['StudentID']; ?>]" 
                                                       id="excused_<?php echo $student['StudentID']; ?>" value="Excused"
                                                       <?php echo $existing_status == 'Excused' ? 'checked' : ''; ?>>
                                                <label class="btn btn-outline-info" for="excused_<?php echo $student['StudentID']; ?>">
                                                    <i class="fas fa-user-check me-1"></i>Excused
                                                </label>
                                            </div>
                                        </td>
                                        <td>
                                            <input type="text" class="form-control form-control-sm" 
                                                   name="remarks[<?php echo $student['StudentID']; ?>]" 
                                                   placeholder="Optional remarks..."
                                                   value="<?php echo htmlspecialchars($existing_remarks); ?>">
                                        </td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <small class="text-muted">
                                <i class="fas fa-info-circle me-1"></i>
                                <?php echo count(array_filter($attendance_records)) > 0 ? 
                                    'Updating existing attendance records' : 
                                    'Creating new attendance records'; ?>
                            </small>
                        </div>
                        <div>
                            <button type="submit" name="mark_attendance" class="btn btn-primary btn-lg">
                                <i class="fas fa-save me-2"></i>Save Attendance
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    <?php } ?>
<?php } ?>

<script>
// Dynamic session loading based on course selection
document.getElementById('courseSelect')?.addEventListener('change', function() {
    const courseId = this.value;
    const sessionSelect = document.getElementById('sessionSelect');
    const username = '<?php echo addslashes($username); ?>';
    
    if (!courseId) {
        sessionSelect.innerHTML = '<option value="">-- Select Course First --</option>';
        sessionSelect.disabled = true;
        return;
    }
    
    sessionSelect.disabled = true;
    sessionSelect.innerHTML = '<option value="">Loading sessions...</option>';
    
    // Fetch sessions for selected course
    fetch(`get_sessions.php?course_id=${courseId}&username=${username}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.sessions.length > 0) {
                sessionSelect.innerHTML = '<option value="">-- Select Session --</option>';
                data.sessions.forEach(session => {
                    const option = document.createElement('option');
                    option.value = session.SessionID;
                    option.textContent = `${session.SessionDate} | ${session.SessionStartTime} - ${session.SessionEndTime} | ${session.SessionType} | ${session.Location}`;
                    sessionSelect.appendChild(option);
                });
                sessionSelect.disabled = false;
            } else {
                sessionSelect.innerHTML = '<option value="">No sessions available</option>';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            sessionSelect.innerHTML = '<option value="">Error loading sessions</option>';
        });
});

// Mark all students with specific status
function markAll(status) {
    const radios = document.querySelectorAll(`input[type="radio"][value="${status}"]`);
    radios.forEach(radio => {
        radio.checked = true;
    });
}

// Confirmation before submitting
document.querySelector('form[name="mark_attendance"]')?.addEventListener('submit', function(e) {
    if (!confirm('Are you sure you want to save this attendance? This action will update all attendance records for this session.')) {
        e.preventDefault();
    }
});
</script>

<style>
.status-group .btn-check:checked + .btn {
    font-weight: bold;
}
.table td {
    vertical-align: middle;
}
</style>

<?php
// Render footer
renderFooter();

// Clean up
sqlsrv_free_stmt($lecturer_result);
sqlsrv_close($conn);
?>