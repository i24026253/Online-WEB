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

// Handle Add User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $new_username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $phone = trim($_POST['phone']) ?: null;
    $role = $_POST['role'];
    $created_date = date('Y-m-d H:i:s');

    // Check if username exists
    $check = sqlsrv_query($conn, "SELECT Username FROM dbo.Users WHERE Username = ?", array($new_username));
    if (sqlsrv_has_rows($check)) {
        $message = "<div class='alert alert-danger alert-dismissible fade show'><i class='fas fa-times-circle me-2'></i>Username already exists!<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
    } else {
        $insert = "INSERT INTO dbo.Users 
            (Username, Email, PasswordHash, FirstName, LastName, PhoneNumber, Role, IsActive, CreatedDate)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)";
        $params = array($new_username, $email, $password, $first_name, $last_name, $phone, $role, $created_date);
        $result = sqlsrv_query($conn, $insert, $params);

        if ($result) {
            $message = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-check-circle me-2'></i>User added successfully.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        } else {
            error_log("Add user error: " . print_r(sqlsrv_errors(), true));
            $message = "<div class='alert alert-danger alert-dismissible fade show'><i class='fas fa-times-circle me-2'></i>Failed to add user.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        }
    }
}

// Handle Update User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user'])) {
    $user_id = (int)$_POST['user_id'];
    $email = trim($_POST['email']);
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $phone = trim($_POST['phone']) ?: null;
    $role = $_POST['role'];
    $updated_date = date('Y-m-d H:i:s');

    $update = "UPDATE dbo.Users SET 
        Email = ?, FirstName = ?, LastName = ?, PhoneNumber = ?, Role = ?, UpdatedDate = ?
        WHERE UserID = ?";
    $params = array($email, $first_name, $last_name, $phone, $role, $updated_date, $user_id);
    $result = sqlsrv_query($conn, $update, $params);

    if ($result) {
        $message = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-check-circle me-2'></i>User updated successfully.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
    } else {
        $message = "<div class='alert alert-danger alert-dismissible fade show'><i class='fas fa-times-circle me-2'></i>Update failed.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
    }
}

// Handle Delete User (with safety check)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    $user_id = (int)$_POST['user_id'];

    // Prevent deleting yourself
    if ($admin_row['UserID'] == $user_id) {
        $message = "<div class='alert alert-danger alert-dismissible fade show'><i class='fas fa-ban me-2'></i>Cannot delete your own account!<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
    } else {
        // Optional: Check if user has linked records
        $check_student = sqlsrv_query($conn, "SELECT StudentID FROM dbo.Students WHERE UserID = ?", array($user_id));
        $check_lecturer = sqlsrv_query($conn, "SELECT LecturerID FROM dbo.Lecturers WHERE UserID = ?", array($user_id));

        if (sqlsrv_has_rows($check_student) || sqlsrv_has_rows($check_lecturer)) {
            $message = "<div class='alert alert-warning alert-dismissible fade show'><i class='fas fa-exclamation-triangle me-2'></i>Cannot delete: User has linked Student/Lecturer record.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        } else {
            $delete = sqlsrv_query($conn, "DELETE FROM dbo.Users WHERE UserID = ?", array($user_id));
            if ($delete) {
                $message = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-trash me-2'></i>User deleted successfully.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
            } else {
                $message = "<div class='alert alert-danger alert-dismissible fade show'><i class='fas fa-times-circle me-2'></i>Delete failed.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
            }
        }
    }
}

// Fetch all users
$users_query = "SELECT UserID, Username, Email, FirstName, LastName, PhoneNumber, Role, IsActive, CreatedDate FROM dbo.Users ORDER BY Username";
$users_result = sqlsrv_query($conn, $users_query);
if (!$users_result) die("Query error: " . print_r(sqlsrv_errors(), true));

renderHeader($username, $user_role, 'users');
?>

<div class="mb-4 d-flex justify-content-between align-items-center">
    <h1 class="h2"><i class="fas fa-users-cog me-2"></i>User Management</h1>

    <!-- SEARCH & FILTER BAR -->
    <div class="d-flex gap-2 align-items-center">
        <input type="text" id="searchInput" class="form-control form-control-sm" placeholder="Search users..." style="width: 260px;">
        <select id="roleFilter" class="form-select form-select-sm" style="width: 140px;">
            <option value="">All Roles</option>
            <option value="Admin">Admin</option>
            <option value="Lecturer">Lecturer</option>
            <option value="Student">Student</option>
        </select>
        <select id="statusFilter" class="form-select form-select-sm" style="width: 120px;">
            <option value="">All Status</option>
            <option value="1">Active</option>
            <option value="0">Inactive</option>
        </select>
        <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#addUserModal">
            <i class="fas fa-plus-circle me-1"></i>Add User
        </button>
    </div>
</div>

<?php if (isset($message)) echo $message; ?>

