<?php
require_once 'header.php';
require_once 'connect.php';

$username = $_GET['username'] ?? null;
if (!$username) {
    die("<p style='color:red;'>Please log in as a lecturer.</p>");
}

/* ---------- Verify Lecturer ---------- */
$lecturer_q = "SELECT u.UserID, u.Role, l.LecturerID 
               FROM dbo.Users u JOIN dbo.Lecturers l ON u.UserID = l.UserID 
               WHERE u.Username = ?";
$lecturer_res = sqlsrv_query($conn, $lecturer_q, [$username]);
if ($lecturer_res === false || !($row = sqlsrv_fetch_array($lecturer_res, SQLSRV_FETCH_ASSOC)) || $row['Role'] !== 'Lecturer') {
    die("<p style='color:red;'>Access denied.</p>");
}
$lecturer_id = $row['LecturerID'];
$user_role = strtolower($row['Role']);
if ($lecturer_res !== false) sqlsrv_free_stmt($lecturer_res);

/* ---------- Handle Form Submission ---------- */
$show_success_modal = false;
$show_delete_modal = false;
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_session'])) {
    $course_id = (int)$_POST['course_id'];
    $session_name = trim($_POST['session_name']);
    $session_type = trim($_POST['session_type']);
    $location = trim($_POST['location']);
    $start_time = trim($_POST['start_time']);
    $end_time = trim($_POST['end_time']);
    
    // Validate inputs
    if ($course_id && $session_name && $session_type && $location && $start_time && $end_time) {
        $insert_q = "INSERT INTO dbo.Attendance_Sessions 
                     (CourseID, LecturerID, Session, SessionType, Location, SessionStartTime, SessionEndTime, IsActive)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 1)";
        
        $params = [$course_id, $lecturer_id, $session_name, $session_type, $location, $start_time, $end_time];
        $result = sqlsrv_query($conn, $insert_q, $params);
        
        if ($result) {
            // Redirect with success message
            header("Location: create_session.php?username=" . urlencode($username) . "&success=1");
            exit;
        } else {
            $error_message = "Failed to create session. Please try again.";
        }
    } else {
        $error_message = "Please fill in all required fields.";
    }
}

// Handle Delete Session
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_session'])) {
    $session_id = (int)$_POST['session_id'];
    
    // Verify session belongs to this lecturer
    $verify_q = "SELECT SessionID FROM dbo.Attendance_Sessions WHERE SessionID = ? AND LecturerID = ?";
    $verify_res = sqlsrv_query($conn, $verify_q, [$session_id, $lecturer_id]);
    
    if ($verify_res && sqlsrv_fetch_array($verify_res, SQLSRV_FETCH_ASSOC)) {
        // Delete session
        $delete_q = "DELETE FROM dbo.Attendance_Sessions WHERE SessionID = ?";
        $result = sqlsrv_query($conn, $delete_q, [$session_id]);
        
        if ($result) {
            header("Location: create_session.php?username=" . urlencode($username) . "&deleted=1");
            exit;
        } else {
            $error_message = "Failed to delete session. Please try again.";
        }
    } else {
        $error_message = "Session not found or access denied.";
    }
    
    if ($verify_res !== false) sqlsrv_free_stmt($verify_res);
}

// Check for success message from redirect
if (isset($_GET['success'])) {
    $show_success_modal = true;
    $success_message = "Session created successfully!";
}

if (isset($_GET['deleted'])) {
    $show_delete_modal = true;
    $success_message = "Session deleted successfully!";
}

/* ---------- Get Assigned Courses with Session Count ---------- */
$courses = [];
$q = "SELECT c.CourseID, c.CourseCode, c.CourseName, ca.AssignedDate,
             (SELECT COUNT(*) FROM dbo.Attendance_Sessions s 
              WHERE s.CourseID = c.CourseID AND s.LecturerID = ?) as SessionCount
      FROM dbo.Courses c
      JOIN dbo.Course_Assignments ca ON c.CourseID = ca.CourseID
      WHERE ca.LecturerID = ? AND ca.IsActive = 1 AND c.IsActive = 1
      ORDER BY c.CourseCode";
$res = sqlsrv_query($conn, $q, [$lecturer_id, $lecturer_id]);
while ($row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) {
    $courses[] = $row;
}
if ($res !== false) sqlsrv_free_stmt($res);

