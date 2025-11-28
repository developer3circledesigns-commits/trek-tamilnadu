<?php
/**
 * Supplier Management Page - Forest Trekking System
 * Complete with real-time functionality and database integration
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

// Create suppliers table if not exists
$createSuppliersTableQuery = "
CREATE TABLE IF NOT EXISTS suppliers (
    supplier_id INT PRIMARY KEY AUTO_INCREMENT,
    supplier_code VARCHAR(20) UNIQUE,
    supplier_name VARCHAR(255) NOT NULL,
    contact_person VARCHAR(100),
    email VARCHAR(100),
    phone VARCHAR(20),
    address TEXT,
    gstin VARCHAR(15),
    pan_number VARCHAR(10),
    status ENUM('Active', 'Inactive') DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";
$mysqli->query($createSuppliersTableQuery);

// --------------------------- Helper Functions ---------------------------
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
checkAndAddColumn($mysqli, 'suppliers', 'supplier_code', 'VARCHAR(20) UNIQUE');
checkAndAddColumn($mysqli, 'suppliers', 'gstin', 'VARCHAR(15)');
checkAndAddColumn($mysqli, 'suppliers', 'pan_number', 'VARCHAR(10)');
checkAndAddColumn($mysqli, 'suppliers', 'status', "ENUM('Active', 'Inactive') DEFAULT 'Active'");
checkAndAddColumn($mysqli, 'suppliers', 'updated_at', "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");

function generateSupplierCode($mysqli) {
    // Get the maximum existing supplier code number
    $result = $mysqli->query("SELECT MAX(CAST(SUBSTRING(supplier_code, 4) AS UNSIGNED)) as max_code FROM suppliers WHERE supplier_code LIKE 'SUP%'");
    $row = $result->fetch_assoc();
    $nextNumber = ($row['max_code'] ?? 0) + 1;
    
    // Format as SUP followed by 3-digit number with leading zeros
    return 'SUP' . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
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

// Validation functions for GSTIN and PAN
function validateGSTIN($gstin) {
    if (empty($gstin)) return true; // Empty is allowed
    
    // GSTIN format: 2 chars (state code) + 10 chars (PAN) + 1 char (entity) + 1 char (Z by default) + 1 char (check digit)
    if (strlen($gstin) !== 15) return false;
    
    // Check if first 2 characters are state codes (basic check)
    if (!preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}[Z]{1}[0-9A-Z]{1}$/', $gstin)) {
        return false;
    }
    
    return true;
}

function validatePAN($pan) {
    if (empty($pan)) return true; // Empty is allowed
    
    // PAN format: 5 letters + 4 digits + 1 letter
    if (strlen($pan) !== 10) return false;
    
    if (!preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', $pan)) {
        return false;
    }
    
    return true;
}

// Safe delete function to handle foreign key constraints
function deleteSupplierSafely($mysqli, $supplierId) {
    $mysqli->begin_transaction();
    
    try {
        // First, check if there are any items using this supplier
        $checkItems = $mysqli->prepare("SELECT COUNT(*) as item_count FROM items_data WHERE supplier_id = ?");
        if (!$checkItems) {
            throw new Exception("Failed to prepare check items query");
        }
        
        $checkItems->bind_param('i', $supplierId);
        $checkItems->execute();
        $result = $checkItems->get_result();
        $itemCount = $result->fetch_assoc()['item_count'];
        $checkItems->close();
        
        if ($itemCount > 0) {
            // Set supplier_id to NULL for items using this supplier
            $updateItems = $mysqli->prepare("UPDATE items_data SET supplier_id = NULL WHERE supplier_id = ?");
            if ($updateItems) {
                $updateItems->bind_param('i', $supplierId);
                $updateItems->execute();
                $updateItems->close();
            }
        }
        
        // Then delete the supplier
        $deleteSupplier = $mysqli->prepare("DELETE FROM suppliers WHERE supplier_id = ?");
        if (!$deleteSupplier) {
            throw new Exception("Failed to prepare delete supplier query");
        }
        
        $deleteSupplier->bind_param('i', $supplierId);
        $deleteSupplier->execute();
        $affectedRows = $deleteSupplier->affected_rows;
        $deleteSupplier->close();
        
        $mysqli->commit();
        return $affectedRows > 0;
        
    } catch (Exception $e) {
        $mysqli->rollback();
        return false;
    }
}

// Generate supplier codes for existing suppliers that don't have them
$suppliersWithoutCode = fetchAll($mysqli, "SELECT * FROM suppliers WHERE supplier_code IS NULL OR supplier_code = ''");
foreach ($suppliersWithoutCode as $index => $supplier) {
    $supplierCode = generateSupplierCode($mysqli);
    $stmt = $mysqli->prepare("UPDATE suppliers SET supplier_code = ? WHERE supplier_id = ?");
    if ($stmt) {
        $stmt->bind_param('si', $supplierCode, $supplier['supplier_id']);
        $stmt->execute();
        $stmt->close();
    }
}

// --------------------------- Handle Form Actions ---------------------------
$action = $_POST['action'] ?? '';
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'add_supplier') {
        $supplierName = trim($_POST['supplier_name'] ?? '');
        $contactPerson = trim($_POST['contact_person'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $gstin = trim($_POST['gstin'] ?? '');
        $panNumber = trim($_POST['pan_number'] ?? '');
        
        if (!empty($supplierName)) {
            // Validate GSTIN
            if (!empty($gstin) && !validateGSTIN($gstin)) {
                $message = "Invalid GSTIN format! Please enter a valid 15-character GSTIN.";
                $message_type = "warning";
            }
            // Validate PAN
            elseif (!empty($panNumber) && !validatePAN($panNumber)) {
                $message = "Invalid PAN format! Please enter a valid 10-character PAN.";
                $message_type = "warning";
            } else {
                $supplierCode = generateSupplierCode($mysqli);
                
                $stmt = $mysqli->prepare("INSERT INTO suppliers (supplier_code, supplier_name, contact_person, email, phone, address, gstin, pan_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                if ($stmt) {
                    $stmt->bind_param('ssssssss', $supplierCode, $supplierName, $contactPerson, $email, $phone, $address, $gstin, $panNumber);
                    
                    if ($stmt->execute()) {
                        $message = "Supplier added successfully! Code: " . $supplierCode;
                        $message_type = "success";
                        
                        // Redirect to prevent form resubmission
                        header("Location: " . $_SERVER['PHP_SELF'] . "?message=" . urlencode($message) . "&message_type=" . $message_type);
                        exit();
                    } else {
                        $message = "Error adding supplier: " . $stmt->error;
                        $message_type = "danger";
                    }
                    $stmt->close();
                } else {
                    $message = "Database error. Please try again.";
                    $message_type = "danger";
                }
            }
        } else {
            $message = "Supplier name is required!";
            $message_type = "warning";
        }
    } elseif ($action === 'update_supplier') {
        $supplierId = $_POST['supplier_id'] ?? '';
        $supplierName = trim($_POST['supplier_name'] ?? '');
        $contactPerson = trim($_POST['contact_person'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $gstin = trim($_POST['gstin'] ?? '');
        $panNumber = trim($_POST['pan_number'] ?? '');
        
        if (!empty($supplierId) && !empty($supplierName)) {
            // Validate GSTIN
            if (!empty($gstin) && !validateGSTIN($gstin)) {
                $message = "Invalid GSTIN format! Please enter a valid 15-character GSTIN.";
                $message_type = "warning";
            }
            // Validate PAN
            elseif (!empty($panNumber) && !validatePAN($panNumber)) {
                $message = "Invalid PAN format! Please enter a valid 10-character PAN.";
                $message_type = "warning";
            } else {
                $stmt = $mysqli->prepare("UPDATE suppliers SET supplier_name = ?, contact_person = ?, email = ?, phone = ?, address = ?, gstin = ?, pan_number = ?, updated_at = CURRENT_TIMESTAMP WHERE supplier_id = ?");
                if ($stmt) {
                    $stmt->bind_param('sssssssi', $supplierName, $contactPerson, $email, $phone, $address, $gstin, $panNumber, $supplierId);
                    
                    if ($stmt->execute()) {
                        $message = "Supplier updated successfully!";
                        $message_type = "success";
                        
                        // Redirect to prevent form resubmission
                        header("Location: " . $_SERVER['PHP_SELF'] . "?message=" . urlencode($message) . "&message_type=" . $message_type);
                        exit();
                    } else {
                        $message = "Error updating supplier: " . $stmt->error;
                        $message_type = "danger";
                    }
                    $stmt->close();
                } else {
                    $message = "Database error. Please try again.";
                    $message_type = "danger";
                }
            }
        }
    } elseif ($action === 'delete_supplier') {
        $supplierId = $_POST['supplier_id'] ?? '';
        
        if (!empty($supplierId)) {
            if (deleteSupplierSafely($mysqli, $supplierId)) {
                $message = "Supplier deleted successfully!";
                $message_type = "success";
                
                // Redirect to prevent form resubmission
                header("Location: " . $_SERVER['PHP_SELF'] . "?message=" . urlencode($message) . "&message_type=" . $message_type);
                exit();
            } else {
                $message = "Error deleting supplier. Please try again.";
                $message_type = "danger";
            }
        }
    }
}

// Check for message in URL parameters (from redirect)
if (isset($_GET['message']) && isset($_GET['message_type'])) {
    $message = urldecode($_GET['message']);
    $message_type = $_GET['message_type'];
}

// --------------------------- Fetch Suppliers ---------------------------
$suppliers = fetchAll($mysqli, "SELECT * FROM suppliers ORDER BY created_at DESC");

// --------------------------- Utilities ---------------------------
function esc($s) { 
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); 
}

// Safe access to supplier data with defaults
function getSupplierCode($supplier) {
    return $supplier['supplier_code'] ?? 'N/A';
}

function getSupplierStatus($supplier) {
    return $supplier['status'] ?? 'Active';
}

function getGSTIN($supplier) {
    return $supplier['gstin'] ?? '';
}

function getPAN($supplier) {
    return $supplier['pan_number'] ?? '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Supplier Management - Forest Trekking</title>
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
        
        .supplier-code {
            font-family: 'Courier New', monospace;
            font-weight: bold;
            color: var(--primary);
            background: var(--primary-light);
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.85rem;
        }
        
        .tax-number {
            font-family: 'Courier New', monospace;
            font-size: 0.8rem;
            background: #f8f9fa;
            padding: 3px 6px;
            border-radius: 3px;
            border: 1px solid #e9ecef;
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
        
        /* Validation Styles */
        .is-valid {
            border-color: var(--success) !important;
        }
        
        .is-invalid {
            border-color: var(--danger) !important;
        }
        
        .validation-feedback {
            font-size: 0.75rem;
            margin-top: 0.25rem;
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
            
            .supplier-code {
                font-size: 0.8rem;
                padding: 2px 6px;
            }
            
            .tax-number {
                font-size: 0.75rem;
                padding: 2px 4px;
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
            
            .supplier-code {
                font-size: 0.75rem;
            }
            
            .tax-number {
                font-size: 0.7rem;
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
        
        /* Contact Info */
        .contact-info {
            font-size: 0.85rem;
            color: var(--secondary);
        }
        
        .address-info {
            max-width: 200px;
            word-wrap: break-word;
        }
        
        .tax-info {
            max-width: 150px;
            word-wrap: break-word;
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
                                    <i class="bi bi-truck me-2"></i>Supplier Management
                                </h2>
                                <p class="text-muted mb-0">Manage suppliers and their contact information</p>
                            </div>
                            <div class="col-md-6 text-md-end">
                                <button class="btn btn-primary pulse" data-bs-toggle="modal" data-bs-target="#addSupplierModal">
                                    <i class="bi bi-plus-circle me-2"></i>Add New Supplier
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
                                <h4 class="fw-bold text-primary mb-1"><?= count($suppliers) ?></h4>
                                <p class="text-muted mb-0 small">Total Suppliers</p>
                            </div>
                            <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                                <i class="bi bi-truck"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-lg-6">
                    <div class="dashboard-card stat-card p-3 slide-in" style="animation-delay: 0.2s">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="fw-bold text-success mb-1"><?= count(array_filter($suppliers, function($supplier) { return getSupplierStatus($supplier) === 'Active'; })) ?></h4>
                                <p class="text-muted mb-0 small">Active Suppliers</p>
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
                                <h4 class="fw-bold text-warning mb-1"><?= count(array_filter($suppliers, function($supplier) { return getSupplierStatus($supplier) === 'Inactive'; })) ?></h4>
                                <p class="text-muted mb-0 small">Inactive Suppliers</p>
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
                                <h4 class="fw-bold text-info mb-1"><?= count(array_unique(array_column($suppliers, 'supplier_code'))) ?></h4>
                                <p class="text-muted mb-0 small">Unique Suppliers</p>
                            </div>
                            <div class="stat-icon bg-info bg-opacity-10 text-info">
                                <i class="bi bi-building"></i>
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

            <!-- Suppliers Table -->
            <div class="row">
                <div class="col-12">
                    <div class="dashboard-card p-4 fade-in">
                        <div class="table-header-container">
                            <h5 class="fw-bold mb-0">All Suppliers</h5>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-primary"><?= count($suppliers) ?> Suppliers</span>
                                <button class="btn btn-outline-primary btn-sm" id="refreshSuppliers">
                                    <i class="bi bi-arrow-clockwise"></i>
                                </button>
                            </div>
                        </div>

                        <?php if (empty($suppliers)): ?>
                            <div class="empty-state">
                                <i class="bi bi-truck"></i>
                                <h5>No Suppliers Found</h5>
                                <p>Start by adding your first supplier to manage inventory.</p>
                                <button class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#addSupplierModal">
                                    <i class="bi bi-plus-circle me-2"></i>Add First Supplier
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="text-center">S.No</th>
                                            <th>Supplier Code</th>
                                            <th>Supplier Name</th>
                                            <th>Contact Person</th>
                                            <th>Contact Info</th>
                                            <th>GSTIN</th>
                                            <th>PAN No</th>
                                            <th>Address</th>
                                            <th class="text-center">Status</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="suppliersTableBody">
                                        <?php foreach ($suppliers as $index => $supplier): ?>
                                            <tr class="fade-in real-time-update" id="supplier-<?= $supplier['supplier_id'] ?>" style="animation-delay: <?= $index * 0.05 ?>s">
                                                <td class="text-center fw-bold"><?= $index + 1 ?></td>
                                                <td>
                                                    <span class="supplier-code"><?= esc(getSupplierCode($supplier)) ?></span>
                                                </td>
                                                <td>
                                                    <strong><?= esc($supplier['supplier_name']) ?></strong>
                                                </td>
                                                <td>
                                                    <?= !empty($supplier['contact_person']) ? esc($supplier['contact_person']) : '<span class="text-muted">N/A</span>' ?>
                                                </td>
                                                <td class="contact-info">
                                                    <?php if (!empty($supplier['email'])): ?>
                                                        <div><i class="bi bi-envelope me-1"></i><?= esc($supplier['email']) ?></div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($supplier['phone'])): ?>
                                                        <div><i class="bi bi-telephone me-1"></i><?= esc($supplier['phone']) ?></div>
                                                    <?php endif; ?>
                                                    <?php if (empty($supplier['email']) && empty($supplier['phone'])): ?>
                                                        <span class="text-muted">No contact info</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($supplier['gstin'])): ?>
                                                        <span class="tax-number"><?= esc($supplier['gstin']) ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">N/A</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($supplier['pan_number'])): ?>
                                                        <span class="tax-number"><?= esc($supplier['pan_number']) ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">N/A</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="address-info">
                                                    <?= !empty($supplier['address']) ? esc($supplier['address']) : '<span class="text-muted">N/A</span>' ?>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge <?= getSupplierStatus($supplier) === 'Active' ? 'badge-active' : 'badge-inactive' ?>">
                                                        <?= esc(getSupplierStatus($supplier)) ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <div class="btn-group" role="group">
                                                        <button class="btn btn-outline-primary btn-action edit-supplier" 
                                                                data-bs-toggle="modal" 
                                                                data-bs-target="#editSupplierModal"
                                                                data-id="<?= $supplier['supplier_id'] ?>"
                                                                data-name="<?= esc($supplier['supplier_name']) ?>"
                                                                data-contact="<?= esc($supplier['contact_person']) ?>"
                                                                data-email="<?= esc($supplier['email']) ?>"
                                                                data-phone="<?= esc($supplier['phone']) ?>"
                                                                data-address="<?= esc($supplier['address']) ?>"
                                                                data-gstin="<?= esc($supplier['gstin']) ?>"
                                                                data-pan="<?= esc($supplier['pan_number']) ?>">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <button class="btn btn-outline-danger btn-action delete-supplier"
                                                                data-bs-toggle="modal"
                                                                data-bs-target="#deleteSupplierModal"
                                                                data-id="<?= $supplier['supplier_id'] ?>"
                                                                data-name="<?= esc($supplier['supplier_name']) ?>">
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

    <!-- Add Supplier Modal -->
    <div class="modal fade" id="addSupplierModal" tabindex="-1" aria-labelledby="addSupplierModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="addSupplierModalLabel">
                        <i class="bi bi-plus-circle me-2"></i>Add New Supplier
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="addSupplierForm">
                    <input type="hidden" name="action" value="add_supplier">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label for="supplierName" class="form-label">Supplier Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="supplierName" name="supplier_name" 
                                       placeholder="Enter supplier name" required maxlength="255">
                            </div>
                            
                            <div class="col-md-6">
                                <label for="contactPerson" class="form-label">Contact Person</label>
                                <input type="text" class="form-control" id="contactPerson" name="contact_person" 
                                       placeholder="Enter contact person name" maxlength="100">
                            </div>
                            
                            <div class="col-md-6">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="email" name="email" 
                                       placeholder="Enter email address" maxlength="100">
                            </div>
                            
                            <div class="col-12">
                                <label for="phone" class="form-label">Phone</label>
                                <input type="tel" class="form-control" id="phone" name="phone" 
                                       placeholder="Enter phone number" maxlength="20">
                            </div>
                            
                            <div class="col-md-6">
                                <label for="gstin" class="form-label">GSTIN</label>
                                <input type="text" class="form-control" id="gstin" name="gstin" 
                                       placeholder="15-character GSTIN" maxlength="15">
                                <div class="validation-feedback text-muted small">
                                    Format: 2-digit state code + 10-digit PAN + 3-character code
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="panNumber" class="form-label">PAN Number</label>
                                <input type="text" class="form-control" id="panNumber" name="pan_number" 
                                       placeholder="10-character PAN" maxlength="10">
                                <div class="validation-feedback text-muted small">
                                    Format: 5 letters + 4 digits + 1 letter
                                </div>
                            </div>
                            
                            <div class="col-12">
                                <label for="address" class="form-label">Address</label>
                                <textarea class="form-control" id="address" name="address" 
                                          rows="3" placeholder="Enter supplier address"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="addSupplierBtn">
                            <i class="bi bi-check-circle me-2"></i>Save Supplier
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Supplier Modal -->
    <div class="modal fade" id="editSupplierModal" tabindex="-1" aria-labelledby="editSupplierModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="editSupplierModalLabel">
                        <i class="bi bi-pencil me-2"></i>Edit Supplier
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editSupplierForm">
                    <input type="hidden" name="action" value="update_supplier">
                    <input type="hidden" name="supplier_id" id="editSupplierId">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label for="editSupplierName" class="form-label">Supplier Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="editSupplierName" name="supplier_name" 
                                       placeholder="Enter supplier name" required maxlength="255">
                            </div>
                            
                            <div class="col-md-6">
                                <label for="editContactPerson" class="form-label">Contact Person</label>
                                <input type="text" class="form-control" id="editContactPerson" name="contact_person" 
                                       placeholder="Enter contact person name" maxlength="100">
                            </div>
                            
                            <div class="col-md-6">
                                <label for="editEmail" class="form-label">Email</label>
                                <input type="email" class="form-control" id="editEmail" name="email" 
                                       placeholder="Enter email address" maxlength="100">
                            </div>
                            
                            <div class="col-12">
                                <label for="editPhone" class="form-label">Phone</label>
                                <input type="tel" class="form-control" id="editPhone" name="phone" 
                                       placeholder="Enter phone number" maxlength="20">
                            </div>
                            
                            <div class="col-md-6">
                                <label for="editGstin" class="form-label">GSTIN</label>
                                <input type="text" class="form-control" id="editGstin" name="gstin" 
                                       placeholder="15-character GSTIN" maxlength="15">
                                <div class="validation-feedback text-muted small">
                                    Format: 2-digit state code + 10-digit PAN + 3-character code
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="editPanNumber" class="form-label">PAN Number</label>
                                <input type="text" class="form-control" id="editPanNumber" name="pan_number" 
                                       placeholder="10-character PAN" maxlength="10">
                                <div class="validation-feedback text-muted small">
                                    Format: 5 letters + 4 digits + 1 letter
                                </div>
                            </div>
                            
                            <div class="col-12">
                                <label for="editAddress" class="form-label">Address</label>
                                <textarea class="form-control" id="editAddress" name="address" 
                                          rows="3" placeholder="Enter supplier address"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning text-white" id="editSupplierBtn">
                            <i class="bi bi-check-circle me-2"></i>Update Supplier
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Supplier Modal -->
    <div class="modal fade" id="deleteSupplierModal" tabindex="-1" aria-labelledby="deleteSupplierModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="deleteSupplierModalLabel">
                        <i class="bi bi-exclamation-triangle me-2"></i>Confirm Delete
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="deleteSupplierForm">
                    <input type="hidden" name="action" value="delete_supplier">
                    <input type="hidden" name="supplier_id" id="deleteSupplierId">
                    <div class="modal-body">
                        <p>Are you sure you want to delete the supplier <strong id="deleteSupplierName" class="text-danger"></strong>?</p>
                        <p class="text-muted small">This action cannot be undone. Any items using this supplier will have their supplier set to NULL.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger" id="deleteSupplierBtn">
                            <i class="bi bi-trash me-2"></i>Delete Supplier
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

        // Edit Supplier Modal Handler
        document.querySelectorAll('.edit-supplier').forEach(button => {
            button.addEventListener('click', function() {
                const supplierId = this.getAttribute('data-id');
                const supplierName = this.getAttribute('data-name');
                const contactPerson = this.getAttribute('data-contact');
                const email = this.getAttribute('data-email');
                const phone = this.getAttribute('data-phone');
                const address = this.getAttribute('data-address');
                const gstin = this.getAttribute('data-gstin');
                const pan = this.getAttribute('data-pan');
                
                document.getElementById('editSupplierId').value = supplierId;
                document.getElementById('editSupplierName').value = supplierName;
                document.getElementById('editContactPerson').value = contactPerson;
                document.getElementById('editEmail').value = email;
                document.getElementById('editPhone').value = phone;
                document.getElementById('editAddress').value = address;
                document.getElementById('editGstin').value = gstin;
                document.getElementById('editPanNumber').value = pan;
            });
        });

        // Delete Supplier Modal Handler
        document.querySelectorAll('.delete-supplier').forEach(button => {
            button.addEventListener('click', function() {
                const supplierId = this.getAttribute('data-id');
                const supplierName = this.getAttribute('data-name');
                
                document.getElementById('deleteSupplierId').value = supplierId;
                document.getElementById('deleteSupplierName').textContent = supplierName;
            });
        });

        // Auto-focus on supplier name input when modal opens
        const addSupplierModal = document.getElementById('addSupplierModal');
        addSupplierModal.addEventListener('shown.bs.modal', () => {
            document.getElementById('supplierName').focus();
        });

        const editSupplierModal = document.getElementById('editSupplierModal');
        editSupplierModal.addEventListener('shown.bs.modal', () => {
            document.getElementById('editSupplierName').focus();
        });

        // Auto-dismiss alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);

        // Refresh suppliers button
        document.getElementById('refreshSuppliers')?.addEventListener('click', function() {
            this.classList.add('btn-loading');
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        });

        // Real-time form submission handling
        document.getElementById('addSupplierForm')?.addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="bi bi-arrow-repeat spinner-border spinner-border-sm me-2"></i>Saving...';
        });

        document.getElementById('editSupplierForm')?.addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="bi bi-arrow-repeat spinner-border spinner-border-sm me-2"></i>Updating...';
        });

        document.getElementById('deleteSupplierForm')?.addEventListener('submit', function(e) {
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

        // GSTIN Validation
        function validateGSTIN(gstin) {
            if (gstin === '') return true; // Empty is allowed
            
            // GSTIN format: 2 chars (state code) + 10 chars (PAN) + 1 char (entity) + 1 char (Z by default) + 1 char (check digit)
            if (gstin.length !== 15) return false;
            
            // Check if first 2 characters are state codes (basic check)
            if (!/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}[Z]{1}[0-9A-Z]{1}$/.test(gstin)) {
                return false;
            }
            
            return true;
        }

        // PAN Validation
        function validatePAN(pan) {
            if (pan === '') return true; // Empty is allowed
            
            // PAN format: 5 letters + 4 digits + 1 letter
            if (pan.length !== 10) return false;
            
            if (!/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/.test(pan)) {
                return false;
            }
            
            return true;
        }

        // Real-time validation for GSTIN
        const gstinInput = document.getElementById('gstin');
        const editGstinInput = document.getElementById('editGstin');
        
        function validateGSTINInput(input) {
            const value = input.value.toUpperCase();
            input.value = value; // Convert to uppercase
            
            if (value === '') {
                input.classList.remove('is-valid', 'is-invalid');
                return true;
            }
            
            if (validateGSTIN(value)) {
                input.classList.add('is-valid');
                input.classList.remove('is-invalid');
                return true;
            } else {
                input.classList.add('is-invalid');
                input.classList.remove('is-valid');
                return false;
            }
        }

        // Real-time validation for PAN
        const panInput = document.getElementById('panNumber');
        const editPanInput = document.getElementById('editPanNumber');
        
        function validatePANInput(input) {
            const value = input.value.toUpperCase();
            input.value = value; // Convert to uppercase
            
            if (value === '') {
                input.classList.remove('is-valid', 'is-invalid');
                return true;
            }
            
            if (validatePAN(value)) {
                input.classList.add('is-valid');
                input.classList.remove('is-invalid');
                return true;
            } else {
                input.classList.add('is-invalid');
                input.classList.remove('is-valid');
                return false;
            }
        }

        // Add event listeners for real-time validation
        if (gstinInput) {
            gstinInput.addEventListener('input', () => validateGSTINInput(gstinInput));
            gstinInput.addEventListener('blur', () => validateGSTINInput(gstinInput));
        }
        
        if (editGstinInput) {
            editGstinInput.addEventListener('input', () => validateGSTINInput(editGstinInput));
            editGstinInput.addEventListener('blur', () => validateGSTINInput(editGstinInput));
        }
        
        if (panInput) {
            panInput.addEventListener('input', () => validatePANInput(panInput));
            panInput.addEventListener('blur', () => validatePANInput(panInput));
        }
        
        if (editPanInput) {
            editPanInput.addEventListener('input', () => validatePANInput(editPanInput));
            editPanInput.addEventListener('blur', () => validatePANInput(editPanInput));
        }

        // Form validation before submission
        document.getElementById('addSupplierForm')?.addEventListener('submit', function(e) {
            const gstinValid = validateGSTINInput(gstinInput);
            const panValid = validatePANInput(panInput);
            
            if (!gstinValid || !panValid) {
                e.preventDefault();
                alert('Please fix the validation errors before submitting the form.');
            }
        });

        document.getElementById('editSupplierForm')?.addEventListener('submit', function(e) {
            const gstinValid = validateGSTINInput(editGstinInput);
            const panValid = validatePANInput(editPanInput);
            
            if (!gstinValid || !panValid) {
                e.preventDefault();
                alert('Please fix the validation errors before submitting the form.');
            }
        });

        // Clear form data when modal is closed
        const addModal = document.getElementById('addSupplierModal');
        addModal.addEventListener('hidden.bs.modal', function() {
            document.getElementById('addSupplierForm').reset();
        });

        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
</body>
</html>