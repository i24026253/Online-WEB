<?php
// Include header component
require_once 'header.php';

// Include the database connection
require_once 'connect.php';

// Get username from URL
$username = isset($_GET['username']) ? $_GET['username'] : null;

if (!$username) {
    die("<p style='color:red;'>❌ Please log in as an admin.</p>");
}

// Debug: Log username
error_log("Admin Username: $username");

// Verify admin role and get user info
$admin_query = "SELECT u.UserID, u.Role FROM dbo.Users u WHERE u.Username = ?";
$params = array($username);
$admin_result = sqlsrv_query($conn, $admin_query, $params);

if ($admin_result === false) {
    error_log("Admin query error: " . print_r(sqlsrv_errors(), true));
    die("<p style='color:red;'>❌ Database error: " . print_r(sqlsrv_errors(), true) . "</p>");
}

$admin_row = sqlsrv_fetch_array($admin_result, SQLSRV_FETCH_ASSOC);
if (!$admin_row || $admin_row['Role'] !== 'Admin') {
    error_log("User $username is not an admin");
    die("<p style='color:red;'>❌ Access denied: Admin privileges required.</p>");
}

// Set user role for header
$user_role = strtolower($admin_row['Role']);

// Handle add course
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_course'])) {
    $course_code = $_POST['course_code'];
    $course_name = $_POST['course_name'];
    $description = $_POST['description'] ?: null;
    $credits = (int)$_POST['credits'];
    $department = $_POST['department'];
    $academic_year_id = (int)$_POST['academic_year_id'];
    $created_date = date('Y-m-d H:i:s');

    $insert_query = "INSERT INTO dbo.Courses (CourseCode, CourseName, Description, Credits, Department, AcademicYearID, IsActive, CreatedDate) VALUES (?, ?, ?, ?, ?, ?, 1, ?)";
    $insert_params = array($course_code, $course_name, $description, $credits, $department, $academic_year_id, $created_date);
    $insert_result = sqlsrv_query($conn, $insert_query, $insert_params);

    if ($insert_result) {
        $message = "<div class='alert alert-success alert-dismissible fade show' role='alert'><i class='fas fa-check-circle me-2'></i>Course added successfully.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
    } else {
        error_log("Add course error: " . print_r(sqlsrv_errors(), true));
        $message = "<div class='alert alert-danger alert-dismissible fade show' role='alert'><i class='fas fa-times-circle me-2'></i>Failed to add course: " . print_r(sqlsrv_errors(), true) . "<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
    }
}

// Handle update course
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_course'])) {
    $course_id = (int)$_POST['course_id'];
    $course_code = $_POST['course_code'];
    $course_name = $_POST['course_name'];
    $description = $_POST['description'] ?: null;
    $credits = (int)$_POST['credits'];
    $department = $_POST['department'];
    $academic_year_id = (int)$_POST['academic_year_id'];
    $updated_date = date('Y-m-d H:i:s');

    $update_query = "UPDATE dbo.Courses SET CourseCode = ?, CourseName = ?, Description = ?, Credits = ?, Department = ?, AcademicYearID = ?, UpdatedDate = ? WHERE CourseID = ?";
    $update_params = array($course_code, $course_name, $description, $credits, $department, $academic_year_id, $updated_date, $course_id);
    $update_result = sqlsrv_query($conn, $update_query, $update_params);

    if ($update_result) {
        $message = "<div class='alert alert-success alert-dismissible fade show' role='alert'><i class='fas fa-check-circle me-2'></i>Course updated successfully.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
    } else {
        error_log("Update course error: " . print_r(sqlsrv_errors(), true));
        $message = "<div class='alert alert-danger alert-dismissible fade show' role='alert'><i class='fas fa-times-circle me-2'></i>Failed to update course: " . print_r(sqlsrv_errors(), true) . "<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
    }
}

