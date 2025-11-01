<?php
require_once 'header.php';
require_once 'connect.php';

$username = $_GET['username'] ?? null;
if (!$username) {
    die("<p style='color:red;'>Please log in as a lecturer.</p>");
}

/* ---------- Helper Functions ---------- */
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
function getLastInsertId($conn) {
    $q = "SELECT SCOPE_IDENTITY() AS id";
    $res = sqlsrv_query($conn, $q);
    $row = $res ? sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC) : null;
    if ($res) sqlsrv_free_stmt($res);
    return $row ? (int)$row['id'] : 0;
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

/* ---------- Current Date ---------- */
$today = date('Y-m-d');

/* ---------- Get MarkID & SessionID ---------- */
$mark_id    = (int)($_GET['mark_id'] ?? 0);
$session_id = (int)($_GET['session_id'] ?? 0);
$selected_date = null;

if ($mark_id > 0) {
    $q = "SELECT SessionID, [Date] FROM dbo.Attendance_Mark WHERE MarkID = ?";
    $res = sqlsrv_query($conn, $q, [$mark_id]);
    if ($res && ($row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC))) {
        $session_id = $row['SessionID'];
        $selected_date = $row['Date']->format('Y-m-d');
    }
    if ($res !== false) sqlsrv_free_stmt($res);
}

/* ---------- MARK ATTENDANCE POST ---------- */
$msg = '';
$show_success_modal = false;
$success_count = 0;
$error_count = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_attendance'])) {
    $session_id  = (int)$_POST['session_id'];
    $mark_id     = (int)$_POST['mark_id']; // may be 0
    $marked_by   = $lecturer_id;
    $marked_time = date('Y-m-d H:i:s');
    $today       = date('Y-m-d');

    $success = $error = 0;

    // === 1. CREATE OR UPDATE Attendance_Mark ===
    if ($mark_id == 0) {
        // First time → INSERT + get MarkID
        $ins = "INSERT INTO dbo.Attendance_Mark (SessionID, [Date], MarkedTime) 
                OUTPUT INSERTED.MarkID 
                VALUES (?, ?, ?)";
        $res = sqlsrv_query($conn, $ins, [$session_id, $today, $marked_time]);
        if ($res && ($row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC))) {
            $mark_id = (int)$row['MarkID'];
        } else {
            $error_count = 999; // Indicate critical error
            goto end_save;
        }
    } else {
        // Already exists → UPDATE MarkedTime
        $upd = "UPDATE dbo.Attendance_Mark SET EditedTime = ?, EditedBy = ? WHERE MarkID = ?";
        sqlsrv_query($conn, $upd, [$marked_time, $marked_by, $mark_id]);
    }

    // === 2. SAVE STUDENT RECORDS ===
    foreach ($_POST['attendance'] as $student_id => $status) {
        $remarks = trim($_POST['remarks'][$student_id] ?? '');
        $remarks = $remarks === '' ? null : $remarks;

        $chk = sqlsrv_query($conn, "SELECT AttendanceID FROM dbo.Attendance_Records WHERE MarkID = ? AND StudentID = ?", [$mark_id, $student_id]);
        $exists = $chk ? sqlsrv_fetch_array($chk, SQLSRV_FETCH_ASSOC) : false;
        if ($chk !== false) sqlsrv_free_stmt($chk);

        if ($exists) {
            $sql = "UPDATE dbo.Attendance_Records 
                    SET Status = ?, Remarks = ?
                    WHERE AttendanceID = ?";
            $p = [$status, $remarks, $exists['AttendanceID']];
        } else {
            $sql = "INSERT INTO dbo.Attendance_Records (MarkID, StudentID, Status, Remarks)
                    VALUES (?,?,?,?)";
            $p = [$mark_id, $student_id, $status, $remarks];
        }

        $res = sqlsrv_query($conn, $sql, $p);
        $res ? $success++ : $error++;
    }

    $success_count = $success;
    $error_count = $error;

    // Redirect with success message to prevent resubmission
    $redirect_url = "mark_attendance.php?username=" . urlencode($username) . 
                    "&session_id=" . $session_id . 
                    "&mark_id=" . $mark_id . 
                    "&success=" . $success . 
                    "&errors=" . $error;
    header("Location: " . $redirect_url);
    exit;

    end_save:
}

// Check for success message from redirect
if (isset($_GET['success'])) {
    $show_success_modal = true;
    $success_count = (int)$_GET['success'];
    $error_count = (int)($_GET['errors'] ?? 0);
} else {
    $show_success_modal = false;
    $success_count = 0;
    $error_count = 0;
}

