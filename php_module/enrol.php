<?php

require_once 'header.php';

// Include the database connection
require_once 'connect.php';

// Get username from URL
$username = isset($_GET['username']) ? $_GET['username'] : null;

if (!$username) {
    die("<p style='color:red;'>❌ Please log in to enroll in courses.</p>");
}

// Debug: Log username
error_log("Username: $username");

// Fetch student ID AND user role based on username
$student_query = "
    SELECT s.StudentID, u.Role 
    FROM dbo.Students s 
    INNER JOIN dbo.Users u ON s.UserID = u.UserID 
    WHERE u.Username = ?
";
$params = array($username);
$student_result = sqlsrv_query($conn, $student_query, $params);

if ($student_result === false) {
    error_log("Student query error: " . print_r(sqlsrv_errors(), true));
    die("<p style='color:red;'>❌ Database error: " . print_r(sqlsrv_errors(), true) . "</p>");
}

$student_row = sqlsrv_fetch_array($student_result, SQLSRV_FETCH_ASSOC);
$student_id = $student_row ? $student_row['StudentID'] : null;
$user_role = $student_row ? strtolower($student_row['Role']) : 'student'; // Get role from database

if (!$student_id) {
    error_log("Student not found for username: $username");
    die("<p style='color:red;'>❌ Student not found.</p>");
}

// Handle enrollment form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enroll_submit'])) {
    $course_id = $_POST['course_id'];
    $reason = $_POST['reason'];
    $enrollment_date = date('Y-m-d H:i:s');
    $status = 'Pending Enroll';

    // Debug: Log enrollment attempt
    error_log("Enrolling StudentID: $student_id in CourseID: $course_id with Reason: $reason");

    // Check for existing active or pending enrollment
    $check_query = "SELECT COUNT(*) as count FROM dbo.Enrollments WHERE StudentID = ? AND CourseID = ? AND Status IN ('Active', 'Pending Enroll', 'Pending Drop')";
    $check_params = array($student_id, $course_id);
    $check_result = sqlsrv_query($conn, $check_query, $check_params);
    $check_row = sqlsrv_fetch_array($check_result, SQLSRV_FETCH_ASSOC);

    if ($check_row['count'] > 0) {
        $message = "<div class='alert alert-warning'><i class='fas fa-exclamation-triangle me-2'></i>You already have an active or pending enrollment for this course.</div>";
    } else {
        // Insert new enrollment
        $insert_query = "INSERT INTO dbo.Enrollments (StudentID, CourseID, EnrollmentDate, Status, RequestReason) VALUES (?, ?, ?, ?, ?)";
        $insert_params = array($student_id, $course_id, $enrollment_date, $status, $reason);
        $insert_result = sqlsrv_query($conn, $insert_query, $insert_params);

        if ($insert_result) {
            $message = "<div class='alert alert-success'><i class='fas fa-check-circle me-2'></i>Enrollment request submitted, pending admin approval.</div>";
        } else {
            error_log("Enrollment error: " . print_r(sqlsrv_errors(), true));
            $message = "<div class='alert alert-danger'><i class='fas fa-times-circle me-2'></i>Failed to enroll: " . print_r(sqlsrv_errors(), true) . "</div>";
        }
    }
}

// Handle drop form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['drop_submit'])) {
    $enrollment_id = $_POST['enrollment_id'];
    $reason = $_POST['reason'];
    $status = 'Pending Drop';

    // Debug: Log drop attempt
    error_log("Dropping EnrollmentID: $enrollment_id with Reason: $reason");

    // Update enrollment status and reason
    $update_query = "UPDATE dbo.Enrollments SET Status = ?, RequestReason = ?, DropRejectionReason = NULL WHERE EnrollmentID = ? AND StudentID = ?";
    $update_params = array($status, $reason, $enrollment_id, $student_id);
    $update_result = sqlsrv_query($conn, $update_query, $update_params);

    if ($update_result) {
        $message = "<div class='alert alert-success'><i class='fas fa-check-circle me-2'></i>Drop request submitted, pending admin approval.</div>";
    } else {
        error_log("Drop error: " . print_r(sqlsrv_errors(), true));
        $message = "<div class='alert alert-danger'><i class='fas fa-times-circle me-2'></i>Failed to submit drop request: " . print_r(sqlsrv_errors(), true) . "</div>";
    }
}

