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

// Set user role for header
$user_role = strtolower($admin_row['Role']);

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
        $message = "<div class='alert alert-danger alert-dismissible fade show' role='alert'><i class='fas fa-times-circle me-2'></i>Database error: " . print_r(sqlsrv_errors(), true) . "<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
    } else {
        $check_row = sqlsrv_fetch_array($check_result, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($check_result);

        if ($check_row['year_count'] > 0) {
            $message = "<div class='alert alert-danger alert-dismissible fade show' role='alert'><i class='fas fa-exclamation-triangle me-2'></i>Cannot add academic year: Year name already exists.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        } else {
            $insert_query = "INSERT INTO dbo.Academic_Years (YearName, StartDate, EndDate, Semester, IsActive, CreatedDate) VALUES (?, ?, ?, ?, ?, ?)";
            $insert_params = array($year_name, $start_date, $end_date, $semester, $is_active, $created_date);
            $insert_result = sqlsrv_query($conn, $insert_query, $insert_params);

            if ($insert_result) {
                $message = "<div class='alert alert-success alert-dismissible fade show' role='alert'><i class='fas fa-check-circle me-2'></i>Academic year added successfully.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
            } else {
                error_log("Add academic year error: " . print_r(sqlsrv_errors(), true));
                $message = "<div class='alert alert-danger alert-dismissible fade show' role='alert'><i class='fas fa-times-circle me-2'></i>Failed to add academic year: " . print_r(sqlsrv_errors(), true) . "<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
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
        $message = "<div class='alert alert-danger alert-dismissible fade show' role='alert'><i class='fas fa-times-circle me-2'></i>Database error: " . print_r(sqlsrv_errors(), true) . "<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
    } else {
        $check_row = sqlsrv_fetch_array($check_result, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($check_result);

        if ($check_row['year_count'] > 0) {
            $message = "<div class='alert alert-danger alert-dismissible fade show' role='alert'><i class='fas fa-exclamation-triangle me-2'></i>Cannot update academic year: Year name already exists.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        } else {
            // Check for active courses if deactivating
            if ($is_active === 0) {
                $course_query = "SELECT COUNT(*) as course_count FROM dbo.Courses WHERE AcademicYearID = ? AND IsActive = 1";
                $course_params = array($academic_year_id);
                $course_result = sqlsrv_query($conn, $course_query, $course_params);

                if ($course_result === false) {
                    error_log("Course check error: " . print_r(sqlsrv_errors(), true));
                    $message = "<div class='alert alert-danger alert-dismissible fade show' role='alert'><i class='fas fa-times-circle me-2'></i>Database error: " . print_r(sqlsrv_errors(), true) . "<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
                } else {
                    $course_row = sqlsrv_fetch_array($course_result, SQLSRV_FETCH_ASSOC);
                    if ($course_row['course_count'] > 0) {
                        $message = "<div class='alert alert-danger alert-dismissible fade show' role='alert'><i class='fas fa-exclamation-triangle me-2'></i>Cannot deactivate academic year: It has " . $course_row['course_count'] . " active course(s).<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
                        sqlsrv_free_stmt($course_result);
                    } else {
                        sqlsrv_free_stmt($course_result);
                        $update_query = "UPDATE dbo.Academic_Years SET YearName = ?, StartDate = ?, EndDate = ?, Semester = ?, IsActive = ? WHERE AcademicYearID = ?";
                        $update_params = array($year_name, $start_date, $end_date, $semester, $is_active, $academic_year_id);
                        $update_result = sqlsrv_query($conn, $update_query, $update_params);

                        if ($update_result) {
                            $message = "<div class='alert alert-success alert-dismissible fade show' role='alert'><i class='fas fa-check-circle me-2'></i>Academic year updated successfully.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
                        } else {
                            error_log("Update academic year error: " . print_r(sqlsrv_errors(), true));
                            $message = "<div class='alert alert-danger alert-dismissible fade show' role='alert'><i class='fas fa-times-circle me-2'></i>Failed to update academic year: " . print_r(sqlsrv_errors(), true) . "<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
                        }
                    }
                }
            } else {
                // Update without course check if activating
                $update_query = "UPDATE dbo.Academic_Years SET YearName = ?, StartDate = ?, EndDate = ?, Semester = ?, IsActive = ? WHERE AcademicYearID = ?";
                $update_params = array($year_name, $start_date, $end_date, $semester, $is_active, $academic_year_id);
                $update_result = sqlsrv_query($conn, $update_query, $update_params);

                if ($update_result) {
                    $message = "<div class='alert alert-success alert-dismissible fade show' role='alert'><i class='fas fa-check-circle me-2'></i>Academic year updated successfully.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
                } else {
                    error_log("Update academic year error: " . print_r(sqlsrv_errors(), true));
                    $message = "<div class='alert alert-danger alert-dismissible fade show' role='alert'><i class='fas fa-times-circle me-2'></i>Failed to update academic year: " . print_r(sqlsrv_errors(), true) . "<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
                }
            }
        }
    }
}

// Fetch all academic years, ordered by CreatedDate (newest first) and YearName
$academic_years_query = "SELECT AcademicYearID, YearName, StartDate, EndDate, Semester, IsActive, CreatedDate FROM dbo.Academic_Years ORDER BY CreatedDate DESC, YearName DESC";
$academic_years_result = sqlsrv_query($conn, $academic_years_query);

if ($academic_years_result === false) {
    error_log("Academic years query error: " . print_r(sqlsrv_errors(), true));
    die("<p style='color:red;'>❌ Database error: " . print_r(sqlsrv_errors(), true) . "</p>");
}

// Render header with navigation
renderHeader($username, $user_role);
?>

<!-- Page Content Starts Here -->
<div class="mb-4">
    <h1 class="h2"><i class="fas fa-calendar-alt me-2"></i>Academic Year/Semester Management</h1>
</div>

<!-- Display messages -->
<?php if (isset($message)) echo $message; ?>

<!-- Add Academic Year Button -->
<button type="button" class="btn btn-success mb-3" data-bs-toggle="modal" data-bs-target="#addAcademicYearModal">
    <i class="fas fa-plus-circle me-2"></i>Add Academic Year
</button>

<!-- Academic Years Table -->
<div class="card">
    <div class="card-header bg-white">
        <h5 class="mb-0"><i class="fas fa-list me-2"></i>Academic Years/Semesters</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead class="table-light">
                    <tr>
                        <th><i class="fas fa-graduation-cap me-1"></i>Year Name</th>
                        <th><i class="fas fa-calendar-day me-1"></i>Start Date</th>
                        <th><i class="fas fa-calendar-check me-1"></i>End Date</th>
                        <th><i class="fas fa-calendar-week me-1"></i>Semester</th>
                        <th><i class="fas fa-toggle-on me-1"></i>Status</th>
                        <th><i class="fas fa-clock me-1"></i>Created Date</th>
                        <th><i class="fas fa-cogs me-1"></i>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $row_count = 0;
                    while ($year = sqlsrv_fetch_array($academic_years_result, SQLSRV_FETCH_ASSOC)) { 
                        $row_count++;
                    ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($year['YearName']); ?></strong></td>
                            <td><?php echo htmlspecialchars($year['StartDate'] instanceof DateTime ? $year['StartDate']->format('M d, Y') : $year['StartDate']); ?></td>
                            <td><?php echo htmlspecialchars($year['EndDate'] instanceof DateTime ? $year['EndDate']->format('M d, Y') : $year['EndDate']); ?></td>
                            <td>
                                <span class="badge bg-info text-dark">
                                    <?php echo htmlspecialchars($year['Semester']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-<?php echo $year['IsActive'] ? 'success' : 'secondary'; ?>">
                                    <i class="fas fa-<?php echo $year['IsActive'] ? 'check-circle' : 'times-circle'; ?> me-1"></i>
                                    <?php echo $year['IsActive'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                            <td>
                                <?php 
                                if ($year['CreatedDate'] instanceof DateTime) {
                                    echo $year['CreatedDate']->format('M d, Y');
                                    echo '<br><small class="text-muted">' . $year['CreatedDate']->format('h:i A') . '</small>';
                                } else {
                                    echo htmlspecialchars($year['CreatedDate']);
                                }
                                ?>
                            </td>
                            <td>
                                <button type="button" class="btn btn-outline-warning btn-sm" data-bs-toggle="modal" data-bs-target="#editAcademicYearModal<?php echo $year['AcademicYearID']; ?>">
                                    <i class="fas fa-edit me-1"></i>Edit
                                </button>
                            </td>
                        </tr>
                        
                        <!-- Edit Academic Year Modal -->
                        <div class="modal fade" id="editAcademicYearModal<?php echo $year['AcademicYearID']; ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">
                                            <i class="fas fa-edit me-2"></i>Edit Academic Year
                                        </h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <form method="POST" action="semesters.php?username=<?php echo urlencode($username); ?>">
                                        <div class="modal-body">
                                            <input type="hidden" name="academic_year_id" value="<?php echo $year['AcademicYearID']; ?>">
                                            <div class="mb-3">
                                                <label class="form-label">Year Name <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" name="year_name" value="<?php echo htmlspecialchars($year['YearName']); ?>" required placeholder="e.g., 2025-2026">
                                                <small class="text-muted">Format: YYYY-YYYY</small>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">Start Date <span class="text-danger">*</span></label>
                                                    <input type="date" class="form-control" name="start_date" value="<?php echo htmlspecialchars($year['StartDate'] instanceof DateTime ? $year['StartDate']->format('Y-m-d') : $year['StartDate']); ?>" required>
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">End Date <span class="text-danger">*</span></label>
                                                    <input type="date" class="form-control" name="end_date" value="<?php echo htmlspecialchars($year['EndDate'] instanceof DateTime ? $year['EndDate']->format('Y-m-d') : $year['EndDate']); ?>" required>
                                                </div>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Semester <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" name="semester" value="<?php echo htmlspecialchars($year['Semester']); ?>" required placeholder="e.g., Fall 2025">
                                                <small class="text-muted">e.g., Fall 2025, Spring 2026, Summer 2026</small>
                                            </div>
                                            <div class="mb-3">
                                                <div class="form-check form-switch">
                                                    <input type="checkbox" class="form-check-input" id="isActive<?php echo $year['AcademicYearID']; ?>" name="is_active" <?php echo $year['IsActive'] ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="isActive<?php echo $year['AcademicYearID']; ?>">
                                                        <i class="fas fa-toggle-on me-1"></i>Active Status
                                                    </label>
                                                </div>
                                                <small class="text-muted">Inactive years cannot have active courses</small>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" name="update_academic_year" class="btn btn-warning">
                                                <i class="fas fa-save me-2"></i>Update Academic Year
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php } ?>
                    <?php if ($row_count === 0) { ?>
                        <tr>
                            <td colspan="7" class="text-center py-5">
                                <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                                <p class="text-muted mb-0">No academic years found. Click "Add Academic Year" to create one.</p>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Academic Year Modal -->
<div class="modal fade" id="addAcademicYearModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-plus-circle me-2"></i>Add New Academic Year
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="semesters.php?username=<?php echo urlencode($username); ?>">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Year Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="year_name" required placeholder="e.g., 2025-2026">
                        <small class="text-muted">Format: YYYY-YYYY</small>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Start Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="start_date" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">End Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="end_date" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Semester <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="semester" required placeholder="e.g., Fall 2025">
                        <small class="text-muted">e.g., Fall 2025, Spring 2026, Summer 2026</small>
                    </div>
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input type="checkbox" class="form-check-input" id="isActive" name="is_active" checked>
                            <label class="form-check-label" for="isActive">
                                <i class="fas fa-toggle-on me-1"></i>Set as Active
                            </label>
                        </div>
                        <small class="text-muted">Only active academic years can have courses assigned to them</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_academic_year" class="btn btn-success">
                        <i class="fas fa-plus-circle me-2"></i>Add Academic Year
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
sqlsrv_free_stmt($academic_years_result);
sqlsrv_free_stmt($admin_result);
sqlsrv_close($conn);
?>