/* ---------- COURSES ---------- */
$courses = [];
$q = "SELECT DISTINCT c.CourseID, c.CourseCode, c.CourseName 
      FROM dbo.Courses c
      JOIN dbo.Course_Assignments ca ON c.CourseID = ca.CourseID
      WHERE ca.LecturerID = ? AND ca.IsActive = 1 AND c.IsActive = 1
      ORDER BY c.CourseCode";
$res = sqlsrv_query($conn, $q, [$lecturer_id]);
while ($row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) $courses[] = $row;
if ($res !== false) sqlsrv_free_stmt($res);

/* ---------- SESSION & MARK DATA ---------- */
$session_data = $enrolled_students = $attendance_records = $mark_dates = $all_sessions = null;

if ($session_id > 0) {
    // Session info
    $q = "SELECT s.*, c.CourseCode, c.CourseName
          FROM dbo.Attendance_Sessions s
          JOIN dbo.Courses c ON s.CourseID = c.CourseID
          WHERE s.SessionID = ? AND s.LecturerID = ?";
    $res = sqlsrv_query($conn, $q, [$session_id, $lecturer_id]);
    $session_data = $res ? sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC) : null;
    if ($res !== false) sqlsrv_free_stmt($res);
    if (!$session_data) die("<p style='color:red;'>Session not found.</p>");

    // All sessions for switcher
    $q = "SELECT SessionID, Session FROM dbo.Attendance_Sessions WHERE CourseID = ? AND LecturerID = ? ORDER BY Session";
    $res = sqlsrv_query($conn, $q, [$session_data['CourseID'], $lecturer_id]);
    while ($row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) $all_sessions[] = $row;
    if ($res !== false) sqlsrv_free_stmt($res);

    // All marked dates
    $q = "SELECT MarkID, [Date] FROM dbo.Attendance_Mark WHERE SessionID = ? ORDER BY [Date] DESC";
    $res = sqlsrv_query($conn, $q, [$session_id]);
    while ($row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) $mark_dates[] = $row;
    if ($res !== false) sqlsrv_free_stmt($res);

    // Enrolled students via Enrollments
    $q = "SELECT s.StudentID, s.StudentNumber, u.FirstName, u.LastName
          FROM dbo.Students s
          JOIN dbo.Users u ON s.UserID = u.UserID
          JOIN dbo.Enrollments e ON s.StudentID = e.StudentID
          WHERE e.SessionID = ? AND e.Status = 'Active'
          ORDER BY s.StudentNumber";
    $res = sqlsrv_query($conn, $q, [$session_id]);
    while ($row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) $enrolled_students[] = $row;
    if ($res !== false) sqlsrv_free_stmt($res);

    // Attendance records by MarkID
    if ($mark_id > 0) {
        $q = "SELECT StudentID, Status, Remarks FROM dbo.Attendance_Records WHERE MarkID = ?";
        $res = sqlsrv_query($conn, $q, [$mark_id]);
        while ($row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) {
            $attendance_records[$row['StudentID']] = $row;
        }
        if ($res !== false) sqlsrv_free_stmt($res);
    }
}

renderHeader($username, $user_role, 'attendance');
?>

<div class="mb-4">
    <h1 class="h2"><i class="fas fa-clipboard-check me-2"></i>Mark Attendance</h1>
</div>

