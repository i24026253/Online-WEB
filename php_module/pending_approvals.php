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
$admin_query = "
    SELECT u.UserID, u.Role
    FROM dbo.Users u
    WHERE u.Username = ?
";
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

// Handle approve action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve'])) {
    $enrollment_id = $_POST['enrollment_id'];
    $status = $_POST['request_type'] === 'Pending Enroll' ? 'Active' : 'Dropped';

    // Update status
    $update_query = "UPDATE dbo.Enrollments SET Status = ?, RejectionReason = NULL, DropRejectionReason = NULL WHERE EnrollmentID = ?";
    $update_params = array($status, $enrollment_id);
    $update_result = sqlsrv_query($conn, $update_query, $update_params);

    if ($update_result) {
        $message = "<div class='alert alert-success alert-dismissible fade show' role='alert'><i class='fas fa-check-circle me-2'></i>Request approved successfully.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
    } else {
        error_log("Approve error: " . print_r(sqlsrv_errors(), true));
        $message = "<div class='alert alert-danger alert-dismissible fade show' role='alert'><i class='fas fa-times-circle me-2'></i>Failed to approve: " . print_r(sqlsrv_errors(), true) . "<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
    }
}

// Handle reject action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_submit'])) {
    $enrollment_id = $_POST['enrollment_id'];
    $rejection_reason = $_POST['rejection_reason'];
    $request_type = $_POST['request_type'];

    if ($request_type === 'Pending Enroll') {
        // Reject enrollment: set Status = 'Rejected', store RejectionReason
        $update_query = "UPDATE dbo.Enrollments SET Status = 'Rejected', RejectionReason = ?, DropRejectionReason = NULL WHERE EnrollmentID = ?";
        $update_params = array($rejection_reason, $enrollment_id);
    } else {
        // Reject drop: set Status = 'Active', store DropRejectionReason
        $update_query = "UPDATE dbo.Enrollments SET Status = 'Active', RejectionReason = NULL, DropRejectionReason = ? WHERE EnrollmentID = ?";
        $update_params = array($rejection_reason, $enrollment_id);
    }

    $update_result = sqlsrv_query($conn, $update_query, $update_params);

    if ($update_result) {
        $message = "<div class='alert alert-success alert-dismissible fade show' role='alert'><i class='fas fa-check-circle me-2'></i>Request rejected successfully.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
    } else {
        error_log("Reject error: " . print_r(sqlsrv_errors(), true));
        $message = "<div class='alert alert-danger alert-dismissible fade show' role='alert'><i class='fas fa-times-circle me-2'></i>Failed to reject: " . print_r(sqlsrv_errors(), true) . "<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
    }
}

// Fetch pending requests
$pending_query = "
    SELECT e.EnrollmentID, e.StudentID, e.CourseID, e.Status, e.RequestReason, e.EnrollmentDate,
           c.CourseCode, c.CourseName,
           u.FirstName, u.LastName
    FROM dbo.Enrollments e
    JOIN dbo.Courses c ON e.CourseID = c.CourseID
    JOIN dbo.Students s ON e.StudentID = s.StudentID
    JOIN dbo.Users u ON s.UserID = u.UserID
    WHERE e.Status IN ('Pending Enroll', 'Pending Drop')
    ORDER BY e.EnrollmentDate DESC
";
$pending_result = sqlsrv_query($conn, $pending_query);

if ($pending_result === false) {
    error_log("Pending query error: " . print_r(sqlsrv_errors(), true));
    die("<p style='color:red;'>❌ Database error: " . print_r(sqlsrv_errors(), true) . "</p>");
}

// Render header with navigation
renderHeader($username, $user_role);
?>

<!-- Page Content Starts Here -->
<div class="mb-4">
    <h1 class="h2"><i class="fas fa-clipboard-check me-2"></i>Pending Approvals</h1>
</div>

<!-- Display messages -->
<?php if (isset($message)) echo $message; ?>

