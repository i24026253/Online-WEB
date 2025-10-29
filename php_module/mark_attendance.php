<?php
require_once 'header.php';
require_once 'connect.php';

$username = $_GET['username'] ?? null;
if (!$username) {
    die("<p style='color:red;'>❌ Please log in as a lecturer.</p>");
}

/* ---------- Verify Lecturer ---------- */
// Verify lecturer role and get user info
$lecturer_q = "SELECT u.UserID, u.Role, l.LecturerID 
               FROM dbo.Users u JOIN dbo.Lecturers l ON u.UserID = l.UserID 
               WHERE u.Username = ?";

$params = array($username);

$lecturer_res = sqlsrv_query($conn, $lecturer_q, [$username]);
if (!$lecturer_res || !($row = sqlsrv_fetch_array($lecturer_res, SQLSRV_FETCH_ASSOC)) || $row['Role'] !== 'Lecturer') {
    die("<p style='color:red;'>❌ Database error: " . print_r(sqlsrv_errors(), true) . "</p>");
}

$lecturer_id = $row['LecturerID'];
$user_role = strtolower($row['Role']);

/* ---------- Current Date (Always Today) ---------- */
$today = date('Y-m-d');

/* ---------- Session ID ---------- */
$session_id = $_GET['session_id'] ?? null;

/* ---------- MARK ATTENDANCE POST ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_attendance'])) {
    $session_id  = (int)$_POST['session_id'];
    $marked_by   = $lecturer_id;
    $marked_time = date('Y-m-d H:i:s');

    $success = $error = 0;

    foreach ($_POST['attendance'] as $student_id => $status) {
        $remarks = trim($_POST['remarks'][$student_id] ?? '');
        $remarks = $remarks === '' ? null : $remarks;

        $chk = sqlsrv_query($conn, "SELECT AttendanceID FROM dbo.Attendance_Records WHERE SessionID = ? AND StudentID = ?", [$session_id, $student_id]);
        $exists = $chk ? sqlsrv_fetch_array($chk, SQLSRV_FETCH_ASSOC) : false;
        sqlsrv_free_stmt($chk);

        if ($exists) {
            $sql = "UPDATE dbo.Attendance_Records 
                    SET Status = ?, MarkedTime = ?, MarkedBy = ?, Remarks = ?, IsEdited = 1, EditedBy = ?, EditedDate = ?
                    WHERE AttendanceID = ?";
            $p = [$status, $marked_time, $marked_by, $remarks, $marked_by, $marked_time, $exists['AttendanceID']];
        } else {
            $sql = "INSERT INTO dbo.Attendance_Records (SessionID, StudentID, Status, MarkedTime, MarkedBy, Remarks)
                    VALUES (?,?,?,?,?,?)";
            $p = [$session_id, $student_id, $status, $marked_time, $marked_by, $remarks];
        }

        $res = sqlsrv_query($conn, $sql, $p);
        $res ? $success++ : $error++;
    }

    $msg = '';
    if ($success) $msg .= "<div class='alert alert-success alert-dismissible fade show' role='alert'>
                    <i class='fas fa-check-circle me-2'></i>Successfully marked attendance for $success_count student(s).
                    <button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
    if ($error)   $msg .= "<div class='alert alert-warning alert-dismissible fade show' role='alert'>
                     <i class='fas fa-exclamation-triangle me-2'></i>Failed to mark $error_count student(s).
                     <button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
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
sqlsrv_free_stmt($res);

/* ---------- SESSION DATA (if selected) ---------- */
$session_data = $enrolled_students = $attendance_records = $all_sessions = null;

if ($session_id) {
    // Get current session
    $q = "SELECT s.*, c.CourseCode, c.CourseName
          FROM dbo.Attendance_Sessions s
          JOIN dbo.Courses c ON s.CourseID = c.CourseID
          WHERE s.SessionID = ? AND s.LecturerID = ?";
    $res = sqlsrv_query($conn, $q, [$session_id, $lecturer_id]);
    $session_data = $res ? sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC) : null;
    sqlsrv_free_stmt($res);
    if (!$session_data) die("<p style='color:red;'>Session not found.</p>");

    // Get ALL sessions for this course (for dropdown switch)
    $q = "SELECT SessionID, Session, SessionStartTime, SessionEndTime, SessionType, Location
          FROM dbo.Attendance_Sessions 
          WHERE CourseID = ? AND LecturerID = ? 
          ORDER BY Session";
    $res = sqlsrv_query($conn, $q, [$session_data['CourseID'], $lecturer_id]);
    while ($row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) {
        $all_sessions[] = $row;
    }
    sqlsrv_free_stmt($res);

    // Enrolled students
    $q = "SELECT s.StudentID, s.StudentNumber, u.FirstName, u.LastName, u.Email
          FROM dbo.Students s
          JOIN dbo.Users u ON s.UserID = u.UserID
          JOIN dbo.Enrollments e ON s.StudentID = e.StudentID
          WHERE e.SessionID = ? AND e.Status = 'Active'
          ORDER BY s.StudentNumber";
    $res = sqlsrv_query($conn, $q, [$session_id]);
    while ($row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) $enrolled_students[] = $row;
    sqlsrv_free_stmt($res);

    // Existing attendance
    $q = "SELECT StudentID, Status, Remarks FROM dbo.Attendance_Records WHERE SessionID = ?";
    $res = sqlsrv_query($conn, $q, [$session_id]);
    if ($res) while ($row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) $attendance_records[$row['StudentID']] = $row;
    sqlsrv_free_stmt($res);
}

