<?php
// header.php - Reusable header component
function renderHeader($username, $user_role = 'student', $active_page = '') {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Attendance Management System</title>
        
        <!-- Bootstrap CSS -->
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <!-- Font Awesome -->
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        
        <style>
            :root {
                --primary: #2c3e50;
                --secondary: #3498db;
                --success: #27ae60;
                --warning: #f39c12;
                --danger: #e74c3c;
                --light: #ecf0f1;
                --dark: #2c3e50;
            }
            
            .sidebar {
                background: var(--primary);
                min-height: 100vh;
                transition: all 0.3s;
            }
            
            .sidebar .nav-link {
                color: white;
                padding: 12px 20px;
                margin: 2px 0;
                border-radius: 5px;
                transition: all 0.3s;
            }
            
            .sidebar .nav-link:hover,
            .sidebar .nav-link.active {
                background: var(--secondary);
                color: white;
            }
            
            .stat-card {
                border-left: 4px solid;
                transition: transform 0.3s;
            }
            
            .stat-card:hover {
                transform: translateY(-5px);
            }
            
            .card-primary { border-left-color: var(--secondary); }
            .card-success { border-left-color: var(--success); }
            .card-warning { border-left-color: var(--warning); }
            .card-danger { border-left-color: var(--danger); }
        </style>
    </head>
    <body>
        <!-- Navigation -->
        <nav class="navbar navbar-expand-lg navbar-dark" style="background: var(--primary);">
            <div class="container-fluid">
                <a class="navbar-brand" href="http://127.0.0.1:8000/dashboard/">
                    <i class="fas fa-graduation-cap me-2"></i>Attendance System
                </a>
                
                <div class="navbar-nav ms-auto">
                    <div class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user-circle me-1"></i> <?php echo htmlspecialchars($username); ?>
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="#"><i class="fas fa-user me-2"></i>Profile</a></li>
                            <li><a class="dropdown-item" href="#"><i class="fas fa-cog me-2"></i>Settings</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="http://127.0.0.1:8000/logout/"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </nav>

        <div class="container-fluid">
            <div class="row">
                <!-- Sidebar -->
                <nav class="col-md-3 col-lg-2 sidebar d-md-block">
                    <div class="position-sticky pt-3">
                        <?php if ($user_role === 'admin') { ?>
                            <h6 class="text-white px-3 mb-3">ADMIN PANEL</h6>
                            <ul class="nav flex-column">
                                <li class="nav-item">
                                    <a class="nav-link <?php echo $active_page === 'dashboard' ? 'active' : ''; ?>" href="http://127.0.0.1:8000/admin-dashboard/">
                                        <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                                    </a>
                                </li>
                                <li class="nav-item"><a class="nav-link" href="#"><i class="fas fa-users me-2"></i>Users</a></li>
                                <li class="nav-item"><a class="nav-link" href="#"><i class="fas fa-graduation-cap me-2"></i>Students</a></li>
                                <li class="nav-item"><a class="nav-link" href="#"><i class="fas fa-chalkboard-teacher me-2"></i>Lecturers</a></li>
                                <li class="nav-item">
                                    <a class="nav-link <?php echo $active_page === 'courses' ? 'active' : ''; ?>" href="http://localhost/php_module/courses.php?username=<?php echo urlencode($username); ?>">
                                        <i class="fas fa-book me-2"></i>Courses
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link <?php echo $active_page === 'pending' ? 'active' : ''; ?>" href="http://localhost/php_module/pending_approvals.php?username=<?php echo urlencode($username); ?>">
                                        <i class="fas fa-clipboard-check me-2"></i>Pending Approvals
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link <?php echo $active_page === 'semesters' ? 'active' : ''; ?>" href="http://localhost/php_module/semesters.php?username=<?php echo urlencode($username); ?>">
                                        <i class="fas fa-calendar-alt me-2"></i>Semesters
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link <?php echo $active_page === 'reports' ? 'active' : ''; ?>" href="http://127.0.0.1:8000/reports/">
                                        <i class="fas fa-chart-bar me-2"></i>Reports
                                    </a>
                                </li>
                            </ul>

                        <?php } elseif ($user_role === 'lecturer') { ?>
                            <h6 class="text-white px-3 mb-3">LECTURER PANEL</h6>
                            <ul class="nav flex-column">
                                <li class="nav-item">
                                    <a class="nav-link <?php echo $active_page === 'dashboard' ? 'active' : ''; ?>" href="http://127.0.0.1:8000/lecturer-dashboard/">
                                        <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                                    </a>
                                </li>
                                <li class="nav-item"><a class="nav-link" href="#"><i class="fas fa-book me-2"></i>My Courses</a></li>

                                <li class="nav-item">
                                    <a class="nav-link" href="http://localhost:8080/php_module/mark_attendance.php?username=<?php echo urlencode($username); ?>">
                                        <i class="fas fa-calendar-check me-2"></i>Attendance
                                    </a>
                                </li>

                                <li class="nav-item">
                                    <a class="nav-link <?php echo $active_page === 'reports' ? 'active' : ''; ?>" href="http://127.0.0.1:8000/reports/">
                                        <i class="fas fa-chart-bar me-2"></i>Reports
                                    </a>
                                </li>

                            </ul>

                        <?php } else { ?>
                            <h6 class="text-white px-3 mb-3">STUDENT PANEL</h6>
                            <ul class="nav flex-column">
                                <li class="nav-item">
                                    <a class="nav-link <?php echo $active_page === 'dashboard' ? 'active' : ''; ?>" href="http://127.0.0.1:8000/student-dashboard/?username=<?php echo urlencode($username); ?>">
                                        <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link <?php echo $active_page === 'enrol' ? 'active' : ''; ?>" href="http://localhost:8080/php_module/enrol.php?username=<?php echo urlencode($username); ?>">
                                        <i class="fas fa-book me-2"></i>My Courses
                                    </a>
                                </li>
                                <li class="nav-item"><a class="nav-link" href="#"><i class="fas fa-history me-2"></i>Attendance</a></li>
                                <li class="nav-item"><a class="nav-link" href="#"><i class="fas fa-chart-line me-2"></i>Progress</a></li>
                                <li class="nav-item">
                                    <a class="nav-link <?php echo $active_page === 'reports' ? 'active' : ''; ?>" href="http://127.0.0.1:8000/reports/">
                                        <i class="fas fa-chart-bar me-2"></i>My Reports
                                    </a>
                                </li>
                            </ul>
                        <?php } ?>
                    </div>
                </nav>

                <!-- Main content -->
                <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
    <?php
}

function renderFooter() {
    ?>
                </main>
            </div>
        </div>

        <!-- Bootstrap JS -->
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    </body>
    </html>
    <?php
}
?>