<?php
/**
 * Order Status Management Page - Forest Trekking System
 * Complete with real-time order tracking, status management, and stock updates
 */

// --------------------------- Configuration ---------------------------
$dbHost = '127.0.0.1';
$dbUser = 'root';
$dbPass = '';
$dbName = 'Trek_Tamilnadu_Testing_db';

// Create DB connection
$mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if ($mysqli->connect_errno) {
    die("Failed to connect to MySQL: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error);
}
$mysqli->set_charset('utf8mb4');
$mysqli->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

// --------------------------- Create Missing Tables ---------------------------
createMissingTables($mysqli);

function createMissingTables($mysqli) {
    // Create stock_inventory table if it doesn't exist
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS `stock_inventory` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `item_id` int(11) NOT NULL,
            `item_name` varchar(255) NOT NULL,
            `item_code` varchar(100) NOT NULL,
            `category_id` int(11) NOT NULL,
            `category_name` varchar(255) NOT NULL,
            `category_code` varchar(100) NOT NULL,
            `current_stock` int(11) NOT NULL DEFAULT 0,
            `min_stock_level` int(11) NOT NULL DEFAULT 10,
            `max_stock_level` int(11) DEFAULT NULL,
            `unit_price` decimal(10,2) DEFAULT 0.00,
            `total_value` decimal(12,2) DEFAULT 0.00,
            `last_updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_by` varchar(100) DEFAULT NULL,
            `supplier_id` int(11) DEFAULT NULL,
            `supplier_name` varchar(255) DEFAULT NULL,
            `location` varchar(255) DEFAULT 'Main Store',
            `is_active` tinyint(1) NOT NULL DEFAULT 1,
            `remarks` text DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_item` (`item_id`),
            KEY `idx_item_code` (`item_code`),
            KEY `idx_category_id` (`category_id`),
            KEY `idx_category_code` (`category_code`),
            KEY `idx_current_stock` (`current_stock`),
            KEY `idx_last_updated` (`last_updated`),
            KEY `idx_is_active` (`is_active`),
            KEY `idx_supplier_id` (`supplier_id`),
            KEY `idx_location` (`location`),
            KEY `idx_stock_level` (`current_stock`, `min_stock_level`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // Create order_status table if it doesn't exist
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS `order_status` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `po_no` varchar(100) NOT NULL,
            `category_id` int(11) NOT NULL,
            `category_name` varchar(255) NOT NULL,
            `category_code` varchar(100) NOT NULL,
            `item_id` int(11) NOT NULL,
            `item_name` varchar(255) NOT NULL,
            `item_code` varchar(100) NOT NULL,
            `total_quantity` int(11) NOT NULL,
            `received_quantity` int(11) NOT NULL DEFAULT 0,
            `damaged_quantity` int(11) NOT NULL DEFAULT 0,
            `stock_quantity` int(11) NOT NULL DEFAULT 0,
            `status` varchar(50) NOT NULL DEFAULT 'Pending',
            `remarks` text DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_po_item` (`po_no`, `item_id`),
            KEY `idx_po_no` (`po_no`),
            KEY `idx_item_id` (`item_id`),
            KEY `idx_status` (`status`),
            KEY `idx_updated_at` (`updated_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
}

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

function executeQuery($mysqli, $sql, $types = null, $params = []) {
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare query: " . $mysqli->error);
    }
    
    if ($types && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception("Failed to execute query: " . $error);
    }
    
    $stmt->close();
    return true;
}

// --------------------------- Handle Form Actions ---------------------------
$action = $_POST['action'] ?? '';
$message = '';
$message_type = '';

// Handle bulk order status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update_order_status') {
    $poNo = $_POST['po_no'] ?? '';
    $receivedQuantities = $_POST['received_quantity'] ?? [];
    $damagedQuantities = $_POST['damaged_quantity'] ?? [];
    $statuses = $_POST['status'] ?? [];
    $remarks = $_POST['remarks'] ?? [];
    $categoryIds = $_POST['category_id'] ?? [];
    
    if (empty($poNo)) {
        $message = "PO Number is required!";
        $message_type = "warning";
    } else {
        $mysqli->begin_transaction();
        
        try {
            $successCount = 0;
            $totalItems = count($receivedQuantities);
            
            foreach ($receivedQuantities as $itemId => $receivedQty) {
                $categoryId = $categoryIds[$itemId] ?? '';
                $damagedQty = $damagedQuantities[$itemId] ?? 0;
                $status = $statuses[$itemId] ?? 'Pending';
                $itemRemarks = $remarks[$itemId] ?? '';
                
                if (empty($categoryId)) {
                    continue; // Skip if category ID is missing
                }
                
                // Get the original PO item details
                $poItem = fetchOne($mysqli, "
                    SELECT * FROM po_items 
                    WHERE po_no = ? AND item_id = ?
                ", 'si', [$poNo, $itemId]);
                
                if (!$poItem) {
                    throw new Exception("PO item not found for Item ID: $itemId");
                }
                
                // Validate quantities - FIXED: Added proper validation
                $receivedQty = intval($receivedQty);
                $damagedQty = intval($damagedQty);
                $orderedQty = intval($poItem['quantity']);
                
                // Validate received quantity doesn't exceed ordered quantity
                if ($receivedQty > $orderedQty) {
                    throw new Exception("Received quantity ($receivedQty) cannot exceed ordered quantity ($orderedQty) for item: " . $poItem['item_name']);
                }
                
                // Validate damaged quantity doesn't exceed received quantity
                if ($damagedQty > $receivedQty) {
                    throw new Exception("Damaged quantity ($damagedQty) cannot exceed received quantity ($receivedQty) for item: " . $poItem['item_name']);
                }
                
                // Calculate stock quantity
                $stockQty = $receivedQty - $damagedQty;
                
                if ($stockQty < 0) {
                    throw new Exception("Stock quantity cannot be negative for item: " . $poItem['item_name']);
                }
                
                // Get previous received and damaged quantities for stock calculation
                $previousStatus = fetchOne($mysqli, "
                    SELECT received_quantity, damaged_quantity FROM order_status 
                    WHERE po_no = ? AND item_id = ?
                ", 'si', [$poNo, $itemId]);
                
                $prevReceivedQty = $previousStatus ? intval($previousStatus['received_quantity']) : 0;
                $prevDamagedQty = $previousStatus ? intval($previousStatus['damaged_quantity']) : 0;
                $prevStockQty = $prevReceivedQty - $prevDamagedQty;
                
                // Calculate net stock change (difference between current and previous)
                $netStockChange = $stockQty - $prevStockQty;
                
                // Update PO items table
                executeQuery($mysqli, "
                    UPDATE po_items 
                    SET received_quantity = ?, damaged_quantity = ?, stock_quantity = ? 
                    WHERE po_no = ? AND item_id = ?
                ", 'iiisi', [$receivedQty, $damagedQty, $stockQty, $poNo, $itemId]);
                
                // Update or insert order status record
                $existingStatus = fetchOne($mysqli, "
                    SELECT * FROM order_status 
                    WHERE po_no = ? AND item_id = ?
                ", 'si', [$poNo, $itemId]);
                
                if ($existingStatus) {
                    // Update existing record
                    executeQuery($mysqli, "
                        UPDATE order_status 
                        SET received_quantity = ?, damaged_quantity = ?, stock_quantity = ?, 
                            status = ?, remarks = ?, updated_at = CURRENT_TIMESTAMP 
                        WHERE po_no = ? AND item_id = ?
                    ", 'iiisssi', [$receivedQty, $damagedQty, $stockQty, $status, $itemRemarks, $poNo, $itemId]);
                } else {
                    // Insert new record
                    executeQuery($mysqli, "
                        INSERT INTO order_status 
                        (po_no, category_id, category_name, category_code, item_id, item_name, item_code, 
                         total_quantity, received_quantity, damaged_quantity, stock_quantity, status, remarks) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ", 'sisssisiiiiss', [
                        $poNo, $poItem['category_id'], $poItem['category_name'], $poItem['category_code'],
                        $itemId, $poItem['item_name'], $poItem['item_code'], $poItem['quantity'],
                        $receivedQty, $damagedQty, $stockQty, $status, $itemRemarks
                    ]);
                }
                
                // Update stock inventory only if there's a net change in stock - FIXED: Proper stock calculation
                if ($netStockChange != 0) {
                    updateStockInventory($mysqli, $itemId, $poItem['item_name'], $poItem['item_code'], 
                                       $poItem['category_id'], $poItem['category_name'], $poItem['category_code'], 
                                       $netStockChange);
                }
                
                $successCount++;
            }
            
            // Update main PO status - FIXED: Improved status calculation
            updatePOStatus($mysqli, $poNo);
            
            $mysqli->commit();
            
            $message = "Order status updated successfully! $successCount out of $totalItems items processed.";
            $message_type = "success";
            
        } catch (Exception $e) {
            $mysqli->rollback();
            $message = "Error updating order status: " . $e->getMessage();
            $message_type = "danger";
        }
    }
}

// Function to update stock inventory - FIXED: Proper stock calculation
function updateStockInventory($mysqli, $itemId, $itemName, $itemCode, $categoryId, $categoryName, $categoryCode, $netStockChange) {
    // Check if item exists in stock inventory
    $existingStock = fetchOne($mysqli, "
        SELECT * FROM stock_inventory WHERE item_id = ?
    ", 'i', [$itemId]);
    
    if ($existingStock) {
        // Update existing stock - FIXED: Use net change instead of adding full quantity
        executeQuery($mysqli, "
            UPDATE stock_inventory 
            SET current_stock = current_stock + ?, last_updated = CURRENT_TIMESTAMP 
            WHERE item_id = ?
        ", 'ii', [$netStockChange, $itemId]);
    } else {
        // Insert new stock record - FIXED: Use net change as initial stock
        $initialStock = $netStockChange > 0 ? $netStockChange : 0;
        executeQuery($mysqli, "
            INSERT INTO stock_inventory 
            (item_id, item_name, item_code, category_id, category_name, category_code, current_stock) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ", 'ississi', [$itemId, $itemName, $itemCode, $categoryId, $categoryName, $categoryCode, $initialStock]);
    }
}

// Function to update PO status based on item statuses - FIXED: Improved status logic
function updatePOStatus($mysqli, $poNo) {
    // Get all items for this PO with their received quantities and statuses
    $items = fetchAll($mysqli, "
        SELECT pi.quantity, pi.received_quantity, os.status
        FROM po_items pi
        LEFT JOIN order_status os ON pi.po_no = os.po_no AND pi.item_id = os.item_id
        WHERE pi.po_no = ?
    ", 's', [$poNo]);
    
    if (empty($items)) return;
    
    $totalItems = count($items);
    $totalOrderedQty = 0;
    $totalReceivedQty = 0;
    $allCancelled = true;
    $allCompleted = true;
    $hasPartialItems = false;
    $hasPendingItems = false;
    
    foreach ($items as $item) {
        $orderedQty = intval($item['quantity']);
        $receivedQty = intval($item['received_quantity']);
        $status = $item['status'] ?? 'Pending';
        
        $totalOrderedQty += $orderedQty;
        $totalReceivedQty += $receivedQty;
        
        // Check status patterns
        if ($status !== 'Cancelled') {
            $allCancelled = false;
        }
        
        if ($status !== 'Completed') {
            $allCompleted = false;
        }
        
        if ($status === 'Partially Received') {
            $hasPartialItems = true;
        }
        
        if ($status === 'Pending' && $receivedQty == 0) {
            $hasPendingItems = true;
        }
    }
    
    // Calculate received percentage
    $receivedPercentage = $totalOrderedQty > 0 ? ($totalReceivedQty / $totalOrderedQty) * 100 : 0;
    
    // Determine new status based on improved logic - FIXED: Clearer status determination
    if ($allCancelled) {
        $newStatus = 'Cancelled';
    } elseif ($allCompleted) {
        $newStatus = 'Completed';
    } elseif ($receivedPercentage == 100) {
        $newStatus = 'Completed';
    } elseif ($receivedPercentage > 0 && $receivedPercentage < 100) {
        $newStatus = 'Partially Received';
    } elseif ($hasPartialItems) {
        $newStatus = 'Partially Received';
    } else {
        $newStatus = 'Pending';
    }
    
    // Update PO status
    executeQuery($mysqli, "UPDATE purchase_orders SET status = ? WHERE po_no = ?", 'ss', [$newStatus, $poNo]);
}

// --------------------------- Fetch Data ---------------------------
// Fetch purchase orders with their items for the main table
$purchaseOrders = fetchAll($mysqli, "
    SELECT po.po_id, po.po_no, po.created_at, po.supplier_name, po.supplier_code, 
           po.total_items, po.total_quantity, po.remarks, po.status, po.total_amount,
           GROUP_CONCAT(DISTINCT pi.category_code ORDER BY pi.category_code SEPARATOR ', ') as category_codes,
           GROUP_CONCAT(DISTINCT pi.category_id ORDER BY pi.category_id SEPARATOR ',') as category_ids,
           GROUP_CONCAT(DISTINCT pi.item_id ORDER BY pi.item_id SEPARATOR ',') as item_ids,
           GROUP_CONCAT(DISTINCT pi.item_name ORDER BY pi.item_name SEPARATOR '|') as item_names
    FROM purchase_orders po
    LEFT JOIN po_items pi ON po.po_no = pi.po_no
    GROUP BY po.po_id, po.po_no, po.created_at, po.supplier_name, po.supplier_code, 
             po.total_items, po.total_quantity, po.remarks, po.status, po.total_amount
    ORDER BY po.created_at DESC
");

// Fetch all categories from PO items for filters
$categories = fetchAll($mysqli, "
    SELECT DISTINCT category_id, category_name, category_code 
    FROM po_items 
    ORDER BY category_name
");

// Fetch all items from PO items for filters
$items = fetchAll($mysqli, "
    SELECT DISTINCT item_id, item_name, item_code, category_id 
    FROM po_items 
    ORDER BY item_name
");

// Fetch order status data for statistics
$orderStatusData = fetchAll($mysqli, "
    SELECT os.*, po.supplier_name, po.supplier_code, po.created_at as po_date
    FROM order_status os
    LEFT JOIN purchase_orders po ON os.po_no = po.po_no
    ORDER BY os.updated_at DESC
");

// Calculate real-time statistics
$totalOrders = count($purchaseOrders);
$completedOrders = 0;
$pendingOrders = 0;
$cancelledOrders = 0;
$partiallyReceivedOrders = 0;

foreach ($purchaseOrders as $order) {
    switch ($order['status']) {
        case 'Completed':
            $completedOrders++;
            break;
        case 'Pending':
            $pendingOrders++;
            break;
        case 'Cancelled':
            $cancelledOrders++;
            break;
        case 'Partially Received':
            $partiallyReceivedOrders++;
            break;
    }
}

// Calculate real-time stock summary - FIXED: Get accurate stock data
$stockSummary = fetchOne($mysqli, "
    SELECT 
        COUNT(*) as total_items,
        SUM(current_stock) as total_stock,
        SUM(CASE WHEN current_stock <= 10 AND current_stock > 0 THEN 1 ELSE 0 END) as low_stock_items,
        SUM(CASE WHEN current_stock = 0 THEN 1 ELSE 0 END) as out_of_stock_items
    FROM stock_inventory
") ?? ['total_items' => 0, 'total_stock' => 0, 'low_stock_items' => 0, 'out_of_stock_items' => 0];

// Fetch low stock and out of stock items for alerts
$lowStockItems = fetchAll($mysqli, "
    SELECT item_name, item_code, current_stock 
    FROM stock_inventory 
    WHERE current_stock <= 10 AND current_stock > 0
    ORDER BY current_stock ASC
    LIMIT 10
");

$outOfStockItems = fetchAll($mysqli, "
    SELECT item_name, item_code, current_stock 
    FROM stock_inventory 
    WHERE current_stock = 0
    ORDER BY item_name
    LIMIT 10
");

// --------------------------- Utilities ---------------------------
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

function formatCurrency($amount) {
    return '₹' . number_format($amount, 2);
}

function formatDate($date) {
    return date('M j, Y', strtotime($date));
}

// Function to get PO items for a specific PO
function getPOItems($mysqli, $poNo) {
    return fetchAll($mysqli, "
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
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Order Status - Forest Trekking</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2E8B57;
            --primary-dark: #1f6e45;
            --primary-light: rgba(46, 139, 87, 0.1);
            --secondary: #6c757d;
            --success: #198754;
            --warning: #ffc107;
            --danger: #dc3545;
            --info: #0dcaf0;
            --light: #f8f9fa;
            --dark: #212529;
            --sidebar-width: 250px;
            --animation-timing: 0.3s ease;
        }
        
        body {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            overflow-x: hidden;
        }
        
        /* Sidebar Styles */
        .sidebar {
            width: var(--sidebar-width);
            background: linear-gradient(180deg, #000000ff 0%, #020000ff 100%);
            color: white;
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
            transition: all var(--animation-timing);
        }
        
        .sidebar-brand {
            padding: 1.5rem 1rem;
            border-bottom: 1px solid #444;
            text-align: center;
        }
        
        .sidebar-nav {
            padding: 1rem 0;
        }
        
        .nav-link {
            color: rgba(255,255,255,0.8);
            padding: 0.75rem 1.5rem;
            border-radius: 0;
            transition: all var(--animation-timing);
        }
        
        .nav-link:hover, .nav-link.active {
            color: white;
            background: rgba(46, 139, 87, 0.2);
            border-right: 3px solid var(--primary);
        }
        
        .nav-link i {
            width: 20px;
            margin-right: 10px;
        }
        
        .dropdown-menu {
            background: #2d2d2d;
            border: none;
            border-radius: 0;
        }
        
        .dropdown-item {
            color: rgba(255,255,255,0.8);
            padding: 0.5rem 1.5rem;
        }
        
        .dropdown-item:hover {
            background: rgba(46, 139, 87, 0.2);
            color: white;
        }
        
        /* Main Content Area */
        .main-content {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            transition: all var(--animation-timing);
        }
        
        /* Top Navigation Bar */
        .top-navbar {
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 999;
        }
        
        /* Centered Logo Styles */
        .centered-logo {
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .centered-logo img {
            max-height: 36px;
            width: auto;
            object-fit: contain;
        }
        
        /* Card Styles */
        .dashboard-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            border: none;
            transition: all var(--animation-timing);
            overflow: hidden;
        }
        
        .dashboard-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0,0,0,0.12);
        }
        
        /* Form Styles */
        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 0.2rem rgba(46, 139, 87, 0.25);
        }
        
        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 0.2rem rgba(46, 139, 87, 0.25);
        }
        
        .btn-primary {
            background: var(--primary);
            border-color: var(--primary);
            transition: all var(--animation-timing);
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
            transform: translateY(-1px);
        }
        
        /* Table Styles */
        .table-responsive {
            border-radius: 8px;
        }
        
        .table > :not(caption) > * > * {
            padding: 0.75rem 0.5rem;
            vertical-align: middle;
        }
        
        .table-hover tbody tr:hover {
            background-color: var(--primary-light);
            transform: scale(1.01);
            transition: all 0.2s ease;
        }
        
        .po-number {
            font-family: 'Courier New', monospace;
            font-weight: bold;
            color: var(--primary);
            background: var(--primary-light);
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.85rem;
        }
        
        /* Action Buttons */
        .btn-action {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
            border-radius: 4px;
            margin: 0 2px;
            transition: all var(--animation-timing);
        }
        
        .btn-action:hover {
            transform: scale(1.1);
        }
        
        /* Mobile Menu Button */
        .mobile-menu-btn {
            display: none;
            background: none;
            border: none;
            color: var(--dark);
            font-size: 1.2rem;
        }
        
        /* Animation Classes */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes slideIn {
            from { opacity: 0; transform: translateX(-20px); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        .fade-in {
            animation: fadeIn 0.5s ease-in-out;
        }
        
        .slide-in {
            animation: slideIn 0.4s ease-in-out;
        }
        
        .pulse {
            animation: pulse 2s infinite;
        }
        
        /* Stats Cards */
        .stat-card {
            border-left: 4px solid var(--primary);
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            transition: all var(--animation-timing);
        }
        
        .stat-card:hover .stat-icon {
            transform: scale(1.1);
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--secondary);
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        /* Table Header Container */
        .table-header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .sidebar {
                width: 220px;
            }
            .main-content {
                margin-left: 220px;
            }
        }
        
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
                width: 280px;
            }
            
            .sidebar.mobile-open {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .mobile-menu-btn {
                display: block !important;
            }
            
            .centered-logo {
                position: absolute;
                left: 50%;
                transform: translateX(-50%);
            }
            
            .table-responsive {
                font-size: 0.9rem;
            }
        }
        
        @media (max-width: 768px) {
            .dashboard-card {
                margin-bottom: 1rem;
            }
            
            .table-responsive {
                font-size: 0.85rem;
            }
            
            .btn-action {
                padding: 0.2rem 0.4rem;
                font-size: 0.8rem;
                margin: 0 1px;
            }
            
            .po-number {
                font-size: 0.8rem;
                padding: 2px 6px;
            }
            
            .stat-icon {
                width: 40px;
                height: 40px;
                font-size: 18px;
            }
        }
        
        @media (max-width: 576px) {
            .main-content {
                padding: 10px;
            }
            
            .dashboard-card {
                border-radius: 8px;
                margin-bottom: 0.75rem;
                padding: 1rem !important;
            }
            
            .btn-group {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .table > :not(caption) > * > * {
                padding: 0.5rem 0.25rem;
            }
            
            .modal-dialog {
                margin: 0.5rem;
            }
            
            .table-header-container {
                flex-direction: column;
                align-items: flex-start !important;
                gap: 10px;
            }
        }
        
        @media (max-width: 400px) {
            .table-responsive {
                font-size: 0.8rem;
            }
            
            .btn-action {
                padding: 0.15rem 0.3rem;
                font-size: 0.75rem;
            }
            
            .po-number {
                font-size: 0.75rem;
            }
        }
        
        /* Loading Animation */
        .skeleton {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: loading 1.5s infinite;
            border-radius: 4px;
        }
        
        @keyframes loading {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }
        
        /* Remarks text styling */
        .remarks-text {
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .category-codes {
            max-width: 150px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        /* Quantity input styling */
        .quantity-input {
            max-width: 80px;
        }
        
        /* Status badge styling */
        .status-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
        }
        
        /* Filter section styling */
        .filter-section {
            background: var(--primary-light);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        /* Item details styling */
        .item-details {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 10px;
            margin-top: 10px;
        }
        
        .item-row {
            border-bottom: 1px solid #dee2e6;
            padding: 8px 0;
        }
        
        .item-row:last-child {
            border-bottom: none;
        }
        
        /* Real-time update indicators */
        .real-time-update {
            transition: all 0.3s ease;
        }
        
        .updating {
            background-color: rgba(255, 193, 7, 0.2) !important;
        }
        
        .updated {
            background-color: rgba(40, 167, 69, 0.2) !important;
            animation: pulse 1s;
        }
        
        /* Stock level indicators */
        .stock-low {
            background-color: rgba(255, 193, 7, 0.1) !important;
            border-left: 4px solid #ffc107;
        }
        
        .stock-out {
            background-color: rgba(220, 53, 69, 0.1) !important;
            border-left: 4px solid #dc3545;
        }
        
        .stock-good {
            background-color: rgba(25, 135, 84, 0.1) !important;
            border-left: 4px solid #198754;
        }
        
        /* Stock Alert Styles */
        .stock-alert-container {
            position: fixed;
            top: 80px;
            right: 20px;
            z-index: 9999;
            max-width: 400px;
        }
        
        .stock-alert {
            animation: slideIn 0.5s ease-out;
            margin-bottom: 10px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .alert-item {
            padding: 8px 12px;
            border-left: 3px solid;
            margin-bottom: 5px;
            background: white;
            border-radius: 4px;
        }
        
        .alert-item:last-child {
            margin-bottom: 0;
        }
        
        .low-stock-alert {
            border-left-color: #ffc107;
            background: rgba(255, 193, 7, 0.05);
        }
        
        .out-of-stock-alert {
            border-left-color: #dc3545;
            background: rgba(220, 53, 69, 0.05);
        }
    </style>
</head>
<body>
    <!-- Sidebar Navigation -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <img 
                src="./images/Trek Logo.png" 
                alt="Forest Trekking Logo" 
                class="img-fluid" 
                style="
                    max-height: 40px; 
                    width: auto; 
                    object-fit: contain; 
                    background: transparent; 
                    filter: brightness(1.8) contrast(1.1);
                "
            >
        </div>
        
        <nav class="sidebar-nav">
            <ul class="nav flex-column">
                <!-- Dashboard -->
                <li class="nav-item">
                    <a class="nav-link" href="index.php">
                        <i class="bi bi-speedometer2"></i>Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="trekkers_data.php">
                        <i class="bi bi-person-walking"></i>Trekkers Data
                    </a>
                </li>
                
                <!-- Stock Master Dropdown -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-box-seam"></i>Stock Master
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="category.php"><i class="bi bi-tags me-2"></i>Category</a></li>
                        <li><a class="dropdown-item" href="items.php"><i class="bi bi-box me-2"></i>Items</a></li>
                        <li><a class="dropdown-item" href="food_menu.php"><i class="bi bi-file-text me-2"></i>Food Menu</a></li>
                        <li><a class="dropdown-item" href="supplier.php"><i class="bi bi-truck me-2"></i>Supplier</a></li>
                        <li><a class="dropdown-item" href="trails.php"><i class="bi bi-signpost me-2"></i>Trails</a></li>
                    </ul>
                </li>
                
                <!-- Food Master Dropdown -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-bag-check"></i>Purchase Order
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="purchase_order.php"><i class="bi bi-file-bar-graph me-2"></i>Create PO</a></li>
                        <li><a class="dropdown-item active" href="order_status.php"><i class="bi bi-cart-check me-2"></i>Order Status</a></li>
                    </ul>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="stock_transfer.php">
                        <i class="bi bi-arrow-left-right"></i>Stock Transfer
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="live_stocks.php">
                        <i class="bi bi-graph-up-arrow"></i>Live Stocks
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="reports.php">
                        <i class="bi bi-journal-text"></i>Reports
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#">
                        <i class="bi bi-people"></i>Users
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link" href="#">
                        <i class="bi bi-gear"></i>Settings
                    </a>
                </li>
            </ul>
        </nav>
    </div>

    <!-- Main Content Area -->
    <div class="main-content">
        <!-- Top Navigation Bar -->
        <nav class="top-navbar navbar navbar-expand-lg">
            <div class="container-fluid">
                <!-- Mobile Menu Button -->
                <button class="mobile-menu-btn" id="mobileMenuBtn">
                    <i class="bi bi-list"></i>
                </button>

                <!-- Centered Logo -->
                <div class="centered-logo">
                    <img 
                    src="./images/Tn Logo.png" 
                    alt="Forest Logo" 
                    class="img-fluid d-inline-block align-top">
                </div>

                <!-- User Menu -->
                <div class="d-flex align-items-center ms-auto">
                    <div class="dropdown">
                        <a href="#" class="d-flex align-items-center text-dark text-decoration-none dropdown-toggle" 
                           data-bs-toggle="dropdown">
                            <img src="https://ui-avatars.com/api/?name=Super+Admin&background=2E8B57&color=fff" 
                                 alt="User" 
                                 width="32" 
                                 height="32" 
                                 class="rounded-circle me-2">
                            <span class="d-none d-md-inline">Super Admin</span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="#"><i class="bi bi-person me-2"></i>Profile</a></li>
                            <li><a class="dropdown-item" href="#"><i class="bi bi-gear me-2"></i>Settings</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="#"><i class="bi bi-box-arrow-right me-2"></i>Sign Out</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Stock Alerts Container -->
        <?php if (!empty($lowStockItems) || !empty($outOfStockItems)): ?>
        <div class="stock-alert-container">
            <?php if (!empty($lowStockItems)): ?>
            <div class="alert alert-warning stock-alert">
                <div class="d-flex justify-content-between align-items-center">
                    <h6 class="mb-1"><i class="bi bi-exclamation-triangle me-2"></i>Low Stock Alert</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <div class="mt-2">
                    <?php foreach ($lowStockItems as $item): ?>
                    <div class="alert-item low-stock-alert">
                        <strong><?= esc($item['item_name']) ?></strong> (<?= esc($item['item_code']) ?>)
                        <span class="badge bg-warning float-end"><?= $item['current_stock'] ?> left</span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($outOfStockItems)): ?>
            <div class="alert alert-danger stock-alert">
                <div class="d-flex justify-content-between align-items-center">
                    <h6 class="mb-1"><i class="bi bi-x-circle me-2"></i>Out of Stock Alert</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <div class="mt-2">
                    <?php foreach ($outOfStockItems as $item): ?>
                    <div class="alert-item out-of-stock-alert">
                        <strong><?= esc($item['item_name']) ?></strong> (<?= esc($item['item_code']) ?>)
                        <span class="badge bg-danger float-end">Out of Stock</span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Main Content -->
        <div class="container-fluid py-3">
            <!-- Page Header -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="dashboard-card p-4 fade-in">
                        <div class="row align-items-center">
                            <div class="col-md-6 mb-3 mb-md-0">
                                <h2 class="mb-1 fw-bold text-dark">
                                    <i class="bi bi-cart-check me-2"></i>Order Status
                                </h2>
                                <p class="text-muted mb-0">Track and manage purchase order status in real-time</p>
                            </div>
                            <div class="col-md-6 text-md-end">
                                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#filterModal">
                                    <i class="bi bi-funnel me-2"></i>Filter Orders
                                </button>
                                <button class="btn btn-success" id="refreshData">
                                    <i class="bi bi-arrow-clockwise me-2"></i>Refresh
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="row g-3 mb-4">
                <div class="col-12 col-md-6 col-lg-3">
                    <div class="dashboard-card stat-card p-3 slide-in" style="animation-delay: 0.1s">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="fw-bold text-primary mb-1"><?= $totalOrders ?></h4>
                                <p class="text-muted mb-0 small">Total Orders</p>
                            </div>
                            <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                                <i class="bi bi-file-text"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-12 col-md-6 col-lg-3">
                    <div class="dashboard-card stat-card p-3 slide-in" style="animation-delay: 0.2s">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="fw-bold text-success mb-1"><?= $completedOrders ?></h4>
                                <p class="text-muted mb-0 small">Completed Orders</p>
                            </div>
                            <div class="stat-icon bg-success bg-opacity-10 text-success">
                                <i class="bi bi-check-circle"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-12 col-md-6 col-lg-3">
                    <div class="dashboard-card stat-card p-3 slide-in" style="animation-delay: 0.3s">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="fw-bold text-warning mb-1"><?= $pendingOrders + $partiallyReceivedOrders ?></h4>
                                <p class="text-muted mb-0 small">Pending/Partial Orders</p>
                            </div>
                            <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                                <i class="bi bi-clock"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-12 col-md-6 col-lg-3">
                    <div class="dashboard-card stat-card p-3 slide-in" style="animation-delay: 0.4s">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="fw-bold text-danger mb-1"><?= $cancelledOrders ?></h4>
                                <p class="text-muted mb-0 small">Cancelled Orders</p>
                            </div>
                            <div class="stat-icon bg-danger bg-opacity-10 text-danger">
                                <i class="bi bi-x-circle"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stock Summary Cards -->
            <div class="row g-3 mb-4">
                <div class="col-12">
                    <h5 class="fw-bold mb-3"><i class="bi bi-box-seam me-2"></i>Stock Overview</h5>
                </div>
                <div class="col-12 col-md-4">
                    <div class="dashboard-card p-3 stock-good">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="fw-bold text-success mb-1"><?= $stockSummary['total_stock'] ?? 0 ?></h4>
                                <p class="text-muted mb-0 small">Total Stock Items</p>
                            </div>
                            <div class="stat-icon bg-success bg-opacity-10 text-success">
                                <i class="bi bi-box"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="dashboard-card p-3 stock-low">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="fw-bold text-warning mb-1"><?= $stockSummary['low_stock_items'] ?? 0 ?></h4>
                                <p class="text-muted mb-0 small">Low Stock Items</p>
                            </div>
                            <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                                <i class="bi bi-exclamation-triangle"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="dashboard-card p-3 stock-out">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="fw-bold text-danger mb-1"><?= $stockSummary['out_of_stock_items'] ?? 0 ?></h4>
                                <p class="text-muted mb-0 small">Out of Stock</p>
                            </div>
                            <div class="stat-icon bg-danger bg-opacity-10 text-danger">
                                <i class="bi bi-box-x"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Message Alert -->
            <?php if (!empty($message)): ?>
                <div class="row mb-3">
                    <div class="col-12">
                        <div class="alert alert-<?= $message_type ?> alert-dismissible fade show slide-in" role="alert">
                            <i class="bi bi-<?= $message_type === 'success' ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
                            <?= esc($message) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Order Status Table -->
            <div class="row">
                <div class="col-12">
                    <div class="dashboard-card p-4 fade-in">
                        <div class="table-header-container">
                            <h5 class="fw-bold mb-0">Purchase Orders Status</h5>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-primary"><?= count($purchaseOrders) ?> Orders</span>
                                <span class="badge bg-success"><?= $completedOrders ?> Completed</span>
                                <span class="badge bg-warning"><?= $pendingOrders + $partiallyReceivedOrders ?> Pending</span>
                                <span class="badge bg-danger"><?= $cancelledOrders ?> Cancelled</span>
                            </div>
                        </div>

                        <?php if (empty($purchaseOrders)): ?>
                            <div class="empty-state">
                                <i class="bi bi-cart-check"></i>
                                <h5>No Purchase Orders Found</h5>
                                <p>Start by creating purchase orders in the Create PO page.</p>
                                <a href="purchase_order.php" class="btn btn-primary mt-2">
                                    <i class="bi bi-plus-circle me-2"></i>Create Purchase Order
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="text-center">S.No</th>
                                            <th>PO Number</th>
                                            <th>Date</th>
                                            <th>Supplier</th>
                                            <th>Categories</th>
                                            <th>Items</th>
                                            <th class="text-center">Total Quantity</th>
                                            <th class="text-center">Received</th>
                                            <th class="text-center">Status</th>
                                            <th>Remarks</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="orderTableBody">
                                        <?php foreach ($purchaseOrders as $index => $order): 
                                            $poItems = getPOItems($mysqli, $order['po_no']);
                                            $totalReceived = 0;
                                            $totalOrdered = 0;
                                            $allCancelled = true;
                                            
                                            foreach ($poItems as $item) {
                                                $totalReceived += $item['received_quantity'];
                                                $totalOrdered += $item['quantity'];
                                                if ($item['item_status'] !== 'Cancelled') {
                                                    $allCancelled = false;
                                                }
                                            }
                                            $completionPercentage = $totalOrdered > 0 ? round(($totalReceived / $totalOrdered) * 100, 2) : 0;
                                            
                                            // Determine status based on the new improved logic - FIXED: Better status calculation
                                            $displayStatus = $order['status'];
                                            if ($allCancelled) {
                                                $displayStatus = 'Cancelled';
                                            } elseif ($completionPercentage == 100) {
                                                $displayStatus = 'Completed';
                                            } elseif ($completionPercentage > 0 && $completionPercentage < 100) {
                                                $displayStatus = 'Partially Received';
                                            } elseif ($completionPercentage == 0) {
                                                $displayStatus = 'Pending';
                                            }
                                        ?>
                                            <tr class="fade-in real-time-update" id="order-<?= $order['po_id'] ?>" style="animation-delay: <?= $index * 0.05 ?>s">
                                                <td class="text-center fw-bold"><?= $index + 1 ?></td>
                                                <td>
                                                    <span class="po-number"><?= esc($order['po_no']) ?></span>
                                                </td>
                                                <td>
                                                    <small class="text-muted"><?= formatDate($order['created_at']) ?></small>
                                                </td>
                                                <td>
                                                    <strong><?= esc($order['supplier_name']) ?></strong>
                                                    <br>
                                                    <span class="badge bg-secondary"><?= esc($order['supplier_code']) ?></span>
                                                </td>
                                                <td>
                                                    <span class="category-codes" title="<?= esc($order['category_codes']) ?>">
                                                        <?= !empty($order['category_codes']) ? esc($order['category_codes']) : '<span class="text-muted">No categories</span>' ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php
                                                    $itemNames = explode('|', $order['item_names'] ?? '');
                                                    $displayItems = array_slice($itemNames, 0, 2);
                                                    ?>
                                                    <?php foreach ($displayItems as $item): ?>
                                                        <span class="badge bg-light text-dark mb-1"><?= esc($item) ?></span><br>
                                                    <?php endforeach; ?>
                                                    <?php if (count($itemNames) > 2): ?>
                                                        <small class="text-muted">+<?= count($itemNames) - 2 ?> more</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center fw-bold"><?= esc($order['total_quantity']) ?></td>
                                                <td class="text-center">
                                                    <div class="progress" style="height: 20px;">
                                                        <div class="progress-bar <?= $completionPercentage == 100 ? 'bg-success' : ($completionPercentage > 0 ? 'bg-warning' : 'bg-secondary') ?>" 
                                                             role="progressbar" 
                                                             style="width: <?= $completionPercentage ?>%"
                                                             title="<?= $completionPercentage ?>% Complete">
                                                            <?= $totalReceived ?>/<?= $totalOrdered ?>
                                                        </div>
                                                    </div>
                                                    <small class="text-muted"><?= $completionPercentage ?>%</small>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge status-badge <?= getStatusBadgeClass($displayStatus) ?>">
                                                        <?= esc($displayStatus) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="remarks-text" title="<?= esc($order['remarks']) ?>">
                                                        <?= !empty($order['remarks']) ? esc($order['remarks']) : '<span class="text-muted">No remarks</span>' ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <button class="btn btn-primary btn-sm btn-action view-order" 
                                                            data-po-no="<?= esc($order['po_no']) ?>" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#viewOrderModal">
                                                        <i class="bi bi-eye"></i> View
                                                    </button>
                                                    <button class="btn btn-warning btn-sm btn-action update-order" 
                                                            data-po-no="<?= esc($order['po_no']) ?>" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#updateOrderModal">
                                                        <i class="bi bi-pencil"></i> Update
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Modal -->
    <div class="modal fade" id="filterModal" tabindex="-1" aria-labelledby="filterModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="filterModalLabel">
                        <i class="bi bi-funnel me-2"></i>Filter Orders
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label for="filterPO" class="form-label">PO Number</label>
                            <select class="form-select" id="filterPO">
                                <option value="">All POs</option>
                                <?php foreach ($purchaseOrders as $po): ?>
                                    <option value="<?= esc($po['po_no']) ?>"><?= esc($po['po_no']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-12">
                            <label for="filterCategory" class="form-label">Category</label>
                            <select class="form-select" id="filterCategory">
                                <option value="">All Categories</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= $category['category_id'] ?>"><?= esc($category['category_name']) ?> (<?= esc($category['category_code']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-12">
                            <label for="filterStatus" class="form-label">Status</label>
                            <select class="form-select" id="filterStatus">
                                <option value="">All Status</option>
                                <option value="Pending">Pending</option>
                                <option value="Partially Received">Partially Received</option>
                                <option value="Completed">Completed</option>
                                <option value="Cancelled">Cancelled</option>
                                <option value="Draft">Draft</option>
                                <option value="Approved">Approved</option>
                                <option value="Rejected">Rejected</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="applyFilter">
                        <i class="bi bi-check-circle me-2"></i>Apply Filter
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- View Order Details Modal -->
    <div class="modal fade" id="viewOrderModal" tabindex="-1" aria-labelledby="viewOrderModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="viewOrderModalLabel">
                        <i class="bi bi-eye me-2"></i>Order Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="viewOrderModalBody">
                    <!-- Order details will be loaded here dynamically -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Update Order Status Modal -->
    <div class="modal fade" id="updateOrderModal" tabindex="-1" aria-labelledby="updateOrderModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="updateOrderModalLabel">
                        <i class="bi bi-pencil me-2"></i>Update Order Status
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="updateOrderModalBody">
                    <!-- Update form will be loaded here dynamically -->
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Mobile menu functionality
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('sidebar');

        mobileMenuBtn.addEventListener('click', () => {
            sidebar.classList.toggle('mobile-open');
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 992) {
                if (!sidebar.contains(e.target) && !mobileMenuBtn.contains(e.target)) {
                    sidebar.classList.remove('mobile-open');
                }
            }
        });

        // Auto-dismiss alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);

        // Refresh data button
        document.getElementById('refreshData')?.addEventListener('click', function() {
            this.innerHTML = '<i class="bi bi-arrow-clockwise spinner-border spinner-border-sm me-2"></i>Refreshing...';
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        });

        // Filter functionality
        document.getElementById('applyFilter')?.addEventListener('click', function() {
            const poFilter = document.getElementById('filterPO').value;
            const categoryFilter = document.getElementById('filterCategory').value;
            const statusFilter = document.getElementById('filterStatus').value;
            
            const rows = document.querySelectorAll('#orderTableBody tr');
            let visibleCount = 0;
            
            rows.forEach(row => {
                const poNo = row.querySelector('.po-number').textContent;
                const categoryCodes = row.querySelector('.category-codes').textContent;
                const status = row.querySelector('.status-badge').textContent.trim();
                
                let showRow = true;
                
                if (poFilter && poNo !== poFilter) showRow = false;
                if (categoryFilter && !categoryCodes.includes(categoryFilter)) showRow = false;
                if (statusFilter && status !== statusFilter) showRow = false;
                
                if (showRow) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            // Update record count
            const badge = document.querySelector('.table-header-container .badge');
            if (badge) {
                badge.textContent = visibleCount + ' Orders';
            }
            
            // Close modal
            const filterModal = bootstrap.Modal.getInstance(document.getElementById('filterModal'));
            filterModal.hide();
        });

        // View order functionality
        document.querySelectorAll('.view-order').forEach(button => {
            button.addEventListener('click', function() {
                const poNo = this.getAttribute('data-po-no');
                loadOrderDetails(poNo, 'view');
            });
        });

        // Update order functionality
        document.querySelectorAll('.update-order').forEach(button => {
            button.addEventListener('click', function() {
                const poNo = this.getAttribute('data-po-no');
                loadOrderDetails(poNo, 'update');
            });
        });

        function loadOrderDetails(poNo, action) {
            const modalBodyId = action === 'view' ? 'viewOrderModalBody' : 'updateOrderModalBody';
            
            // Show loading state
            document.getElementById(modalBodyId).innerHTML = `
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2">Loading order details...</p>
                </div>
            `;

            // Create a simple AJAX request to fetch order details
            const xhr = new XMLHttpRequest();
            xhr.open('GET', `get_order_details.php?po_no=${encodeURIComponent(poNo)}&action=${action}`, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        const data = JSON.parse(xhr.responseText);
                        
                        if (data.error) {
                            document.getElementById(modalBodyId).innerHTML = `
                                <div class="alert alert-danger">
                                    <i class="bi bi-exclamation-triangle me-2"></i>${data.error}
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                </div>
                            `;
                        } else {
                            if (action === 'view') {
                                displayOrderDetails(data);
                            } else {
                                displayUpdateForm(data);
                            }
                        }
                    } catch (e) {
                        console.error('Error parsing JSON:', e);
                        document.getElementById(modalBodyId).innerHTML = `
                            <div class="alert alert-danger">
                                <i class="bi bi-exclamation-triangle me-2"></i>Error loading order details. Please try again.
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            </div>
                        `;
                    }
                } else {
                    document.getElementById(modalBodyId).innerHTML = `
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle me-2"></i>Failed to load order details. Please try again.
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        </div>
                    `;
                }
            };
            
            xhr.onerror = function() {
                document.getElementById(modalBodyId).innerHTML = `
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle me-2"></i>Network error. Please check your connection and try again.
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                `;
            };
            
            xhr.send();
        }

        function displayOrderDetails(data) {
            const { po, items } = data;
            
            let itemsHtml = '';
            let totalReceived = 0;
            let totalDamaged = 0;
            let totalStock = 0;
            let totalOrdered = 0;
            let allCancelled = true;
            
            items.forEach(item => {
                itemsHtml += `
                    <div class="item-row">
                        <div class="row">
                            <div class="col-md-3">
                                <strong>${item.item_name}</strong>
                                <br><span class="badge bg-secondary">${item.item_code}</span>
                            </div>
                            <div class="col-md-2">
                                <strong>Category:</strong><br>
                                ${item.category_name} (${item.category_code})
                            </div>
                            <div class="col-md-1 text-center">
                                <strong>Total:</strong><br>
                                ${item.quantity}
                            </div>
                            <div class="col-md-2 text-center">
                                <strong>Received:</strong><br>
                                <span class="badge bg-success">${item.received_quantity}</span>
                            </div>
                            <div class="col-md-2 text-center">
                                <strong>Damaged:</strong><br>
                                <span class="badge bg-danger">${item.damaged_quantity}</span>
                            </div>
                            <div class="col-md-2 text-center">
                                <strong>Stock:</strong><br>
                                <span class="badge bg-primary">${item.stock_quantity}</span>
                            </div>
                        </div>
                        <div class="row mt-2">
                            <div class="col-12">
                                <strong>Status:</strong> 
                                <span class="badge ${getStatusBadgeClass(item.item_status)}">${item.item_status}</span>
                                ${item.item_remarks ? `<br><strong>Remarks:</strong> ${item.item_remarks}` : ''}
                            </div>
                        </div>
                    </div>
                `;
                
                totalReceived += parseInt(item.received_quantity);
                totalDamaged += parseInt(item.damaged_quantity);
                totalStock += parseInt(item.stock_quantity);
                totalOrdered += parseInt(item.quantity);
                
                if (item.item_status !== 'Cancelled') {
                    allCancelled = false;
                }
            });

            const completionPercentage = totalOrdered > 0 ? Math.round((totalReceived / totalOrdered) * 100) : 0;
            
            // Determine display status based on the new improved logic - FIXED: Better status calculation
            let displayStatus = po.status;
            if (allCancelled) {
                displayStatus = 'Cancelled';
            } else if (completionPercentage == 100) {
                displayStatus = 'Completed';
            } else if (completionPercentage > 0 && completionPercentage < 100) {
                displayStatus = 'Partially Received';
            } else if (completionPercentage == 0) {
                displayStatus = 'Pending';
            }

            const html = `
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>PO Number:</strong> ${po.po_no}</p>
                        <p><strong>Supplier:</strong> ${po.supplier_name} (${po.supplier_code})</p>
                        <p><strong>Total Amount:</strong> ₹${parseFloat(po.total_amount).toLocaleString('en-IN', {minimumFractionDigits: 2})}</p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Date:</strong> ${new Date(po.created_at).toLocaleDateString()}</p>
                        <p><strong>Status:</strong> <span class="badge ${getStatusBadgeClass(displayStatus)}">${displayStatus}</span></p>
                        <p><strong>Completion:</strong> 
                            <div class="progress mt-1">
                                <div class="progress-bar ${completionPercentage == 100 ? 'bg-success' : (completionPercentage > 0 ? 'bg-warning' : 'bg-secondary')}" 
                                     style="width: ${completionPercentage}%"
                                     title="${completionPercentage}% Complete">
                                    ${completionPercentage}%
                                </div>
                            </div>
                        </p>
                    </div>
                </div>
                
                <div class="row mt-3">
                    <div class="col-12">
                        <h6>Order Items (${items.length} items)</h6>
                        <div class="item-details">
                            ${itemsHtml}
                        </div>
                    </div>
                </div>
                
                <div class="row mt-3">
                    <div class="col-md-3 text-center">
                        <h6>Total Ordered</h6>
                        <h4 class="text-primary">${totalOrdered}</h4>
                    </div>
                    <div class="col-md-3 text-center">
                        <h6>Total Received</h6>
                        <h4 class="text-success">${totalReceived}</h4>
                    </div>
                    <div class="col-md-3 text-center">
                        <h6>Total Damaged</h6>
                        <h4 class="text-danger">${totalDamaged}</h4>
                    </div>
                    <div class="col-md-3 text-center">
                        <h6>Total Stock</h6>
                        <h4 class="text-info">${totalStock}</h4>
                    </div>
                </div>
                
                ${po.remarks ? `
                <div class="row mt-3">
                    <div class="col-12">
                        <h6>Remarks</h6>
                        <p>${po.remarks}</p>
                    </div>
                </div>
                ` : ''}
            `;

            document.getElementById('viewOrderModalBody').innerHTML = html;
        }

        function displayUpdateForm(data) {
            const { po, items } = data;
            
            let itemsHtml = '';
            
            items.forEach((item, index) => {
                itemsHtml += `
                    <div class="item-details mb-3">
                        <div class="row">
                            <div class="col-md-4">
                                <strong>${item.item_name}</strong>
                                <br><span class="badge bg-secondary">${item.item_code}</span>
                                <br><small class="text-muted">${item.category_name} (${item.category_code})</small>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Total Quantity</label>
                                <input type="number" class="form-control" value="${item.quantity}" readonly>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Received Quantity</label>
                                <input type="number" class="form-control received-qty" 
                                       name="received_quantity[${item.item_id}]" 
                                       value="${item.received_quantity}" min="0" max="${item.quantity}"
                                       onchange="calculateStock(${index})">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Damaged Quantity</label>
                                <input type="number" class="form-control damaged-qty" 
                                       name="damaged_quantity[${item.item_id}]" 
                                       value="${item.damaged_quantity}" min="0"
                                       onchange="calculateStock(${index})">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Stock Quantity</label>
                                <input type="number" class="form-control stock-qty" 
                                       value="${item.stock_quantity}" readonly>
                            </div>
                        </div>
                        <div class="row mt-2">
                            <div class="col-md-6">
                                <label class="form-label">Status</label>
                                <select class="form-select item-status" name="status[${item.item_id}]">
                                    <option value="Pending" ${item.item_status === 'Pending' ? 'selected' : ''}>Pending</option>
                                    <option value="Partially Received" ${item.item_status === 'Partially Received' ? 'selected' : ''}>Partially Received</option>
                                    <option value="Completed" ${item.item_status === 'Completed' ? 'selected' : ''}>Completed</option>
                                    <option value="Cancelled" ${item.item_status === 'Cancelled' ? 'selected' : ''}>Cancelled</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Remarks</label>
                                <textarea class="form-control" name="remarks[${item.item_id}]" rows="2" placeholder="Enter remarks for this item...">${item.item_remarks || ''}</textarea>
                            </div>
                        </div>
                        <input type="hidden" name="category_id[${item.item_id}]" value="${item.category_id}">
                    </div>
                `;
            });

            const html = `
                <form method="POST" id="updateOrderForm">
                    <input type="hidden" name="action" value="update_order_status">
                    <input type="hidden" name="po_no" value="${po.po_no}">
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <p><strong>PO Number:</strong> ${po.po_no}</p>
                            <p><strong>Supplier:</strong> ${po.supplier_name} (${po.supplier_code})</p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Current Status:</strong> <span class="badge ${getStatusBadgeClass(po.status)}">${po.status}</span></p>
                            <p><strong>Total Items:</strong> ${items.length}</p>
                        </div>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Update Instructions:</strong> Enter received and damaged quantities for each item. Stock quantity will be calculated automatically (Received - Damaged).
                    </div>
                    
                    <h6>Update Item Status</h6>
                    ${itemsHtml}
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="updateOrderBtn">
                            <i class="bi bi-check-circle me-2"></i>Update Status
                        </button>
                    </div>
                </form>
            `;

            document.getElementById('updateOrderModalBody').innerHTML = html;
            
            // Add form submission handler
            document.getElementById('updateOrderForm').addEventListener('submit', function(e) {
                e.preventDefault();
                submitUpdateForm(this, po.po_no);
            });
        }

        function calculateStock(index) {
            const receivedInput = document.querySelectorAll('.received-qty')[index];
            const damagedInput = document.querySelectorAll('.damaged-qty')[index];
            const stockInput = document.querySelectorAll('.stock-qty')[index];
            
            const receivedQty = parseInt(receivedInput.value) || 0;
            const damagedQty = parseInt(damagedInput.value) || 0;
            const stockQty = receivedQty - damagedQty;
            
            stockInput.value = stockQty >= 0 ? stockQty : 0;
            
            // Auto-update status based on quantities
            const totalQty = parseInt(receivedInput.closest('.item-details').querySelector('input[readonly]').value) || 0;
            const statusSelect = document.querySelectorAll('.item-status')[index];
            
            if (receivedQty >= totalQty) {
                statusSelect.value = 'Completed';
            } else if (receivedQty > 0) {
                statusSelect.value = 'Partially Received';
            } else {
                statusSelect.value = 'Pending';
            }
        }

        function submitUpdateForm(form, poNo) {
            const formData = new FormData(form);
            const submitBtn = form.querySelector('button[type="submit"]');
            
            // Show updating state in the table row
            const tableRow = document.querySelector(`[data-po-no="${poNo}"]`).closest('tr');
            if (tableRow) {
                tableRow.classList.add('updating');
            }
            
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="bi bi-arrow-repeat spinner-border spinner-border-sm me-2"></i>Updating...';
            
            // Submit form data
            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'order_status.php', true);
            
            xhr.onload = function() {
                if (xhr.status === 200) {
                    // Success - reload the page to show updated data
                    const modal = bootstrap.Modal.getInstance(document.getElementById('updateOrderModal'));
                    modal.hide();
                    
                    // Show success message
                    showAlert('Order status updated successfully! Stock inventory has been updated.', 'success');
                    
                    // Reload page after a short delay
                    setTimeout(() => {
                        window.location.reload();
                    }, 2000);
                } else {
                    // Error
                    showAlert('Failed to update order status. Please try again.', 'danger');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="bi bi-check-circle me-2"></i>Update Status';
                    
                    // Remove updating state from table row
                    if (tableRow) {
                        tableRow.classList.remove('updating');
                    }
                }
            };
            
            xhr.onerror = function() {
                showAlert('Network error. Please check your connection and try again.', 'danger');
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="bi bi-check-circle me-2"></i>Update Status';
                
                // Remove updating state from table row
                if (tableRow) {
                    tableRow.classList.remove('updating');
                }
            };
            
            xhr.send(formData);
        }

        function showAlert(message, type) {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type} alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3`;
            alertDiv.style.zIndex = '9999';
            alertDiv.innerHTML = `
                <i class="bi bi-${type === 'success' ? 'check-circle' : 'exclamation-triangle'} me-2"></i>
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            document.body.appendChild(alertDiv);
            
            // Auto remove after 5 seconds
            setTimeout(() => {
                if (alertDiv.parentNode) {
                    alertDiv.parentNode.removeChild(alertDiv);
                }
            }, 5000);
        }

        function getStatusBadgeClass(status) {
            switch (status) {
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

        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
</body>
</html>