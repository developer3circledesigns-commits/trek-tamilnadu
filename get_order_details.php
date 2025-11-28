<?php
/**
 * AJAX handler for fetching order details - Forest Trekking System
 * Complete with error handling and data validation
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

function esc($s) { 
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); 
}

function getStatusBadgeClass($status) {
    switch ($status) {
        case 'Pending': return 'bg-warning text-dark';
        case 'Partially Received': return 'bg-info';
        case 'Completed': return 'bg-success';
        case 'Cancelled': return 'bg-danger';
        case 'Draft': return 'bg-secondary';
        case 'Approved': return 'bg-primary';
        case 'Rejected': return 'bg-dark';
        default: return 'bg-secondary';
    }
}

// --------------------------- Input Validation ---------------------------
// Get parameters from request
$poNo = $_GET['po_no'] ?? '';
$action = $_GET['action'] ?? 'view';

// Validate required parameters
if (empty($poNo)) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Missing required parameters',
        'message' => 'PO Number is required'
    ]);
    exit();
}

// Sanitize inputs
$poNo = trim($poNo);
$action = in_array($action, ['view', 'update']) ? $action : 'view';

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

    // Fetch PO items with status information
    $items = fetchAll($mysqli, "
        SELECT pi.*, 
               COALESCE(os.received_quantity, 0) as received_quantity,
               COALESCE(os.damaged_quantity, 0) as damaged_quantity,
               COALESCE(os.stock_quantity, 0) as stock_quantity,
               COALESCE(os.status, 'Pending') as item_status,
               COALESCE(os.remarks, '') as item_remarks
        FROM po_items pi
        LEFT JOIN order_status os ON pi.po_no = os.po_no AND pi.item_id = os.item_id
        WHERE pi.po_no = ?
        ORDER BY pi.category_name, pi.item_name
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
        'items' => [],
        'summary' => [
            'total_items' => count($items),
            'total_quantity' => 0,
            'total_received' => 0,
            'total_damaged' => 0,
            'total_stock' => 0,
            'completion_percentage' => 0
        ]
    ];

    // Process items and calculate summary
    foreach ($items as $item) {
        $itemData = [
            'po_item_id' => $item['po_item_id'],
            'item_id' => $item['item_id'],
            'item_name' => $item['item_name'],
            'item_code' => $item['item_code'],
            'category_id' => $item['category_id'],
            'category_name' => $item['category_name'],
            'category_code' => $item['category_code'],
            'quantity' => intval($item['quantity']),
            'price_per_unit' => floatval($item['price_per_unit']),
            'total_price' => floatval($item['total_price']),
            'received_quantity' => intval($item['received_quantity']),
            'damaged_quantity' => intval($item['damaged_quantity']),
            'stock_quantity' => intval($item['stock_quantity']),
            'item_status' => $item['item_status'],
            'item_remarks' => $item['item_remarks'],
            'created_at' => $item['created_at'],
            'status_badge_class' => getStatusBadgeClass($item['item_status']),
            'completion_percentage' => $item['quantity'] > 0 ? round(($item['received_quantity'] / $item['quantity']) * 100, 2) : 0
        ];

        $responseData['items'][] = $itemData;

        // Update summary
        $responseData['summary']['total_quantity'] += $itemData['quantity'];
        $responseData['summary']['total_received'] += $itemData['received_quantity'];
        $responseData['summary']['total_damaged'] += $itemData['damaged_quantity'];
        $responseData['summary']['total_stock'] += $itemData['stock_quantity'];
    }

    // Calculate overall completion percentage
    if ($responseData['summary']['total_quantity'] > 0) {
        $responseData['summary']['completion_percentage'] = round(
            ($responseData['summary']['total_received'] / $responseData['summary']['total_quantity']) * 100, 
            2
        );
    }

    // Add additional metadata
    $responseData['metadata'] = [
        'timestamp' => date('Y-m-d H:i:s'),
        'action' => $action,
        'po_no' => $poNo,
        'items_count' => count($items),
        'server_time' => time()
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