/* ---------- Get All Sessions Grouped by Course ---------- */
$sessions_by_course = [];
$q = "SELECT s.SessionID, s.Session, s.SessionType, s.Location, 
             s.SessionStartTime, s.SessionEndTime, s.IsActive, s.CourseID,
             c.CourseCode, c.CourseName
      FROM dbo.Attendance_Sessions s
      JOIN dbo.Courses c ON s.CourseID = c.CourseID
      WHERE s.LecturerID = ?
      ORDER BY c.CourseCode, s.Session";
$res = sqlsrv_query($conn, $q, [$lecturer_id]);
while ($row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) {
    $sessions_by_course[$row['CourseID']][] = $row;
}
if ($res !== false) sqlsrv_free_stmt($res);

// Count courses without sessions
$courses_without_sessions = 0;
foreach ($courses as $course) {
    if ($course['SessionCount'] == 0) {
        $courses_without_sessions++;
    }
}

renderHeader($username, $user_role, 'sessions');
?>

<div class="mb-4">
    <h1 class="h2"><i class="fas fa-calendar-plus me-2"></i>Manage Sessions</h1>
</div>

<!-- Alert for courses without sessions -->
<?php if ($courses_without_sessions > 0): ?>
    <div class="alert alert-warning alert-dismissible fade show">
        <i class="fas fa-exclamation-triangle me-2"></i>
        <strong>Action Required:</strong> You have <strong><?php echo $courses_without_sessions; ?></strong> 
        course(s) without any sessions. Please create sessions for them below.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- My Assigned Courses -->
<div class="card mb-4">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="fas fa-book me-2"></i>My Assigned Courses (<?php echo count($courses); ?>)</h5>
    </div>
    <div class="card-body">
        <?php if (empty($courses)): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>No courses assigned yet.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Course Code</th>
                            <th>Course Name</th>
                            <th>Assigned Date</th>
                            <th>Sessions</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; foreach ($courses as $course): ?>
                            <tr class="<?php echo $course['SessionCount'] == 0 ? 'table-warning' : ''; ?>">
                                <td><?php echo $i++; ?></td>
                                <td><strong><?php echo htmlspecialchars($course['CourseCode']); ?></strong></td>
                                <td><?php echo htmlspecialchars($course['CourseName']); ?></td>
                                <td>
                                    <small><?php echo $course['AssignedDate']->format('M d, Y'); ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $course['SessionCount'] > 0 ? 'success' : 'danger'; ?>">
                                        <?php echo $course['SessionCount']; ?> Session(s)
                                    </span>
                                </td>
                                <td>
                                    <?php if ($course['SessionCount'] == 0): ?>
                                        <span class="badge bg-warning text-dark">
                                            <i class="fas fa-exclamation-triangle me-1"></i>No Sessions
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-success">
                                            <i class="fas fa-check-circle me-1"></i>Ready
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-primary" 
                                            onclick="openCreateModal(<?php echo $course['CourseID']; ?>, '<?php echo htmlspecialchars($course['CourseCode']); ?>', '<?php echo htmlspecialchars($course['CourseName']); ?>')">
                                        <i class="fas fa-plus me-1"></i>Create Session
                                    </button>
                                    <?php if ($course['SessionCount'] > 0): ?>
                                        <button type="button" class="btn btn-sm btn-info" 
                                                onclick="toggleSessions(<?php echo $course['CourseID']; ?>)">
                                            <i class="fas fa-eye me-1"></i>View
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <!-- Sessions List (Hidden by default) -->
                            <?php if ($course['SessionCount'] > 0 && isset($sessions_by_course[$course['CourseID']])): ?>
                                <tr id="sessions-<?php echo $course['CourseID']; ?>" style="display:none;">
                                    <td colspan="7" class="bg-light">
                                        <div class="p-3">
                                            <h6 class="mb-3">Sessions for <?php echo htmlspecialchars($course['CourseCode']); ?>:</h6>
                                            <div class="row g-2">
                                                <?php foreach ($sessions_by_course[$course['CourseID']] as $session): ?>
                                                    <div class="col-md-6">
                                                        <div class="card border">
                                                            <div class="card-body p-2">
                                                                <div class="d-flex justify-content-between align-items-start">
                                                                    <div>
                                                                        <h6 class="mb-1"><?php echo htmlspecialchars($session['Session']); ?></h6>
                                                                        <small class="text-muted">
                                                                            <i class="fas fa-bookmark me-1"></i><?php echo htmlspecialchars($session['SessionType']); ?> |
                                                                            <i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($session['Location']); ?>
                                                                        </small>
                                                                        <br>
                                                                        <small class="text-muted">
                                                                            <i class="fas fa-clock me-1"></i>
                                                                            <?php echo $session['SessionStartTime']->format('h:i A'); ?> - 
                                                                            <?php echo $session['SessionEndTime']->format('h:i A'); ?>
                                                                        </small>
                                                                    </div>
                                                                    <div class="d-flex flex-column gap-1">
                                                                        <span class="badge bg-<?php echo $session['IsActive'] ? 'success' : 'secondary'; ?>">
                                                                            <?php echo $session['IsActive'] ? 'Active' : 'Inactive'; ?>
                                                                        </span>
                                                                        <button type="button" class="btn btn-sm btn-danger" 
                                                                                onclick="confirmDeleteSession(<?php echo $session['SessionID']; ?>, '<?php echo htmlspecialchars($session['Session']); ?>')">
                                                                            <i class="fas fa-trash"></i>
                                                                        </button>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Create Session Modal -->