// Fetch available courses with the latest enrollment status
$course_query = "
    SELECT c.CourseID, c.CourseCode, c.CourseName, c.Description, c.Credits, e.EnrollmentID, e.Status, e.RejectionReason, e.DropRejectionReason
    FROM dbo.Courses c
    LEFT JOIN (
        SELECT EnrollmentID, StudentID, CourseID, Status, RejectionReason, DropRejectionReason, EnrollmentDate,
               ROW_NUMBER() OVER (PARTITION BY StudentID, CourseID ORDER BY EnrollmentDate DESC) as rn
        FROM dbo.Enrollments
        WHERE StudentID = ?
    ) e ON c.CourseID = e.CourseID AND e.rn = 1
    WHERE c.IsActive = 1
    AND c.AcademicYearID = (SELECT TOP 1 AcademicYearID FROM dbo.Academic_Years WHERE IsActive = 1)
";
$course_params = array($student_id);
$course_result = sqlsrv_query($conn, $course_query, $course_params);

if ($course_result === false) {
    error_log("Course query error: " . print_r(sqlsrv_errors(), true));
    die("<p style='color:red;'>❌ Database error: " . print_r(sqlsrv_errors(), true) . "</p>");
}

// Render header with navigation (NOW $user_role is defined)
renderHeader($username, $user_role);
?>

<!-- Your page content starts here (inside the main content area) -->
<div class="mb-4">
    <h1 class="h2"><i class="fas fa-book me-2"></i>Course Enrollment</h1>
</div>

<!-- Display messages -->
<?php if (isset($message)) echo $message; ?>

