<?php
/**
 * Food Menu Management Page - Forest Trekking System
 * Complete with inventory integration and live updates
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

// Create food_menu_type table if not exists
$createFoodMenuTableQuery = "
CREATE TABLE IF NOT EXISTS food_menu_type (
    menu_id INT PRIMARY KEY AUTO_INCREMENT,
    menu_type VARCHAR(50) NOT NULL,
    item_code VARCHAR(20) NOT NULL,
    item_name VARCHAR(255) NOT NULL,
    quantity INT DEFAULT 1,
    expiry_date DATE NULL,
    status ENUM('Active', 'Inactive') DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";
$mysqli->query($createFoodMenuTableQuery);

// Create food_inventory table for stock tracking
$createFoodInventoryTableQuery = "
CREATE TABLE IF NOT EXISTS food_inventory_type (
    inventory_id INT PRIMARY KEY AUTO_INCREMENT,
    item_code VARCHAR(20) NOT NULL,
    item_name VARCHAR(255) NOT NULL,
    current_stock INT DEFAULT 0,
    min_stock_level INT DEFAULT 10,
    max_stock_level INT DEFAULT 100,
    expiry_date DATE NULL,
    last_restocked DATE NULL,
    status ENUM('In Stock', 'Low Stock', 'Out of Stock', 'Expired') DEFAULT 'In Stock',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";
$mysqli->query($createFoodInventoryTableQuery);

// --------------------------- Helper Functions ---------------------------
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

// Update inventory status based on current stock
function updateInventoryStatus($mysqli) {
    $today = date('Y-m-d');
    
    // Update status based on stock levels and expiry dates
    $updateQuery = "
        UPDATE food_inventory_type 
        SET status = CASE 
            WHEN current_stock = 0 THEN 'Out of Stock'
            WHEN expiry_date < ? THEN 'Expired'
            WHEN current_stock <= min_stock_level THEN 'Low Stock'
            ELSE 'In Stock'
        END,
        updated_at = CURRENT_TIMESTAMP
    ";
    
    $stmt = $mysqli->prepare($updateQuery);
    if ($stmt) {
        $stmt->bind_param('s', $today);
        $stmt->execute();
        $stmt->close();
    }
}

// --------------------------- Handle AJAX Actions ---------------------------
if (isset($_POST['ajax_action'])) {
    $ajaxAction = $_POST['ajax_action'];
    $response = ['success' => false, 'message' => ''];
    
    if ($ajaxAction === 'save_menu') {
        $menuType = $_POST['menu_type'] ?? '';
        $menuItemsJson = $_POST['menu_items'] ?? '[]';
        $menuItems = json_decode($menuItemsJson, true);
        
        if (!empty($menuType)) {
            $mysqli->begin_transaction();
            
            try {
                // Delete existing items for this menu type
                $deleteStmt = $mysqli->prepare("DELETE FROM food_menu_type WHERE menu_type = ?");
                if ($deleteStmt) {
                    $deleteStmt->bind_param('s', $menuType);
                    $deleteStmt->execute();
                    $deleteStmt->close();
                }
                
                $successCount = 0;
                
                // Only insert if there are menu items
                if (!empty($menuItems) && is_array($menuItems)) {
                    // Insert new menu items
                    $insertStmt = $mysqli->prepare("INSERT INTO food_menu_type (menu_type, item_code, item_name, quantity, expiry_date) VALUES (?, ?, ?, ?, ?)");
                    
                    if ($insertStmt) {
                        foreach ($menuItems as $item) {
                            if (!empty($item['item_code']) && !empty($item['item_name'])) {
                                $itemCode = $item['item_code'];
                                $itemName = $item['item_name'];
                                $quantity = !empty($item['quantity']) ? intval($item['quantity']) : 1;
                                $expiryDate = !empty($item['expiry_date']) ? $item['expiry_date'] : null;
                                
                                $insertStmt->bind_param('sssis', $menuType, $itemCode, $itemName, $quantity, $expiryDate);
                                if ($insertStmt->execute()) {
                                    $successCount++;
                                    updateInventoryItem($mysqli, $itemCode, $itemName, $quantity, $expiryDate);
                                }
                            }
                        }
                        $insertStmt->close();
                    }
                }
                
                $mysqli->commit();
                
                if ($successCount > 0) {
                    $response['success'] = true;
                    $response['message'] = "Menu saved successfully! {$successCount} items added to {$menuType}";
                } else {
                    $response['success'] = true;
                    $response['message'] = "Menu cleared successfully! No items in {$menuType}";
                }
                
            } catch (Exception $e) {
                $mysqli->rollback();
                $response['message'] = "Error saving menu: " . $e->getMessage();
            }
        } else {
            $response['message'] = "Please select a menu type!";
        }
        
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
        
    } elseif ($ajaxAction === 'update_menu_item') {
        $menuId = $_POST['menu_id'] ?? '';
        $quantity = $_POST['quantity'] ?? 1;
        $expiryDate = $_POST['expiry_date'] ?? null;
        
        if (!empty($menuId)) {
            $item = fetchOne($mysqli, "SELECT item_code, item_name FROM food_menu_type WHERE menu_id = ?", 'i', [$menuId]);
            
            if ($item) {
                $stmt = $mysqli->prepare("UPDATE food_menu_type SET quantity = ?, expiry_date = ?, updated_at = CURRENT_TIMESTAMP WHERE menu_id = ?");
                if ($stmt) {
                    $stmt->bind_param('isi', $quantity, $expiryDate, $menuId);
                    
                    if ($stmt->execute()) {
                        updateInventoryItem($mysqli, $item['item_code'], $item['item_name'], $quantity, $expiryDate);
                        $response['success'] = true;
                        $response['message'] = "Menu item updated successfully!";
                    } else {
                        $response['message'] = "Error updating menu item: " . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $response['message'] = "Database error. Please try again.";
                }
            } else {
                $response['message'] = "Menu item not found!";
            }
        } else {
            $response['message'] = "Invalid menu item ID!";
        }
        
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
        
    } elseif ($ajaxAction === 'delete_menu_item') {
        $menuId = $_POST['menu_id'] ?? '';
        
        if (!empty($menuId)) {
            $item = fetchOne($mysqli, "SELECT item_code, item_name FROM food_menu_type WHERE menu_id = ?", 'i', [$menuId]);
            
            if ($item) {
                $stmt = $mysqli->prepare("DELETE FROM food_menu_type WHERE menu_id = ?");
                if ($stmt) {
                    $stmt->bind_param('i', $menuId);
                    
                    if ($stmt->execute()) {
                        // Delete the item from inventory table when removed from menu
                        $deleteInventoryStmt = $mysqli->prepare("DELETE FROM food_inventory_type WHERE item_code = ?");
                        if ($deleteInventoryStmt) {
                            $deleteInventoryStmt->bind_param('s', $item['item_code']);
                            $deleteInventoryStmt->execute();
                            $deleteInventoryStmt->close();
                        }
                        $response['success'] = true;
                        $response['message'] = "Menu item deleted successfully!";
                    } else {
                        $response['message'] = "Error deleting menu item: " . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $response['message'] = "Database error. Please try again.";
                }
            } else {
                $response['message'] = "Menu item not found!";
            }
        } else {
            $response['message'] = "Invalid menu item ID!";
        }
        
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
}

// --------------------------- Handle Regular Form Actions ---------------------------
$action = $_POST['action'] ?? '';
$message = '';
$message_type = '';

// Initialize session for form resubmission prevention
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Generate unique form token to prevent resubmission
if (!isset($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}

$form_token = $_SESSION['form_token'];

// Handle regular form actions (for backward compatibility)
if ($action && isset($_POST['form_token']) && $_POST['form_token'] === $form_token) {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
    
    if ($action === 'save_menu') {
        $menuType = $_POST['menu_type'] ?? '';
        $menuItems = $_POST['menu_items'] ?? [];
        
        if (!empty($menuType)) {
            $mysqli->begin_transaction();
            
            try {
                // Delete existing items for this menu type
                $deleteStmt = $mysqli->prepare("DELETE FROM food_menu_type WHERE menu_type = ?");
                if ($deleteStmt) {
                    $deleteStmt->bind_param('s', $menuType);
                    $deleteStmt->execute();
                    $deleteStmt->close();
                }
                
                $successCount = 0;
                
                if (!empty($menuItems) && is_array($menuItems)) {
                    $insertStmt = $mysqli->prepare("INSERT INTO food_menu_type (menu_type, item_code, item_name, quantity, expiry_date) VALUES (?, ?, ?, ?, ?)");
                    
                    if ($insertStmt) {
                        foreach ($menuItems as $item) {
                            if (!empty($item['item_code']) && !empty($item['item_name'])) {
                                $itemCode = $item['item_code'];
                                $itemName = $item['item_name'];
                                $quantity = !empty($item['quantity']) ? intval($item['quantity']) : 1;
                                $expiryDate = !empty($item['expiry_date']) ? $item['expiry_date'] : null;
                                
                                $insertStmt->bind_param('sssis', $menuType, $itemCode, $itemName, $quantity, $expiryDate);
                                if ($insertStmt->execute()) {
                                    $successCount++;
                                    updateInventoryItem($mysqli, $itemCode, $itemName, $quantity, $expiryDate);
                                }
                            }
                        }
                        $insertStmt->close();
                    }
                }
                
                $mysqli->commit();
                
                if ($successCount > 0) {
                    $message = "Menu saved successfully! {$successCount} items added to {$menuType}";
                    $message_type = "success";
                } else {
                    $message = "Menu cleared successfully! No items in {$menuType}";
                    $message_type = "info";
                }
                
            } catch (Exception $e) {
                $mysqli->rollback();
                $message = "Error saving menu: " . $e->getMessage();
                $message_type = "danger";
            }
        } else {
            $message = "Please select a menu type!";
            $message_type = "warning";
        }
    }
}

// Optimized helper function to update inventory items
function updateInventoryItem($mysqli, $itemCode, $itemName = '', $quantity = 0, $expiryDate = null) {
    // Get item name if not provided
    if (empty($itemName)) {
        $item = fetchOne($mysqli, "SELECT item_name FROM items_data WHERE item_code = ?", 's', [$itemCode]);
        if ($item) {
            $itemName = $item['item_name'];
        }
    }
    
    // Check if item exists in inventory
    $existingItem = fetchOne($mysqli, "SELECT inventory_id FROM food_inventory_type WHERE item_code = ?", 's', [$itemCode]);
    
    if ($existingItem) {
        // Update existing item
        $stmt = $mysqli->prepare("UPDATE food_inventory_type SET current_stock = ?, expiry_date = ?, updated_at = CURRENT_TIMESTAMP WHERE item_code = ?");
        if ($stmt) {
            $stmt->bind_param('iss', $quantity, $expiryDate, $itemCode);
            $stmt->execute();
            $stmt->close();
        }
    } else {
        // Insert new item only if quantity is greater than 0
        if ($quantity > 0) {
            $stmt = $mysqli->prepare("INSERT INTO food_inventory_type (item_code, item_name, current_stock, expiry_date) VALUES (?, ?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param('ssis', $itemCode, $itemName, $quantity, $expiryDate);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
    
    // Update inventory status
    updateInventoryStatus($mysqli);
}

// --------------------------- Fetch Data ---------------------------
// Define all menu types - this ensures they always show in dropdown
$menuTypes = ['Menu Type1', 'Menu Type2', 'Menu Type3', 'Menu Type4', 'Menu Type5'];
$selectedMenuType = $_POST['menu_type'] ?? 'Menu Type1';

// Fetch all food-related items from items_data table
$foodItems = fetchAll($mysqli, "
    SELECT i.*, c.category_name 
    FROM items_data i 
    LEFT JOIN categories c ON i.category_id = c.category_id 
    WHERE c.category_name LIKE '%food%' OR c.category_name LIKE '%Food%' OR 
          c.category_name LIKE '%refreshment%' OR c.category_name LIKE '%Refreshment%' OR
          i.item_name LIKE '%food%' OR i.item_name LIKE '%Food%' OR
          i.item_name LIKE '%refreshment%' OR i.item_name LIKE '%Refreshment%'
    ORDER BY i.item_code
");

// If no specific food items found, get all items
if (empty($foodItems)) {
    $foodItems = fetchAll($mysqli, "
        SELECT i.*, c.category_name 
        FROM items_data i 
        LEFT JOIN categories c ON i.category_id = c.category_id 
        ORDER BY i.item_code
    ");
}

// Load menu items for the selected menu type
$menuItems = fetchAll($mysqli, "SELECT * FROM food_menu_type WHERE menu_type = ? ORDER BY created_at", 's', [$selectedMenuType]);

// Get all menu items for display
$allMenuItems = fetchAll($mysqli, "SELECT * FROM food_menu_type ORDER BY menu_type, created_at");

// Group menu items by menu type for display
$menuItemsByType = [];
foreach ($allMenuItems as $item) {
    $menuItemsByType[$item['menu_type']][] = $item;
}

// Ensure all menu types exist in the grouped array, even if empty
foreach ($menuTypes as $type) {
    if (!isset($menuItemsByType[$type])) {
        $menuItemsByType[$type] = [];
    }
}

// Fetch inventory data for statistics
$inventoryStats = fetchOne($mysqli, "
    SELECT 
        COUNT(*) as total_items,
        SUM(CASE WHEN status = 'Low Stock' THEN 1 ELSE 0 END) as low_stock_items,
        SUM(CASE WHEN status = 'Out of Stock' THEN 1 ELSE 0 END) as out_of_stock_items,
        SUM(CASE WHEN status = 'Expired' THEN 1 ELSE 0 END) as expired_items,
        SUM(CASE WHEN status = 'In Stock' THEN 1 ELSE 0 END) as in_stock_items
    FROM food_inventory_type
");

// Fetch low stock items with menu type information
$lowStockItems = fetchAll($mysqli, "
    SELECT fi.*, GROUP_CONCAT(DISTINCT fm.menu_type) as menu_types
    FROM food_inventory_type fi
    LEFT JOIN food_menu_type fm ON fi.item_code = fm.item_code
    WHERE fi.status IN ('Low Stock', 'Out of Stock') AND fi.current_stock > 0
    GROUP BY fi.inventory_id
    ORDER BY 
        CASE fi.status 
            WHEN 'Out of Stock' THEN 1
            WHEN 'Low Stock' THEN 2
            ELSE 3
        END,
        fi.current_stock ASC
    LIMIT 10
");

// Fetch expired items
$expiredItems = fetchAll($mysqli, "
    SELECT fi.*, GROUP_CONCAT(DISTINCT fm.menu_type) as menu_types
    FROM food_inventory_type fi
    LEFT JOIN food_menu_type fm ON fi.item_code = fm.item_code
    WHERE fi.status = 'Expired'
    GROUP BY fi.inventory_id
    ORDER BY fi.expiry_date ASC
    LIMIT 10
");

// Fetch items expiring soon (within 7 days)
$today = date('Y-m-d');
$nextWeek = date('Y-m-d', strtotime('+7 days'));
$expiringSoon = fetchAll($mysqli, "
    SELECT fi.*, GROUP_CONCAT(DISTINCT fm.menu_type) as menu_types
    FROM food_inventory_type fi
    LEFT JOIN food_menu_type fm ON fi.item_code = fm.item_code
    WHERE fi.expiry_date BETWEEN ? AND ? 
    AND fi.status != 'Expired'
    GROUP BY fi.inventory_id
    ORDER BY fi.expiry_date ASC
    LIMIT 10
", 'ss', [$today, $nextWeek]);

// --------------------------- Utilities ---------------------------
function esc($s) { 
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); 
}

function getStockLevelClass($status) {
    switch ($status) {
        case 'Low Stock': return 'warning';
        case 'Out of Stock': return 'danger';
        case 'Expired': return 'dark';
        case 'In Stock': return 'success';
        default: return 'secondary';
    }
}

function formatDate($date) {
    if (empty($date) || $date == '0000-00-00') return 'N/A';
    return date('M j, Y', strtotime($date));
}

function isExpired($expiryDate) {
    if (empty($expiryDate) || $expiryDate == '0000-00-00') return false;
    return strtotime($expiryDate) < strtotime(date('Y-m-d'));
}

function getDaysUntilExpiry($expiryDate) {
    if (empty($expiryDate) || $expiryDate == '0000-00-00') return null;
    $today = new DateTime();
    $expiry = new DateTime($expiryDate);
    $interval = $today->diff($expiry);
    return $interval->days * ($interval->invert ? -1 : 1);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Food Menu Management - Forest Trekking</title>
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
        
        /* Menu Item Row */
        .menu-item-row {
            transition: all var(--animation-timing);
            border-left: 3px solid transparent;
        }
        
        .menu-item-row:hover {
            border-left-color: var(--primary);
            background-color: var(--primary-light);
        }
        
        .menu-item-row.new-row {
            animation: slideIn 0.3s ease-in-out;
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
        
        /* Food Categories in Dropdown */
        .food-category {
            font-weight: bold;
            background-color: var(--primary-light);
            color: var(--primary-dark);
        }
        
        .food-item {
            padding-left: 20px;
        }
        
        /* Menu Type Badge */
        .menu-type-badge {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: bold;
        }
        
        /* Menu Type Section */
        .menu-type-section {
            border-left: 4px solid var(--primary);
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            margin-bottom: 2rem;
        }
        
        .menu-type-header {
            background: var(--primary-light);
            padding: 1rem 1.5rem;
            border-bottom: 1px solid rgba(46, 139, 87, 0.2);
        }
        
        .item-badge {
            font-weight: bold;
            color: var(--primary);
            background: var(--primary-light);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.9rem;
        }
        
        /* Item Code Styles */
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
        }
        
        .stock-low { background: rgba(255, 193, 7, 0.2); color: #856404; }
        .stock-out { background: rgba(220, 53, 69, 0.2); color: #721c24; }
        .stock-expired { background: rgba(108, 117, 125, 0.2); color: #495057; }
        .stock-good { background: rgba(25, 135, 84, 0.2); color: #155724; }
        
        /* Expiry Warning */
        .expiry-warning {
            background: linear-gradient(135deg, #ff6b6b, #ee5a24);
            color: white;
            padding: 8px 12px;
            border-radius: 6px;
            margin-bottom: 1rem;
        }
        
        .expiry-soon {
            background: linear-gradient(135deg, #ffeaa7, #fdcb6e);
            color: #856404;
            padding: 8px 12px;
            border-radius: 6px;
            margin-bottom: 1rem;
        }
        
        /* Quantity Input */
        .quantity-input {
            max-width: 100px;
        }
        
        /* Inventory Stats */
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
        
        /* Live Update Styles */
        .live-update {
            transition: all 0.3s ease;
        }
        
        .live-update.updated {
            background-color: rgba(25, 135, 84, 0.1);
        }
        
        /* Refresh Button */
        .refresh-btn {
            transition: all 0.3s ease;
        }
        
        /* Toast Container */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
        }
        
        /* Form Validation Styles */
        .is-invalid {
            border-color: #dc3545 !important;
            box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25) !important;
        }
        
        .invalid-feedback {
            display: none;
            width: 100%;
            margin-top: 0.25rem;
            font-size: 0.875em;
            color: #dc3545;
        }
        
        .was-validated .form-control:invalid ~ .invalid-feedback,
        .form-control.is-invalid ~ .invalid-feedback {
            display: block;
        }

        /* Menu Type Tags */
        .menu-type-tag {
            background: var(--primary-light);
            color: var(--primary-dark);
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            margin: 1px;
            display: inline-block;
        }
    </style>
</head>
<body>
    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>

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
                                    <i class="bi bi-egg-fried me-2"></i>Food Menu Management
                                </h2>
                                <p class="text-muted mb-0">Manage food menus with live inventory tracking and expiry management</p>
                            </div>
                            <div class="col-md-6 text-md-end">
                                <button class="btn btn-primary refresh-btn" id="refreshData">
                                    <i class="bi bi-arrow-clockwise me-2"></i>Refresh Data
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Inventory Overview & Statistics -->
            <div class="row g-3 mb-4">
                <div class="col-xl-3 col-lg-4 col-md-6">
                    <div class="dashboard-card stat-card p-3 slide-in live-update" id="total-items-card" style="animation-delay: 0.1s">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="fw-bold text-primary mb-1" id="total-items"><?= $inventoryStats['total_items'] ?? 0 ?></h4>
                                <p class="text-muted mb-0 small">Total Items</p>
                            </div>
                            <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                                <i class="bi bi-egg-fried"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-lg-4 col-md-6">
                    <div class="dashboard-card stat-card p-3 slide-in live-update" id="in-stock-card" style="animation-delay: 0.2s">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="fw-bold text-success mb-1" id="in-stock-items"><?= $inventoryStats['in_stock_items'] ?? 0 ?></h4>
                                <p class="text-muted mb-0 small">In Stock</p>
                            </div>
                            <div class="stat-icon bg-success bg-opacity-10 text-success">
                                <i class="bi bi-check-circle"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-lg-4 col-md-6">
                    <div class="dashboard-card stat-card p-3 slide-in live-update" id="low-stock-card" style="animation-delay: 0.3s">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="fw-bold text-warning mb-1" id="low-stock-items"><?= $inventoryStats['low_stock_items'] ?? 0 ?></h4>
                                <p class="text-muted mb-0 small">Low Stock</p>
                            </div>
                            <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                                <i class="bi bi-exclamation-triangle"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-lg-4 col-md-6">
                    <div class="dashboard-card stat-card p-3 slide-in live-update" id="expired-card" style="animation-delay: 0.4s">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="fw-bold text-dark mb-1" id="expired-items"><?= $inventoryStats['expired_items'] ?? 0 ?></h4>
                                <p class="text-muted mb-0 small">Expired</p>
                            </div>
                            <div class="stat-icon bg-dark bg-opacity-10 text-dark">
                                <i class="bi bi-clock-history"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Low Stock & Expiry Alerts -->
            <?php if (!empty($lowStockItems) || !empty($expiredItems) || !empty($expiringSoon)): ?>
            <div class="row mb-4">
                <?php if (!empty($lowStockItems)): ?>
                <div class="col-lg-6">
                    <div class="dashboard-card p-3 fade-in">
                        <h6 class="fw-bold text-warning mb-3">
                            <i class="bi bi-exclamation-triangle me-2"></i>Low Stock Alert
                        </h6>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Item</th>
                                        <th>Code</th>
                                        <th class="text-center">Stock</th>
                                        <th>Menu Type</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody id="low-stock-items-body">
                                    <?php foreach ($lowStockItems as $item): ?>
                                    <tr class="live-update">
                                        <td><?= esc($item['item_name']) ?></td>
                                        <td><span class="item-code"><?= esc($item['item_code']) ?></span></td>
                                        <td class="text-center fw-bold"><?= $item['current_stock'] ?></td>
                                        <td>
                                            <?php if (!empty($item['menu_types'])): 
                                                $menuTypes = explode(',', $item['menu_types']);
                                                foreach ($menuTypes as $menuType): ?>
                                                    <span class="menu-type-tag"><?= esc($menuType) ?></span>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <span class="text-muted">Not in menu</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="stock-level stock-<?= getStockLevelClass($item['status']) ?>">
                                                <?= $item['status'] ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($expiredItems)): ?>
                <div class="col-lg-6">
                    <div class="dashboard-card p-3 fade-in">
                        <h6 class="fw-bold text-danger mb-3">
                            <i class="bi bi-exclamation-circle me-2"></i>Expired Items
                        </h6>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Item</th>
                                        <th>Code</th>
                                        <th>Expiry Date</th>
                                        <th>Menu Type</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody id="expired-items-body">
                                    <?php foreach ($expiredItems as $item): ?>
                                    <tr class="live-update">
                                        <td><?= esc($item['item_name']) ?></td>
                                        <td><span class="item-code"><?= esc($item['item_code']) ?></span></td>
                                        <td><?= formatDate($item['expiry_date']) ?></td>
                                        <td>
                                            <?php if (!empty($item['menu_types'])): 
                                                $menuTypes = explode(',', $item['menu_types']);
                                                foreach ($menuTypes as $menuType): ?>
                                                    <span class="menu-type-tag"><?= esc($menuType) ?></span>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <span class="text-muted">Not in menu</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="stock-level stock-expired">
                                                Expired
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($expiringSoon)): ?>
            <div class="row mb-4">
                <div class="col-12">
                    <div class="dashboard-card p-3 fade-in">
                        <h6 class="fw-bold text-warning mb-3">
                            <i class="bi bi-clock me-2"></i>Expiring Soon (Within 7 Days)
                        </h6>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Item</th>
                                        <th>Code</th>
                                        <th>Expiry Date</th>
                                        <th>Days Left</th>
                                        <th>Menu Type</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody id="expiring-soon-body">
                                    <?php foreach ($expiringSoon as $item): 
                                        $daysLeft = getDaysUntilExpiry($item['expiry_date']);
                                    ?>
                                    <tr class="live-update">
                                        <td><?= esc($item['item_name']) ?></td>
                                        <td><span class="item-code"><?= esc($item['item_code']) ?></span></td>
                                        <td><?= formatDate($item['expiry_date']) ?></td>
                                        <td class="fw-bold <?= $daysLeft <= 3 ? 'text-danger' : 'text-warning' ?>">
                                            <?= $daysLeft ?> days
                                        </td>
                                        <td>
                                            <?php if (!empty($item['menu_types'])): 
                                                $menuTypes = explode(',', $item['menu_types']);
                                                foreach ($menuTypes as $menuType): ?>
                                                    <span class="menu-type-tag"><?= esc($menuType) ?></span>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <span class="text-muted">Not in menu</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="stock-level stock-<?= getStockLevelClass($item['status']) ?>">
                                                <?= $item['status'] ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>

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

            <!-- Add Menu Items Section -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="dashboard-card p-4 fade-in">
                        <div class="table-header-container">
                            <h5 class="fw-bold mb-0">Add Items to Menu Type</h5>
                            <div>
                                <button type="button" class="btn btn-primary pulse" id="addMenuItem">
                                    <i class="bi bi-plus-circle me-2"></i>Add Item
                                </button>
                            </div>
                        </div>

                        <form method="POST" id="menuForm" class="needs-validation" novalidate>
                            <input type="hidden" name="action" value="save_menu">
                            <input type="hidden" name="form_token" value="<?= $form_token ?>">
                            
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label for="menuType" class="form-label fw-bold">Select Menu Type</label>
                                    <select class="form-select" id="menuType" name="menu_type" required>
                                        <option value="">Select Menu Type</option>
                                        <?php foreach ($menuTypes as $type): ?>
                                            <option value="<?= esc($type) ?>" <?= $selectedMenuType === $type ? 'selected' : '' ?>>
                                                <?= esc($type) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="invalid-feedback">
                                        Please select a menu type.
                                    </div>
                                </div>
                                <div class="col-md-6 d-flex align-items-end">
                                    <button type="button" class="btn btn-success" id="saveMenuBtn">
                                        <i class="bi bi-check-circle me-2"></i>Save Menu
                                    </button>
                                </div>
                            </div>
                            
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="text-center">S.No</th>
                                            <th>Item Code</th>
                                            <th>Item Name</th>
                                            <th class="text-center">Quantity</th>
                                            <th>Expiry Date</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="menuTableBody">
                                        <?php if (empty($menuItems)): ?>
                                            <tr id="empty-row">
                                                <td colspan="6" class="text-center">
                                                    <div class="empty-state py-4">
                                                        <i class="bi bi-egg-fried"></i>
                                                        <h5>No Menu Items Added</h5>
                                                        <p>Click "Add Item" to start building your menu</p>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($menuItems as $index => $item): ?>
                                                <tr class="menu-item-row fade-in live-update" data-row-id="<?= $item['menu_id'] ?>" style="animation-delay: <?= $index * 0.05 ?>s">
                                                    <td class="text-center fw-bold"><?= $index + 1 ?></td>
                                                    <td>
                                                        <span class="item-code"><?= esc($item['item_code']) ?></span>
                                                        <input type="hidden" name="menu_items[<?= $index ?>][item_code]" value="<?= esc($item['item_code']) ?>">
                                                    </td>
                                                    <td>
                                                        <?= esc($item['item_name']) ?>
                                                        <input type="hidden" name="menu_items[<?= $index ?>][item_name]" value="<?= esc($item['item_name']) ?>">
                                                    </td>
                                                    <td class="text-center">
                                                        <input type="number" class="form-control form-control-sm quantity-input" 
                                                               name="menu_items[<?= $index ?>][quantity]" 
                                                               value="<?= $item['quantity'] ?>" min="1" max="1000" required>
                                                    </td>
                                                    <td>
                                                        <input type="date" class="form-control form-control-sm" 
                                                               name="menu_items[<?= $index ?>][expiry_date]" 
                                                               value="<?= $item['expiry_date'] ?>">
                                                    </td>
                                                    <td class="text-center">
                                                        <div class="btn-group" role="group">
                                                            <button type="button" class="btn btn-outline-primary btn-action edit-menu-item" 
                                                                    data-menu-id="<?= $item['menu_id'] ?>"
                                                                    data-quantity="<?= $item['quantity'] ?>"
                                                                    data-expiry-date="<?= $item['expiry_date'] ?>">
                                                                <i class="bi bi-pencil"></i>
                                                            </button>
                                                            <button type="button" class="btn btn-outline-danger btn-action remove-item" 
                                                                    data-menu-id="<?= $item['menu_id'] ?>">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Display All Menu Types Section -->
            <div class="row">
                <div class="col-12">
                    <div class="dashboard-card p-0 fade-in">
                        <div class="table-header-container p-4">
                            <h5 class="fw-bold mb-0">All Menu Types Overview</h5>
                            <span class="badge bg-primary"><?= count($menuTypes) ?> Menu Types</span>
                        </div>

                        <?php foreach ($menuTypes as $menuType): ?>
                            <div class="menu-type-section">
                                <div class="menu-type-header">
                                    <h6 class="fw-bold mb-0 text-dark">
                                        <i class="bi bi-list-check me-2"></i><?= esc($menuType) ?>
                                        <span class="badge bg-primary ms-2">
                                            <?= count($menuItemsByType[$menuType] ?? []) ?> items
                                        </span>
                                    </h6>
                                </div>
                                
                                <div class="p-3">
                                    <?php if (!empty($menuItemsByType[$menuType])): ?>
                                        <div class="table-responsive">
                                            <table class="table table-hover mb-0">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th class="text-center">S.No</th>
                                                        <th>Item Code</th>
                                                        <th>Item Name</th>
                                                        <th class="text-center">Quantity</th>
                                                        <th>Expiry Date</th>
                                                        <th class="text-center">Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="menu-type-<?= preg_replace('/\s+/', '-', strtolower($menuType)) ?>">
                                                    <?php foreach ($menuItemsByType[$menuType] as $index => $item): 
                                                        $isExpired = isExpired($item['expiry_date']);
                                                        $daysLeft = getDaysUntilExpiry($item['expiry_date']);
                                                    ?>
                                                        <tr class="menu-item-row live-update <?= $isExpired ? 'table-danger' : '' ?>">
                                                            <td class="text-center fw-bold"><?= $index + 1 ?></td>
                                                            <td>
                                                                <span class="item-code"><?= esc($item['item_code']) ?></span>
                                                            </td>
                                                            <td>
                                                                <span class="item-badge">
                                                                    <i class="bi bi-egg-fried me-2"></i>
                                                                    <?= esc($item['item_name']) ?>
                                                                </span>
                                                                <?php if ($isExpired): ?>
                                                                    <span class="badge bg-danger ms-2">Expired</span>
                                                                <?php elseif ($daysLeft !== null && $daysLeft <= 7): ?>
                                                                    <span class="badge bg-warning ms-2">Expiring in <?= $daysLeft ?> days</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td class="text-center fw-bold"><?= $item['quantity'] ?></td>
                                                            <td>
                                                                <?= formatDate($item['expiry_date']) ?>
                                                                <?php if ($daysLeft !== null && $daysLeft <= 7 && !$isExpired): ?>
                                                                    <br><small class="text-warning">(<?= $daysLeft ?> days left)</small>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td class="text-center">
                                                                <div class="btn-group" role="group">
                                                                    <button type="button" class="btn btn-outline-primary btn-action edit-saved-item" 
                                                                            data-menu-id="<?= $item['menu_id'] ?>"
                                                                            data-quantity="<?= $item['quantity'] ?>"
                                                                            data-expiry-date="<?= $item['expiry_date'] ?>">
                                                                        <i class="bi bi-pencil"></i>
                                                                    </button>
                                                                    <button type="button" class="btn btn-outline-danger btn-action remove-saved-item" 
                                                                            data-menu-id="<?= $item['menu_id'] ?>">
                                                                        <i class="bi bi-trash"></i>
                                                                    </button>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-center py-4">
                                            <i class="bi bi-egg-fried text-muted" style="font-size: 2rem;"></i>
                                            <p class="text-muted mt-2 mb-0">No items added to <?= esc($menuType) ?> yet</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Menu Item Modal -->
    <div class="modal fade" id="editMenuItemModal" tabindex="-1" aria-labelledby="editMenuItemModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="editMenuItemModalLabel">
                        <i class="bi bi-pencil me-2"></i>Edit Menu Item
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="editMenuItemForm">
                    <input type="hidden" name="ajax_action" value="update_menu_item">
                    <input type="hidden" name="menu_id" id="editMenuId">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="editQuantity" class="form-label">Quantity</label>
                            <input type="number" class="form-control" id="editQuantity" name="quantity" 
                                   min="1" max="1000" required>
                        </div>
                        <div class="mb-3">
                            <label for="editExpiryDate" class="form-label">Expiry Date</label>
                            <input type="date" class="form-control" id="editExpiryDate" name="expiry_date">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning text-white" id="updateMenuItemBtn">
                            <i class="bi bi-check-circle me-2"></i>Update Item
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteMenuItemModal" tabindex="-1" aria-labelledby="deleteMenuItemModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="deleteMenuItemModalLabel">
                        <i class="bi bi-exclamation-triangle me-2"></i>Confirm Delete
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="deleteMenuItemForm">
                    <input type="hidden" name="ajax_action" value="delete_menu_item">
                    <input type="hidden" name="menu_id" id="deleteMenuItemId">
                    <div class="modal-body">
                        <p>Are you sure you want to delete this menu item?</p>
                        <p class="text-muted small">This action cannot be undone.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger" id="deleteMenuItemBtn">
                            <i class="bi bi-trash me-2"></i>Delete Item
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Item Modal -->
    <div class="modal fade" id="addItemModal" tabindex="-1" aria-labelledby="addItemModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="addItemModalLabel">
                        <i class="bi bi-plus-circle me-2"></i>Add Menu Item
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="itemSelect" class="form-label">Select Food Item</label>
                        <select class="form-select" id="itemSelect">
                            <option value="">Select Food Item</option>
                            <?php foreach ($foodItems as $item): ?>
                                <option value="<?= esc($item['item_code']) ?>" data-name="<?= esc($item['item_name']) ?>">
                                    <?= esc($item['item_code']) ?> - <?= esc($item['item_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($foodItems)): ?>
                            <div class="alert alert-warning mt-2">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                No food items found. Please add food items to the items page first.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="confirmAddItem">Add Item</button>
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

        // Add new menu item row
        let rowCounter = <?= count($menuItems) ?>;
        const addItemModal = new bootstrap.Modal(document.getElementById('addItemModal'));
        
        document.getElementById('addMenuItem')?.addEventListener('click', function() {
            addItemModal.show();
        });

        // Confirm adding item from modal
        document.getElementById('confirmAddItem')?.addEventListener('click', function() {
            const itemSelect = document.getElementById('itemSelect');
            const selectedOption = itemSelect.options[itemSelect.selectedIndex];
            
            if (selectedOption.value) {
                const tableBody = document.getElementById('menuTableBody');
                const emptyRow = document.getElementById('empty-row');
                
                if (emptyRow) {
                    emptyRow.remove();
                }
                
                const newRow = document.createElement('tr');
                newRow.className = 'menu-item-row new-row live-update';
                
                // Set default expiry date to 30 days from now
                const tomorrow = new Date();
                tomorrow.setDate(tomorrow.getDate() + 30);
                const defaultExpiry = tomorrow.toISOString().split('T')[0];
                
                newRow.innerHTML = `
                    <td class="text-center fw-bold">${rowCounter + 1}</td>
                    <td>
                        <span class="item-code">${selectedOption.value}</span>
                        <input type="hidden" name="menu_items[${rowCounter}][item_code]" value="${selectedOption.value}">
                    </td>
                    <td>
                        ${selectedOption.getAttribute('data-name')}
                        <input type="hidden" name="menu_items[${rowCounter}][item_name]" value="${selectedOption.getAttribute('data-name')}">
                    </td>
                    <td class="text-center">
                        <input type="number" class="form-control form-control-sm quantity-input" 
                               name="menu_items[${rowCounter}][quantity]" value="1" min="1" max="1000" required>
                    </td>
                    <td>
                        <input type="date" class="form-control form-control-sm" 
                               name="menu_items[${rowCounter}][expiry_date]" value="${defaultExpiry}">
                    </td>
                    <td class="text-center">
                        <div class="btn-group" role="group">
                            <button type="button" class="btn btn-outline-primary btn-action edit-new-row">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button type="button" class="btn btn-outline-danger btn-action remove-new-row">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </td>
                `;
                
                tableBody.appendChild(newRow);
                rowCounter++;
                addItemModal.hide();
                itemSelect.value = '';
                
                // Add event listeners for the new row buttons
                addRowEventListeners(newRow);
                
                // Update row numbers
                updateRowNumbers();
            } else {
                showToast('Please select a food item!', 'warning');
            }
        });

        // Function to add event listeners to row buttons
        function addRowEventListeners(row) {
            // Edit new row (not yet saved)
            const editBtn = row.querySelector('.edit-new-row');
            editBtn?.addEventListener('click', function() {
                const quantityInput = row.querySelector('input[name*="quantity"]');
                quantityInput.focus();
            });

            // Remove new row (not yet saved)
            const removeBtn = row.querySelector('.remove-new-row');
            removeBtn?.addEventListener('click', function() {
                row.remove();
                updateRowNumbers();
                
                // Show empty state if no rows left
                const tableBody = document.getElementById('menuTableBody');
                if (tableBody.children.length === 0) {
                    tableBody.innerHTML = `
                        <tr id="empty-row">
                            <td colspan="6" class="text-center">
                                <div class="empty-state py-4">
                                    <i class="bi bi-egg-fried"></i>
                                    <h5>No Menu Items Added</h5>
                                    <p>Click "Add Item" to start building your menu</p>
                                </div>
                            </td>
                        </tr>
                    `;
                    rowCounter = 0;
                }
            });
        }

        // Edit menu item functionality
        const editMenuItemModal = new bootstrap.Modal(document.getElementById('editMenuItemModal'));
        
        // Edit menu item from add section
        document.addEventListener('click', function(e) {
            if (e.target.closest('.edit-menu-item')) {
                const button = e.target.closest('.edit-menu-item');
                const menuId = button.getAttribute('data-menu-id');
                const quantity = button.getAttribute('data-quantity');
                const expiryDate = button.getAttribute('data-expiry-date');
                
                document.getElementById('editMenuId').value = menuId;
                document.getElementById('editQuantity').value = quantity;
                document.getElementById('editExpiryDate').value = expiryDate;
                
                editMenuItemModal.show();
            }
        });

        // Edit menu item from display section
        document.addEventListener('click', function(e) {
            if (e.target.closest('.edit-saved-item')) {
                const button = e.target.closest('.edit-saved-item');
                const menuId = button.getAttribute('data-menu-id');
                const quantity = button.getAttribute('data-quantity');
                const expiryDate = button.getAttribute('data-expiry-date');
                
                document.getElementById('editMenuId').value = menuId;
                document.getElementById('editQuantity').value = quantity;
                document.getElementById('editExpiryDate').value = expiryDate;
                
                editMenuItemModal.show();
            }
        });

        // Remove saved menu item from add section
        document.addEventListener('click', function(e) {
            if (e.target.closest('.remove-item')) {
                const button = e.target.closest('.remove-item');
                const menuId = button.getAttribute('data-menu-id');
                
                document.getElementById('deleteMenuItemId').value = menuId;
                const deleteModal = new bootstrap.Modal(document.getElementById('deleteMenuItemModal'));
                deleteModal.show();
            }
        });

        // Remove saved menu item from display section
        document.addEventListener('click', function(e) {
            if (e.target.closest('.remove-saved-item')) {
                const button = e.target.closest('.remove-saved-item');
                const menuId = button.getAttribute('data-menu-id');
                
                document.getElementById('deleteMenuItemId').value = menuId;
                const deleteModal = new bootstrap.Modal(document.getElementById('deleteMenuItemModal'));
                deleteModal.show();
            }
        });

        // Update row numbers
        function updateRowNumbers() {
            const rows = document.querySelectorAll('#menuTableBody tr.menu-item-row');
            rows.forEach((row, index) => {
                const serialNumber = row.querySelector('td:first-child');
                if (serialNumber) {
                    serialNumber.textContent = index + 1;
                }
            });
        }

        // Save menu functionality using AJAX
        document.getElementById('saveMenuBtn')?.addEventListener('click', function() {
            const menuType = document.getElementById('menuType');
            const tableBody = document.getElementById('menuTableBody');
            const emptyRow = document.getElementById('empty-row');
            const submitBtn = this;
            
            // Check if menu type is selected
            if (!menuType.value) {
                menuType.classList.add('is-invalid');
                showToast('Please select a menu type!', 'warning');
                return false;
            } else {
                menuType.classList.remove('is-invalid');
            }
            
            // Validate all quantity inputs
            const quantityInputs = document.querySelectorAll('.quantity-input');
            let hasInvalidQuantity = false;
            
            quantityInputs.forEach(input => {
                if (!input.value || input.value < 1 || input.value > 1000) {
                    input.classList.add('is-invalid');
                    hasInvalidQuantity = true;
                } else {
                    input.classList.remove('is-invalid');
                    }
                });
            
            if (hasInvalidQuantity) {
                showToast('Please check quantity values. They must be between 1 and 1000.', 'warning');
                return false;
            }
            
            // Collect menu items data
            const menuItems = [];
            const rows = tableBody.querySelectorAll('tr.menu-item-row');
            
            if (rows.length === 0) {
                // No items to save - this is valid (clearing the menu)
            } else {
                rows.forEach((row, index) => {
                    const itemCodeInput = row.querySelector('input[name*="item_code"]');
                    const itemNameInput = row.querySelector('input[name*="item_name"]');
                    const quantityInput = row.querySelector('input[name*="quantity"]');
                    const expiryDateInput = row.querySelector('input[name*="expiry_date"]');
                    
                    if (itemCodeInput && itemNameInput && quantityInput) {
                        menuItems.push({
                            item_code: itemCodeInput.value,
                            item_name: itemNameInput.value,
                            quantity: quantityInput.value,
                            expiry_date: expiryDateInput ? expiryDateInput.value : null
                        });
                    }
                });
            }
            
            // Show loading state
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="bi bi-check-circle me-2"></i>Saving...';
            
            // Send AJAX request
            const formData = new FormData();
            formData.append('ajax_action', 'save_menu');
            formData.append('menu_type', menuType.value);
            formData.append('menu_items', JSON.stringify(menuItems));
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    // Refresh the page to show updated data in the display section
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    showToast(data.message, 'danger');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="bi bi-check-circle me-2"></i>Save Menu';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Error saving menu. Please try again.', 'danger');
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="bi bi-check-circle me-2"></i>Save Menu';
            });
        });

        // Update menu item form submission using AJAX
        document.getElementById('editMenuItemForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const submitBtn = document.getElementById('updateMenuItemBtn');
            const formData = new FormData(this);
            
            // Validate quantity
            const quantity = document.getElementById('editQuantity').value;
            if (!quantity || quantity < 1 || quantity > 1000) {
                showToast('Please enter a valid quantity between 1 and 1000.', 'warning');
                return;
            }
            
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="bi bi-check-circle me-2"></i>Updating...';
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    editMenuItemModal.hide();
                    // Refresh the page after 1 second to show updated data
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    showToast(data.message, 'danger');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="bi bi-check-circle me-2"></i>Update Item';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Error updating item. Please try again.', 'danger');
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="bi bi-check-circle me-2"></i>Update Item';
            });
        });

        // Delete form submission using AJAX
        document.getElementById('deleteMenuItemForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const submitBtn = document.getElementById('deleteMenuItemBtn');
            const formData = new FormData(this);
            const deleteModal = bootstrap.Modal.getInstance(document.getElementById('deleteMenuItemModal'));
            
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="bi bi-trash me-2"></i>Deleting...';
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    deleteModal.hide();
                    // Refresh the page after 1 second to show updated data
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    showToast(data.message, 'danger');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="bi bi-trash me-2"></i>Delete Item';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Error deleting item. Please try again.', 'danger');
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="bi bi-trash me-2"></i>Delete Item';
            });
        });

        // Refresh data functionality
        document.getElementById('refreshData')?.addEventListener('click', function() {
            window.location.reload();
        });

        // Show toast notification
        function showToast(message, type = 'info') {
            // Create toast element
            const toast = document.createElement('div');
            toast.className = `toast align-items-center text-bg-${type} border-0`;
            toast.setAttribute('role', 'alert');
            toast.setAttribute('aria-live', 'assertive');
            toast.setAttribute('aria-atomic', 'true');
            
            toast.innerHTML = `
                <div class="d-flex">
                    <div class="toast-body">
                        <i class="bi bi-${type === 'success' ? 'check-circle' : type === 'warning' ? 'exclamation-triangle' : 'info-circle'} me-2"></i>
                        ${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            `;
            
            // Add to page
            const toastContainer = document.getElementById('toastContainer');
            toastContainer.appendChild(toast);
            
            // Initialize and show toast
            const bsToast = new bootstrap.Toast(toast);
            bsToast.show();
            
            // Remove from DOM after hide
            toast.addEventListener('hidden.bs.toast', () => {
                toastContainer.removeChild(toast);
            });
        }

        // Initialize event listeners for existing rows
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('#menuTableBody tr.menu-item-row').forEach(row => {
                addRowEventListeners(row);
            });
            
            // Add validation to quantity inputs
            document.querySelectorAll('.quantity-input').forEach(input => {
                input.addEventListener('input', function() {
                    if (this.value < 1 || this.value > 1000) {
                        this.classList.add('is-invalid');
                    } else {
                        this.classList.remove('is-invalid');
                    }
                });
            });
        });
    </script>
</body>
</html>