// Handle toggle active status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_active'])) {
    $course_id = (int)$_POST['course_id'];
    $new_status = (int)$_POST['new_status'];

    // Check for active enrollments if deactivating
    if ($new_status === 0) {
        $enrollment_query = "SELECT COUNT(*) as enrollment_count FROM dbo.Enrollments WHERE CourseID = ? AND Status = 'Active'";
        $enrollment_params = array($course_id);
        $enrollment_result = sqlsrv_query($conn, $enrollment_query, $enrollment_params);

        if ($enrollment_result === false) {
            error_log("Enrollment check error: " . print_r(sqlsrv_errors(), true));
            $message = "<div class='alert alert-danger alert-dismissible fade show' role='alert'><i class='fas fa-times-circle me-2'></i>Database error: " . print_r(sqlsrv_errors(), true) . "<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        } else {
            $enrollment_row = sqlsrv_fetch_array($enrollment_result, SQLSRV_FETCH_ASSOC);
            if ($enrollment_row['enrollment_count'] > 0) {
                $message = "<div class='alert alert-danger alert-dismissible fade show' role='alert'><i class='fas fa-exclamation-triangle me-2'></i>Cannot deactivate course: It has active student enrollments.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
                sqlsrv_free_stmt($enrollment_result);
            } else {
                sqlsrv_free_stmt($enrollment_result);
                $update_query = "UPDATE dbo.Courses SET IsActive = ?, UpdatedDate = ? WHERE CourseID = ?";
                $update_params = array($new_status, date('Y-m-d H:i:s'), $course_id);
                $update_result = sqlsrv_query($conn, $update_query, $update_params);

                if ($update_result) {
                    $message = "<div class='alert alert-success alert-dismissible fade show' role='alert'><i class='fas fa-check-circle me-2'></i>Course status updated successfully.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
                } else {
                    error_log("Toggle active error: " . print_r(sqlsrv_errors(), true));
                    $message = "<div class='alert alert-danger alert-dismissible fade show' role='alert'><i class='fas fa-times-circle me-2'></i>Failed to update course status: " . print_r(sqlsrv_errors(), true) . "<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
                }
            }
        }
    } else {
        // Activate course
        $update_query = "UPDATE dbo.Courses SET IsActive = ?, UpdatedDate = ? WHERE CourseID = ?";
        $update_params = array($new_status, date('Y-m-d H:i:s'), $course_id);
        $update_result = sqlsrv_query($conn, $update_query, $update_params);

        if ($update_result) {
            $message = "<div class='alert alert-success alert-dismissible fade show' role='alert'><i class='fas fa-check-circle me-2'></i>Course status updated successfully.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        } else {
            error_log("Toggle active error: " . print_r(sqlsrv_errors(), true));
            $message = "<div class='alert alert-danger alert-dismissible fade show' role='alert'><i class='fas fa-times-circle me-2'></i>Failed to update course status: " . print_r(sqlsrv_errors(), true) . "<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        }
    }
}

