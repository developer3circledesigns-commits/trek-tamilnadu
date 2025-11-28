<?php
require_once '../config/database.php';

// Simple API endpoint
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');
    
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        $response = [
            'status' => 'success',
            'message' => 'Forest Trekking System API',
            'database' => $db ? 'connected' : 'disconnected',
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        echo json_encode($response);
    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
    }
}
?>