<?php if (!$session_id) { ?>
    <!-- COURSE & SESSION SELECTION -->
    <div class="card">
        <div class="card-header bg-white">
            <h5 class="mb-0"><i class="fas fa-calendar-alt me-2"></i>Select Session</h5>
        </div>
        <div class="card-body">
            <form method="GET" action="mark_attendance.php" class="row g-3">
                <input type="hidden" name="username" value="<?php echo htmlspecialchars($username); ?>">
                <div class="col-md-6">
                    <label class="form-label">Course <span class="text-danger">*</span></label>
                    <select class="form-control" id="courseSelect" required>
                        <option value="">-- Select Course --</option>
                        <?php foreach ($courses as $c) { ?>
                            <option value="<?php echo $c['CourseID']; ?>">
                                <?php echo htmlspecialchars($c['CourseCode'].' - '.$c['CourseName']); ?>
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
                    <button type="submit" class="btn btn-primary">Proceed</button>
                </div>
            </form>
        </div>
    </div>
<?php } else { ?>
    <!-- MARKING PAGE -->
    <div class="card mb-3">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">
                <?php echo htmlspecialchars($session_data['CourseCode'].' - '.$session_data['CourseName']); ?>
            </h5>
        </div>
        <div class="card-body">
            <!-- Session Details Row -->
            <div class="row mb-3 pb-3 border-bottom">
                <div class="col-md-4">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-clock text-primary me-2"></i>
                        <div>
                            <small class="text-muted d-block">Time</small>
                            <strong><?php echo $session_data['SessionStartTime']->format('h:i A') . ' - ' . $session_data['SessionEndTime']->format('h:i A'); ?></strong>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-bookmark text-primary me-2"></i>
                        <div>
                            <small class="text-muted d-block">Type</small>
                            <strong><?php echo htmlspecialchars($session_data['SessionType']); ?></strong>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-map-marker-alt text-primary me-2"></i>
                        <div>
                            <small class="text-muted d-block">Location</small>
                            <strong><?php echo htmlspecialchars($session_data['Location']); ?></strong>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Date & Session Switcher Row -->
            <div class="row">
                <div class="col-md-6">
                    <label class="form-label fw-bold"><i class="fas fa-calendar me-1"></i>Date</label>
                    <select class="form-control" id="dateSelect" onchange="switchDate(this.value)">
                        <option value="">-- Select Date --</option>
                        <option value="today" <?php echo ($mark_id == 0 || $selected_date == $today) ? 'selected' : ''; ?>>
                            Today (<?php echo date('M d, Y'); ?>)
                        </option>
                        <?php foreach ($mark_dates as $md): ?>
                            <option value="<?php echo $md['MarkID']; ?>" <?php echo ($mark_id == $md['MarkID']) ? 'selected' : ''; ?>>
                                <?php echo $md['Date']->format('M d, Y'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold"><i class="fas fa-exchange-alt me-1"></i>Switch Session</label>
                    <select class="form-control" onchange="window.location='mark_attendance.php?username=<?php echo urlencode($username); ?>&session_id='+this.value">
                        <?php foreach ($all_sessions as $s): ?>
                            <option value="<?php echo $s['SessionID']; ?>" <?php echo $s['SessionID'] == $session_id ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($s['Session']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <?php if (empty($enrolled_students)) { ?>
        <div class="alert alert-warning">No students enrolled in this session.</div>
    <?php } else { ?>
        <form method="POST" action="mark_attendance.php?username=<?php echo urlencode($username); ?>&session_id=<?php echo $session_id; ?>&mark_id=<?php echo $mark_id; ?>" id="attendanceForm">
            <input type="hidden" name="session_id" value="<?php echo $session_id; ?>">
            <input type="hidden" name="mark_id" value="<?php echo $mark_id; ?>">

            <div class="card">
                <div class="card-header bg-white">
                    <div class="row align-items-center">
                        <div class="col">
                            <h5 class="mb-0"><i class="fas fa-users me-2"></i>Students (<?php echo count($enrolled_students); ?>)</h5>
                        </div>
                        <div class="col-auto">
                            <button type="button" class="btn btn-sm btn-outline-success" onclick="markAll('Present')">
                                Mark All Present
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="markAll('Absent')">
                                Mark All Absent
                            </button>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Name</th>
                                    <th>Student ID</th>
                                    <th>Status</th>
                                    <th>Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $i = 1; foreach ($enrolled_students as $stu):
                                    $sid = $stu['StudentID'];
                                    $status = $attendance_records[$sid]['Status'] ?? 'Present';
                                    $remark = $attendance_records[$sid]['Remarks'] ?? '';
                                ?>
                                    <tr>
                                        <td><strong><?php echo $i++; ?></strong></td>
                                        <td><?php echo htmlspecialchars($stu['FirstName'].' '.$stu['LastName']); ?></td>
                                        <td><small class="text-muted"><?php echo htmlspecialchars($stu['StudentNumber']); ?></small></td>
                                        <td>
                                            <div class="btn-group btn-group-sm" role="group">
                                                <input type="radio" class="btn-check" name="attendance[<?php echo $sid; ?>]" 
                                                       id="present_<?php echo $sid; ?>" value="Present" 
                                                       <?php echo $status == 'Present' ? 'checked' : ''; ?>>
                                                <label class="btn btn-outline-success" for="present_<?php echo $sid; ?>">Present</label>

                                                <input type="radio" class="btn-check" name="attendance[<?php echo $sid; ?>]" 
                                                       id="absent_<?php echo $sid; ?>" value="Absent"
                                                       <?php echo $status == 'Absent' ? 'checked' : ''; ?>>
                                                <label class="btn btn-outline-danger" for="absent_<?php echo $sid; ?>">Absent</label>
                                            </div>
                                        </td>
                                        <td>
                                            <input type="text" class="form-control form-control-sm" name="remarks[<?php echo $sid; ?>]" 
                                                   placeholder="Optional…" value="<?php echo htmlspecialchars($remark); ?>">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-white text-end">
                    <button type="button" class="btn btn-primary btn-lg" onclick="showConfirmModal()">
                        <i class="fas fa-save me-2"></i>Save Attendance
                    </button>
                </div>
            </div>
        </form>
    <?php } ?>
<?php } ?>

<!-- Confirmation Modal -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fas fa-exclamation-circle me-2"></i>Confirm Save Attendance
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center py-4">
                <i class="fas fa-clipboard-check fa-3x text-primary mb-3"></i>
                <h5>Are you sure you want to save the attendance?</h5>
                <p class="text-muted mb-0">This will record attendance for <strong id="studentCount"></strong> student(s).</p>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-2"></i>Cancel
                </button>
                <button type="button" class="btn btn-primary" onclick="submitForm()">
                    <i class="fas fa-check me-2"></i>Yes, Save
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
                <?php if ($error_count == 999): ?>
                    <div class="mb-3">
                        <i class="fas fa-times-circle fa-4x text-danger"></i>
                    </div>
                    <h4 class="mb-3 text-danger">Failed to Save Attendance</h4>
                    <p class="text-muted mb-0">There was an error creating the attendance mark. Please try again.</p>
                <?php elseif ($error_count > 0 && $success_count == 0): ?>
                    <div class="mb-3">
                        <i class="fas fa-exclamation-triangle fa-4x text-warning"></i>
                    </div>
                    <h4 class="mb-3 text-warning">Attendance Save Failed</h4>
                    <p class="text-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong><?php echo $error_count; ?></strong> student record(s) failed to save
                    </p>
                <?php else: ?>
                    <div class="mb-3">
                        <i class="fas fa-check-circle fa-4x text-success"></i>
                    </div>
                    <h4 class="mb-3">Attendance Saved Successfully!</h4>
                    <p class="text-muted mb-0">
                        <i class="fas fa-users me-2"></i>
                        <strong><?php echo $success_count; ?></strong> student record(s) saved
                    </p>
                    <?php if ($error_count > 0): ?>
                        <p class="text-danger mt-2">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong><?php echo $error_count; ?></strong> failed
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
                <button type="button" class="btn btn-primary mt-4" onclick="closeSuccessModal()">
                    <i class="fas fa-times me-2"></i>Close
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function switchDate(val) {
    if (!val || val === 'today') {
        const url = new URL(window.location);
        url.searchParams.delete('mark_id');
        window.location = url;
    } else {
        const url = new URL(window.location);
        url.searchParams.set('mark_id', val);
        window.location = url;
    }
}

document.getElementById('courseSelect')?.addEventListener('change', function () {
    const courseId = this.value;
    const sessSel = document.getElementById('sessionSelect');
    sessSel.disabled = true;
    sessSel.innerHTML = '<option>Loading…</option>';

    fetch(`get_sessions_for_mark.php?course_id=${courseId}&username=<?php echo addslashes($username); ?>`)
        .then(r => r.json())
        .then(d => {
            sessSel.innerHTML = '<option value="">-- Select Session --</option>';
            if (d.success && d.sessions.length) {
                d.sessions.forEach(s => {
                    const opt = document.createElement('option');
                    opt.value = s.SessionID;
                    opt.textContent = `${s.Session} | ${s.SessionStartTime}-${s.SessionEndTime}`;
                    sessSel.appendChild(opt);
                });
            } else {
                sessSel.innerHTML = '<option value="">No sessions</option>';
            }
            sessSel.disabled = false;
        });
});

function markAll(st) {
    document.querySelectorAll(`input[type="radio"][value="${st}"]`).forEach(r => r.checked = true);
}

function showConfirmModal() {
    const studentCount = <?php echo count($enrolled_students ?? []); ?>;
    document.getElementById('studentCount').textContent = studentCount;
    const modal = new bootstrap.Modal(document.getElementById('confirmModal'));
    modal.show();
}

function submitForm() {
    const modal = bootstrap.Modal.getInstance(document.getElementById('confirmModal'));
    modal.hide();
    
    // Add hidden input to trigger the POST
    const form = document.getElementById('attendanceForm');
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'mark_attendance';
    input.value = '1';
    form.appendChild(input);
    
    form.submit();
}

function closeSuccessModal() {
    const modal = bootstrap.Modal.getInstance(document.getElementById('successModal'));
    if (modal) {
        modal.hide();
    }
    // Redirect to clear POST data and refresh
    window.location.href = 'mark_attendance.php?username=<?php echo urlencode($username); ?>&session_id=<?php echo $session_id; ?>&mark_id=<?php echo $mark_id; ?>';
}

<?php if ($show_success_modal): ?>
    window.addEventListener('DOMContentLoaded', function() {
        const modal = new bootstrap.Modal(document.getElementById('successModal'));
        modal.show();
    });
<?php endif; ?>
</script>

<?php
renderFooter();
sqlsrv_close($conn);
?>