<!-- Available Courses -->
<div class="card">
    <div class="card-header bg-white">
        <h5 class="mb-0"><i class="fas fa-list me-2"></i>Available Courses</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead class="table-light">
                    <tr>
                        <th>Course Code</th>
                        <th>Course Name</th>
                        <th>Description</th>
                        <th>Credits</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($course = sqlsrv_fetch_array($course_result, SQLSRV_FETCH_ASSOC)) { ?>
                        <tr>
                            <td><?php echo htmlspecialchars($course['CourseCode']); ?></td>
                            <td><?php echo htmlspecialchars($course['CourseName']); ?></td>
                            <td><?php echo htmlspecialchars($course['Description'] ?? 'No description'); ?></td>
                            <td><?php echo htmlspecialchars($course['Credits']); ?></td>
                            <td>
                                <?php if ($course['EnrollmentID'] && $course['Status'] === 'Active') { ?>
                                    <span class="badge bg-success">Enrolled</span>
                                    <?php if ($course['DropRejectionReason']) { ?>
                                        <small class="text-muted d-block mt-1">(Drop Rejected: <?php echo htmlspecialchars($course['DropRejectionReason']); ?>)</small>
                                    <?php } ?>
                                    <button type="button" class="btn btn-outline-danger btn-sm mt-1" data-bs-toggle="modal" data-bs-target="#dropModal<?php echo $course['EnrollmentID']; ?>">
                                        <i class="fas fa-minus-circle me-1"></i>Drop
                                    </button>
                                    
                                    <!-- Drop Course Modal -->
                                    <div class="modal fade" id="dropModal<?php echo $course['EnrollmentID']; ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title"><i class="fas fa-minus-circle me-2"></i>Drop Course</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form method="POST" action="enrol.php?username=<?php echo urlencode($username); ?>">
                                                    <div class="modal-body">
                                                        <div class="mb-3">
                                                            <label class="form-label">Subject</label>
                                                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($course['CourseCode'] . ' - ' . $course['CourseName']); ?>" readonly>
                                                            <input type="hidden" name="enrollment_id" value="<?php echo $course['EnrollmentID']; ?>">
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Reason for Dropping <span class="text-danger">*</span></label>
                                                            <textarea class="form-control" name="reason" rows="4" required></textarea>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" name="drop_submit" class="btn btn-danger">Submit Drop Request</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                    
                                <?php } elseif ($course['EnrollmentID'] && $course['Status'] === 'Pending Enroll') { ?>
                                    <span class="badge bg-warning text-dark">Pending Approval</span>
                                    
                                <?php } elseif ($course['EnrollmentID'] && $course['Status'] === 'Pending Drop') { ?>
                                    <span class="badge bg-info text-dark">Pending Drop</span>
                                    
                                <?php } elseif ($course['EnrollmentID'] && $course['Status'] === 'Dropped') { ?>
                                    <span class="badge bg-secondary">Previously Dropped</span>
                                    <button type="button" class="btn btn-success btn-sm ms-2" data-bs-toggle="modal" data-bs-target="#enrollModal<?php echo $course['CourseID']; ?>">
                                        <i class="fas fa-plus-circle me-1"></i>Re-enroll
                                    </button>
                                    
                                    <!-- Enroll Course Modal -->
                                    <div class="modal fade" id="enrollModal<?php echo $course['CourseID']; ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Enroll in Course</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form method="POST" action="enrol.php?username=<?php echo urlencode($username); ?>">
                                                    <div class="modal-body">
                                                        <div class="mb-3">
                                                            <label class="form-label">Subject</label>
                                                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($course['CourseCode'] . ' - ' . $course['CourseName']); ?>" readonly>
                                                            <input type="hidden" name="course_id" value="<?php echo $course['CourseID']; ?>">
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Reason for Enrollment <span class="text-danger">*</span></label>
                                                            <textarea class="form-control" name="reason" rows="4" required></textarea>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" name="enroll_submit" class="btn btn-success">Submit Enrollment Request</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                    
                                <?php } elseif ($course['EnrollmentID'] && $course['Status'] === 'Rejected') { ?>
                                    <span class="badge bg-danger">Rejected</span>
                                    <?php if ($course['RejectionReason']) { ?>
                                        <small class="text-muted d-block mt-1">(<?php echo htmlspecialchars($course['RejectionReason']); ?>)</small>
                                    <?php } ?>
                                    <button type="button" class="btn btn-success btn-sm mt-1" data-bs-toggle="modal" data-bs-target="#enrollModal<?php echo $course['CourseID']; ?>">
                                        <i class="fas fa-plus-circle me-1"></i>Re-enroll
                                    </button>
                                    
                                    <!-- Enroll Course Modal -->
                                    <div class="modal fade" id="enrollModal<?php echo $course['CourseID']; ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Enroll in Course</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form method="POST" action="enrol.php?username=<?php echo urlencode($username); ?>">
                                                    <div class="modal-body">
                                                        <div class="mb-3">
                                                            <label class="form-label">Subject</label>
                                                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($course['CourseCode'] . ' - ' . $course['CourseName']); ?>" readonly>
                                                            <input type="hidden" name="course_id" value="<?php echo $course['CourseID']; ?>">
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Reason for Enrollment <span class="text-danger">*</span></label>
                                                            <textarea class="form-control" name="reason" rows="4" required></textarea>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" name="enroll_submit" class="btn btn-success">Submit Enrollment Request</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                    
                                <?php } elseif ($course['EnrollmentID']) { ?>
                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($course['Status']); ?></span>
                                    
                                <?php } else { ?>
                                    <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#enrollModal<?php echo $course['CourseID']; ?>">
                                        <i class="fas fa-plus-circle me-1"></i>Enroll
                                    </button>
                                    
                                    <!-- Enroll Course Modal -->
                                    <div class="modal fade" id="enrollModal<?php echo $course['CourseID']; ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Enroll in Course</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form method="POST" action="enrol.php?username=<?php echo urlencode($username); ?>">
                                                    <div class="modal-body">
                                                        <div class="mb-3">
                                                            <label class="form-label">Subject</label>
                                                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($course['CourseCode'] . ' - ' . $course['CourseName']); ?>" readonly>
                                                            <input type="hidden" name="course_id" value="<?php echo $course['CourseID']; ?>">
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Reason for Enrollment <span class="text-danger">*</span></label>
                                                            <textarea class="form-control" name="reason" rows="4" required></textarea>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" name="enroll_submit" class="btn btn-success">Submit Enrollment Request</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php } ?>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
// Render footer and close HTML
renderFooter();

// Clean up
sqlsrv_free_stmt($course_result);
sqlsrv_free_stmt($student_result);
sqlsrv_close($conn);
?>