<!-- Pending Requests -->
<div class="card">
    <div class="card-header bg-white">
        <h5 class="mb-0"><i class="fas fa-list-check me-2"></i>Pending Enrollment and Drop Requests</h5>
    </div>
    <div class="card-body">
        <?php 
        $pending_count = 0;
        $temp_result = sqlsrv_query($conn, $pending_query);
        if ($temp_result) {
            while (sqlsrv_fetch($temp_result)) {
                $pending_count++;
            }
            sqlsrv_free_stmt($temp_result);
        }
        ?>
        
        <?php if ($pending_count > 0) { ?>
            <div class="alert alert-info mb-3">
                <i class="fas fa-info-circle me-2"></i>
                You have <strong><?php echo $pending_count; ?></strong> pending request<?php echo $pending_count > 1 ? 's' : ''; ?> to review.
            </div>
        <?php } ?>
        
        <div class="table-responsive">
            <table class="table table-hover">
                <thead class="table-light">
                    <tr>
                        <th><i class="fas fa-book me-1"></i>Course</th>
                        <th><i class="fas fa-user-graduate me-1"></i>Student</th>
                        <th><i class="fas fa-tag me-1"></i>Request Type</th>
                        <th><i class="fas fa-comment me-1"></i>Reason</th>
                        <th><i class="fas fa-calendar me-1"></i>Submission Date</th>
                        <th><i class="fas fa-cogs me-1"></i>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($request = sqlsrv_fetch_array($pending_result, SQLSRV_FETCH_ASSOC)) { ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($request['CourseCode']); ?></strong><br>
                                <small class="text-muted"><?php echo htmlspecialchars($request['CourseName']); ?></small>
                            </td>
                            <td><?php echo htmlspecialchars($request['FirstName'] . ' ' . $request['LastName']); ?></td>
                            <td>
                                <?php if ($request['Status'] === 'Pending Enroll') { ?>
                                    <span class="badge bg-primary">
                                        <i class="fas fa-user-plus me-1"></i>Enrollment
                                    </span>
                                <?php } else { ?>
                                    <span class="badge bg-warning text-dark">
                                        <i class="fas fa-user-minus me-1"></i>Drop
                                    </span>
                                <?php } ?>
                            </td>
                            <td>
                                <small><?php echo htmlspecialchars($request['RequestReason'] ?? 'No reason provided'); ?></small>
                            </td>
                            <td>
                                <?php 
                                if ($request['EnrollmentDate']) {
                                    echo $request['EnrollmentDate']->format('M d, Y');
                                    echo '<br><small class="text-muted">' . $request['EnrollmentDate']->format('h:i A') . '</small>';
                                } else {
                                    echo '<span class="text-muted">N/A</span>';
                                }
                                ?>
                            </td>
                            <td>
                                <div class="btn-group" role="group">
                                    <form method="POST" action="pending_approvals.php?username=<?php echo urlencode($username); ?>" style="display:inline;">
                                        <input type="hidden" name="enrollment_id" value="<?php echo $request['EnrollmentID']; ?>">
                                        <input type="hidden" name="request_type" value="<?php echo $request['Status']; ?>">
                                        <button type="submit" name="approve" class="btn btn-success btn-sm" onclick="return confirm('Are you sure you want to approve this request?');">
                                            <i class="fas fa-check me-1"></i>Approve
                                        </button>
                                    </form>
                                    <button type="button" class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#rejectModal<?php echo $request['EnrollmentID']; ?>">
                                        <i class="fas fa-times me-1"></i>Reject
                                    </button>
                                </div>
                                
                                <!-- Reject Modal -->
                                <div class="modal fade" id="rejectModal<?php echo $request['EnrollmentID']; ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">
                                                    <i class="fas fa-times-circle me-2"></i>
                                                    Reject <?php echo $request['Status'] === 'Pending Enroll' ? 'Enrollment' : 'Drop'; ?> Request
                                                </h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form method="POST" action="pending_approvals.php?username=<?php echo urlencode($username); ?>">
                                                <div class="modal-body">
                                                    <div class="alert alert-warning">
                                                        <i class="fas fa-exclamation-triangle me-2"></i>
                                                        <strong>Student:</strong> <?php echo htmlspecialchars($request['FirstName'] . ' ' . $request['LastName']); ?><br>
                                                        <strong>Course:</strong> <?php echo htmlspecialchars($request['CourseCode'] . ' - ' . $request['CourseName']); ?>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label">Reason for Rejection <span class="text-danger">*</span></label>
                                                        <textarea class="form-control" name="rejection_reason" rows="4" placeholder="Provide a clear reason for rejecting this request..." required></textarea>
                                                        <small class="text-muted">This reason will be visible to the student.</small>
                                                    </div>
                                                    <input type="hidden" name="enrollment_id" value="<?php echo $request['EnrollmentID']; ?>">
                                                    <input type="hidden" name="request_type" value="<?php echo $request['Status']; ?>">
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" name="reject_submit" class="btn btn-danger">
                                                        <i class="fas fa-times-circle me-2"></i>Submit Rejection
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php } ?>
                    <?php if ($pending_count === 0) { ?>
                        <tr>
                            <td colspan="6" class="text-center py-5">
                                <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                <p class="text-muted mb-0">No pending requests at the moment.</p>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
// Render footer
renderFooter();

// Clean up
sqlsrv_free_stmt($pending_result);
sqlsrv_free_stmt($admin_result);
sqlsrv_close($conn);
?>