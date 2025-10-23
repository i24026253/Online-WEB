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

// Handle form submission for enrollment
// Handle form submission for enrollment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['course_id'])) {
    $course_id = $_POST['course_id'];
    $enrollment_date = date('Y-m-d H:i:s');
    $status = 'Active'; // Changed from 'Enrolled' to comply with CHECK constraint

    // Debug: Log enrollment attempt
    error_log("Enrolling StudentID: $student_id in CourseID: $course_id");

    // Check if already enrolled
    $check_query = "SELECT COUNT(*) as count FROM dbo.Enrollments WHERE StudentID = ? AND CourseID = ?";
    $check_params = array($student_id, $course_id);
    $check_result = sqlsrv_query($conn, $check_query, $check_params);
    $check_row = sqlsrv_fetch_array($check_result, SQLSRV_FETCH_ASSOC);

    if ($check_row['count'] > 0) {
        $message = "<p style='color:orange;'>⚠️ You are already enrolled in this course.</p>";
    } else {
        // Insert enrollment
        $insert_query = "INSERT INTO dbo.Enrollments (StudentID, CourseID, EnrollmentDate, Status) VALUES (?, ?, ?, ?)";
        $insert_params = array($student_id, $course_id, $enrollment_date, $status);
        $insert_result = sqlsrv_query($conn, $insert_query, $insert_params);

        if ($insert_result) {
            $message = "<p style='color:green;'>✅ Successfully enrolled in the course!</p>";
        } else {
            error_log("Enrollment error: " . print_r(sqlsrv_errors(), true));
            $message = "<p style='color:red;'>❌ Failed to enroll: " . print_r(sqlsrv_errors(), true) . "</p>";
        }
    }
}

// Fetch available courses with enrollment status
$course_query = "
    SELECT c.CourseID, c.CourseCode, c.CourseName, c.Description, c.Credits,
           CASE WHEN e.StudentID IS NOT NULL THEN 1 ELSE 0 END AS IsEnrolled
    FROM dbo.Courses c
    LEFT JOIN dbo.Enrollments e ON c.CourseID = e.CourseID AND e.StudentID = ?
    WHERE c.IsActive = 1
    AND c.AcademicYearID = (SELECT TOP 1 AcademicYearID FROM dbo.Academic_Years WHERE IsActive = 1)
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
                                        <?php if ($course['IsEnrolled'] == 1) { ?>
                                            <span class="text-muted">Already Enrolled</span>
                                        <?php } else { ?>
                                            <form method="POST" action="enrol.php?username=<?php echo urlencode($username); ?>">
                                                <input type="hidden" name="course_id" value="<?php echo $course['CourseID']; ?>">
                                                <button type="submit" class="btn btn-success btn-sm">
                                                    <i class="fas fa-plus-circle me-2"></i>Enroll
                                                </button>
                                            </form>
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