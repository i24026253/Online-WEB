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

// Handle create session
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_session'])) {
    $course_id = (int)$_POST['course_id'];
    $session_date = $_POST['session_date'];
    $session_start_time = $_POST['session_start_time'];
    $session_end_time = $_POST['session_end_time'];
    $session_type = $_POST['session_type'];
    $location = $_POST['location'];
    $qr_code_expiry = !empty($_POST['qr_expiry_minutes']) ? 
                      date('Y-m-d H:i:s', strtotime("+{$_POST['qr_expiry_minutes']} minutes")) : null;
    $can_edit = isset($_POST['can_edit']) ? 1 : 0;
    $edit_deadline = $can_edit && !empty($_POST['edit_deadline_hours']) ? 
                     date('Y-m-d H:i:s', strtotime("+{$_POST['edit_deadline_hours']} hours")) : null;
    $created_date = date('Y-m-d H:i:s');
    
    $insert_query = "INSERT INTO dbo.Attendance_Sessions 
                    (CourseID, LecturerID, SessionDate, SessionStartTime, SessionEndTime, 
                     SessionType, Location, QRCodeExpiry, IsActive, CanEdit, EditDeadline, CreatedDate) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)";
    $insert_params = array($course_id, $lecturer_id, $session_date, $session_start_time, 
                          $session_end_time, $session_type, $location, $qr_code_expiry,
                          $can_edit, $edit_deadline, $created_date);
    $insert_result = sqlsrv_query($conn, $insert_query, $insert_params);
    
    if ($insert_result) {
        $message = "<div class='alert alert-success alert-dismissible fade show' role='alert'>
                    <i class='fas fa-check-circle me-2'></i>Session created successfully.
                    <button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
    } else {
        error_log("Create session error: " . print_r(sqlsrv_errors(), true));
        $message = "<div class='alert alert-danger alert-dismissible fade show' role='alert'>
                    <i class='fas fa-times-circle me-2'></i>Failed to create session: " . print_r(sqlsrv_errors(), true) . "
                    <button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
    }
}

