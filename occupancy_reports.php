<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}

require_once "db/database.php";

// Get room types filter
$filter_type = isset($_GET['type']) ? $_GET['type'] : '';

try {
    // Total Rooms
    $result = $conn->query("SELECT COUNT(*) as total FROM rooms");
    $total_rooms = $result->fetch(PDO::FETCH_ASSOC)['total'];

    // Occupied Rooms - Calculate based on actual occupancy (tenants + co-tenants)
    $sql_occupied = "
        SELECT COUNT(DISTINCT r.id) as occupied
        FROM rooms r
        WHERE (
            SELECT COUNT(DISTINCT t.id) + COUNT(DISTINCT ct.id)
            FROM tenants t
            LEFT JOIN co_tenants ct ON r.id = ct.room_id
            WHERE t.room_id = r.id AND t.status = 'active'
        ) > 0
    ";
    $result = $conn->query($sql_occupied);
    $occupied_rooms = $result->fetch(PDO::FETCH_ASSOC)['occupied'];

    // Unavailable Rooms - Count rooms with status='unavailable' in database (REAL-TIME)
    $result = $conn->query("SELECT COUNT(*) as count FROM rooms WHERE status = 'unavailable'");
    $unavailable_rooms = $result->fetch(PDO::FETCH_ASSOC)['count'];

    // Vacant Rooms - Rooms with no tenants and no co-tenants and not unavailable
    $vacant_rooms = $total_rooms - $occupied_rooms - $unavailable_rooms;

    // Total Tenants
    $result = $conn->query("SELECT COUNT(*) as total FROM tenants WHERE status = 'active'");
    $total_tenants = $result->fetch(PDO::FETCH_ASSOC)['total'];

    // Occupancy Rate
    $occupancy_rate = $total_rooms > 0 ? round(($occupied_rooms / $total_rooms) * 100, 1) : 0;

    // Room Types Distribution - Calculate based on actual occupancy
    $result = $conn->query("
        SELECT 
            r.room_type,
            COUNT(DISTINCT r.id) as count,
            SUM(CASE WHEN (
                SELECT COUNT(DISTINCT t.id) + COUNT(DISTINCT ct.id)
                FROM tenants t
                LEFT JOIN co_tenants ct ON r.id = ct.room_id
                WHERE t.room_id = r.id AND t.status = 'active'
            ) > 0 THEN 1 ELSE 0 END) as occupied,
            ROUND((SUM(CASE WHEN (
                SELECT COUNT(DISTINCT t.id) + COUNT(DISTINCT ct.id)
                FROM tenants t
                LEFT JOIN co_tenants ct ON r.id = ct.room_id
                WHERE t.room_id = r.id AND t.status = 'active'
            ) > 0 THEN 1 ELSE 0 END) / COUNT(DISTINCT r.id)) * 100, 1) as occupancy_pct
        FROM rooms r
        GROUP BY r.room_type
        ORDER BY count DESC
    ");
    $room_types = $result->fetchAll(PDO::FETCH_ASSOC);

    // Room Status Breakdown - Based on actual occupancy
    $status_breakdown = array(
        array('status' => 'Occupied', 'count' => $occupied_rooms),
        array('status' => 'Vacant', 'count' => $vacant_rooms)
    );

    // Detailed Room Listing - Include both tenants and co-tenants
    $sql = "
        SELECT 
            r.id,
            r.room_number,
            r.room_type,
            r.rate,
            r.status,
            GROUP_CONCAT(DISTINCT t.name SEPARATOR ', ') as tenant_names,
            GROUP_CONCAT(DISTINCT ct.name SEPARATOR ', ') as co_tenant_names,
            COALESCE(COUNT(DISTINCT t.id), 0) as tenant_count,
            COALESCE(COUNT(DISTINCT ct.id), 0) as co_tenant_count,
            GROUP_CONCAT(DISTINCT t.phone SEPARATOR ', ') as phones,
            MIN(DATEDIFF(CURDATE(), t.start_date)) as days_occupied_min,
            MAX(DATEDIFF(CURDATE(), t.start_date)) as days_occupied_max
        FROM rooms r
        LEFT JOIN tenants t ON r.id = t.room_id AND t.status = 'active'
        LEFT JOIN co_tenants ct ON r.id = ct.room_id
        WHERE 1=1
    ";
    
    if ($filter_type) {
        $sql .= " AND r.room_type = :room_type";
    }
    
    $sql .= " GROUP BY r.id ORDER BY r.room_number ASC";
    
    $stmt = $conn->prepare($sql);
    if ($filter_type) {
        $stmt->bindParam(':room_type', $filter_type);
    }
    $stmt->execute();
    $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $error = "Error loading report: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Occupancy Reports - BAMINT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <style>
        body { background-color: #f8f9fa; }
        .metric-card {
            border: none;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .metric-card .card-body { padding: 1.5rem; }
        .metric-value { font-size: 1.75rem; font-weight: 700; }
        .chart-container {
            position: relative;
            height: 300px;
            margin-bottom: 2rem;
        }
        .room-status-badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <?php include 'templates/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <!-- Page Header -->
                <div class="mb-4">
                    <h1 class="mb-1"><i class="bi bi-building"></i> Occupancy Reports</h1>
                    <p class="text-muted">Room status, occupancy rates, and utilization analysis</p>
                </div>

                <!-- Key Metrics -->
                <div class="row g-3 mb-4">
                    <div class="col-md-6 col-lg-3">
                        <div class="card metric-card bg-primary bg-opacity-10">
                            <div class="card-body">
                                <p class="text-muted mb-2">Total Rooms</p>
                                <p class="metric-value text-primary"><?php echo $total_rooms; ?></p>
                                <small class="text-muted">In property</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-lg-3">
                        <div class="card metric-card bg-success bg-opacity-10">
                            <div class="card-body">
                                <p class="text-muted mb-2">Occupied</p>
                                <p class="metric-value text-success"><?php echo $occupied_rooms; ?></p>
                                <small class="text-muted"><?php echo $occupancy_rate; ?>% occupancy</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-lg-3">
                        <div class="card metric-card bg-info bg-opacity-10">
                            <div class="card-body">
                                <p class="text-muted mb-2">Vacant</p>
                                <p class="metric-value text-info"><?php echo $vacant_rooms; ?></p>
                                <small class="text-muted">Available for rent</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-lg-3">
                        <div class="card metric-card bg-warning bg-opacity-10">
                            <div class="card-body">
                                <p class="text-muted mb-2">Unavailable</p>
                                <p class="metric-value text-warning"><?php echo $unavailable_rooms; ?></p>
                                <small class="text-muted">Maintenance/other</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-lg-3">
                        <div class="card metric-card bg-purple bg-opacity-10">
                            <div class="card-body">
                                <p class="text-muted mb-2">Total Tenants</p>
                                <p class="metric-value" style="color: #764ba2;"><?php echo $total_tenants; ?></p>
                                <small class="text-muted">Active occupants</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts -->
                <div class="row g-4 mb-4">
                    <!-- Occupancy Status Chart -->
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header bg-success bg-opacity-10">
                                <h6 class="mb-0"><i class="bi bi-pie-chart"></i> Occupancy Status</h6>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="statusChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Room Types Distribution -->
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header bg-info bg-opacity-10">
                                <h6 class="mb-0"><i class="bi bi-bar-chart"></i> Rooms by Type</h6>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="typesChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Room Types Breakdown -->
                <div class="card mb-4">
                    <div class="card-header bg-secondary bg-opacity-10">
                        <h6 class="mb-0"><i class="bi bi-list-ul"></i> Room Types Summary</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>Room Type</th>
                                        <th class="text-center">Total Rooms</th>
                                        <th class="text-center">Occupied</th>
                                        <th class="text-center">Vacant</th>
                                        <th class="text-end">Occupancy Rate</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($room_types)): ?>
                                        <?php foreach ($room_types as $type): ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($type['room_type'] ?? 'Unspecified'); ?></strong></td>
                                                <td class="text-center"><?php echo $type['count']; ?></td>
                                                <td class="text-center"><span class="badge bg-success"><?php echo $type['occupied']; ?></span></td>
                                                <td class="text-center"><span class="badge bg-info"><?php echo $type['count'] - $type['occupied']; ?></span></td>
                                                <td class="text-end"><strong><?php echo $type['occupancy_pct']; ?>%</strong></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Filter Section -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-8">
                                <label for="type" class="form-label">Filter by Room Type</label>
                                <select class="form-select" id="type" name="type">
                                    <option value="">All Room Types</option>
                                    <?php foreach ($room_types as $type): ?>
                                        <option value="<?php echo htmlspecialchars($type['room_type']); ?>" <?php echo $filter_type === $type['room_type'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($type['room_type']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4 d-flex align-items-end gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-filter"></i> Filter
                                </button>
                                <a href="occupancy_reports.php" class="btn btn-secondary">
                                    <i class="bi bi-arrow-clockwise"></i> Reset
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Detailed Room Listing -->
                <div class="card">
                    <div class="card-header bg-primary bg-opacity-10">
                        <h6 class="mb-0"><i class="bi bi-door-open"></i> Detailed Room Status</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>Room #</th>
                                        <th>Type</th>
                                        <th class="text-end">Rate (₱)</th>
                                        <th>Status</th>
                                        <th>Total Occupancy</th>
                                        <th>Occupants</th>
                                        <th class="text-center">Days Occupied</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($rooms)): ?>
                                        <?php foreach ($rooms as $room): ?>
                                            <?php 
                                            // Calculate total occupancy
                                            $total_occupancy = intval($room['tenant_count']) + intval($room['co_tenant_count']);
                                            
                                            // Combine tenant and co-tenant names
                                            $all_occupants = [];
                                            if ($room['tenant_names']) {
                                                $all_occupants[] = $room['tenant_names'] . " (Primary)";
                                            }
                                            if ($room['co_tenant_names']) {
                                                $all_occupants[] = $room['co_tenant_names'] . " (Co-tenant)";
                                            }
                                            $occupants_display = !empty($all_occupants) ? implode(", ", $all_occupants) : 'Vacant';
                                            
                                            // Determine actual status based on occupancy
                                            $actual_status = $total_occupancy > 0 ? 'occupied' : 'available';
                                            ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($room['room_number']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($room['room_type'] ?? 'N/A'); ?></td>
                                                <td class="text-end">₱<?php echo number_format($room['rate'], 2); ?></td>
                                                <td>
                                                    <?php 
                                                    $badge_class = $actual_status === 'occupied' ? 'success' : 'info';
                                                    ?>
                                                    <span class="badge bg-<?php echo $badge_class; ?>"><?php echo ucfirst($actual_status); ?></span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php echo $total_occupancy > 0 ? 'success' : 'secondary'; ?>">
                                                        <?php echo $total_occupancy; ?> person(s)
                                                    </span>
                                                </td>
                                                <td>
                                                    <small><?php echo htmlspecialchars($occupants_display); ?></small>
                                                </td>
                                                <td class="text-center"><?php echo $room['days_occupied_min'] !== null ? intval($room['days_occupied_min']) : '-'; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="text-center text-muted">No rooms found</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Status Chart
        const statusCtx = document.getElementById('statusChart');
        if (statusCtx) {
            const statusData = <?php echo json_encode($status_breakdown); ?>;
            const statuses = statusData.map(d => {
                if (d.status === 'occupied') return 'Occupied';
                if (d.status === 'available') return 'Vacant';
                return d.status.charAt(0).toUpperCase() + d.status.slice(1);
            });
            const counts = statusData.map(d => d.count);

            new Chart(statusCtx, {
                type: 'doughnut',
                data: {
                    labels: statuses,
                    datasets: [{
                        data: counts,
                        backgroundColor: ['#198754', '#0dcaf0', '#ffc107']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom' } }
                }
            });
        }

        // Types Chart
        const typesCtx = document.getElementById('typesChart');
        if (typesCtx) {
            const typesData = <?php echo json_encode($room_types); ?>;
            const types = typesData.map(d => d.room_type);
            const total = typesData.map(d => d.count);
            const occupied = typesData.map(d => d.occupied);

            new Chart(typesCtx, {
                type: 'bar',
                data: {
                    labels: types,
                    datasets: [
                        {
                            label: 'Occupied',
                            data: occupied,
                            backgroundColor: '#198754'
                        },
                        {
                            label: 'Vacant',
                            data: typesData.map((d, i) => d.count - d.occupied),
                            backgroundColor: '#0dcaf0'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: { x: { stacked: true }, y: { stacked: true } },
                    plugins: { legend: { position: 'bottom' } }
                }
            });
        }
    </script>
</body>
</html>