<div class="modal fade" id="createSessionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fas fa-plus-circle me-2"></i>Create New Session
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="create_session.php?username=<?php echo urlencode($username); ?>" id="sessionForm">
                <div class="modal-body">
                    <?php if ($error_message): ?>
                        <div class="alert alert-danger alert-dismissible fade show">
                            <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error_message); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <input type="hidden" name="course_id" id="modal_course_id">
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Course</label>
                        <input type="text" class="form-control" id="modal_course_display" readonly>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Session Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="session_name" 
                                   placeholder="e.g., 1H1, 2G1, Lab 1" required>
                            <small class="text-muted">Example: 1H1, 2G1, Lab 1</small>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Session Type <span class="text-danger">*</span></label>
                            <select class="form-control" name="session_type" required>
                                <option value="">-- Select Type --</option>
                                <option value="Lecture">Lecture</option>
                                <option value="Lab">Lab</option>
                                <option value="Tutorial">Tutorial</option>
                                <option value="Workshop">Workshop</option>
                            </select>
                        </div>

                        <div class="col-md-12">
                            <label class="form-label fw-bold">Location <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="location" 
                                   placeholder="e.g., Room 101, Lab 3" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Start Time <span class="text-danger">*</span></label>
                            <input type="time" class="form-control" name="start_time" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">End Time <span class="text-danger">*</span></label>
                            <input type="time" class="form-control" name="end_time" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                    <button type="button" class="btn btn-primary" onclick="showConfirmModal()">
                        <i class="fas fa-save me-2"></i>Create Session
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Confirmation Modal -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fas fa-exclamation-circle me-2"></i>Confirm Create Session
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center py-4">
                <i class="fas fa-calendar-plus fa-3x text-primary mb-3"></i>
                <h5>Are you sure you want to create this session?</h5>
                <p class="text-muted mb-0">Please verify all details before creating.</p>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-2"></i>Cancel
                </button>
                <button type="button" class="btn btn-primary" onclick="submitForm()">
                    <i class="fas fa-check me-2"></i>Yes, Create
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Success Modal -->
<div class="modal fade" id="successModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body text-center py-5">
                <div class="mb-3">
                    <i class="fas fa-check-circle fa-4x text-success"></i>
                </div>
                <h4 class="mb-3">Session Created Successfully!</h4>
                <p class="text-muted mb-0">
                    <i class="fas fa-calendar-check me-2"></i>
                    You can now enroll students and mark attendance for this session.
                </p>
                <button type="button" class="btn btn-primary mt-4" onclick="closeSuccessModal()">
                    <i class="fas fa-times me-2"></i>Close
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Success Modal -->
<div class="modal fade" id="deleteSuccessModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body text-center py-5">
                <div class="mb-3">
                    <i class="fas fa-check-circle fa-4x text-success"></i>
                </div>
                <h4 class="mb-3">Session Deleted Successfully!</h4>
                <p class="text-muted mb-0">
                    <i class="fas fa-trash me-2"></i>
                    The session has been removed from the system.
                </p>
                <button type="button" class="btn btn-primary mt-4" onclick="closeDeleteModal()">
                    <i class="fas fa-times me-2"></i>Close
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="fas fa-exclamation-triangle me-2"></i>Confirm Delete Session
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center py-4">
                <i class="fas fa-trash-alt fa-3x text-danger mb-3"></i>
                <h5>Are you sure you want to delete this session?</h5>
                <p class="text-muted mb-0">Session: <strong id="delete_session_name"></strong></p>
                <p class="text-danger mt-2"><small><i class="fas fa-exclamation-circle me-1"></i>This action cannot be undone!</small></p>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-2"></i>Cancel
                </button>
                <button type="button" class="btn btn-danger" onclick="submitDeleteForm()">
                    <i class="fas fa-trash me-2"></i>Yes, Delete
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Hidden Delete Form -->
<form method="POST" action="create_session.php?username=<?php echo urlencode($username); ?>" id="deleteForm" style="display:none;">
    <input type="hidden" name="session_id" id="delete_session_id">
    <input type="hidden" name="delete_session" value="1">
