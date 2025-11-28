<?php
/**
 * Items Management Page - Forest Trekking System
 * Complete with animations and responsive design
 * Modified: Removed supplier from main table, added multi-supplier support
 * Enhanced: Real-time stock integration with order status updates
 * Updated: Removed manual stock update, automated from order_status table
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

// --------------------------- Helper Functions ---------------------------
function generateItemCode($categoryName, $itemName, $mysqli) {
    // Get words from category name
    $words = preg_split('/\s+/', trim($categoryName));
    $code = '';
    
    // If single word, take first 2 letters
    if (count($words) == 1) {
        $code = strtoupper(substr($words[0], 0, 2));
    } else {
        // If multiple words, take first letter of first word and first letter of second word
        $code = strtoupper(substr($words[0], 0, 1));
        if (isset($words[1])) {
            $code .= strtoupper(substr($words[1], 0, 1));
        }
    }
    
    // Ensure we have exactly 2 characters
    if (strlen($code) < 2) {
        $code = str_pad($code, 2, 'X');
    } elseif (strlen($code) > 2) {
        $code = substr($code, 0, 2);
    }
    
    // Get the next sequence number for this category
    $pattern = $code . '%';
    $stmt = $mysqli->prepare("SELECT COUNT(*) as count FROM items_data WHERE item_code LIKE ?");
    if ($stmt) {
        $stmt->bind_param('s', $pattern);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $sequence = $row['count'] + 1;
        $stmt->close();
    } else {
        $sequence = 1;
    }
    
    return $code . str_pad($sequence, 3, '0', STR_PAD_LEFT);
}

function fetchAll($mysqli, $sql, $types = null, $params = []) {
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        return [];
    }
    
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

// Function to update item quantity from order_status table
function updateItemQuantityFromOrders($mysqli, $itemCode) {
    if (empty($itemCode)) return;
    
    // Get total stock quantity from order_status for this item
    $stmt = $mysqli->prepare("
        SELECT SUM(stock_quantity) as total_stock 
        FROM order_status 
        WHERE item_code = ? AND status = 'Completed'
    ");
    
    if ($stmt) {
        $stmt->bind_param('s', $itemCode);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $totalStock = $row['total_stock'] ?? 0;
        $stmt->close();
        
        // Update items_data table quantity
        $updateStmt = $mysqli->prepare("UPDATE items_data SET quantity = ?, updated_at = CURRENT_TIMESTAMP WHERE item_code = ?");
        if ($updateStmt) {
            $updateStmt->bind_param('is', $totalStock, $itemCode);
            $updateStmt->execute();
            $updateStmt->close();
        }
        
        // Also update stock_inventory table
        $stockStmt = $mysqli->prepare("
            INSERT INTO stock_inventory (item_id, item_name, item_code, category_id, category_name, category_code, current_stock) 
            SELECT i.item_id, i.item_name, i.item_code, i.category_id, c.category_name, c.category_code, i.quantity 
            FROM items_data i 
            LEFT JOIN categories c ON i.category_id = c.category_id 
            WHERE i.item_code = ?
            ON DUPLICATE KEY UPDATE current_stock = VALUES(current_stock), last_updated = CURRENT_TIMESTAMP
        ");
        if ($stockStmt) {
            $stockStmt->bind_param('s', $itemCode);
            $stockStmt->execute();
            $stockStmt->close();
        }
        
        return $totalStock;
    }
    
    return 0;
}

// Function to update all items quantities from order_status
function updateAllItemsQuantities($mysqli) {
    $items = fetchAll($mysqli, "SELECT item_code FROM items_data");
    foreach ($items as $item) {
        updateItemQuantityFromOrders($mysqli, $item['item_code']);
    }
}

// --------------------------- Handle Form Actions ---------------------------
$action = $_POST['action'] ?? '';
$message = '';
$message_type = '';

// Update all items quantities from order_status table on page load
updateAllItemsQuantities($mysqli);

if ($action === 'add_item') {
    $itemName = trim($_POST['item_name'] ?? '');
    $categoryId = $_POST['category_id'] ?? '';
    $supplierIds = $_POST['supplier_ids'] ?? [];
    $initialQuantity = intval($_POST['initial_quantity'] ?? 0);
    
    if (!empty($itemName) && !empty($categoryId) && !empty($supplierIds)) {
        // Get category name for code generation
        $category = fetchOne($mysqli, "SELECT category_name FROM categories WHERE category_id = ?", 'i', [$categoryId]);
        $categoryName = $category ? $category['category_name'] : 'GN';
        
        $itemCode = generateItemCode($categoryName, $itemName, $mysqli);
        
        // Start transaction
        $mysqli->begin_transaction();
        
        try {
            // Insert main item
            $stmt = $mysqli->prepare("INSERT INTO items_data (item_code, item_name, category_id, quantity) VALUES (?, ?, ?, ?)");
            if (!$stmt) {
                throw new Exception("Failed to prepare item insert statement");
            }
            
            $stmt->bind_param('ssii', $itemCode, $itemName, $categoryId, $initialQuantity);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to insert item: " . $stmt->error);
            }
            
            $itemId = $mysqli->insert_id;
            $stmt->close();
            
            // Insert item-supplier relationships
            $supplierStmt = $mysqli->prepare("INSERT INTO item_suppliers (item_id, supplier_id, is_primary) VALUES (?, ?, ?)");
            if (!$supplierStmt) {
                throw new Exception("Failed to prepare item-supplier insert statement");
            }
            
            $isFirst = true;
            foreach ($supplierIds as $supplierId) {
                if (!empty($supplierId)) {
                    $isPrimary = $isFirst;
                    $isFirst = false;
                    
                    $supplierStmt->bind_param('iii', $itemId, $supplierId, $isPrimary);
                    if (!$supplierStmt->execute()) {
                        throw new Exception("Failed to insert item-supplier relationship: " . $supplierStmt->error);
                    }
                }
            }
            
            $supplierStmt->close();
            
            // Update stock inventory
            $stockStmt = $mysqli->prepare("
                INSERT INTO stock_inventory (item_id, item_name, item_code, category_id, category_name, category_code, current_stock) 
                SELECT i.item_id, i.item_name, i.item_code, i.category_id, c.category_name, c.category_code, i.quantity 
                FROM items_data i 
                LEFT JOIN categories c ON i.category_id = c.category_id 
                WHERE i.item_id = ?
                ON DUPLICATE KEY UPDATE current_stock = VALUES(current_stock), last_updated = CURRENT_TIMESTAMP
            ");
            if ($stockStmt) {
                $stockStmt->bind_param('i', $itemId);
                $stockStmt->execute();
                $stockStmt->close();
            }
            
            $mysqli->commit();
            
            $message = "Item added successfully with " . count($supplierIds) . " supplier(s)! Code: " . $itemCode;
            $message_type = "success";
            
        } catch (Exception $e) {
            $mysqli->rollback();
            $message = "Error adding item: " . $e->getMessage();
            $message_type = "danger";
        }
    } else {
        $message = "Please fill all required fields and add at least one supplier!";
        $message_type = "warning";
    }
} elseif ($action === 'update_item') {
    $itemId = $_POST['item_id'] ?? '';
    $itemName = trim($_POST['item_name'] ?? '');
    $categoryId = $_POST['category_id'] ?? '';
    $description = trim($_POST['description'] ?? '');
    $supplierIds = $_POST['supplier_ids'] ?? [];
    
    if (!empty($itemId) && !empty($itemName) && !empty($categoryId) && !empty($supplierIds)) {
        // Start transaction
        $mysqli->begin_transaction();
        
        try {
            // Update main item
            $stmt = $mysqli->prepare("UPDATE items_data SET item_name = ?, category_id = ?, description = ?, updated_at = CURRENT_TIMESTAMP WHERE item_id = ?");
            if (!$stmt) {
                throw new Exception("Failed to prepare item update statement");
            }
            
            $stmt->bind_param('sisi', $itemName, $categoryId, $description, $itemId);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to update item: " . $stmt->error);
            }
            $stmt->close();
            
            // Delete existing suppliers for this item
            $deleteStmt = $mysqli->prepare("DELETE FROM item_suppliers WHERE item_id = ?");
            if (!$deleteStmt) {
                throw new Exception("Failed to prepare delete suppliers statement");
            }
            
            $deleteStmt->bind_param('i', $itemId);
            if (!$deleteStmt->execute()) {
                throw new Exception("Failed to delete existing suppliers: " . $deleteStmt->error);
            }
            $deleteStmt->close();
            
            // Insert new item-supplier relationships
            $supplierStmt = $mysqli->prepare("INSERT INTO item_suppliers (item_id, supplier_id, is_primary) VALUES (?, ?, ?)");
            if (!$supplierStmt) {
                throw new Exception("Failed to prepare item-supplier insert statement");
            }
            
            $isFirst = true;
            foreach ($supplierIds as $supplierId) {
                if (!empty($supplierId)) {
                    $isPrimary = $isFirst;
                    $isFirst = false;
                    
                    $supplierStmt->bind_param('iii', $itemId, $supplierId, $isPrimary);
                    if (!$supplierStmt->execute()) {
                        throw new Exception("Failed to insert item-supplier relationship: " . $supplierStmt->error);
                    }
                }
            }
            
            $supplierStmt->close();
            $mysqli->commit();
            
            $message = "Item updated successfully!";
            $message_type = "success";
            
        } catch (Exception $e) {
            $mysqli->rollback();
            $message = "Error updating item: " . $e->getMessage();
            $message_type = "danger";
        }
    } else {
        $message = "Please fill all required fields and add at least one supplier!";
        $message_type = "warning";
    }
} elseif ($action === 'delete_item') {
    $itemId = $_POST['item_id'] ?? '';
    
    if (!empty($itemId)) {
        $stmt = $mysqli->prepare("DELETE FROM items_data WHERE item_id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $itemId);
            
            if ($stmt->execute()) {
                $message = "Item deleted successfully!";
                $message_type = "success";
            } else {
                $message = "Error deleting item: " . $stmt->error;
                $message_type = "danger";
            }
            $stmt->close();
        } else {
            $message = "Database error. Please try again.";
            $message_type = "danger";
        }
    }
}

// --------------------------- Fetch Data ---------------------------
$categories = fetchAll($mysqli, "SELECT * FROM categories WHERE status = 'Active' ORDER BY category_name");
$suppliers = fetchAll($mysqli, "SELECT * FROM suppliers WHERE status = 'Active' ORDER BY supplier_name");
$items = fetchAll($mysqli, "
    SELECT i.*, c.category_name 
    FROM items_data i 
    LEFT JOIN categories c ON i.category_id = c.category_id 
    ORDER BY i.created_at DESC
");

// Fetch item suppliers for forms
$itemSuppliers = [];
foreach ($items as $item) {
    $itemId = $item['item_id'];
    $itemSuppliers[$itemId] = fetchAll($mysqli, "
        SELECT isup.*, s.supplier_name, s.supplier_id, s.supplier_code
        FROM item_suppliers isup 
        LEFT JOIN suppliers s ON isup.supplier_id = s.supplier_id 
        WHERE isup.item_id = ? AND isup.status = 'Active'
        ORDER BY isup.is_primary DESC, s.supplier_name
    ", 'i', [$itemId]);
}

// Fetch stock inventory data for real-time updates
$stockInventory = fetchAll($mysqli, "
    SELECT si.*, i.quantity as item_quantity 
    FROM stock_inventory si 
    LEFT JOIN items_data i ON si.item_id = i.item_id 
    ORDER BY si.last_updated DESC
");

// --------------------------- Utilities ---------------------------
function esc($s) { 
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); 
}

function getStockLevelClass($quantity, $minStock = 10) {
    if ($quantity == 0) {
        return 'danger';
    } elseif ($quantity <= $minStock) {
        return 'warning';
    } else {
        return 'success';
    }
}

function formatCurrency($amount) {
    return '₹' . number_format($amount, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Items Management - Forest Trekking</title>
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
            background: linear-gradient(180deg, #1a1a1a 0%, #2d2d2d 100%);
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
        
        .item-code {
            font-family: 'Courier New', monospace;
            font-weight: bold;
            color: var(--primary);
            background: var(--primary-light);
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.85rem;
        }
        
        /* Stock Level Indicators */
        .stock-level {
            font-weight: bold;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .stock-level:hover {
            transform: scale(1.05);
        }
        
        .stock-low { 
            background: rgba(255, 193, 7, 0.2); 
            color: #856404; 
        }
        .stock-out { 
            background: rgba(220, 53, 69, 0.2); 
            color: #721c24; 
        }
        .stock-good { 
            background: rgba(25, 135, 84, 0.2); 
            color: #155724; 
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
        
        /* Badge Styles */
        .badge-active {
            background: var(--success);
        }
        
        .badge-inactive {
            background: var(--secondary);
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
        
        /* Supplier Entry Styles */
        .supplier-entry {
            background: var(--primary-light);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
            border-left: 3px solid var(--primary);
        }
        
        .supplier-entry:last-child {
            margin-bottom: 0;
        }
        
        .supplier-entry-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .remove-supplier {
            color: var(--danger);
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .remove-supplier:hover {
            transform: scale(1.2);
        }
        
        .add-supplier-btn {
            border: 2px dashed var(--primary);
            background: transparent;
            color: var(--primary);
            padding: 10px;
            border-radius: 8px;
            width: 100%;
            transition: all 0.2s ease;
        }
        
        .add-supplier-btn:hover {
            background: var(--primary-light);
            border-color: var(--primary-dark);
        }
        
        /* Real-time update indicators */
        .real-time-update {
            transition: all 0.3s ease;
        }
        
        .stock-updated {
            background-color: rgba(25, 135, 84, 0.1) !important;
            animation: pulse 1s;
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
            
            .item-code {
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
            
            .item-code {
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
                        <li><a class="dropdown-item active" href="items.php"><i class="bi bi-box me-2"></i>Items</a></li>
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
                        <li><a class="dropdown-item" href="order_status.php"><i class="bi bi-cart-check me-2"></i>Order Status</a></li>
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

        <!-- Main Content -->
        <div class="container-fluid py-3">
            <!-- Page Header -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="dashboard-card p-4 fade-in">
                        <div class="row align-items-center">
                            <div class="col-md-6 mb-3 mb-md-0">
                                <h2 class="mb-1 fw-bold text-dark">
                                    <i class="bi bi-box-seam me-2"></i>Items Management
                                </h2>
                                <p class="text-muted mb-0">Manage inventory items, stock levels, and suppliers with real-time updates</p>
                            </div>
                            <div class="col-md-6 text-md-end">
                                <button class="btn btn-primary pulse" data-bs-toggle="modal" data-bs-target="#addItemModal">
                                    <i class="bi bi-plus-circle me-2"></i>Add New Item
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="row g-3 mb-4">
                <div class="col-xl-3 col-lg-6">
                    <div class="dashboard-card stat-card p-3 slide-in" style="animation-delay: 0.1s">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="fw-bold text-primary mb-1"><?= count($items) ?></h4>
                                <p class="text-muted mb-0 small">Total Items</p>
                            </div>
                            <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                                <i class="bi bi-box"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-lg-6">
                    <div class="dashboard-card stat-card p-3 slide-in" style="animation-delay: 0.2s">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="fw-bold text-success mb-1"><?= count(array_filter($items, function($item) { return $item['quantity'] > 10; })) ?></h4>
                                <p class="text-muted mb-0 small">In Stock</p>
                            </div>
                            <div class="stat-icon bg-success bg-opacity-10 text-success">
                                <i class="bi bi-check-circle"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-lg-6">
                    <div class="dashboard-card stat-card p-3 slide-in" style="animation-delay: 0.3s">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="fw-bold text-warning mb-1"><?= count(array_filter($items, function($item) { return $item['quantity'] > 0 && $item['quantity'] <= 10; })) ?></h4>
                                <p class="text-muted mb-0 small">Low Stock</p>
                            </div>
                            <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                                <i class="bi bi-exclamation-triangle"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-lg-6">
                    <div class="dashboard-card stat-card p-3 slide-in" style="animation-delay: 0.4s">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="fw-bold text-danger mb-1"><?= count(array_filter($items, function($item) { return $item['quantity'] == 0; })) ?></h4>
                                <p class="text-muted mb-0 small">Out of Stock</p>
                            </div>
                            <div class="stat-icon bg-danger bg-opacity-10 text-danger">
                                <i class="bi bi-x-circle"></i>
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

            <!-- Items Table -->
            <div class="row">
                <div class="col-12">
                    <div class="dashboard-card p-4 fade-in">
                        <div class="table-header-container">
                            <h5 class="fw-bold mb-0">All Items</h5>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-primary"><?= count($items) ?> Items</span>
                                <button class="btn btn-outline-primary btn-sm" id="refreshItems">
                                    <i class="bi bi-arrow-clockwise"></i>
                                </button>
                            </div>
                        </div>

                        <?php if (empty($items)): ?>
                            <div class="empty-state">
                                <i class="bi bi-box"></i>
                                <h5>No Items Found</h5>
                                <p>Start by adding your first item to manage inventory.</p>
                                <button class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#addItemModal">
                                    <i class="bi bi-plus-circle me-2"></i>Add First Item
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="text-center">S.No</th>
                                            <th>Item Code</th>
                                            <th>Item Name</th>
                                            <th>Category</th>
                                            <th class="text-center">Quantity</th>
                                            <th class="text-center">Stock Level</th>
                                            <th class="text-center">Last Updated</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="itemsTableBody">
                                        <?php foreach ($items as $index => $item): ?>
                                            <?php
                                            $stockClass = getStockLevelClass($item['quantity']);
                                            $stockText = $item['quantity'] == 0 ? 'Out of Stock' : ($item['quantity'] <= 10 ? 'Low Stock' : 'In Stock');
                                            $lastUpdated = !empty($item['updated_at']) ? date('M j, Y H:i', strtotime($item['updated_at'])) : 'Never';
                                            ?>
                                            <tr class="fade-in real-time-update" id="item-<?= $item['item_id'] ?>" style="animation-delay: <?= $index * 0.05 ?>s">
                                                <td class="text-center fw-bold"><?= $index + 1 ?></td>
                                                <td>
                                                    <span class="item-code"><?= esc($item['item_code']) ?></span>
                                                </td>
                                                <td>
                                                    <strong><?= esc($item['item_name']) ?></strong>
                                                    <?php if (!empty($item['description'])): ?>
                                                        <br><small class="text-muted"><?= esc($item['description']) ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= esc($item['category_name'] ?? 'N/A') ?></td>
                                                <td class="text-center fw-bold"><?= esc($item['quantity']) ?></td>
                                                <td class="text-center">
                                                    <span class="stock-level stock-<?= $stockClass ?>" 
                                                          onclick="handleStockClick(<?= $item['item_id'] ?>, '<?= $stockClass ?>')"
                                                          title="<?= $stockClass === 'warning' ? 'Click to create purchase order for this item' : '' ?>">
                                                        <?= $stockText ?>
                                                    </span>
                                                </td>
                                                <td class="text-center small text-muted">
                                                    <?= $lastUpdated ?>
                                                </td>
                                                <td class="text-center">
                                                    <div class="btn-group" role="group">
                                                        <button class="btn btn-outline-primary btn-action edit-item" 
                                                                data-bs-toggle="modal" 
                                                                data-bs-target="#editItemModal"
                                                                data-id="<?= $item['item_id'] ?>"
                                                                data-name="<?= esc($item['item_name']) ?>"
                                                                data-category="<?= esc($item['category_id']) ?>"
                                                                data-description="<?= esc($item['description']) ?>"
                                                                data-suppliers="<?= esc(json_encode(array_column($itemSuppliers[$item['item_id']] ?? [], 'supplier_id'))) ?>">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <button class="btn btn-outline-danger btn-action delete-item"
                                                                data-bs-toggle="modal"
                                                                data-bs-target="#deleteItemModal"
                                                                data-id="<?= $item['item_id'] ?>"
                                                                data-name="<?= esc($item['item_name']) ?>">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </div>
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

    <!-- Add Item Modal -->
    <div class="modal fade" id="addItemModal" tabindex="-1" aria-labelledby="addItemModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="addItemModalLabel">
                        <i class="bi bi-plus-circle me-2"></i>Add New Item
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="addItemForm">
                    <input type="hidden" name="action" value="add_item">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label for="itemName" class="form-label">Item Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="itemName" name="item_name" 
                                       placeholder="Enter item name" required maxlength="255">
                            </div>
                            
                            <div class="col-12">
                                <label for="categoryId" class="form-label">Category <span class="text-danger">*</span></label>
                                <select class="form-select" id="categoryId" name="category_id" required>
                                    <option value="">Select Category</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?= $category['category_id'] ?>"><?= esc($category['category_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-12">
                                <label for="initialQuantity" class="form-label">Initial Quantity</label>
                                <input type="number" class="form-control" id="initialQuantity" name="initial_quantity" 
                                       value="0" min="0" placeholder="Enter initial stock quantity">
                                <div class="form-text">Set initial stock quantity for this item</div>
                            </div>
                            
                            <div class="col-12">
                                <label for="description" class="form-label">Description</label>
                                <textarea class="form-control" id="description" name="description" 
                                          rows="3" placeholder="Enter item description"></textarea>
                            </div>
                            
                            <!-- Supplier Section -->
                            <div class="col-12">
                                <label class="form-label">Suppliers <span class="text-danger">*</span></label>
                                <div id="supplierEntries">
                                    <!-- Supplier entries will be added here dynamically -->
                                </div>
                                <button type="button" class="btn add-supplier-btn" id="addSupplierBtn">
                                    <i class="bi bi-plus-circle me-2"></i>Add Another Supplier
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="addItemBtn">
                            <i class="bi bi-check-circle me-2"></i>Save Item
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Item Modal -->
    <div class="modal fade" id="editItemModal" tabindex="-1" aria-labelledby="editItemModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="editItemModalLabel">
                        <i class="bi bi-pencil me-2"></i>Edit Item
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editItemForm">
                    <input type="hidden" name="action" value="update_item">
                    <input type="hidden" name="item_id" id="editItemId">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label for="editItemName" class="form-label">Item Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="editItemName" name="item_name" 
                                       placeholder="Enter item name" required maxlength="255">
                            </div>
                            
                            <div class="col-12">
                                <label for="editCategoryId" class="form-label">Category <span class="text-danger">*</span></label>
                                <select class="form-select" id="editCategoryId" name="category_id" required>
                                    <option value="">Select Category</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?= $category['category_id'] ?>"><?= esc($category['category_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-12">
                                <label for="editDescription" class="form-label">Description</label>
                                <textarea class="form-control" id="editDescription" name="description" 
                                          rows="3" placeholder="Enter item description"></textarea>
                            </div>
                            
                            <!-- Supplier Section -->
                            <div class="col-12">
                                <label class="form-label">Suppliers <span class="text-danger">*</span></label>
                                <div id="editSupplierEntries">
                                    <!-- Supplier entries will be added here dynamically -->
                                </div>
                                <button type="button" class="btn add-supplier-btn" id="addEditSupplierBtn">
                                    <i class="bi bi-plus-circle me-2"></i>Add Another Supplier
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning text-white" id="editItemBtn">
                            <i class="bi bi-check-circle me-2"></i>Update Item
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Item Modal -->
    <div class="modal fade" id="deleteItemModal" tabindex="-1" aria-labelledby="deleteItemModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="deleteItemModalLabel">
                        <i class="bi bi-exclamation-triangle me-2"></i>Confirm Delete
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="deleteItemForm">
                    <input type="hidden" name="action" value="delete_item">
                    <input type="hidden" name="item_id" id="deleteItemId">
                    <div class="modal-body">
                        <p>Are you sure you want to delete the item <strong id="deleteItemName" class="text-danger"></strong>?</p>
                        <p class="text-muted small">This action cannot be undone. All item data will be permanently removed.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger" id="deleteItemBtn">
                            <i class="bi bi-trash me-2"></i>Delete Item
                        </button>
                    </div>
                </form>
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

        // Supplier management for Add Modal
        let supplierCount = 0;
        const supplierEntries = document.getElementById('supplierEntries');
        const addSupplierBtn = document.getElementById('addSupplierBtn');

        // Supplier management for Edit Modal
        let editSupplierCount = 0;
        const editSupplierEntries = document.getElementById('editSupplierEntries');
        const addEditSupplierBtn = document.getElementById('addEditSupplierBtn');

        function createSupplierEntry(container, countVar, isEdit = false) {
            countVar++;
            const entryId = `supplier_${isEdit ? 'edit_' : ''}${countVar}`;
            
            const supplierEntry = document.createElement('div');
            supplierEntry.className = 'supplier-entry';
            supplierEntry.id = entryId;
            
            supplierEntry.innerHTML = `
                <div class="supplier-entry-header">
                    <h6 class="mb-0">Supplier ${countVar}</h6>
                    ${countVar > 1 ? '<button type="button" class="remove-supplier" onclick="removeSupplierEntry(\'' + entryId + '\', ' + isEdit + ')">&times;</button>' : ''}
                </div>
                <div class="row g-2">
                    <div class="col-12">
                        <select class="form-select supplier-select" name="supplier_ids[]" required>
                            <option value="">Select Supplier</option>
                            <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?= $supplier['supplier_id'] ?>" data-code="<?= esc($supplier['supplier_code']) ?>">
                                    <?= esc($supplier['supplier_name']) ?> (Code: <?= esc($supplier['supplier_code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            `;
            
            container.appendChild(supplierEntry);
            return countVar;
        }

        function removeSupplierEntry(entryId, isEdit = false) {
            const entry = document.getElementById(entryId);
            if (entry) {
                entry.remove();
                // Renumber remaining suppliers
                const container = isEdit ? editSupplierEntries : supplierEntries;
                const entries = container.getElementsByClassName('supplier-entry');
                for (let i = 0; i < entries.length; i++) {
                    const header = entries[i].querySelector('h6');
                    header.textContent = `Supplier ${i + 1}`;
                }
                
                if (isEdit) {
                    editSupplierCount = entries.length;
                } else {
                    supplierCount = entries.length;
                }
            }
        }

        // Initialize with one supplier entry for add modal
        document.addEventListener('DOMContentLoaded', function() {
            supplierCount = createSupplierEntry(supplierEntries, supplierCount, false);
        });

        addSupplierBtn.addEventListener('click', function() {
            supplierCount = createSupplierEntry(supplierEntries, supplierCount, false);
        });

        addEditSupplierBtn.addEventListener('click', function() {
            editSupplierCount = createSupplierEntry(editSupplierEntries, editSupplierCount, true);
        });

        // Edit Item Modal Handler
        document.querySelectorAll('.edit-item').forEach(button => {
            button.addEventListener('click', function() {
                const itemId = this.getAttribute('data-id');
                const itemName = this.getAttribute('data-name');
                const categoryId = this.getAttribute('data-category');
                const description = this.getAttribute('data-description');
                const suppliers = JSON.parse(this.getAttribute('data-suppliers') || '[]');
                
                document.getElementById('editItemId').value = itemId;
                document.getElementById('editItemName').value = itemName;
                document.getElementById('editCategoryId').value = categoryId;
                document.getElementById('editDescription').value = description;
                
                // Clear existing supplier entries
                editSupplierEntries.innerHTML = '';
                editSupplierCount = 0;
                
                // Add supplier entries based on existing suppliers
                if (suppliers.length > 0) {
                    suppliers.forEach(supplierId => {
                        editSupplierCount = createSupplierEntry(editSupplierEntries, editSupplierCount, true);
                        const lastEntry = editSupplierEntries.lastElementChild;
                        const select = lastEntry.querySelector('.supplier-select');
                        if (select) {
                            select.value = supplierId;
                        }
                    });
                } else {
                    // Add at least one empty supplier entry
                    editSupplierCount = createSupplierEntry(editSupplierEntries, editSupplierCount, true);
                }
            });
        });

        // Delete Item Modal Handler
        document.querySelectorAll('.delete-item').forEach(button => {
            button.addEventListener('click', function() {
                const itemId = this.getAttribute('data-id');
                const itemName = this.getAttribute('data-name');
                
                document.getElementById('deleteItemId').value = itemId;
                document.getElementById('deleteItemName').textContent = itemName;
            });
        });

        // Handle stock level click for low stock items
        function handleStockClick(itemId, stockClass) {
            if (stockClass === 'warning') {
                // Redirect to purchase orders page with item ID as parameter
                window.location.href = `purchase_order.php?item_id=${itemId}`;
            }
        }

        // Auto-focus on item name input when modal opens
        const addItemModal = document.getElementById('addItemModal');
        addItemModal.addEventListener('shown.bs.modal', () => {
            document.getElementById('itemName').focus();
        });

        const editItemModal = document.getElementById('editItemModal');
        editItemModal.addEventListener('shown.bs.modal', () => {
            document.getElementById('editItemName').focus();
        });

        // Auto-dismiss alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);

        // Refresh items button
        document.getElementById('refreshItems')?.addEventListener('click', function() {
            this.classList.add('btn-loading');
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        });

        // Real-time form submission handling
        document.getElementById('addItemForm')?.addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="bi bi-arrow-repeat spinner-border spinner-border-sm me-2"></i>Saving...';
        });

        document.getElementById('editItemForm')?.addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="bi bi-arrow-repeat spinner-border spinner-border-sm me-2"></i>Updating...';
        });

        document.getElementById('deleteItemForm')?.addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="bi bi-arrow-repeat spinner-border spinner-border-sm me-2"></i>Deleting...';
        });

        // Add hover effects to table rows
        document.querySelectorAll('.table-hover tbody tr').forEach(row => {
            row.addEventListener('mouseenter', function() {
                this.style.transform = 'scale(1.01)';
                this.style.transition = 'all 0.2s ease';
            });
            
            row.addEventListener('mouseleave', function() {
                this.style.transform = 'scale(1)';
            });
        });

        // Add animation to stats cards on scroll
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.animationPlayState = 'running';
                }
            });
        }, observerOptions);

        document.querySelectorAll('.slide-in').forEach(el => {
            observer.observe(el);
        });

        // Reset supplier entries when add modal is closed
        addItemModal.addEventListener('hidden.bs.modal', function() {
            supplierEntries.innerHTML = '';
            supplierCount = 0;
            supplierCount = createSupplierEntry(supplierEntries, supplierCount, false);
        });

        // Reset edit supplier entries when edit modal is closed
        editItemModal.addEventListener('hidden.bs.modal', function() {
            editSupplierEntries.innerHTML = '';
            editSupplierCount = 0;
        });

        // Real-time stock update simulation (for demo purposes)
        // In a real application, this would be handled by WebSockets or AJAX polling
        function simulateRealTimeStockUpdate() {
            // This is a simulation - in real application, you would receive updates from server
            setInterval(() => {
                // Randomly select an item to update (for demo only)
                const items = document.querySelectorAll('#itemsTableBody tr');
                if (items.length > 0) {
                    const randomIndex = Math.floor(Math.random() * items.length);
                    const randomItem = items[randomIndex];
                    const currentQty = parseInt(randomItem.querySelector('td:nth-child(5)').textContent);
                    
                    // Simulate stock update (small random change)
                    const change = Math.random() > 0.7 ? 1 : 0; // 30% chance of update
                    if (change && currentQty < 100) {
                        const newQty = currentQty + 1;
                        randomItem.querySelector('td:nth-child(5)').textContent = newQty;
                        
                        // Update stock level indicator
                        const stockLevel = randomItem.querySelector('.stock-level');
                        const stockClass = newQty == 0 ? 'danger' : (newQty <= 10 ? 'warning' : 'success');
                        const stockText = newQty == 0 ? 'Out of Stock' : (newQty <= 10 ? 'Low Stock' : 'In Stock');
                        
                        stockLevel.className = `stock-level stock-${stockClass}`;
                        stockLevel.textContent = stockText;
                        
                        // Add visual feedback
                        randomItem.classList.add('stock-updated');
                        setTimeout(() => {
                            randomItem.classList.remove('stock-updated');
                        }, 2000);
                    }
                }
            }, 10000); // Check every 10 seconds
        }

        // Start real-time updates (for demo)
        // simulateRealTimeStockUpdate();

        // Function to check for stock updates from order status
        function checkForStockUpdates() {
            // This would typically make an AJAX call to check for updates
            // For now, we'll just refresh the page periodically
            setTimeout(() => {
                // In a real application, you would use AJAX to update specific items
                // For this demo, we'll just show a notification
                console.log('Checking for stock updates from order status...');
            }, 30000); // Check every 30 seconds
        }

        // Start checking for updates
        checkForStockUpdates();
    </script>
</body>
</html>