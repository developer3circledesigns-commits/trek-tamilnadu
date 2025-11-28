<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reports - Forest Trekking Management</title>
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
            background: linear-gradient(180deg, #000000ff 0%, #020000ff 100%);
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
        
        /* Report Filter Styles */
        .report-filter-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .report-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
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
            
            .report-actions {
                flex-direction: column;
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
        
        /* Report Summary Styles */
        .report-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .summary-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            padding: 1.5rem;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .summary-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        
        .summary-value {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .summary-label {
            font-size: 1rem;
            color: var(--secondary);
            margin-bottom: 0;
        }
        
        .trend-indicator {
            display: inline-flex;
            align-items: center;
            font-size: 0.85rem;
            font-weight: 600;
            margin-top: 0.5rem;
        }
        
        .trend-up {
            color: var(--success);
        }
        
        .trend-down {
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

        <!-- Reports Content -->
        <div class="container-fluid py-3">
            <!-- Page Header -->
            <div class="row compact-row">
                <div class="col-12">
                    <div class="dashboard-card compact-card">
                        <div class="row align-items-center">
                            <div class="col-md-6 mb-2 mb-md-0">
                                <h4 class="mb-1 fw-bold text-dark">Reports & Analytics</h4>
                                <p class="text-muted mb-0 small-text">Comprehensive insights and performance metrics</p>
                            </div>
                            <div class="col-md-6 text-md-end">
                                <div class="report-actions">
                                    <button class="btn btn-primary">
                                        <i class="bi bi-download me-1"></i> Export PDF
                                    </button>
                                    <button class="btn btn-outline-primary">
                                        <i class="bi bi-file-earmark-excel me-1"></i> Export Excel
                                    </button>
                                    <button class="btn btn-outline-secondary">
                                        <i class="bi bi-printer me-1"></i> Print
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Report Filters -->
            <div class="report-filter-card">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label small-text fw-bold">Date Range</label>
                        <select class="form-select">
                            <option selected>Last 7 Days</option>
                            <option>Last 30 Days</option>
                            <option>Last 3 Months</option>
                            <option>Last 6 Months</option>
                            <option>Last Year</option>
                            <option>Custom Range</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small-text fw-bold">Branch</label>
                        <select class="form-select">
                            <option selected>All Branches</option>
                            <option>Western Ghats</option>
                            <option>Himalayan Base</option>
                            <option>Eastern Trails</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small-text fw-bold">Trail Difficulty</label>
                        <select class="form-select">
                            <option selected>All Levels</option>
                            <option>Beginner</option>
                            <option>Intermediate</option>
                            <option>Advanced</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small-text fw-bold">Report Type</label>
                        <select class="form-select">
                            <option selected>Summary Report</option>
                            <option>Detailed Report</option>
                            <option>Financial Report</option>
                            <option>Inventory Report</option>
                            <option>Booking Report</option>
                        </select>
                    </div>
                    <div class="col-12 text-end">
                        <button class="btn btn-primary">
                            <i class="bi bi-funnel me-1"></i> Apply Filters
                        </button>
                        <button class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-clockwise me-1"></i> Reset
                        </button>
                    </div>
                </div>
            </div>

            <!-- Report Summary Cards -->
            <div class="report-summary">
                <div class="summary-card">
                    <div class="summary-value text-primary">1,248</div>
                    <div class="summary-label">Total Bookings</div>
                    <div class="trend-indicator trend-up">
                        <i class="bi bi-arrow-up-right me-1"></i> 12.5% from last month
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-value text-success">₹2,84,560</div>
                    <div class="summary-label">Total Revenue</div>
                    <div class="trend-indicator trend-up">
                        <i class="bi bi-arrow-up-right me-1"></i> 8.3% from last month
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-value text-warning">892</div>
                    <div class="summary-label">Unique Trekkers</div>
                    <div class="trend-indicator trend-up">
                        <i class="bi bi-arrow-up-right me-1"></i> 5.7% from last month
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-value text-info">94.2%</div>
                    <div class="summary-label">Satisfaction Rate</div>
                    <div class="trend-indicator trend-up">
                        <i class="bi bi-arrow-up-right me-1"></i> 2.1% from last month
                    </div>
                </div>
            </div>

            <!-- Charts Row -->
            <div class="row g-2 compact-row">
                <!-- Revenue Trend Chart -->
                <div class="col-xl-6 col-lg-6">
                    <div class="dashboard-card compact-card">
                        <div class="table-header-container">
                            <h6 class="fw-bold mb-0">Revenue Trend</h6>
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button" class="btn btn-outline-primary active">Monthly</button>
                                <button type="button" class="btn btn-outline-primary">Quarterly</button>
                                <button type="button" class="btn btn-outline-primary">Yearly</button>
                            </div>
                        </div>
                        <div class="chart-container">
                            <canvas id="revenueTrendChart"></canvas>
                        </div>
                    </div>
                </div>
                
                <!-- Booking Distribution Chart -->
                <div class="col-xl-6 col-lg-6">
                    <div class="dashboard-card compact-card">
                        <div class="table-header-container">
                            <h6 class="fw-bold mb-0">Booking Distribution by Trail</h6>
                        </div>
                        <div class="chart-container">
                            <canvas id="bookingDistributionChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Detailed Reports Row -->
            <div class="row g-2 compact-row">
                <!-- Branch Performance -->
                <div class="col-xl-4 col-lg-4">
                    <div class="dashboard-card compact-card h-100">
                        <div class="table-header-container">
                            <h6 class="fw-bold mb-0">Branch Performance</h6>
                        </div>
                        <div class="branch-performance">
                            <div class="branch-card">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="small-text fw-bold">Western Ghats</span>
                                    <span class="badge bg-primary small">₹1,24,500</span>
                                </div>
                                <div class="d-flex justify-content-between small-text text-muted mb-1">
                                    <span>428 bookings</span>
                                    <span>92% satisfaction</span>
                                </div>
                                <div class="progress">
                                    <div class="progress-bar" style="width: 100%"></div>
                                </div>
                            </div>
                            <div class="branch-card">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="small-text fw-bold">Himalayan Base</span>
                                    <span class="badge bg-primary small">₹98,200</span>
                                </div>
                                <div class="d-flex justify-content-between small-text text-muted mb-1">
                                    <span>356 bookings</span>
                                    <span>88% satisfaction</span>
                                </div>
                                <div class="progress">
                                    <div class="progress-bar" style="width: 79%"></div>
                                </div>
                            </div>
                            <div class="branch-card">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="small-text fw-bold">Eastern Trails</span>
                                    <span class="badge bg-primary small">₹61,860</span>
                                </div>
                                <div class="d-flex justify-content-between small-text text-muted mb-1">
                                    <span>208 bookings</span>
                                    <span>95% satisfaction</span>
                                </div>
                                <div class="progress">
                                    <div class="progress-bar" style="width: 50%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Inventory Status -->
                <div class="col-xl-4 col-lg-4">
                    <div class="dashboard-card compact-card h-100">
                        <div class="table-header-container">
                            <h6 class="fw-bold mb-0">Inventory Status</h6>
                            <span class="badge bg-success small">Live</span>
                        </div>
                        <div class="chart-container">
                            <canvas id="inventoryStatusChart"></canvas>
                        </div>
                    </div>
                </div>
                
                <!-- Trekker Demographics -->
                <div class="col-xl-4 col-lg-4">
                    <div class="dashboard-card compact-card h-100">
                        <div class="table-header-container">
                            <h6 class="fw-bold mb-0">Trekker Demographics</h6>
                        </div>
                        <div class="chart-container">
                            <canvas id="trekkerDemographicsChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Detailed Tables Row -->
            <div class="row g-2">
                <!-- Top Performing Trails -->
                <div class="col-lg-6">
                    <div class="dashboard-card compact-card">
                        <div class="table-header-container">
                            <h6 class="fw-bold mb-0">Top Performing Trails</h6>
                            <a href="#" class="btn btn-sm btn-outline-primary">View All</a>
                        </div>
                        <div class="table-responsive" style="max-height: 300px;">
                            <table class="table table-sm table-hover">
                                <thead class="table-light small-text">
                                    <tr>
                                        <th>Trail Name</th>
                                        <th>Difficulty</th>
                                        <th>Bookings</th>
                                        <th>Revenue</th>
                                        <th>Rating</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr class="small-text">
                                        <td>
                                            <div class="fw-bold">Valley of Flowers</div>
                                            <small class="text-muted">Western Ghats</small>
                                        </td>
                                        <td><span class="badge bg-success">Beginner</span></td>
                                        <td>156</td>
                                        <td>₹42,800</td>
                                        <td>
                                            <i class="bi bi-star-fill text-warning"></i>
                                            <i class="bi bi-star-fill text-warning"></i>
                                            <i class="bi bi-star-fill text-warning"></i>
                                            <i class="bi bi-star-fill text-warning"></i>
                                            <i class="bi bi-star-half text-warning"></i>
                                            <small class="text-muted ms-1">4.5</small>
                                        </td>
                                    </tr>
                                    <tr class="small-text">
                                        <td>
                                            <div class="fw-bold">Himalayan Heights</div>
                                            <small class="text-muted">Himalayan Base</small>
                                        </td>
                                        <td><span class="badge bg-warning text-dark">Intermediate</span></td>
                                        <td>128</td>
                                        <td>₹38,400</td>
                                        <td>
                                            <i class="bi bi-star-fill text-warning"></i>
                                            <i class="bi bi-star-fill text-warning"></i>
                                            <i class="bi bi-star-fill text-warning"></i>
                                            <i class="bi bi-star-fill text-warning"></i>
                                            <i class="bi bi-star text-warning"></i>
                                            <small class="text-muted ms-1">4.0</small>
                                        </td>
                                    </tr>
                                    <tr class="small-text">
                                        <td>
                                            <div class="fw-bold">Eastern Ridge</div>
                                            <small class="text-muted">Eastern Trails</small>
                                        </td>
                                        <td><span class="badge bg-danger">Advanced</span></td>
                                        <td>94</td>
                                        <td>₹28,200</td>
                                        <td>
                                            <i class="bi bi-star-fill text-warning"></i>
                                            <i class="bi bi-star-fill text-warning"></i>
                                            <i class="bi bi-star-fill text-warning"></i>
                                            <i class="bi bi-star-fill text-warning"></i>
                                            <i class="bi bi-star-fill text-warning"></i>
                                            <small class="text-muted ms-1">5.0</small>
                                        </td>
                                    </tr>
                                    <tr class="small-text">
                                        <td>
                                            <div class="fw-bold">Forest Canopy Walk</div>
                                            <small class="text-muted">Western Ghats</small>
                                        </td>
                                        <td><span class="badge bg-success">Beginner</span></td>
                                        <td>87</td>
                                        <td>₹17,400</td>
                                        <td>
                                            <i class="bi bi-star-fill text-warning"></i>
                                            <i class="bi bi-star-fill text-warning"></i>
                                            <i class="bi bi-star-fill text-warning"></i>
                                            <i class="bi bi-star-fill text-warning"></i>
                                            <i class="bi bi-star-half text-warning"></i>
                                            <small class="text-muted ms-1">4.5</small>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Financial Summary -->
                <div class="col-lg-6">
                    <div class="dashboard-card compact-card">
                        <div class="table-header-container">
                            <h6 class="fw-bold mb-0">Financial Summary</h6>
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button" class="btn btn-outline-primary active">Income</button>
                                <button type="button" class="btn btn-outline-primary">Expenses</button>
                            </div>
                        </div>
                        <div class="table-responsive" style="max-height: 300px;">
                            <table class="table table-sm table-hover">
                                <thead class="table-light small-text">
                                    <tr>
                                        <th>Category</th>
                                        <th>Amount</th>
                                        <th>% of Total</th>
                                        <th>Trend</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr class="small-text">
                                        <td>
                                            <div class="fw-bold">Trekking Bookings</div>
                                            <small class="text-muted">Primary Income</small>
                                        </td>
                                        <td>₹2,24,500</td>
                                        <td>78.9%</td>
                                        <td>
                                            <span class="trend-indicator trend-up">
                                                <i class="bi bi-arrow-up-right me-1"></i> 12.5%
                                            </span>
                                        </td>
                                    </tr>
                                    <tr class="small-text">
                                        <td>
                                            <div class="fw-bold">Equipment Rental</div>
                                            <small class="text-muted">Additional Services</small>
                                        </td>
                                        <td>₹38,200</td>
                                        <td>13.4%</td>
                                        <td>
                                            <span class="trend-indicator trend-up">
                                                <i class="bi bi-arrow-up-right me-1"></i> 8.3%
                                            </span>
                                        </td>
                                    </tr>
                                    <tr class="small-text">
                                        <td>
                                            <div class="fw-bold">Food & Beverages</div>
                                            <small class="text-muted">On-site Sales</small>
                                        </td>
                                        <td>₹21,860</td>
                                        <td>7.7%</td>
                                        <td>
                                            <span class="trend-indicator trend-up">
                                                <i class="bi bi-arrow-up-right me-1"></i> 5.7%
                                            </span>
                                        </td>
                                    </tr>
                                    <tr class="small-text">
                                        <td class="fw-bold">Total Revenue</td>
                                        <td class="fw-bold">₹2,84,560</td>
                                        <td class="fw-bold">100%</td>
                                        <td>
                                            <span class="trend-indicator trend-up">
                                                <i class="bi bi-arrow-up-right me-1"></i> 10.2%
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
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

        // Revenue Trend Chart
        const revenueCtx = document.getElementById('revenueTrendChart').getContext('2d');
        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        const revenueData = [42000, 48000, 52000, 61000, 72000, 85000, 92000, 88000, 78000, 82000, 95000, 112000];

        new Chart(revenueCtx, {
            type: 'line',
            data: {
                labels: months,
                datasets: [{
                    label: 'Monthly Revenue (₹)',
                    data: revenueData,
                    borderColor: '#2E8B57',
                    backgroundColor: 'rgba(46, 139, 87, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        grid: {
                            color: 'rgba(0,0,0,0.1)'
                        },
                        ticks: {
                            callback: function(value) {
                                return '₹' + (value / 1000) + 'k';
                            }
                        }
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
                        },
                        callbacks: {
                            label: function(context) {
                                return 'Revenue: ₹' + context.parsed.y.toLocaleString('en-IN');
                            }
                        }
                    }
                }
            }
        });

        // Booking Distribution Chart
        const bookingCtx = document.getElementById('bookingDistributionChart').getContext('2d');
        new Chart(bookingCtx, {
            type: 'doughnut',
            data: {
                labels: ['Valley of Flowers', 'Himalayan Heights', 'Eastern Ridge', 'Forest Canopy', 'Mountain Pass'],
                datasets: [{
                    data: [25, 20, 15, 12, 8],
                    backgroundColor: [
                        '#2E8B57',
                        '#3CB371',
                        '#20B2AA',
                        '#4682B4',
                        '#6A5ACD'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            boxWidth: 12,
                            font: {
                                size: 11
                            }
                        }
                    }
                }
            }
        });

        // Inventory Status Chart
        const inventoryCtx = document.getElementById('inventoryStatusChart').getContext('2d');
        new Chart(inventoryCtx, {
            type: 'bar',
            data: {
                labels: ['Trekking Poles', 'First Aid', 'Water Bottles', 'Energy Bars', 'Tents', 'Sleeping Bags'],
                datasets: [{
                    label: 'Current Stock',
                    data: [24, 8, 42, 12, 18, 15],
                    backgroundColor: '#2E8B57',
                    borderWidth: 0
                }, {
                    label: 'Max Capacity',
                    data: [30, 15, 50, 25, 25, 20],
                    backgroundColor: 'rgba(46, 139, 87, 0.2)',
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        grid: {
                            color: 'rgba(0,0,0,0.1)'
                        }
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
                    }
                }
            }
        });

        // Trekker Demographics Chart
        const demographicsCtx = document.getElementById('trekkerDemographicsChart').getContext('2d');
        new Chart(demographicsCtx, {
            type: 'polarArea',
            data: {
                labels: ['18-25 Years', '26-35 Years', '36-45 Years', '46-55 Years', '55+ Years'],
                datasets: [{
                    data: [25, 40, 20, 10, 5],
                    backgroundColor: [
                        '#2E8B57',
                        '#3CB371',
                        '#20B2AA',
                        '#4682B4',
                        '#6A5ACD'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            boxWidth: 12,
                            font: {
                                size: 11
                            }
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>