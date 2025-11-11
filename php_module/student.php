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

// Handle Add Student
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_student'])) {
    $student_number = trim($_POST['student_number']);
    $user_id = (int)$_POST['user_id'];
    $dob = $_POST['dob'] ?: null;
    $gender = $_POST['gender'];
    $address = trim($_POST['address']) ?: null;
    $city = trim($_POST['city']) ?: null;
    $state = trim($_POST['state']) ?: null;
    $zip = trim($_POST['zip']) ?: null;
    $country = trim($_POST['country']) ?: null;
    $emergency_contact = trim($_POST['emergency_contact']) ?: null;
    $emergency_phone = trim($_POST['emergency_phone']) ?: null;
    $enrollment_date = date('Y-m-d');
    $created_date = date('Y-m-d H:i:s');

    $check = sqlsrv_query($conn, "SELECT StudentID FROM dbo.Students WHERE StudentNumber = ?", array($student_number));
    if (sqlsrv_has_rows($check)) {
        $message = "<div class='alert alert-danger alert-dismissible fade show'><i class='fas fa-times-circle me-2'></i>Student Number already exists!<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
    } else {
        $insert = "INSERT INTO dbo.Students 
            (UserID, StudentNumber, DateOfBirth, Gender, Address, City, State, ZipCode, Country, EmergencyContact, EmergencyPhone, EnrollmentDate, IsActive, CreatedDate)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)";
        $params = array($user_id, $student_number, $dob, $gender, $address, $city, $state, $zip, $country, $emergency_contact, $emergency_phone, $enrollment_date, $created_date);
        $result = sqlsrv_query($conn, $insert, $params);

        if ($result) {
            $message = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-check-circle me-2'></i>Student added successfully.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        } else {
            error_log("Add error: " . print_r(sqlsrv_errors(), true));
            $message = "<div class='alert alert-danger alert-dismissible fade show'><i class='fas fa-times-circle me-2'></i>Failed to add student.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        }
    }
}

// Handle Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_student'])) {
    $student_id = (int)$_POST['student_id'];
    $dob = $_POST['dob'] ?: null;
    $gender = $_POST['gender'];
    $address = trim($_POST['address']) ?: null;
    $city = trim($_POST['city']) ?: null;
    $state = trim($_POST['state']) ?: null;
    $zip = trim($_POST['zip']) ?: null;
    $country = trim($_POST['country']) ?: null;
    $emergency_contact = trim($_POST['emergency_contact']) ?: null;
    $emergency_phone = trim($_POST['emergency_phone']) ?: null;
    $updated_date = date('Y-m-d H:i:s');

    $update = "UPDATE dbo.Students SET 
        DateOfBirth = ?, Gender = ?, Address = ?, City = ?, State = ?, ZipCode = ?, Country = ?, 
        EmergencyContact = ?, EmergencyPhone = ?, UpdatedDate = ?
        WHERE StudentID = ?";
    $params = array($dob, $gender, $address, $city, $state, $zip, $country, $emergency_contact, $emergency_phone, $updated_date, $student_id);
    $result = sqlsrv_query($conn, $update, $params);

    if ($result) {
        $message = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-check-circle me-2'></i>Student updated successfully.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
    } else {
        $message = "<div class='alert alert-danger alert-dismissible fade show'><i class='fas fa-times-circle me-2'></i>Update failed.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
    }
}

// Handle Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_student'])) {
    $student_id = (int)$_POST['student_id'];
    $delete = sqlsrv_query($conn, "DELETE FROM dbo.Students WHERE StudentID = ?", array($student_id));
    if ($delete) {
        $message = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-trash me-2'></i>Student deleted successfully.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
    } else {
        $message = "<div class='alert alert-danger alert-dismissible fade show'><i class='fas fa-times-circle me-2'></i>Delete failed.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
    }
}

// Fetch students
$students_query = "
    SELECT s.StudentID, s.StudentNumber, s.DateOfBirth, s.Gender, s.City, s.EnrollmentDate, s.IsActive,
           u.UserID, u.Username, u.FirstName, u.LastName, u.Email
    FROM dbo.Students s
    LEFT JOIN dbo.Users u ON s.UserID = u.UserID
    ORDER BY s.StudentNumber
";
$students_result = sqlsrv_query($conn, $students_query);
if (!$students_result) die("Query error: " . print_r(sqlsrv_errors(), true));

