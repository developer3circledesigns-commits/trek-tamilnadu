<?php
/**
 * AJAX handler for fetching PO details - Forest Trekking System
 */

// --------------------------- Configuration ---------------------------
$dbHost = '127.0.0.1';
$dbUser = 'root';
$dbPass = '';
$dbName = 'Trek_Tamilnadu_Testing_db';

// Enable CORS for AJAX requests
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Set content type to JSON
header('Content-Type: application/json; charset=utf-8');

// Create DB connection
$mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if ($mysqli->connect_errno) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Database connection failed',
        'message' => 'Failed to connect to MySQL: (' . $mysqli->connect_errno . ') ' . $mysqli->connect_error
    ]);
    exit();
}

$mysqli->set_charset('utf8mb4');
$mysqli->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

// --------------------------- Helper Functions ---------------------------
function fetchAll($mysqli, $sql, $types = null, $params = []) {
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        error_log("SQL Prepare failed: " . $mysqli->error);
        return [];
    }
    
    if ($types && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    if (!$stmt->execute()) {
        error_log("SQL Execute failed: " . $stmt->error);
        $stmt->close();
        return [];
    }
    
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function fetchOne($mysqli, $sql, $types = null, $params = []) {
    $rows = fetchAll($mysqli, $sql, $types, $params);
    return count($rows) ? $rows[0] : null;
}

// --------------------------- Input Validation ---------------------------
$poNo = $_GET['po_no'] ?? '';

if (empty($poNo)) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Missing required parameter',
        'message' => 'PO Number is required'
    ]);
    exit();
}

// Sanitize input
$poNo = trim($poNo);

// --------------------------- Fetch Data ---------------------------
try {
    // Fetch PO details
    $po = fetchOne($mysqli, "
        SELECT * FROM purchase_orders 
        WHERE po_no = ?
    ", 's', [$poNo]);

    if (!$po) {
        http_response_code(404);
        echo json_encode([
            'error' => 'Purchase order not found',
            'message' => 'No purchase order found with PO number: ' . $poNo
        ]);
        exit();
    }

    // Fetch PO items
    $items = fetchAll($mysqli, "
        SELECT * FROM po_items 
        WHERE po_no = ?
        ORDER BY item_name
    ", 's', [$poNo]);

    // Validate items were found
    if (empty($items)) {
        http_response_code(404);
        echo json_encode([
            'error' => 'No items found',
            'message' => 'No items found for purchase order: ' . $poNo
        ]);
        exit();
    }

    // Get supplier address
    $supplierAddress = fetchOne($mysqli, "
        SELECT address FROM suppliers 
        WHERE supplier_id = ?
    ", 'i', [$po['supplier_id']]);

    // --------------------------- Prepare Response Data ---------------------------
    $responseData = [
        'success' => true,
        'po' => [
            'po_id' => $po['po_id'],
            'po_no' => $po['po_no'],
            'proforma_invoice_date' => $po['proforma_invoice_date'],
            'proforma_invoice_no' => $po['proforma_invoice_no'],
            'supplier_id' => $po['supplier_id'],
            'supplier_name' => $po['supplier_name'],
            'supplier_code' => $po['supplier_code'],
            'delivery_address' => $po['delivery_address'],
            'remarks' => $po['remarks'],
            'status' => $po['status'],
            'total_items' => $po['total_items'],
            'total_quantity' => $po['total_quantity'],
            'total_amount' => $po['total_amount'],
            'created_at' => $po['created_at'],
            'updated_at' => $po['updated_at']
        ],
        'items' => $items,
        'supplier_address' => $supplierAddress ? $supplierAddress['address'] : ''
    ];

    // --------------------------- Send Response ---------------------------
    http_response_code(200);
    echo json_encode($responseData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    // Handle any unexpected errors
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        'message' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
} finally {
    // Close database connection
    if (isset($mysqli)) {
        $mysqli->close();
    }
}
?>