<?php
/**
 * Purchase Orders Management Page - Forest Trekking System
 * Complete with PO creation and management - FIXED VERSION
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

// Safely create tables with error handling
function safeTableCreate($mysqli, $tableName, $createQuery) {
    // Check if table exists
    $checkTable = $mysqli->query("SHOW TABLES LIKE '$tableName'");
    if ($checkTable->num_rows == 0) {
        // Table doesn't exist, create it
        if (!$mysqli->query($createQuery)) {
            die("Error creating $tableName table: " . $mysqli->error);
        }
    } else {
        // Table exists, check and add missing columns
        $existingColumns = [];
        $result = $mysqli->query("DESCRIBE $tableName");
        while ($row = $result->fetch_assoc()) {
            $existingColumns[] = $row['Field'];
        }
        
        // Define required columns for each table
        $requiredColumns = [
            'purchase_orders' => ['po_id', 'po_no', 'proforma_invoice_date', 'proforma_invoice_no', 'supplier_id', 'supplier_name', 'supplier_code', 'delivery_address', 'remarks', 'status', 'total_items', 'total_quantity', 'total_amount', 'created_at', 'updated_at'],
            'po_items' => ['po_item_id', 'po_no', 'item_id', 'item_name', 'item_code', 'category_id', 'category_name', 'category_code', 'quantity', 'price_per_unit', 'total_price', 'created_at']
        ];
        
        if (isset($requiredColumns[$tableName])) {
            foreach ($requiredColumns[$tableName] as $column) {
                if (!in_array($column, $existingColumns)) {
                    // Add missing column based on table structure
                    $alterQuery = "";
                    switch ($column) {
                        case 'delivery_address':
                        case 'remarks':
                            $alterQuery = "ALTER TABLE $tableName ADD COLUMN $column TEXT";
                            break;
                        case 'total_items':
                        case 'total_quantity':
                            $alterQuery = "ALTER TABLE $tableName ADD COLUMN $column INT NOT NULL DEFAULT 0";
                            break;
                        case 'total_amount':
                            $alterQuery = "ALTER TABLE $tableName ADD COLUMN $column DECIMAL(15,2) NOT NULL DEFAULT 0.00";
                            break;
                        case 'status':
                            $alterQuery = "ALTER TABLE $tableName ADD COLUMN $status ENUM('Draft', 'Pending', 'Approved', 'Rejected', 'Completed') DEFAULT 'Draft'";
                            break;
                        case 'created_at':
                        case 'updated_at':
                            $alterQuery = "ALTER TABLE $tableName ADD COLUMN $column TIMESTAMP DEFAULT CURRENT_TIMESTAMP";
                            if ($column === 'updated_at') {
                                $alterQuery .= " ON UPDATE CURRENT_TIMESTAMP";
                            }
                            break;
                        default:
                            continue 2; // Skip to next column
                    }
                    
                    if ($alterQuery && !$mysqli->query($alterQuery)) {
                        die("Error adding column $column to $tableName: " . $mysqli->error);
                    }
                }
            }
        }
    }
}

// Create or update tables
$purchaseOrdersTable = "
CREATE TABLE purchase_orders (
    po_id INT PRIMARY KEY AUTO_INCREMENT,
    po_no VARCHAR(50) UNIQUE NOT NULL,
    proforma_invoice_date DATE NOT NULL,
    proforma_invoice_no VARCHAR(100) NOT NULL,
    supplier_id INT NOT NULL,
    supplier_name VARCHAR(255) NOT NULL,
    supplier_code VARCHAR(20) NOT NULL,
    delivery_address TEXT,
    remarks TEXT,
    status ENUM('Draft', 'Pending', 'Approved', 'Rejected', 'Completed') DEFAULT 'Draft',
    total_items INT NOT NULL DEFAULT 0,
    total_quantity INT NOT NULL DEFAULT 0,
    total_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";

$poItemsTable = "
CREATE TABLE po_items (
    po_item_id INT PRIMARY KEY AUTO_INCREMENT,
    po_no VARCHAR(50) NOT NULL,
    item_id INT NOT NULL,
    item_name VARCHAR(255) NOT NULL,
    item_code VARCHAR(20) NOT NULL,
    category_id INT,
    category_name VARCHAR(100),
    category_code VARCHAR(20),
    quantity INT NOT NULL DEFAULT 0,
    price_per_unit DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";

safeTableCreate($mysqli, 'purchase_orders', $purchaseOrdersTable);
safeTableCreate($mysqli, 'po_items', $poItemsTable);

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

function generatePONumber($supplierCode, $mysqli) {
    $date = date('ymd'); // YYMMDD format
    $baseNumber = "01POTT" . $supplierCode . $date;
    
    // Check if there are any existing POs with this pattern today
    $pattern = $baseNumber . '%';
    $stmt = $mysqli->prepare("SELECT COUNT(*) as count FROM purchase_orders WHERE po_no LIKE ?");
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
    
    return $baseNumber . str_pad($sequence, 2, '0', STR_PAD_LEFT);
}

// Function to convert number to words
function numberToWords($number) {
    $ones = array(
        0 => "",
        1 => "One",
        2 => "Two",
        3 => "Three",
        4 => "Four",
        5 => "Five",
        6 => "Six",
        7 => "Seven",
        8 => "Eight",
        9 => "Nine",
        10 => "Ten",
        11 => "Eleven",
        12 => "Twelve",
        13 => "Thirteen",
        14 => "Fourteen",
        15 => "Fifteen",
        16 => "Sixteen",
        17 => "Seventeen",
        18 => "Eighteen",
        19 => "Nineteen"
    );
    
    $tens = array(
        2 => "Twenty",
        3 => "Thirty",
        4 => "Forty",
        5 => "Fifty",
        6 => "Sixty",
        7 => "Seventy",
        8 => "Eighty",
        9 => "Ninety"
    );
    
    $number = number_format($number, 2, '.', '');
    $parts = explode('.', $number);
    $rupees = intval($parts[0]);
    $paise = intval($parts[1]);
    
    $words = "";
    
    // Convert rupees
    if ($rupees > 0) {
        if ($rupees >= 10000000) {
            $crores = floor($rupees / 10000000);
            $words .= numberToWords($crores) . " Crore ";
            $rupees %= 10000000;
        }
        
        if ($rupees >= 100000) {
            $lakhs = floor($rupees / 100000);
            $words .= numberToWords($lakhs) . " Lakh ";
            $rupees %= 100000;
        }
        
        if ($rupees >= 1000) {
            $thousands = floor($rupees / 1000);
            $words .= numberToWords($thousands) . " Thousand ";
            $rupees %= 1000;
        }
        
        if ($rupees >= 100) {
            $hundreds = floor($rupees / 100);
            $words .= numberToWords($hundreds) . " Hundred ";
            $rupees %= 100;
        }
        
        if ($rupees > 0) {
            if ($rupees < 20) {
                $words .= $ones[$rupees];
            } else {
                $words .= $tens[floor($rupees / 10)];
                if ($rupees % 10 > 0) {
                    $words .= " " . $ones[$rupees % 10];
                }
            }
        }
    } else {
        $words = "Zero";
    }
    
    $words .= " Rupees";
    
    // Convert paise
    if ($paise > 0) {
        $words .= " and ";
        if ($paise < 20) {
            $words .= $ones[$paise] . " Paise";
        } else {
            $words .= $tens[floor($paise / 10)];
            if ($paise % 10 > 0) {
                $words .= " " . $ones[$paise % 10];
            }
            $words .= " Paise";
        }
    }
    
    return ucwords(strtolower($words)) . "";
}

// --------------------------- Handle Form Actions ---------------------------
$action = $_POST['action'] ?? '';
$message = '';
$message_type = '';

// Prevent form resubmission on page refresh
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create_po') {
    $proformaInvoiceDate = $_POST['proforma_invoice_date'] ?? '';
    $proformaInvoiceNo = trim($_POST['proforma_invoice_no'] ?? '');
    $supplierId = $_POST['supplier_id'] ?? '';
    $deliveryAddress = trim($_POST['delivery_address'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');
    $items = $_POST['items'] ?? [];
    
    // Validate required fields
    if (empty($proformaInvoiceDate) || empty($proformaInvoiceNo) || empty($supplierId) || empty($items)) {
        $message = "Please fill all required fields and add at least one item!";
        $message_type = "warning";
    } else {
        // Get supplier details
        $supplier = fetchOne($mysqli, "SELECT supplier_name, supplier_code FROM suppliers WHERE supplier_id = ?", 'i', [$supplierId]);
        if (!$supplier) {
            $message = "Invalid supplier selected!";
            $message_type = "danger";
        } else {
            $supplierName = $supplier['supplier_name'];
            $supplierCode = $supplier['supplier_code'];
            
            // Generate PO number
            $poNo = generatePONumber($supplierCode, $mysqli);
            
            // Start transaction
            $mysqli->begin_transaction();
            
            try {
                $categoryCodes = [];
                $totalItems = 0;
                $totalQuantity = 0;
                $totalAmount = 0.00;
                $validItems = [];
                
                // Validate and prepare items
                foreach ($items as $item) {
                    $itemId = $item['item_id'] ?? '';
                    $quantity = intval($item['quantity'] ?? 0);
                    $pricePerUnit = floatval($item['price_per_unit'] ?? 0);
                    
                    if (!empty($itemId) && $quantity > 0 && $pricePerUnit > 0) {
                        // Get item details
                        $itemData = fetchOne($mysqli, "
                            SELECT i.item_name, i.item_code, c.category_name, c.category_code, c.category_id 
                            FROM items_data i 
                            LEFT JOIN categories c ON i.category_id = c.category_id 
                            WHERE i.item_id = ?
                        ", 'i', [$itemId]);
                        
                        if ($itemData) {
                            $totalPrice = $quantity * $pricePerUnit;
                            $validItems[] = [
                                'item_id' => $itemId,
                                'item_name' => $itemData['item_name'],
                                'item_code' => $itemData['item_code'],
                                'category_id' => $itemData['category_id'],
                                'category_name' => $itemData['category_name'],
                                'category_code' => $itemData['category_code'],
                                'quantity' => $quantity,
                                'price_per_unit' => $pricePerUnit,
                                'total_price' => $totalPrice
                            ];
                            
                            // Collect data for PO summary
                            if (!in_array($itemData['category_code'], $categoryCodes)) {
                                $categoryCodes[] = $itemData['category_code'];
                            }
                            $totalItems++;
                            $totalQuantity += $quantity;
                            $totalAmount += $totalPrice;
                        }
                    }
                }
                
                // Check if we have valid items
                if (empty($validItems)) {
                    throw new Exception("No valid items found in the purchase order.");
                }
                
                // Insert main PO record
                $stmt = $mysqli->prepare("
                    INSERT INTO purchase_orders 
                    (po_no, proforma_invoice_date, proforma_invoice_no, supplier_id, supplier_name, supplier_code, 
                     delivery_address, remarks, total_items, total_quantity, total_amount, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Draft')
                ");
                
                if (!$stmt) {
                    throw new Exception("Failed to prepare PO insert statement: " . $mysqli->error);
                }
                
                $stmt->bind_param(
                    'sssissssiid', 
                    $poNo, $proformaInvoiceDate, $proformaInvoiceNo, $supplierId, $supplierName, $supplierCode,
                    $deliveryAddress, $remarks, $totalItems, $totalQuantity, $totalAmount
                );
                
                if (!$stmt->execute()) {
                    // Check if it's a duplicate PO number error
                    if ($mysqli->errno === 1062) {
                        throw new Exception("Duplicate PO number detected. Please try again.");
                    } else {
                        throw new Exception("Failed to insert PO: " . $stmt->error);
                    }
                }
                $stmt->close();
                
                // Insert PO items
                foreach ($validItems as $item) {
                    $stmt = $mysqli->prepare("
                        INSERT INTO po_items 
                        (po_no, item_id, item_name, item_code, category_id, category_name, category_code, 
                         quantity, price_per_unit, total_price) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    if (!$stmt) {
                        throw new Exception("Failed to prepare PO item insert statement: " . $mysqli->error);
                    }
                    
                    $stmt->bind_param(
                        'sisssisiid', 
                        $poNo, $item['item_id'], $item['item_name'], $item['item_code'], 
                        $item['category_id'], $item['category_name'], $item['category_code'],
                        $item['quantity'], $item['price_per_unit'], $item['total_price']
                    );
                    
                    if (!$stmt->execute()) {
                        throw new Exception("Failed to insert PO item: " . $stmt->error);
                    }
                    $stmt->close();
                }
                
                $mysqli->commit();
                
                $message = "Purchase Order created successfully! PO Number: " . $poNo;
                $message_type = "success";
                
                // Clear form data to prevent resubmission
                $_POST = [];
                
            } catch (Exception $e) {
                $mysqli->rollback();
                $message = "Error creating purchase order: " . $e->getMessage();
                $message_type = "danger";
            }
        }
    }
}

// --------------------------- Fetch Data ---------------------------
$suppliers = fetchAll($mysqli, "SELECT * FROM suppliers WHERE status = 'Active' ORDER BY supplier_name");
$items = fetchAll($mysqli, "
    SELECT i.*, c.category_name, c.category_code 
    FROM items_data i 
    LEFT JOIN categories c ON i.category_id = c.category_id 
    WHERE i.status = 'Active'
    ORDER BY i.item_name
");

// Fetch existing POs for display with category codes
$purchaseOrders = fetchAll($mysqli, "
    SELECT po.po_id, po.po_no, po.created_at, po.supplier_name, po.supplier_code, 
           po.total_items, po.total_quantity, po.remarks, po.status, po.total_amount,
           GROUP_CONCAT(DISTINCT pi.category_code ORDER BY pi.category_code SEPARATOR ', ') as category_codes
    FROM purchase_orders po
    LEFT JOIN po_items pi ON po.po_no = pi.po_no
    GROUP BY po.po_id, po.po_no, po.created_at, po.supplier_name, po.supplier_code, 
             po.total_items, po.total_quantity, po.remarks, po.status, po.total_amount
    ORDER BY po.created_at DESC
");

// --------------------------- Utilities ---------------------------
function esc($s) { 
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); 
}

function getStatusBadgeClass($status) {
    switch ($status) {
        case 'Draft': return 'bg-secondary';
        case 'Pending': return 'bg-warning text-dark';
        case 'Approved': return 'bg-success';
        case 'Rejected': return 'bg-danger';
        case 'Completed': return 'bg-info';
        default: return 'bg-secondary';
    }
}

function formatCurrency($amount) {
    return '₹' . number_format($amount, 2);
}

function formatDate($date) {
    return date('M j, Y', strtotime($date));
}

// Function to get PO items for view modal
function getPOItems($mysqli, $poNo) {
    return fetchAll($mysqli, "
        SELECT item_name, item_code, category_name, category_code, quantity, price_per_unit, total_price
        FROM po_items 
        WHERE po_no = ?
        ORDER BY item_name
    ", 's', [$poNo]);
}

// Function to get supplier address
function getSupplierAddress($mysqli, $supplierId) {
    $supplier = fetchOne($mysqli, "SELECT address FROM suppliers WHERE supplier_id = ?", 'i', [$supplierId]);
    return $supplier ? $supplier['address'] : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Purchase Orders - Forest Trekking</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
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
        
        /* Item Entry Styles */
        .item-entry {
            background: var(--primary-light);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
            border-left: 3px solid var(--primary);
        }
        
        .item-entry:last-child {
            margin-bottom: 0;
        }
        
        .item-entry-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .remove-item {
            color: var(--danger);
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .remove-item:hover {
            transform: scale(1.2);
        }
        
        .add-item-btn {
            border: 2px dashed var(--primary);
            background: transparent;
            color: var(--primary);
            padding: 10px;
            border-radius: 8px;
            width: 100%;
            transition: all 0.2s ease;
        }
        
        .add-item-btn:hover {
            background: var(--primary-light);
            border-color: var(--primary-dark);
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
        
        /* Bill/Invoice Styles */
        .bill-header {
            border-bottom: 2px solid var(--primary);
            padding-bottom: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .bill-item {
            border-bottom: 1px solid #dee2e6;
            padding: 0.75rem 0;
        }
        
        .bill-totals {
            background: var(--primary-light);
            border-radius: 8px;
            padding: 1rem;
            margin-top: 2rem;
        }
        
        .category-badge {
            background: var(--info);
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            margin: 0.1rem;
        }
        
        /* Enhanced PDF Style Bill - COMPACT FOR SINGLE PAGE */
        .pdf-bill {
            font-family: 'Georgia', 'Times New Roman', serif;
            background: white;
            padding: 15px;
            border: 2px solid #1a1a1a;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            max-width: 100%;
            margin: 0 auto;
            position: relative;
            line-height: 1.4;
            font-size: 12px;
        }
        
        .pdf-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            border-bottom: 3px double #1a1a1a;
            padding-bottom: 15px;
        }
        
        .pdf-logo {
            max-height: 60px;
            width: auto;
        }
        
        .pdf-title {
            text-align: center;
            font-weight: bold;
            font-size: 18px;
            color: #1a1a1a;
            text-transform: uppercase;
            letter-spacing: 1px;
            line-height: 1.2;
        }
        
        .pdf-ref {
            margin-bottom: 15px;
            padding: 8px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-left: 4px solid var(--primary);
            font-size: 12px;
        }
        
        .pdf-from-address{
            margin-bottom: 8px;
            padding: 4px;
            background: #f8f9fa;
            border-radius: 6px;
            border: 1px solid #dee2e6;
            font-size: 12px;
        }
        
        .pdf-to-address {
            margin-bottom: 15px;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 6px;
            border: 1px solid #dee2e6;
            font-size: 12px;
        }
        
        .pdf-subject {
            margin-bottom: 12px;
            font-weight: bold;
            color: #1a1a1a;
            font-size: 13px;
        }
        
        .pdf-content {
            margin-bottom: 15px;
            line-height: 1.5;
            text-align: justify;
            font-size: 12px;
        }
        
        .pdf-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
            font-size: 11px;
            page-break-inside: avoid;
        }
        
        .pdf-table th {
            background: linear-gradient(135deg, #2E8B57 0%, #1f6e45 100%);
            color: white;
            font-weight: bold;
            padding: 8px 6px;
            text-align: left;
            border: 1px solid #1a1a1a;
        }
        
        .pdf-table td {
            padding: 6px 6px;
            border: 1px solid #1a1a1a;
            vertical-align: top;
        }
        
        .pdf-table tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        
        .pdf-conditions {
            margin-top: 15px;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 6px;
            border: 1px solid #dee2e6;
            font-size: 11px;
        }
        
        .pdf-conditions ol {
            margin-bottom: 10px;
            padding-left: 15px;
        }
        
        .pdf-conditions li {
            margin-bottom: 6px;
            line-height: 1.3;
        }
        
        .pdf-signature {
            margin-top: 20px;
            text-align: right;
            padding-right: 40px;
        }
        
        .pdf-watermark {
            position: absolute;
            opacity: 0.03;
            font-size: 80px;
            text-align: center;
            transform: rotate(-45deg);
            top: 40%;
            left: 25%;
            color: #1a1a1a;
            font-weight: bold;
            pointer-events: none;
        }
        
        .amount-in-words {
            background: #fff3cd;
            padding: 8px;
            border-radius: 4px;
            border-left: 4px solid #ffc107;
            margin: 10px 0;
            font-style: italic;
            font-size: 11px;
        }
        
        /* Print Styles - OPTIMIZED FOR SINGLE PAGE */
        @media print {
            @page {
                margin: 0.3cm;
                size: auto;
            }
            
            body, html {
                margin: 0 !important;
                padding: 0 !important;
                background: white !important;
                width: 100% !important;
                height: auto !important;
            }
            
            body * {
                visibility: hidden;
                background: white !important;
                color: black !important;
            }
            
            .pdf-bill, .pdf-bill * {
                visibility: visible !important;
                background: white !important;
                color: black !important;
                box-shadow: none !important;
                border: none !important;
            }
            
            .pdf-bill {
                position: absolute !important;
                top: 0 !important;
                left: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
                margin: 0 !important;
                padding: 10px !important;
                border: 2px solid #000 !important;
                page-break-inside: avoid;
                page-break-after: avoid;
                height: auto !important;
                min-height: auto !important;
                font-size: 11px !important;
                line-height: 1.3 !important;
            }
            
            .pdf-header {
                margin-bottom: 12px !important;
                padding-bottom: 10px !important;
            }
            
            .pdf-logo {
                max-height: 50px !important;
                filter: none !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            
            .pdf-title {
                font-size: 16px !important;
                line-height: 1.1 !important;
            }
            
            .pdf-table {
                font-size: 10px !important;
                margin-bottom: 12px !important;
            }
            
            .pdf-table th, .pdf-table td {
                padding: 5px 4px !important;
            }
            
            .pdf-content, .pdf-conditions {
                font-size: 10px !important;
                margin-bottom: 10px !important;
            }
            
            .pdf-signature {
                margin-top: 15px !important;
            }
            
            .pdf-watermark {
                font-size: 70px !important;
            }
            
            .no-print, .modal-footer, .modal-header {
                display: none !important;
            }
            
            .modal-dialog {
                max-width: 100% !important;
                margin: 0 !important;
                width: 100% !important;
            }
            
            .modal-content {
                border: none !important;
                box-shadow: none !important;
                height: auto !important;
                min-height: auto !important;
            }
            
            .modal-body {
                padding: 0 !important;
                margin: 0 !important;
            }
            
            /* Ensure proper printing of colors */
            .pdf-table th {
                background: #2E8B57 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            
            .amount-in-words {
                background: #fff3cd !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            
            /* Force single page */
            .pdf-bill {
                page-break-before: avoid !important;
                page-break-after: avoid !important;
                page-break-inside: avoid !important;
            }
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
        
        /* Share buttons */
        .share-buttons {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            flex-wrap: wrap;
        }
        
        .btn-whatsapp {
            background: #25D366;
            border-color: #25D366;
            color: white;
        }
        
        .btn-whatsapp:hover {
            background: #128C7E;
            border-color: #128C7E;
            color: white;
        }
        
        .btn-email {
            background: #EA4335;
            border-color: #EA4335;
            color: white;
        }
        
        .btn-email:hover {
            background: #D14836;
            border-color: #D14836;
            color: white;
        }
        
        .btn-pdf {
            background: #FF6B6B;
            border-color: #FF6B6B;
            color: white;
        }
        
        .btn-pdf:hover {
            background: #EE5A5A;
            border-color: #EE5A5A;
            color: white;
        }
        
        /* Professional government statement styling */
        .government-statement {
            font-family: 'Times New Roman', Times, serif;
            line-height: 1.4;
        }
        
        .government-header {
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        
        .government-title {
            font-size: 16px;
            font-weight: bold;
            margin: 8px 0;
        }
        
        .government-subtitle {
            font-size: 12px;
            margin: 4px 0;
        }
        
        .official-address {
            font-size: 10px;
            margin: 4px 0;
        }
        
        /* Compact styling for single page */
        .compact-mode .pdf-bill {
            padding: 12px;
            font-size: 11px;
        }
        
        .compact-mode .pdf-header {
            margin-bottom: 12px;
            padding-bottom: 12px;
        }
        
        .compact-mode .pdf-logo {
            max-height: 50px;
        }
        
        .compact-mode .pdf-title {
            font-size: 16px;
        }
        
        .compact-mode .pdf-table {
            font-size: 10px;
            margin-bottom: 12px;
        }
        
        .compact-mode .pdf-table th,
        .compact-mode .pdf-table td {
            padding: 5px 4px;
        }
        
        .compact-mode .pdf-content {
            font-size: 11px;
            margin-bottom: 12px;
        }
        
        .compact-mode .pdf-conditions {
            font-size: 10px;
            padding: 10px;
            margin-top: 12px;
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
                                    <i class="bi bi-file-bar-graph me-2"></i>Purchase Orders
                                </h2>
                                <p class="text-muted mb-0">Manage and track purchase orders</p>
                            </div>
                            <div class="col-md-6 text-md-end">
                                <button class="btn btn-primary pulse" data-bs-toggle="modal" data-bs-target="#createPOModal">
                                    <i class="bi bi-plus-circle me-2"></i>Create Purchase Order
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Statistics Cards - Only Total POs -->
            <div class="row g-3 mb-4">
                <div class="col-12 col-md-6 col-lg-3">
                    <div class="dashboard-card stat-card p-3 slide-in" style="animation-delay: 0.1s">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="fw-bold text-primary mb-1"><?= count($purchaseOrders) ?></h4>
                                <p class="text-muted mb-0 small">Total POs</p>
                            </div>
                            <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                                <i class="bi bi-file-text"></i>
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

            <!-- Purchase Orders Table -->
            <div class="row">
                <div class="col-12">
                    <div class="dashboard-card p-4 fade-in">
                        <div class="table-header-container">
                            <h5 class="fw-bold mb-0">All Purchase Orders</h5>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-primary"><?= count($purchaseOrders) ?> POs</span>
                                <button class="btn btn-outline-primary btn-sm" id="refreshPOs">
                                    <i class="bi bi-arrow-clockwise"></i>
                                </button>
                            </div>
                        </div>

                        <?php if (empty($purchaseOrders)): ?>
                            <div class="empty-state">
                                <i class="bi bi-file-text"></i>
                                <h5>No Purchase Orders Found</h5>
                                <p>Start by creating your first purchase order.</p>
                                <button class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#createPOModal">
                                    <i class="bi bi-plus-circle me-2"></i>Create First PO
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="text-center">S.No</th>
                                            <th>PO Number</th>
                                            <th>Date</th>
                                            <th>Supplier Name</th>
                                            <th>Supplier Code</th>
                                            <th>Categories</th>
                                            <th class="text-center">Total Items</th>
                                            <th class="text-center">Total Quantity</th>
                                            <th>Remarks</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="poTableBody">
                                        <?php foreach ($purchaseOrders as $index => $po): ?>
                                            <tr class="fade-in real-time-update" id="po-<?= $po['po_id'] ?>" style="animation-delay: <?= $index * 0.05 ?>s">
                                                <td class="text-center fw-bold"><?= $index + 1 ?></td>
                                                <td>
                                                    <span class="po-number"><?= esc($po['po_no']) ?></span>
                                                </td>
                                                <td>
                                                    <small class="text-muted"><?= formatDate($po['created_at']) ?></small>
                                                </td>
                                                <td>
                                                    <strong><?= esc($po['supplier_name']) ?></strong>
                                                </td>
                                                <td>
                                                    <span class="badge bg-secondary"><?= esc($po['supplier_code']) ?></span>
                                                </td>
                                                <td>
                                                    <span class="category-codes" title="<?= esc($po['category_codes']) ?>">
                                                        <?= !empty($po['category_codes']) ? esc($po['category_codes']) : '<span class="text-muted">No categories</span>' ?>
                                                    </span>
                                                </td>
                                                <td class="text-center fw-bold"><?= esc($po['total_items']) ?></td>
                                                <td class="text-center fw-bold"><?= esc($po['total_quantity']) ?></td>
                                                <td>
                                                    <span class="remarks-text" title="<?= esc($po['remarks']) ?>">
                                                        <?= !empty($po['remarks']) ? esc($po['remarks']) : '<span class="text-muted">No remarks</span>' ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <button class="btn btn-primary btn-sm btn-action view-po" 
                                                            data-po-no="<?= esc($po['po_no']) ?>" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#viewPOModal">
                                                        <i class="bi bi-eye"></i> View
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

    <!-- Create PO Modal -->
    <div class="modal fade" id="createPOModal" tabindex="-1" aria-labelledby="createPOModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="createPOModalLabel">
                        <i class="bi bi-plus-circle me-2"></i>Create Purchase Order
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="createPOForm">
                    <input type="hidden" name="action" value="create_po">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="proformaInvoiceDate" class="form-label">Proforma Invoice Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="proformaInvoiceDate" name="proforma_invoice_date" 
                                       value="<?= date('Y-m-d') ?>" required>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="proformaInvoiceNo" class="form-label">Proforma Invoice No <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="proformaInvoiceNo" name="proforma_invoice_no" 
                                       placeholder="Enter proforma invoice number" required maxlength="100">
                            </div>
                            
                            <div class="col-12">
                                <label for="supplierId" class="form-label">Supplier <span class="text-danger">*</span></label>
                                <select class="form-select" id="supplierId" name="supplier_id" required>
                                    <option value="">Select Supplier</option>
                                    <?php foreach ($suppliers as $supplier): ?>
                                        <option value="<?= $supplier['supplier_id'] ?>" data-code="<?= esc($supplier['supplier_code']) ?>">
                                            <?= esc($supplier['supplier_name']) ?> (Code: <?= esc($supplier['supplier_code']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-12">
                                <label for="deliveryAddress" class="form-label">Delivery Address</label>
                                <textarea class="form-control" id="deliveryAddress" name="delivery_address" 
                                          rows="3" placeholder="Enter delivery address"></textarea>
                            </div>
                            
                            <div class="col-12">
                                <label for="remarks" class="form-label">Remarks</label>
                                <textarea class="form-control" id="remarks" name="remarks" 
                                          rows="2" placeholder="Enter any remarks or notes"></textarea>
                            </div>
                            
                            <!-- Items Section -->
                            <div class="col-12">
                                <label class="form-label">Items <span class="text-danger">*</span></label>
                                <div id="itemEntries">
                                    <!-- Item entries will be added here dynamically -->
                                </div>
                                <button type="button" class="btn add-item-btn" id="addItemBtn">
                                    <i class="bi bi-plus-circle me-2"></i>Add Another Item
                                </button>
                            </div>
                            
                            <!-- PO Preview -->
                            <div class="col-12 mt-4">
                                <h6 class="border-bottom pb-2">PO Preview</h6>
                                <div class="row">
                                    <div class="col-md-6">
                                        <p><strong>PO Number:</strong> <span id="poPreviewNumber" class="text-muted">Will be generated after supplier selection</span></p>
                                        <p><strong>Total Items:</strong> <span id="poPreviewItems" class="fw-bold">0</span></p>
                                    </div>
                                    <div class="col-md-6">
                                        <p><strong>Total Quantity:</strong> <span id="poPreviewQuantity" class="fw-bold">0</span></p>
                                        <p><strong>Total Amount:</strong> <span id="poPreviewTotal" class="text-success fw-bold">₹0.00</span></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="createPOBtn">
                            <i class="bi bi-check-circle me-2"></i>Create Purchase Order
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View PO Modal -->
    <div class="modal fade" id="viewPOModal" tabindex="-1" aria-labelledby="viewPOModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="viewPOModalLabel">
                        <i class="bi bi-eye me-2"></i>Purchase Order Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="viewPOModalBody">
                    <!-- PO details will be loaded here dynamically -->
                </div>
                <div class="modal-footer no-print">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="window.print()">
                        <i class="bi bi-printer me-2"></i>Print
                    </button>
                    <button type="button" class="btn btn-pdf" id="downloadPDF">
                        <i class="bi bi-file-pdf me-2"></i>Download PDF
                    </button>
                    <button type="button" class="btn btn-whatsapp" id="shareWhatsApp">
                        <i class="bi bi-whatsapp me-2"></i>Share PDF
                    </button>
                    <button type="button" class="btn btn-email" id="shareEmail">
                        <i class="bi bi-envelope me-2"></i>Email PDF
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

        // Item management for PO creation
        let itemCount = 0;
        const itemEntries = document.getElementById('itemEntries');
        const addItemBtn = document.getElementById('addItemBtn');
        const supplierSelect = document.getElementById('supplierId');
        const poPreviewNumber = document.getElementById('poPreviewNumber');
        const poPreviewItems = document.getElementById('poPreviewItems');
        const poPreviewQuantity = document.getElementById('poPreviewQuantity');
        const poPreviewTotal = document.getElementById('poPreviewTotal');

        // Track used item IDs to prevent duplicates
        let usedItemIds = new Set();

        function createItemEntry() {
            itemCount++;
            const entryId = `item_${itemCount}`;
            
            const itemEntry = document.createElement('div');
            itemEntry.className = 'item-entry';
            itemEntry.id = entryId;
            
            itemEntry.innerHTML = `
                <div class="item-entry-header">
                    <h6 class="mb-0">Item ${itemCount}</h6>
                    ${itemCount > 1 ? '<button type="button" class="remove-item" onclick="removeItemEntry(\'' + entryId + '\')">&times;</button>' : ''}
                </div>
                <div class="row g-2">
                    <div class="col-md-5">
                        <label class="form-label">Item <span class="text-danger">*</span></label>
                        <select class="form-select item-select" name="items[${itemCount}][item_id]" required onchange="updateCategoryInfo(this, ${itemCount})">
                            <option value="">Select Item</option>
                            <?php foreach ($items as $item): ?>
                                <option value="<?= $item['item_id'] ?>" 
                                        data-category-name="<?= esc($item['category_name']) ?>"
                                        data-category-code="<?= esc($item['category_code']) ?>">
                                    <?= esc($item['item_name']) ?> (Code: <?= esc($item['item_code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Category</label>
                        <input type="text" class="form-control category-display" id="category_${itemCount}" readonly>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Quantity <span class="text-danger">*</span></label>
                        <input type="number" class="form-control quantity-input" name="items[${itemCount}][quantity]" 
                               min="1" value="1" required onchange="calculateTotal(this, ${itemCount})">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Price/Unit <span class="text-danger">*</span></label>
                        <input type="number" class="form-control price-input" name="items[${itemCount}][price_per_unit]" 
                               step="0.01" min="0.01" value="0.00" required onchange="calculateTotal(this, ${itemCount})">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Total Price</label>
                        <input type="text" class="form-control total-display" id="total_${itemCount}" readonly>
                    </div>
                </div>
            `;
            
            itemEntries.appendChild(itemEntry);
            
            // Add event listener to prevent duplicate items
            const itemSelect = itemEntry.querySelector('.item-select');
            itemSelect.addEventListener('change', function() {
                validateItemSelection(this);
            });
            
            return itemCount;
        }

        function validateItemSelection(selectElement) {
            const selectedValue = selectElement.value;
            if (!selectedValue) return true;
            
            // Clear previous selection from used items
            const currentEntry = selectElement.closest('.item-entry');
            const currentIndex = getItemIndex(currentEntry);
            
            // Remove all entries for this item except the current one
            usedItemIds.forEach(itemId => {
                const entries = document.querySelectorAll('.item-select');
                entries.forEach(entry => {
                    if (entry !== selectElement && entry.value === selectedValue) {
                        // This is a duplicate, clear it
                        entry.value = '';
                        const entryIndex = getItemIndex(entry.closest('.item-entry'));
                        updateCategoryInfo(entry, entryIndex);
                    }
                });
            });
            
            // Update used items set
            updateUsedItemsSet();
            return true;
        }

        function updateUsedItemsSet() {
            usedItemIds.clear();
            const allSelects = document.querySelectorAll('.item-select');
            allSelects.forEach(select => {
                if (select.value) {
                    usedItemIds.add(select.value);
                }
            });
        }

        function getItemIndex(entryElement) {
            const id = entryElement.id;
            return parseInt(id.replace('item_', ''));
        }

        function removeItemEntry(entryId) {
            const entry = document.getElementById(entryId);
            if (entry) {
                // Remove item ID from used items
                const itemSelect = entry.querySelector('.item-select');
                if (itemSelect && itemSelect.value) {
                    usedItemIds.delete(itemSelect.value);
                }
                
                entry.remove();
                // Renumber remaining items
                const entries = itemEntries.getElementsByClassName('item-entry');
                for (let i = 0; i < entries.length; i++) {
                    const header = entries[i].querySelector('h6');
                    header.textContent = `Item ${i + 1}`;
                    
                    // Update all inputs with new index
                    const inputs = entries[i].querySelectorAll('input, select');
                    inputs.forEach(input => {
                        const name = input.getAttribute('name');
                        if (name) {
                            input.setAttribute('name', name.replace(/items\[\d+\]/, `items[${i + 1}]`));
                        }
                    });
                    
                    // Update IDs and event handlers
                    entries[i].id = `item_${i + 1}`;
                    const removeBtn = entries[i].querySelector('.remove-item');
                    if (removeBtn) {
                        removeBtn.setAttribute('onclick', `removeItemEntry('item_${i + 1}')`);
                    }
                    
                    // Update category display ID
                    const categoryDisplay = entries[i].querySelector('.category-display');
                    if (categoryDisplay) {
                        categoryDisplay.id = `category_${i + 1}`;
                    }
                    
                    // Update total display ID
                    const totalDisplay = entries[i].querySelector('.total-display');
                    if (totalDisplay) {
                        totalDisplay.id = `total_${i + 1}`;
                    }
                    
                    // Update event handlers for inputs
                    const quantityInput = entries[i].querySelector('.quantity-input');
                    const priceInput = entries[i].querySelector('.price-input');
                    const itemSelect = entries[i].querySelector('.item-select');
                    
                    if (quantityInput) {
                        quantityInput.setAttribute('onchange', `calculateTotal(this, ${i + 1})`);
                    }
                    if (priceInput) {
                        priceInput.setAttribute('onchange', `calculateTotal(this, ${i + 1})`);
                    }
                    if (itemSelect) {
                        itemSelect.setAttribute('onchange', `updateCategoryInfo(this, ${i + 1})`);
                    }
                }
                
                itemCount = entries.length;
                updatePOTotal();
                updateUsedItemsSet(); // Update the set after removal
            }
        }

        function updateCategoryInfo(selectElement, index) {
            const selectedOption = selectElement.options[selectElement.selectedIndex];
            const categoryName = selectedOption.getAttribute('data-category-name');
            const categoryCode = selectedOption.getAttribute('data-category-code');
            
            const categoryDisplay = document.getElementById(`category_${index}`);
            if (categoryDisplay) {
                categoryDisplay.value = categoryName ? `${categoryName} (${categoryCode})` : '';
            }
            
            updateUsedItemsSet(); // Update the set after selection change
            calculateTotal(selectElement, index);
        }

        function calculateTotal(inputElement, index) {
            const entry = document.getElementById(`item_${index}`);
            if (!entry) return;
            
            const quantityInput = entry.querySelector('.quantity-input');
            const priceInput = entry.querySelector('.price-input');
            
            const quantity = parseFloat(quantityInput?.value) || 0;
            const price = parseFloat(priceInput?.value) || 0;
            const total = quantity * price;
            
            const totalDisplay = document.getElementById(`total_${index}`);
            if (totalDisplay) {
                totalDisplay.value = '₹' + total.toFixed(2);
            }
            
            updatePOTotal();
        }

        function updatePOTotal() {
            let totalItems = 0;
            let totalQuantity = 0;
            let totalAmount = 0;
            
            const entries = itemEntries.getElementsByClassName('item-entry');
            
            for (let i = 0; i < entries.length; i++) {
                const quantityInput = entries[i].querySelector('.quantity-input');
                const priceInput = entries[i].querySelector('.price-input');
                const itemSelect = entries[i].querySelector('.item-select');
                
                if (itemSelect && itemSelect.value) {
                    totalItems++;
                }
                
                const quantity = parseFloat(quantityInput?.value) || 0;
                const price = parseFloat(priceInput?.value) || 0;
                
                totalQuantity += quantity;
                totalAmount += quantity * price;
            }
            
            poPreviewItems.textContent = totalItems;
            poPreviewQuantity.textContent = totalQuantity;
            poPreviewTotal.textContent = '₹' + totalAmount.toFixed(2);
        }

        // Update PO number preview when supplier changes
        supplierSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const supplierCode = selectedOption.getAttribute('data-code');
            
            if (supplierCode) {
                const date = new Date();
                const year = date.getFullYear().toString().slice(-2);
                const month = (date.getMonth() + 1).toString().padStart(2, '0');
                const day = date.getDate().toString().padStart(2, '0');
                
                poPreviewNumber.textContent = `01POTT${supplierCode}${year}${month}${day}01`;
            } else {
                poPreviewNumber.textContent = 'Will be generated after supplier selection';
            }
        });

        // Initialize with one item entry
        document.addEventListener('DOMContentLoaded', function() {
            itemCount = createItemEntry();
            updateUsedItemsSet(); // Initialize the used items set
        });

        addItemBtn.addEventListener('click', function() {
            itemCount = createItemEntry();
            updateUsedItemsSet(); // Update the set after adding new item
        });

        // Auto-focus on first input when modal opens
        const createPOModal = document.getElementById('createPOModal');
        createPOModal.addEventListener('shown.bs.modal', () => {
            document.getElementById('proformaInvoiceNo').focus();
        });

        // Auto-dismiss alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);

        // Refresh POs button
        document.getElementById('refreshPOs')?.addEventListener('click', function() {
            this.classList.add('btn-loading');
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        });

        // Real-time form submission handling
        document.getElementById('createPOForm')?.addEventListener('submit', function(e) {
            // Validate at least one item is added
            const entries = itemEntries.getElementsByClassName('item-entry');
            let hasValidItems = false;
            
            for (let i = 0; i < entries.length; i++) {
                const itemSelect = entries[i].querySelector('.item-select');
                const quantityInput = entries[i].querySelector('.quantity-input');
                const priceInput = entries[i].querySelector('.price-input');
                
                if (itemSelect.value && quantityInput.value > 0 && priceInput.value > 0) {
                    hasValidItems = true;
                    break;
                }
            }
            
            if (!hasValidItems) {
                e.preventDefault();
                alert('Please add at least one valid item with quantity and price.');
                return;
            }
            
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="bi bi-arrow-repeat spinner-border spinner-border-sm me-2"></i>Creating...';
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

        // Reset everything when modal is closed
        createPOModal.addEventListener('hidden.bs.modal', function() {
            itemEntries.innerHTML = '';
            itemCount = 0;
            usedItemIds.clear();
            itemCount = createItemEntry();
            poPreviewNumber.textContent = 'Will be generated after supplier selection';
            poPreviewItems.textContent = '0';
            poPreviewQuantity.textContent = '0';
            poPreviewTotal.textContent = '₹0.00';
            
            // Reset form
            document.getElementById('createPOForm').reset();
            document.getElementById('proformaInvoiceDate').value = '<?= date('Y-m-d') ?>';
            updateUsedItemsSet(); // Reset the used items set
        });

        // View PO functionality
        document.querySelectorAll('.view-po').forEach(button => {
            button.addEventListener('click', function() {
                const poNo = this.getAttribute('data-po-no');
                loadPODetails(poNo);
            });
        });

        function loadPODetails(poNo) {
            // Show loading state
            document.getElementById('viewPOModalBody').innerHTML = `
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2">Loading PO details...</p>
                </div>
            `;

            // Fetch PO details via AJAX
            fetch(`get_po_details.php?po_no=${encodeURIComponent(poNo)}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.error) {
                        document.getElementById('viewPOModalBody').innerHTML = `
                            <div class="alert alert-danger">
                                <i class="bi bi-exclamation-triangle me-2"></i>${data.error}
                            </div>
                        `;
                    } else {
                        displayPODetails(data);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('viewPOModalBody').innerHTML = `
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle me-2"></i>Failed to load PO details. Please try again.
                        </div>
                    `;
                });
        }

        function displayPODetails(data) {
            const { po, items, supplier_address } = data;
            
            // Format date for display
            const poDate = new Date(po.created_at);
            const formattedDate = `${poDate.getDate().toString().padStart(2, '0')}.${(poDate.getMonth() + 1).toString().padStart(2, '0')}.${poDate.getFullYear()}`;
            
            const proformaDate = new Date(po.proforma_invoice_date);
            const formattedProformaDate = `${proformaDate.getDate().toString().padStart(2, '0')}.${(proformaDate.getMonth() + 1).toString().padStart(2, '0')}.${proformaDate.getFullYear()}`;
            
            // Get item names for subject line
            const itemNames = items.map(item => item.item_name).join(', ');
            
            // Convert total amount to words
            const amountInWords = numberToWords(parseFloat(po.total_amount));
            
            let itemsHtml = '';
            let totalAmount = 0;
            
            items.forEach(item => {
                itemsHtml += `
                    <tr>
                        <td>${item.item_name}</td>
                        <td>${item.quantity}</td>
                        <td>₹${parseFloat(item.price_per_unit).toFixed(2)}</td>
                        <td>${po.delivery_address || 'Not specified'}</td>
                    </tr>
                `;
                totalAmount += parseFloat(item.total_price);
            });

            const html = `
                <div class="pdf-bill government-statement compact-mode" id="pdfContent">
                    <div class="pdf-watermark">TNWEC</div>
                    
                    <div class="pdf-header">
                        <div>
                            <img src="./images/Tn Logo.png" alt="Tamil Nadu Government Logo" class="pdf-logo" style="filter: brightness(1);">
                        </div>
                        <div class="pdf-title government-title">
                            TAMILNADU WILDERNESS EXPERIENCES CORPORATION<br>
                            <span class="government-subtitle">(A Government of Tamil Nadu Undertaking)</span><br>
                            <span class="official-address">6th Floor, Panagal Maaligai, Jeenis Road, Saidapet, Chennai-600 015</span>
                        </div>
                        <div>
                            <img src="./images/Trek Logo.png" alt="Trek Tamil Nadu Logo" class="pdf-logo">
                        </div>
                    </div>
                    <div class="pdf-ref">
                        <strong>Ref. No.:</strong> ${po.po_no}<br>
                        <strong>Date:</strong> ${formattedDate}
                    </div>
                    <div class="pdf-from-address">
                    <h8><strong>From:</strong></h6>
                    <h6>Thiru. Vismiju Viswanathan, I.F.S.</h6>
                    <h6>Managing Director.</h6>
                    </div>
                    <div class="pdf-to-address">
                        <h6><strong>To:</strong></h6>
                        ${po.supplier_name}
                        <br>${supplier_address || 'Address not specified'}
                    </div>

                    <div class="pdf-subject">
                        <strong>Subject:</strong> Purchase Order for supply of ${itemNames} for Trek Tamil Nadu Project
                    </div>

                    <div class="pdf-subject">
                        <strong>Reference:</strong> Your Proforma Invoice No. ${po.proforma_invoice_no} 
                        dated: ${formattedProformaDate}
                    </div>

                    <div class="pdf-content">
                        <p>
                            This is to inform that your quotation for supplying ${itemNames} for Trek Tamil Nadu Project 
                            has been accepted for Rs. ${parseFloat(po.total_amount).toFixed(2)}/- (${amountInWords}) including GST.
                        </p>
                        <p>
                            You are requested to supply the following items as per your quotation and complete 
                            the delivery immediately, subject to the conditions mentioned below:
                        </p>
                    </div>

                    <table class="pdf-table">
                        <thead>
                            <tr>
                                <th>Description of Items</th>
                                <th>Quantity</th>
                                <th>Unit Price (₹)</th>
                                <th>Delivery Address</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${itemsHtml}
                        </tbody>
                    </table>
                     <div class="bill-totals mt-4">
                        <div class="row">
                            <div class="col-md-4">
                                <p class="mb-1"><strong>Total Items:</strong> ${po.total_items}</p>
                            </div>
                            <div class="col-md-4">
                                <p class="mb-1"><strong>Total Quantity:</strong> ${po.total_quantity}</p>
                            </div>
                            <div class="col-md-4">
                                <p class="mb-1"><strong>Total Amount:</strong> ₹${parseFloat(po.total_amount).toFixed(2)}</p>
                            </div>
                        </div>
                    </div>
                    <div class="pdf-conditions">
                        <strong>Terms and Conditions:</strong>
                        <ol>
                            <li>Payment will be made through electronic mode only. Please submit your company bank account details along with the invoice after completion of delivery.</li>
                            <li>All invoices must be addressed to "Tamilnadu Wilderness Experiences Corporation, 6th Floor, Panagal Maaligai, Jeenis Road, Saidapet, Chennai-600 015" with GST No. 33AAICT4806C1ZO.</li>
                            <li>50% advance payment will be processed based on your Proforma Invoice.</li>
                            <li>Final payment will be processed after successful delivery of goods and submission of Tax Invoice, subject to applicable tax deductions.</li>
                            <li>Goods must be delivered in perfect condition and as per the specifications mentioned in your quotation.</li>
                            <li>Please return the duplicate copy of this purchase order duly signed as acceptance of the order and terms.</li>
                        </ol>
                    </div>

                    <div class="pdf-signature">
                        <strong>Managing Director</strong><br>
                        <strong>TNWEC</strong><br>
                        <strong>Chennai</strong>
                    </div>
                </div>
            `;

            document.getElementById('viewPOModalBody').innerHTML = html;
            
            // Set up share buttons
            setupShareButtons(po, items, supplier_address);
        }

        function setupShareButtons(po, items, supplierAddress) {
            // PDF Download
            document.getElementById('downloadPDF').addEventListener('click', function() {
                generateAndDownloadPDF(po);
            });

            // WhatsApp Share
            document.getElementById('shareWhatsApp').addEventListener('click', function() {
                shareViaWhatsApp(po);
            });

            // Email Share
            document.getElementById('shareEmail').addEventListener('click', function() {
                shareViaEmail(po);
            });
        }

        function generateAndDownloadPDF(po) {
            const element = document.getElementById('pdfContent');
            const options = {
                margin: [0.3, 0.3, 0.3, 0.3],
                filename: `Purchase_Order_${po.po_no}.pdf`,
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { 
                    scale: 2, 
                    useCORS: true,
                    logging: false,
                    letterRendering: true
                },
                jsPDF: { 
                    unit: 'cm', 
                    format: 'a4', 
                    orientation: 'portrait',
                    compress: true
                },
                pagebreak: { mode: ['avoid-all', 'css', 'legacy'] }
            };

            // Show loading state
            const originalText = document.getElementById('downloadPDF').innerHTML;
            document.getElementById('downloadPDF').innerHTML = '<i class="bi bi-arrow-repeat spinner-border spinner-border-sm me-2"></i>Generating...';
            document.getElementById('downloadPDF').disabled = true;

            html2pdf().set(options).from(element).save().then(() => {
                // Restore button state
                document.getElementById('downloadPDF').innerHTML = originalText;
                document.getElementById('downloadPDF').disabled = false;
            }).catch(error => {
                console.error('PDF generation error:', error);
                alert('Error generating PDF. Please try again.');
                document.getElementById('downloadPDF').innerHTML = originalText;
                document.getElementById('downloadPDF').disabled = false;
            });
        }

        function shareViaWhatsApp(po) {
            const itemNames = po.items ? po.items.map(item => item.item_name).join(', ') : 'Items';
            const message = `Purchase Order: ${po.po_no}\nSupplier: ${po.supplier_name}\nItems: ${itemNames}\nTotal Amount: ₹${parseFloat(po.total_amount).toFixed(2)}\nDate: ${new Date(po.created_at).toLocaleDateString()}\n\nPlease check the attached purchase order document.`;
            
            const encodedMessage = encodeURIComponent(message);
            window.open(`https://web.whatsapp.com/send?text=${encodedMessage}`, '_blank');
        }

        function shareViaEmail(po) {
            const itemNames = po.items ? po.items.map(item => item.item_name).join(', ') : 'Items';
            const subject = `Purchase Order: ${po.po_no} - Tamilnadu Wilderness Experiences Corporation`;
            const body = `Dear Supplier,\n\nPlease find below the purchase order details:\n\nPO Number: ${po.po_no}\nSupplier: ${po.supplier_name}\nItems: ${itemNames}\nTotal Amount: ₹${parseFloat(po.total_amount).toFixed(2)}\nDate: ${new Date(po.created_at).toLocaleDateString()}\n\nPlease proceed with the delivery as per the terms and conditions mentioned in the attached purchase order.\n\nRegards,\nTamilnadu Wilderness Experiences Corporation`;
            
            const encodedSubject = encodeURIComponent(subject);
            const encodedBody = encodeURIComponent(body);
            window.open(`mailto:?subject=${encodedSubject}&body=${encodedBody}`, '_blank');
        }

        // Function to convert number to words
        function numberToWords(num) {
            const ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 
                         'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
            const tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
            
            if (num === 0) return 'Zero Rupees';
            
            let words = '';
            
            // Handle rupees part
            let rupees = Math.floor(num);
            let paise = Math.round((num - rupees) * 100);
            
            if (rupees > 0) {
                if (rupees >= 10000000) {
                    words += numberToWords(Math.floor(rupees / 10000000)) + ' Crore ';
                    rupees %= 10000000;
                }
                
                if (rupees >= 100000) {
                    words += numberToWords(Math.floor(rupees / 100000)) + ' Lakh ';
                    rupees %= 100000;
                }
                
                if (rupees >= 1000) {
                    words += numberToWords(Math.floor(rupees / 1000)) + ' Thousand ';
                    rupees %= 1000;
                }
                
                if (rupees >= 100) {
                    words += numberToWords(Math.floor(rupees / 100)) + ' Hundred ';
                    rupees %= 100;
                }
                
                if (rupees > 0) {
                    if (rupees < 20) {
                        words += ones[rupees] + ' ';
                    } else {
                        words += tens[Math.floor(rupees / 10)] + ' ';
                        if (rupees % 10 > 0) {
                            words += ones[rupees % 10] + ' ';
                        }
                    }
                }
                
                words += 'Rupees';
            }
            
            // Handle paise part
            if (paise > 0) {
                if (words !== '') words += ' and ';
                if (paise < 20) {
                    words += ones[paise] + ' Paise';
                } else {
                    words += tens[Math.floor(paise / 10)] + ' ';
                    if (paise % 10 > 0) {
                        words += ones[paise % 10] + ' ';
                    }
                    words += 'Paise';
                }
            }
            
            return words + ' Only';
        }

        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }

        // Enhanced print functionality
        function setupPrintFunctionality() {
            const printBtn = document.querySelector('button[onclick="window.print()"]');
            if (printBtn) {
                printBtn.addEventListener('click', function() {
                    // Small delay to ensure modal content is ready
                    setTimeout(() => {
                        window.print();
                    }, 100);
                });
            }
        }

        // Initialize print functionality when modal opens
        const viewPOModal = document.getElementById('viewPOModal');
        if (viewPOModal) {
            viewPOModal.addEventListener('shown.bs.modal', function() {
                setupPrintFunctionality();
            });
        }
    </script>
</body>
</html>