// Fetch available users for Add modal
$users_query = "SELECT UserID, Username, FirstName, LastName FROM dbo.Users WHERE Role = 'Student' AND UserID NOT IN (SELECT UserID FROM dbo.Students WHERE UserID IS NOT NULL) ORDER BY Username";
$users_result = sqlsrv_query($conn, $users_query);
$available_users = [];
while ($row = sqlsrv_fetch_array($users_result, SQLSRV_FETCH_ASSOC)) {
    $available_users[] = $row;
}

renderHeader($username, $user_role, 'students');
?>

<div class="mb-4 d-flex justify-content-between align-items-center">
    <h1 class="h2"><i class="fas fa-graduation-cap me-2"></i>Student Management</h1>

    <!-- SEARCH & FILTER BAR (TOP RIGHT) -->
    <div class="d-flex gap-2 align-items-center">
        <input type="text" id="searchInput" class="form-control form-control-sm" placeholder="Search students..." style="width: 260px;">
        <select id="genderFilter" class="form-select form-select-sm" style="width: 120px;">
            <option value="">All Genders</option>
            <option value="Male">Male</option>
            <option value="Female">Female</option>
            <option value="Other">Other</option>
        </select>
        <select id="statusFilter" class="form-select form-select-sm" style="width: 120px;">
            <option value="">All Status</option>
            <option value="1">Active</option>
            <option value="0">Inactive</option>
        </select>
        <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#addStudentModal">
            <i class="fas fa-plus-circle me-1"></i>Add Student
        </button>
    </div>
</div>

<?php if (isset($message)) echo $message; ?>