// Handle assign/reassign lecturer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_lecturer'])) {
    $course_id = (int)$_POST['course_id'];
    $lecturer_id = (int)$_POST['lecturer_id'];
    $assigned_date = date('Y-m-d H:i:s');

    // Check if the selected lecturer is already actively assigned to the course
    $current_assignment_query = "SELECT LecturerID FROM dbo.Course_Assignments WHERE CourseID = ? AND IsActive = 1";
    $current_assignment_params = array($course_id);
    $current_assignment_result = sqlsrv_query($conn, $current_assignment_query, $current_assignment_params);

    if ($current_assignment_result === false) {
        error_log("Check current assignment error: " . print_r(sqlsrv_errors(), true));
        $message = "<div class='alert alert-danger alert-dismissible fade show' role='alert'><i class='fas fa-times-circle me-2'></i>Failed to assign lecturer: " . print_r(sqlsrv_errors(), true) . "<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
    } else {
        $current_assignment = sqlsrv_fetch_array($current_assignment_result, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($current_assignment_result);

        if ($current_assignment && $current_assignment['LecturerID'] == $lecturer_id) {
            $message = "<div class='alert alert-warning alert-dismissible fade show' role='alert'><i class='fas fa-exclamation-triangle me-2'></i>Cannot reassign the same lecturer.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        } else {
            // Check if an inactive assignment exists for this CourseID and LecturerID
            $check_query = "SELECT AssignmentID FROM dbo.Course_Assignments WHERE CourseID = ? AND LecturerID = ? AND IsActive = 0";
            $check_params = array($course_id, $lecturer_id);
            $check_result = sqlsrv_query($conn, $check_query, $check_params);

            if ($check_result === false) {
                error_log("Check assignment error: " . print_r(sqlsrv_errors(), true));
                $message = "<div class='alert alert-danger alert-dismissible fade show' role='alert'><i class='fas fa-times-circle me-2'></i>Failed to assign lecturer: " . print_r(sqlsrv_errors(), true) . "<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
            } else {
                $existing_assignment = sqlsrv_fetch_array($check_result, SQLSRV_FETCH_ASSOC);
                sqlsrv_free_stmt($check_result);

                if ($existing_assignment) {
                    // Reactivate existing inactive assignment
                    $reactivate_query = "UPDATE dbo.Course_Assignments SET IsActive = 1, AssignedDate = ? WHERE AssignmentID = ?";
                    $reactivate_params = array($assigned_date, $existing_assignment['AssignmentID']);
                    $reactivate_result = sqlsrv_query($conn, $reactivate_query, $reactivate_params);

                    if ($reactivate_result) {
                        // Deactivate any other active assignment for the course
                        $deactivate_query = "UPDATE dbo.Course_Assignments SET IsActive = 0 WHERE CourseID = ? AND AssignmentID != ? AND IsActive = 1";
                        $deactivate_params = array($course_id, $existing_assignment['AssignmentID']);
                        $deactivate_result = sqlsrv_query($conn, $deactivate_query, $deactivate_params);

                        if ($deactivate_result === false) {
                            error_log("Deactivate other assignments error: " . print_r(sqlsrv_errors(), true));
                        }
                        $message = "<div class='alert alert-success alert-dismissible fade show' role='alert'><i class='fas fa-check-circle me-2'></i>Lecturer assigned successfully.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
                    } else {
                        error_log("Reactivate assignment error: " . print_r(sqlsrv_errors(), true));
                        $message = "<div class='alert alert-danger alert-dismissible fade show' role='alert'><i class='fas fa-times-circle me-2'></i>Failed to assign lecturer: " . print_r(sqlsrv_errors(), true) . "<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
                    }
                } else {
                    // Deactivate existing active assignment
                    $deactivate_query = "UPDATE dbo.Course_Assignments SET IsActive = 0 WHERE CourseID = ? AND IsActive = 1";
                    $deactivate_params = array($course_id);
                    $deactivate_result = sqlsrv_query($conn, $deactivate_query, $deactivate_params);

                    if ($deactivate_result === false) {
                        error_log("Deactivate assignment error: " . print_r(sqlsrv_errors(), true));
                    }

                    // Insert new assignment
                    $insert_query = "INSERT INTO dbo.Course_Assignments (CourseID, LecturerID, AssignedDate, IsActive) VALUES (?, ?, ?, 1)";
                    $insert_params = array($course_id, $lecturer_id, $assigned_date);
                    $insert_result = sqlsrv_query($conn, $insert_query, $insert_params);

                    if ($insert_result) {
                        $message = "<div class='alert alert-success alert-dismissible fade show' role='alert'><i class='fas fa-check-circle me-2'></i>Lecturer assigned successfully.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
                    } else {
                        error_log("Assign lecturer error: " . print_r(sqlsrv_errors(), true));
                        $message = "<div class='alert alert-danger alert-dismissible fade show' role='alert'><i class='fas fa-times-circle me-2'></i>Failed to assign lecturer: " . print_r(sqlsrv_errors(), true) . "<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
                    }
                }
            }
        }
    }
}

// Fetch all courses (active and inactive) with lecturer assignments
$courses_query = "
    SELECT c.CourseID, c.CourseCode, c.CourseName, c.Description, c.Credits, c.Department, c.AcademicYearID, c.IsActive,
           u.FirstName, u.LastName, ay.YearName
    FROM dbo.Courses c
    LEFT JOIN dbo.Course_Assignments ca ON c.CourseID = ca.CourseID AND ca.IsActive = 1
    LEFT JOIN dbo.Lecturers l ON ca.LecturerID = l.LecturerID
    LEFT JOIN dbo.Users u ON l.UserID = u.UserID
    LEFT JOIN dbo.Academic_Years ay ON c.AcademicYearID = ay.AcademicYearID
    ORDER BY c.CourseCode
";
$courses_result = sqlsrv_query($conn, $courses_query);

if ($courses_result === false) {
    error_log("Courses query error: " . print_r(sqlsrv_errors(), true));
    die("<p style='color:red;'>❌ Database error: " . print_r(sqlsrv_errors(), true) . "</p>");
}

// Fetch active academic years and store in array
$academic_years_query = "SELECT AcademicYearID, YearName FROM dbo.Academic_Years WHERE IsActive = 1";
$academic_years_result = sqlsrv_query($conn, $academic_years_query);
$academic_years = [];
if ($academic_years_result) {
    while ($year = sqlsrv_fetch_array($academic_years_result, SQLSRV_FETCH_ASSOC)) {
        $academic_years[] = $year;
    }
    sqlsrv_free_stmt($academic_years_result);
} else {
    error_log("Academic years query error: " . print_r(sqlsrv_errors(), true));
    die("<p style='color:red;'>❌ Database error: " . print_r(sqlsrv_errors(), true) . "</p>");
}

