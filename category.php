<?php
/**
 * Category Management Page - Forest Trekking System
 * Error-free with proper column checking
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

// Create categories table if not exists with all required columns
$createTableQuery = "
CREATE TABLE IF NOT EXISTS categories (
    category_id INT PRIMARY KEY AUTO_INCREMENT,
    category_code VARCHAR(20) UNIQUE,
    category_name VARCHAR(100) NOT NULL,
    description TEXT,
    status ENUM('Active', 'Inactive') DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";
$mysqli->query($createTableQuery);

// Safe column checking function
function checkAndAddColumn($mysqli, $table, $column, $definition) {
    // Check if column exists using information_schema (more reliable)
    $checkQuery = $mysqli->query("
        SELECT COUNT(*) as column_exists 
        FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = '$table' 
        AND COLUMN_NAME = '$column'
    ");
    
    if ($checkQuery) {
        $result = $checkQuery->fetch_assoc();
        if ($result['column_exists'] == 0) {
            // Column doesn't exist, add it
            $mysqli->query("ALTER TABLE $table ADD COLUMN $column $definition");
        }
        $checkQuery->free();
    }
}

// Check and add missing columns safely
checkAndAddColumn($mysqli, 'categories', 'category_code', 'VARCHAR(20) UNIQUE');
checkAndAddColumn($mysqli, 'categories', 'status', "ENUM('Active', 'Inactive') DEFAULT 'Active'");
checkAndAddColumn($mysqli, 'categories', 'updated_at', "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");

// --------------------------- Helper Functions ---------------------------
function generateCategoryCode($categoryName, $mysqli) {
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
    
    return $code;
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

// Safe delete function to handle foreign key constraints - FIXED TABLE NAME
function deleteCategorySafely($mysqli, $categoryId) {
    $mysqli->begin_transaction();
    
    try {
        // First, check if there are any items using this category - FIXED: using items_data table
        $checkItems = $mysqli->prepare("SELECT COUNT(*) as item_count FROM items_data WHERE category_id = ?");
        if (!$checkItems) {
            throw new Exception("Failed to prepare check items query");
        }
        
        $checkItems->bind_param('i', $categoryId);
        $checkItems->execute();
        $result = $checkItems->get_result();
        $itemCount = $result->fetch_assoc()['item_count'];
        $checkItems->close();
        
        if ($itemCount > 0) {
            // Set category_id to NULL for items using this category - FIXED: using items_data table
            $updateItems = $mysqli->prepare("UPDATE items_data SET category_id = NULL WHERE category_id = ?");
            if ($updateItems) {
                $updateItems->bind_param('i', $categoryId);
                $updateItems->execute();
                $updateItems->close();
            }
        }
        
        // Then delete the category
        $deleteCategory = $mysqli->prepare("DELETE FROM categories WHERE category_id = ?");
        if (!$deleteCategory) {
            throw new Exception("Failed to prepare delete category query");
        }
        
        $deleteCategory->bind_param('i', $categoryId);
        $deleteCategory->execute();
        $affectedRows = $deleteCategory->affected_rows;
        $deleteCategory->close();
        
        $mysqli->commit();
        return $affectedRows > 0;
        
    } catch (Exception $e) {
        $mysqli->rollback();
        return false;
    }
}

// Generate category codes for existing categories that don't have them
$categoriesWithoutCode = fetchAll($mysqli, "SELECT * FROM categories WHERE category_code IS NULL OR category_code = ''");
foreach ($categoriesWithoutCode as $category) {
    $categoryCode = generateCategoryCode($category['category_name'], $mysqli);
    $stmt = $mysqli->prepare("UPDATE categories SET category_code = ?, status = 'Active' WHERE category_id = ?");
    if ($stmt) {
        $stmt->bind_param('si', $categoryCode, $category['category_id']);
        $stmt->execute();
        $stmt->close();
    }
}

// --------------------------- Handle Form Actions ---------------------------
$action = $_POST['action'] ?? '';
$message = '';
$message_type = '';

if ($action === 'add_category') {
    $categoryName = trim($_POST['category_name'] ?? '');
    
    if (!empty($categoryName)) {
        $categoryCode = generateCategoryCode($categoryName, $mysqli);
        
        $stmt = $mysqli->prepare("INSERT INTO categories (category_code, category_name) VALUES (?, ?)");
        if ($stmt) {
            $stmt->bind_param('ss', $categoryCode, $categoryName);
            
            if ($stmt->execute()) {
                $message = "Category added successfully! Code: " . $categoryCode;
                $message_type = "success";
            } else {
                $message = "Error adding category: " . $stmt->error;
                $message_type = "danger";
            }
            $stmt->close();
        } else {
            $message = "Database error. Please try again.";
            $message_type = "danger";
        }
    } else {
        $message = "Category name is required!";
        $message_type = "warning";
    }
} elseif ($action === 'update_category') {
    $categoryId = $_POST['category_id'] ?? '';
    $categoryName = trim($_POST['category_name'] ?? '');
    
    if (!empty($categoryId) && !empty($categoryName)) {
        // Regenerate category code when name changes
        $categoryCode = generateCategoryCode($categoryName, $mysqli);
        
        // Check if updated_at column exists and use appropriate query
        $checkUpdatedAt = $mysqli->query("
            SELECT COUNT(*) as column_exists 
            FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'categories' 
            AND COLUMN_NAME = 'updated_at'
        ");
        
        $hasUpdatedAt = false;
        if ($checkUpdatedAt) {
            $result = $checkUpdatedAt->fetch_assoc();
            $hasUpdatedAt = ($result['column_exists'] == 1);
            $checkUpdatedAt->free();
        }
        
        if ($hasUpdatedAt) {
            $stmt = $mysqli->prepare("UPDATE categories SET category_name = ?, category_code = ?, updated_at = CURRENT_TIMESTAMP WHERE category_id = ?");
        } else {
            $stmt = $mysqli->prepare("UPDATE categories SET category_name = ?, category_code = ? WHERE category_id = ?");
        }
        
        if ($stmt) {
            if ($hasUpdatedAt) {
                $stmt->bind_param('ssi', $categoryName, $categoryCode, $categoryId);
            } else {
                $stmt->bind_param('ssi', $categoryName, $categoryCode, $categoryId);
            }
            
            if ($stmt->execute()) {
                $message = "Category updated successfully!";
                $message_type = "success";
            } else {
                $message = "Error updating category: " . $stmt->error;
                $message_type = "danger";
            }
            $stmt->close();
        } else {
            $message = "Database error. Please try again.";
            $message_type = "danger";
        }
    }
} elseif ($action === 'delete_category') {
    $categoryId = $_POST['category_id'] ?? '';
    
    if (!empty($categoryId)) {
        if (deleteCategorySafely($mysqli, $categoryId)) {
            $message = "Category deleted successfully!";
            $message_type = "success";
        } else {
            $message = "Error deleting category. Please try again.";
            $message_type = "danger";
        }
    }
}

// --------------------------- Fetch Categories ---------------------------
$categories = fetchAll($mysqli, "SELECT * FROM categories ORDER BY created_at DESC");

// --------------------------- Utilities ---------------------------
function esc($s) { 
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); 
}

// Safe access to category data with defaults
function getCategoryCode($category) {
    return $category['category_code'] ?? 'N/A';
}

function getCategoryStatus($category) {
    return $category['status'] ?? 'Active';
}

function getCategoryDescription($category) {
    return $category['description'] ?? '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Category Management - Forest Trekking</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2E8B57;
            --primary-dark: #1f6e45;
            --secondary: #6c757d;
            --success: #198754;
            --warning: #ffc107;
            --danger: #dc3545;
            --light: #f8f9fa;
            --dark: #212529;
            --sidebar-width: 250px;
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
            transition: all 0.3s ease;
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
            transition: all 0.3s ease;
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
            transition: all 0.3s ease;
        }
        
        /* Top Navigation Bar */
        .top-navbar {
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 999;
        }
        
        /* Centered Logo Styles - UPDATED */
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
            transition: all 0.3s ease;
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
        
        .btn-primary {
            background: var(--primary);
            border-color: var(--primary);
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
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
            background-color: rgba(46, 139, 87, 0.05);
        }
        
        .category-code {
            font-family: 'Courier New', monospace;
            font-weight: bold;
            color: var(--primary);
            background: rgba(46, 139, 87, 0.1);
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
            transition: all 0.3s ease;
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
            
            /* Ensure logo stays centered on mobile */
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
            
            .category-code {
                font-size: 0.8rem;
                padding: 2px 6px;
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
            
            .category-code {
                font-size: 0.75rem;
            }
        }
        
        /* Animation */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .fade-in {
            animation: fadeIn 0.3s ease-in-out;
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
        
        /* Compact mobile view */
        .mobile-compact-view .table th,
        .mobile-compact-view .table td {
            padding: 0.4rem 0.3rem;
        }
        
        /* Real-time updates */
        .real-time-update {
            transition: all 0.3s ease;
        }
        
        /* Loading states */
        .btn-loading {
            position: relative;
            pointer-events: none;
        }
        
        .btn-loading::after {
            content: '';
            position: absolute;
            width: 16px;
            height: 16px;
            top: 50%;
            left: 50%;
            margin-left: -8px;
            margin-top: -8px;
            border: 2px solid transparent;
            border-top-color: currentColor;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
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
                    <div class="dashboard-card p-4">
                        <div class="row align-items-center">
                            <div class="col-md-6 mb-3 mb-md-0">
                                <h2 class="mb-1 fw-bold text-dark">
                                    <i class="bi bi-tags me-2"></i>Category Management
                                </h2>
                                <p class="text-muted mb-0">Manage product categories and classifications</p>
                            </div>
                            <div class="col-md-6 text-md-end">
                                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                                    <i class="bi bi-plus-circle me-2"></i>Add New Category
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Message Alert -->
            <?php if (!empty($message)): ?>
                <div class="row mb-3">
                    <div class="col-12">
                        <div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
                            <i class="bi bi-<?= $message_type === 'success' ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
                            <?= esc($message) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Categories Table -->
            <div class="row">
                <div class="col-12">
                    <div class="dashboard-card p-4">
                        <div class="table-header-container">
                            <h5 class="fw-bold mb-0">All Categories</h5>
                            <span class="badge bg-primary"><?= count($categories) ?> Categories</span>
                        </div>

                        <?php if (empty($categories)): ?>
                            <div class="empty-state">
                                <i class="bi bi-tags"></i>
                                <h5>No Categories Found</h5>
                                <p>Start by adding your first category to organize your inventory.</p>
                                <button class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                                    <i class="bi bi-plus-circle me-2"></i>Add First Category
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="text-center">S.No</th>
                                            <th>Category Code</th>
                                            <th>Category Name</th>
                                            <th>Status</th>
                                            <th>Created Date</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="categoriesTableBody">
                                        <?php foreach ($categories as $index => $category): ?>
                                            <tr class="fade-in real-time-update" id="category-<?= $category['category_id'] ?>">
                                                <td class="text-center fw-bold"><?= $index + 1 ?></td>
                                                <td>
                                                    <span class="category-code"><?= esc(getCategoryCode($category)) ?></span>
                                                </td>
                                                <td>
                                                    <strong><?= esc($category['category_name']) ?></strong>
                                                    <?php if (!empty(getCategoryDescription($category))): ?>
                                                        <br><small class="text-muted"><?= esc(getCategoryDescription($category)) ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge <?= getCategoryStatus($category) === 'Active' ? 'badge-active' : 'badge-inactive' ?>">
                                                        <?= esc(getCategoryStatus($category)) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <small class="text-muted">
                                                        <?= date('M j, Y', strtotime($category['created_at'])) ?>
                                                    </small>
                                                </td>
                                                <td class="text-center">
                                                    <div class="btn-group" role="group">
                                                        <button class="btn btn-outline-primary btn-action edit-category" 
                                                                data-bs-toggle="modal" 
                                                                data-bs-target="#editCategoryModal"
                                                                data-id="<?= $category['category_id'] ?>"
                                                                data-name="<?= esc($category['category_name']) ?>">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <button class="btn btn-outline-danger btn-action delete-category"
                                                                data-bs-toggle="modal"
                                                                data-bs-target="#deleteCategoryModal"
                                                                data-id="<?= $category['category_id'] ?>"
                                                                data-name="<?= esc($category['category_name']) ?>">
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

    <!-- Add Category Modal -->
    <div class="modal fade" id="addCategoryModal" tabindex="-1" aria-labelledby="addCategoryModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="addCategoryModalLabel">
                        <i class="bi bi-plus-circle me-2"></i>Add New Category
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="addCategoryForm">
                    <input type="hidden" name="action" value="add_category">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="categoryName" class="form-label">Category Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="categoryName" name="category_name" 
                                   placeholder="Enter category name" required maxlength="100">
                            <div class="form-text">Category code will be auto-generated based on the name (characters only).</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="addCategoryBtn">
                            <i class="bi bi-check-circle me-2"></i>Save Category
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Category Modal -->
    <div class="modal fade" id="editCategoryModal" tabindex="-1" aria-labelledby="editCategoryModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="editCategoryModalLabel">
                        <i class="bi bi-pencil me-2"></i>Edit Category
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editCategoryForm">
                    <input type="hidden" name="action" value="update_category">
                    <input type="hidden" name="category_id" id="editCategoryId">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="editCategoryName" class="form-label">Category Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="editCategoryName" name="category_name" 
                                   placeholder="Enter category name" required maxlength="100">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning text-white" id="editCategoryBtn">
                            <i class="bi bi-check-circle me-2"></i>Update Category
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Category Modal -->
    <div class="modal fade" id="deleteCategoryModal" tabindex="-1" aria-labelledby="deleteCategoryModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="deleteCategoryModalLabel">
                        <i class="bi bi-exclamation-triangle me-2"></i>Confirm Delete
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="deleteCategoryForm">
                    <input type="hidden" name="action" value="delete_category">
                    <input type="hidden" name="category_id" id="deleteCategoryId">
                    <div class="modal-body">
                        <p>Are you sure you want to delete the category <strong id="deleteCategoryName" class="text-danger"></strong>?</p>
                        <p class="text-muted small">This action cannot be undone. Any items using this category will have their category set to NULL.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger" id="deleteCategoryBtn">
                            <i class="bi bi-trash me-2"></i>Delete Category
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

        // Edit Category Modal Handler
        document.querySelectorAll('.edit-category').forEach(button => {
            button.addEventListener('click', function() {
                const categoryId = this.getAttribute('data-id');
                const categoryName = this.getAttribute('data-name');
                
                document.getElementById('editCategoryId').value = categoryId;
                document.getElementById('editCategoryName').value = categoryName;
            });
        });

        // Delete Category Modal Handler
        document.querySelectorAll('.delete-category').forEach(button => {
            button.addEventListener('click', function() {
                const categoryId = this.getAttribute('data-id');
                const categoryName = this.getAttribute('data-name');
                
                document.getElementById('deleteCategoryId').value = categoryId;
                document.getElementById('deleteCategoryName').textContent = categoryName;
            });
        });

        // Auto-focus on category name input when modal opens
        const addCategoryModal = document.getElementById('addCategoryModal');
        addCategoryModal.addEventListener('shown.bs.modal', () => {
            document.getElementById('categoryName').focus();
        });

        const editCategoryModal = document.getElementById('editCategoryModal');
        editCategoryModal.addEventListener('shown.bs.modal', () => {
            document.getElementById('editCategoryName').focus();
        });

        // Auto-dismiss alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);

        // Add mobile compact view class for small screens
        function checkScreenSize() {
            const table = document.querySelector('.table');
            if (window.innerWidth < 576) {
                table.classList.add('mobile-compact-view');
            } else {
                table.classList.remove('mobile-compact-view');
            }
        }

        // Check on load and resize
        window.addEventListener('load', checkScreenSize);
        window.addEventListener('resize', checkScreenSize);

        // Real-time form submission handling
        document.getElementById('addCategoryForm')?.addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="bi bi-arrow-repeat spinner-border spinner-border-sm me-2"></i>Saving...';
        });

        document.getElementById('editCategoryForm')?.addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="bi bi-arrow-repeat spinner-border spinner-border-sm me-2"></i>Updating...';
        });

        document.getElementById('deleteCategoryForm')?.addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="bi bi-arrow-repeat spinner-border spinner-border-sm me-2"></i>Deleting...';
        });
    </script>
</body>
</html>