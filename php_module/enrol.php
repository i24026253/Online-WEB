<?php
// Include the database connection
require_once 'connect.php'; // Matches test_php.php

// Get username from URL
$username = isset($_GET['username']) ? $_GET['username'] : null;

if (!$username) {
    die("<p style='color:red;'>❌ Please log in to enroll in courses.</p>");
}

// Debug: Log username
error_log("Username: $username");

// Fetch student ID based on username
$student_query = "SELECT StudentID FROM dbo.Students WHERE UserID = (SELECT UserID FROM dbo.Users WHERE Username = ?)";
$params = array($username);
$student_result = sqlsrv_query($conn, $student_query, $params);

if ($student_result === false) {
    error_log("Student query error: " . print_r(sqlsrv_errors(), true));
    die("<p style='color:red;'>❌ Database error: " . print_r(sqlsrv_errors(), true) . "</p>");
}

$student_row = sqlsrv_fetch_array($student_result, SQLSRV_FETCH_ASSOC);
$student_id = $student_row ? $student_row['StudentID'] : null;

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

    // Check if already enrolled
    $check_query = "SELECT COUNT(*) as count FROM dbo.Enrollments WHERE StudentID = ? AND CourseID = ?";
    $check_params = array($student_id, $course_id);
    $check_result = sqlsrv_query($conn, $check_query, $check_params);
    $check_row = sqlsrv_fetch_array($check_result, SQLSRV_FETCH_ASSOC);

    if ($check_row['count'] > 0) {
        $message = "<p style='color:orange;'>⚠️ You are already enrolled in this course.</p>";
    } else {
        // Insert enrollment
        $insert_query = "INSERT INTO dbo.Enrollments (StudentID, CourseID, EnrollmentDate, Status, RequestReason) VALUES (?, ?, ?, ?, ?)";
        $insert_params = array($student_id, $course_id, $enrollment_date, $status, $reason);
        $insert_result = sqlsrv_query($conn, $insert_query, $insert_params);

        if ($insert_result) {
            $message = "<p style='color:green;'>✅ Enrollment request submitted, pending admin approval.</p>";
        } else {
            error_log("Enrollment error: " . print_r(sqlsrv_errors(), true));
            $message = "<p style='color:red;'>❌ Failed to enroll: " . print_r(sqlsrv_errors(), true) . "</p>";
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
    $update_query = "UPDATE dbo.Enrollments SET Status = ?, RequestReason = ? WHERE EnrollmentID = ? AND StudentID = ?";
    $update_params = array($status, $reason, $enrollment_id, $student_id);
    $update_result = sqlsrv_query($conn, $update_query, $update_params);

    if ($update_result) {
        $message = "<p style='color:green;'>✅ Drop request submitted, pending admin approval.</p>";
    } else {
        error_log("Drop error: " . print_r(sqlsrv_errors(), true));
        $message = "<p style='color:red;'>❌ Failed to submit drop request: " . print_r(sqlsrv_errors(), true) . "</p>";
    }
}

// Fetch available courses with enrollment status
$course_query = "
    SELECT c.CourseID, c.CourseCode, c.CourseName, c.Description, c.Credits, e.EnrollmentID, e.Status
    FROM dbo.Courses c
    LEFT JOIN dbo.Enrollments e ON c.CourseID = e.CourseID AND e.StudentID = ?
    WHERE c.IsActive = 1
    AND c.AcademicYearID = (SELECT TOP 1 AcademicYearID FROM dbo.Academic_Years WHERE IsActive = 1)
    AND (e.Status IS NULL OR e.Status != 'Dropped')
";
$course_params = array($student_id);
$course_result = sqlsrv_query($conn, $course_query, $course_params);

if ($course_result === false) {
    error_log("Course query error: " . print_r(sqlsrv_errors(), true));
    die("<p style='color:red;'>❌ Database error: " . print_r(sqlsrv_errors(), true) . "</p>");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Course Enrollment - Attendance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        .card { margin-bottom: 20px; }
        .table-responsive { margin-top: 20px; }
        .alert { margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="h2"><i class="fas fa-book me-2"></i>Course Enrollment</h1>
            <a href="http://127.0.0.1:8000/student-dashboard/?username=<?php echo urlencode($username); ?>" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
            </a>
        </div>

        <!-- Display messages -->
        <?php if (isset($message)) echo $message; ?>

        <!-- Available Courses -->
        <div class="card">
            <div class="card-header">
                <h5>Available Courses</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
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
                                            <span class="text-muted">Enrolled (Active)</span>
                                            <button type="button" class="btn btn-outline-danger btn-sm ms-2" data-bs-toggle="modal" data-bs-target="#dropModal<?php echo $course['EnrollmentID']; ?>">
                                                <i class="fas fa-minus-circle me-2"></i>Drop
                                            </button>
                                            <!-- Drop Course Modal -->
                                            <div class="modal fade" id="dropModal<?php echo $course['EnrollmentID']; ?>" tabindex="-1" aria-labelledby="dropModalLabel<?php echo $course['EnrollmentID']; ?>" aria-hidden="true">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title" id="dropModalLabel<?php echo $course['EnrollmentID']; ?>">Drop Course</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                        </div>
                                                        <form method="POST" action="enrol.php?username=<?php echo urlencode($username); ?>">
                                                            <div class="modal-body">
                                                                <div class="mb-3">
                                                                    <label for="dropSubject<?php echo $course['EnrollmentID']; ?>" class="form-label">Subject</label>
                                                                    <input type="text" class="form-control" id="dropSubject<?php echo $course['EnrollmentID']; ?>" value="<?php echo htmlspecialchars($course['CourseCode'] . ' - ' . $course['CourseName']); ?>" readonly>
                                                                    <input type="hidden" name="enrollment_id" value="<?php echo $course['EnrollmentID']; ?>">
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label for="dropReason<?php echo $course['EnrollmentID']; ?>" class="form-label">Reason for Dropping</label>
                                                                    <textarea class="form-control" id="dropReason<?php echo $course['EnrollmentID']; ?>" name="reason" rows="4" required></textarea>
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
                                            <span class="text-muted">Pending Enroll (Awaiting Admin Approval)</span>
                                        <?php } elseif ($course['EnrollmentID'] && $course['Status'] === 'Pending Drop') { ?>
                                            <span class="text-muted">Pending Drop (Awaiting Admin Approval)</span>
                                        <?php } elseif ($course['EnrollmentID']) { ?>
                                            <span class="text-muted">Enrolled (<?php echo htmlspecialchars($course['Status']); ?>)</span>
                                        <?php } else { ?>
                                            <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#enrollModal<?php echo $course['CourseID']; ?>">
                                                <i class="fas fa-plus-circle me-2"></i>Enroll
                                            </button>
                                            <!-- Enroll Course Modal -->
                                            <div class="modal fade" id="enrollModal<?php echo $course['CourseID']; ?>" tabindex="-1" aria-labelledby="enrollModalLabel<?php echo $course['CourseID']; ?>" aria-hidden="true">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title" id="enrollModalLabel<?php echo $course['CourseID']; ?>">Enroll in Course</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                        </div>
                                                        <form method="POST" action="enrol.php?username=<?php echo urlencode($username); ?>">
                                                            <div class="modal-body">
                                                                <div class="mb-3">
                                                                    <label for="enrollSubject<?php echo $course['CourseID']; ?>" class="form-label">Subject</label>
                                                                    <input type="text" class="form-control" id="enrollSubject<?php echo $course['CourseID']; ?>" value="<?php echo htmlspecialchars($course['CourseCode'] . ' - ' . $course['CourseName']); ?>" readonly>
                                                                    <input type="hidden" name="course_id" value="<?php echo $course['CourseID']; ?>">
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label for="enrollReason<?php echo $course['CourseID']; ?>" class="form-label">Reason for Enrollment</label>
                                                                    <textarea class="form-control" id="enrollReason<?php echo $course['CourseID']; ?>" name="reason" rows="4" required></textarea>
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
                            <?php if (sqlsrv_num_rows($course_result) === 0) { ?>
                                <tr><td colspan="5" class="text-center text-muted">No courses available.</td></tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php
// Clean up
sqlsrv_free_stmt($course_result);
sqlsrv_free_stmt($student_result);
sqlsrv_close($conn);
?>