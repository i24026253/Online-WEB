<?php

require_once 'header.php';
require_once 'connect.php';

$username = isset($_GET['username']) ? $_GET['username'] : null;

if (!$username) {
    die("<p style='color:red;'>Please log in to enroll in courses.</p>");
}

// Fetch student ID and role
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
    die("<p style='color:red;'>Database error: " . print_r(sqlsrv_errors(), true) . "</p>");
}

$student_row = sqlsrv_fetch_array($student_result, SQLSRV_FETCH_ASSOC);
$student_id = $student_row ? $student_row['StudentID'] : null;
$user_role = $student_row ? strtolower($student_row['Role']) : 'student';

if (!$student_id) {
    die("<p style='color:red;'>Student not found.</p>");
}

// Handle enrollment submission (NO RequestReason)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enroll_submit'])) {
    $course_id = $_POST['course_id'];
    $enrollment_date = date('Y-m-d H:i:s');
    $status = 'Pending Enroll';

    // Check duplicate
    $check_query = "SELECT COUNT(*) as count FROM dbo.Enrollments WHERE StudentID = ? AND CourseID = ? AND Status IN ('Active', 'Pending Enroll', 'Pending Drop')";
    $check_params = array($student_id, $course_id);
    $check_result = sqlsrv_query($conn, $check_query, $check_params);
    $check_row = sqlsrv_fetch_array($check_result, SQLSRV_FETCH_ASSOC);

    if ($check_row['count'] > 0) {
        $message = "<div class='alert alert-warning'>You already have an active or pending enrollment for this course.</div>";
    } else {
        // INSERT: Only existing fields
        $insert_query = "INSERT INTO dbo.Enrollments (StudentID, CourseID, EnrollmentDate, Status) VALUES (?, ?, ?, ?)";
        $insert_params = array($student_id, $course_id, $enrollment_date, $status);
        $insert_result = sqlsrv_query($conn, $insert_query, $insert_params);

        if ($insert_result) {
            $message = "<div class='alert alert-success'>Enrollment request submitted, pending approval.</div>";
        } else {
            $message = "<div class='alert alert-danger'>Failed to enroll: " . print_r(sqlsrv_errors(), true) . "</div>";
        }
    }
}

// Handle drop submission (NO RequestReason, NO DropRejectionReason)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['drop_submit'])) {
    $enrollment_id = $_POST['enrollment_id'];
    $status = 'Pending Drop';

    // UPDATE: Only Status
    $update_query = "UPDATE dbo.Enrollments SET Status = ? WHERE EnrollmentID = ? AND StudentID = ?";
    $update_params = array($status, $enrollment_id, $student_id);
    $update_result = sqlsrv_query($conn, $update_query, $update_params);

    if ($update_result) {
        $message = "<div class='alert alert-success'>Drop request submitted, pending approval.</div>";
    } else {
        $message = "<div class='alert alert-danger'>Failed to submit drop request: " . print_r(sqlsrv_errors(), true) . "</div>";
    }
}

// Fetch courses (ONLY existing fields)
$course_query = "
    SELECT 
        c.CourseID, c.CourseCode, c.CourseName, c.Description, c.Credits,
        e.EnrollmentID, e.Status, e.EnrollmentDate
    FROM dbo.Courses c
    LEFT JOIN (
        SELECT EnrollmentID, StudentID, CourseID, Status, EnrollmentDate,
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
    die("<p style='color:red;'>Database error: " . print_r(sqlsrv_errors(), true) . "</p>");
}

renderHeader($username, $user_role);
?>

<div class="mb-4">
    <h1 class="h2">Course Enrollment</h1>
</div>

<?php if (isset($message)) echo $message; ?>

<div class="card">
    <div class="card-header bg-white">
        <h5 class="mb-0">Available Courses</h5>
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
                    <?php while ($course = sqlsrv_fetch_array($course_result, SQLSRV_FETCH_ASSOC)): ?>
                        <tr>
                            <td><?= htmlspecialchars($course['CourseCode']) ?></td>
                            <td><?= htmlspecialchars($course['CourseName']) ?></td>
                            <td><?= htmlspecialchars($course['Description'] ?? 'No description') ?></td>
                            <td><?= htmlspecialchars($course['Credits']) ?></td>
                            <td>
                                <?php if ($course['EnrollmentID'] && $course['Status'] === 'Active'): ?>
                                    <span class="badge bg-success">Enrolled</span>
                                    <button type="button" class="btn btn-outline-danger btn-sm mt-1" data-bs-toggle="modal" data-bs-target="#dropModal<?= $course['EnrollmentID'] ?>">
                                        Drop
                                    </button>

                                    <!-- Drop Modal -->
                                    <div class="modal fade" id="dropModal<?= $course['EnrollmentID'] ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Drop Course</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form method="POST" action="enrol.php?username=<?= urlencode($username) ?>">
                                                    <div class="modal-body">
                                                        <input type="hidden" name="enrollment_id" value="<?= $course['EnrollmentID'] ?>">
                                                        <p>Are you sure you want to drop <strong><?= htmlspecialchars($course['CourseName']) ?></strong>?</p>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" name="drop_submit" class="btn btn-danger">Drop Course</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                <?php elseif ($course['EnrollmentID'] && in_array($course['Status'], ['Pending Enroll', 'Pending Drop'])): ?>
                                    <span class="badge bg-warning text-dark"><?= ucfirst(str_replace('_', ' ', $course['Status'])) ?></span>

                                <?php elseif ($course['EnrollmentID'] && in_array($course['Status'], ['Dropped', 'Rejected'])): ?>
                                    <span class="badge bg-secondary"><?= $course['Status'] ?></span>
                                    <button type="button" class="btn btn-success btn-sm ms-2" data-bs-toggle="modal" data-bs-target="#enrollModal<?= $course['CourseID'] ?>">
                                        Re-enroll
                                    </button>

                                <?php elseif ($course['EnrollmentID']): ?>
                                    <span class="badge bg-secondary"><?= htmlspecialchars($course['Status']) ?></span>

                                <?php else: ?>
                                    <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#enrollModal<?= $course['CourseID'] ?>">
                                        Enroll
                                    </button>
                                <?php endif; ?>

                                <!-- Enroll Modal -->
                                <?php if (!$course['EnrollmentID'] || in_array($course['Status'], ['Dropped', 'Rejected'])): ?>
                                <div class="modal fade" id="enrollModal<?= $course['CourseID'] ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Enroll in Course</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form method="POST" action="enrol.php?username=<?= urlencode($username) ?>">
                                                <div class="modal-body">
                                                    <input type="hidden" name="course_id" value="<?= $course['CourseID'] ?>">
                                                    <p>Enroll in <strong><?= htmlspecialchars($course['CourseName']) ?></strong>?</p>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" name="enroll_submit" class="btn btn-success">Submit Request</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
renderFooter();
sqlsrv_free_stmt($course_result);
sqlsrv_free_stmt($student_result);
sqlsrv_close($conn);
?>