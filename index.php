<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard - Forest Trekking Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
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
            background: linear-gradient(180deg, #070001ff 0%, #000003ff 100%);
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
            transition: all 0.3s ease;
            height: 100%;
        }
        
        .dashboard-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0,0,0,0.12);
        }
        
        .kpi-card {
            border-left: 4px solid var(--primary);
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
        }
        
        .kpi-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }
        
        /* Filter Styles */
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
        
        /* Chart Styles */
        .chart-container {
            position: relative;
            height: 280px;
            width: 100%;
        }
        
        /* Table Styles */
        .table-responsive {
            border-radius: 8px;
        }
        
        .table > :not(caption) > * > * {
            padding: 0.75rem 0.5rem;
        }
        
        .inventory-item {
            border-left: 3px solid var(--primary);
            transition: all 0.3s ease;
        }
        
        .inventory-item:hover {
            background-color: #f8f9fa;
        }
        
        /* Branch Performance */
        .branch-card {
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 10px;
            background: white;
            box-shadow: 0 1px 4px rgba(0,0,0,0.05);
            border-left: 3px solid var(--primary);
        }
        
        .progress {
            height: 6px;
            border-radius: 10px;
        }
        
        /* Alert Styles */
        .alert {
            border-radius: 8px;
            border: none;
            padding: 12px 16px;
        }
        
        /* Table Header Alignment */
        .table-header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        /* Responsive Design */
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
        }
        
        @media (max-width: 1400px) {
            .chart-container {
                height: 250px;
            }
        }
        
        @media (max-width: 1200px) {
            .kpi-icon {
                width: 45px;
                height: 45px;
                font-size: 18px;
            }
            
            .chart-container {
                height: 220px;
            }
        }
        
        @media (max-width: 768px) {
            .dashboard-card {
                margin-bottom: 1rem;
            }
            
            .chart-container {
                height: 200px;
            }
            
            .filter-btn {
                padding: 5px 12px;
                font-size: 0.85rem;
            }
            
            .kpi-icon {
                width: 40px;
                height: 40px;
                font-size: 16px;
            }
            
            .table-header-container {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
        }
        
        @media (max-width: 576px) {
            .main-content {
                padding: 10px;
            }
            
            .dashboard-card {
                border-radius: 8px;
                margin-bottom: 0.75rem;
            }
            
            .chart-container {
                height: 180px;
            }
            
            .filter-btn {
                padding: 4px 10px;
                font-size: 0.8rem;
                margin: 2px;
            }
            
            .btn-group {
                flex-wrap: wrap;
                justify-content: center;
            }
        }
        
        @media (max-width: 400px) {
            .kpi-icon {
                width: 35px;
                height: 35px;
                font-size: 14px;
            }
            
            .chart-container {
                height: 160px;
            }
        }
        
        /* Compact spacing */
        .compact-row {
            margin-bottom: 0.5rem;
        }
        
        .compact-card {
            padding: 1rem;
        }
        
        .small-text {
            font-size: 0.85rem;
        }
        
        /* Mobile Menu Button */
        .mobile-menu-btn {
            display: none;
            background: none;
            border: none;
            color: var(--dark);
            font-size: 1.2rem;
        }
        
        /* Database Status Styles */
        .db-status {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 1000;
        }
        
        .status-badge {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
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
                    <a class="nav-link active" href="index.php">
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

        <!-- Dashboard Content -->
        <div class="container-fluid py-3">
            <!-- Database Status Indicator -->
            <?php
            require_once 'config/database.php';
            
            $dbStatus = 'danger';
            $dbMessage = 'Database Disconnected';
            $tableCount = 0;
            $tables = [];
            
            try {
                $database = new Database();
                $db = $database->getConnection();
                
                if ($db) {
                    $dbStatus = 'success';
                    $dbMessage = 'Database Connected';
                    
                    // Get table count
                    $stmt = $db->query("SHOW TABLES");
                    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    $tableCount = count($tables);
                }
            } catch (Exception $e) {
                $dbMessage = 'Connection Failed: ' . $e->getMessage();
            }
            ?>
            
            <!-- Header with Filter -->
            <div class="row compact-row">
                <div class="col-12">
                    <div class="dashboard-card compact-card">
                        <div class="row align-items-center">
                            <div class="col-md-6 mb-2 mb-md-0">
                                <h4 class="mb-1 fw-bold text-dark">Dashboard Overview</h4>
                                <p class="text-muted mb-0 small-text">Real-time insights and analytics</p>
                            </div>
                            <div class="col-md-6 text-md-end">
                                <div class="btn-group" role="group">
                                    <a href="?filter=daily" class="btn filter-btn active">
                                        Daily
                                    </a>
                                    <a href="?filter=weekly" class="btn filter-btn btn-outline-primary">
                                        Weekly
                                    </a>
                                    <a href="?filter=monthly" class="btn filter-btn btn-outline-primary">
                                        Monthly
                                    </a>
                                    <a href="?filter=yearly" class="btn filter-btn btn-outline-primary">
                                        Yearly
                                    </a>
                                </div>
                                <div class="mt-1">
                                    <span class="text-muted small">Period: <strong>Today</strong></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- KPI Cards -->
            <div class="row g-2 compact-row">
                <div class="col-xl-3 col-lg-6 col-md-6">
                    <div class="dashboard-card kpi-card compact-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="fw-bold text-primary mb-1">24</h4>
                                <p class="text-muted mb-0 small-text">Confirmed Bookings</p>
                            </div>
                            <div class="kpi-icon bg-primary bg-opacity-10 text-primary">
                                <i class="bi bi-calendar-check"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-lg-6 col-md-6">
                    <div class="dashboard-card kpi-card compact-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="fw-bold text-success mb-1">18</h4>
                                <p class="text-muted mb-0 small-text">Unique Trekkers</p>
                            </div>
                            <div class="kpi-icon bg-success bg-opacity-10 text-success">
                                <i class="bi bi-people"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-lg-6 col-md-6">
                    <div class="dashboard-card kpi-card compact-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="fw-bold text-warning mb-1">₹12,450</h4>
                                <p class="text-muted mb-0 small-text">Total Revenue</p>
                            </div>
                            <div class="kpi-icon bg-warning bg-opacity-10 text-warning">
                                <i class="bi bi-currency-rupee"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-lg-6 col-md-6">
                    <div class="dashboard-card kpi-card compact-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="fw-bold text-info mb-1"><?php echo $tableCount; ?></h4>
                                <p class="text-muted mb-0 small-text">Database Tables</p>
                            </div>
                            <div class="kpi-icon bg-info bg-opacity-10 text-info">
                                <i class="bi bi-database"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts & Performance Row -->
            <div class="row g-2 compact-row">
                <!-- Revenue Chart -->
                <div class="col-xl-8 col-lg-7">
                    <div class="dashboard-card compact-card">
                        <div class="table-header-container">
                            <h6 class="fw-bold mb-0">Revenue & Bookings Trend</h6>
                        </div>
                        <div class="chart-container">
                            <canvas id="revenueChart"></canvas>
                        </div>
                    </div>
                </div>
                
                <!-- Branch Performance -->
                <div class="col-xl-4 col-lg-5">
                    <div class="dashboard-card compact-card h-100">
                        <div class="table-header-container">
                            <h6 class="fw-bold mb-0">Branch Performance</h6>
                        </div>
                        <div class="branch-performance">
                            <div class="branch-card">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="small-text fw-bold"> Gudiyam Caves</span>
                                    <span class="badge bg-primary small">₹8,450</span>
                                </div>
                                <div class="d-flex justify-content-between small-text text-muted mb-1">
                                    <span>14 bookings</span>
                                </div>
                                <div class="progress">
                                    <div class="progress-bar" style="width: 100%"></div>
                                </div>
                            </div>
                            <div class="branch-card">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="small-text fw-bold"> Yelagiri - Swamimalai</span>
                                    <span class="badge bg-primary small">₹6,200</span>
                                </div>
                                <div class="d-flex justify-content-between small-text text-muted mb-1">
                                    <span>10 bookings</span>
                                </div>
                                <div class="progress">
                                    <div class="progress-bar" style="width: 73%"></div>
                                </div>
                            </div>
                            <div class="branch-card">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="small-text fw-bold">Jalagamparai</span>
                                    <span class="badge bg-primary small">₹4,800</span>
                                </div>
                                <div class="d-flex justify-content-between small-text text-muted mb-1">
                                    <span>8 bookings</span>
                                </div>
                                <div class="progress">
                                    <div class="progress-bar" style="width: 57%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Inventory & Bookings Row -->
            <div class="row g-2">
                <!-- Inventory Overview -->
                <div class="col-lg-6">
                    <div class="dashboard-card compact-card">
                        <div class="table-header-container">
                            <h6 class="fw-bold mb-0">Inventory Dashboard</h6>
                            <span class="badge bg-success small">Live</span>
                        </div>
                        <div class="table-responsive" style="max-height: 300px;">
                            <table class="table table-sm table-hover">
                                <thead class="table-light small-text">
                                    <tr>
                                        <th>Item</th>
                                        <th>Branch</th>
                                        <th>Stock</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr class="inventory-item small-text">
                                        <td>
                                            <div class="fw-bold">Trekking Poles</div>
                                            <small class="text-muted">Equipment</small>
                                        </td>
                                        <td>Gudiyam Caves</td>
                                        <td>
                                            <span class="fw-bold">24</span>
                                            <small class="text-muted">/30</small>
                                        </td>
                                        <td>
                                            <span class="badge bg-success small">Good</span>
                                        </td>
                                    </tr>
                                    <tr class="inventory-item small-text">
                                        <td>
                                            <div class="fw-bold">First Aid Kit</div>
                                            <small class="text-muted">Medical</small>
                                        </td>
                                        <td>Yelagiri - Swamimalai</td>
                                        <td>
                                            <span class="fw-bold">8</span>
                                            <small class="text-muted">/15</small>
                                        </td>
                                        <td>
                                            <span class="badge bg-warning text-dark small">Low</span>
                                        </td>
                                    </tr>
                                    <tr class="inventory-item small-text">
                                        <td>
                                            <div class="fw-bold">Water Bottles</div>
                                            <small class="text-muted">Supplies</small>
                                        </td>
                                        <td>Jalagamparai</td>
                                        <td>
                                            <span class="fw-bold">42</span>
                                            <small class="text-muted">/50</small>
                                        </td>
                                        <td>
                                            <span class="badge bg-success small">Good</span>
                                        </td>
                                    </tr>
                                    <tr class="inventory-item small-text">
                                        <td>
                                            <div class="fw-bold">Energy Bars</div>
                                            <small class="text-muted">Food</small>
                                        </td>
                                        <td>Solar Observatory - Gundar Zero Point</td>
                                        <td>
                                            <span class="fw-bold">12</span>
                                            <small class="text-muted">/25</small>
                                        </td>
                                        <td>
                                            <span class="badge bg-warning text-dark small">Low</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Recent Bookings & Alerts -->
                <div class="col-lg-6">
                    <!-- Recent Bookings -->
                    <div class="dashboard-card compact-card mb-2">
                        <div class="table-header-container">
                            <h6 class="fw-bold mb-0">Recent Bookings</h6>
                        </div>
                        <div class="table-responsive" style="max-height: 180px;">
                            <table class="table table-sm table-hover">
                                <thead class="table-light small-text">
                                    <tr>
                                        <th>Booking ID</th>
                                        <th>Trekker</th>
                                        <th>Date</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr class="small-text">
                                        <td><strong>TRK-00124</strong></td>
                                        <td>Rajesh Kumar</td>
                                        <td>2023-11-15</td>
                                        <td>
                                            <span class="badge bg-success small">Confirmed</span>
                                        </td>
                                    </tr>
                                    <tr class="small-text">
                                        <td><strong>TRK-00123</strong></td>
                                        <td>Priya Sharma</td>
                                        <td>2023-11-14</td>
                                        <td>
                                            <span class="badge bg-success small">Confirmed</span>
                                        </td>
                                    </tr>
                                    <tr class="small-text">
                                        <td><strong>TRK-00122</strong></td>
                                        <td>Anil Patel</td>
                                        <td>2023-11-14</td>
                                        <td>
                                            <span class="badge bg-warning small">Pending</span>
                                        </td>
                                    </tr>
                                    <tr class="small-text">
                                        <td><strong>TRK-00121</strong></td>
                                        <td>Meera Singh</td>
                                        <td>2023-11-13</td>
                                        <td>
                                            <span class="badge bg-success small">Confirmed</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Low Stock Alerts -->
                    <div class="dashboard-card compact-card">
                        <div class="table-header-container">
                            <h6 class="fw-bold mb-0 text-danger">
                                <i class="bi bi-exclamation-triangle"></i> Low Stock Alerts
                            </h6>
                        </div>
                        <div class="alert-list">
                            <div class="alert alert-warning alert-dismissible fade show mb-1 py-2 small-text" role="alert">
                                <strong>First Aid Kit</strong> 
                                at Guthirayan Peak 
                                - Only 8 left
                                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert"></button>
                            </div>
                            <div class="alert alert-warning alert-dismissible fade show mb-1 py-2 small-text" role="alert">
                                <strong>Energy Bars</strong> 
                                at  Vattakanal - Vellagavi
                                - Only 12 left
                                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert"></button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Database Information Section -->
            <div class="row g-2 mt-2">
                <div class="col-12">
                    <div class="dashboard-card compact-card">
                        <div class="table-header-container">
                            <h6 class="fw-bold mb-0">System Information</h6>
                            <span class="badge bg-<?php echo $dbStatus; ?> small"><?php echo $dbMessage; ?></span>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="fw-bold text-primary mb-2">Available Pages:</h6>
                                <div class="row">
                                    <?php
                                    $pages = [
                                        'category.php' => 'Categories',
                                        'items.php' => 'Items', 
                                        'trails.php' => 'Forest Trails',
                                        'trekkers_data.php' => 'Trekkers Data',
                                        'purchase_order.php' => 'Purchase Orders',
                                        'stock_transfer.php' => 'Stock Transfer',
                                        'reports.php' => 'Reports',
                                        'supplier.php' => 'Suppliers',
                                        'food_menu.php' => 'Food Menu',
                                        'order_status.php' => 'Order Status',
                                        'live_stocks.php' => 'Live Stocks'
                                    ];
                                    
                                    $chunkSize = ceil(count($pages) / 2);
                                    $pageChunks = array_chunk($pages, $chunkSize, true);
                                    
                                    foreach ($pageChunks as $chunk) {
                                        echo '<div class="col-sm-6">';
                                        echo '<ul class="list-unstyled small-text">';
                                        foreach ($chunk as $file => $name) {
                                            $fileExists = file_exists($file);
                                            $badgeClass = $fileExists ? 'bg-success' : 'bg-secondary';
                                            $badgeText = $fileExists ? 'Live' : 'Missing';
                                            
                                            echo '<li class="mb-1">';
                                            echo '<a href="' . $file . '" class="text-decoration-none">' . $name . '</a>';
                                            echo ' <span class="badge ' . $badgeClass . ' small">' . $badgeText . '</span>';
                                            echo '</li>';
                                        }
                                        echo '</ul>';
                                        echo '</div>';
                                    }
                                    ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h6 class="fw-bold text-success mb-2">Database Tables (<?php echo $tableCount; ?>):</h6>
                                <?php if ($tableCount > 0): ?>
                                    <div class="row">
                                        <?php
                                        $tableChunks = array_chunk($tables, ceil(count($tables) / 2));
                                        foreach ($tableChunks as $chunk) {
                                            echo '<div class="col-sm-6">';
                                            echo '<ul class="list-unstyled small-text">';
                                            foreach ($chunk as $table) {
                                                echo '<li class="mb-1">';
                                                echo '<i class="bi bi-table text-primary me-1"></i>';
                                                echo htmlspecialchars($table);
                                                echo '</li>';
                                            }
                                            echo '</ul>';
                                            echo '</div>';
                                        }
                                        ?>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted small-text">No tables found in database</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Database Status Badge -->
    <div class="db-status">
        <span class="status-badge bg-<?php echo $dbStatus; ?> text-white">
            <i class="bi bi-database me-1"></i>
            <?php echo $dbMessage; ?>
            <?php if ($tableCount > 0): ?>
                <span class="ms-1">(<?php echo $tableCount; ?> tables)</span>
            <?php endif; ?>
        </span>
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

        // Revenue Chart
        const ctx = document.getElementById('revenueChart').getContext('2d');
        const labels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        const revenueData = [3200, 4500, 3800, 5200, 6100, 7800, 8450];
        const bookingData = [8, 12, 10, 14, 16, 20, 24];

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Revenue (₹)',
                        data: revenueData,
                        borderColor: '#2E8B57',
                        backgroundColor: 'rgba(46, 139, 87, 0.1)',
                        tension: 0.4,
                        fill: true,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Bookings',
                        data: bookingData,
                        borderColor: '#FF6B6B',
                        backgroundColor: 'rgba(255, 107, 107, 0.1)',
                        tension: 0.4,
                        fill: true,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            maxRotation: 45,
                            minRotation: 45
                        }
                    },
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        grid: {
                            color: 'rgba(0,0,0,0.1)'
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        grid: {
                            drawOnChartArea: false,
                        },
                    }
                },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            boxWidth: 12,
                            font: {
                                size: 11
                            }
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0,0,0,0.8)',
                        titleFont: {
                            size: 11
                        },
                        bodyFont: {
                            size: 11
                        }
                    }
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
    </script>
</body>
</html>