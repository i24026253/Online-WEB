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

// Handle create session
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_session'])) {
    $course_id     = (int)$_POST['course_id'];
    $session_id    = (int)$_POST['session_id'];
    $mark_date     = $_POST['mark_date'];
    $session_name  = trim($_POST['session_name']);

    $errors = [];

    if ($course_id <= 0) {
        $errors[] = "Please select a <strong>course</strong>.";
    }
    if (empty($session_name)) {
        $errors[] = "Please select a <strong>session</strong>.";
    }
    if (empty($mark_date)) {
        $errors[] = "Please select an <strong>attendance date</strong>.";
    }
    if ($session_id <= 0) {
        $errors[] = "Invalid session selected.";
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
        $check_sql = "SELECT SessionID FROM dbo.Attendance_Sessions 
                      WHERE SessionID = ? AND CourseID = ? AND LecturerID = ?";
        $check_stmt = sqlsrv_query($conn, $check_sql, [$session_id, $course_id, $lecturer_id]);
        
        if ($check_stmt === false) {
            error_log("Check session error: " . print_r(sqlsrv_errors(), true));
            $message = errorAlert("Database error while validating session.");
        } elseif (sqlsrv_has_rows($check_stmt) === false) {
            $message = errorAlert("Invalid session selected.");
        } else {
            $ins_sql = "INSERT INTO dbo.Attendance_Mark (SessionID, [Date]) VALUES (?, ?)";
            $ins_stmt = sqlsrv_query($conn, $ins_sql, [$session_id, $mark_date]);

            if ($ins_stmt) {
                $message = successAlert("Attendance session created successfully.");
                header("Location: attendance_sessions.php?username=" . urlencode($username));
                exit;
            } else {
                error_log("Insert error: " . print_r(sqlsrv_errors(), true));
                $message = errorAlert("Failed to create attendance record.");
            }
        }
        if (isset($check_stmt) && $check_stmt !== false) sqlsrv_free_stmt($check_stmt);
        if (isset($ins_stmt) && $ins_stmt !== false) sqlsrv_free_stmt($ins_stmt);
    }
}

