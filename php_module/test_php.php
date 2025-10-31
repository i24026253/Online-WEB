<?php
include("connect.php");

if ($conn) {
    $sql = "SELECT TOP 5 * FROM Students";
    $stmt = sqlsrv_query($conn, $sql);

    if ($stmt === false) {
        die("<h3>Query Error:</h3><pre>" . print_r(sqlsrv_errors(), true) . "</pre>");
    }

    // columns exist
    echo "<h3>Available Columns:</h3>";
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if ($row) {
        echo "<pre>";
        print_r(array_keys($row)); // all column names
        echo "</pre>";
        
        echo "<h3>Sample Data:</h3><ul>";
        // first row
        echo "<li>" . print_r($row, true) . "</li>";
        
        // remaining rows
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            echo "<li>" . print_r($row, true) . "</li>";
        }
        echo "</ul>";
    } else {
        echo "<p>No data found in Students table.</p>";
    }
    
    sqlsrv_free_stmt($stmt);
    sqlsrv_close($conn);
} else {
    echo "<h3>No database connection.</h3>";
}


echo date('Y-m-d H:i:s');
session_start();
$_SESSION['test'] = 'Hello, Session!';
echo $_SESSION['test'];
?>


