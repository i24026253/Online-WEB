<?php
// ============================================
// PHP connection to Microsoft SQL Server
// ============================================

// Your local SQL Server instance and database
$serverName = "LAPTOP-8O7OUMB4"; // <-- use your actual instance name
$connectionOptions = array(
    "Database" => "WebGrpData", // same as Django NAME
    "TrustServerCertificate" => true,
    "Encrypt" => false, // disable SSL for local development
);

// Connect using Windows Authentication
$conn = sqlsrv_connect($serverName, $connectionOptions);

// Check connection
if ($conn) {
    echo "<p style='color:green;'>✅ PHP connected to SQL Server successfully!</p>";
} else {
    echo "<p style='color:red;'>❌ Connection failed!</p>";
    die(print_r(sqlsrv_errors(), true));
}
?>
