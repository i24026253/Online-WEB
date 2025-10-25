<?php
// Include the database connection
require_once 'connect.php';

// Get username from URL
$username = isset($_GET['username']) ? $_GET['username'] : null;

if (!$username) {
    die("<p style='color:red;'>❌ Please log in as an admin.</p>");
}

// Debug: Log username
error_log("Admin Username: $username");

// Verify admin role
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

// Handle add academic year
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_academic_year'])) {
    $year_name = $_POST['year_name'];
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $semester = $_POST['semester'];
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $created_date = date('Y-m-d H:i:s');

    // Check for duplicate YearName
    $check_query = "SELECT COUNT(*) as year_count FROM dbo.Academic_Years WHERE YearName = ?";
    $check_params = array($year_name);
    $check_result = sqlsrv_query($conn, $check_query, $check_params);

    if ($check_result === false) {
        error_log("Check YearName error: " . print_r(sqlsrv_errors(), true));
        $message = "<p style='color:red;'>❌ Database error: " . print_r(sqlsrv_errors(), true) . "</p>";
    } else {
        $check_row = sqlsrv_fetch_array($check_result, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($check_result);

        if ($check_row['year_count'] > 0) {
            $message = "<div class='alert alert-danger alert-dismissible fade show' role='alert'>❌ Cannot add academic year: Year name already exists.<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
        } else {
            $insert_query = "INSERT INTO dbo.Academic_Years (YearName, StartDate, EndDate, Semester, IsActive, CreatedDate) VALUES (?, ?, ?, ?, ?, ?)";
            $insert_params = array($year_name, $start_date, $end_date, $semester, $is_active, $created_date);
            $insert_result = sqlsrv_query($conn, $insert_query, $insert_params);

            if ($insert_result) {
                $message = "<p style='color:green;'>✅ Academic year added successfully.</p>";
            } else {
                error_log("Add academic year error: " . print_r(sqlsrv_errors(), true));
                $message = "<p style='color:red;'>❌ Failed to add academic year: " . print_r(sqlsrv_errors(), true) . "</p>";
            }
        }
    }
}

// Handle update academic year
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_academic_year'])) {
    $academic_year_id = (int)$_POST['academic_year_id'];
    $year_name = $_POST['year_name'];
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $semester = $_POST['semester'];
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    // Check for duplicate YearName (excluding the current record)
    $check_query = "SELECT COUNT(*) as year_count FROM dbo.Academic_Years WHERE YearName = ? AND AcademicYearID != ?";
    $check_params = array($year_name, $academic_year_id);
    $check_result = sqlsrv_query($conn, $check_query, $check_params);

    if ($check_result === false) {
        error_log("Check YearName error: " . print_r(sqlsrv_errors(), true));
        $message = "<p style='color:red;'>❌ Database error: " . print_r(sqlsrv_errors(), true) . "</p>";
    } else {
        $check_row = sqlsrv_fetch_array($check_result, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($check_result);

        if ($check_row['year_count'] > 0) {
            $message = "<div class='alert alert-danger alert-dismissible fade show' role='alert'>❌ Cannot update academic year: Year name already exists.<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
        } else {
            // Check for active courses if deactivating
            if ($is_active === 0) {
                $course_query = "SELECT COUNT(*) as course_count FROM dbo.Courses WHERE AcademicYearID = ? AND IsActive = 1";
                $course_params = array($academic_year_id);
                $course_result = sqlsrv_query($conn, $course_query, $course_params);

                if ($course_result === false) {
                    error_log("Course check error: " . print_r(sqlsrv_errors(), true));
                    $message = "<p style='color:red;'>❌ Database error: " . print_r(sqlsrv_errors(), true) . "</p>";
                } else {
                    $course_row = sqlsrv_fetch_array($course_result, SQLSRV_FETCH_ASSOC);
                    if ($course_row['course_count'] > 0) {
                        $message = "<div class='alert alert-danger alert-dismissible fade show' role='alert'>❌ Cannot deactivate academic year: It has active courses.<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
                        sqlsrv_free_stmt($course_result);
                    } else {
                        sqlsrv_free_stmt($course_result);
                        $update_query = "UPDATE dbo.Academic_Years SET YearName = ?, StartDate = ?, EndDate = ?, Semester = ?, IsActive = ? WHERE AcademicYearID = ?";
                        $update_params = array($year_name, $start_date, $end_date, $semester, $is_active, $academic_year_id);
                        $update_result = sqlsrv_query($conn, $update_query, $update_params);

                        if ($update_result) {
                            $message = "<p style='color:green;'>✅ Academic year updated successfully.</p>";
                        } else {
                            error_log("Update academic year error: " . print_r(sqlsrv_errors(), true));
                            $message = "<p style='color:red;'>❌ Failed to update academic year: " . print_r(sqlsrv_errors(), true) . "</p>";
                        }
                    }
                }
            } else {
                // Update without course check if activating
                $update_query = "UPDATE dbo.Academic_Years SET YearName = ?, StartDate = ?, EndDate = ?, Semester = ?, IsActive = ? WHERE AcademicYearID = ?";
                $update_params = array($year_name, $start_date, $end_date, $semester, $is_active, $academic_year_id);
                $update_result = sqlsrv_query($conn, $update_query, $update_params);

                if ($update_result) {
                    $message = "<p style='color:green;'>✅ Academic year updated successfully.</p>";
                } else {
                    error_log("Update academic year error: " . print_r(sqlsrv_errors(), true));
                    $message = "<p style='color:red;'>❌ Failed to update academic year: " . print_r(sqlsrv_errors(), true) . "</p>";
                }
            }
        }
    }
}

