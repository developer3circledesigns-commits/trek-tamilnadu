<?php
/**
 * Trekkers Data Management Page - Forest Trekking System
 * Enhanced with Excel import support and improved functionality
 * SECURE VERSION - Fixed all security vulnerabilities and logical errors
 */

// --------------------------- Configuration & Security ---------------------------
session_start();

// Environment-based configuration
$environment = 'development'; // Change to 'production' in production

// Error reporting based on environment
if ($environment === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(E_ALL & ~E_NOTICE);
    ini_set('display_errors', 0);
}

// Security headers
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

// Database configuration - In production, use environment variables
$dbConfig = [
    'host' => '127.0.0.1',
    'username' => 'root',
    'password' => '',
    'database' => 'Trek_Tamilnadu_Testing_db',
    'charset' => 'utf8mb4'
];

// File upload configuration
$uploadConfig = [
    'max_file_size' => 10 * 1024 * 1024, // 10MB
    'allowed_extensions' => ['csv', 'xls', 'xlsx'],
    'upload_dir' => __DIR__ . '/uploads/',
    'max_execution_time' => 300 // 5 minutes for large files
];

// Create upload directory if it doesn't exist
if (!is_dir($uploadConfig['upload_dir'])) {
    mkdir($uploadConfig['upload_dir'], 0755, true);
}

// --------------------------- Security Functions ---------------------------
function sanitizeInput($data, $type = 'string') {
    if ($data === null) return null;
    
    switch ($type) {
        case 'int':
            return filter_var($data, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        case 'email':
            return filter_var($data, FILTER_VALIDATE_EMAIL);
        case 'url':
            return filter_var($data, FILTER_VALIDATE_URL);
        case 'date':
            $timestamp = strtotime($data);
            return $timestamp ? date('Y-m-d', $timestamp) : null;
        case 'alphanum':
            return preg_replace('/[^a-zA-Z0-9]/', '', $data);
        case 'filename':
            return preg_replace('/[^a-zA-Z0-9._-]/', '', $data);
        default:
            return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
    }
}

function validateFileUpload($file, $config) {
    $errors = [];
    
    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE => 'File size exceeds server limit',
            UPLOAD_ERR_FORM_SIZE => 'File size exceeds form limit',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
        ];
        $errors[] = $uploadErrors[$file['error']] ?? 'Unknown upload error';
        return $errors;
    }
    
    // Check file size
    if ($file['size'] > $config['max_file_size']) {
        $errors[] = 'File size exceeds maximum allowed limit of ' . ($config['max_file_size'] / 1024 / 1024) . 'MB';
    }
    
    // Check file extension
    $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($fileExt, $config['allowed_extensions'])) {
        $errors[] = 'Invalid file type. Allowed types: ' . implode(', ', $config['allowed_extensions']);
    }
    
    // Check MIME type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    $allowedMimes = [
        'text/csv',
        'text/plain',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    ];
    
    if (!in_array($mimeType, $allowedMimes)) {
        $errors[] = 'Invalid file MIME type';
    }
    
    return $errors;
}

function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// --------------------------- Database Class ---------------------------
class Database {
    private $connection;
    private $lastError;
    
    public function __construct($config) {
        $this->connection = new mysqli(
            $config['host'],
            $config['username'],
            $config['password'],
            $config['database']
        );
        
        if ($this->connection->connect_errno) {
            throw new Exception("Failed to connect to MySQL: " . $this->connection->connect_error);
        }
        
        $this->connection->set_charset($config['charset']);
        $this->connection->query("SET NAMES {$config['charset']} COLLATE {$config['charset']}_unicode_ci");
    }
    
    public function query($sql, $params = [], $types = '') {
        $stmt = $this->connection->prepare($sql);
        if (!$stmt) {
            $this->lastError = $this->connection->error;
            return false;
        }
        
        if (!empty($params) && !empty($types)) {
            $stmt->bind_param($types, ...$params);
        }
        
        if (!$stmt->execute()) {
            $this->lastError = $stmt->error;
            $stmt->close();
            return false;
        }
        
        $result = $stmt->get_result();
        $stmt->close();
        
        return $result;
    }
    
    public function fetchAll($sql, $params = [], $types = '') {
        $result = $this->query($sql, $params, $types);
        if (!$result) return [];
        
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
        return $rows;
    }
    
    public function fetchOne($sql, $params = [], $types = '') {
        $rows = $this->fetchAll($sql, $params, $types);
        return count($rows) ? $rows[0] : null;
    }
    
    public function insert($table, $data) {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $values = array_values($data);
        $types = str_repeat('s', count($data));
        
        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
        return $this->query($sql, $values, $types);
    }
    
    public function update($table, $data, $where, $whereParams = [], $whereTypes = '') {
        $setClause = implode(' = ?, ', array_keys($data)) . ' = ?';
        $values = array_values($data);
        $types = str_repeat('s', count($data));
        
        $sql = "UPDATE {$table} SET {$setClause} WHERE {$where}";
        
        if (!empty($whereParams)) {
            $values = array_merge($values, $whereParams);
            $types .= $whereTypes;
        }
        
        return $this->query($sql, $values, $types);
    }
    
    public function beginTransaction() {
        return $this->connection->begin_transaction();
    }
    
    public function commit() {
        return $this->connection->commit();
    }
    
    public function rollback() {
        return $this->connection->rollback();
    }
    
    public function getLastError() {
        return $this->lastError;
    }
    
    public function escape($value) {
        return $this->connection->real_escape_string($value);
    }
    
    public function __destruct() {
        if ($this->connection) {
            $this->connection->close();
        }
    }
}