</form>

<script>
function openCreateModal(courseId, courseCode, courseName) {
    document.getElementById('modal_course_id').value = courseId;
    document.getElementById('modal_course_display').value = courseCode + ' - ' + courseName;
    
    // Reset form
    document.getElementById('sessionForm').reset();
    document.getElementById('modal_course_id').value = courseId;
    document.getElementById('modal_course_display').value = courseCode + ' - ' + courseName;
    
    const modal = new bootstrap.Modal(document.getElementById('createSessionModal'));
    modal.show();
}

function toggleSessions(courseId) {
    const row = document.getElementById('sessions-' + courseId);
    if (row.style.display === 'none') {
        row.style.display = 'table-row';
    } else {
        row.style.display = 'none';
    }
}

function showConfirmModal() {
    // Validate form first
    const form = document.getElementById('sessionForm');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    
    // Hide create modal
    const createModal = bootstrap.Modal.getInstance(document.getElementById('createSessionModal'));
    createModal.hide();
    
    // Show confirm modal
    const confirmModal = new bootstrap.Modal(document.getElementById('confirmModal'));
    confirmModal.show();
}

function submitForm() {
    const modal = bootstrap.Modal.getInstance(document.getElementById('confirmModal'));
    modal.hide();
    
    const form = document.getElementById('sessionForm');
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'create_session';
    input.value = '1';
    form.appendChild(input);
    
    form.submit();
}

function closeSuccessModal() {
    const modal = bootstrap.Modal.getInstance(document.getElementById('successModal'));
    if (modal) {
        modal.hide();
    }
    const url = new URL(window.location);
    url.searchParams.delete('success');
    window.location.href = url.toString();
}

function closeDeleteModal() {
    const modal = bootstrap.Modal.getInstance(document.getElementById('deleteSuccessModal'));
    if (modal) {
        modal.hide();
    }
    const url = new URL(window.location);
    url.searchParams.delete('deleted');
    window.location.href = url.toString();
}

function confirmDeleteSession(sessionId, sessionName) {
    document.getElementById('delete_session_id').value = sessionId;
    document.getElementById('delete_session_name').textContent = sessionName;
    
    const modal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
    modal.show();
}

function submitDeleteForm() {
    const modal = bootstrap.Modal.getInstance(document.getElementById('deleteConfirmModal'));
    modal.hide();
    
    document.getElementById('deleteForm').submit();
}

<?php if ($show_success_modal): ?>
    window.addEventListener('DOMContentLoaded', function() {
        const modal = new bootstrap.Modal(document.getElementById('successModal'));
        modal.show();
    });
<?php endif; ?>

<?php if ($show_delete_modal): ?>
    window.addEventListener('DOMContentLoaded', function() {
        const modal = new bootstrap.Modal(document.getElementById('deleteSuccessModal'));
        modal.show();
    });
<?php endif; ?>
</script>

<?php
renderFooter();
sqlsrv_close($conn);
?>