<div class="card">
    <div class="card-header bg-white">
        <h5 class="mb-0"><i class="fas fa-users me-2"></i>All Users</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover" id="usersTable">
                <thead class="table-light">
                    <tr>
                        <th>Username</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Registered</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($u = sqlsrv_fetch_array($users_result, SQLSRV_FETCH_ASSOC)) { ?>
                        <tr data-role="<?php echo htmlspecialchars($u['Role']); ?>" data-status="<?php echo $u['IsActive']; ?>">
                            <td><strong><?php echo htmlspecialchars($u['Username']); ?></strong></td>
                            <td><?php echo htmlspecialchars($u['FirstName'] . ' ' . $u['LastName']); ?></td>
                            <td><?php echo htmlspecialchars($u['Email']); ?></td>
                            <td><?php echo htmlspecialchars($u['PhoneNumber'] ?? '—'); ?></td>
                            <td>
                                <span class="badge bg-<?php 
                                    echo $u['Role'] == 'Admin' ? 'danger' : 
                                         ($u['Role'] == 'Lecturer' ? 'primary' : 'info'); 
                                ?>">
                                    <?php echo htmlspecialchars($u['Role']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-<?php echo $u['IsActive'] ? 'success' : 'secondary'; ?>">
                                    <?php echo $u['IsActive'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                            <td><?php echo $u['CreatedDate'] ? $u['CreatedDate']->format('d M Y') : '—'; ?></td>
                            <td>
                                <button class="btn btn-outline-warning btn-sm" data-bs-toggle="modal" data-bs-target="#edit<?php echo $u['UserID']; ?>">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-outline-danger btn-sm" <?php echo ($admin_row['UserID'] == $u['UserID']) ? 'disabled' : ''; ?> data-bs-toggle="modal" data-bs-target="#delete<?php echo $u['UserID']; ?>">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>

                        <!-- Edit Modal -->
                        <div class="modal fade" id="edit<?php echo $u['UserID']; ?>">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit User</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <form method="POST" action="user.php?username=<?php echo urlencode($username); ?>">
                                        <div class="modal-body">
                                            <input type="hidden" name="user_id" value="<?php echo $u['UserID']; ?>">
                                            <div class="row">
                                                <div class="col-md-6 mb-3">
                                                    <label>First Name <span class="text-danger">*</span></label>
                                                    <input type="text" class="form-control" name="first_name" value="<?php echo htmlspecialchars($u['FirstName']); ?>" required>
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label>Last Name <span class="text-danger">*</span></label>
                                                    <input type="text" class="form-control" name="last_name" value="<?php echo htmlspecialchars($u['LastName']); ?>" required>
                                                </div>
                                            </div>
                                            <div class="mb-3">
                                                <label>Email <span class="text-danger">*</span></label>
                                                <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($u['Email']); ?>" required>
                                            </div>
                                            <div class="mb-3">
                                                <label>Phone</label>
                                                <input type="text" class="form-control" name="phone" value="<?php echo htmlspecialchars($u['PhoneNumber'] ?? ''); ?>">
                                            </div>
                                            <div class="mb-3">
                                                <label>Role <span class="text-danger">*</span></label>
                                                <select class="form-control" name="role" required>
                                                    <option value="Student" <?php echo $u['Role'] == 'Student' ? 'selected' : ''; ?>>Student</option>
                                                    <option value="Lecturer" <?php echo $u['Role'] == 'Lecturer' ? 'selected' : ''; ?>>Lecturer</option>
                                                    <option value="Admin" <?php echo $u['Role'] == 'Admin' ? 'selected' : ''; ?>>Admin</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" name="update_user" class="btn btn-warning">Update User</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- Delete Modal -->
                        <div class="modal fade" id="delete<?php echo $u['UserID']; ?>">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title text-danger"><i class="fas fa-exclamation-triangle me-2"></i>Delete User</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <p>Permanently delete this user?</p>
                                        <strong><?php echo htmlspecialchars($u['Username'] . ' - ' . $u['FirstName'] . ' ' . $u['LastName']); ?></strong>
                                        <br><small class="text-danger">This action cannot be undone.</small>
                                    </div>
                                    <div class="modal-footer">
                                        <form method="POST" action="user.php?username=<?php echo urlencode($username); ?>">
                                            <input type="hidden" name="user_id" value="<?php echo $u['UserID']; ?>">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" name="delete_user" class="btn btn-danger">Yes, Delete</button>
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

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Add New User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="user.php?username=<?php echo urlencode($username); ?>">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label>Username <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="username" required placeholder="e.g., TP060123">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label>Email <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" name="email" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label>Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" name="password" required minlength="6">
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label>First Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="first_name" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label>Last Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="last_name" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label>Phone</label>
                        <input type="text" class="form-control" name="phone">
                    </div>
                    <div class="mb-3">
                        <label>Role <span class="text-danger">*</span></label>
                        <select class="form-control" name="role" required>
                            <option value="Student">Student</option>
                            <option value="Lecturer">Lecturer</option>
                            <option value="Admin">Admin</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_user" class="btn btn-success">
                        <i class="fas fa-plus-circle me-2"></i>Add User
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
    const roleFilter = document.getElementById('roleFilter');
    const statusFilter = document.getElementById('statusFilter');
    const rows = document.querySelectorAll('#usersTable tbody tr');

    function filterTable() {
        const search = searchInput.value.toLowerCase().trim();
        const role = roleFilter.value;
        const status = statusFilter.value;

        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            const rowRole = row.getAttribute('data-role');
            const rowStatus = row.getAttribute('data-status');

            const matchesSearch = text.includes(search);
            const matchesRole = !role || rowRole === role;
            const matchesStatus = status === '' || rowStatus === status;

            row.style.display = (matchesSearch && matchesRole && matchesStatus) ? '' : 'none';
        });
    }

    searchInput.addEventListener('keyup', filterTable);
    roleFilter.addEventListener('change', filterTable);
    statusFilter.addEventListener('change', filterTable);

    filterTable();
});
</script>

<?php
renderFooter();
sqlsrv_free_stmt($users_result);
sqlsrv_free_stmt($admin_result);
sqlsrv_close($conn);
?>