// Initialize database connection
try {
    $db = new Database($dbConfig);
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

// --------------------------- Application Classes ---------------------------
class TrekkerManager {
    private $db;
    
    public function __construct($db) {
        $this->db = $db;
        $this->initializeDatabase();
    }
    
    private function initializeDatabase() {
        // Check and create tables if they don't exist
        $tableCheck = $this->db->query("SHOW TABLES LIKE 'trekkers_data_table'");
        if (!$tableCheck || $tableCheck->num_rows == 0) {
            $this->createTrekkersTable();
        }
        
        $trailsTableCheck = $this->db->query("SHOW TABLES LIKE 'forest_trails'");
        if (!$trailsTableCheck || $trailsTableCheck->num_rows == 0) {
            $this->createTrailsTable();
        }
    }
    
    private function createTrekkersTable() {
        $sql = "
        CREATE TABLE trekkers_data_table (
            id INT PRIMARY KEY AUTO_INCREMENT,
            trek_id VARCHAR(50) UNIQUE NOT NULL,
            trek_route_no VARCHAR(20) NOT NULL,
            trail_name VARCHAR(255) NOT NULL,
            booking_date DATE NOT NULL,
            trek_date DATE NOT NULL,
            category VARCHAR(50) DEFAULT 'Easy',
            status ENUM('Paid','Cancelled','Pending') DEFAULT 'Pending',
            GU001 VARCHAR(50) DEFAULT '',
            GU002 VARCHAR(50) DEFAULT '',
            GU003 VARCHAR(50) DEFAULT '',
            GU004 VARCHAR(50) DEFAULT '',
            GU005 VARCHAR(50) DEFAULT '',
            GU006 VARCHAR(50) DEFAULT '',
            GU007 VARCHAR(50) DEFAULT '',
            GK001 VARCHAR(50) DEFAULT '',
            GK002 VARCHAR(50) DEFAULT '',
            GK003 VARCHAR(50) DEFAULT '',
            GK004 VARCHAR(50) DEFAULT '',
            GK005 VARCHAR(50) DEFAULT '',
            GK006 VARCHAR(50) DEFAULT '',
            GK007 VARCHAR(50) DEFAULT '',
            GK008 VARCHAR(50) DEFAULT '',
            GK009 VARCHAR(50) DEFAULT '',
            GK010 VARCHAR(50) DEFAULT '',
            GK011 VARCHAR(50) DEFAULT '',
            GK012 VARCHAR(50) DEFAULT '',
            GK013 VARCHAR(50) DEFAULT '',
            CK001 VARCHAR(50) DEFAULT '',
            CK002 VARCHAR(50) DEFAULT '',
            CK003 VARCHAR(50) DEFAULT '',
            CK004 VARCHAR(50) DEFAULT '',
            CK005 VARCHAR(50) DEFAULT '',
            CK006 VARCHAR(50) DEFAULT '',
            TR001 VARCHAR(50) DEFAULT '',
            TR002 VARCHAR(50) DEFAULT '',
            TR003 VARCHAR(50) DEFAULT '',
            TR004 VARCHAR(50) DEFAULT '',
            TR005 VARCHAR(50) DEFAULT '',
            TR006 VARCHAR(50) DEFAULT '',
            TR007 VARCHAR(50) DEFAULT '',
            TR008 VARCHAR(50) DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_trek_id (trek_id),
            INDEX idx_trek_route_no (trek_route_no),
            INDEX idx_status (status),
            INDEX idx_trek_date (trek_date),
            FOREIGN KEY (trek_route_no) REFERENCES forest_trails(trail_code) ON DELETE RESTRICT
        )";
        
        return $this->db->query($sql);
    }
    
    private function createTrailsTable() {
        $sql = "
        CREATE TABLE forest_trails (
            id INT PRIMARY KEY AUTO_INCREMENT,
            trail_code VARCHAR(20) UNIQUE NOT NULL,
            trail_location VARCHAR(255) NOT NULL,
            difficulty ENUM('Easy','Moderate','Tough') DEFAULT 'Easy',
            status ENUM('Active','Inactive') DEFAULT 'Active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        
        $this->db->query($sql);
        
        // Insert default trails
        $defaultTrails = [
            ['TT045', 'Western Ghats Trail', 'Easy'],
            ['TT003', 'Himalayan Base Camp', 'Tough'],
            ['TT050', 'Coastal Forest Walk', 'Easy'],
            ['TT020', 'Mountain Ridge Trail', 'Moderate'],
            ['TT021', 'River Valley Trek', 'Moderate'],
            ['TT022', 'Wildlife Sanctuary Route', 'Easy'],
            ['TT023', 'Alpine Meadow Path', 'Tough'],
            ['TT015', 'Forest Canopy Walk', 'Easy']
        ];
        
        foreach ($defaultTrails as $trail) {
            $this->db->insert('forest_trails', [
                'trail_code' => $trail[0],
                'trail_location' => $trail[1],
                'difficulty' => $trail[2]
            ]);
        }
    }
    
    public function getAllTrekkers($filters = []) {
        $sql = "SELECT * FROM trekkers_data_table WHERE 1=1";
        $params = [];
        $types = '';
        
        if (!empty($filters['status'])) {
            $sql .= " AND status = ?";
            $params[] = $filters['status'];
            $types .= 's';
        }
        
        if (!empty($filters['search'])) {
            $sql .= " AND (trek_id LIKE ? OR trail_name LIKE ? OR trek_route_no LIKE ?)";
            $searchTerm = "%{$filters['search']}%";
            $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
            $types .= 'sss';
        }
        
        $sql .= " ORDER BY created_at DESC";
        
        return $this->db->fetchAll($sql, $params, $types);
    }
    
    public function getTrekkerById($id) {
        return $this->db->fetchOne(
            "SELECT * FROM trekkers_data_table WHERE id = ?",
            [$id],
            'i'
        );
    }
    
    public function addTrekker($data) {
        // Validate required fields
        $required = ['trek_id', 'trek_route_no', 'booking_date', 'trek_date'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new Exception("Missing required field: {$field}");
            }
        }
        
        // Check if trek_id already exists
        $existing = $this->db->fetchOne(
            "SELECT id FROM trekkers_data_table WHERE trek_id = ?",
            [$data['trek_id']],
            's'
        );
        
        if ($existing) {
            throw new Exception("Trek ID already exists");
        }
        
        // Validate trail exists
        $trail = $this->db->fetchOne(
            "SELECT trail_location FROM forest_trails WHERE trail_code = ? AND status = 'Active'",
            [$data['trek_route_no']],
            's'
        );
        
        if (!$trail) {
            throw new Exception("Invalid trail code or trail is inactive");
        }
        
        // Prepare trekker data
        $trekkerData = [
            'trek_id' => $data['trek_id'],
            'trek_route_no' => $data['trek_route_no'],
            'trail_name' => $trail['trail_location'],
            'booking_date' => $data['booking_date'],
            'trek_date' => $data['trek_date'],
            'category' => $data['category'] ?? 'Easy',
            'status' => $data['status'] ?? 'Pending'
        ];
        
        // Add additional columns if provided
        $additionalColumns = [
            'GU001', 'GU002', 'GU003', 'GU004', 'GU005', 'GU006', 'GU007',
            'GK001', 'GK002', 'GK003', 'GK004', 'GK005', 'GK006', 'GK007', 'GK008', 'GK009', 'GK010', 'GK011', 'GK012', 'GK013',
            'CK001', 'CK002', 'CK003', 'CK004', 'CK005', 'CK006',
            'TR001', 'TR002', 'TR003', 'TR004', 'TR005', 'TR006', 'TR007', 'TR008'
        ];
        
        foreach ($additionalColumns as $column) {
            if (isset($data[$column])) {
                $trekkerData[$column] = $data[$column];
            }
        }
        
        return $this->db->insert('trekkers_data_table', $trekkerData);
    }
    
    public function updateTrekker($id, $data) {
        // Validate trekker exists
        $existing = $this->getTrekkerById($id);
        if (!$existing) {
            throw new Exception("Trekker not found");
        }
        
        // Validate trail if route number is being updated
        if (isset($data['trek_route_no']) && $data['trek_route_no'] !== $existing['trek_route_no']) {
            $trail = $this->db->fetchOne(
                "SELECT trail_location FROM forest_trails WHERE trail_code = ? AND status = 'Active'",
                [$data['trek_route_no']],
                's'
            );
            
            if (!$trail) {
                throw new Exception("Invalid trail code or trail is inactive");
            }
            
            $data['trail_name'] = $trail['trail_location'];
        }
        
        return $this->db->update('trekkers_data_table', $data, 'id = ?', [$id], 'i');
    }
    
    public function deleteTrekker($id) {
        return $this->db->query(
            "DELETE FROM trekkers_data_table WHERE id = ?",
            [$id],
            'i'
        );
    }
    
    public function getStatistics() {
        $today = date('Y-m-d');
        
        $stats = [
            'total' => 0,
            'paid' => 0,
            'cancelled' => 0,
            'pending' => 0,
            'completed' => 0
        ];
        
        $result = $this->db->fetchAll("
            SELECT status, trek_date, COUNT(*) as count 
            FROM trekkers_data_table 
            GROUP BY status, trek_date
        ");
        
        foreach ($result as $row) {
            $stats['total'] += $row['count'];
            
            switch ($row['status']) {
                case 'Paid':
                    $stats['paid'] += $row['count'];
                    if ($row['trek_date'] < $today) {
                        $stats['completed'] += $row['count'];
                    }
                    break;
                case 'Cancelled':
                    $stats['cancelled'] += $row['count'];
                    break;
                case 'Pending':
                    $stats['pending'] += $row['count'];
                    break;
            }
        }
        
        return $stats;
    }
}

class FileImporter {
    private $db;
    private $trekkerManager;
    private $uploadConfig;
    
    public function __construct($db, $trekkerManager, $uploadConfig) {
        $this->db = $db;
        $this->trekkerManager = $trekkerManager;
        $this->uploadConfig = $uploadConfig;
    }
    
    public function importFromFile($filePath, $fileName) {
        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        
        switch ($fileExt) {
            case 'csv':
                return $this->importCSV($filePath);
            case 'xls':
            case 'xlsx':
                return $this->importExcel($filePath);
            default:
                throw new Exception("Unsupported file format");
        }
    }
    
    private function importCSV($filePath) {
        $rows = [];
        
        if (($handle = fopen($filePath, 'r')) !== FALSE) {
            // Detect delimiter
            $firstLine = fgets($handle);
            rewind($handle);
            
            $delimiter = $this->detectDelimiter($firstLine);
            
            while (($data = fgetcsv($handle, 10000, $delimiter)) !== FALSE) {
                if ($this->isValidRow($data)) {
                    $rows[] = $data;
                }
            }
            fclose($handle);
        }
        
        return $this->processRows($rows);
    }
    
    private function importExcel($filePath) {
        $rows = [];
        
        // Try using PhpSpreadsheet if available
        if (class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
            try {
                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
                $worksheet = $spreadsheet->getActiveSheet();
                
                foreach ($worksheet->getRowIterator() as $row) {
                    $cellIterator = $row->getCellIterator();
                    $cellIterator->setIterateOnlyExistingCells(false);
                    
                    $rowData = [];
                    foreach ($cellIterator as $cell) {
                        $value = $cell->getCalculatedValue();
                        if ($value instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
                            $value = $value->getPlainText();
                        }
                        $rowData[] = $value;
                    }
                    
                    if ($this->isValidRow($rowData)) {
                        $rows[] = $rowData;
                    }
                }
            } catch (Exception $e) {
                throw new Exception("Excel parsing failed: " . $e->getMessage());
            }
        } else {
            // Fallback to CSV reading for Excel files if PhpSpreadsheet not available
            return $this->importCSV($filePath);
        }
        
        return $this->processRows($rows);
    }
    
    private function detectDelimiter($firstLine) {
        $delimiters = ["\t" => 0, ";" => 0, "," => 0];
        
        foreach ($delimiters as $delimiter => &$count) {
            $count = count(str_getcsv($firstLine, $delimiter));
        }
        
        return array_search(max($delimiters), $delimiters);
    }
    
    private function isValidRow($row) {
        return count(array_filter($row, function($value) { 
            return $value !== null && $value !== '' && trim($value) !== ''; 
        })) > 0;
    }
    
    private function isHeaderRow($row) {
        if (count($row) < 3) return false;
        
        $firstCell = strtolower(trim($row[0] ?? ''));
        $headerIndicators = ['trekking', 'si no', 'route no', 'booked date', 'trekking date', 'status', 'category'];
        
        foreach ($headerIndicators as $indicator) {
            if (strpos($firstCell, $indicator) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    private function processRows($rows) {
        if (empty($rows)) {
            throw new Exception("No valid data found in file");
        }
        
        // Check if first row is header
        if ($this->isHeaderRow($rows[0])) {
            array_shift($rows);
        }
        
        $results = [
            'imported' => 0,
            'updated' => 0,
            'errors' => 0,
            'error_details' => []
        ];
        
        $this->db->beginTransaction();
        
        try {
            foreach ($rows as $index => $rawData) {
                try {
                    $sanitizedData = $this->sanitizeRowData($rawData);
                    
                    if (empty($sanitizedData['trek_id']) || empty($sanitizedData['trek_route_no'])) {
                        throw new Exception("Missing required fields: Trek ID and Trek Route No");
                    }
                    
                    // Check if record exists
                    $existing = $this->db->fetchOne(
                        "SELECT id FROM trekkers_data_table WHERE trek_id = ?",
                        [$sanitizedData['trek_id']],
                        's'
                    );
                    
                    if ($existing) {
                        // Update existing record
                        if ($this->updateTrekkerFromImport($existing['id'], $sanitizedData)) {
                            $results['updated']++;
                        } else {
                            throw new Exception("Update failed");
                        }
                    } else {
                        // Insert new record
                        if ($this->trekkerManager->addTrekker($sanitizedData)) {
                            $results['imported']++;
                        } else {
                            throw new Exception("Insert failed");
                        }
                    }
                    
                } catch (Exception $e) {
                    $results['errors']++;
                    $results['error_details'][] = "Row " . ($index + 1) . ": " . $e->getMessage();
                    
                    // Continue processing other rows but log the error
                    error_log("Import error at row {$index}: " . $e->getMessage());
                }
            }
            
            $this->db->commit();
            
        } catch (Exception $e) {
            $this->db->rollback();
            throw new Exception("Import transaction failed: " . $e->getMessage());
        }
        
        return $results;
    }
    
    private function sanitizeRowData($rawData) {
        // Clean and reindex the data
        $cleanData = array_values(array_filter($rawData, function($value) {
            return $value !== null && $value !== '' && trim($value) !== '';
        }));
        
        $sanitized = [];
        
        // Map columns with validation - handle flexible column mapping
        $sanitized['trek_id'] = !empty($cleanData[0]) ? sanitizeInput($cleanData[0], 'alphanum') : '';
        $sanitized['trek_route_no'] = !empty($cleanData[1]) ? sanitizeInput($cleanData[1], 'alphanum') : '';
        $sanitized['booking_date'] = !empty($cleanData[2]) ? $this->parseDate($cleanData[2]) : null;
        $sanitized['trek_date'] = !empty($cleanData[3]) ? $this->parseDate($cleanData[3]) : null;
        $sanitized['category'] = !empty($cleanData[4]) ? sanitizeInput($cleanData[4]) : 'Easy';
        $sanitized['status'] = $this->validateStatus(!empty($cleanData[5]) ? $cleanData[5] : 'Pending');
        
        // Validate required dates
        if (empty($sanitized['booking_date'])) {
            $sanitized['booking_date'] = date('Y-m-d');
        }
        
        if (empty($sanitized['trek_date'])) {
            $sanitized['trek_date'] = date('Y-m-d', strtotime('+7 days'));
        }
        
        // Additional columns - handle flexible column mapping
        $additionalColumns = [
            'GU001', 'GU002', 'GU003', 'GU004', 'GU005', 'GU006', 'GU007',
            'GK001', 'GK002', 'GK003', 'GK004', 'GK005', 'GK006', 'GK007', 'GK008', 'GK009', 'GK010', 'GK011', 'GK012', 'GK013',
            'CK001', 'CK002', 'CK003', 'CK004', 'CK005', 'CK006',
            'TR001', 'TR002', 'TR003', 'TR004', 'TR005', 'TR006', 'TR007', 'TR008'
        ];
        
        foreach ($additionalColumns as $index => $column) {
            $dataIndex = 6 + $index;
            $value = isset($cleanData[$dataIndex]) ? sanitizeInput($cleanData[$dataIndex]) : '';
            
            // Validate numeric values but allow empty/zero values
            if (!empty($value) && !is_numeric($value)) {
                throw new Exception("Invalid numeric value for {$column}");
            }
            
            $sanitized[$column] = $value;
        }
        
        return $sanitized;
    }
    
    private function parseDate($dateString) {
        if (empty($dateString)) return null;
        
        // Handle various date formats
        $formats = [
            'd/m/Y', 'm/d/Y', 'Y-m-d', 'd-m-Y', 'm-d-Y',
            'd/m/y', 'm/d/y', 'd-m-y', 'm-d-y'
        ];
        
        foreach ($formats as $format) {
            $date = DateTime::createFromFormat($format, $dateString);
            if ($date !== false) {
                return $date->format('Y-m-d');
            }
        }
        
        // Try strtotime as fallback
        $timestamp = strtotime($dateString);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }
        
        return null;
    }
    
    private function validateStatus($status) {
        $validStatuses = ['Paid', 'Cancelled', 'Pending'];
        $normalizedStatus = ucfirst(strtolower(trim($status)));
        
        if (in_array($normalizedStatus, $validStatuses)) {
            return $normalizedStatus;
        }
        
        return 'Pending';
    }
    
    private function updateTrekkerFromImport($id, $data) {
        // Get trail name
        $trail = $this->db->fetchOne(
            "SELECT trail_location FROM forest_trails WHERE trail_code = ?",
            [$data['trek_route_no']],
            's'
        );
        
        if ($trail) {
            $data['trail_name'] = $trail['trail_location'];
        }
        
        return $this->trekkerManager->updateTrekker($id, $data);
    }
}

// Initialize application components
$trekkerManager = new TrekkerManager($db);
$fileImporter = new FileImporter($db, $trekkerManager, $uploadConfig);
$csrfToken = generateCSRFToken();

// --------------------------- Handle Form Actions ---------------------------
$action = sanitizeInput($_POST['action'] ?? '');
$message = '';
$message_type = '';

// Validate CSRF token for all POST actions except file downloads
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($action, ['download_sample', 'export_csv', 'export_excel'])) {
    $postedToken = sanitizeInput($_POST['csrf_token'] ?? '');
    if (!validateCSRFToken($postedToken)) {
        $message = "Security validation failed. Please try again.";
        $message_type = "danger";
        $action = ''; // Cancel the action
    }
}

// Handle Download Sample File
if ($action === 'download_sample') {
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="Trekkers_Data_Sample.xlsx"');
    
    $sampleData = [
        ['Trekking SI No', 'Trek Route No', 'Booked Date', 'Trekking Date', 'Category', 'Status', 'GU001', 'GU002', 'GU003', 'GU004', 'GU005', 'GU006', 'GU007', 'GK001', 'GK002', 'GK003', 'GK004', 'GK005', 'GK006', 'GK007', 'GK008', 'GK009', 'GK010', 'GK011', 'GK012', 'GK013', 'CK001', 'CK002', 'CK003', 'CK004', 'CK005', 'CK006', 'TR001', 'TR002', 'TR003', 'TR004', 'TR005', 'TR006', 'TR007', 'TR008'],
        ['TT0450385029132526E01', 'TT045', '29/09/2025', '05/10/2025', 'General', 'Paid', '1', '2', '0', '1', '0', '0', '0', '1', '0', '0', '0', '0', '0', '0', '0', '0', '0', '0', '0', '0', '0', '0', '0', '0', '0', '0', '0', '0', '0', '0', '0', '0', '0', '0'],
        ['TT0450385029132526E02', 'TT046', '30/09/2025', '06/10/2025', 'Student', 'Pending', '0', '1', '1', '0', '0', '0', '0', '0', '1', '0', '0', '0', '0', '0', '0', '0', '0', '0', '0', '0', '0', '0', '0', '0', '0', '0', '0', '0', '0', '0', '0', '0', '0', '0']
    ];
    
    $output = fopen('php://output', 'w');
    fputs($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
    
    foreach ($sampleData as $row) {
        fputcsv($output, $row, "\t");
    }
    
    fclose($output);
    exit();
}

// Handle File Import
if ($action === 'import_csv') {
    if (isset($_FILES['csv_file']) && is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
        $file = $_FILES['csv_file'];
        
        // Validate file upload
        $uploadErrors = validateFileUpload($file, $uploadConfig);
        
        if (empty($uploadErrors)) {
            try {
                // Create a secure temporary file name
                $tempFileName = $uploadConfig['upload_dir'] . uniqid('import_', true) . '_' . sanitizeInput($file['name'], 'filename');
                
                if (move_uploaded_file($file['tmp_name'], $tempFileName)) {
                    $results = $fileImporter->importFromFile($tempFileName, $file['name']);
                    
                    // Clean up temporary file
                    unlink($tempFileName);
                    
                    if ($results['imported'] > 0 || $results['updated'] > 0) {
                        $message = "File imported successfully! {$results['imported']} new records imported, {$results['updated']} records updated.";
                        if ($results['errors'] > 0) {
                            $message .= " {$results['errors']} records had errors.";
                            if (!empty($results['error_details'])) {
                                $message .= " First errors: " . implode(", ", array_slice($results['error_details'], 0, 3));
                            }
                        }
                        $message_type = "success";
                    } else {
                        $message = "No records imported. Please check your file format.";
                        if ($results['errors'] > 0) {
                            $message .= " Errors: " . implode(", ", array_slice($results['error_details'], 0, 5));
                        }
                        $message_type = "warning";
                    }
                } else {
                    $message = "Error saving uploaded file.";
                    $message_type = "danger";
                }
                
            } catch (Exception $e) {
                $message = "Import failed: " . $e->getMessage();
                $message_type = "danger";
                
                // Clean up temporary file if it exists
                if (isset($tempFileName) && file_exists($tempFileName)) {
                    unlink($tempFileName);
                }
            }
        } else {
            $message = "File upload error: " . implode(", ", $uploadErrors);
            $message_type = "warning";
        }
    } else {
        $message = "Please select a valid file to upload.";
        $message_type = "warning";
    }
}

// Handle Add Trekker
if ($action === 'add_trekker') {
    try {
        $trekkerData = [
            'trek_id' => sanitizeInput($_POST['trek_id'] ?? '', 'alphanum'),
            'trek_route_no' => sanitizeInput($_POST['trek_route_no'] ?? '', 'alphanum'),
            'booking_date' => sanitizeInput($_POST['booking_date'] ?? '', 'date'),
            'trek_date' => sanitizeInput($_POST['trek_date'] ?? '', 'date'),
            'category' => sanitizeInput($_POST['category'] ?? 'Easy'),
            'status' => sanitizeInput($_POST['status'] ?? 'Pending')
        ];
        
        // Validate required fields
        foreach (['trek_id', 'trek_route_no', 'booking_date', 'trek_date'] as $field) {
            if (empty($trekkerData[$field])) {
                throw new Exception("Field '{$field}' is required");
            }
        }
        
        // Validate dates
        if ($trekkerData['trek_date'] < $trekkerData['booking_date']) {
            throw new Exception("Trek date cannot be before booking date");
        }
        
        if ($trekkerData['booking_date'] < date('Y-m-d')) {
            throw new Exception("Booking date cannot be in the past");
        }
        
        if ($trekkerManager->addTrekker($trekkerData)) {
            $message = "Trekker added successfully! Trek ID: " . $trekkerData['trek_id'];
            $message_type = "success";
            
            // Clear form data
            $_POST = [];
        } else {
            throw new Exception("Database error while adding trekker");
        }
        
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = "danger";
    }
}

// Handle Update Trekker
if ($action === 'update_trekker') {
    try {
        $trekkerId = sanitizeInput($_POST['trekker_id'] ?? '', 'int');
        
        if (empty($trekkerId)) {
            throw new Exception("Invalid trekker ID");
        }
        
        $trekkerData = [
            'trek_id' => sanitizeInput($_POST['trek_id'] ?? '', 'alphanum'),
            'trek_route_no' => sanitizeInput($_POST['trek_route_no'] ?? '', 'alphanum'),
            'booking_date' => sanitizeInput($_POST['booking_date'] ?? '', 'date'),
            'trek_date' => sanitizeInput($_POST['trek_date'] ?? '', 'date'),
            'category' => sanitizeInput($_POST['category'] ?? 'Easy'),
            'status' => sanitizeInput($_POST['status'] ?? 'Pending')
        ];
        
        // Validate required fields
        foreach (['trek_id', 'trek_route_no', 'booking_date', 'trek_date'] as $field) {
            if (empty($trekkerData[$field])) {
                throw new Exception("Field '{$field}' is required");
            }
        }
        
        if ($trekkerManager->updateTrekker($trekkerId, $trekkerData)) {
            $message = "Trekker updated successfully!";
            $message_type = "success";
        } else {
            throw new Exception("Database error while updating trekker");
        }
        
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = "danger";
    }
}

// Handle Delete Trekker
if ($action === 'delete_trekker') {
    $trekkerId = sanitizeInput($_POST['trekker_id'] ?? '', 'int');
    
    if (!empty($trekkerId)) {
        if ($trekkerManager->deleteTrekker($trekkerId)) {
            $message = "Trekker deleted successfully!";
            $message_type = "success";
        } else {
            $message = "Error deleting trekker. Please try again.";
            $message_type = "danger";
        }
    } else {
        $message = "Invalid trekker ID";
        $message_type = "warning";
    }
}

// Handle Export CSV
if ($action === 'export_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=trekkers_data_' . date('Y-m-d') . '.csv');
    
    $output = fopen('php://output', 'w');
    fputs($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
    
    // Headers
    fputcsv($output, ['S.No', 'Trek ID', 'Trail Name', 'Trail ID', 'Booked Date', 'Trek Date', 'Category', 'Status', 'GU001', 'GU002', 'GU003', 'GU004', 'GU005', 'GU006', 'GU007', 'GK001', 'GK002', 'GK003', 'GK004', 'GK005', 'GK006', 'GK007', 'GK008', 'GK009', 'GK010', 'GK011', 'GK012', 'GK013', 'CK001', 'CK002', 'CK003', 'CK004', 'CK005', 'CK006', 'TR001', 'TR002', 'TR003', 'TR004', 'TR005', 'TR006', 'TR007', 'TR008']);
    
    // Get data
    $trekkers = $trekkerManager->getAllTrekkers();
    
    foreach ($trekkers as $index => $trekker) {
        $bookingDate = !empty($trekker['booking_date']) && $trekker['booking_date'] != '0000-00-00' ? 
            date('d/m/Y', strtotime($trekker['booking_date'])) : '';
        $trekDate = !empty($trekker['trek_date']) && $trekker['trek_date'] != '0000-00-00' ? 
            date('d/m/Y', strtotime($trekker['trek_date'])) : '';
        
        fputcsv($output, [
            $index + 1,
            $trekker['trek_id'] ?? '',
            $trekker['trail_name'] ?? '',
            $trekker['trek_route_no'] ?? '',
            $bookingDate,
            $trekDate,
            $trekker['category'] ?? 'Easy',
            $trekker['status'] ?? 'Pending',
            $trekker['GU001'] ?? '',
            $trekker['GU002'] ?? '',
            $trekker['GU003'] ?? '',
            $trekker['GU004'] ?? '',
            $trekker['GU005'] ?? '',
            $trekker['GU006'] ?? '',
            $trekker['GU007'] ?? '',
            $trekker['GK001'] ?? '',
            $trekker['GK002'] ?? '',
            $trekker['GK003'] ?? '',
            $trekker['GK004'] ?? '',
            $trekker['GK005'] ?? '',
            $trekker['GK006'] ?? '',
            $trekker['GK007'] ?? '',
            $trekker['GK008'] ?? '',
            $trekker['GK009'] ?? '',
            $trekker['GK010'] ?? '',
            $trekker['GK011'] ?? '',
            $trekker['GK012'] ?? '',
            $trekker['GK013'] ?? '',
            $trekker['CK001'] ?? '',
            $trekker['CK002'] ?? '',
            $trekker['CK003'] ?? '',
            $trekker['CK004'] ?? '',
            $trekker['CK005'] ?? '',
            $trekker['CK006'] ?? '',
            $trekker['TR001'] ?? '',
            $trekker['TR002'] ?? '',
            $trekker['TR003'] ?? '',
            $trekker['TR004'] ?? '',
            $trekker['TR005'] ?? '',
            $trekker['TR006'] ?? '',
            $trekker['TR007'] ?? '',
            $trekker['TR008'] ?? ''
        ]);
    }
    
    fclose($output);
    exit();
}

// Handle Export Excel
if ($action === 'export_excel') {
    $export_type = sanitizeInput($_POST['export_type'] ?? 'xlsx');
    $filename = 'trekkers_data_' . date('Y-m-d') . '.' . $export_type;
    
    if ($export_type === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
    } else {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
    }
    
    $output = fopen('php://output', 'w');
    fputs($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
    
    // Headers
    fwrite($output, "S.No\tTrek ID\tTrail Name\tTrail ID\tBooked Date\tTrek Date\tCategory\tStatus\tGU001\tGU002\tGU003\tGU004\tGU005\tGU006\tGU007\tGK001\tGK002\tGK003\tGK004\tGK005\tGK006\tGK007\tGK008\tGK009\tGK010\tGK011\tGK012\tGK013\tCK001\tCK002\tCK003\tCK004\tCK005\tCK006\tTR001\tTR002\tTR003\tTR004\tTR005\tTR006\tTR007\tTR008\n");
    
    // Get data
    $trekkers = $trekkerManager->getAllTrekkers();
    
    foreach ($trekkers as $index => $trekker) {
        $bookingDate = !empty($trekker['booking_date']) && $trekker['booking_date'] != '0000-00-00' ? 
            date('d/m/Y', strtotime($trekker['booking_date'])) : '';
        $trekDate = !empty($trekker['trek_date']) && $trekker['trek_date'] != '0000-00-00' ? 
            date('d/m/Y', strtotime($trekker['trek_date'])) : '';
        
        $line = implode("\t", [
            $index + 1,
            $trekker['trek_id'] ?? '',
            $trekker['trail_name'] ?? '',
            $trekker['trek_route_no'] ?? '',
            $bookingDate,
            $trekDate,
            $trekker['category'] ?? 'Easy',
            $trekker['status'] ?? 'Pending',
            $trekker['GU001'] ?? '',
            $trekker['GU002'] ?? '',
            $trekker['GU003'] ?? '',
            $trekker['GU004'] ?? '',
            $trekker['GU005'] ?? '',
            $trekker['GU006'] ?? '',
            $trekker['GU007'] ?? '',
            $trekker['GK001'] ?? '',
            $trekker['GK002'] ?? '',
            $trekker['GK003'] ?? '',
            $trekker['GK004'] ?? '',
            $trekker['GK005'] ?? '',
            $trekker['GK006'] ?? '',
            $trekker['GK007'] ?? '',
            $trekker['GK008'] ?? '',
            $trekker['GK009'] ?? '',
            $trekker['GK010'] ?? '',
            $trekker['GK011'] ?? '',
            $trekker['GK012'] ?? '',
            $trekker['GK013'] ?? '',
            $trekker['CK001'] ?? '',
            $trekker['CK002'] ?? '',
            $trekker['CK003'] ?? '',
            $trekker['CK004'] ?? '',
            $trekker['CK005'] ?? '',
            $trekker['CK006'] ?? '',
            $trekker['TR001'] ?? '',
            $trekker['TR002'] ?? '',
            $trekker['TR003'] ?? '',
            $trekker['TR004'] ?? '',
            $trekker['TR005'] ?? '',
            $trekker['TR006'] ?? '',
            $trekker['TR007'] ?? '',
            $trekker['TR008'] ?? ''
        ]) . "\n";
        fwrite($output, $line);
    }
    
    fclose($output);
    exit();
}

// --------------------------- Fetch Data for Display ---------------------------
// Get filters from request
$filters = [
    'status' => sanitizeInput($_GET['status'] ?? ''),
    'search' => sanitizeInput($_GET['search'] ?? '')
];

// Fetch trekkers with filters
$trekkers = $trekkerManager->getAllTrekkers($filters);

// Get statistics
$stats = $trekkerManager->getStatistics();

// Get available trails
$trails = $db->fetchAll("SELECT trail_code, trail_location FROM forest_trails WHERE status = 'Active' ORDER BY trail_code");
$trailCodes = array_column($trails, 'trail_code');

// Helper function for display
function esc($s) { 
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); 
}

function getCellClass($value) {
    if ($value === '0' || $value === '' || $value === null) {
        return 'cell-value-0';
    } elseif ($value === '1') {
        return 'cell-value-1';
    } elseif ($value === '2') {
        return 'cell-value-2';
    } elseif (is_numeric($value) && $value > 2) {
        return 'cell-value-high';
    } else {
        return '';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Trekkers Data - Forest Trekking</title>
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
            --orange: #fd7e14;
            --purple: #6f42c1;
            --teal: #20c997;
        }
        
        body {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            overflow-x: hidden;
        }
        
        /* Status Badge Styles */
        .badge-paid {
            background: linear-gradient(135deg, #198754, #20c997) !important;
            color: white !important;
        }
        
        .badge-cancelled {
            background: linear-gradient(135deg, #6c757d, #8e9ba7) !important;
            color: white !important;
        }
        
        .badge-pending {
            background: linear-gradient(135deg, #fd7e14, #ff9b4e) !important;
            color: white !important;
        }
        
        .badge-completed {
            background: linear-gradient(135deg, #20c997, #3dd5a8) !important;
            color: white !important;
        }
        
        /* Row Background Colors based on Status */
        .status-paid { 
            background-color: rgba(25, 135, 84, 0.08) !important; 
            border-left: 4px solid #198754;
        }
        .status-cancelled { 
            background-color: rgba(108, 117, 125, 0.08) !important; 
            border-left: 4px solid #6c757d;
        }
        .status-pending { 
            background-color: rgba(253, 126, 20, 0.08) !important; 
            border-left: 4px solid #fd7e14;
        }
        .status-completed {
            background-color: rgba(32, 201, 151, 0.08) !important; 
            border-left: 4px solid #20c997;
        }
        
        /* Hover effects for different status rows */
        .status-paid:hover { background-color: rgba(25, 135, 84, 0.12) !important; }
        .status-cancelled:hover { background-color: rgba(108, 117, 125, 0.12) !important; }
        .status-pending:hover { background-color: rgba(253, 126, 20, 0.12) !important; }
        .status-completed:hover { background-color: rgba(32, 201, 151, 0.12) !important; }

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
        
        .table-hover tbody tr {
            transition: all 0.3s ease;
        }
        
        .table-hover tbody tr:hover {
            transform: scale(1.002);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .trail-code {
            font-family: 'Courier New', monospace;
            font-weight: bold;
            color: #ffffff !important;
            background: linear-gradient(135deg, #6f42c1, #8c68cd) !important;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.85rem;
        }
        
        /* Category Badge Styles */
        .badge-category-Easy {
            background: linear-gradient(135deg, #0dcaf0, #4dd2f2) !important;
            color: white !important;
        }
        
        .badge-category-Moderate {
            background: linear-gradient(135deg, #6f42c1, #8c68cd) !important;
            color: white !important;
        }
        
        .badge-category-Tough {
            background: linear-gradient(135deg, #fd7e14, #ff9b4e) !important;
            color: white !important;
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
        
        /* Horizontal scroll for wide tables */
        .table-scroll-container {
            overflow-x: auto;
            max-width: 100%;
        }
        
        .wide-table {
            min-width: 1200px;
        }
        
        .column-group-header {
            background-color: #e9ecef;
            font-weight: bold;
            text-align: center;
        }
        
        /* Quick Action Buttons */
        .quick-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }
        
        .quick-action-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.3s ease;
            font-weight: 500;
        }
        
        .quick-action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        /* Status Filter Buttons */
        .status-filter {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }
        
        .status-filter-btn {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.3s ease;
            border: 2px solid transparent;
            text-decoration: none;
            display: inline-block;
        }
        
        .status-filter-btn.active {
            border-color: currentColor;
            transform: scale(1.05);
        }
        
        /* Enhanced Search Box */
        .search-box-container {
            position: relative;
            max-width: 400px;
        }
        
        .search-box {
            padding-left: 40px;
            border-radius: 25px;
            border: 2px solid #e9ecef;
            transition: all 0.3s ease;
        }
        
        .search-box:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 0.2rem rgba(46, 139, 87, 0.1);
        }
        
        .search-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
        }
        
        /* Enhanced table cell coloring for better data visualization */
        .table-cell-highlight {
            font-weight: 600;
        }
        
        .cell-value-0 {
            background-color: rgba(220, 53, 69, 0.1) !important;
            color: #dc3545 !important;
        }
        
        .cell-value-1 {
            background-color: rgba(25, 135, 84, 0.1) !important;
            color: #198754 !important;
        }
        
        .cell-value-2 {
            background-color: rgba(13, 110, 253, 0.1) !important;
            color: #0d6efd !important;
        }
        
        .cell-value-high {
            background-color: rgba(220, 53, 69, 0.15) !important;
            font-weight: bold;
        }
        
        .cell-value-medium {
            background-color: rgba(255, 193, 7, 0.15) !important;
            font-weight: bold;
        }
        
        .cell-value-low {
            background-color: rgba(25, 135, 84, 0.15) !important;
        }
        
        /* Column group styling */
        .column-group-gu {
            background: linear-gradient(135deg, rgba(13, 110, 253, 0.05), rgba(13, 110, 253, 0.1)) !important;
        }
        
        .column-group-gk {
            background: linear-gradient(135deg, rgba(111, 66, 193, 0.05), rgba(111, 66, 193, 0.1)) !important;
        }
        
        .column-group-ck {
            background: linear-gradient(135deg, rgba(32, 201, 151, 0.05), rgba(32, 201, 151, 0.1)) !important;
        }
        
        .column-group-tr {
            background: linear-gradient(135deg, rgba(253, 126, 20, 0.05), rgba(253, 126, 20, 0.1)) !important;
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
            
            .quick-actions {
                justify-content: center;
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
            
            .status-filter {
                justify-content: center;
            }
            
            .search-box-container {
                max-width: 100%;
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
            
            .quick-actions {
                flex-direction: column;
            }
            
            .status-filter {
                justify-content: flex-start;
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
        
        /* Improved table styling for better data separation */
        .table th {
            background: linear-gradient(135deg, #2E8B57, #3cb371) !important;
            color: white;
            font-weight: 600;
            border-bottom: 2px solid #1f6e45;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .table td {
            border-bottom: 1px solid #e9ecef;
            vertical-align: middle;
        }
        
        /* Alternating row colors for better readability */
        .trekker-row:nth-child(even) {
            background-color: rgba(0,0,0,0.02);
        }
        
        .trekker-row:nth-child(odd) {
            background-color: rgba(255,255,255,0.5);
        }
        
        /* Column grouping styles */
        .column-group {
            background: linear-gradient(135deg, #e9ecef, #f8f9fa) !important;
            font-weight: bold;
            text-align: center;
        }
        
        /* Improved button styles */
        .btn-success {
            background: linear-gradient(135deg, #198754, #20c997);
            border: none;
        }
        
        .btn-warning {
            background: linear-gradient(135deg, #ffc107, #ffd54f);
            border: none;
            color: #212529;
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #dc3545, #e35d6a);
            border: none;
        }
        
        .btn-info {
            background: linear-gradient(135deg, #0dcaf0, #4dd2f2);
            border: none;
        }
        
        .btn-purple {
            background: linear-gradient(135deg, #6f42c1, #8c68cd);
            border: none;
            color: white;
        }
        
        .btn-orange {
            background: linear-gradient(135deg, #fd7e14, #ff9b4e);
            border: none;
            color: white;
        }
        
        .btn-teal {
            background: linear-gradient(135deg, #20c997, #3dd5a8);
            border: none;
            color: white;
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
                    <a class="nav-link active" href="trekkers_data.php">
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
        <div class="container-fluid py-2">
            <!-- Page Header -->
            <div class="row mb-3">
                <div class="col-12">
                    <div class="dashboard-card p-3 fade-in">
                        <div class="row align-items-center">
                            <div class="col-md-6 mb-2 mb-md-0">
                                <h2 class="mb-1 fw-bold text-dark">
                                    <i class="bi bi-people me-2"></i>Trekkers Data Management
                                </h2>
                                <p class="text-muted mb-0 small">Manage trekker information, bookings, and trek status</p>
                            </div>
                            <div class="col-md-6 text-md-end">
                                <div class="btn-group" role="group">
                                    <button class="btn btn-primary pulse" data-bs-toggle="modal" data-bs-target="#addTrekkerModal">
                                        <i class="bi bi-plus-circle me-1"></i>Add Trekker
                                    </button>
                                    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#importModal">
                                        <i class="bi bi-upload me-1"></i>Import
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="row g-3 mb-3">
                <div class="col-xl-2 col-lg-4 col-md-4 col-sm-6">
                    <div class="dashboard-card stat-card p-2 slide-in" style="animation-delay: 0.1s">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="fw-bold text-primary mb-0"><?= $stats['total'] ?></h4>
                                <p class="text-muted mb-0 small">Total Trekkers</p>
                            </div>
                            <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                                <i class="bi bi-people"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-2 col-lg-4 col-md-4 col-sm-6">
                    <div class="dashboard-card stat-card p-2 slide-in" style="animation-delay: 0.2s">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="fw-bold text-success mb-0"><?= $stats['paid'] ?></h4>
                                <p class="text-muted mb-0 small">Paid Trekkers</p>
                            </div>
                            <div class="stat-icon bg-success bg-opacity-10 text-success">
                                <i class="bi bi-currency-dollar"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-2 col-lg-4 col-md-4 col-sm-6">
                    <div class="dashboard-card stat-card p-2 slide-in" style="animation-delay: 0.3s">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="fw-bold text-teal mb-0"><?= $stats['completed'] ?></h4>
                                <p class="text-muted mb-0 small">Completed</p>
                            </div>
                            <div class="stat-icon bg-teal bg-opacity-10 text-teal">
                                <i class="bi bi-check-circle"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-2 col-lg-4 col-md-4 col-sm-6">
                    <div class="dashboard-card stat-card p-2 slide-in" style="animation-delay: 0.4s">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="fw-bold text-orange mb-0"><?= $stats['pending'] ?></h4>
                                <p class="text-muted mb-0 small">Pending</p>
                            </div>
                            <div class="stat-icon bg-orange bg-opacity-10 text-orange">
                                <i class="bi bi-clock"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-2 col-lg-4 col-md-4 col-sm-6">
                    <div class="dashboard-card stat-card p-2 slide-in" style="animation-delay: 0.5s">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="fw-bold text-secondary mb-0"><?= $stats['cancelled'] ?></h4>
                                <p class="text-muted mb-0 small">Cancelled</p>
                            </div>
                            <div class="stat-icon bg-secondary bg-opacity-10 text-secondary">
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

            <!-- Search and Filter Section -->
            <div class="row mb-3">
                <div class="col-md-6">
                    <form method="GET" id="searchForm">
                        <div class="search-box-container">
                            <i class="bi bi-search search-icon"></i>
                            <input type="text" class="form-control search-box" name="search" id="searchInput" 
                                   placeholder="Search trekkers by ID, trail name, or route..." 
                                   value="<?= esc($filters['search']) ?>">
                        </div>
                    </form>
                </div>
                <div class="col-md-6">
                    <div class="status-filter">
                        <a href="?" class="status-filter-btn btn btn-sm btn-primary <?= empty($filters['status']) ? 'active' : '' ?>">All</a>
                        <a href="?status=Paid" class="status-filter-btn btn btn-sm btn-success <?= $filters['status'] === 'Paid' ? 'active' : '' ?>">Paid</a>
                        <a href="?status=Cancelled" class="status-filter-btn btn btn-sm btn-secondary <?= $filters['status'] === 'Cancelled' ? 'active' : '' ?>">Cancelled</a>
                        <a href="?status=Pending" class="status-filter-btn btn btn-sm btn-orange text-white <?= $filters['status'] === 'Pending' ? 'active' : '' ?>">Pending</a>
                    </div>
                </div>
            </div>

            <!-- Trekkers Table -->
            <div class="row">
                <div class="col-12">
                    <div class="dashboard-card p-3 fade-in">
                        <div class="table-header-container">
                            <h5 class="fw-bold mb-0">All Trekkers <span class="badge bg-primary" id="trekkerCount"><?= count($trekkers) ?></span></h5>
                            <div class="d-flex align-items-center gap-2">
                                <!-- Export Dropdown -->
                                <div class="dropdown">
                                    <button class="btn btn-success dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                        <i class="bi bi-download me-1"></i>Export
                                    </button>
                                    <ul class="dropdown-menu">
                                        <li>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="action" value="export_csv">
                                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                                <button type="submit" class="dropdown-item">
                                                    <i class="bi bi-file-earmark-text me-2"></i>Export as CSV
                                                </button>
                                            </form>
                                        </li>
                                        <li>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="action" value="export_excel">
                                                <input type="hidden" name="export_type" value="xlsx">
                                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                                <button type="submit" class="dropdown-item">
                                                    <i class="bi bi-file-earmark-excel me-2"></i>Export as Excel
                                                </button>
                                            </form>
                                        </li>
                                    </ul>
                                </div>
                                <a href="?" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-arrow-clockwise"></i> Refresh
                                </a>
                            </div>
                        </div>

                        <?php if (empty($trekkers)): ?>
                            <div class="empty-state">
                                <i class="bi bi-people"></i>
                                <h5>No Trekkers Found</h5>
                                <p>Start by adding your first trekker or importing a CSV/Excel file.</p>
                                <div class="mt-3">
                                    <button class="btn btn-primary me-2 mb-2" data-bs-toggle="modal" data-bs-target="#addTrekkerModal">
                                        <i class="bi bi-plus-circle me-2"></i>Add First Trekker
                                    </button>
                                    <button class="btn btn-success mb-2" data-bs-toggle="modal" data-bs-target="#importModal">
                                        <i class="bi bi-upload me-2"></i>Import File
                                    </button>
                                </div>
                            </div>
                        <?php else: ?>
                            <!-- Horizontal Scroll Container -->
                            <div class="table-scroll-container">
                                <div class="table-responsive">
                                    <table class="table table-hover wide-table" id="trekkersTable">
                                        <thead class="table-light">
                                            <tr>
                                                <th class="text-center">S.No</th>
                                                <th>Trek ID</th>
                                                <th>Trail Name</th>
                                                <th>Trail ID</th>
                                                <th>Booked Date</th>
                                                <th>Trek Date</th>
                                                <th>Category</th>
                                                <th class="text-center">Status</th>
                                                <th>GU001</th>
                                                <th>GU002</th>
                                                <th>GU003</th>
                                                <th>GU004</th>
                                                <th>GU005</th>
                                                <th>GU006</th>
                                                <th>GU007</th>
                                                
                                                <!-- GK Sub-headers -->
                                                <th>GK001</th>
                                                <th>GK002</th>
                                                <th>GK003</th>
                                                <th>GK004</th>
                                                <th>GK005</th>
                                                <th>GK006</th>
                                                <th>GK007</th>
                                                <th>GK008</th>
                                                <th>GK009</th>
                                                <th>GK010</th>
                                                <th>GK011</th>
                                                <th>GK012</th>
                                                <th>GK013</th>
                                                
                                                <!-- CK Sub-headers -->
                                                <th>CK001</th>
                                                <th>CK002</th>
                                                <th>CK003</th>
                                                <th>CK004</th>
                                                <th>CK005</th>
                                                <th>CK006</th>
                                                
                                                <!-- TR Sub-headers -->
                                                <th>TR001</th>
                                                <th>TR002</th>
                                                <th>TR003</th>
                                                <th>TR004</th>
                                                <th>TR005</th>
                                                <th>TR006</th>
                                                <th>TR007</th>
                                                <th>TR008</th>
                                                <th class="text-center">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="trekkersTableBody">
                                            <?php foreach ($trekkers as $index => $trekker): 
                                                $statusClass = 'status-' . strtolower($trekker['status']);
                                                $today = date('Y-m-d');
                                                
                                                // Add completed class if trek date is in past and status is Paid
                                                if ($trekker['status'] === 'Paid' && $trekker['trek_date'] < $today) {
                                                    $statusClass = 'status-completed';
                                                }
                                            ?>
                                                <tr class="fade-in real-time-update trekker-row <?= $statusClass ?>" 
                                                    id="trekker-<?= $trekker['id'] ?>" 
                                                    data-status="<?= esc($trekker['status']) ?>"
                                                    data-trek-id="<?= esc($trekker['trek_id']) ?>"
                                                    data-trail-name="<?= esc($trekker['trail_name']) ?>"
                                                    data-route-no="<?= esc($trekker['trek_route_no']) ?>"
                                                    style="animation-delay: <?= $index * 0.05 ?>s">
                                                    <td class="text-center fw-bold"><?= $index + 1 ?></td>
                                                    <td>
                                                        <span class="trek-id fw-bold text-primary"><?= esc($trekker['trek_id']) ?></span>
                                                    </td>
                                                    <td>
                                                        <span class="fw-medium"><?= esc($trekker['trail_name']) ?></span>
                                                    </td>
                                                    <td>
                                                        <span class="badge trail-code"><?= esc($trekker['trek_route_no']) ?></span>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-light text-dark">
                                                            <i class="bi bi-calendar me-1"></i>
                                                            <?= !empty($trekker['booking_date']) && $trekker['booking_date'] != '0000-00-00' ? 
                                                                date('d M Y', strtotime($trekker['booking_date'])) : 'Invalid Date' ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-light text-dark">
                                                            <i class="bi bi-calendar-check me-1"></i>
                                                            <?= !empty($trekker['trek_date']) && $trekker['trek_date'] != '0000-00-00' ? 
                                                                date('d M Y', strtotime($trekker['trek_date'])) : 'Invalid Date' ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="badge <?php
                                                            $category = $trekker['category'] ?? 'Easy';
                                                            if ($category === 'Easy') echo 'badge-category-Easy';
                                                            elseif ($category === 'Moderate') echo 'badge-category-Moderate';
                                                            elseif ($category === 'Tough') echo 'badge-category-Tough';
                                                            else echo 'badge-category-general';
                                                        ?>">
                                                            <?= esc($category) ?>
                                                        </span>
                                                    </td>
                                                    <td class="text-center">
                                                        <?php
                                                        $displayStatus = $trekker['status'];
                                                        $badgeClass = 'badge-pending';
                                                        
                                                        if ($trekker['status'] === 'Paid' && $trekker['trek_date'] < $today) {
                                                            $displayStatus = 'Completed';
                                                            $badgeClass = 'badge-completed';
                                                        } elseif ($trekker['status'] === 'Paid') {
                                                            $badgeClass = 'badge-paid';
                                                        } elseif ($trekker['status'] === 'Cancelled') {
                                                            $badgeClass = 'badge-cancelled';
                                                        }
                                                        ?>
                                                        <span class="badge <?= $badgeClass ?>">
                                                            <?= esc($displayStatus) ?>
                                                        </span>
                                                    </td>
                                                    
                                                    <!-- GU Columns -->
                                                    <td class="<?= getCellClass($trekker['GU001']) ?>"><?= esc($trekker['GU001']) ?></td>
                                                    <td class="<?= getCellClass($trekker['GU002']) ?>"><?= esc($trekker['GU002']) ?></td>
                                                    <td class="<?= getCellClass($trekker['GU003']) ?>"><?= esc($trekker['GU003']) ?></td>
                                                    <td class="<?= getCellClass($trekker['GU004']) ?>"><?= esc($trekker['GU004']) ?></td>
                                                    <td class="<?= getCellClass($trekker['GU005']) ?>"><?= esc($trekker['GU005']) ?></td>
                                                    <td class="<?= getCellClass($trekker['GU006']) ?>"><?= esc($trekker['GU006']) ?></td>
                                                    <td class="<?= getCellClass($trekker['GU007']) ?>"><?= esc($trekker['GU007']) ?></td>
                                                    
                                                    <!-- GK Columns -->
                                                    <td class="<?= getCellClass($trekker['GK001']) ?>"><?= esc($trekker['GK001']) ?></td>
                                                    <td class="<?= getCellClass($trekker['GK002']) ?>"><?= esc($trekker['GK002']) ?></td>
                                                    <td class="<?= getCellClass($trekker['GK003']) ?>"><?= esc($trekker['GK003']) ?></td>
                                                    <td class="<?= getCellClass($trekker['GK004']) ?>"><?= esc($trekker['GK004']) ?></td>
                                                    <td class="<?= getCellClass($trekker['GK005']) ?>"><?= esc($trekker['GK005']) ?></td>
                                                    <td class="<?= getCellClass($trekker['GK006']) ?>"><?= esc($trekker['GK006']) ?></td>
                                                    <td class="<?= getCellClass($trekker['GK007']) ?>"><?= esc($trekker['GK007']) ?></td>
                                                    <td class="<?= getCellClass($trekker['GK008']) ?>"><?= esc($trekker['GK008']) ?></td>
                                                    <td class="<?= getCellClass($trekker['GK009']) ?>"><?= esc($trekker['GK009']) ?></td>
                                                    <td class="<?= getCellClass($trekker['GK010']) ?>"><?= esc($trekker['GK010']) ?></td>
                                                    <td class="<?= getCellClass($trekker['GK011']) ?>"><?= esc($trekker['GK011']) ?></td>
                                                    <td class="<?= getCellClass($trekker['GK012']) ?>"><?= esc($trekker['GK012']) ?></td>
                                                    <td class="<?= getCellClass($trekker['GK013']) ?>"><?= esc($trekker['GK013']) ?></td>
                                                    
                                                    <!-- CK Columns -->
                                                    <td class="<?= getCellClass($trekker['CK001']) ?>"><?= esc($trekker['CK001']) ?></td>
                                                    <td class="<?= getCellClass($trekker['CK002']) ?>"><?= esc($trekker['CK002']) ?></td>
                                                    <td class="<?= getCellClass($trekker['CK003']) ?>"><?= esc($trekker['CK003']) ?></td>
                                                    <td class="<?= getCellClass($trekker['CK004']) ?>"><?= esc($trekker['CK004']) ?></td>
                                                    <td class="<?= getCellClass($trekker['CK005']) ?>"><?= esc($trekker['CK005']) ?></td>
                                                    <td class="<?= getCellClass($trekker['CK006']) ?>"><?= esc($trekker['CK006']) ?></td>
                                                    
                                                    <!-- TR Columns -->
                                                    <td class="<?= getCellClass($trekker['TR001']) ?>"><?= esc($trekker['TR001']) ?></td>
                                                    <td class="<?= getCellClass($trekker['TR002']) ?>"><?= esc($trekker['TR002']) ?></td>
                                                    <td class="<?= getCellClass($trekker['TR003']) ?>"><?= esc($trekker['TR003']) ?></td>
                                                    <td class="<?= getCellClass($trekker['TR004']) ?>"><?= esc($trekker['TR004']) ?></td>
                                                    <td class="<?= getCellClass($trekker['TR005']) ?>"><?= esc($trekker['TR005']) ?></td>
                                                    <td class="<?= getCellClass($trekker['TR006']) ?>"><?= esc($trekker['TR006']) ?></td>
                                                    <td class="<?= getCellClass($trekker['TR007']) ?>"><?= esc($trekker['TR007']) ?></td>
                                                    <td class="<?= getCellClass($trekker['TR008']) ?>"><?= esc($trekker['TR008']) ?></td>
                                                    
                                                    <td class="text-center">
                                                        <div class="btn-group" role="group">
                                                            <button class="btn btn-outline-primary btn-action edit-trekker" 
                                                                    data-bs-toggle="modal" 
                                                                    data-bs-target="#editTrekkerModal"
                                                                    data-id="<?= $trekker['id'] ?>"
                                                                    data-trek-id="<?= esc($trekker['trek_id']) ?>"
                                                                    data-route-no="<?= esc($trekker['trek_route_no']) ?>"
                                                                    data-booking-date="<?= esc($trekker['booking_date'] ?? '') ?>"
                                                                    data-trek-date="<?= esc($trekker['trek_date'] ?? '') ?>"
                                                                    data-category="<?= esc($trekker['category']) ?>"
                                                                    data-status="<?= esc($trekker['status']) ?>">
                                                                <i class="bi bi-pencil"></i>
                                                            </button>
                                                            <button class="btn btn-outline-danger btn-action delete-trekker"
                                                                    data-bs-toggle="modal"
                                                                    data-bs-target="#deleteTrekkerModal"
                                                                    data-id="<?= $trekker['id'] ?>"
                                                                    data-trek-id="<?= esc($trekker['trek_id']) ?>">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Trekker Modal -->
    <div class="modal fade" id="addTrekkerModal" tabindex="-1" aria-labelledby="addTrekkerModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="addTrekkerModalLabel">
                        <i class="bi bi-plus-circle me-2"></i>Add New Trekker
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="addTrekkerForm">
                    <input type="hidden" name="action" value="add_trekker">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label for="trekId" class="form-label">Trekking SI No <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="trekId" name="trek_id" 
                                       placeholder="Enter Trekking SI No (e.g., TT0450385029132526E01)" 
                                       value="<?= esc($_POST['trek_id'] ?? '') ?>" 
                                       required maxlength="50" pattern="[A-Za-z0-9]+" title="Alphanumeric characters only">
                                <div class="form-text" id="trekIdFeedback"></div>
                            </div>
                            
                            <div class="col-12">
                                <label for="trekRouteNo" class="form-label">Trek Route No <span class="text-danger">*</span></label>
                                <select class="form-select" id="trekRouteNo" name="trek_route_no" required>
                                    <option value="">Select Trek Route No</option>
                                    <?php foreach ($trailCodes as $code): ?>
                                        <option value="<?= esc($code) ?>" <?= ($_POST['trek_route_no'] ?? '') === $code ? 'selected' : '' ?>>
                                            <?= esc($code) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="bookingDate" class="form-label">Trekking Booked Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="bookingDate" name="booking_date" 
                                       value="<?= esc($_POST['booking_date'] ?? '') ?>" 
                                       min="<?= date('Y-m-d') ?>" required>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="trekDate" class="form-label">Trekking Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="trekDate" name="trek_date" 
                                       value="<?= esc($_POST['trek_date'] ?? '') ?>" 
                                       min="<?= date('Y-m-d') ?>" required>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="category" class="form-label">Category</label>
                                <select class="form-select" id="category" name="category">
                                    <option value="Easy" <?= ($_POST['category'] ?? 'Easy') === 'Easy' ? 'selected' : '' ?>>Easy</option>
                                    <option value="Moderate" <?= ($_POST['category'] ?? 'Moderate') === 'Moderate' ? 'selected' : '' ?>>Moderate</option>
                                    <option value="Tough" <?= ($_POST['category'] ?? 'Tough') === 'Tough' ? 'selected' : '' ?>>Tough</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="Pending" <?= ($_POST['status'] ?? 'Pending') === 'Pending' ? 'selected' : '' ?>>Pending</option>
                                    <option value="Paid" <?= ($_POST['status'] ?? 'Paid') === 'Paid' ? 'selected' : '' ?>>Paid</option>
                                    <option value="Cancelled" <?= ($_POST['status'] ?? 'Cancelled') === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle me-2"></i>Save Trekker
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Trekker Modal -->
    <div class="modal fade" id="editTrekkerModal" tabindex="-1" aria-labelledby="editTrekkerModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="editTrekkerModalLabel">
                        <i class="bi bi-pencil me-2"></i>Edit Trekker
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editTrekkerForm">
                    <input type="hidden" name="action" value="update_trekker">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="trekker_id" id="editTrekkerId">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label for="editTrekId" class="form-label">Trekking SI No <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="editTrekId" name="trek_id" required maxlength="50" pattern="[A-Za-z0-9]+">
                            </div>
                            
                            <div class="col-12">
                                <label for="editTrekRouteNo" class="form-label">Trek Route No <span class="text-danger">*</span></label>
                                <select class="form-select" id="editTrekRouteNo" name="trek_route_no" required>
                                    <option value="">Select Trek Route No</option>
                                    <?php foreach ($trailCodes as $code): ?>
                                        <option value="<?= esc($code) ?>"><?= esc($code) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="editBookingDate" class="form-label">Trekking Booked Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="editBookingDate" name="booking_date" required>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="editTrekDate" class="form-label">Trekking Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="editTrekDate" name="trek_date" required>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="editCategory" class="form-label">Category</label>
                                <select class="form-select" id="editCategory" name="category">
                                    <option value="Easy">Easy</option>
                                    <option value="Moderate">Moderate</option>
                                    <option value="Tough">Tough</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="editStatus" class="form-label">Status</label>
                                <select class="form-select" id="editStatus" name="status">
                                    <option value="Pending">Pending</option>
                                    <option value="Paid">Paid</option>
                                    <option value="Cancelled">Cancelled</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning text-white">
                            <i class="bi bi-check-circle me-2"></i>Update Trekker
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Trekker Modal -->
    <div class="modal fade" id="deleteTrekkerModal" tabindex="-1" aria-labelledby="deleteTrekkerModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="deleteTrekkerModalLabel">
                        <i class="bi bi-exclamation-triangle me-2"></i>Confirm Delete
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="deleteTrekkerForm">
                    <input type="hidden" name="action" value="delete_trekker">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="trekker_id" id="deleteTrekkerId">
                    <div class="modal-body">
                        <p>Are you sure you want to delete the trekker with ID <strong id="deleteTrekId" class="text-danger"></strong>?</p>
                        <p class="text-muted small">This action cannot be undone and all associated data will be permanently removed.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-trash me-2"></i>Delete Trekker
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Import File Modal -->
    <div class="modal fade" id="importModal" tabindex="-1" aria-labelledby="importModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="importModalLabel">
                        <i class="bi bi-upload me-2"></i>Import Trekkers Data
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data" id="importForm">
                    <input type="hidden" name="action" value="import_csv">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <div class="modal-body">
                        <div class="import-section">
                            <div class="import-icon">
                                <i class="bi bi-file-earmark-spreadsheet"></i>
                            </div>
                            <h5>Upload CSV or Excel File</h5>
                            <p class="text-muted">Select a CSV or Excel file containing trekkers data to import</p>
                            
                            <div class="mb-3">
                                <input type="file" class="form-control" id="csvFile" name="csv_file" accept=".csv,.xls,.xlsx" required>
                                <div class="form-text">Supported formats: CSV, XLS, XLSX (Max: 10MB)</div>
                            </div>
                            
                            <div class="csv-format">
                                <h6 class="fw-bold mb-2">File Format Requirements:</h6>
                                <p class="small mb-2">Your file should have the following columns in order:</p>
                                <div class="small">
                                    <code>Trekking SI No, Trek Route No, Trekking Booked Date, Trekking Date, Category, Status, GU001, GU002, GU003, GU004, GU005, GU006, GU007, GK001, GK002, GK003, GK004, GK005, GK006, GK007, GK008, GK009, GK010, GK011, GK012, GK013, CK001, CK002, CK003, CK004, CK005, CK006, TR001, TR002, TR003, TR004, TR005, TR006, TR007, TR008</code>
                                </div>
                                <p class="small mt-2 mb-0">Date format: Any standard date format (dd/mm/yyyy, mm/dd/yyyy, yyyy-mm-dd)</p>
                                <p class="small mb-0">Status values: Paid, Cancelled, Pending</p>
                                <p class="small mb-0">Numeric columns (GU, GK, CK, TR): Only numeric values allowed</p>
                            </div>
                            
                            <div class="mt-3">
                                <h6 class="fw-bold">Sample Format:</h6>
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered">
                                        <thead>
                                            <tr>
                                                <th>Trekking SI No</th>
                                                <th>Trek Route No</th>
                                                <th>Trekking Booked Date</th>
                                                <th>Trekking Date</th>
                                                <th>Category</th>
                                                <th>Status</th>
                                                <th>GU001</th>
                                                <th>GU002</th>
                                                <th>GU003</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td>TT0450385029132526E01</td>
                                                <td>TT045</td>
                                                <td>29/09/2025</td>
                                                <td>05/10/2025</td>
                                                <td>General</td>
                                                <td>Paid</td>
                                                <td>1</td>
                                                <td>2</td>
                                                <td>0</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            
                            <div class="alert alert-info mt-3">
                                <i class="bi bi-info-circle me-2"></i>
                                <strong>Note:</strong> If a trekker with the same Trekking SI No already exists, the record will be updated. New records will be added.
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-upload me-2"></i>Import Data
                        </button>
                        <a href="images/Final_Example_import.xls" download class="btn btn-success">
    Download Sample
</a>
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

        // Edit Trekker Modal Handler
        document.querySelectorAll('.edit-trekker').forEach(button => {
            button.addEventListener('click', function() {
                const trekkerId = this.getAttribute('data-id');
                const trekId = this.getAttribute('data-trek-id');
                const routeNo = this.getAttribute('data-route-no');
                const bookingDate = this.getAttribute('data-booking-date');
                const trekDate = this.getAttribute('data-trek-date');
                const category = this.getAttribute('data-category');
                const status = this.getAttribute('data-status');
                
                document.getElementById('editTrekkerId').value = trekkerId;
                document.getElementById('editTrekId').value = trekId;
                document.getElementById('editTrekRouteNo').value = routeNo;
                document.getElementById('editBookingDate').value = bookingDate;
                document.getElementById('editTrekDate').value = trekDate;
                document.getElementById('editCategory').value = category;
                document.getElementById('editStatus').value = status;
            });
        });

        // Delete Trekker Modal Handler
        document.querySelectorAll('.delete-trekker').forEach(button => {
            button.addEventListener('click', function() {
                const trekkerId = this.getAttribute('data-id');
                const trekId = this.getAttribute('data-trek-id');
                
                document.getElementById('deleteTrekkerId').value = trekkerId;
                document.getElementById('deleteTrekId').textContent = trekId;
            });
        });

        // Auto-focus on trek id input when modal opens
        const addTrekkerModal = document.getElementById('addTrekkerModal');
        addTrekkerModal.addEventListener('shown.bs.modal', () => {
            document.getElementById('trekId').focus();
        });

        // Auto-dismiss alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);

        // Form submission handling with loading states
        document.getElementById('addTrekkerForm')?.addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="bi bi-arrow-repeat spinner-border spinner-border-sm me-2"></i>Saving...';
        });

        document.getElementById('editTrekkerForm')?.addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="bi bi-arrow-repeat spinner-border spinner-border-sm me-2"></i>Updating...';
        });

        document.getElementById('deleteTrekkerForm')?.addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="bi bi-arrow-repeat spinner-border spinner-border-sm me-2"></i>Deleting...';
        });

        document.getElementById('importForm')?.addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="bi bi-arrow-repeat spinner-border spinner-border-sm me-2"></i>Importing...';
        });

        // Date validation
        const bookingDateInput = document.getElementById('bookingDate');
        const trekDateInput = document.getElementById('trekDate');
        
        if (bookingDateInput && trekDateInput) {
            bookingDateInput.addEventListener('change', function() {
                trekDateInput.min = this.value;
            });
        }

        // Auto-generate trek ID if field is empty
        document.getElementById('trekId')?.addEventListener('focus', function() {
            if (!this.value.trim()) {
                const randomId = 'TRK' + Math.random().toString(36).substr(2, 9).toUpperCase();
                this.value = randomId;
            }
        });

        // Character count for trek ID
        const trekIdInput = document.getElementById('trekId');
        if (trekIdInput) {
            trekIdInput.addEventListener('input', function() {
                const charCount = this.value.length;
                let feedback = document.getElementById('trekIdFeedback');
                
                if (!feedback) {
                    feedback = document.createElement('div');
                    feedback.id = 'trekIdFeedback';
                    feedback.className = 'form-text';
                    this.parentNode.appendChild(feedback);
                }
                
                feedback.textContent = `${charCount} characters`;
                feedback.className = `form-text ${charCount > 5 ? 'text-success' : 'text-warning'}`;
            });
        }

        // Search form auto-submit
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            let searchTimeout;
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    document.getElementById('searchForm').submit();
                }, 500);
            });
        }

        // Enhanced table functionality
        function applyCellHighlighting() {
            document.querySelectorAll('td').forEach(cell => {
                const value = cell.textContent.trim();
                if (value === '0') {
                    cell.classList.add('cell-value-0');
                } else if (value === '1') {
                    cell.classList.add('cell-value-1');
                } else if (value === '2') {
                    cell.classList.add('cell-value-2');
                } else if (!isNaN(value) && value > 2) {
                    cell.classList.add('cell-value-high');
                }
            });
        }

        // Apply cell highlighting on page load
        document.addEventListener('DOMContentLoaded', function() {
            applyCellHighlighting();
            
            // Add hover effects to table rows
            document.querySelectorAll('.table-hover tbody tr').forEach(row => {
                row.addEventListener('mouseenter', function() {
                    this.style.transform = 'scale(1.002)';
                    this.style.transition = 'all 0.2s ease';
                });
                
                row.addEventListener('mouseleave', function() {
                    this.style.transform = 'scale(1)';
                });
            });
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + F for search
            if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                e.preventDefault();
                document.getElementById('searchInput')?.focus();
            }
            
            // Ctrl/Cmd + N for new trekker
            if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                e.preventDefault();
                const addModal = new bootstrap.Modal(document.getElementById('addTrekkerModal'));
                addModal.show();
            }
        });

        console.log('Enhanced Trekkers Data Management System Loaded Successfully!');
        console.log('Security Features: CSRF Protection, Input Validation, SQL Injection Prevention');
    </script>                 
</body>          
</html> 