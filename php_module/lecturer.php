<?php
require_once 'header.php';
require_once 'connect.php';

$username = isset($_GET['username']) ? $_GET['username'] : null;
if (!$username) {
    die("<p style='color:red;'>Please log in as an admin.</p>");
}

// Verify admin
$admin_query = "SELECT UserID, Role FROM dbo.Users WHERE Username = ?";
$admin_result = sqlsrv_query($conn, $admin_query, array($username));
if (!$admin_result || !($admin_row = sqlsrv_fetch_array($admin_result, SQLSRV_FETCH_ASSOC)) || $admin_row['Role'] !== 'Admin') {
    die("<p style='color:red;'>Access denied: Admin only.</p>");
}
$user_role = strtolower($admin_row['Role']);

// Handle Add Lecturer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_lecturer'])) {
    $employee_number = trim($_POST['employee_number']);
    $user_id = (int)$_POST['user_id'];
    $department = trim($_POST['department']);
    $qualification = trim($_POST['qualification']);
    $specialization = trim($_POST['specialization']);
    $date_of_joining = $_POST['date_of_joining'] ?: date('Y-m-d');
    $office_location = trim($_POST['office_location']) ?: null;
    $created_date = date('Y-m-d H:i:s');

    $check = sqlsrv_query($conn, "SELECT LecturerID FROM dbo.Lecturers WHERE EmployeeNumber = ?", array($employee_number));
    if (sqlsrv_has_rows($check)) {
        $message = "<div class='alert alert-danger alert-dismissible fade show'><i class='fas fa-times-circle me-2'></i>Employee Number already exists!<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
    } else {
        $insert = "INSERT INTO dbo.Lecturers 
            (UserID, EmployeeNumber, Department, Qualification, Specialization, DateOfJoining, OfficeLocation, IsActive, CreatedDate)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)";
        $params = array($user_id, $employee_number, $department, $qualification, $specialization, $date_of_joining, $office_location, $created_date);
        $result = sqlsrv_query($conn, $insert, $params);

        if ($result) {
            $message = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-check-circle me-2'></i>Lecturer added successfully.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        } else {
            error_log("Add lecturer error: " . print_r(sqlsrv_errors(), true));
            $message = "<div class='alert alert-danger alert-dismissible fade show'><i class='fas fa-times-circle me-2'></i>Failed to add lecturer.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        }
    }
}

// Handle Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_lecturer'])) {
    $lecturer_id = (int)$_POST['lecturer_id'];
    $department = trim($_POST['department']);
    $qualification = trim($_POST['qualification']);
    $specialization = trim($_POST['specialization']);
    $date_of_joining = $_POST['date_of_joining'];
    $office_location = trim($_POST['office_location']) ?: null;
    $updated_date = date('Y-m-d H:i:s');

    $update = "UPDATE dbo.Lecturers SET 
        Department = ?, Qualification = ?, Specialization = ?, DateOfJoining = ?, OfficeLocation = ?, UpdatedDate = ?
        WHERE LecturerID = ?";
    $params = array($department, $qualification, $specialization, $date_of_joining, $office_location, $updated_date, $lecturer_id);
    $result = sqlsrv_query($conn, $update, $params);

    if ($result) {
        $message = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-check-circle me-2'></i>Lecturer updated successfully.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
    } else {
        $message = "<div class='alert alert-danger alert-dismissible fade show'><i class='fas fa-times-circle me-2'></i>Update failed.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
    }
}

// Handle Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_lecturer'])) {
    $lecturer_id = (int)$_POST['lecturer_id'];
    $delete = sqlsrv_query($conn, "DELETE FROM dbo.Lecturers WHERE LecturerID = ?", array($lecturer_id));
    if ($delete) {
        $message = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-trash me-2'></i>Lecturer deleted successfully.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
    } else {
        $message = "<div class='alert alert-danger alert-dismissible fade show'><i class='fas fa-times-circle me-2'></i>Delete failed.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
    }
}

// Fetch lecturers
$lecturers_query = "
    SELECT l.LecturerID, l.EmployeeNumber, l.Department, l.Qualification, l.Specialization, l.DateOfJoining, l.OfficeLocation, l.IsActive,
           u.UserID, u.Username, u.FirstName, u.LastName, u.Email
    FROM dbo.Lecturers l
    LEFT JOIN dbo.Users u ON l.UserID = u.UserID
    ORDER BY l.EmployeeNumber