// Handle delete session
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_session'])) {
    $session_id = (int)$_POST['session_id'];
    
    // Check if attendance has been marked
    $check_query = "SELECT COUNT(*) as count FROM dbo.Attendance_Records WHERE SessionID = ?";
    $check_params = array($session_id);
    $check_result = sqlsrv_query($conn, $check_query, $check_params);
    
    if ($check_result !== false) {
        $check_row = sqlsrv_fetch_array($check_result, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($check_result);
        
        if ($check_row['count'] > 0) {
            $message = "<div class='alert alert-warning alert-dismissible fade show' role='alert'>
                        <i class='fas fa-exclamation-triangle me-2'></i>Cannot delete session: Attendance has already been marked.
                        <button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        } else {
            $delete_query = "DELETE FROM dbo.Attendance_Sessions WHERE SessionID = ? AND LecturerID = ?";
            $delete_params = array($session_id, $lecturer_id);
            $delete_result = sqlsrv_query($conn, $delete_query, $delete_params);
            
            if ($delete_result) {
                $message = "<div class='alert alert-success alert-dismissible fade show' role='alert'>
                            <i class='fas fa-check-circle me-2'></i>Session deleted successfully.
                            <button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
            } else {
                error_log("Delete session error: " . print_r(sqlsrv_errors(), true));
                $message = "<div class='alert alert-danger alert-dismissible fade show' role='alert'>
                            <i class='fas fa-times-circle me-2'></i>Failed to delete session.
                            <button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
            }
        }
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

// Fetch all sessions
$sessions_query = "SELECT s.SessionID, s.CourseID, s.SessionDate, s.SessionStartTime, 
                         s.SessionEndTime, s.SessionType, s.Location, s.QRCodeExpiry,
                         s.CanEdit, s.EditDeadline, c.CourseCode, c.CourseName,
                         (SELECT COUNT(*) FROM dbo.Attendance_Records WHERE SessionID = s.SessionID) as AttendanceCount
                  FROM dbo.Attendance_Sessions s
                  JOIN dbo.Courses c ON s.CourseID = c.CourseID
                  WHERE s.LecturerID = ? AND s.IsActive = 1
                  ORDER BY s.SessionDate DESC, s.SessionStartTime DESC";
$sessions_params = array($lecturer_id);
$sessions_result = sqlsrv_query($conn, $sessions_query, $sessions_params);

if ($sessions_result === false) {
    error_log("Sessions query error: " . print_r(sqlsrv_errors(), true));
    die("<p style='color:red;'>❌ Database error: " . print_r(sqlsrv_errors(), true) . "</p>");
}

// Render header with navigation
renderHeader($username, $user_role, 'attendance');
?>

<!-- Page Content Starts Here -->
<div class="mb-4">
    <h1 class="h2"><i class="fas fa-calendar-alt me-2"></i>Attendance Sessions</h1>
</div>

<!-- Display messages -->
<?php if (isset($message)) echo $message; ?>

<!-- Create Session Button -->
<button type="button" class="btn btn-success mb-3" data-bs-toggle="modal" data-bs-target="#createSessionModal">
    <i class="fas fa-plus-circle me-2"></i>Create New Session
</button>

<!-- Sessions Table -->
<div class="card">
    <div class="card-header bg-white">
        <h5 class="mb-0"><i class="fas fa-list me-2"></i>All Sessions</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead class="table-light">
                    <tr>
                        <th>Course</th>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Type</th>
                        <th>Location</th>
                        <th>Attendance Marked</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $has_sessions = false;
                    while ($session = sqlsrv_fetch_array($sessions_result, SQLSRV_FETCH_ASSOC)) { 
                        $has_sessions = true;
                        $is_past = $session['SessionDate'] < new DateTime();
                    ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($session['CourseCode']); ?></strong><br>
                                <small class="text-muted"><?php echo htmlspecialchars($session['CourseName']); ?></small>
                            </td>
                            <td><?php echo $session['SessionDate']->format('M d, Y'); ?></td>
                            <td>
                                <?php echo $session['SessionStartTime']->format('h:i A'); ?> -
                                <?php echo $session['SessionEndTime']->format('h:i A'); ?>
                            </td>
                            <td>
                                <span class="badge bg-info">
                                    <?php echo htmlspecialchars($session['SessionType']); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($session['Location']); ?></td>
                            <td class="text-center">
                                <?php if ($session['AttendanceCount'] > 0) { ?>
                                    <span class="badge bg-success">
                                        <i class="fas fa-check me-1"></i><?php echo $session['AttendanceCount']; ?> marked
                                    </span>
                                <?php } else { ?>
                                    <span class="badge bg-secondary">Not marked</span>
                                <?php } ?>
                            </td>
                            <td>
                                <div class="btn-group" role="group">
                                    <a href="mark_attendance.php?username=<?php echo urlencode($username); ?>&session_id=<?php echo $session['SessionID']; ?>" 
                                       class="btn btn-primary btn-sm">
                                        <i class="fas fa-clipboard-check me-1"></i>Mark
                                    </a>
                                    <?php if ($session['AttendanceCount'] == 0) { ?>
                                        <form method="POST" action="attendance_sessions.php?username=<?php echo urlencode($username); ?>" style="display:inline;">
                                            <input type="hidden" name="session_id" value="<?php echo $session['SessionID']; ?>">
                                            <button type="submit" name="delete_session" class="btn btn-danger btn-sm" 
                                                    onclick="return confirm('Are you sure you want to delete this session?');">
                                                <i class="fas fa-trash me-1"></i>Delete
                                            </button>
                                        </form>
                                    <?php } ?>
                                </div>
                            </td>
                        </tr>
                    <?php } 
                    if (!$has_sessions) { ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted">
                                No sessions created yet. Click "Create New Session" to get started.
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Create Session Modal -->
<div class="modal fade" id="createSessionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Create Attendance Session</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="attendance_sessions.php?username=<?php echo urlencode($username); ?>">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-12 mb-3">
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
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Session Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="session_date" required>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Start Time <span class="text-danger">*</span></label>
                            <input type="time" class="form-control" name="session_start_time" required>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <label class="form-label">End Time <span class="text-danger">*</span></label>
                            <input type="time" class="form-control" name="session_end_time" required>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Session Type <span class="text-danger">*</span></label>
                            <select class="form-control" name="session_type" required>
                                <option value="">-- Select Type --</option>
                                <option value="Lecture">Lecture</option>
                                <option value="Lab">Lab</option>
                                <option value="Tutorial">Tutorial</option>
                                <option value="Practical">Practical</option>
                                <option value="Workshop">Workshop</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Location <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="location" placeholder="e.g., Room 101" required>
                        </div>
                        
                        <div class="col-md-12 mb-3">
                            <label class="form-label">QR Code Expiry (minutes)</label>
                            <input type="number" class="form-control" name="qr_expiry_minutes" 
                                   placeholder="Leave empty for no expiry" min="1">
                            <small class="text-muted">How long the QR code should be valid for student scanning</small>
                        </div>
                        
                        <div class="col-md-12 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_edit" 
                                       id="canEditCheck" onchange="toggleEditDeadline()">
                                <label class="form-check-label" for="canEditCheck">
                                    Allow attendance editing
                                </label>
                            </div>
                        </div>
                        
                        <div class="col-md-12 mb-3" id="editDeadlineDiv" style="display:none;">
                            <label class="form-label">Edit Deadline (hours after session)</label>
                            <input type="number" class="form-control" name="edit_deadline_hours" 
                                   placeholder="e.g., 24" min="1">
                            <small class="text-muted">How long after the session attendance can be edited</small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="create_session" class="btn btn-success">
                        <i class="fas fa-plus-circle me-2"></i>Create Session
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleEditDeadline() {
    const canEdit = document.getElementById('canEditCheck').checked;
    const editDiv = document.getElementById('editDeadlineDiv');
    editDiv.style.display = canEdit ? 'block' : 'none';
}

// Set minimum date to today
document.querySelector('input[name="session_date"]').min = new Date().toISOString().split('T')[0];
</script>

<?php
// Render footer
renderFooter();

// Clean up
sqlsrv_free_stmt($lecturer_result);
sqlsrv_free_stmt($sessions_result);
sqlsrv_close($conn);
?>