// Fetch lecturers and store in array
$lecturers_query = "
    SELECT l.LecturerID, u.FirstName, u.LastName
    FROM dbo.Lecturers l
    JOIN dbo.Users u ON l.UserID = u.UserID
    WHERE u.Role = 'Lecturer'
";
$lecturers_result = sqlsrv_query($conn, $lecturers_query);
$lecturers = [];
if ($lecturers_result) {
    while ($lecturer = sqlsrv_fetch_array($lecturers_result, SQLSRV_FETCH_ASSOC)) {
        $lecturers[] = $lecturer;
    }
    sqlsrv_free_stmt($lecturers_result);
} else {
    error_log("Lecturers query error: " . print_r(sqlsrv_errors(), true));
    die("<p style='color:red;'>❌ Database error: " . print_r(sqlsrv_errors(), true) . "</p>");
}

// Render header with navigation
renderHeader($username, $user_role, 'courses');
?>

<!-- Page Content Starts Here -->
<div class="mb-4">
    <h1 class="h2"><i class="fas fa-book me-2"></i>Course Management</h1>
</div>

<!-- Display messages -->
<?php if (isset($message)) echo $message; ?>

<!-- Add Course Button -->
<button type="button" class="btn btn-success mb-3" data-bs-toggle="modal" data-bs-target="#addCourseModal">
    <i class="fas fa-plus-circle me-2"></i>Add Course
</button>

