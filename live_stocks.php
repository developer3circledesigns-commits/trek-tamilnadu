<?php
/**
 * Live Stocks Management Page - Forest Trekking System
 * Complete with real-time stock tracking, trail-wise inventory, and comprehensive statistics
 * Uses existing database tables without creating new ones
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
function fetchAll($mysqli, $sql, $types = null, $params = []) {
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        error_log("Failed to prepare query: " . $mysqli->error);
        return [];
    }
    
    if ($types && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    if (!$stmt->execute()) {
        error_log("Failed to execute query: " . $stmt->error);
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

// --------------------------- Fetch Data ---------------------------

// Fetch all trails
$trails = fetchAll($mysqli, "
    SELECT * FROM forest_trails 
    WHERE status = 'Active' 
    ORDER BY trail_location
");

// Fetch all items
$items = fetchAll($mysqli, "
    SELECT i.*, c.category_name, c.category_code 
    FROM items_data i 
    LEFT JOIN categories c ON i.category_id = c.category_id 
    WHERE i.status = 'Active' 
    ORDER BY c.category_name, i.item_name
");

// Fetch trail inventory data
$trailInventory = fetchAll($mysqli, "
    SELECT ti.*, t.trail_code, t.trail_location, i.item_name, i.item_code, c.category_name
    FROM trail_inventory ti
    LEFT JOIN forest_trails t ON ti.trail_id = t.id
    LEFT JOIN items_data i ON ti.item_id = i.item_id
    LEFT JOIN categories c ON i.category_id = c.category_id
    WHERE t.status = 'Active' AND i.status = 'Active'
    ORDER BY t.trail_location, c.category_name, i.item_name
");

// Fetch stock inventory data
$stockInventory = fetchAll($mysqli, "
    SELECT * FROM stock_inventory 
    WHERE is_active = 1
    ORDER BY category_name, item_name
");

// Fetch in-transit transfers
$inTransitTransfers = fetchAll($mysqli, "
    SELECT st.*, 
           ft.trail_location as from_location, ft.trail_code as from_code,
           tt.trail_location as to_location, tt.trail_code as to_code,
           (SELECT COUNT(*) FROM transfer_items WHERE transfer_id = st.transfer_id) as item_count,
           (SELECT SUM(requested_quantity) FROM transfer_items WHERE transfer_id = st.transfer_id) as total_quantity
    FROM stock_transfers st
    LEFT JOIN forest_trails ft ON st.from_trail_id = ft.id
    LEFT JOIN forest_trails tt ON st.to_trail_id = tt.id
    WHERE st.status = 'In Progress'
    ORDER BY st.created_at DESC
");

// Calculate statistics
$stats = [];

// Low stock items (less than or equal to 10)
$stats['low_stock'] = fetchOne($mysqli, "
    SELECT COUNT(*) as count, SUM(current_stock) as total 
    FROM stock_inventory 
    WHERE current_stock <= 10 AND current_stock > 0
") ?? ['count' => 0, 'total' => 0];

// Out of stock items
$stats['out_of_stock'] = fetchOne($mysqli, "
    SELECT COUNT(*) as count 
    FROM stock_inventory 
    WHERE current_stock = 0
") ?? ['count' => 0];

// In-transit items
$stats['in_transit'] = fetchOne($mysqli, "
    SELECT COUNT(*) as transfer_count, SUM(total_quantity) as total_quantity
    FROM stock_transfers 
    WHERE status = 'In Progress'
") ?? ['transfer_count' => 0, 'total_quantity' => 0];

// Total stock value and items
$stats['total'] = fetchOne($mysqli, "
    SELECT COUNT(*) as item_count, SUM(current_stock) as total_stock, SUM(total_value) as total_value
    FROM stock_inventory 
    WHERE is_active = 1
") ?? ['item_count' => 0, 'total_stock' => 0, 'total_value' => 0];

// Damaged items (from order_status table)
$stats['damaged'] = fetchOne($mysqli, "
    SELECT SUM(damaged_quantity) as total_damaged
    FROM order_status 
    WHERE damaged_quantity > 0
") ?? ['total_damaged' => 0];

// Create trail-wise stock matrix
$trailStockMatrix = [];
$itemColumns = [];

// Initialize matrix with all trails and items
foreach ($trails as $trail) {
    $trailStockMatrix[$trail['id']] = [
        'trail_info' => $trail,
        'items' => []
    ];
    
    // Initialize all items with 0 quantity for this trail
    foreach ($items as $item) {
        $trailStockMatrix[$trail['id']]['items'][$item['item_id']] = 0;
        $itemColumns[$item['item_id']] = $item;
    }
}

// Populate matrix with actual inventory data
foreach ($trailInventory as $inventory) {
    if (isset($trailStockMatrix[$inventory['trail_id']]) && 
        isset($trailStockMatrix[$inventory['trail_id']]['items'][$inventory['item_id']])) {
        $trailStockMatrix[$inventory['trail_id']]['items'][$inventory['item_id']] = $inventory['current_stock'];
    }
}

// --------------------------- Utilities ---------------------------
function esc($s) { 
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); 
}

function formatNumber($num) {
    return number_format($num ?? 0);
}

function formatCurrency($amount) {
    return '₹' . number_format($amount ?? 0, 2);
}

function getStockLevelClass($quantity) {
    if ($quantity == 0) {
        return 'danger';
    } elseif ($quantity <= 10) {
        return 'warning';
    } else {
        return 'success';
    }
}

function getStockLevelText($quantity) {
    if ($quantity == 0) {
        return 'Out of Stock';
    } elseif ($quantity <= 10) {
        return 'Low Stock';
    } else {
        return 'In Stock';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Live Stocks - Forest Trekking</title>
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
        
        /* Stats Cards */
        .stat-card {
            border-left: 4px solid var(--primary);
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            height: 100%;
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
        
        /* Stock Level Indicators */
        .stock-level {
            font-weight: bold;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.85rem;
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
        
        .fade-in {
            animation: fadeIn 0.5s ease-in-out;
        }
        
        .slide-in {
            animation: slideIn 0.4s ease-in-out;
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
        
        /* Stock Quantity Cells */
        .stock-quantity {
            text-align: center;
            font-weight: bold;
            padding: 8px 4px;
            border-radius: 4px;
            min-width: 60px;
        }
        
        .quantity-0 {
            background-color: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }
        
        .quantity-low {
            background-color: rgba(255, 193, 7, 0.1);
            color: #856404;
        }
        
        .quantity-good {
            background-color: rgba(25, 135, 84, 0.1);
            color: #155724;
        }
        
        /* Trail Header */
        .trail-header {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            padding: 10px 15px;
            border-radius: 8px;
            margin-bottom: 15px;
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
        }
        
        /* Loading Animation */
        .loading-spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Filter Section */
        .filter-section {
            background: white;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        /* In-transit items styling */
        .in-transit-item {
            border-left: 3px solid var(--info);
            background-color: rgba(13, 202, 240, 0.05);
            margin-bottom: 8px;
            padding: 8px 12px;
            border-radius: 4px;
        }
        
        /* Stock summary cards */
        .summary-card {
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
        }
        
        .summary-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .summary-low-stock {
            border-left: 4px solid var(--warning);
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
        }
        
        .summary-in-transit {
            border-left: 4px solid var(--info);
            background: linear-gradient(135deg, #d1ecf1 0%, #bee5eb 100%);
        }
        
        .summary-damaged {
            border-left: 4px solid var(--danger);
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
        }
        
        .summary-expired {
            border-left: 4px solid var(--secondary);
            background: linear-gradient(135deg, #e2e3e5 0%, #d6d8db 100%);
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
                
                <!-- Purchase Order Dropdown -->
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
                    <a class="nav-link active" href="live_stocks.php">
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
                                    <i class="bi bi-graph-up-arrow me-2"></i>Live Stocks Dashboard
                                </h2>
                                <p class="text-muted mb-0">Real-time stock tracking across all trail locations</p>
                            </div>
                            <div class="col-md-6 text-md-end">
                                <button class="btn btn-primary" id="refreshStocks">
                                    <i class="bi bi-arrow-clockwise me-2"></i>Refresh Data
                                </button>
                                <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#exportModal">
                                    <i class="bi bi-download me-2"></i>Export
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
                                <h4 class="fw-bold text-primary mb-1"><?= formatNumber($stats['total']['item_count'] ?? 0) ?></h4>
                                <p class="text-muted mb-0 small">Total Items</p>
                            </div>
                            <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                                <i class="bi bi-box-seam"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-lg-6">
                    <div class="dashboard-card stat-card p-3 slide-in" style="animation-delay: 0.2s">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="fw-bold text-success mb-1"><?= formatNumber($stats['total']['total_stock'] ?? 0) ?></h4>
                                <p class="text-muted mb-0 small">Total Stock</p>
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
                                <h4 class="fw-bold text-warning mb-1"><?= formatNumber($stats['low_stock']['count'] ?? 0) ?></h4>
                                <p class="text-muted mb-0 small">Low Stock Items</p>
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
                                <h4 class="fw-bold text-danger mb-1"><?= formatNumber($stats['out_of_stock']['count'] ?? 0) ?></h4>
                                <p class="text-muted mb-0 small">Out of Stock</p>
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
                <div class="col-md-3">
                    <div class="summary-card summary-low-stock">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="fw-bold text-warning mb-1"><?= formatNumber($stats['low_stock']['count'] ?? 0) ?></h5>
                                <p class="mb-0 small">Low Stock Items</p>
                                <small class="text-muted"><?= formatNumber($stats['low_stock']['total'] ?? 0) ?> units total</small>
                            </div>
                            <div class="stat-icon bg-warning bg-opacity-20 text-warning">
                                <i class="bi bi-exclamation-triangle"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="summary-card summary-in-transit">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="fw-bold text-info mb-1"><?= formatNumber($stats['in_transit']['transfer_count'] ?? 0) ?></h5>
                                <p class="mb-0 small">In Transit</p>
                                <small class="text-muted"><?= formatNumber($stats['in_transit']['total_quantity'] ?? 0) ?> units moving</small>
                            </div>
                            <div class="stat-icon bg-info bg-opacity-20 text-info">
                                <i class="bi bi-truck"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="summary-card summary-damaged">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="fw-bold text-danger mb-1"><?= formatNumber($stats['damaged']['total_damaged'] ?? 0) ?></h5>
                                <p class="mb-0 small">Damaged Items</p>
                                <small class="text-muted">Requires attention</small>
                            </div>
                            <div class="stat-icon bg-danger bg-opacity-20 text-danger">
                                <i class="bi bi-exclamation-octagon"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="summary-card summary-expired">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="fw-bold text-secondary mb-1">0</h5>
                                <p class="mb-0 small">Expired Items</p>
                                <small class="text-muted">No expired items</small>
                            </div>
                            <div class="stat-icon bg-secondary bg-opacity-20 text-secondary">
                                <i class="bi bi-calendar-x"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- In-Transit Transfers Section -->
            <?php if (!empty($inTransitTransfers)): ?>
            <div class="row mb-4">
                <div class="col-12">
                    <div class="dashboard-card p-4 fade-in">
                        <div class="table-header-container">
                            <h5 class="fw-bold mb-0">
                                <i class="bi bi-truck me-2"></i>In-Transit Stock Transfers
                            </h5>
                            <span class="badge bg-info"><?= count($inTransitTransfers) ?> Active Transfers</span>
                        </div>
                        
                        <div class="row g-3">
                            <?php foreach ($inTransitTransfers as $transfer): ?>
                            <div class="col-md-6 col-lg-4">
                                <div class="in-transit-item">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h6 class="mb-1"><?= esc($transfer['transfer_code']) ?></h6>
                                            <p class="mb-1 small">
                                                <strong>From:</strong> <?= esc($transfer['from_location']) ?><br>
                                                <strong>To:</strong> <?= esc($transfer['to_location']) ?>
                                            </p>
                                            <p class="mb-0 small text-muted">
                                                <?= $transfer['item_count'] ?> items, <?= $transfer['total_quantity'] ?> units
                                            </p>
                                        </div>
                                        <span class="badge bg-info">In Transit</span>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Filter Section -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="filter-section">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label small fw-bold">Trail Location</label>
                                <select class="form-select" id="trailFilter">
                                    <option value="all">All Trails</option>
                                    <?php foreach ($trails as $trail): ?>
                                        <option value="<?= $trail['id'] ?>"><?= esc($trail['trail_location']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-bold">Category</label>
                                <select class="form-select" id="categoryFilter">
                                    <option value="all">All Categories</option>
                                    <?php 
                                    $categories = [];
                                    foreach ($items as $item) {
                                        if (!empty($item['category_name']) && !in_array($item['category_name'], $categories)) {
                                            $categories[] = $item['category_name'];
                                        }
                                    }
                                    sort($categories);
                                    foreach ($categories as $category): ?>
                                        <option value="<?= esc($category) ?>"><?= esc($category) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-bold">Stock Level</label>
                                <select class="form-select" id="stockLevelFilter">
                                    <option value="all">All Levels</option>
                                    <option value="out">Out of Stock</option>
                                    <option value="low">Low Stock</option>
                                    <option value="good">In Stock</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-bold">Search Item</label>
                                <input type="text" class="form-control" id="itemSearch" placeholder="Search items...">
                            </div>
                            <div class="col-12 text-end">
                                <button class="btn btn-primary btn-sm" id="applyStockFilters">
                                    <i class="bi bi-funnel me-1"></i> Apply Filters
                                </button>
                                <button class="btn btn-outline-secondary btn-sm" id="resetStockFilters">
                                    <i class="bi bi-arrow-clockwise me-1"></i> Reset
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Live Stocks Table -->
            <div class="row">
                <div class="col-12">
                    <div class="dashboard-card p-4 fade-in">
                        <div class="table-header-container">
                            <h5 class="fw-bold mb-0">Live Stock Levels - All Trail Locations</h5>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-primary" id="stockCount"><?= count($trails) ?> Trails, <?= count($items) ?> Items</span>
                                <button class="btn btn-outline-primary btn-sm" id="toggleStockView">
                                    <i class="bi bi-arrows-expand me-1"></i> Expand View
                                </button>
                            </div>
                        </div>

                        <?php if (empty($trails) || empty($items)): ?>
                            <div class="empty-state">
                                <i class="bi bi-inboxes"></i>
                                <h5>No Stock Data Available</h5>
                                <p>Set up trails and items to start tracking stock levels.</p>
                                <div class="mt-3">
                                    <a href="trails.php" class="btn btn-primary me-2">
                                        <i class="bi bi-signpost me-2"></i>Manage Trails
                                    </a>
                                    <a href="items.php" class="btn btn-primary">
                                        <i class="bi bi-box me-2"></i>Manage Items
                                    </a>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive" style="max-height: 600px; overflow: auto;">
                                <table class="table table-bordered table-hover" id="liveStocksTable">
                                    <thead class="table-light sticky-top">
                                        <tr>
                                            <th class="text-center">S.No</th>
                                            <th>Trail Code</th>
                                            <th>Trail Name</th>
                                            <?php foreach ($items as $item): ?>
                                                <th class="text-center" title="<?= esc($item['item_name']) ?> (<?= esc($item['category_name']) ?>)">
                                                    <?= esc($item['item_code']) ?>
                                                    <br>
                                                    <small class="text-muted"><?= esc($item['category_code'] ?? 'GEN') ?></small>
                                                </th>
                                            <?php endforeach; ?>
                                            <th class="text-center">Total Stock</th>
                                            <th class="text-center">Stock Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($trailStockMatrix as $index => $trailData): 
                                            $trail = $trailData['trail_info'];
                                            $trailItems = $trailData['items'];
                                            $trailTotalStock = array_sum($trailItems);
                                            $trailStockLevel = getStockLevelClass($trailTotalStock);
                                            $trailStockText = getStockLevelText($trailTotalStock);
                                        ?>
                                            <tr class="trail-row" 
                                                data-trail-id="<?= $trail['id'] ?>" 
                                                data-trail-location="<?= esc($trail['trail_location']) ?>"
                                                data-total-stock="<?= $trailTotalStock ?>">
                                                <td class="text-center fw-bold"><?= $index + 1 ?></td>
                                                <td>
                                                    <span class="badge bg-dark"><?= esc($trail['trail_code']) ?></span>
                                                </td>
                                                <td>
                                                    <strong><?= esc($trail['trail_location']) ?></strong>
                                                </td>
                                                <?php foreach ($items as $item): 
                                                    $quantity = $trailItems[$item['item_id']] ?? 0;
                                                    $quantityClass = $quantity == 0 ? 'quantity-0' : ($quantity <= 10 ? 'quantity-low' : 'quantity-good');
                                                ?>
                                                    <td class="stock-quantity <?= $quantityClass ?>" 
                                                        data-item-id="<?= $item['item_id'] ?>"
                                                        data-item-name="<?= esc($item['item_name']) ?>"
                                                        title="<?= esc($item['item_name']) ?>: <?= $quantity ?> available">
                                                        <?= $quantity ?>
                                                    </td>
                                                <?php endforeach; ?>
                                                <td class="text-center fw-bold">
                                                    <?= $trailTotalStock ?>
                                                </td>
                                                <td class="text-center">
                                                    <span class="stock-level stock-<?= $trailStockLevel ?>">
                                                        <?= $trailStockText ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <!-- Footer with column totals -->
                                    <tfoot class="table-light">
                                        <tr>
                                            <th colspan="3" class="text-end">Total Stock:</th>
                                            <?php 
                                            $columnTotals = [];
                                            foreach ($items as $item) {
                                                $columnTotals[$item['item_id']] = 0;
                                            }
                                            
                                            foreach ($trailStockMatrix as $trailData) {
                                                foreach ($trailData['items'] as $itemId => $quantity) {
                                                    if (isset($columnTotals[$itemId])) {
                                                        $columnTotals[$itemId] += $quantity;
                                                    }
                                                }
                                            }
                                            
                                            $grandTotal = 0;
                                            foreach ($items as $item): 
                                                $total = $columnTotals[$item['item_id']] ?? 0;
                                                $grandTotal += $total;
                                                $totalClass = $total == 0 ? 'quantity-0' : ($total <= 10 ? 'quantity-low' : 'quantity-good');
                                            ?>
                                                <th class="text-center fw-bold <?= $totalClass ?>">
                                                    <?= $total ?>
                                                </th>
                                            <?php endforeach; ?>
                                            <th class="text-center fw-bold text-primary">
                                                <?= $grandTotal ?>
                                            </th>
                                            <th class="text-center">
                                                <span class="badge bg-primary">System Total</span>
                                            </th>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>

                            <!-- Stock Legend -->
                            <div class="row mt-3">
                                <div class="col-12">
                                    <div class="d-flex justify-content-center gap-3">
                                        <div class="d-flex align-items-center">
                                            <div class="stock-quantity quantity-good me-2" style="width: 20px; height: 20px;"></div>
                                            <small class="text-muted">Good Stock (>10)</small>
                                        </div>
                                        <div class="d-flex align-items-center">
                                            <div class="stock-quantity quantity-low me-2" style="width: 20px; height: 20px;"></div>
                                            <small class="text-muted">Low Stock (1-10)</small>
                                        </div>
                                        <div class="d-flex align-items-center">
                                            <div class="stock-quantity quantity-0 me-2" style="width: 20px; height: 20px;"></div>
                                            <small class="text-muted">Out of Stock (0)</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Low Stock Alerts Section -->
            <div class="row mt-4">
                <div class="col-12">
                    <div class="dashboard-card p-4 fade-in">
                        <div class="table-header-container">
                            <h5 class="fw-bold mb-0 text-warning">
                                <i class="bi bi-exclamation-triangle me-2"></i>Low Stock Alerts
                            </h5>
                            <span class="badge bg-warning"><?= $stats['low_stock']['count'] + $stats['out_of_stock']['count'] ?> Items Need Attention</span>
                        </div>

                        <?php 
                        $lowStockAlerts = [];
                        $outOfStockAlerts = [];
                        
                        foreach ($trailStockMatrix as $trailData) {
                            $trail = $trailData['trail_info'];
                            foreach ($trailData['items'] as $itemId => $quantity) {
                                if ($quantity == 0) {
                                    $outOfStockAlerts[] = [
                                        'trail' => $trail,
                                        'item' => $itemColumns[$itemId] ?? null,
                                        'quantity' => $quantity
                                    ];
                                } elseif ($quantity <= 10) {
                                    $lowStockAlerts[] = [
                                        'trail' => $trail,
                                        'item' => $itemColumns[$itemId] ?? null,
                                        'quantity' => $quantity
                                    ];
                                }
                            }
                        }
                        ?>
                        
                        <div class="row g-3">
                            <!-- Out of Stock Alerts -->
                            <?php if (!empty($outOfStockAlerts)): ?>
                            <div class="col-md-6">
                                <div class="alert alert-danger">
                                    <h6 class="alert-heading">
                                        <i class="bi bi-x-circle me-2"></i>Out of Stock
                                    </h6>
                                    <div class="mt-2" style="max-height: 200px; overflow-y: auto;">
                                        <?php foreach (array_slice($outOfStockAlerts, 0, 10) as $alert): 
                                            if (!$alert['item']) continue;
                                        ?>
                                            <div class="d-flex justify-content-between align-items-center mb-2 p-2 border-bottom">
                                                <div>
                                                    <strong><?= esc($alert['item']['item_name']) ?></strong>
                                                    <br>
                                                    <small class="text-muted">
                                                        <?= esc($alert['trail']['trail_location']) ?> (<?= esc($alert['trail']['trail_code']) ?>)
                                                    </small>
                                                </div>
                                                <span class="badge bg-danger">0</span>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php if (count($outOfStockAlerts) > 10): ?>
                                            <div class="text-center mt-2">
                                                <small class="text-muted">+ <?= count($outOfStockAlerts) - 10 ?> more items</small>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Low Stock Alerts -->
                            <?php if (!empty($lowStockAlerts)): ?>
                            <div class="col-md-6">
                                <div class="alert alert-warning">
                                    <h6 class="alert-heading">
                                        <i class="bi bi-exclamation-triangle me-2"></i>Low Stock
                                    </h6>
                                    <div class="mt-2" style="max-height: 200px; overflow-y: auto;">
                                        <?php foreach (array_slice($lowStockAlerts, 0, 10) as $alert): 
                                            if (!$alert['item']) continue;
                                        ?>
                                            <div class="d-flex justify-content-between align-items-center mb-2 p-2 border-bottom">
                                                <div>
                                                    <strong><?= esc($alert['item']['item_name']) ?></strong>
                                                    <br>
                                                    <small class="text-muted">
                                                        <?= esc($alert['trail']['trail_location']) ?> (<?= esc($alert['trail']['trail_code']) ?>)
                                                    </small>
                                                </div>
                                                <span class="badge bg-warning"><?= $alert['quantity'] ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php if (count($lowStockAlerts) > 10): ?>
                                            <div class="text-center mt-2">
                                                <small class="text-muted">+ <?= count($lowStockAlerts) - 10 ?> more items</small>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (empty($outOfStockAlerts) && empty($lowStockAlerts)): ?>
                                <div class="col-12 text-center py-4">
                                    <i class="bi bi-check-circle text-success" style="font-size: 3rem;"></i>
                                    <h5 class="mt-3 text-success">All Stock Levels Are Good!</h5>
                                    <p class="text-muted">No low stock or out of stock items detected.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Export Modal -->
    <div class="modal fade" id="exportModal" tabindex="-1" aria-labelledby="exportModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="exportModalLabel">
                        <i class="bi bi-download me-2"></i>Export Stock Data
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Export Format</label>
                        <select class="form-select" id="exportFormat">
                            <option value="csv">CSV Format</option>
                            <option value="excel">Excel Format</option>
                            <option value="pdf">PDF Report</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Data Range</label>
                        <select class="form-select" id="exportRange">
                            <option value="current">Current View</option>
                            <option value="all">All Data</option>
                            <option value="low_stock">Low Stock Items Only</option>
                            <option value="out_of_stock">Out of Stock Only</option>
                        </select>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="includeSummary" checked>
                        <label class="form-check-label" for="includeSummary">
                            Include Summary Statistics
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="exportData">
                        <i class="bi bi-download me-2"></i>Export Data
                    </button>
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

        // Refresh stocks button
        document.getElementById('refreshStocks')?.addEventListener('click', function() {
            this.innerHTML = '<i class="bi bi-arrow-clockwise spinner-border spinner-border-sm me-2"></i>Refreshing...';
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        });

        // Filter functionality
        document.getElementById('applyStockFilters')?.addEventListener('click', function() {
            applyStockFilters();
        });

        document.getElementById('resetStockFilters')?.addEventListener('click', function() {
            document.getElementById('trailFilter').value = 'all';
            document.getElementById('categoryFilter').value = 'all';
            document.getElementById('stockLevelFilter').value = 'all';
            document.getElementById('itemSearch').value = '';
            applyStockFilters();
        });

        function applyStockFilters() {
            const trailFilter = document.getElementById('trailFilter').value;
            const categoryFilter = document.getElementById('categoryFilter').value;
            const stockLevelFilter = document.getElementById('stockLevelFilter').value;
            const itemSearch = document.getElementById('itemSearch').value.toLowerCase();
            
            const rows = document.querySelectorAll('#liveStocksTable .trail-row');
            let visibleCount = 0;
            
            rows.forEach(row => {
                const trailId = row.getAttribute('data-trail-id');
                const totalStock = parseInt(row.getAttribute('data-total-stock'));
                const trailLocation = row.getAttribute('data-trail-location').toLowerCase();
                
                let showRow = true;
                
                // Trail filter
                if (trailFilter !== 'all' && trailId !== trailFilter) {
                    showRow = false;
                }
                
                // Stock level filter
                if (stockLevelFilter !== 'all') {
                    if (stockLevelFilter === 'out' && totalStock > 0) {
                        showRow = false;
                    } else if (stockLevelFilter === 'low' && (totalStock > 10 || totalStock === 0)) {
                        showRow = false;
                    } else if (stockLevelFilter === 'good' && totalStock <= 10) {
                        showRow = false;
                    }
                }
                
                // Item search filter
                if (itemSearch && !trailLocation.includes(itemSearch)) {
                    // Also check if any item in this row matches the search
                    let itemMatch = false;
                    const itemCells = row.querySelectorAll('.stock-quantity');
                    itemCells.forEach(cell => {
                        const itemName = cell.getAttribute('data-item-name').toLowerCase();
                        if (itemName.includes(itemSearch)) {
                            itemMatch = true;
                        }
                    });
                    
                    if (!itemMatch) {
                        showRow = false;
                    }
                }
                
                row.style.display = showRow ? '' : 'none';
                if (showRow) visibleCount++;
            });
            
            document.getElementById('stockCount').textContent = visibleCount + ' Trails';
        }

        // Toggle stock view
        document.getElementById('toggleStockView')?.addEventListener('click', function() {
            const tableContainer = document.querySelector('.table-responsive');
            const isExpanded = tableContainer.style.maxHeight === 'none';
            
            if (isExpanded) {
                tableContainer.style.maxHeight = '600px';
                tableContainer.style.overflow = 'auto';
                this.innerHTML = '<i class="bi bi-arrows-expand me-1"></i> Expand View';
            } else {
                tableContainer.style.maxHeight = 'none';
                tableContainer.style.overflow = 'visible';
                this.innerHTML = '<i class="bi bi-arrows-collapse me-1"></i> Collapse View';
            }
        });

        // Export functionality
        document.getElementById('exportData')?.addEventListener('click', function() {
            const format = document.getElementById('exportFormat').value;
            const range = document.getElementById('exportRange').value;
            const includeSummary = document.getElementById('includeSummary').checked;
            
            // Show loading state
            const originalText = this.innerHTML;
            this.innerHTML = '<i class="bi bi-arrow-repeat spinner-border spinner-border-sm me-2"></i>Exporting...';
            this.disabled = true;
            
            // Simulate export process
            setTimeout(() => {
                alert(`Exporting ${range} data in ${format.toUpperCase()} format...`);
                this.innerHTML = originalText;
                this.disabled = false;
                
                // Close modal
                const exportModal = bootstrap.Modal.getInstance(document.getElementById('exportModal'));
                exportModal.hide();
            }, 1500);
        });

        // Auto-dismiss alerts after 8 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 8000);

        // Add click handlers to stock quantity cells for quick actions
        document.querySelectorAll('.stock-quantity').forEach(cell => {
            cell.addEventListener('click', function() {
                const itemId = this.getAttribute('data-item-id');
                const itemName = this.getAttribute('data-item-name');
                const quantity = this.textContent.trim();
                
                // Show quick action menu or modal
                showStockActionModal(itemId, itemName, quantity, this);
            });
        });

        function showStockActionModal(itemId, itemName, quantity, cellElement) {
            // Create a simple modal or tooltip for quick actions
            const modalHtml = `
                <div class="modal fade" id="stockActionModal" tabindex="-1">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header bg-primary text-white">
                                <h5 class="modal-title">Stock Action: ${itemName}</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <p><strong>Current Stock:</strong> ${quantity} units</p>
                                <div class="d-grid gap-2">
                                    <button class="btn btn-warning" onclick="initiateStockTransfer('${itemId}', '${itemName}')">
                                        <i class="bi bi-arrow-left-right me-2"></i>Transfer Stock
                                    </button>
                                    <button class="btn btn-info" onclick="viewStockHistory('${itemId}', '${itemName}')">
                                        <i class="bi bi-clock-history me-2"></i>View History
                                    </button>
                                    <button class="btn btn-success" onclick="createPurchaseOrder('${itemId}', '${itemName}')">
                                        <i class="bi bi-cart-plus me-2"></i>Create Purchase Order
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Remove existing modal if any
            const existingModal = document.getElementById('stockActionModal');
            if (existingModal) {
                existingModal.remove();
            }
            
            // Add modal to body
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            
            // Show modal
            const stockActionModal = new bootstrap.Modal(document.getElementById('stockActionModal'));
            stockActionModal.show();
        }

        // Stock action functions
        function initiateStockTransfer(itemId, itemName) {
            alert(`Initiating stock transfer for: ${itemName}`);
            // Redirect to stock transfer page with pre-filled item
            window.location.href = `stock_transfer.php?item_id=${itemId}`;
        }

        function viewStockHistory(itemId, itemName) {
            alert(`Viewing stock history for: ${itemName}`);
            // Implement stock history view
        }

        function createPurchaseOrder(itemId, itemName) {
            alert(`Creating purchase order for: ${itemName}`);
            // Redirect to purchase order page with pre-filled item
            window.location.href = `purchase_order.php?item_id=${itemId}`;
        }

        // Initialize tooltips for item codes
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });

        // Auto-refresh data every 2 minutes
        setInterval(() => {
            const refreshBtn = document.getElementById('refreshStocks');
            if (refreshBtn) {
                refreshBtn.click();
            }
        }, 120000);

        // Add visual feedback for stock level changes
        function highlightStockChanges() {
            // This would typically compare with previous stock levels
            // For now, we'll just add a subtle animation
            document.querySelectorAll('.stock-quantity').forEach(cell => {
                cell.style.transition = 'all 0.3s ease';
            });
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            highlightStockChanges();
            applyStockFilters(); // Apply any existing filters
        });
    </script>
</body>
</html>
<?php
// Close database connection
$mysqli->close();
?>