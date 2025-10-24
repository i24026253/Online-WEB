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

// Handle approve action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve'])) {
    $enrollment_id = $_POST['enrollment_id'];
    $status = $_POST['request_type'] === 'Pending Enroll' ? 'Active' : 'Dropped';

    // Update status
    $update_query = "UPDATE dbo.Enrollments SET Status = ?, RejectionReason = NULL, DropRejectionReason = NULL WHERE EnrollmentID = ?";
    $update_params = array($status, $enrollment_id);
    $update_result = sqlsrv_query($conn, $update_query, $update_params);

    if ($update_result) {
        $message = "<p style='color:green;'>✅ Request approved successfully.</p>";
    } else {
        error_log("Approve error: " . print_r(sqlsrv_errors(), true));
        $message = "<p style='color:red;'>❌ Failed to approve: " . print_r(sqlsrv_errors(), true) . "</p>";
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
        $message = "<p style='color:green;'>✅ Request rejected successfully.</p>";
    } else {
        error_log("Reject error: " . print_r(sqlsrv_errors(), true));
        $message = "<p style='color:red;'>❌ Failed to reject: " . print_r(sqlsrv_errors(), true) . "</p>";
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Approvals - Attendance System</title>
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
            <h1 class="h2"><i class="fas fa-clipboard-check me-2"></i>Pending Approvals</h1>
            <a href="http://127.0.0.1:8000/admin-dashboard/?username=<?php echo urlencode($username); ?>" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
            </a>
        </div>

        <!-- Display messages -->
        <?php if (isset($message)) echo $message; ?>

        <!-- Pending Requests -->
        <div class="card">
            <div class="card-header">
                <h5>Pending Enrollment and Drop Requests</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Course</th>
                                <th>Student</th>
                                <th>Request Type</th>
                                <th>Reason</th>
                                <th>Submission Date</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($request = sqlsrv_fetch_array($pending_result, SQLSRV_FETCH_ASSOC)) { ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($request['CourseCode'] . ' - ' . $request['CourseName']); ?></td>
                                    <td><?php echo htmlspecialchars($request['FirstName'] . ' ' . $request['LastName']); ?></td>
                                    <td><?php echo htmlspecialchars($request['Status'] === 'Pending Enroll' ? 'Enrollment' : 'Drop'); ?></td>
                                    <td><?php echo htmlspecialchars($request['RequestReason'] ?? 'No reason provided'); ?></td>
                                    <td><?php echo $request['EnrollmentDate'] ? $request['EnrollmentDate']->format('M d, Y H:i') : 'N/A'; ?></td>
                                    <td>
                                        <form method="POST" action="pending_approvals.php?username=<?php echo urlencode($username); ?>" style="display:inline;">
                                            <input type="hidden" name="enrollment_id" value="<?php echo $request['EnrollmentID']; ?>">
                                            <input type="hidden" name="request_type" value="<?php echo $request['Status']; ?>">
                                            <button type="submit" name="approve" class="btn btn-success btn-sm">
                                                <i class="fas fa-check me-2"></i>Approve
                                            </button>
                                        </form>
                                        <button type="button" class="btn btn-danger btn-sm ms-2" data-bs-toggle="modal" data-bs-target="#rejectModal<?php echo $request['EnrollmentID']; ?>">
                                            <i class="fas fa-times me-2"></i>Reject
                                        </button>
                                        <!-- Reject Modal -->
                                        <div class="modal fade" id="rejectModal<?php echo $request['EnrollmentID']; ?>" tabindex="-1" aria-labelledby="rejectModalLabel<?php echo $request['EnrollmentID']; ?>" aria-hidden="true">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title" id="rejectModalLabel<?php echo $request['EnrollmentID']; ?>">Reject <?php echo $request['Status'] === 'Pending Enroll' ? 'Enrollment' : 'Drop'; ?> Request</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                    </div>
                                                    <form method="POST" action="pending_approvals.php?username=<?php echo urlencode($username); ?>">
                                                        <div class="modal-body">
                                                            <div class="mb-3">
                                                                <label for="rejectReason<?php echo $request['EnrollmentID']; ?>" class="form-label">Reason for Rejection</label>
                                                                <textarea class="form-control" id="rejectReason<?php echo $request['EnrollmentID']; ?>" name="rejection_reason" rows="4" required></textarea>
                                                            </div>
                                                            <input type="hidden" name="enrollment_id" value="<?php echo $request['EnrollmentID']; ?>">
                                                            <input type="hidden" name="request_type" value="<?php echo $request['Status']; ?>">
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" name="reject_submit" class="btn btn-danger">Submit Rejection</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php } ?>
                            <?php if (sqlsrv_num_rows($pending_result) === 0) { ?>
                                <tr><td colspan="6" class="text-center text-muted">No pending requests.</td></tr>
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
sqlsrv_free_stmt($pending_result);
sqlsrv_free_stmt($admin_result);
sqlsrv_close($conn);
?>