<div class="card">
    <div class="card-header bg-white">
        <h5 class="mb-0"><i class="fas fa-users me-2"></i>All Students</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover" id="studentsTable">
                <thead class="table-light">
                    <tr>
                        <th>Student Number</th>
                        <th>Name</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Gender</th>
                        <th>City</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($s = sqlsrv_fetch_array($students_result, SQLSRV_FETCH_ASSOC)) { ?>
                        <tr data-gender="<?php echo htmlspecialchars($s['Gender'] ?? ''); ?>" data-status="<?php echo $s['IsActive']; ?>">
                            <td><strong><?php echo htmlspecialchars($s['StudentNumber']); ?></strong></td>
                            <td><?php echo htmlspecialchars(($s['FirstName'] ?? '') . ' ' . ($s['LastName'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars($s['Username'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($s['Email'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($s['Gender'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($s['City'] ?? '—'); ?></td>
                            <td>
                                <span class="badge bg-<?php echo $s['IsActive'] ? 'success' : 'secondary'; ?>">
                                    <?php echo $s['IsActive'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                            <td>
                                <button class="btn btn-outline-warning btn-sm" data-bs-toggle="modal" data-bs-target="#edit<?php echo $s['StudentID']; ?>">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#delete<?php echo $s['StudentID']; ?>">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>

                        <!-- Edit Modal -->
                        <div class="modal fade" id="edit<?php echo $s['StudentID']; ?>" tabindex="-1">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Student</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <form method="POST" action="student.php?username=<?php echo urlencode($username); ?>">
                                        <div class="modal-body">
                                            <input type="hidden" name="student_id" value="<?php echo $s['StudentID']; ?>">
                                            <div class="row">
                                                <div class="col-md-6 mb-3">
                                                    <label>Date of Birth</label>
                                                    <input type="date" class="form-control" name="dob" value="<?php echo $s['DateOfBirth'] ? $s['DateOfBirth']->format('Y-m-d') : ''; ?>">
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label>Gender</label>
                                                    <select class="form-control" name="gender">
                                                        <option value="">-- Select --</option>
                                                        <option value="Male" <?php echo ($s['Gender'] ?? '') == 'Male' ? 'selected' : ''; ?>>Male</option>
                                                        <option value="Female" <?php echo ($s['Gender'] ?? '') == 'Female' ? 'selected' : ''; ?>>Female</option>
                                                        <option value="Other" <?php echo ($s['Gender'] ?? '') == 'Other' ? 'selected' : ''; ?>>Other</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="mb-3">
                                                <label>Address</label>
                                                <textarea class="form-control" name="address" rows="2"><?php echo htmlspecialchars($s['Address'] ?? ''); ?></textarea>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6 mb-3">
                                                    <label>City</label>
                                                    <input type="text" class="form-control" name="city" value="<?php echo htmlspecialchars($s['City'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label>State</label>
                                                    <input type="text" class="form-control" name="state" value="<?php echo htmlspecialchars($s['State'] ?? ''); ?>">
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6 mb-3">
                                                    <label>Zip Code</label>
                                                    <input type="text" class="form-control" name="zip" value="<?php echo htmlspecialchars($s['ZipCode'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label>Country</label>
                                                    <input type="text" class="form-control" name="country" value="<?php echo htmlspecialchars($s['Country'] ?? ''); ?>">
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6 mb-3">
                                                    <label>Emergency Contact</label>
                                                    <input type="text" class="form-control" name="emergency_contact" value="<?php echo htmlspecialchars($s['EmergencyContact'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label>Emergency Phone</label>
                                                    <input type="text" class="form-control" name="emergency_phone" value="<?php echo htmlspecialchars($s['EmergencyPhone'] ?? ''); ?>">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" name="update_student" class="btn btn-warning">Update Student</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- Delete Modal -->
                        <div class="modal fade" id="delete<?php echo $s['StudentID']; ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title text-danger"><i class="fas fa-exclamation-triangle me-2"></i>Delete Student</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <p>Are you sure you want to <strong>permanently delete</strong> this student?</p>
                                        <p><strong><?php echo htmlspecialchars($s['StudentNumber'] . ' - ' . ($s['FirstName'] ?? '') . ' ' . ($s['LastName'] ?? '')); ?></strong></p>
                                        <small class="text-danger">This action cannot be undone.</small>
                                    </div>
                                    <div class="modal-footer">
                                        <form method="POST" action="student.php?username=<?php echo urlencode($username); ?>" style="display:inline;">
                                            <input type="hidden" name="student_id" value="<?php echo $s['StudentID']; ?>">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" name="delete_student" class="btn btn-danger">Yes, Delete</button>
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

<!-- Add Student Modal -->
<div class="modal fade" id="addStudentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Add New Student Record</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="student.php?username=<?php echo urlencode($username); ?>">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label>Link to User <span class="text-danger">*</span></label>
                            <select class="form-control" name="user_id" required>
                                <option value="">-- Select Student User --</option>
                                <?php foreach ($available_users as $u): ?>
                                    <option value="<?php echo $u['UserID']; ?>">
                                        <?php echo htmlspecialchars($u['Username'] . ' - ' . $u['FirstName'] . ' ' . $u['LastName']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label>Student Number <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="student_number" required placeholder="e.g., TP060123" style="text-transform: uppercase;">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label>Date of Birth</label>
                            <input type="date" class="form-control" name="dob">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label>Gender</label>
                            <select class="form-control" name="gender">
                                <option value="">-- Select --</option>
                                <option>Male</option>
                                <option>Female</option>
                                <option>Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label>Address</label>
                        <textarea class="form-control" name="address" rows="2"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label>City</label>
                            <input type="text" class="form-control" name="city">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label>State</label>
                            <input type="text" class="form-control" name="state">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label>Zip Code</label>
                            <input type="text" class="form-control" name="zip">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label>Country</label>
                            <input type="text" class="form-control" name="country" value="Malaysia">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label>Emergency Contact</label>
                            <input type="text" class="form-control" name="emergency_contact">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label>Emergency Phone</label>
                            <input type="text" class="form-control" name="emergency_phone">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_student" class="btn btn-success">
                        <i class="fas fa-plus-circle me-2"></i>Add Student
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
    const genderFilter = document.getElementById('genderFilter');
    const statusFilter = document.getElementById('statusFilter');
    const rows = document.querySelectorAll('#studentsTable tbody tr');

    function filterTable() {
        const search = searchInput.value.toLowerCase().trim();
        const gender = genderFilter.value;
        const status = statusFilter.value;

        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            const rowGender = row.getAttribute('data-gender') || '';
            const rowStatus = row.getAttribute('data-status');

            const matchesSearch = text.includes(search);
            const matchesGender = !gender || rowGender === gender;
            const matchesStatus = status === '' || rowStatus === status;

            row.style.display = (matchesSearch && matchesGender && matchesStatus) ? '' : 'none';
        });
    }

    searchInput.addEventListener('keyup', filterTable);
    genderFilter.addEventListener('change', filterTable);
    statusFilter.addEventListener('change', filterTable);

    // Initial filter
    filterTable();
});
</script>

<?php
renderFooter();
sqlsrv_free_stmt($students_result);
sqlsrv_free_stmt($users_result);
sqlsrv_free_stmt($admin_result);
sqlsrv_close($conn);
?>