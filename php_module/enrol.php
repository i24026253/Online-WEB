<?php
// Include the database connection
require_once 'db_connect.php'; // Your existing connection file

// Start or resume session to get the logged-in student's username
session_start();
$username = isset($_GET['username']) ? $_GET['username'] : null;
if (!$username) {
    die("<p style='color:red;'>❌ Please log in to enroll in courses.</p>");
}

if (!$username) {
    die("<p style='color:red;'>❌ Please log in to enroll in courses.</p>");
}

// Fetch student ID based on username
$student_query = "SELECT StudentID FROM dbo.Students WHERE UserID = (SELECT UserID FROM dbo.Users WHERE Username = ?)";
$params = array($username);
$student_result = sqlsrv_query($conn, $student_query, $params);

if ($student_result === false) {
    die(print_r(sqlsrv_errors(), true));
}

$student_row = sqlsrv_fetch_array($student_result, SQLSRV_FETCH_ASSOC);
$student_id = $student_row ? $student_row['StudentID'] : null;

if (!$student_id) {
    die("<p style='color:red;'>❌ Student not found.</p>");
}

// Handle form submission for enrollment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['course_id'])) {
    $course_id = $_POST['course_id'];
    $enrollment_date = date('Y-m-d H:i:s');
    $status = 'Enrolled';

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
            $message = "<p style='color:red;'>❌ Failed to enroll: " . print_r(sqlsrv_errors(), true) . "</p>";
        }
    }
}

// Fetch available courses
$course_query = "
    SELECT CourseID, CourseCode, CourseName, Description, Credits
    FROM dbo.Courses
    WHERE IsActive = 1
    AND AcademicYearID = (SELECT TOP 1 AcademicYearID FROM dbo.Academic_Years WHERE IsActive = 1)
";
$course_result = sqlsrv_query($conn, $course_query);

if ($course_result === false) {
    die(print_r(sqlsrv_errors(), true));
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
            <a href="http://localhost/dashboard/student_dashboard.html" class="btn btn-outline-primary btn-sm">
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
                                        <form method="POST" action="enroll.php">
                                            <input type="hidden" name="course_id" value="<?php echo $course['CourseID']; ?>">
                                            <button type="submit" class="btn btn-success btn-sm">
                                                <i class="fas fa-plus-circle me-2"></i>Enroll
                                            </button>
                                        </form>
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