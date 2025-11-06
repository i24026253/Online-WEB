<?php
/* AJAX endpoint to mark alerts as read */

// ✅ CORS headers - Allow Django frontend
header('Access-Control-Allow-Origin: http://127.0.0.1:8000');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors in response
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

require_once 'connect.php';

// Log the request for debugging
error_log("=== Mark Alert Read Request ===");
error_log("Timestamp: " . date('Y-m-d H:i:s'));
error_log("Method: " . $_SERVER['REQUEST_METHOD']);
error_log("POST data: " . print_r($_POST, true));
error_log("GET data: " . print_r($_GET, true));
error_log("Raw input: " . file_get_contents('php://input'));

// ✅ Check database connection
if (!$conn) {
    error_log("ERROR: Database connection failed");
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get alert_id from POST
    $alertId = $_POST['alert_id'] ?? null;
    
    error_log("Alert ID received: " . ($alertId ?? 'NULL'));
    
    if (!$alertId || trim($alertId) === '') {
        error_log("ERROR: No alert ID provided");
        echo json_encode([
            'success' => false,
            'message' => 'Alert ID is required'
        ]);
        exit;
    }
    
    // Convert to integer and validate
    $alertId = (int)$alertId;
    
    if ($alertId <= 0) {
        error_log("ERROR: Invalid alert ID: " . $alertId);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid alert ID format'
        ]);
        exit;
    }
    
    error_log("Processing alert ID: " . $alertId);
    
    // ✅ Check if the alert exists
    $checkQuery = "SELECT AlertID, StudentID, IsRead, AlertType FROM dbo.Alerts WHERE AlertID = ?";
    $checkStmt = sqlsrv_query($conn, $checkQuery, [$alertId]);
    
    if ($checkStmt === false) {
        $errors = sqlsrv_errors();
        error_log("ERROR: Check query failed: " . print_r($errors, true));
        echo json_encode([
            'success' => false,
            'message' => 'Database error while checking alert',
            'error_details' => $errors
        ]);
        exit;
    }
    
    $alertRow = sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($checkStmt);
    
    if (!$alertRow) {
        error_log("ERROR: Alert ID $alertId not found in database");
        echo json_encode([
            'success' => false,
            'message' => 'Alert not found with ID: ' . $alertId
        ]);
        exit;
    }
    
    error_log("Alert found: ID=" . $alertRow['AlertID'] . 
              ", StudentID=" . $alertRow['StudentID'] . 
              ", IsRead=" . $alertRow['IsRead'] . 
              ", Type=" . $alertRow['AlertType']);
    
    if ($alertRow['IsRead'] == 1) {
        error_log("NOTICE: Alert $alertId already marked as read");
        echo json_encode([
            'success' => true,
            'message' => 'Alert already marked as read',
            'already_read' => true,
            'alert_id' => $alertId
        ]);
        exit;
    }
    
    // ✅ Update the alert to mark as read
    $updateQuery = "UPDATE dbo.Alerts SET IsRead = 1 WHERE AlertID = ?";
    $updateStmt = sqlsrv_query($conn, $updateQuery, [$alertId]);
    
    if ($updateStmt === false) {
        $errors = sqlsrv_errors();
        error_log("ERROR: Update query failed: " . print_r($errors, true));
        echo json_encode([
            'success' => false,
            'message' => 'Database error while updating alert',
            'error_details' => $errors
        ]);
        exit;
    }
    
    $rowsAffected = sqlsrv_rows_affected($updateStmt);
    error_log("Rows affected by UPDATE: " . $rowsAffected);
    
    sqlsrv_free_stmt($updateStmt);
    
    if ($rowsAffected > 0) {
        error_log("SUCCESS: Alert $alertId marked as read successfully");
        echo json_encode([
            'success' => true,
            'message' => 'Alert marked as read successfully',
            'alert_id' => $alertId,
            'rows_affected' => $rowsAffected
        ]);
    } else {
        error_log("WARNING: No rows were updated for alert $alertId");
        echo json_encode([
            'success' => false,
            'message' => 'No rows were updated - alert may already be read',
            'alert_id' => $alertId
        ]);
    }
    
} else {
    error_log("ERROR: Invalid request method: " . $_SERVER['REQUEST_METHOD']);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method. POST required, got: ' . $_SERVER['REQUEST_METHOD']
    ]);
}

// Close database connection
sqlsrv_close($conn);
error_log("=== End of Request ===\n");
?>