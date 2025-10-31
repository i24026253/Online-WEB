<?php
/**
 * mark_alert_read.php
 * AJAX endpoint to mark alerts as read
 */

require_once 'connect.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $alertId = $_POST['alert_id'] ?? null;
    
    if (!$alertId) {
        echo json_encode([
            'success' => false,
            'message' => 'Alert ID is required'
        ]);
        exit;
    }
    
    $query = "UPDATE dbo.Alerts SET IsRead = 1 WHERE AlertID = ?";
    $stmt = sqlsrv_query($conn, $query, [$alertId]);
    
    if ($stmt === false) {
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . print_r(sqlsrv_errors(), true)
        ]);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Alert marked as read'
    ]);
    
    sqlsrv_free_stmt($stmt);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method'
    ]);
}

sqlsrv_close($conn);
?>