<!-- Courses Table -->
<div class="card">
    <div class="card-header bg-white">
        <h5 class="mb-0"><i class="fas fa-list me-2"></i>All Courses</h5>
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
                        <th>Department</th>
                        <th>Academic Year</th>
                        <th>Lecturer</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($course = sqlsrv_fetch_array($courses_result, SQLSRV_FETCH_ASSOC)) { ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($course['CourseCode']); ?></strong></td>
                            <td><?php echo htmlspecialchars($course['CourseName']); ?></td>
                            <td><?php echo htmlspecialchars($course['Description'] ?? 'No description'); ?></td>
                            <td><?php echo htmlspecialchars($course['Credits']); ?></td>
                            <td><?php echo htmlspecialchars($course['Department']); ?></td>
                            <td><?php echo htmlspecialchars($course['YearName'] ?? $course['AcademicYearID']); ?></td>
                            <td>
                                <?php if ($course['FirstName']) { ?>
                                    <span class="badge bg-info text-dark">
                                        <?php echo htmlspecialchars($course['FirstName'] . ' ' . $course['LastName']); ?>
                                    </span>
                                <?php } else { ?>
                                    <span class="text-muted">Not Assigned</span>
                                <?php } ?>
                                <button type="button" class="btn btn-outline-primary btn-sm ms-2" data-bs-toggle="modal" data-bs-target="#assignModal<?php echo $course['CourseID']; ?>">
                                    <i class="fas fa-user-<?php echo $course['FirstName'] ? 'edit' : 'plus'; ?> me-1"></i>
                                    <?php echo $course['FirstName'] ? 'Reassign' : 'Assign'; ?>
                                </button>
                            </td>
                            <td>
                                <span class="badge bg-<?php echo $course['IsActive'] ? 'success' : 'secondary'; ?>">
                                    <?php echo $course['IsActive'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                            <td>
                                <div class="btn-group" role="group">
                                    <button type="button" class="btn btn-outline-warning btn-sm" data-bs-toggle="modal" data-bs-target="#editCourseModal<?php echo $course['CourseID']; ?>">
                                        <i class="fas fa-edit me-1"></i>Edit
                                    </button>
                                    <form method="POST" action="courses.php?username=<?php echo urlencode($username); ?>" style="display:inline;">
                                        <input type="hidden" name="course_id" value="<?php echo $course['CourseID']; ?>">
                                        <input type="hidden" name="new_status" value="<?php echo $course['IsActive'] ? 0 : 1; ?>">
                                        <button type="submit" name="toggle_active" class="btn btn-outline-<?php echo $course['IsActive'] ? 'danger' : 'success'; ?> btn-sm" onclick="return confirm('Are you sure you want to <?php echo $course['IsActive'] ? 'deactivate' : 'activate'; ?> this course?');">
                                            <i class="fas fa-<?php echo $course['IsActive'] ? 'times' : 'check'; ?> me-1"></i>
                                            <?php echo $course['IsActive'] ? 'Deactivate' : 'Activate'; ?>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        
                        <!-- Edit Course Modal -->
                        <div class="modal fade" id="editCourseModal<?php echo $course['CourseID']; ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Course</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <form method="POST" action="courses.php?username=<?php echo urlencode($username); ?>">
                                        <div class="modal-body">
                                            <input type="hidden" name="course_id" value="<?php echo $course['CourseID']; ?>">
                                            <div class="mb-3">
                                                <label class="form-label">Course Code <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" name="course_code" value="<?php echo htmlspecialchars($course['CourseCode']); ?>" required>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Course Name <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" name="course_name" value="<?php echo htmlspecialchars($course['CourseName']); ?>" required>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Description</label>
                                                <textarea class="form-control" name="description" rows="3"><?php echo htmlspecialchars($course['Description'] ?? ''); ?></textarea>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Credits <span class="text-danger">*</span></label>
                                                <input type="number" class="form-control" name="credits" value="<?php echo htmlspecialchars($course['Credits']); ?>" required min="1">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Department <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" name="department" value="<?php echo htmlspecialchars($course['Department']); ?>" required>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Academic Year <span class="text-danger">*</span></label>
                                                <select class="form-control" name="academic_year_id" required>
                                                    <?php foreach ($academic_years as $year) { ?>
                                                        <option value="<?php echo $year['AcademicYearID']; ?>" <?php echo $year['AcademicYearID'] == $course['AcademicYearID'] ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($year['YearName']); ?>
                                                        </option>
                                                    <?php } ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" name="update_course" class="btn btn-warning">Update Course</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Assign/Reassign Lecturer Modal -->
                        <div class="modal fade" id="assignModal<?php echo $course['CourseID']; ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">
                                            <i class="fas fa-user-<?php echo $course['FirstName'] ? 'edit' : 'plus'; ?> me-2"></i>
                                            <?php echo $course['FirstName'] ? 'Reassign' : 'Assign'; ?> Lecturer to <?php echo htmlspecialchars($course['CourseCode']); ?>
                                        </h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <form method="POST" action="courses.php?username=<?php echo urlencode($username); ?>">
                                        <div class="modal-body">
                                            <input type="hidden" name="course_id" value="<?php echo $course['CourseID']; ?>">
                                            <div class="mb-3">
                                                <label class="form-label">Select Lecturer <span class="text-danger">*</span></label>
                                                <select class="form-control" name="lecturer_id" required>
                                                    <option value="">-- Select Lecturer --</option>
                                                    <?php foreach ($lecturers as $lecturer) { ?>
                                                        <option value="<?php echo $lecturer['LecturerID']; ?>" 
                                                            <?php echo ($course['FirstName'] && $lecturer['FirstName'] == $course['FirstName'] && $lecturer['LastName'] == $course['LastName']) ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($lecturer['FirstName'] . ' ' . $lecturer['LastName']); ?>
                                                        </option>
                                                    <?php } ?>
                                                </select>
                                            </div>
                                            <?php if ($course['FirstName']) { ?>
                                                <div class="alert alert-info">
                                                    <i class="fas fa-info-circle me-2"></i>
                                                    Current lecturer: <strong><?php echo htmlspecialchars($course['FirstName'] . ' ' . $course['LastName']); ?></strong>
                                                </div>
                                            <?php } ?>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" name="assign_lecturer" class="btn btn-primary">
                                                <?php echo $course['FirstName'] ? 'Reassign' : 'Assign'; ?> Lecturer
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Course Modal -->
<div class="modal fade" id="addCourseModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Add New Course</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="courses.php?username=<?php echo urlencode($username); ?>">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Course Code <span class="text-danger">*</span></label>
                        <input type="text"class="form-control" name="course_code" placeholder="e.g., CS101" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Course Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="course_name" placeholder="e.g., Introduction to Computer Science" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="3" placeholder="Course description (optional)"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Credits <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" name="credits" placeholder="e.g., 3" required min="1">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Department <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="department" placeholder="e.g., Computer Science" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Academic Year <span class="text-danger">*</span></label>
                        <select class="form-control" name="academic_year_id" required>
                            <option value="">-- Select Academic Year --</option>
                            <?php foreach ($academic_years as $year) { ?>
                                <option value="<?php echo $year['AcademicYearID']; ?>">
                                    <?php echo htmlspecialchars($year['YearName']); ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_course" class="btn btn-success">
                        <i class="fas fa-plus-circle me-2"></i>Add Course
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
// Render footer
renderFooter();

// Clean up
sqlsrv_free_stmt($courses_result);
sqlsrv_free_stmt($admin_result);
sqlsrv_close($conn);
?>