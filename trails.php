<?php
/**
 * Trails Management Page - Forest Trekking System
 * Complete with trail management functionality
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

// Create new trails table with different name
$createTrailsTableQuery = "
CREATE TABLE IF NOT EXISTS forest_trails (
    id INT PRIMARY KEY AUTO_INCREMENT,
    trail_code VARCHAR(20) UNIQUE,
    trail_location VARCHAR(255) NOT NULL,
    district_name VARCHAR(100) NOT NULL,
    division_name VARCHAR(100) NOT NULL,
    menu_type VARCHAR(50) NOT NULL,
    status ENUM('Active', 'Inactive') DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";
if (!$mysqli->query($createTrailsTableQuery)) {
    die("Error creating table: " . $mysqli->error);
}

// Tamil Nadu Districts
$tamilNaduDistricts = [
    'Ariyalur', 'Chennai', 'Coimbatore', 'Cuddalore', 'Dharmapuri',
    'Dindigul', 'Erode', 'Kanchipuram', 'Kanyakumari', 'Karur',
    'Krishnagiri', 'Madurai', 'Nagapattinam', 'Namakkal', 'Nilgiris',
    'Perambalur', 'Pudukkottai', 'Ramanathapuram', 'Salem', 'Sivaganga',
    'Thanjavur', 'Tenkasi', 'Theni', 'Thoothukudi', 'Tiruchirappalli',
    'Tirunelveli', 'Tirupathur', 'Tiruppur', 'Tiruvallur', 'Tiruvannamalai',
    'Tiruvarur', 'Vellore', 'Viluppuram', 'Virudhunagar'
];

// Menu types from food_menu table
$menuTypes = ['Menu Type 1', 'Menu Type 2', 'Menu Type 3', 'Menu Type 4', 'Menu Type 5'];

// --------------------------- Helper Functions ---------------------------
function generateTrailCode($mysqli) {
    // Get the next sequence number
    $stmt = $mysqli->prepare("SELECT COUNT(*) as count FROM forest_trails");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $sequence = $row['count'] + 1;
        $stmt->close();
    } else {
        $sequence = 1;
    }
    
    return 'TT' . str_pad($sequence, 3, '0', STR_PAD_LEFT);
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

function checkTrailCodeExists($mysqli, $trailCode, $excludeId = null) {
    if ($excludeId) {
        $stmt = $mysqli->prepare("SELECT COUNT(*) as count FROM forest_trails WHERE trail_code = ? AND id != ?");
        if ($stmt) {
            $stmt->bind_param('si', $trailCode, $excludeId);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();
            return $row['count'] > 0;
        }
    } else {
        $stmt = $mysqli->prepare("SELECT COUNT(*) as count FROM forest_trails WHERE trail_code = ?");
        if ($stmt) {
            $stmt->bind_param('s', $trailCode);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();
            return $row['count'] > 0;
        }
    }
    return false;
}

function isValidTrailCodeFormat($trailCode) {
    return preg_match('/^TT\d{3}$/', $trailCode);
}

// Safe delete function
function deleteTrailSafely($mysqli, $trailId) {
    $stmt = $mysqli->prepare("DELETE FROM forest_trails WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param('i', $trailId);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }
    return false;
}

// --------------------------- Handle Form Actions ---------------------------
$action = $_POST['action'] ?? '';
$message = '';
$message_type = '';

if ($action === 'add_trail') {
    $trailCode = trim($_POST['trail_code'] ?? '');
    $trailLocation = trim($_POST['trail_location'] ?? '');
    $districtName = trim($_POST['district_name'] ?? '');
    $divisionName = trim($_POST['division_name'] ?? '');
    $menuType = trim($_POST['menu_type'] ?? '');
    
    if (!empty($trailCode) && !empty($trailLocation) && !empty($districtName) && !empty($divisionName) && !empty($menuType)) {
        // Validate trail code format
        if (!isValidTrailCodeFormat($trailCode)) {
            $message = "Invalid trail code format! Please use format: TT001, TT002, etc.";
            $message_type = "warning";
        } 
        // Check if trail code already exists
        elseif (checkTrailCodeExists($mysqli, $trailCode)) {
            $message = "Trail code already exists! Please use a different trail code.";
            $message_type = "warning";
        } else {
            $stmt = $mysqli->prepare("INSERT INTO forest_trails (trail_code, trail_location, district_name, division_name, menu_type) VALUES (?, ?, ?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param('sssss', $trailCode, $trailLocation, $districtName, $divisionName, $menuType);
                
                if ($stmt->execute()) {
                    $message = "Trail added successfully! Code: " . $trailCode;
                    $message_type = "success";
                    
                    // Clear form data after successful submission
                    $_POST = [];
                } else {
                    $message = "Error adding trail: " . $stmt->error;
                    $message_type = "danger";
                }
                $stmt->close();
            } else {
                $message = "Database error. Please try again.";
                $message_type = "danger";
            }
        }
    } else {
        $message = "All fields are required!";
        $message_type = "warning";
    }
} elseif ($action === 'update_trail') {
    $trailId = $_POST['trail_id'] ?? '';
    $trailCode = trim($_POST['trail_code'] ?? '');
    $trailLocation = trim($_POST['trail_location'] ?? '');
    $districtName = trim($_POST['district_name'] ?? '');
    $divisionName = trim($_POST['division_name'] ?? '');
    $menuType = trim($_POST['menu_type'] ?? '');
    
    if (!empty($trailId) && !empty($trailCode) && !empty($trailLocation) && !empty($districtName) && !empty($divisionName) && !empty($menuType)) {
        // Validate trail code format
        if (!isValidTrailCodeFormat($trailCode)) {
            $message = "Invalid trail code format! Please use format: TT001, TT002, etc.";
            $message_type = "warning";
        } 
        // Check if trail code already exists (excluding current trail)
        elseif (checkTrailCodeExists($mysqli, $trailCode, $trailId)) {
            $message = "Trail code already exists! Please use a different trail code.";
            $message_type = "warning";
        } else {
            $stmt = $mysqli->prepare("UPDATE forest_trails SET trail_code = ?, trail_location = ?, district_name = ?, division_name = ?, menu_type = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('sssssi', $trailCode, $trailLocation, $districtName, $divisionName, $menuType, $trailId);
                
                if ($stmt->execute()) {
                    $message = "Trail updated successfully!";
                    $message_type = "success";
                } else {
                    $message = "Error updating trail: " . $stmt->error;
                    $message_type = "danger";
                }
                $stmt->close();
            } else {
                $message = "Database error. Please try again.";
                $message_type = "danger";
            }
        }
    } else {
        $message = "All fields are required!";
        $message_type = "warning";
    }
} elseif ($action === 'delete_trail') {
    $trailId = $_POST['trail_id'] ?? '';
    
    if (!empty($trailId)) {
        if (deleteTrailSafely($mysqli, $trailId)) {
            $message = "Trail deleted successfully!";
            $message_type = "success";
        } else {
            $message = "Error deleting trail. Please try again.";
            $message_type = "danger";
        }
    }
}

// --------------------------- Fetch Trails ---------------------------
$trails = fetchAll($mysqli, "SELECT * FROM forest_trails ORDER BY created_at DESC");

// --------------------------- Utilities ---------------------------
function esc($s) { 
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); 
}

function getTrailStatus($trail) {
    return $trail['status'] ?? 'Active';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Trails Management - Forest Trekking</title>
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
        
        .trail-code {
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
            
            .trail-code {
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
            
            .trail-code {
                font-size: 0.75rem;
            }
        }
        
        /* Location Badge */
        .location-badge {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        
        /* District Badge */
        .district-badge {
            background: var(--info);
            color: white;
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 0.75rem;
        }
        
        /* Division Badge */
        .division-badge {
            background: var(--warning);
            color: white;
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 0.75rem;
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
        
        /* Trail Code Input Styles */
        .trail-code-input {
            font-family: 'Courier New', monospace;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .trail-code-help {
            font-size: 0.75rem;
            color: var(--secondary);
            margin-top: 0.25rem;
        }
        
        .form-control.is-valid {
            border-color: var(--success);
            box-shadow: 0 0 0 0.2rem rgba(25, 135, 84, 0.25);
        }
        
        .form-control.is-invalid {
            border-color: var(--danger);
            box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
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
                    <div class="dashboard-card p-4 fade-in">
                        <div class="row align-items-center">
                            <div class="col-md-6 mb-3 mb-md-0">
                                <h2 class="mb-1 fw-bold text-dark">
                                    <i class="bi bi-signpost me-2"></i>Trails Management
                                </h2>
                                <p class="text-muted mb-0">Manage trekking trails and locations across Tamil Nadu</p>
                            </div>
                            <div class="col-md-6 text-md-end">
                                <button class="btn btn-primary pulse" data-bs-toggle="modal" data-bs-target="#addTrailModal">
                                    <i class="bi bi-plus-circle me-2"></i>Add New Trail
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
                                <h4 class="fw-bold text-primary mb-1"><?= count($trails) ?></h4>
                                <p class="text-muted mb-0 small">Total Trails</p>
                            </div>
                            <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                                <i class="bi bi-signpost"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-lg-6">
                    <div class="dashboard-card stat-card p-3 slide-in" style="animation-delay: 0.2s">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="fw-bold text-success mb-1"><?= count(array_filter($trails, function($trail) { return getTrailStatus($trail) === 'Active'; })) ?></h4>
                                <p class="text-muted mb-0 small">Active Trails</p>
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
                                <h4 class="fw-bold text-warning mb-1"><?= count(array_unique(array_column($trails, 'district_name'))) ?></h4>
                                <p class="text-muted mb-0 small">Districts Covered</p>
                            </div>
                            <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                                <i class="bi bi-geo-alt"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-lg-6">
                    <div class="dashboard-card stat-card p-3 slide-in" style="animation-delay: 0.4s">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="fw-bold text-info mb-1"><?= count($menuTypes) ?></h4>
                                <p class="text-muted mb-0 small">Menu Types</p>
                            </div>
                            <div class="stat-icon bg-info bg-opacity-10 text-info">
                                <i class="bi bi-list-check"></i>
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

            <!-- Trails Table -->
            <div class="row">
                <div class="col-12">
                    <div class="dashboard-card p-4 fade-in">
                        <div class="table-header-container">
                            <h5 class="fw-bold mb-0">All Trails</h5>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-primary"><?= count($trails) ?> Trails</span>
                                <button class="btn btn-outline-primary btn-sm" id="refreshTrails">
                                    <i class="bi bi-arrow-clockwise"></i>
                                </button>
                            </div>
                        </div>

                        <?php if (empty($trails)): ?>
                            <div class="empty-state">
                                <i class="bi bi-signpost"></i>
                                <h5>No Trails Found</h5>
                                <p>Start by adding your first trail location.</p>
                                <button class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#addTrailModal">
                                    <i class="bi bi-plus-circle me-2"></i>Add First Trail
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="text-center">S.No</th>
                                            <th>Trail ID</th>
                                            <th>Trail Location</th>
                                            <th>District</th>
                                            <th>Division</th>
                                            <th>Menu Type</th>
                                            <th class="text-center">Status</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="trailsTableBody">
                                        <?php foreach ($trails as $index => $trail): ?>
                                            <tr class="fade-in real-time-update" id="trail-<?= $trail['id'] ?>" style="animation-delay: <?= $index * 0.05 ?>s">
                                                <td class="text-center fw-bold"><?= $index + 1 ?></td>
                                                <td>
                                                    <span class="trail-code"><?= esc($trail['trail_code']) ?></span>
                                                </td>
                                                <td>
                                                    <span class="location-badge">
                                                        <i class="bi bi-geo-alt me-1"></i>
                                                        <?= esc($trail['trail_location']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="district-badge"><?= esc($trail['district_name']) ?></span>
                                                </td>
                                                <td>
                                                    <span class="division-badge"><?= esc($trail['division_name']) ?></span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-secondary"><?= esc($trail['menu_type']) ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge <?= getTrailStatus($trail) === 'Active' ? 'badge-active' : 'badge-inactive' ?>">
                                                        <?= esc(getTrailStatus($trail)) ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <div class="btn-group" role="group">
                                                        <button class="btn btn-outline-primary btn-action edit-trail" 
                                                                data-bs-toggle="modal" 
                                                                data-bs-target="#editTrailModal"
                                                                data-id="<?= $trail['id'] ?>"
                                                                data-code="<?= esc($trail['trail_code']) ?>"
                                                                data-location="<?= esc($trail['trail_location']) ?>"
                                                                data-district="<?= esc($trail['district_name']) ?>"
                                                                data-division="<?= esc($trail['division_name']) ?>"
                                                                data-menu="<?= esc($trail['menu_type']) ?>">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <button class="btn btn-outline-danger btn-action delete-trail"
                                                                data-bs-toggle="modal"
                                                                data-bs-target="#deleteTrailModal"
                                                                data-id="<?= $trail['id'] ?>"
                                                                data-location="<?= esc($trail['trail_location']) ?>">
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

    <!-- Add Trail Modal -->
    <div class="modal fade" id="addTrailModal" tabindex="-1" aria-labelledby="addTrailModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="addTrailModalLabel">
                        <i class="bi bi-plus-circle me-2"></i>Add New Trail
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="addTrailForm">
                    <input type="hidden" name="action" value="add_trail">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label for="trailCode" class="form-label">Trail Code <span class="text-danger">*</span></label>
                                <input type="text" class="form-control trail-code-input" id="trailCode" name="trail_code" 
                                       placeholder="Enter trail code (e.g., TT001)" 
                                       value="<?= esc($_POST['trail_code'] ?? '') ?>" 
                                       pattern="TT\d{3}"
                                       title="Please enter trail code in format TT001, TT002, etc."
                                       required>
                                <div class="trail-code-help">
                                    Format: TT followed by 3 digits (e.g., TT001, TT002, etc.)
                                </div>
                                <div class="invalid-feedback" id="trailCodeError">
                                    Please enter a valid trail code in format TT001, TT002, etc.
                                </div>
                            </div>
                            
                            <div class="col-12">
                                <label for="trailLocation" class="form-label">Trail Location <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="trailLocation" name="trail_location" 
                                       placeholder="Enter trail location name" 
                                       value="<?= esc($_POST['trail_location'] ?? '') ?>" 
                                       required>
                            </div>
                            
                            <div class="col-12">
                                <label for="districtName" class="form-label">District <span class="text-danger">*</span></label>
                                <select class="form-select" id="districtName" name="district_name" required>
                                    <option value="">Select District</option>
                                    <?php foreach ($tamilNaduDistricts as $district): ?>
                                        <option value="<?= esc($district) ?>" <?= ($_POST['district_name'] ?? '') === $district ? 'selected' : '' ?>>
                                            <?= esc($district) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-12">
                                <label for="divisionName" class="form-label">Division <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="divisionName" name="division_name" 
                                       placeholder="Enter division name" 
                                       value="<?= esc($_POST['division_name'] ?? '') ?>" 
                                       required>
                            </div>
                            
                            <div class="col-12">
                                <label for="menuType" class="form-label">Menu Type <span class="text-danger">*</span></label>
                                <select class="form-select" id="menuType" name="menu_type" required>
                                    <option value="">Select Menu Type</option>
                                    <?php foreach ($menuTypes as $type): ?>
                                        <option value="<?= esc($type) ?>" <?= ($_POST['menu_type'] ?? '') === $type ? 'selected' : '' ?>>
                                            <?= esc($type) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="addTrailSubmit">
                            <i class="bi bi-check-circle me-2"></i>Save Trail
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Trail Modal -->
    <div class="modal fade" id="editTrailModal" tabindex="-1" aria-labelledby="editTrailModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="editTrailModalLabel">
                        <i class="bi bi-pencil me-2"></i>Edit Trail
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editTrailForm">
                    <input type="hidden" name="action" value="update_trail">
                    <input type="hidden" name="trail_id" id="editTrailId">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label for="editTrailCode" class="form-label">Trail Code <span class="text-danger">*</span></label>
                                <input type="text" class="form-control trail-code-input" id="editTrailCode" name="trail_code" 
                                       pattern="TT\d{3}"
                                       title="Please enter trail code in format TT001, TT002, etc."
                                       required>
                                <div class="trail-code-help">
                                    Format: TT followed by 3 digits (e.g., TT001, TT002, etc.)
                                </div>
                                <div class="invalid-feedback" id="editTrailCodeError">
                                    Please enter a valid trail code in format TT001, TT002, etc.
                                </div>
                            </div>
                            
                            <div class="col-12">
                                <label for="editTrailLocation" class="form-label">Trail Location <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="editTrailLocation" name="trail_location" required>
                            </div>
                            
                            <div class="col-12">
                                <label for="editDistrictName" class="form-label">District <span class="text-danger">*</span></label>
                                <select class="form-select" id="editDistrictName" name="district_name" required>
                                    <option value="">Select District</option>
                                    <?php foreach ($tamilNaduDistricts as $district): ?>
                                        <option value="<?= esc($district) ?>"><?= esc($district) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-12">
                                <label for="editDivisionName" class="form-label">Division <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="editDivisionName" name="division_name" required>
                            </div>
                            
                            <div class="col-12">
                                <label for="editMenuType" class="form-label">Menu Type <span class="text-danger">*</span></label>
                                <select class="form-select" id="editMenuType" name="menu_type" required>
                                    <option value="">Select Menu Type</option>
                                    <?php foreach ($menuTypes as $type): ?>
                                        <option value="<?= esc($type) ?>"><?= esc($type) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning text-white" id="editTrailSubmit">
                            <i class="bi bi-check-circle me-2"></i>Update Trail
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Trail Modal -->
    <div class="modal fade" id="deleteTrailModal" tabindex="-1" aria-labelledby="deleteTrailModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="deleteTrailModalLabel">
                        <i class="bi bi-exclamation-triangle me-2"></i>Confirm Delete
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="deleteTrailForm">
                    <input type="hidden" name="action" value="delete_trail">
                    <input type="hidden" name="trail_id" id="deleteTrailId">
                    <div class="modal-body">
                        <p>Are you sure you want to delete the trail at <strong id="deleteTrailLocation" class="text-danger"></strong>?</p>
                        <p class="text-muted small">This action cannot be undone.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger" id="deleteTrailSubmit">
                            <i class="bi bi-trash me-2"></i>Delete Trail
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

        // Edit Trail Modal Handler
        document.querySelectorAll('.edit-trail').forEach(button => {
            button.addEventListener('click', function() {
                const trailId = this.getAttribute('data-id');
                const trailCode = this.getAttribute('data-code');
                const location = this.getAttribute('data-location');
                const district = this.getAttribute('data-district');
                const division = this.getAttribute('data-division');
                const menuType = this.getAttribute('data-menu');
                
                document.getElementById('editTrailId').value = trailId;
                document.getElementById('editTrailCode').value = trailCode;
                document.getElementById('editTrailLocation').value = location;
                document.getElementById('editDistrictName').value = district;
                document.getElementById('editDivisionName').value = division;
                document.getElementById('editMenuType').value = menuType;
            });
        });

        // Delete Trail Modal Handler
        document.querySelectorAll('.delete-trail').forEach(button => {
            button.addEventListener('click', function() {
                const trailId = this.getAttribute('data-id');
                const location = this.getAttribute('data-location');
                
                document.getElementById('deleteTrailId').value = trailId;
                document.getElementById('deleteTrailLocation').textContent = location;
            });
        });

        // Auto-focus on trail code input when modal opens
        const addTrailModal = document.getElementById('addTrailModal');
        addTrailModal.addEventListener('shown.bs.modal', () => {
            document.getElementById('trailCode').focus();
        });

        // Auto-dismiss alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);

        // Refresh trails button
        document.getElementById('refreshTrails')?.addEventListener('click', function() {
            this.classList.add('btn-loading');
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        });

        // Form submission handling
        document.getElementById('addTrailForm')?.addEventListener('submit', function(e) {
            const trailCode = document.getElementById('trailCode').value;
            const submitBtn = document.getElementById('addTrailSubmit');
            
            // Validate trail code format
            if (!/^TT\d{3}$/.test(trailCode)) {
                e.preventDefault();
                document.getElementById('trailCode').classList.add('is-invalid');
                return;
            }
            
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="bi bi-arrow-repeat spinner-border spinner-border-sm me-2"></i>Saving...';
        });

        document.getElementById('editTrailForm')?.addEventListener('submit', function(e) {
            const trailCode = document.getElementById('editTrailCode').value;
            const submitBtn = document.getElementById('editTrailSubmit');
            
            // Validate trail code format
            if (!/^TT\d{3}$/.test(trailCode)) {
                e.preventDefault();
                document.getElementById('editTrailCode').classList.add('is-invalid');
                return;
            }
            
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="bi bi-arrow-repeat spinner-border spinner-border-sm me-2"></i>Updating...';
        });

        document.getElementById('deleteTrailForm')?.addEventListener('submit', function(e) {
            const submitBtn = document.getElementById('deleteTrailSubmit');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="bi bi-arrow-repeat spinner-border spinner-border-sm me-2"></i>Deleting...';
        });

        // Trail code format validation
        document.getElementById('trailCode')?.addEventListener('input', function() {
            const trailCode = this.value.toUpperCase();
            this.value = trailCode;
            
            if (/^TT\d{3}$/.test(trailCode)) {
                this.classList.remove('is-invalid');
                this.classList.add('is-valid');
            } else {
                this.classList.remove('is-valid');
                this.classList.add('is-invalid');
            }
        });

        document.getElementById('editTrailCode')?.addEventListener('input', function() {
            const trailCode = this.value.toUpperCase();
            this.value = trailCode;
            
            if (/^TT\d{3}$/.test(trailCode)) {
                this.classList.remove('is-invalid');
                this.classList.add('is-valid');
            } else {
                this.classList.remove('is-valid');
                this.classList.add('is-invalid');
            }
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

        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }

        // Generate suggested trail code
        function generateSuggestedTrailCode() {
            const existingCodes = <?= json_encode(array_column($trails, 'trail_code')) ?>;
            let sequence = 1;
            
            while (true) {
                const suggestedCode = 'TT' + sequence.toString().padStart(3, '0');
                if (!existingCodes.includes(suggestedCode)) {
                    return suggestedCode;
                }
                sequence++;
            }
        }

        // Set suggested trail code when modal opens
        addTrailModal.addEventListener('show.bs.modal', function() {
            const suggestedCode = generateSuggestedTrailCode();
            document.getElementById('trailCode').value = suggestedCode;
            document.getElementById('trailCode').classList.add('is-valid');
        });

        // Reset form validation when modal closes
        addTrailModal.addEventListener('hidden.bs.modal', function() {
            document.getElementById('trailCode').classList.remove('is-valid', 'is-invalid');
        });

        const editTrailModal = document.getElementById('editTrailModal');
        editTrailModal.addEventListener('hidden.bs.modal', function() {
            document.getElementById('editTrailCode').classList.remove('is-valid', 'is-invalid');
        });
    </script>                 
</body>
</html>