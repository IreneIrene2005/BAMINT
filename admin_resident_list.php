<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "admin") {
    header("location: index.php?role=admin");
    exit;
}

require_once "db/database.php";

$filter_room = $_GET['room'] ?? '';
$filter_status = $_GET['status'] ?? 'all';
$search_query = $_GET['search'] ?? '';
$residents = [];
$rooms = [];
$stats = [];
$error_msg = "";

try {
    // Get all residents (tenants and co-tenants combined)
    $query = "
        SELECT 
            t.id,
            t.name,
            t.email,
            t.phone,
            t.status as tenant_status,
            t.start_date,
            r.id as room_id,
            r.room_number,
            r.room_type,
            r.rate,
            'Primary' as resident_type,
            t.id as resident_id
        FROM tenants t
        LEFT JOIN rooms r ON t.room_id = r.id
        WHERE 1=1
        
        UNION ALL
        
        SELECT 
            ct.id,
            ct.name,
            ct.email,
            ct.phone,
            t.status as tenant_status,
            t.start_date,
            r.id as room_id,
            r.room_number,
            r.room_type,
            r.rate,
            'Co-tenant' as resident_type,
            ct.id as resident_id
        FROM co_tenants ct
        JOIN tenants t ON ct.primary_tenant_id = t.id
        LEFT JOIN rooms r ON ct.room_id = r.id
        WHERE 1=1
    ";

    $params = [];

    // Split the query into two parts for easier manipulation
    $parts = explode("UNION ALL", $query);
    $primary_query = $parts[0];
    $co_tenant_query = $parts[1];

    // Build WHERE conditions
    $where_conditions = [];
    
    // Apply room filter
    if ($filter_room) {
        $where_conditions[] = "CAST(r.room_number AS CHAR) = :room";
        $params['room'] = trim($filter_room);
    }
    
    // Apply status filter
    if ($filter_status !== 'all') {
        $where_conditions[] = "t.status = :status";
        $params['status'] = $filter_status;
    }

    // Apply search filter
    if (!empty($search_query)) {
        $params['search'] = "%$search_query%";
    }

    // Build the WHERE clause
    if (!empty($where_conditions)) {
        $where_clause = "WHERE " . implode(" AND ", $where_conditions);
        
        $primary_query = str_replace("WHERE 1=1", $where_clause, $primary_query);
        $co_tenant_query = str_replace("WHERE 1=1", $where_clause, $co_tenant_query);
        
        // Add search conditions separately since they're different for each query
        if (!empty($search_query)) {
            $primary_query .= " AND (t.name LIKE :search OR t.email LIKE :search OR t.phone LIKE :search)";
            $co_tenant_query .= " AND (ct.name LIKE :search OR ct.email LIKE :search OR ct.phone LIKE :search)";
        }
    } else {
        // No filters, just remove WHERE 1=1 placeholder
        $primary_query = str_replace("WHERE 1=1", "WHERE 1=1", $primary_query);
        $co_tenant_query = str_replace("WHERE 1=1", "WHERE 1=1", $co_tenant_query);
        
        // Add search if provided
        if (!empty($search_query)) {
            $primary_query .= " AND (t.name LIKE :search OR t.email LIKE :search OR t.phone LIKE :search)";
            $co_tenant_query .= " AND (ct.name LIKE :search OR ct.email LIKE :search OR ct.phone LIKE :search)";
        }
    }

    // Recombine the queries
    $query = $primary_query . " UNION ALL " . $co_tenant_query;
    $query .= " ORDER BY room_number ASC, resident_type ASC";

    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $residents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get room list for filter dropdown
    $rooms_query = "SELECT DISTINCT room_number FROM rooms WHERE room_number IS NOT NULL ORDER BY room_number ASC";
    $rooms_stmt = $conn->query($rooms_query);
    $rooms = $rooms_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get statistics
    $stats_query = "
        SELECT 
            (SELECT COUNT(*) FROM tenants) as total_tenants,
            (SELECT COUNT(*) FROM co_tenants) as total_co_tenants,
            (SELECT COUNT(*) FROM tenants WHERE status = 'active') as active_tenants
    ";
    $stats_result = $conn->query($stats_query);
    $stats = $stats_result->fetch(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $error_msg = "Error loading residents: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resident List - BAMINT Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .stat-card {
            background: white;
            border: none;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
        }
        .stat-card:hover { transform: translateY(-2px); }
        .stat-value { font-size: 1.75rem; font-weight: 700; }
        .resident-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .primary-badge {
            background: #dbeafe;
            color: #1e40af;
        }
        .co-tenant-badge {
            background: #dcfce7;
            color: #166534;
        }
        .status-active {
            color: #10b981;
            font-weight: 600;
        }
        .status-inactive {
            color: #ef4444;
            font-weight: 600;
        }
        .resident-table tbody tr {
            transition: background-color 0.2s ease;
        }
        .resident-table tbody tr:hover {
            background-color: #f3f4f6;
        }
    </style>
</head>
<body>
    <?php include 'templates/header.php'; ?>
    <div class="container-fluid">
        <div class="row">
            <?php include 'templates/sidebar.php'; ?>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <!-- Page Header -->
                <div class="mb-4">
                    <h1 class="mb-1"><i class="bi bi-people-fill"></i> Resident List</h1>
                    <p class="text-muted">View all tenants and co-tenants in the boarding house</p>
                </div>

                <?php if ($error_msg): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-circle"></i> <?php echo htmlspecialchars($error_msg); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- Statistics Cards -->
                <div class="row g-3 mb-4">
                    <div class="col-md-6 col-lg-3">
                        <div class="card stat-card">
                            <div class="card-body">
                                <p class="text-muted mb-2">Total Residents</p>
                                <div class="stat-value text-primary"><?php echo (($stats['total_tenants'] ?? 0) + ($stats['total_co_tenants'] ?? 0)); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card mb-4">
                    <div class="card-header bg-primary bg-opacity-10">
                        <h6 class="mb-0"><i class="bi bi-funnel"></i> Filters</h6>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Search (Name, Email, Phone)</label>
                                <input type="text" class="form-control" name="search" placeholder="Enter search term..." 
                                       value="<?php echo htmlspecialchars($search_query); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Room</label>
                                <select class="form-control" name="room">
                                    <option value="">All Rooms</option>
                                    <?php foreach ($rooms as $room): ?>
                                        <option value="<?php echo htmlspecialchars($room['room_number']); ?>"
                                            <?php echo ($filter_room === $room['room_number']) ? 'selected' : ''; ?>>
                                            Room <?php echo htmlspecialchars($room['room_number']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Status</label>
                                <select class="form-control" name="status">
                                    <option value="all">All</option>
                                    <option value="active" <?php echo ($filter_status === 'active') ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo ($filter_status === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">&nbsp;</label>
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-search"></i> Search
                                </button>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">&nbsp;</label>
                                <a href="admin_resident_list.php" class="btn btn-secondary w-100">
                                    <i class="bi bi-arrow-clockwise"></i> Reset
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Residents Table -->
                <div class="card">
                    <div class="card-header bg-primary bg-opacity-10">
                        <h6 class="mb-0"><i class="bi bi-list-ul"></i> Residents (<?php echo count($residents); ?>)</h6>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover resident-table mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Type</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Room</th>
                                    <th>Status</th>
                                    <th>Start Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($residents)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-4 text-muted">
                                            <i class="bi bi-inbox"></i> No residents found
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($residents as $resident): ?>
                                        <tr>
                                            <td>
                                                <?php if ($resident['resident_type'] === 'Primary'): ?>
                                                    <span class="resident-badge primary-badge">Primary</span>
                                                <?php else: ?>
                                                    <span class="resident-badge co-tenant-badge">Co-tenant</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($resident['name']); ?></strong>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($resident['email'] ?? 'N/A'); ?>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($resident['phone'] ?? 'N/A'); ?>
                                            </td>
                                            <td>
                                                <?php if ($resident['room_number']): ?>
                                                    <strong><?php echo htmlspecialchars($resident['room_number']); ?></strong>
                                                    <br>
                                                    <small class="text-muted"><?php echo htmlspecialchars($resident['room_type']); ?></small>
                                                <?php else: ?>
                                                    <span class="text-muted">Not assigned</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($resident['tenant_status'] === 'active'): ?>
                                                    <span class="status-active">
                                                        <i class="bi bi-check-circle-fill"></i> Active
                                                    </span>
                                                <?php elseif ($resident['tenant_status'] === 'inactive'): ?>
                                                    <span class="status-inactive">
                                                        <i class="bi bi-x-circle-fill"></i> Inactive
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-warning">
                                                        <i class="bi bi-clock-fill"></i> Pending
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo $resident['start_date'] ? date('M d, Y', strtotime($resident['start_date'])) : 'N/A'; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
