<?php
/* AJAX endpoint to mark alerts as read */

// CORS headers 
header('Access-Control-Allow-Origin: http://127.0.0.1:8000');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');


if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'connect.php';

// Log the request for debugging
error_log("=== Mark Alert Read Request ===");
error_log("Method: " . $_SERVER['REQUEST_METHOD']);
error_log("POST data: " . print_r($_POST, true));
error_log("Raw input: " . file_get_contents('php://input'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $alertId = $_POST['alert_id'] ?? null;
    
    error_log("Alert ID received: " . $alertId);
    
    if (!$alertId) {
        error_log("ERROR: No alert ID provided");
        echo json_encode([
            'success' => false,
            'message' => 'Alert ID is required'
        ]);
        exit;
    }
    
    // Convert to integer 
    $alertId = (int)$alertId;
    error_log("Alert ID (int): " . $alertId);
    
    // check if the alert exists
    $checkQuery = "SELECT AlertID, IsRead FROM dbo.Alerts WHERE AlertID = ?";
    $checkStmt = sqlsrv_query($conn, $checkQuery, [$alertId]);
    
    if ($checkStmt === false) {
        $errors = sqlsrv_errors();
        error_log("ERROR: Check query failed: " . print_r($errors, true));
        echo json_encode([
            'success' => false,
            'message' => 'Database error while checking alert'
        ]);
        exit;
    }
    
    $alertRow = sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($checkStmt);
    
    if (!$alertRow) {
        error_log("ERROR: Alert ID $alertId not found");
        echo json_encode([
            'success' => false,
            'message' => 'Alert not found with ID: ' . $alertId
        ]);
        exit;
    }
    
    error_log("Alert found: ID=" . $alertRow['AlertID'] . ", IsRead=" . $alertRow['IsRead']);
    
    if ($alertRow['IsRead'] == 1) {
        error_log("NOTICE: Alert already marked as read");
        echo json_encode([
            'success' => true,
            'message' => 'Alert already marked as read',
            'already_read' => true
        ]);
        exit;
    }
    
    // Update the alert to mark as read
    $query = "UPDATE dbo.Alerts SET IsRead = 1 WHERE AlertID = ?";
    $stmt = sqlsrv_query($conn, $query, [$alertId]);
    
    if ($stmt === false) {
        $errors = sqlsrv_errors();
        error_log("ERROR: Update query failed: " . print_r($errors, true));
        echo json_encode([
            'success' => false,
            'message' => 'Database error while updating alert'
        ]);
        exit;
    }
    
    $rowsAffected = sqlsrv_rows_affected($stmt);
    error_log("Rows affected: " . $rowsAffected);
    
    sqlsrv_free_stmt($stmt);
    
    if ($rowsAffected > 0) {
        error_log("SUCCESS: Alert $alertId marked as read");
        echo json_encode([
            'success' => true,
            'message' => 'Alert marked as read successfully',
            'alert_id' => $alertId
        ]);
    } else {
        error_log("WARNING: No rows were updated for alert $alertId");
        echo json_encode([
            'success' => false,
            'message' => 'No rows were updated'
        ]);
    }
    
} else {
    error_log("ERROR: Invalid request method: " . $_SERVER['REQUEST_METHOD']);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method. POST required'
    ]);
}

sqlsrv_close($conn);
error_log("=== End of Request ===\n");
?>