";
$lecturers_result = sqlsrv_query($conn, $lecturers_query);
if (!$lecturers_result) die("Query error: " . print_r(sqlsrv_errors(), true));

// Fetch available lecturer-role users for Add modal
$users_query = "SELECT UserID, Username, FirstName, LastName FROM dbo.Users WHERE Role = 'Lecturer' AND UserID NOT IN (SELECT UserID FROM dbo.Lecturers WHERE UserID IS NOT NULL) ORDER BY Username";
$users_result = sqlsrv_query($conn, $users_query);
$available_users = [];
while ($row = sqlsrv_fetch_array($users_result, SQLSRV_FETCH_ASSOC)) {
    $available_users[] = $row;
}

renderHeader($username, $user_role, 'lecturers');
?>

<div class="mb-4 d-flex justify-content-between align-items-center">
    <h1 class="h2"><i class="fas fa-chalkboard-teacher me-2"></i>Lecturer Management</h1>

    <!-- SEARCH & FILTER BAR (TOP RIGHT) -->
    <div class="d-flex gap-2 align-items-center">
        <input type="text" id="searchInput" class="form-control form-control-sm" placeholder="Search lecturers..." style="width: 260px;">
        <select id="departmentFilter" class="form-select form-select-sm" style="width: 160px;">
            <option value="">All Departments</option>
            <option value="Computer Science">Computer Science</option>
            <option value="Engineering">Engineering</option>
            <option value="Business">Business</option>
            <option value="Design">Design</option>
            <option value="Other">Other</option>
        </select>
        <select id="statusFilter" class="form-select form-select-sm" style="width: 120px;">
            <option value="">All Status</option>
            <option value="1">Active</option>
            <option value="0">Inactive</option>
        </select>
        <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#addLecturerModal">
            <i class="fas fa-plus-circle me-1"></i>Add Lecturer
        </button>
    </div>
</div>

<?php if (isset($message)) echo $message; ?>