// Fetch all academic years, ordered by CreatedDate (oldest to newest) and YearName
$academic_years_query = "SELECT AcademicYearID, YearName, StartDate, EndDate, Semester, IsActive, CreatedDate FROM dbo.Academic_Years ORDER BY CreatedDate ASC, YearName ASC";
$academic_years_result = sqlsrv_query($conn, $academic_years_query);

if ($academic_years_result === false) {
    error_log("Academic years query error: " . print_r(sqlsrv_errors(), true));
    die("<p style='color:red;'>❌ Database error: " . print_r(sqlsrv_errors(), true) . "</p>");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academic Year/Semester Management - Attendance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .card { margin-bottom: 20px; }
        .table-responsive { margin-top: 20px; }
        .alert { margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="h2"><i class="fas fa-calendar-alt me-2"></i>Academic Year/Semester Management</h1>
            <a href="http://127.0.0.1:8000/admin-dashboard/?username=<?php echo urlencode($username); ?>" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
            </a>
        </div>

        <!-- Display messages -->
        <?php if (isset($message)) echo $message; ?>

        <!-- Add Academic Year Button -->
        <button type="button" class="btn btn-success mb-3" data-bs-toggle="modal" data-bs-target="#addAcademicYearModal">
            <i class="fas fa-plus-circle me-2"></i>Add Academic Year
        </button>

        <!-- Academic Years Table -->
        <div class="card">
            <div class="card-header">
                <h5>Academic Years/Semesters</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Year Name</th>
                                <th>Start Date</th>
                                <th>End Date</th>
                                <th>Semester</th>
                                <th>Status</th>
                                <th>Created Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($year = sqlsrv_fetch_array($academic_years_result, SQLSRV_FETCH_ASSOC)) { ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($year['YearName']); ?></td>
                                    <td><?php echo htmlspecialchars($year['StartDate'] instanceof DateTime ? $year['StartDate']->format('Y-m-d') : $year['StartDate']); ?></td>
                                    <td><?php echo htmlspecialchars($year['EndDate'] instanceof DateTime ? $year['EndDate']->format('Y-m-d') : $year['EndDate']); ?></td>
                                    <td><?php echo htmlspecialchars($year['Semester']); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $year['IsActive'] ? 'success' : 'secondary'; ?>">
                                            <?php echo $year['IsActive'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($year['CreatedDate'] instanceof DateTime ? $year['CreatedDate']->format('Y-m-d H:i:s') : $year['CreatedDate']); ?></td>
                                    <td>
                                        <button type="button" class="btn btn-outline-warning btn-sm" data-bs-toggle="modal" data-bs-target="#editAcademicYearModal<?php echo $year['AcademicYearID']; ?>">
                                            <i class="fas fa-edit me-1"></i>Edit
                                        </button>
                                    </td>
                                </tr>
                                <!-- Edit Academic Year Modal -->
                                <div class="modal fade" id="editAcademicYearModal<?php echo $year['AcademicYearID']; ?>" tabindex="-1" aria-labelledby="editAcademicYearModalLabel<?php echo $year['AcademicYearID']; ?>" aria-hidden="true">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title" id="editAcademicYearModalLabel<?php echo $year['AcademicYearID']; ?>">Edit Academic Year</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                            </div>
                                            <form method="POST" action="semesters.php?username=<?php echo urlencode($username); ?>">
                                                <div class="modal-body">
                                                    <input type="hidden" name="academic_year_id" value="<?php echo $year['AcademicYearID']; ?>">
                                                    <div class="mb-3">
                                                        <label for="yearName<?php echo $year['AcademicYearID']; ?>" class="form-label">Year Name</label>
                                                        <input type="text" class="form-control" id="yearName<?php echo $year['AcademicYearID']; ?>" name="year_name" value="<?php echo htmlspecialchars($year['YearName']); ?>" required placeholder="e.g., 2025-2026">
                                                    </div>
                                                    <div class="mb-3">
                                                        <label for="startDate<?php echo $year['AcademicYearID']; ?>" class="form-label">Start Date</label>
                                                        <input type="date" class="form-control" id="startDate<?php echo $year['AcademicYearID']; ?>" name="start_date" value="<?php echo htmlspecialchars($year['StartDate'] instanceof DateTime ? $year['StartDate']->format('Y-m-d') : $year['StartDate']); ?>" required>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label for="endDate<?php echo $year['AcademicYearID']; ?>" class="form-label">End Date</label>
                                                        <input type="date" class="form-control" id="endDate<?php echo $year['AcademicYearID']; ?>" name="end_date" value="<?php echo htmlspecialchars($year['EndDate'] instanceof DateTime ? $year['EndDate']->format('Y-m-d') : $year['EndDate']); ?>" required>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label for="semester<?php echo $year['AcademicYearID']; ?>" class="form-label">Semester</label>
                                                        <input type="text" class="form-control" id="semester<?php echo $year['AcademicYearID']; ?>" name="semester" value="<?php echo htmlspecialchars($year['Semester']); ?>" required placeholder="e.g., Fall 2025">
                                                    </div>
                                                    <div class="mb-3 form-check">
                                                        <input type="checkbox" class="form-check-input" id="isActive<?php echo $year['AcademicYearID']; ?>" name="is_active" <?php echo $year['IsActive'] ? 'checked' : ''; ?>>
                                                        <label class="form-check-label" for="isActive<?php echo $year['AcademicYearID']; ?>">Active</label>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" name="update_academic_year" class="btn btn-warning">Update Academic Year</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php } ?>
                            <?php if (sqlsrv_num_rows($academic_years_result) === 0) { ?>
                                <tr><td colspan="7" class="text-center text-muted">No academic years found.</td></tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Add Academic Year Modal -->
        <div class="modal fade" id="addAcademicYearModal" tabindex="-1" aria-labelledby="addAcademicYearModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="addAcademicYearModalLabel">Add New Academic Year</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form method="POST" action="semesters.php?username=<?php echo urlencode($username); ?>">
                        <div class="modal-body">
                            <div class="mb-3">
                                <label for="yearName" class="form-label">Year Name</label>
                                <input type="text" class="form-control" id="yearName" name="year_name" required placeholder="e.g., 2025-2026">
                            </div>
                            <div class="mb-3">
                                <label for="startDate" class="form-label">Start Date</label>
                                <input type="date" class="form-control" id="startDate" name="start_date" required placeholder="YYYY-MM-DD">
                            </div>
                            <div class="mb-3">
                                <label for="endDate" class="form-label">End Date</label>
                                <input type="date" class="form-control" id="endDate" name="end_date" required placeholder="YYYY-MM-DD">
                            </div>
                            <div class="mb-3">
                                <label for="semester" class="form-label">Semester</label>
                                <input type="text" class="form-control" id="semester" name="semester" required placeholder="e.g., Fall 2025">
                            </div>
                            <div class="mb-3 form-check">
                                <input type="checkbox" class="form-check-input" id="isActive" name="is_active" checked>
                                <label class="form-check-label" for="isActive">Active</label>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="add_academic_year" class="btn btn-success">Add Academic Year</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php
// Clean up
sqlsrv_free_stmt($academic_years_result);
sqlsrv_free_stmt($admin_result);
sqlsrv_close($conn);
?>