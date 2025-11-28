<?php
/**
 * AJAX handler for fetching transfer details
 */

// --------------------------- Configuration ---------------------------
$dbHost = '127.0.0.1';
$dbUser = 'root';
$dbPass = '';
$dbName = 'Trek_Tamilnadu_Testing_db';

// Create DB connection
$mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if ($mysqli->connect_errno) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}
$mysqli->set_charset('utf8mb4');

// Helper function to fetch data
function fetchAll($mysqli, $sql, $types = null, $params = []) {
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) return [];
    
    if ($types && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    if (!$stmt->execute()) {
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

// Get transfer ID from request
$transferId = $_GET['transfer_id'] ?? '';
$action = $_GET['action'] ?? 'view';

if (empty($transferId)) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Transfer ID is required']);
    exit;
}

// Fetch transfer details
$transfer = fetchOne($mysqli, "
    SELECT st.*, 
           s.supplier_name, s.supplier_code,
           ft.trail_name, ft.trail_code, ft.trail_location
    FROM stock_transfers st
    LEFT JOIN suppliers s ON st.from_supplier_id = s.supplier_id
    LEFT JOIN forest_trails ft ON st.to_trail_id = ft.trail_id
    WHERE st.transfer_id = ?
", 'i', [$transferId]);

if (!$transfer) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Transfer not found']);
    exit;
}

// Fetch transfer items
$items = fetchAll($mysqli, "
    SELECT sti.*, i.quantity as current_stock
    FROM stock_transfer_items sti
    LEFT JOIN items_data i ON sti.item_id = i.item_id
    WHERE sti.transfer_id = ?
    ORDER BY sti.item_name
", 'i', [$transferId]);

header('Content-Type: application/json');
echo json_encode([
    'transfer' => $transfer,
    'items' => $items
]);
?>