<div class="card">
    <div class="card-header bg-white">
        <h5 class="mb-0"><i class="fas fa-users me-2"></i>All Lecturers</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover" id="lecturersTable">
                <thead class="table-light">
                    <tr>
                        <th>Employee Number</th>
                        <th>Name</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Department</th>
                        <th>Specialization</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($l = sqlsrv_fetch_array($lecturers_result, SQLSRV_FETCH_ASSOC)) { ?>
                        <tr data-department="<?php echo htmlspecialchars($l['Department'] ?? ''); ?>" data-status="<?php echo $l['IsActive']; ?>">
                            <td><strong><?php echo htmlspecialchars($l['EmployeeNumber']); ?></strong></td>
                            <td><?php echo htmlspecialchars(($l['FirstName'] ?? '') . ' ' . ($l['LastName'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars($l['Username'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($l['Email'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($l['Department'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($l['Specialization'] ?? '—'); ?></td>
                            <td>
                                <span class="badge bg-<?php echo $l['IsActive'] ? 'success' : 'secondary'; ?>">
                                    <?php echo $l['IsActive'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                            <td>
                                <button class="btn btn-outline-warning btn-sm" data-bs-toggle="modal" data-bs-target="#edit<?php echo $l['LecturerID']; ?>">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#delete<?php echo $l['LecturerID']; ?>">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>

                        <!-- Edit Modal -->
                        <div class="modal fade" id="editModal<?php echo $l['LecturerID']; ?>" tabindex="-1">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Lecturer</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <form method="POST" action="lecturer.php?username=<?php echo urlencode($username); ?>">
                                        <div class="modal-body">
                                            <input type="hidden" name="lecturer_id" value="<?php echo $l['LecturerID']; ?>">
                                            <div class="row">
                                                <div class="col-md-6 mb-3">
                                                    <label>Department <span class="text-danger">*</span></label>
                                                    <input type="text" class="form-control" name="department" value="<?php echo htmlspecialchars($l['Department'] ?? ''); ?>" required>
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label>Qualification <span class="text-danger">*</span></label>
                                                    <input type="text" class="form-control" name="qualification" value="<?php echo htmlspecialchars($l['Qualification'] ?? ''); ?>" required>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6 mb-3">
                                                    <label>Specialization</label>
                                                    <input type="text" class="form-control" name="specialization" value="<?php echo htmlspecialchars($l['Specialization'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label>Date of Joining</label>
                                                    <input type="date" class="form-control" name="date_of_joining" value="<?php echo $l['DateOfJoining'] ? $l['DateOfJoining']->format('Y-m-d') : ''; ?>">
                                                </div>
                                            </div>
                                            <div class="mb-3">
                                                <label>Office Location</label>
                                                <input type="text" class="form-control" name="office_location" value="<?php echo htmlspecialchars($l['OfficeLocation'] ?? ''); ?>">
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" name="update_lecturer" class="btn btn-warning">Update Lecturer</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- Delete Modal -->
                        <div class="modal fade" id="deleteModal<?php echo $l['LecturerID']; ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title text-danger"><i class="fas fa-exclamation-triangle me-2"></i>Delete Lecturer</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <p>Permanently delete this lecturer?</p>
                                        <strong><?php echo htmlspecialchars($l['EmployeeNumber'] . ' - ' . ($l['FirstName'] ?? '') . ' ' . ($l['LastName'] ?? '')); ?></strong>
                                    </div>
                                    <div class="modal-footer">
                                        <form method="POST" action="lecturer.php?username=<?php echo urlencode($username); ?>">
                                            <input type="hidden" name="lecturer_id" value="<?php echo $l['LecturerID']; ?>">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" name="delete_lecturer" class="btn btn-danger">Yes, Delete</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Lecturer Modal -->
<div class="modal fade" id="addLecturerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Add New Lecturer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="lecturer.php?username=<?php echo urlencode($username); ?>">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label>Link to User <span class="text-danger">*</span></label>
                            <select class="form-control" name="user_id" required>
                                <option value="">-- Select Lecturer User --</option>
                                <?php foreach ($available_users as $u): ?>
                                    <option value="<?php echo $u['UserID']; ?>">
                                        <?php echo htmlspecialchars($u['Username'] . ' - ' . $u['FirstName'] . ' ' . $u['LastName']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label>Employee Number <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="employee_number" required placeholder="e.g., LEC00123" style="text-transform: uppercase;">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label>Department <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="department" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label>Qualification <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="qualification" required placeholder="e.g., PhD Computer Science">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label>Specialization</label>
                            <input type="text" class="form-control" name="specialization" placeholder="e.g., Machine Learning">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label>Date of Joining</label>
                            <input type="date" class="form-control" name="date_of_joining" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label>Office Location</label>
                        <input type="text" class="form-control" name="office_location" placeholder="e.g., Block A, Room 305">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_lecturer" class="btn btn-success">
                        <i class="fas fa-plus-circle me-2"></i>Add Lecturer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- SEARCH & FILTER SCRIPT -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById('searchInput');
    const departmentFilter = document.getElementById('departmentFilter');
    const statusFilter = document.getElementById('statusFilter');
    const rows = document.querySelectorAll('#lecturersTable tbody tr');

    function filterTable() {
        const search = searchInput.value.toLowerCase().trim();
        const dept = departmentFilter.value.toLowerCase();
        const status = statusFilter.value;

        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            const rowDept = row.getAttribute('data-department')?.toLowerCase() || '';
            const rowStatus = row.getAttribute('data-status');

            const matchesSearch = text.includes(search);
            const matchesDept = !dept || rowDept.includes(dept);
            const matchesStatus = status === '' || rowStatus === status;

            row.style.display = (matchesSearch && matchesDept && matchesStatus) ? '' : 'none';
        });
    }

    searchInput.addEventListener('keyup', filterTable);
    departmentFilter.addEventListener('change', filterTable);
    statusFilter.addEventListener('change', filterTable);

    filterTable();
});
</script>

<?php
renderFooter();
sqlsrv_free_stmt($lecturers_result);
sqlsrv_free_stmt($users_result);
sqlsrv_free_stmt($admin_result);
sqlsrv_close($conn);
?>