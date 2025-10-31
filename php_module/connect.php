<?php
// ============================================
// PHP connection to Microsoft SQL Server
// ============================================

// Your local SQL Server instance and database
$serverName = "localhost,1434"; // <-- use your actual instance name
$connectionOptions = array(
    "Database" => "AttendanceManagementDB", // same as Django NAME
    "TrustServerCertificate" => true,
    "Encrypt" => false, // disable SSL for local development
);

// Connect using Windows Authentication
$conn = sqlsrv_connect($serverName, $connectionOptions);

// Check connection
if ($conn) {
    error_log("✅ PHP connected to SQL Server successfully!\n");
} else {
    error_log("❌ Connection failed!\n");
    error_log(print_r(sqlsrv_errors(), true) . "\n");
}
?>