// Handle delete attendance mark (ONLY from Attendance_Mark)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_session'])) {
    $mark_id = (int)$_POST['mark_id'];

    if ($mark_id <= 0) {
        $message = errorAlert("Invalid attendance record.");
    } else {
        // Delete only from Attendance_Mark, no checks
        $delete_sql = "DELETE FROM dbo.Attendance_Mark 
                       WHERE MarkID = ? 
                       AND SessionID IN (SELECT SessionID FROM dbo.Attendance_Sessions WHERE LecturerID = ?)";
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
$courses_query = "SELECT DISTINCT c.CourseID, c.CourseCode, c.CourseName 
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

// Fetch all sessions (with MarkID)
$sessions_query = "
    SELECT am.MarkID,
           am.[Date]          AS SessionDate,
           am.MarkedTime,
           s.SessionStartTime,
           s.SessionEndTime,
           s.Session,
           c.CourseCode,
           c.CourseName,
           s.SessionID,
           s.CourseID
    FROM dbo.Attendance_Mark am
    JOIN dbo.Attendance_Sessions s ON am.SessionID = s.SessionID
    JOIN dbo.Courses c ON s.CourseID = c.CourseID
    WHERE s.LecturerID = ?
    ORDER BY am.[Date] DESC, s.SessionStartTime DESC";
$sessions_result = sqlsrv_query($conn, $sessions_query, [$lecturer_id]);

if ($sessions_result === false) {
    error_log("Sessions query error: " . print_r(sqlsrv_errors(), true));
    die("<p style='color:red;'>Database error: " . print_r(sqlsrv_errors(), true) . "</p>");
}

$all_sessions = [];
while ($session = sqlsrv_fetch_array($sessions_result, SQLSRV_FETCH_ASSOC)) {
    $all_sessions[] = $session;
}
sqlsrv_free_stmt($sessions_result);

// Render header with navigation
renderHeader($username, $user_role, 'attendance');
?>

<!-- Page Content Starts Here -->
<div class="mb-4 d-flex justify-content-between align-items-center">
    <h1 class="h2"><i class="fas fa-calendar-alt me-2"></i>Attendance Sessions</h1>
    <a class="nav-link" href="http://127.0.0.1:8000/lecturer-dashboard/">
        <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
    </a>
</div>

<?php if (isset($message)) echo $message; ?>

<!-- Create Session Button -->
<button type="button" class="btn btn-success mb-3" data-bs-toggle="modal" data-bs-target="#createSessionModal">
    <i class="fas fa-plus-circle me-2"></i>Create New Session
</button>

<!-- ENHANCED FILTERS -->
<div class="card mb-3 shadow-sm">
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-md-5">
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
            <div class="col-md-5">
                <label class="form-label fw-semibold">
                    <i class="fas fa-chalkboard-teacher me-1"></i>Filter by Session
                </label>
                <select class="form-select" id="filterSession" disabled>
                    <option value="">All Sessions</option>
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

<!-- Sessions Table -->
<div class="card shadow-sm">
    <div class="card-header bg-white border-bottom">
        <h5 class="mb-0"><i class="fas fa-list me-2"></i>All Sessions</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4 py-3">Course</th>
                        <th class="py-3">Session</th>
                        <th class="py-3">Date</th>
                        <th class="py-3">Time</th>
                        <th class="text-center py-3" style="width: 180px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="sessionsBody">
                    <!-- Filled by JavaScript -->
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
                <h5 class="modal-title">Create Attendance Session</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="attendance_sessions.php?username=<?php echo urlencode($username); ?>">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Course <span class="text-danger">*</span></label>
                            <select class="form-control" id="courseSelect" name="course_id" required onchange="loadSessions()">
                                <option value="">-- Select Course --</option>
                                <?php foreach ($courses as $course) { ?>
                                    <option value="<?php echo $course['CourseID']; ?>">
                                        <?php echo htmlspecialchars($course['CourseCode'] . ' - ' . $course['CourseName']); ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Attendance Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="markDate" name="mark_date" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Session <span class="text-danger">*</span></label>
                            <select class="form-control" id="sessionSelect" name="session_name" required disabled>
                                <option value="">-- Select Course First --</option>
                            </select>
                        </div>

                        <input type="hidden" name="session_id" id="hiddenSessionId">
                        
                        <div class="col-md-6">
                            <label class="form-label">Time</label>
                            <input type="text" class="form-control" id="displayTime" readonly placeholder="Will show after selection">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Location</label>
                            <input type="text" class="form-control" id="displayLocation" readonly placeholder="Will show after selection">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="create_session" class="btn btn-success" disabled>
                        Create Session
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// === MODAL: Load sessions for selected course ===
function loadSessions() {
    const courseId      = document.getElementById('courseSelect').value;
    const sessionSelect = document.getElementById('sessionSelect');
    const hiddenId      = document.getElementById('hiddenSessionId');
    const timeDisplay   = document.getElementById('displayTime');
    const locDisplay    = document.getElementById('displayLocation');
    const createBtn     = document.querySelector('#createSessionModal .btn-success');

    sessionSelect.innerHTML = '<option value="">-- Loading... --</option>';
    sessionSelect.disabled = true;
    hiddenId.value = '';
    timeDisplay.value = '';
    locDisplay.value = '';
    createBtn.disabled = true;

    if (!courseId) {
        sessionSelect.innerHTML = '<option value="">-- Select Course First --</option>';
        return;
    }

    fetch(`get_sessions_for_mark.php?course_id=${courseId}&username=<?php echo addslashes($username); ?>`)
        .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(data => {
            sessionSelect.innerHTML = '<option value="">-- Select Session --</option>';

            if (data.success && data.sessions.length > 0) {
                data.sessions.forEach(s => {
                    const opt = document.createElement('option');
                    opt.value = s.Session;
                    opt.textContent = s.Session;
                    opt.dataset.id = s.SessionID;
                    opt.dataset.time = `${s.SessionStartTime} - ${s.SessionEndTime}`;
                    opt.dataset.location = s.Location || '';
                    sessionSelect.appendChild(opt);
                });
                sessionSelect.disabled = false;
            } else {
                sessionSelect.innerHTML = '<option value="">No sessions found</option>';
            }
        })
        .catch(err => {
            console.error(err);
            sessionSelect.innerHTML = '<option value="">Error loading</option>';
        });
}

// Enable Create button
document.getElementById('createSessionModal').addEventListener('input', function () {
    const courseOk   = document.getElementById('courseSelect').value;
    const sessionOk  = document.getElementById('sessionSelect').value;
    const dateOk     = document.getElementById('markDate').value;
    const createBtn  = this.querySelector('.btn-success');
    createBtn.disabled = !(courseOk && sessionOk && dateOk);
});

// Fill hidden ID + display
document.getElementById('sessionSelect').addEventListener('change', function () {
    const sel = this.options[this.selectedIndex];
    document.getElementById('hiddenSessionId').value = sel.dataset.id || '';
    document.getElementById('displayTime').value     = sel.dataset.time || '';
    document.getElementById('displayLocation').value = sel.dataset.location || '';
});

// === TABLE RENDERING & FILTERING ===
const allRows = <?php echo json_encode($all_sessions); ?>;
const tbody   = document.getElementById('sessionsBody');

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

function formatTime(timeObj) {
    if (!timeObj || !timeObj.date) return '—';
    const t = new Date(timeObj.date);
    return t.toLocaleTimeString('en-US', { hour:'numeric', minute:'2-digit' });
}

function renderRow(s) {
    const marked = s.MarkedTime !== null;
    const btnClass = marked ? 'btn-primary' : 'btn-success';
    const btnIcon  = marked ? 'fa-edit' : 'fa-clock';
    const btnText  = marked ? 'Edit' : 'Mark';

    return `
        <tr data-course="${s.CourseID}" data-session="${s.SessionID}">
            <td class="px-4 py-3">
                <div class="fw-semibold">${escapeHtml(s.CourseCode)}</div>
                <small class="text-muted">${escapeHtml(s.CourseName)}</small>
            </td>
            <td class="py-3">
                <span class="badge bg-primary px-3 py-2">${escapeHtml(s.Session ?? '—')}</span>
            </td>
            <td class="py-3">${formatDate(s.SessionDate)}</td>
            <td class="py-3">
                <small>${formatTime(s.SessionStartTime)} - ${formatTime(s.SessionEndTime)}</small>
            </td>
            <td class="text-center py-3">
                <div class="btn-group" role="group">
                    <a href="mark_attendance.php?username=<?php echo urlencode($username); ?>
                        &session_id=${s.SessionID}&mark_id=${s.MarkID}"
                       class="btn ${btnClass} btn-sm" title="${btnText} Attendance">
                        <i class="fas ${btnIcon} me-1"></i>${btnText}
                    </a>
                    <form method="POST" style="display:inline;" 
                          onsubmit="return confirm('Delete this attendance record? This will remove the mark for this date.');">
                        <input type="hidden" name="mark_id" value="${s.MarkID}">
                        <button type="submit" name="delete_session"
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
        tbody.innerHTML = `<tr><td colspan="5" class="text-center text-muted py-5">
                              <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                              No attendance records created yet.
                           </td></tr>`;
        return;
    }
    tbody.innerHTML = allRows.map(renderRow).join('');
}
renderAll();

// === FILTER LOGIC ===
const filterCourse   = document.getElementById('filterCourse');
const filterSession  = document.getElementById('filterSession');
const clearBtn       = document.getElementById('clearFilter');

filterCourse.addEventListener('change', applyFilters);
filterSession.addEventListener('change', applyFilters);
clearBtn.addEventListener('click', () => {
    filterCourse.value = '';
    filterSession.innerHTML = '<option value="">All Sessions</option>';
    filterSession.disabled = true;
    applyFilters();
});

filterCourse.addEventListener('change', function () {
    const cid = this.value;
    filterSession.disabled = true;
    filterSession.innerHTML = '<option value="">Loading…</option>';

    if (!cid) {
        filterSession.innerHTML = '<option value="">All Sessions</option>';
        filterSession.disabled = true;
        return;
    }

    fetch(`get_sessions_for_mark.php?course_id=${cid}&username=<?php echo addslashes($username); ?>`)
        .then(r => r.json())
        .then(data => {
            filterSession.innerHTML = '<option value="">All Sessions</option>';
            if (data.success && data.sessions.length) {
                data.sessions.forEach(s => {
                    const opt = new Option(s.Session, s.SessionID);
                    filterSession.appendChild(opt);
                });
            } else {
                filterSession.innerHTML = '<option value="">No sessions</option>';
            }
            filterSession.disabled = false;
        });
});

function applyFilters() {
    const courseId   = filterCourse.value;
    const sessionId  = filterSession.value;

    const filtered = allRows.filter(row => {
        const matchCourse  = !courseId || row.CourseID == courseId;
        const matchSession = !sessionId || row.SessionID == sessionId;
        return matchCourse && matchSession;
    });

    if (filtered.length === 0) {
        tbody.innerHTML = `<tr><td colspan="5" class="text-center text-muted py-5">
                              <i class="fas fa-search fa-2x mb-2 d-block"></i>
                              No sessions match the selected filters.
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