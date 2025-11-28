<?php
/**
 * Stock Transfer Management - Forest Trekking System
 * Complete with real-time database integration and security features
 * FIXED: Available quantity display, AJAX endpoints, stock validation, and data synchronization
 */

// --------------------------- Configuration & Security ---------------------------
session_start();
$dbHost = '127.0.0.1';
$dbUser = 'root';
$dbPass = '';
$dbName = 'Trek_Tamilnadu_Testing_db';

// Create DB connection with error handling
try {
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    if ($mysqli->connect_errno) {
        throw new Exception("Failed to connect to MySQL: " . $mysqli->connect_error);
    }
    $mysqli->set_charset('utf8mb4');
    $mysqli->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (Exception $e) {
    error_log("Database connection error: " . $e->getMessage());
    die("System temporarily unavailable. Please try again later.");
}

// CSRF Protection
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// --------------------------- Helper Functions ---------------------------
function generateTransferCode($mysqli) {
    $stmt = $mysqli->prepare("SELECT COUNT(*) as count FROM stock_transfers WHERE DATE(created_at) = CURDATE()");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $dailyCount = $row['count'] + 1;
        $stmt->close();
    } else {
        $dailyCount = 1;
    }
    
    $dateCode = date('Ymd');
    return 'TRF-' . $dateCode . '-' . str_pad($dailyCount, 3, '0', STR_PAD_LEFT);
}