renderHeader($username, $user_role, 'attendance');
?>

<div class="mb-4">
    <h1 class="h2"><i class="fas fa-clipboard-check me-2"></i>Mark Attendance</h1>
</div>

<?php if (isset($msg)) echo $msg; ?>

<?php if (!$session_id) { ?>
    <!-- SESSION SELECTION -->
    <div class="card">
        <div class="card-header bg-white">
            <h5 class="mb-0"><i class="fas fa-calendar-alt me-2"></i>Select Attendance Session</h5>
        </div>
        <div class="card-body">
            <p class="text-muted">Please select a session to mark attendance.</p>

            <?php if (empty($courses)) { ?>
                <div class="alert alert-info">No courses assigned.</div>
            <?php } else { ?>
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
                        <a href="attendance_sessions.php?username=<?php echo urlencode($username); ?>" class="btn btn-outline-secondary">
                            Create New Session
                        </a>
                    </div>
                </form>
            <?php } ?>
        </div>
    </div>
<?php } else { ?>
    <!-- MARKING PAGE WITH SESSION SWITCHER -->
    <div class="card mb-3">
        <div class="card-header bg-primary text-white">
            <div class="row align-items-center">
                <div class="col">
                    <h5 class="mb-0">
                        <?php echo htmlspecialchars($session_data['CourseCode'].' - '.$session_data['CourseName']); ?>
                    </h5>
                </div>
                <div class="col-auto">
                    <a href="mark_attendance.php?username=<?php echo urlencode($username); ?>" class="btn btn-light btn-sm">
                        Back 
                    </a>
                </div>
            </div>
        </div>
        <div class="card-body">
            <!-- SESSION SWITCHER DROPDOWN -->
            <div class="row mb-3">
                <div class="col-md-3">
                    <strong><i class="fas fa-calendar me-2"></i>Date:</strong>
                    <?php echo date('M d, Y'); ?>
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

                <div class="col-md-4">
                    <label class="form-label fw-bold">Switch Session</label>
                    <select class="form-control" onchange="window.location='mark_attendance.php?username=<?php echo urlencode($username); ?>&session_id='+this.value">
                        <?php foreach ($all_sessions as $s) { ?>
                            <option value="<?php echo $s['SessionID']; ?>" <?php echo $s['SessionID'] == $session_id ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($s['Session']); ?>
                            </option>
                        <?php } ?>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <?php if (empty($enrolled_students)) { ?>
        <div class="alert alert-warning">No students enrolled.</div>
    <?php } else { ?>
        <form method="POST" action="mark_attendance.php?username=<?php echo urlencode($username); ?>&session_id=<?php echo $session_id; ?>">
            <input type="hidden" name="session_id" value="<?php echo $session_id; ?>">

            <div class="card">
                <div class="card-header bg-white">
                    <div class="row align-items-center">
                        <div class="col">
                            h5 class="mb-0"><i class="fas fa-users me-2"></i>Student Attendance (<?php echo count($enrolled_students); ?> students)</h5>
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
                                    <th style="width:8%;">#</th>
                                    <th style="width:30%;">Name</th>
                                    <th style="width:25%;">Student ID</th>
                                    <th style="width:15%;">Status</th>
                                    <th style="width:22%;">Remarks</th>
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
                                                <label class="btn btn-outline-success" for="present_<?php echo $sid; ?>">
                                                    <i class="fas fa-check me-1"></i>Present
                                                </label>

                                                <input type="radio" class="btn-check" name="attendance[<?php echo $sid; ?>]" 
                                                       id="absent_<?php echo $sid; ?>" value="Absent"
                                                       <?php echo $status == 'Absent' ? 'checked' : ''; ?>>
                                                <label class="btn btn-outline-danger" for="absent_<?php echo $sid; ?>">
                                                    <i class="fas fa-times me-1"></i>Absent
                                                </label>
                                            </div>
                                        </td>
                                        <td>
                                            <input type="text" class="form-control form-control-sm" name="remarks[<?php echo $sid; ?>]" placeholder="Optional…" value="<?php echo htmlspecialchars($remark); ?>">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card-footer bg-white d-flex justify-content-end">
                    <button type="submit" name="mark_attendance" class="btn btn-primary btn-lg">Save Attendance</button>
                </div>
            </div>
        </form>
    <?php } ?>
<?php } ?>

<script>
/* Load sessions */
document.getElementById('courseSelect')?.addEventListener('change', function () {
    const courseId = this.value;
    const sessSel = document.getElementById('sessionSelect');
    const username = '<?php echo addslashes($username); ?>';

    sessSel.disabled = true;
    sessSel.innerHTML = '<option>Loading…</option>';

    fetch(`get_sessions.php?course_id=${courseId}&username=${username}`)
        .then(r => r.json())
        .then(d => {
            sessSel.innerHTML = '<option value="">-- Select Session --</option>';
            if (d.success && d.sessions.length) {
                d.sessions.forEach(s => {
                    const opt = document.createElement('option');
                    opt.value = s.SessionID;
                    opt.textContent = `${s.Session} | ${s.SessionStartTime}-${s.SessionEndTime} | ${s.SessionType} | ${s.Location}`;
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
renderFooter();

sqlsrv_free_stmt($lecturer_result);
sqlsrv_close($conn);
?>