function fetchAll($mysqli, $sql, $types = null, $params = []) {
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        error_log("Failed to prepare query: " . $sql);
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

function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function sanitizeInput($data) {
    return htmlspecialchars(trim($data ?? ''), ENT_QUOTES, 'UTF-8');
}

function validateQuantity($quantity) {
    $quantity = intval($quantity);
    return $quantity > 0 && $quantity <= 10000; // Reasonable limits
}

function getTrailStockSummary($mysqli, $trailId) {
    $inventory = fetchAll($mysqli, "
        SELECT i.item_name, ti.current_stock 
        FROM trail_inventory ti 
        JOIN items_data i ON ti.item_id = i.item_id 
        WHERE ti.trail_id = ? AND ti.current_stock > 0
        ORDER BY ti.current_stock DESC 
        LIMIT 5
    ", 'i', [$trailId]);
    
    $summary = [];
    $totalItems = 0;
    foreach ($inventory as $item) {
        $summary[] = $item['item_name'] . ' (' . $item['current_stock'] . ')';
        $totalItems += $item['current_stock'];
    }
    
    return [
        'total_items' => $totalItems,
        'summary' => implode(', ', $summary)
    ];
}

function getItemStockAtTrail($mysqli, $itemId, $trailId) {
    $stock = fetchOne($mysqli, "
        SELECT current_stock 
        FROM trail_inventory 
        WHERE item_id = ? AND trail_id = ?
    ", 'ii', [$itemId, $trailId]);
    
    return $stock ? $stock['current_stock'] : 0;
}

// Update stock quantities when transfer is completed
function updateStockQuantities($mysqli, $transferId, $status) {
    // Only process stock updates for Completed status
    if ($status !== 'Completed') {
        return true;
    }
    
    // Get transfer details
    $transfer = fetchOne($mysqli, "
        SELECT * FROM stock_transfers WHERE transfer_id = ?
    ", 'i', [$transferId]);
    
    if (!$transfer) {
        return false;
    }
    
    // Get transfer items
    $transferItems = fetchAll($mysqli, "
        SELECT * FROM transfer_items WHERE transfer_id = ?
    ", 'i', [$transferId]);
    
    $mysqli->begin_transaction();
    
    try {
        foreach ($transferItems as $item) {
            $itemId = $item['item_id'];
            $quantity = $item['requested_quantity'];
            $fromTrailId = $transfer['from_trail_id'];
            $toTrailId = $transfer['to_trail_id'];
            
            // Check source trail has enough stock
            $sourceStock = getItemStockAtTrail($mysqli, $itemId, $fromTrailId);
            if ($sourceStock < $quantity) {
                throw new Exception("Insufficient stock for item: " . $item['item_name'] . 
                                  " (Available: $sourceStock, Requested: $quantity)");
            }
            
            // Update source trail inventory (deduct)
            $updateSourceStmt = $mysqli->prepare("
                UPDATE trail_inventory 
                SET current_stock = current_stock - ? 
                WHERE item_id = ? AND trail_id = ? AND current_stock >= ?
            ");
            $updateSourceStmt->bind_param('iiii', $quantity, $itemId, $fromTrailId, $quantity);
            
            if (!$updateSourceStmt->execute() || $updateSourceStmt->affected_rows === 0) {
                throw new Exception("Failed to update source stock for item: " . $item['item_name']);
            }
            $updateSourceStmt->close();
            
            // Update destination trail inventory (add)
            $updateDestStmt = $mysqli->prepare("
                INSERT INTO trail_inventory (trail_id, item_id, current_stock) 
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE current_stock = current_stock + VALUES(current_stock)
            ");
            $updateDestStmt->bind_param('iii', $toTrailId, $itemId, $quantity);
            
            if (!$updateDestStmt->execute()) {
                throw new Exception("Failed to update destination stock for item: " . $item['item_name']);
            }
            $updateDestStmt->close();
            
            // Update transferred quantity in transfer_items
            $updateTransferItemStmt = $mysqli->prepare("
                UPDATE transfer_items 
                SET transferred_quantity = ? 
                WHERE transfer_item_id = ?
            ");
            $updateTransferItemStmt->bind_param('ii', $quantity, $item['transfer_item_id']);
            $updateTransferItemStmt->execute();
            $updateTransferItemStmt->close();
            
            // Log the stock movement
            $logStmt = $mysqli->prepare("
                INSERT INTO stock_movements (item_id, from_trail_id, to_trail_id, quantity, movement_type, reference_id, created_by) 
                VALUES (?, ?, ?, ?, 'TRANSFER_OUT', ?, ?)
            ");
            $createdBy = $_SESSION['username'] ?? 'System';
            $logStmt->bind_param('iiiiis', $itemId, $fromTrailId, $toTrailId, $quantity, $transferId, $createdBy);
            $logStmt->execute();
            $logStmt->close();
        }
        
        $mysqli->commit();
        return true;
        
    } catch (Exception $e) {
        $mysqli->rollback();
        error_log("Stock update error: " . $e->getMessage());
        return false;
    }
}

// Reverse stock quantities when transfer is cancelled or returned
function reverseStockQuantities($mysqli, $transferId, $oldStatus, $newStatus) {
    // Only reverse if moving from Completed to Cancelled/Returned
    if ($oldStatus !== 'Completed' || ($newStatus !== 'Cancelled' && $newStatus !== 'Returned')) {
        return true;
    }
    
    // Get transfer details
    $transfer = fetchOne($mysqli, "
        SELECT * FROM stock_transfers WHERE transfer_id = ?
    ", 'i', [$transferId]);
    
    if (!$transfer) {
        return false;
    }
    
    // Get transfer items with transferred quantities
    $transferItems = fetchAll($mysqli, "
        SELECT * FROM transfer_items WHERE transfer_id = ? AND transferred_quantity > 0
    ", 'i', [$transferId]);
    
    $mysqli->begin_transaction();
    
    try {
        foreach ($transferItems as $item) {
            $itemId = $item['item_id'];
            $quantity = $item['transferred_quantity'];
            $fromTrailId = $transfer['from_trail_id'];
            $toTrailId = $transfer['to_trail_id'];
            
            // Return stock to source trail
            $updateSourceStmt = $mysqli->prepare("
                UPDATE trail_inventory 
                SET current_stock = current_stock + ? 
                WHERE item_id = ? AND trail_id = ?
            ");
            $updateSourceStmt->bind_param('iii', $quantity, $itemId, $fromTrailId);
            
            if (!$updateSourceStmt->execute()) {
                throw new Exception("Failed to return stock to source for item: " . $item['item_name']);
            }
            $updateSourceStmt->close();
            
            // Remove stock from destination trail
            $updateDestStmt = $mysqli->prepare("
                UPDATE trail_inventory 
                SET current_stock = current_stock - ? 
                WHERE item_id = ? AND trail_id = ? AND current_stock >= ?
            ");
            $updateDestStmt->bind_param('iiii', $quantity, $itemId, $toTrailId, $quantity);
            
            if (!$updateDestStmt->execute() || $updateDestStmt->affected_rows === 0) {
                throw new Exception("Failed to remove stock from destination for item: " . $item['item_name']);
            }
            $updateDestStmt->close();
            
            // Reset transferred quantity
            $resetTransferItemStmt = $mysqli->prepare("
                UPDATE transfer_items 
                SET transferred_quantity = 0 
                WHERE transfer_item_id = ?
            ");
            $resetTransferItemStmt->bind_param('i', $item['transfer_item_id']);
            $resetTransferItemStmt->execute();
            $resetTransferItemStmt->close();
            
            // Log the reversal
            $logStmt = $mysqli->prepare("
                INSERT INTO stock_movements (item_id, from_trail_id, to_trail_id, quantity, movement_type, reference_id, notes, created_by) 
                VALUES (?, ?, ?, ?, 'TRANSFER_REVERSAL', ?, ?, ?)
            ");
            $createdBy = $_SESSION['username'] ?? 'System';
            $notes = "Transfer " . $transfer['transfer_code'] . " " . $newStatus;
            $logStmt->bind_param('iiiiiss', $itemId, $toTrailId, $fromTrailId, $quantity, $transferId, $notes, $createdBy);
            $logStmt->execute();
            $logStmt->close();
        }
        
        $mysqli->commit();
        return true;
        
    } catch (Exception $e) {
        $mysqli->rollback();
        error_log("Stock reversal error: " . $e->getMessage());
        return false;
    }
}

// Handle AJAX requests
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    if ($_GET['ajax'] === 'get_item_stock') {
        $itemId = intval($_GET['item_id'] ?? 0);
        $trailId = intval($_GET['trail_id'] ?? 0);
        
        if ($itemId && $trailId) {
            $stock = getItemStockAtTrail($mysqli, $itemId, $trailId);
            echo json_encode(['success' => true, 'stock' => $stock]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
        }
        exit;
    }
    
    if ($_GET['ajax'] === 'get_transfer_details') {
        $transferId = intval($_GET['transfer_id'] ?? 0);
        if ($transferId) {
            // Get transfer details
            $transfer = fetchOne($mysqli, "
                SELECT st.*, 
                       ft.trail_location as from_location, ft.trail_code as from_code,
                       tt.trail_location as to_location, tt.trail_code as to_code
                FROM stock_transfers st
                LEFT JOIN forest_trails ft ON st.from_trail_id = ft.id
                LEFT JOIN forest_trails tt ON st.to_trail_id = tt.id
                WHERE st.transfer_id = ?
            ", 'i', [$transferId]);
            
            if ($transfer) {
                // Get transfer items
                $transferItems = fetchAll($mysqli, "
                    SELECT ti.*, c.category_name as actual_category_name
                    FROM transfer_items ti 
                    LEFT JOIN items_data i ON ti.item_id = i.item_id
                    LEFT JOIN categories c ON i.category_id = c.category_id
                    WHERE ti.transfer_id = ?
                ", 'i', [$transferId]);
                
                echo json_encode([
                    'success' => true,
                    'transfer' => $transfer,
                    'items' => $transferItems
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Transfer not found']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid transfer ID']);
        }
        exit;
    }
}

// --------------------------- Handle Form Actions ---------------------------
$action = $_POST['action'] ?? '';
$message = '';
$message_type = '';

// CSRF validation FIRST before any processing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = "Security validation failed. Please try again.";
        $message_type = "danger";
    } elseif ($action === 'create_transfer') {
        $fromTrailId = intval($_POST['from_trail_id'] ?? 0);
        $toTrailId = intval($_POST['to_trail_id'] ?? 0);
        $transferReason = sanitizeInput($_POST['transfer_reason'] ?? '');
        $items = $_POST['items'] ?? [];
        
        // Validation
        if (!$fromTrailId || !$toTrailId) {
            $message = "Please select both source and destination trails.";
            $message_type = "warning";
        } elseif ($fromTrailId === $toTrailId) {
            $message = "Source and destination trails must be different.";
            $message_type = "warning";
        } elseif (empty($items)) {
            $message = "Please add at least one item to transfer.";
            $message_type = "warning";
        } else {
            $mysqli->begin_transaction();
            
            try {
                // Generate transfer code
                $transferCode = generateTransferCode($mysqli);
                $createdBy = $_SESSION['username'] ?? 'Super Admin';
                
                // Calculate totals and validate items
                $totalItems = 0;
                $totalQuantity = 0;
                $validItems = [];
                
                foreach ($items as $itemData) {
                    $itemId = intval($itemData['item_id'] ?? 0);
                    $quantity = intval($itemData['quantity'] ?? 0);
                    $itemRemarks = sanitizeInput($itemData['remarks'] ?? '');
                    
                    if ($itemId && validateQuantity($quantity)) {
                        // Check if item exists and has sufficient stock
                        $availableStock = getItemStockAtTrail($mysqli, $itemId, $fromTrailId);
                        if ($availableStock < $quantity) {
                            throw new Exception("Insufficient stock for selected items. Please check available quantities.");
                        }
                        
                        $validItems[] = [
                            'item_id' => $itemId,
                            'quantity' => $quantity,
                            'remarks' => $itemRemarks
                        ];
                        $totalItems++;
                        $totalQuantity += $quantity;
                    }
                }
                
                if (empty($validItems)) {
                    throw new Exception("No valid items found for transfer.");
                }
                
                // Insert main transfer record
                $stmt = $mysqli->prepare("
                    INSERT INTO stock_transfers (transfer_code, from_trail_id, to_trail_id, transfer_reason, total_items, total_quantity, created_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                if (!$stmt) {
                    throw new Exception("Failed to prepare transfer insert: " . $mysqli->error);
                }
                
                $stmt->bind_param('siisiss', $transferCode, $fromTrailId, $toTrailId, $transferReason, $totalItems, $totalQuantity, $createdBy);
                
                if (!$stmt->execute()) {
                    throw new Exception("Failed to insert transfer: " . $stmt->error);
                }
                
                $transferId = $mysqli->insert_id;
                $stmt->close();
                
                // Insert transfer items
                $itemStmt = $mysqli->prepare("
                    INSERT INTO transfer_items (transfer_id, item_id, item_code, item_name, category_name, requested_quantity, available_quantity, item_remarks) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                if (!$itemStmt) {
                    throw new Exception("Failed to prepare transfer items insert: " . $mysqli->error);
                }
                
                foreach ($validItems as $itemData) {
                    $itemId = $itemData['item_id'];
                    $quantity = $itemData['quantity'];
                    $itemRemarks = $itemData['remarks'];
                    
                    // Get item details
                    $item = fetchOne($mysqli, "
                        SELECT i.item_code, i.item_name, c.category_name 
                        FROM items_data i 
                        LEFT JOIN categories c ON i.category_id = c.category_id 
                        WHERE i.item_id = ?
                    ", 'i', [$itemId]);
                    
                    if ($item) {
                        $availableStock = getItemStockAtTrail($mysqli, $itemId, $fromTrailId);
                        $itemStmt->bind_param('isssiiis', $transferId, $itemId, $item['item_code'], 
                                            $item['item_name'], $item['category_name'], $quantity, $availableStock, $itemRemarks);
                        if (!$itemStmt->execute()) {
                            throw new Exception("Failed to insert transfer item: " . $itemStmt->error);
                        }
                    }
                }
                
                $itemStmt->close();
                $mysqli->commit();
                
                $message = "Stock transfer created successfully! Transfer Code: " . $transferCode;
                $message_type = "success";
                
            } catch (Exception $e) {
                $mysqli->rollback();
                error_log("Transfer creation error: " . $e->getMessage());
                $message = "Error creating transfer: " . $e->getMessage();
                $message_type = "danger";
            }
        }
    } elseif ($action === 'update_transfer_status') {
        $transferId = intval($_POST['transfer_id'] ?? 0);
        $newStatus = sanitizeInput($_POST['new_status'] ?? '');
        $statusNotes = sanitizeInput($_POST['status_notes'] ?? '');
        
        if ($transferId && in_array($newStatus, ['Pending', 'In Progress', 'Completed', 'Cancelled', 'Returned'])) {
            // Get current status for potential reversal
            $currentTransfer = fetchOne($mysqli, "SELECT status FROM stock_transfers WHERE transfer_id = ?", 'i', [$transferId]);
            $oldStatus = $currentTransfer['status'] ?? '';
            
            $mysqli->begin_transaction();
            
            try {
                // Update transfer status
                $stmt = $mysqli->prepare("UPDATE stock_transfers SET status = ? WHERE transfer_id = ?");
                if (!$stmt) {
                    throw new Exception("Failed to prepare status update: " . $mysqli->error);
                }
                
                $stmt->bind_param('si', $newStatus, $transferId);
                
                if (!$stmt->execute()) {
                    throw new Exception("Failed to update transfer status: " . $stmt->error);
                }
                $stmt->close();
                
                // Handle stock quantity updates based on status change
                if ($newStatus === 'Completed') {
                    // Update stock quantities - transfer from source to destination
                    if (!updateStockQuantities($mysqli, $transferId, $newStatus)) {
                        throw new Exception("Failed to update stock quantities");
                    }
                } elseif (($oldStatus === 'Completed') && ($newStatus === 'Cancelled' || $newStatus === 'Returned')) {
                    // Reverse stock quantities - return to source
                    if (!reverseStockQuantities($mysqli, $transferId, $oldStatus, $newStatus)) {
                        throw new Exception("Failed to reverse stock quantities");
                    }
                }
                
                // Log status change
                $logStmt = $mysqli->prepare("
                    INSERT INTO stock_movements (item_id, from_trail_id, to_trail_id, quantity, movement_type, reference_id, notes, created_by) 
                    SELECT ti.item_id, st.from_trail_id, st.to_trail_id, 0, 'STATUS_CHANGE', ?, ?, ?
                    FROM transfer_items ti 
                    JOIN stock_transfers st ON ti.transfer_id = st.transfer_id 
                    WHERE ti.transfer_id = ?
                    LIMIT 1
                ");
                $createdBy = $_SESSION['username'] ?? 'System';
                $notes = "Status changed from $oldStatus to $newStatus" . ($statusNotes ? ": $statusNotes" : "");
                $logStmt->bind_param('issi', $transferId, $notes, $createdBy, $transferId);
                $logStmt->execute();
                $logStmt->close();
                
                $mysqli->commit();
                
                $message = "Transfer status updated successfully!";
                $message_type = "success";
                
            } catch (Exception $e) {
                $mysqli->rollback();
                error_log("Status update error: " . $e->getMessage());
                $message = "Error updating transfer status: " . $e->getMessage();
                $message_type = "danger";
            }
        }
    } elseif ($action === 'update_transfer') {
        $transferId = intval($_POST['transfer_id'] ?? 0);
        $transferReason = sanitizeInput($_POST['transfer_reason'] ?? '');
        $items = $_POST['items'] ?? [];
        
        if ($transferId && !empty($items)) {
            $mysqli->begin_transaction();
            
            try {
                // Check if transfer can be edited (only Pending or In Progress)
                $currentTransfer = fetchOne($mysqli, "SELECT status FROM stock_transfers WHERE transfer_id = ?", 'i', [$transferId]);
                if (!$currentTransfer || !in_array($currentTransfer['status'], ['Pending', 'In Progress'])) {
                    throw new Exception("Only Pending or In Progress transfers can be edited.");
                }
                
                // Update main transfer record
                $stmt = $mysqli->prepare("
                    UPDATE stock_transfers 
                    SET transfer_reason = ?, updated_at = CURRENT_TIMESTAMP 
                    WHERE transfer_id = ?
                ");
                if (!$stmt) {
                    throw new Exception("Failed to prepare transfer update: " . $mysqli->error);
                }
                
                $stmt->bind_param('si', $transferReason, $transferId);
                
                if (!$stmt->execute()) {
                    throw new Exception("Failed to update transfer: " . $stmt->error);
                }
                $stmt->close();
                
                // Delete existing transfer items
                $deleteStmt = $mysqli->prepare("DELETE FROM transfer_items WHERE transfer_id = ?");
                if (!$deleteStmt) {
                    throw new Exception("Failed to prepare delete items: " . $mysqli->error);
                }
                $deleteStmt->bind_param('i', $transferId);
                if (!$deleteStmt->execute()) {
                    throw new Exception("Failed to delete transfer items: " . $deleteStmt->error);
                }
                $deleteStmt->close();
                
                // Re-insert transfer items
                $itemStmt = $mysqli->prepare("
                    INSERT INTO transfer_items (transfer_id, item_id, item_code, item_name, category_name, requested_quantity, available_quantity, item_remarks) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                if (!$itemStmt) {
                    throw new Exception("Failed to prepare transfer items insert: " . $mysqli->error);
                }
                
                $totalItems = 0;
                $totalQuantity = 0;
                $fromTrailId = 0;
                
                // Get from_trail_id for stock validation
                $transferInfo = fetchOne($mysqli, "SELECT from_trail_id FROM stock_transfers WHERE transfer_id = ?", 'i', [$transferId]);
                $fromTrailId = $transferInfo['from_trail_id'];
                
                foreach ($items as $itemData) {
                    $itemId = intval($itemData['item_id'] ?? 0);
                    $quantity = intval($itemData['quantity'] ?? 0);
                    $itemRemarks = sanitizeInput($itemData['remarks'] ?? '');
                    
                    if ($itemId && validateQuantity($quantity)) {
                        // Check stock availability
                        $availableStock = getItemStockAtTrail($mysqli, $itemId, $fromTrailId);
                        if ($availableStock < $quantity) {
                            throw new Exception("Insufficient stock for item ID: $itemId (Available: $availableStock, Requested: $quantity)");
                        }
                        
                        // Get item details
                        $item = fetchOne($mysqli, "
                            SELECT i.item_code, i.item_name, c.category_name 
                            FROM items_data i 
                            LEFT JOIN categories c ON i.category_id = c.category_id 
                            WHERE i.item_id = ?
                        ", 'i', [$itemId]);
                        
                        if ($item) {
                            $itemStmt->bind_param('isssiiis', $transferId, $itemId, $item['item_code'], $item['item_name'], 
                                                $item['category_name'], $quantity, $availableStock, $itemRemarks);
                            if (!$itemStmt->execute()) {
                                throw new Exception("Failed to insert transfer item: " . $itemStmt->error);
                            }
                            $totalItems++;
                            $totalQuantity += $quantity;
                        }
                    }
                }
                
                $itemStmt->close();
                
                // Update totals in main transfer record
                $updateTotalsStmt = $mysqli->prepare("
                    UPDATE stock_transfers 
                    SET total_items = ?, total_quantity = ? 
                    WHERE transfer_id = ?
                ");
                if ($updateTotalsStmt) {
                    $updateTotalsStmt->bind_param('iii', $totalItems, $totalQuantity, $transferId);
                    $updateTotalsStmt->execute();
                    $updateTotalsStmt->close();
                }
                
                $mysqli->commit();
                
                $message = "Stock transfer updated successfully!";
                $message_type = "success";
                
            } catch (Exception $e) {
                $mysqli->rollback();
                error_log("Transfer update error: " . $e->getMessage());
                $message = "Error updating transfer: " . $e->getMessage();
                $message_type = "danger";
            }
        } else {
            $message = "Please fill all required fields and add at least one item!";
            $message_type = "warning";
        }
    }
}

// Regenerate CSRF token after POST
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// --------------------------- Fetch Data ---------------------------
// Fetch all trails with stock information
$trails = fetchAll($mysqli, "
    SELECT t.*, 
           (SELECT COUNT(*) FROM trail_inventory WHERE trail_id = t.id AND current_stock > 0) as stocked_items_count,
           (SELECT SUM(current_stock) FROM trail_inventory WHERE trail_id = t.id) as total_stock
    FROM forest_trails t 
    WHERE t.status = 'Active' 
    ORDER BY t.trail_location
");

// Fetch all active items
$items = fetchAll($mysqli, "
    SELECT i.*, c.category_name, c.category_code 
    FROM items_data i 
    LEFT JOIN categories c ON i.category_id = c.category_id 
    WHERE i.status = 'Active' 
    ORDER BY i.item_name
");

// Fetch all transfers with details
$transfers = fetchAll($mysqli, "
    SELECT st.*, 
           ft.trail_location as from_location, ft.trail_code as from_code,
           tt.trail_location as to_location, tt.trail_code as to_code,
           (SELECT COUNT(*) FROM transfer_items WHERE transfer_id = st.transfer_id) as item_count
    FROM stock_transfers st
    LEFT JOIN forest_trails ft ON st.from_trail_id = ft.id
    LEFT JOIN forest_trails tt ON st.to_trail_id = tt.id
    ORDER BY st.created_at DESC
    LIMIT 50
");

// Fetch transfer statistics
$stats = fetchOne($mysqli, "
    SELECT 
        COUNT(*) as total_transfers,
        SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed_transfers,
        SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) as in_transit_transfers,
        SUM(CASE WHEN status IN ('Cancelled', 'Returned') THEN 1 ELSE 0 END) as cancelled_returned_transfers,
        SUM(total_quantity) as total_items_transferred
    FROM stock_transfers
    WHERE DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
");

// --------------------------- Utilities ---------------------------
function esc($s) { 
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); 
}

function formatDate($date) {
    return $date ? date('M j, Y H:i', strtotime($date)) : 'N/A';
}

function getStatusBadgeClass($status) {
    switch ($status) {
        case 'Completed': return 'success';
        case 'In Progress': return 'info';
        case 'Cancelled': return 'danger';
        case 'Returned': return 'warning';
        default: return 'secondary';
    }
}

function formatNumber($num) {
    return number_format($num ?? 0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Stock Transfer - Forest Trekking Management</title>
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
        
        /* Transfer Item Styles */
        .transfer-item {
            background: var(--primary-light);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
            border-left: 3px solid var(--primary);
        }
        
        .transfer-item:last-child {
            margin-bottom: 0;
        }
        
        .transfer-item-header {
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
        
        /* Transfer Status Badges */
        .badge-pending {
            background: var(--warning);
            color: var(--dark);
        }
        
        .badge-in-progress {
            background: var(--info);
            color: white;
        }
        
        .badge-completed {
            background: var(--success);
            color: white;
        }
        
        .badge-cancelled {
            background: var(--danger);
            color: white;
        }
        
        .badge-returned {
            background: var(--secondary);
            color: white;
        }
        
        /* Trail Badges */
        .trail-badge {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        
        /* Stock Level Indicators */
        .stock-low {
            color: var(--danger);
            font-weight: bold;
        }
        
        .stock-medium {
            color: var(--warning);
            font-weight: bold;
        }
        
        .stock-high {
            color: var(--success);
            font-weight: bold;
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
        
        /* Transfer Preview */
        .transfer-preview {
            background: var(--primary-light);
            border-radius: 8px;
            padding: 15px;
            margin-top: 20px;
        }
        
        /* Filter Styles */
        .filter-section {
            background: white;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .filter-btn {
            border-radius: 20px;
            padding: 6px 16px;
            font-weight: 500;
            transition: all 0.3s ease;
            border: 1px solid var(--primary);
        }
        
        .filter-btn.active {
            background: var(--primary);
            color: white;
            box-shadow: 0 2px 8px rgba(46, 139, 87, 0.3);
        }
        
        .filter-btn:not(.active):hover {
            background: rgba(46, 139, 87, 0.1);
        }
        
        /* Stock validation styles */
        .stock-validation {
            font-size: 0.8rem;
            margin-top: 2px;
        }
        
        .stock-valid {
            color: var(--success);
        }
        
        .stock-invalid {
            color: var(--danger);
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
                    <a class="nav-link active" href="stock_transfer.php">
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
                            <img src="https://ui-avatars.com/api/?name=<?= urlencode($_SESSION['username'] ?? 'Super Admin') ?>&background=2E8B57&color=fff" 
                                 alt="User" 
                                 width="32" 
                                 height="32" 
                                 class="rounded-circle me-2">
                            <span class="d-none d-md-inline"><?= esc($_SESSION['username'] ?? 'Super Admin') ?></span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="#"><i class="bi bi-person me-2"></i>Profile</a></li>
                            <li><a class="dropdown-item" href="#"><i class="bi bi-gear me-2"></i>Settings</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Sign Out</a></li>
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
                                    <i class="bi bi-arrow-left-right me-2"></i>Stock Transfer Management
                                </h2>
                                <p class="text-muted mb-0">Manage stock transfers between trail locations with real-time tracking</p>
                            </div>
                            <div class="col-md-6 text-md-end">
                                <button class="btn btn-primary pulse" data-bs-toggle="modal" data-bs-target="#createTransferModal">
                                    <i class="bi bi-plus-circle me-2"></i>Create Transfer
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
                                <h4 class="fw-bold text-primary mb-1"><?= formatNumber($stats['total_transfers'] ?? 0) ?></h4>
                                <p class="text-muted mb-0 small">Total Transfers</p>
                            </div>
                            <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                                <i class="bi bi-arrow-left-right"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-lg-6">
                    <div class="dashboard-card stat-card p-3 slide-in" style="animation-delay: 0.2s">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="fw-bold text-success mb-1"><?= formatNumber($stats['completed_transfers'] ?? 0) ?></h4>
                                <p class="text-muted mb-0 small">Completed</p>
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
                                <h4 class="fw-bold text-info mb-1"><?= formatNumber($stats['in_transit_transfers'] ?? 0) ?></h4>
                                <p class="text-muted mb-0 small">In Transit</p>
                            </div>
                            <div class="stat-icon bg-info bg-opacity-10 text-info">
                                <i class="bi bi-truck"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-lg-6">
                    <div class="dashboard-card stat-card p-3 slide-in" style="animation-delay: 0.4s">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="fw-bold text-warning mb-1"><?= formatNumber($stats['total_items_transferred'] ?? 0) ?></h4>
                                <p class="text-muted mb-0 small">Items Transferred</p>
                            </div>
                            <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                                <i class="bi bi-box"></i>
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

            <!-- Filter Section -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="filter-section">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label small fw-bold">Status</label>
                                <select class="form-select" id="statusFilter">
                                    <option value="all">All Status</option>
                                    <option value="Pending">Pending</option>
                                    <option value="In Progress">In Progress</option>
                                    <option value="Completed">Completed</option>
                                    <option value="Cancelled">Cancelled</option>
                                    <option value="Returned">Returned</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-bold">From Trail</label>
                                <select class="form-select" id="fromTrailFilter">
                                    <option value="all">All Trails</option>
                                    <?php foreach ($trails as $trail): ?>
                                        <option value="<?= $trail['id'] ?>"><?= esc($trail['trail_location']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-bold">To Trail</label>
                                <select class="form-select" id="toTrailFilter">
                                    <option value="all">All Trails</option>
                                    <?php foreach ($trails as $trail): ?>
                                        <option value="<?= $trail['id'] ?>"><?= esc($trail['trail_location']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-bold">Date Range</label>
                                <select class="form-select" id="dateFilter">
                                    <option value="today">Today</option>
                                    <option value="week">This Week</option>
                                    <option value="month">This Month</option>
                                    <option value="quarter">This Quarter</option>
                                    <option value="year">This Year</option>
                                </select>
                            </div>
                            <div class="col-12 text-end">
                                <button class="btn btn-primary btn-sm" id="applyFilters">
                                    <i class="bi bi-funnel me-1"></i> Apply Filters
                                </button>
                                <button class="btn btn-outline-secondary btn-sm" id="resetFilters">
                                    <i class="bi bi-arrow-clockwise me-1"></i> Reset
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Transfers Table -->
            <div class="row">
                <div class="col-12">
                    <div class="dashboard-card p-4 fade-in">
                        <div class="table-header-container">
                            <h5 class="fw-bold mb-0">All Stock Transfers</h5>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-primary" id="transferCount"><?= count($transfers) ?> Transfers</span>
                                <button class="btn btn-outline-primary btn-sm" id="refreshTransfers">
                                    <i class="bi bi-arrow-clockwise"></i>
                                </button>
                            </div>
                        </div>

                        <?php if (empty($transfers)): ?>
                            <div class="empty-state">
                                <i class="bi bi-arrow-left-right"></i>
                                <h5>No Stock Transfers Found</h5>
                                <p>Start by creating your first stock transfer between trail locations.</p>
                                <button class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#createTransferModal">
                                    <i class="bi bi-plus-circle me-2"></i>Create First Transfer
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover" id="transfersTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="text-center">S.No</th>
                                            <th>Transfer ID</th>
                                            <th>Date</th>
                                            <th>From Trail</th>
                                            <th>To Trail</th>
                                            <th>Items</th>
                                            <th class="text-center">Quantity</th>
                                            <th>Remarks</th>
                                            <th>Status</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($transfers as $index => $transfer): ?>
                                            <tr class="fade-in" id="transfer-<?= $transfer['transfer_id'] ?>" 
                                                data-status="<?= esc($transfer['status']) ?>"
                                                data-from-trail="<?= esc($transfer['from_trail_id']) ?>"
                                                data-to-trail="<?= esc($transfer['to_trail_id']) ?>"
                                                data-created="<?= esc($transfer['created_at']) ?>">
                                                <td class="text-center fw-bold"><?= $index + 1 ?></td>
                                                <td>
                                                    <span class="badge bg-dark"><?= esc($transfer['transfer_code']) ?></span>
                                                </td>
                                                <td>
                                                    <small class="text-muted"><?= formatDate($transfer['created_at']) ?></small>
                                                </td>
                                                <td>
                                                    <span class="trail-badge">
                                                        <?= esc($transfer['from_location']) ?> (<?= esc($transfer['from_code']) ?>)
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="trail-badge">
                                                        <?= esc($transfer['to_location']) ?> (<?= esc($transfer['to_code']) ?>)
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php
                                                    $transferItems = fetchAll($mysqli, "
                                                        SELECT ti.item_name, ti.requested_quantity 
                                                        FROM transfer_items ti 
                                                        WHERE ti.transfer_id = ?
                                                        LIMIT 3
                                                    ", 'i', [$transfer['transfer_id']]);
                                                    
                                                    $itemsDisplay = [];
                                                    foreach ($transferItems as $item) {
                                                        $itemsDisplay[] = $item['item_name'] . ' (' . $item['requested_quantity'] . ')';
                                                    }
                                                    echo esc(implode(', ', $itemsDisplay));
                                                    if ($transfer['item_count'] > 3) {
                                                        echo ' <span class="text-muted">+ ' . ($transfer['item_count'] - 3) . ' more</span>';
                                                    }
                                                    ?>
                                                </td>
                                                <td class="text-center fw-bold"><?= esc($transfer['total_quantity']) ?></td>
                                                <td>
                                                    <small class="text-muted">
                                                        <?= !empty($transfer['transfer_reason']) ? esc(substr($transfer['transfer_reason'], 0, 50) . (strlen($transfer['transfer_reason']) > 50 ? '...' : '')) : 'No remarks' ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <span class="badge badge-<?= strtolower(str_replace(' ', '-', $transfer['status'])) ?>">
                                                        <?= esc($transfer['status']) ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <div class="btn-group" role="group">
                                                        <button class="btn btn-primary btn-sm btn-action view-transfer" 
                                                                data-bs-toggle="modal" 
                                                                data-bs-target="#viewTransferModal"
                                                                data-id="<?= $transfer['transfer_id'] ?>">
                                                            <i class="bi bi-eye"></i>
                                                        </button>
                                                        <?php if ($transfer['status'] === 'Pending' || $transfer['status'] === 'In Progress'): ?>
                                                            <button class="btn btn-warning btn-sm btn-action edit-transfer" 
                                                                    data-bs-toggle="modal" 
                                                                    data-bs-target="#editTransferModal"
                                                                    data-id="<?= $transfer['transfer_id'] ?>">
                                                                <i class="bi bi-pencil"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                        <button class="btn btn-success btn-sm btn-action update-status" 
                                                                data-bs-toggle="modal" 
                                                                data-bs-target="#updateStatusModal"
                                                                data-id="<?= $transfer['transfer_id'] ?>"
                                                                data-status="<?= esc($transfer['status']) ?>"
                                                                data-code="<?= esc($transfer['transfer_code']) ?>">
                                                            <i class="bi bi-arrow-repeat"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Pagination -->
                            <nav aria-label="Transfer pagination">
                                <ul class="pagination justify-content-center mt-3">
                                    <li class="page-item disabled">
                                        <a class="page-link" href="#" tabindex="-1">Previous</a>
                                    </li>
                                    <li class="page-item active"><a class="page-link" href="#">1</a></li>
                                    <li class="page-item"><a class="page-link" href="#">2</a></li>
                                    <li class="page-item"><a class="page-link" href="#">3</a></li>
                                    <li class="page-item">
                                        <a class="page-link" href="#">Next</a>
                                    </li>
                                </ul>
                            </nav>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Transfer Modal -->
    <div class="modal fade" id="createTransferModal" tabindex="-1" aria-labelledby="createTransferModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="createTransferModalLabel">
                        <i class="bi bi-plus-circle me-2"></i>Create Stock Transfer
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="createTransferForm">
                    <input type="hidden" name="action" value="create_transfer">
                    <input type="hidden" name="csrf_token" value="<?= esc($_SESSION['csrf_token']) ?>">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="fromTrailId" class="form-label">From Trail<span class="text-danger">*</span></label>
                                <select class="form-select" id="fromTrailId" name="from_trail_id" required onchange="updateTrailStockInfo()">
                                    <option value="">Select Source Trail</option>
                                    <?php foreach ($trails as $trail): ?>
                                        <?php $stockInfo = getTrailStockSummary($mysqli, $trail['id']); ?>
                                        <option value="<?= $trail['id'] ?>" 
                                                data-stock="<?= esc($stockInfo['total_items']) ?>" 
                                                data-summary="<?= esc($stockInfo['summary']) ?>">
                                            <?= esc($trail['trail_location']) ?> (<?= esc($trail['trail_code']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text" id="fromTrailStockInfo"></div>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="toTrailId" class="form-label">To Trail <span class="text-danger">*</span></label>
                                <select class="form-select" id="toTrailId" name="to_trail_id" required onchange="updateTrailStockInfo()">
                                    <option value="">Select Destination Trail</option>
                                    <?php foreach ($trails as $trail): ?>
                                        <?php $stockInfo = getTrailStockSummary($mysqli, $trail['id']); ?>
                                        <option value="<?= $trail['id'] ?>" 
                                                data-stock="<?= esc($stockInfo['total_items']) ?>" 
                                                data-summary="<?= esc($stockInfo['summary']) ?>">
                                            <?= esc($trail['trail_location']) ?> (<?= esc($trail['trail_code']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text" id="toTrailStockInfo"></div>
                            </div>
                            
                            <div class="col-12">
                                <label for="transferReason" class="form-label">Transfer Remarks</label>
                                <textarea class="form-control" id="transferReason" name="transfer_reason" 
                                          rows="2" placeholder="Enter reason for this transfer"></textarea>
                            </div>
                            
                            <!-- Items Section -->
                            <div class="col-12">
                                <label class="form-label">Items to Transfer <span class="text-danger">*</span></label>
                                <div id="transferItemEntries">
                                    <!-- Item entries will be added here dynamically -->
                                </div>
                                <button type="button" class="btn add-item-btn" id="addTransferItemBtn">
                                    <i class="bi bi-plus-circle me-2"></i>Add Another Item
                                </button>
                            </div>
                            
                            <!-- Transfer Preview -->
                            <div class="col-12 mt-4">
                                <div class="transfer-preview">
                                    <h6 class="border-bottom pb-2">Transfer Preview</h6>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <p><strong>Total Items:</strong> <span id="transferPreviewItems" class="fw-bold">0</span></p>
                                            <p><strong>From:</strong> <span id="transferPreviewFrom" class="text-muted">Not selected</span></p>
                                        </div>
                                        <div class="col-md-6">
                                            <p><strong>Total Quantity:</strong> <span id="transferPreviewQuantity" class="fw-bold">0</span></p>
                                            <p><strong>To Trail:</strong> <span id="transferPreviewTo" class="text-muted">Not selected</span></p>
                                        </div>
                                        <div class="col-12 mt-2">
                                            <p><strong>Transfer Remarks:</strong> <span id="transferPreviewReason" class="text-muted">Not specified</span></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="createTransferBtn">
                            <i class="bi bi-check-circle me-2"></i>Create Transfer
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Transfer Modal -->
    <div class="modal fade" id="viewTransferModal" tabindex="-1" aria-labelledby="viewTransferModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title" id="viewTransferModalLabel">
                        <i class="bi bi-eye me-2"></i>View Transfer Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="viewTransferContent">
                    <!-- Content will be loaded via JavaScript -->
                    <div class="text-center">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2">Loading transfer details...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Transfer Modal -->
    <div class="modal fade" id="editTransferModal" tabindex="-1" aria-labelledby="editTransferModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning text-white">
                    <h5 class="modal-title" id="editTransferModalLabel">
                        <i class="bi bi-pencil me-2"></i>Edit Stock Transfer
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editTransferForm">
                    <input type="hidden" name="action" value="update_transfer">
                    <input type="hidden" name="csrf_token" value="<?= esc($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="transfer_id" id="editTransferId">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">From Trail</label>
                                <input type="text" class="form-control" id="editFromTrail" readonly>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">To Trail</label>
                                <input type="text" class="form-control" id="editToTrail" readonly>
                            </div>
                            
                            <div class="col-12">
                                <label for="editTransferReason" class="form-label">Transfer Remarks</label>
                                <textarea class="form-control" id="editTransferReason" name="transfer_reason" 
                                          rows="2" placeholder="Enter reason for this transfer"></textarea>
                            </div>
                            
                            <!-- Items Section -->
                            <div class="col-12">
                                <label class="form-label">Items to Transfer <span class="text-danger">*</span></label>
                                <div id="editTransferItemEntries">
                                    <!-- Item entries will be added here dynamically -->
                                </div>
                                <button type="button" class="btn add-item-btn" id="addEditTransferItemBtn">
                                    <i class="bi bi-plus-circle me-2"></i>Add Another Item
                                </button>
                            </div>
                            
                            <!-- Transfer Preview -->
                            <div class="col-12 mt-4">
                                <div class="transfer-preview">
                                    <h6 class="border-bottom pb-2">Transfer Summary</h6>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <p><strong>Transfer ID:</strong> <span id="editTransferCode" class="fw-bold">-</span></p>
                                            <p><strong>Status:</strong> <span id="editTransferStatus" class="fw-bold">-</span></p>
                                        </div>
                                        <div class="col-md-6">
                                            <p><strong>Created:</strong> <span id="editTransferCreated" class="fw-bold">-</span></p>
                                            <p><strong>Last Updated:</strong> <span id="editTransferUpdated" class="fw-bold">-</span></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning" id="editTransferBtn">
                            <i class="bi bi-check-circle me-2"></i>Update Transfer
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Update Status Modal -->
    <div class="modal fade" id="updateStatusModal" tabindex="-1" aria-labelledby="updateStatusModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="updateStatusModalLabel">
                        <i class="bi bi-arrow-repeat me-2"></i>Update Transfer Status
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="updateStatusForm">
                    <input type="hidden" name="action" value="update_transfer_status">
                    <input type="hidden" name="csrf_token" value="<?= esc($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="transfer_id" id="updateTransferId">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="transferCode" class="form-label">Transfer ID</label>
                            <input type="text" class="form-control" id="transferCode" readonly>
                        </div>
                        <div class="mb-3">
                            <label for="currentStatus" class="form-label">Current Status</label>
                            <input type="text" class="form-control" id="currentStatus" readonly>
                        </div>
                        <div class="mb-3">
                            <label for="newStatus" class="form-label">New Status <span class="text-danger">*</span></label>
                            <select class="form-select" id="newStatus" name="new_status" required>
                                <option value="">Select New Status</option>
                                <option value="In Progress">In Progress</option>
                                <option value="Completed">Completed</option>
                                <option value="Cancelled">Cancelled</option>
                                <option value="Returned">Returned</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="statusNotes" class="form-label">Status Notes</label>
                            <textarea class="form-control" id="statusNotes" name="status_notes" 
                                      rows="3" placeholder="Add any notes about this status change"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check-circle me-2"></i>Update Status
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

        // Item management for transfer creation
        let transferItemCount = 0;
        const transferItemEntries = document.getElementById('transferItemEntries');
        const addTransferItemBtn = document.getElementById('addTransferItemBtn');

        let editTransferItemCount = 0;
        const editTransferItemEntries = document.getElementById('editTransferItemEntries');
        const addEditTransferItemBtn = document.getElementById('addEditTransferItemBtn');

        function createTransferItemEntry(container, itemData = null, isEdit = false) {
            const count = isEdit ? ++editTransferItemCount : ++transferItemCount;
            const entryId = isEdit ? `edit_transfer_item_${count}` : `transfer_item_${count}`;
            const prefix = isEdit ? 'edit_' : '';
            
            const itemEntry = document.createElement('div');
            itemEntry.className = 'transfer-item';
            itemEntry.id = entryId;
            
            const selectedItemId = itemData ? itemData.item_id : '';
            const selectedQuantity = itemData ? itemData.requested_quantity : 1;
            const selectedRemarks = itemData ? itemData.item_remarks : '';
            
            itemEntry.innerHTML = `
                <div class="transfer-item-header">
                    <h6 class="mb-0">Item ${count}</h6>
                    ${count > 1 ? '<button type="button" class="remove-item" onclick="removeTransferItemEntry(\'' + entryId + '\', ' + isEdit + ')">&times;</button>' : ''}
                </div>
                <div class="row g-2">
                    <div class="col-md-4">
                        <label class="form-label">Item <span class="text-danger">*</span></label>
                        <select class="form-select item-select" name="items[${count}][item_id]" required 
                                onchange="${isEdit ? 'updateEditTransferItemInfo(this, ' + count + ')' : 'updateTransferItemInfo(this, ' + count + ')'}">
                            <option value="">Select Item</option>
                            <?php foreach ($items as $item): ?>
                                <option value="<?= $item['item_id'] ?>" 
                                        data-category="<?= esc($item['category_name'] ?? 'General') ?>" 
                                        data-code="<?= esc($item['item_code']) ?>"
                                        ${selectedItemId == <?= $item['item_id'] ?> ? 'selected' : ''}>
                                    <?= esc($item['item_name']) ?> (<?= esc($item['item_code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Category</label>
                        <input type="text" class="form-control category-display" id="${prefix}transfer_category_${count}" readonly 
                               value="${itemData ? (itemData.category_name || '') : ''}">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Available Quantity</label>
                        <input type="text" class="form-control available-display" id="${prefix}transfer_available_${count}" readonly
                               value="${itemData ? (itemData.available_quantity || '0') : '0'}">
                        <div class="stock-validation" id="${prefix}stock_validation_${count}"></div>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Transfering Quantity<span class="text-danger">*</span></label>
                        <input type="number" class="form-control quantity-input" name="items[${count}][quantity]" 
                               min="1" max="10000" value="${selectedQuantity}" required 
                               onchange="${isEdit ? 'updateEditTransferPreview()' : 'updateTransferPreview()'}" 
                               oninput="${isEdit ? 'validateEditQuantity(this, ' + count + ')' : 'validateQuantity(this, ' + count + ')'}">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Item Remarks</label>
                        <input type="text" class="form-control" name="items[${count}][remarks]" 
                               value="${selectedRemarks}" placeholder="Remarks">
                    </div>
                </div>
            `;
            
            container.appendChild(itemEntry);
            
            if (isEdit) {
                updateEditTransferPreview();
                if (itemData) {
                    // Update the info for pre-selected items
                    const selectElement = itemEntry.querySelector('.item-select');
                    if (selectElement) {
                        updateEditTransferItemInfo(selectElement, count);
                    }
                }
            } else {
                updateTransferPreview();
            }
            
            return count;
        }

        function removeTransferItemEntry(entryId, isEdit = false) {
            const entry = document.getElementById(entryId);
            if (entry) {
                entry.remove();
                // Renumber remaining items
                const container = isEdit ? editTransferItemEntries : transferItemEntries;
                const entries = container.getElementsByClassName('transfer-item');
                const currentCount = isEdit ? editTransferItemCount : transferItemCount;
                
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
                    const prefix = isEdit ? 'edit_' : '';
                    entries[i].id = `${prefix}transfer_item_${i + 1}`;
                    const removeBtn = entries[i].querySelector('.remove-item');
                    if (removeBtn) {
                        removeBtn.setAttribute('onclick', `removeTransferItemEntry('${prefix}transfer_item_${i + 1}', ${isEdit})`);
                    }
                    
                    // Update category display ID
                    const categoryDisplay = entries[i].querySelector('.category-display');
                    if (categoryDisplay) {
                        categoryDisplay.id = `${prefix}transfer_category_${i + 1}`;
                    }
                    
                    // Update available display ID
                    const availableDisplay = entries[i].querySelector('.available-display');
                    if (availableDisplay) {
                        availableDisplay.id = `${prefix}transfer_available_${i + 1}`;
                    }
                    
                    // Update validation display ID
                    const validationDisplay = entries[i].querySelector('.stock-validation');
                    if (validationDisplay) {
                        validationDisplay.id = `${prefix}stock_validation_${i + 1}`;
                    }
                    
                    // Update event handlers for inputs
                    const quantityInput = entries[i].querySelector('.quantity-input');
                    const itemSelect = entries[i].querySelector('.item-select');
                    
                    if (quantityInput) {
                        quantityInput.setAttribute('onchange', isEdit ? 'updateEditTransferPreview()' : 'updateTransferPreview()');
                        quantityInput.setAttribute('oninput', isEdit ? `validateEditQuantity(this, ${i + 1})` : `validateQuantity(this, ${i + 1})`);
                    }
                    if (itemSelect) {
                        itemSelect.setAttribute('onchange', isEdit ? `updateEditTransferItemInfo(this, ${i + 1})` : `updateTransferItemInfo(this, ${i + 1})`);
                    }
                }
                
                if (isEdit) {
                    editTransferItemCount = entries.length;
                    updateEditTransferPreview();
                } else {
                    transferItemCount = entries.length;
                    updateTransferPreview();
                }
            }
        }

        function updateTransferItemInfo(selectElement, index) {
            const selectedOption = selectElement.options[selectElement.selectedIndex];
            const category = selectedOption.getAttribute('data-category');
            
            const categoryDisplay = document.getElementById(`transfer_category_${index}`);
            if (categoryDisplay) {
                categoryDisplay.value = category || '';
            }
            
            // Get current stock from source trail
            const fromTrailId = document.getElementById('fromTrailId').value;
            const itemId = selectElement.value;
            
            if (fromTrailId && itemId) {
                // Show loading state
                const availableDisplay = document.getElementById(`transfer_available_${index}`);
                if (availableDisplay) {
                    availableDisplay.value = 'Loading...';
                }
                
                fetch(`stock_transfer.php?ajax=get_item_stock&item_id=${itemId}&trail_id=${fromTrailId}`)
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            const availableDisplay = document.getElementById(`transfer_available_${index}`);
                            if (availableDisplay) {
                                availableDisplay.value = data.stock;
                            }
                            
                            // Validate quantity
                            const quantityInput = document.querySelector(`#transfer_item_${index} .quantity-input`);
                            if (quantityInput) {
                                validateQuantity(quantityInput, index);
                            }
                        } else {
                            console.error('Error fetching stock:', data.message);
                            const availableDisplay = document.getElementById(`transfer_available_${index}`);
                            if (availableDisplay) {
                                availableDisplay.value = 'Error';
                            }
                        }
                    })
                    .catch(error => {
                        console.error('Error fetching stock:', error);
                        const availableDisplay = document.getElementById(`transfer_available_${index}`);
                        if (availableDisplay) {
                            availableDisplay.value = 'Error';
                        }
                    });
            } else {
                const availableDisplay = document.getElementById(`transfer_available_${index}`);
                if (availableDisplay) {
                    availableDisplay.value = '0';
                }
            }
            
            updateTransferPreview();
        }

        function updateEditTransferItemInfo(selectElement, index) {
            const selectedOption = selectElement.options[selectElement.selectedIndex];
            const category = selectedOption.getAttribute('data-category');
            
            const categoryDisplay = document.getElementById(`edit_transfer_category_${index}`);
            if (categoryDisplay) {
                categoryDisplay.value = category || '';
            }
            
            // For edit mode, we'll use the available quantity from the loaded data
            // The stock validation will be handled when the transfer details are loaded
            
            updateEditTransferPreview();
        }

        function validateQuantity(input, index) {
            const availableDisplay = document.getElementById(`transfer_available_${index}`);
            const available = parseInt(availableDisplay.value) || 0;
            const quantity = parseInt(input.value) || 0;
            const validationDisplay = document.getElementById(`stock_validation_${index}`);
            
            if (quantity > available) {
                input.classList.add('is-invalid');
                validationDisplay.innerHTML = `<span class="stock-invalid">Exceeds available stock (${available})</span>`;
                input.setCustomValidity(`Quantity cannot exceed available stock (${available})`);
            } else if (quantity <= 0) {
                input.classList.add('is-invalid');
                validationDisplay.innerHTML = `<span class="stock-invalid">Quantity must be greater than 0</span>`;
                input.setCustomValidity('Quantity must be greater than 0');
            } else {
                input.classList.remove('is-invalid');
                validationDisplay.innerHTML = `<span class="stock-valid">Stock available</span>`;
                input.setCustomValidity('');
            }
            
            updateTransferPreview();
        }

        function validateEditQuantity(input, index) {
            const availableDisplay = document.getElementById(`edit_transfer_available_${index}`);
            const available = parseInt(availableDisplay.value) || 0;
            const quantity = parseInt(input.value) || 0;
            const validationDisplay = document.getElementById(`edit_stock_validation_${index}`);
            
            if (quantity > available) {
                input.classList.add('is-invalid');
                validationDisplay.innerHTML = `<span class="stock-invalid">Exceeds available stock (${available})</span>`;
                input.setCustomValidity(`Quantity cannot exceed available stock (${available})`);
            } else if (quantity <= 0) {
                input.classList.add('is-invalid');
                validationDisplay.innerHTML = `<span class="stock-invalid">Quantity must be greater than 0</span>`;
                input.setCustomValidity('Quantity must be greater than 0');
            } else {
                input.classList.remove('is-invalid');
                validationDisplay.innerHTML = `<span class="stock-valid">Stock available</span>`;
                input.setCustomValidity('');
            }
            
            updateEditTransferPreview();
        }

        function updateTransferPreview() {
            let totalItems = 0;
            let totalQuantity = 0;
            
            const entries = transferItemEntries.getElementsByClassName('transfer-item');
            
            for (let i = 0; i < entries.length; i++) {
                const quantityInput = entries[i].querySelector('.quantity-input');
                const itemSelect = entries[i].querySelector('.item-select');
                
                if (itemSelect && itemSelect.value) {
                    totalItems++;
                }
                
                const quantity = parseFloat(quantityInput?.value) || 0;
                totalQuantity += quantity;
            }
            
            document.getElementById('transferPreviewItems').textContent = totalItems;
            document.getElementById('transferPreviewQuantity').textContent = totalQuantity;
            
            // Update trail previews
            const fromTrail = document.getElementById('fromTrailId');
            const toTrail = document.getElementById('toTrailId');
            const transferReason = document.getElementById('transferReason');
            
            if (fromTrail.value) {
                const selectedOption = fromTrail.options[fromTrail.selectedIndex];
                document.getElementById('transferPreviewFrom').textContent = selectedOption.text.split(' - ')[0];
            }
            
            if (toTrail.value) {
                const selectedOption = toTrail.options[toTrail.selectedIndex];
                document.getElementById('transferPreviewTo').textContent = selectedOption.text.split(' - ')[0];
            }
            
            if (transferReason.value) {
                document.getElementById('transferPreviewReason').textContent = transferReason.value;
            } else {
                document.getElementById('transferPreviewReason').textContent = 'Not specified';
            }
        }

        function updateEditTransferPreview() {
            let totalItems = 0;
            let totalQuantity = 0;
            
            const entries = editTransferItemEntries.getElementsByClassName('transfer-item');
            
            for (let i = 0; i < entries.length; i++) {
                const quantityInput = entries[i].querySelector('.quantity-input');
                const itemSelect = entries[i].querySelector('.item-select');
                
                if (itemSelect && itemSelect.value) {
                    totalItems++;
                }
                
                const quantity = parseFloat(quantityInput?.value) || 0;
                totalQuantity += quantity;
            }
            
            // You can update any edit-specific preview elements here if needed
        }

        // Update trail stock info display
        function updateTrailStockInfo() {
            const fromTrail = document.getElementById('fromTrailId');
            const toTrail = document.getElementById('toTrailId');
            
            if (fromTrail.value) {
                const selectedOption = fromTrail.options[fromTrail.selectedIndex];
                const stockSummary = selectedOption.getAttribute('data-summary');
                document.getElementById('fromTrailStockInfo').textContent = 'Current stock: ' + stockSummary;
                
                // Update all item available quantities
                updateAllItemAvailableQuantities();
            } else {
                document.getElementById('fromTrailStockInfo').textContent = '';
            }
            
            if (toTrail.value) {
                const selectedOption = toTrail.options[toTrail.selectedIndex];
                const stockSummary = selectedOption.getAttribute('data-summary');
                document.getElementById('toTrailStockInfo').textContent = 'Current stock: ' + stockSummary;
            } else {
                document.getElementById('toTrailStockInfo').textContent = '';
            }
            
            updateTransferPreview();
        }

        function updateAllItemAvailableQuantities() {
            const fromTrailId = document.getElementById('fromTrailId').value;
            if (!fromTrailId) return;
            
            const entries = transferItemEntries.getElementsByClassName('transfer-item');
            
            for (let i = 0; i < entries.length; i++) {
                const itemSelect = entries[i].querySelector('.item-select');
                const itemId = itemSelect.value;
                
                if (itemId) {
                    fetch(`stock_transfer.php?ajax=get_item_stock&item_id=${itemId}&trail_id=${fromTrailId}`)
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                const availableDisplay = document.getElementById(`transfer_available_${i + 1}`);
                                if (availableDisplay) {
                                    availableDisplay.value = data.stock;
                                }
                                
                                // Re-validate quantity
                                const quantityInput = document.querySelector(`#transfer_item_${i + 1} .quantity-input`);
                                if (quantityInput) {
                                    validateQuantity(quantityInput, i + 1);
                                }
                            }
                        })
                        .catch(error => {
                            console.error('Error fetching stock:', error);
                        });
                }
            }
        }

        // Initialize with one item entry
        document.addEventListener('DOMContentLoaded', function() {
            transferItemCount = createTransferItemEntry(transferItemEntries);
            
            // Add event listeners for trail changes
            document.getElementById('fromTrailId').addEventListener('change', function() {
                updateTransferPreview();
                updateTrailStockInfo();
            });
            
            document.getElementById('toTrailId').addEventListener('change', function() {
                updateTransferPreview();
                updateTrailStockInfo();
            });
            
            // Initialize view transfer modal functionality
            initializeViewTransferModal();
            initializeEditTransferModal();
            initializeUpdateStatusModal();
        });

        addTransferItemBtn.addEventListener('click', function() {
            transferItemCount = createTransferItemEntry(transferItemEntries);
        });

        addEditTransferItemBtn.addEventListener('click', function() {
            editTransferItemCount = createTransferItemEntry(editTransferItemEntries, null, true);
        });

        // Auto-focus on first input when modal opens
        const createTransferModal = document.getElementById('createTransferModal');
        createTransferModal.addEventListener('shown.bs.modal', () => {
            document.getElementById('fromTrailId').focus();
        });

        // Auto-dismiss alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);

        // Refresh transfers button
        document.getElementById('refreshTransfers')?.addEventListener('click', function() {
            this.classList.add('loading-spinner');
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        });

        // Form submission handling
        document.getElementById('createTransferForm')?.addEventListener('submit', function(e) {
            // Validate at least one item is added
            const entries = transferItemEntries.getElementsByClassName('transfer-item');
            let hasValidItems = false;
            
            for (let i = 0; i < entries.length; i++) {
                const itemSelect = entries[i].querySelector('.item-select');
                const quantityInput = entries[i].querySelector('.quantity-input');
                
                if (itemSelect.value && quantityInput.value > 0) {
                    hasValidItems = true;
                    break;
                }
            }
            
            if (!hasValidItems) {
                e.preventDefault();
                alert('Please add at least one valid item with quantity.');
                return;
            }
            
            // Validate from and to trails are different
            const fromTrail = document.getElementById('fromTrailId').value;
            const toTrail = document.getElementById('toTrailId').value;
            
            if (fromTrail === toTrail) {
                e.preventDefault();
                alert('Source and destination trails must be different.');
                return;
            }
            
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="bi bi-arrow-repeat spinner-border spinner-border-sm me-2"></i>Creating...';
        });

        // Edit form submission handling
        document.getElementById('editTransferForm')?.addEventListener('submit', function(e) {
            // Validate at least one item is added
            const entries = editTransferItemEntries.getElementsByClassName('transfer-item');
            let hasValidItems = false;
            
            for (let i = 0; i < entries.length; i++) {
                const itemSelect = entries[i].querySelector('.item-select');
                const quantityInput = entries[i].querySelector('.quantity-input');
                
                if (itemSelect.value && quantityInput.value > 0) {
                    hasValidItems = true;
                    break;
                }
            }
            
            if (!hasValidItems) {
                e.preventDefault();
                alert('Please add at least one valid item with quantity.');
                return;
            }
            
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="bi bi-arrow-repeat spinner-border spinner-border-sm me-2"></i>Updating...';
        });

        // Update status form handling
        document.getElementById('updateStatusForm')?.addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="bi bi-arrow-repeat spinner-border spinner-border-sm me-2"></i>Updating...';
        });

        // Update status modal handler
        function initializeUpdateStatusModal() {
            document.querySelectorAll('.update-status').forEach(button => {
                button.addEventListener('click', function() {
                    const transferId = this.getAttribute('data-id');
                    const currentStatus = this.getAttribute('data-status');
                    const transferCode = this.getAttribute('data-code');
                    
                    document.getElementById('updateTransferId').value = transferId;
                    document.getElementById('transferCode').value = transferCode;
                    document.getElementById('currentStatus').value = currentStatus;
                    
                    // Reset new status dropdown
                    document.getElementById('newStatus').value = '';
                    document.getElementById('statusNotes').value = '';
                });
            });
        }

        // View transfer modal functionality
        function initializeViewTransferModal() {
            const viewTransferModal = document.getElementById('viewTransferModal');
            
            viewTransferModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const transferId = button.getAttribute('data-id');
                
                // Load transfer details via AJAX
                loadTransferDetails(transferId);
            });
        }

        // Edit transfer modal functionality
        function initializeEditTransferModal() {
            const editTransferModal = document.getElementById('editTransferModal');
            
            editTransferModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const transferId = button.getAttribute('data-id');
                
                // Load transfer details for editing
                loadTransferDetailsForEdit(transferId);
            });
        }

        function loadTransferDetails(transferId) {
            const viewTransferContent = document.getElementById('viewTransferContent');
            
            // Show loading spinner
            viewTransferContent.innerHTML = `
                <div class="text-center">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2">Loading transfer details...</p>
                </div>
            `;
            
            // Make AJAX call to get transfer details
            fetch(`stock_transfer.php?ajax=get_transfer_details&transfer_id=${transferId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const transfer = data.transfer;
                        const items = data.items;
                        
                        let itemsHtml = '';
                        if (items.length > 0) {
                            itemsHtml = items.map(item => `
                                <tr>
                                    <td>${item.item_name} (${item.item_code})</td>
                                    <td>${item.actual_category_name || item.category_name || 'N/A'}</td>
                                    <td class="text-center">${item.requested_quantity}</td>
                                    <td class="text-center">${item.available_quantity}</td>
                                    <td class="text-center">${item.transferred_quantity || 0}</td>
                                    <td>${item.item_remarks || 'N/A'}</td>
                                </tr>
                            `).join('');
                        } else {
                            itemsHtml = '<tr><td colspan="6" class="text-center">No items found</td></tr>';
                        }
                        
                        viewTransferContent.innerHTML = `
                            <div class="transfer-details">
                                <div class="row mb-4">
                                    <div class="col-md-6">
                                        <h6 class="text-muted">Transfer Information</h6>
                                        <p><strong>Transfer ID:</strong> ${transfer.transfer_code}</p>
                                        <p><strong>Created:</strong> ${new Date(transfer.created_at).toLocaleDateString()}</p>
                                        <p><strong>Status:</strong> <span class="badge badge-${transfer.status.toLowerCase().replace(' ', '-')}">${transfer.status}</span></p>
                                    </div>
                                    <div class="col-md-6">
                                        <h6 class="text-muted">Trail Information</h6>
                                        <p><strong>From:</strong> ${transfer.from_location} (${transfer.from_code})</p>
                                        <p><strong>To:</strong> ${transfer.to_location} (${transfer.to_code})</p>
                                        <p><strong>Reason:</strong> ${transfer.transfer_reason || 'No reason provided'}</p>
                                    </div>
                                </div>
                                
                                <h6 class="text-muted border-bottom pb-2">Items to Transfer</h6>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Item Name</th>
                                                <th>Category</th>
                                                <th class="text-center">Requested Qty</th>
                                                <th class="text-center">Available</th>
                                                <th class="text-center">Transferred</th>
                                                <th>Remarks</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            ${itemsHtml}
                                        </tbody>
                                    </table>
                                </div>
                                
                                <div class="row mt-4">
                                    <div class="col-md-6">
                                        <h6 class="text-muted">Transfer Summary</h6>
                                        <p><strong>Total Items:</strong> ${transfer.total_items}</p>
                                        <p><strong>Total Quantity:</strong> ${transfer.total_quantity}</p>
                                    </div>
                                    <div class="col-md-6">
                                        <h6 class="text-muted">Created By</h6>
                                        <p><strong>User:</strong> ${transfer.created_by}</p>
                                        <p><strong>Time:</strong> ${new Date(transfer.created_at).toLocaleString()}</p>
                                    </div>
                                </div>
                            </div>
                        `;
                    } else {
                        viewTransferContent.innerHTML = `
                            <div class="alert alert-danger">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                ${data.message || 'Failed to load transfer details'}
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error loading transfer details:', error);
                    viewTransferContent.innerHTML = `
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            Error loading transfer details. Please try again.
                        </div>
                    `;
                });
        }

        function loadTransferDetailsForEdit(transferId) {
            // Show loading state
            editTransferItemEntries.innerHTML = `
                <div class="text-center py-3">
                    <div class="spinner-border text-warning" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2">Loading transfer details...</p>
                </div>
            `;
            
            // Make AJAX call to get transfer details
            fetch(`stock_transfer.php?ajax=get_transfer_details&transfer_id=${transferId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const transfer = data.transfer;
                        const items = data.items;
                        
                        // Populate form fields
                        document.getElementById('editTransferId').value = transferId;
                        document.getElementById('editFromTrail').value = `${transfer.from_location} (${transfer.from_code})`;
                        document.getElementById('editToTrail').value = `${transfer.to_location} (${transfer.to_code})`;
                        document.getElementById('editTransferReason').value = transfer.transfer_reason || '';
                        
                        document.getElementById('editTransferCode').textContent = transfer.transfer_code;
                        document.getElementById('editTransferStatus').textContent = transfer.status;
                        document.getElementById('editTransferCreated').textContent = new Date(transfer.created_at).toLocaleString();
                        document.getElementById('editTransferUpdated').textContent = new Date(transfer.updated_at).toLocaleString();
                        
                        // Clear existing items and add new ones
                        editTransferItemEntries.innerHTML = '';
                        editTransferItemCount = 0;
                        
                        if (items.length > 0) {
                            items.forEach(item => {
                                editTransferItemCount = createTransferItemEntry(editTransferItemEntries, item, true);
                            });
                        } else {
                            // Add one empty item entry
                            editTransferItemCount = createTransferItemEntry(editTransferItemEntries, null, true);
                        }
                    } else {
                        editTransferItemEntries.innerHTML = `
                            <div class="alert alert-danger">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                ${data.message || 'Failed to load transfer details'}
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error loading transfer details for edit:', error);
                    editTransferItemEntries.innerHTML = `
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            Error loading transfer details. Please try again.
                        </div>
                    `;
                });
        }

        // Filter functionality
        document.getElementById('applyFilters').addEventListener('click', function() {
            const statusFilter = document.getElementById('statusFilter').value;
            const fromTrailFilter = document.getElementById('fromTrailFilter').value;
            const toTrailFilter = document.getElementById('toTrailFilter').value;
            const dateFilter = document.getElementById('dateFilter').value;
            
            filterTransfersTable(statusFilter, fromTrailFilter, toTrailFilter, dateFilter);
        });

        document.getElementById('resetFilters').addEventListener('click', function() {
            document.getElementById('statusFilter').value = 'all';
            document.getElementById('fromTrailFilter').value = 'all';
            document.getElementById('toTrailFilter').value = 'all';
            document.getElementById('dateFilter').value = 'today';
            
            // Reset table to show all transfers
            const rows = document.querySelectorAll('#transfersTable tbody tr');
            rows.forEach(row => row.style.display = '');
            
            document.getElementById('transferCount').textContent = rows.length + ' Transfers';
        });

        function filterTransfersTable(status, fromTrail, toTrail, dateRange) {
            const rows = document.querySelectorAll('#transfersTable tbody tr');
            let visibleCount = 0;
            
            const now = new Date();
            let startDate;
            
            switch (dateRange) {
                case 'today':
                    startDate = new Date(now.getFullYear(), now.getMonth(), now.getDate());
                    break;
                case 'week':
                    startDate = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 7);
                    break;
                case 'month':
                    startDate = new Date(now.getFullYear(), now.getMonth() - 1, now.getDate());
                    break;
                case 'quarter':
                    startDate = new Date(now.getFullYear(), now.getMonth() - 3, now.getDate());
                    break;
                case 'year':
                    startDate = new Date(now.getFullYear() - 1, now.getMonth(), now.getDate());
                    break;
                default:
                    startDate = new Date(0); // Beginning of time
            }
            
            rows.forEach(row => {
                let showRow = true;
                
                // Status filter
                if (status !== 'all') {
                    const rowStatus = row.getAttribute('data-status');
                    if (rowStatus !== status) {
                        showRow = false;
                    }
                }
                
                // From trail filter
                if (fromTrail !== 'all') {
                    const rowFromTrail = row.getAttribute('data-from-trail');
                    if (rowFromTrail !== fromTrail) {
                        showRow = false;
                    }
                }
                
                // To trail filter
                if (toTrail !== 'all') {
                    const rowToTrail = row.getAttribute('data-to-trail');
                    if (rowToTrail !== toTrail) {
                        showRow = false;
                    }
                }
                
                // Date filter
                if (dateRange !== 'today') {
                    const rowDate = new Date(row.getAttribute('data-created'));
                    if (rowDate < startDate) {
                        showRow = false;
                    }
                }
                
                row.style.display = showRow ? '' : 'none';
                if (showRow) visibleCount++;
            });
            
            document.getElementById('transferCount').textContent = visibleCount + ' Transfers';
        }

        // Reset everything when modal is closed
        createTransferModal.addEventListener('hidden.bs.modal', function() {
            transferItemEntries.innerHTML = '';
            transferItemCount = 0;
            transferItemCount = createTransferItemEntry(transferItemEntries);
            document.getElementById('transferPreviewItems').textContent = '0';
            document.getElementById('transferPreviewQuantity').textContent = '0';
            document.getElementById('transferPreviewFrom').textContent = 'Not selected';
            document.getElementById('transferPreviewTo').textContent = 'Not selected';
            document.getElementById('transferPreviewReason').textContent = 'Not specified';
            
            // Reset form
            document.getElementById('createTransferForm').reset();
            document.getElementById('fromTrailStockInfo').textContent = '';
            document.getElementById('toTrailStockInfo').textContent = '';
        });

        // Reset edit modal when closed
        document.getElementById('editTransferModal').addEventListener('hidden.bs.modal', function() {
            editTransferItemEntries.innerHTML = '';
            editTransferItemCount = 0;
        });

        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
</body>
</html>
<?php
// Close database connection
$mysqli->close();
?>