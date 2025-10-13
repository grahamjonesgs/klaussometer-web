<?php
// Authentication - must be first line
require_once 'auth.php';

include 'vars.php';

// Function to get system stats
function getSystemStats() {
    $stats = [];
    
    // Memory usage
    $free = shell_exec('free -m');
    if (preg_match('/Mem:\s+(\d+)\s+(\d+)\s+(\d+)/', $free, $matches)) {
        $stats['mem_total'] = $matches[1];
        $stats['mem_used'] = $matches[2];
        $stats['mem_free'] = $matches[3];
        $stats['mem_percent'] = round(($matches[2] / $matches[1]) * 100, 1);
    }
    
    // Swap usage
    if (preg_match('/Swap:\s+(\d+)\s+(\d+)\s+(\d+)/', $free, $matches)) {
        $stats['swap_total'] = $matches[1];
        $stats['swap_used'] = $matches[2];
        $stats['swap_free'] = $matches[3];
        $stats['swap_percent'] = $matches[1] > 0 ? round(($matches[2] / $matches[1]) * 100, 1) : 0;
    }
    
    // CPU load average
    $loadavg = sys_getloadavg();
    $stats['cpu_load_1'] = $loadavg[0];
    $stats['cpu_load_5'] = $loadavg[1];
    $stats['cpu_load_15'] = $loadavg[2];
    
    // CPU core count
    $stats['cpu_cores'] = trim(shell_exec("nproc"));
    
    // Uptime
    $uptime = shell_exec('uptime -s');
    $stats['boot_time'] = trim($uptime);
    
    // Calculate uptime duration
    $bootTimestamp = strtotime($stats['boot_time']);
    $uptimeSeconds = time() - $bootTimestamp;
    $days = floor($uptimeSeconds / 86400);
    $hours = floor(($uptimeSeconds % 86400) / 3600);
    $minutes = floor(($uptimeSeconds % 3600) / 60);
    $stats['uptime_formatted'] = "${days}d ${hours}h ${minutes}m";
    
    // Disk usage
    $disk = shell_exec('df -h / | tail -1');
    if (preg_match('/(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\d+)%/', $disk, $matches)) {
        $stats['disk_size'] = $matches[2];
        $stats['disk_used'] = $matches[3];
        $stats['disk_free'] = $matches[4];
        $stats['disk_percent'] = $matches[5];
    }
    
    // Available updates
    $updates = shell_exec('/usr/lib/update-notifier/apt-check 2>&1');
    if (preg_match('/(\d+);(\d+)/', $updates, $matches)) {
        $stats['updates_available'] = $matches[0];
        $stats['security_updates'] = $matches[1];
    } else {
        $stats['updates_available'] = 'N/A';
        $stats['security_updates'] = 'N/A';
    }
    
    return $stats;
}

// Function to get network information
function getNetworkInfo() {
    $network = [];
    
    // Get local IP addresses
    $ifconfig = shell_exec('ip addr show');
    preg_match_all('/inet (\d+\.\d+\.\d+\.\d+)/', $ifconfig, $matches);
    $network['local_ips'] = array_filter($matches[1], function($ip) {
        return $ip !== '127.0.0.1'; // Exclude localhost
    });
    
    // Get external IP (with timeout)
    $external_ip = @file_get_contents('https://api.ipify.org', false, stream_context_create([
        'http' => ['timeout' => 3]
    ]));
    $network['external_ip'] = $external_ip ?: 'Unable to fetch';
    
    // Test internet connectivity
    $network['internet_connected'] = ($external_ip !== false);
    
    // Get hostname
    $network['hostname'] = gethostname();
    
    return $network;
}

// Function to get service status
function getServiceStatus() {
    $services = [];
    
    // PHP Version
    $services['php_version'] = phpversion();
    
    // Web server (Apache or Nginx)
    $apache_status = shell_exec('systemctl is-active apache2 2>/dev/null');
    $nginx_status = shell_exec('systemctl is-active nginx 2>/dev/null');
    
    if (trim($apache_status) === 'active') {
        $services['webserver'] = 'Apache';
        $services['webserver_status'] = 'Running';
        $apache_version = shell_exec('apache2 -v 2>/dev/null | head -1');
        if (preg_match('/Apache\/([^\s]+)/', $apache_version, $matches)) {
            $services['webserver_version'] = $matches[1];
        }
    } elseif (trim($nginx_status) === 'active') {
        $services['webserver'] = 'Nginx';
        $services['webserver_status'] = 'Running';
        $nginx_version = shell_exec('nginx -v 2>&1');
        if (preg_match('/nginx\/([^\s]+)/', $nginx_version, $matches)) {
            $services['webserver_version'] = $matches[1];
        }
    } else {
        $services['webserver'] = 'Unknown';
        $services['webserver_status'] = 'Not Running';
    }
    
    // MariaDB/MySQL status
    $mysql_status = shell_exec('systemctl is-active mariadb 2>/dev/null || systemctl is-active mysql 2>/dev/null');
    $services['database_status'] = (trim($mysql_status) === 'active') ? 'Running' : 'Not Running';
    
    return $services;
}

// Function to get database statistics
function getDatabaseStats($conn) {
    $stats = [];
    
    // Database size
    $result = $conn->query("SELECT 
        ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb 
        FROM information_schema.TABLES 
        WHERE table_schema = DATABASE()");
    if ($row = $result->fetch_assoc()) {
        $stats['total_size_mb'] = $row['size_mb'];
    }
    
    // Table sizes and row counts
    $tables = ['rec_data', 'hourly_avg', 'daily_avg', 'event_log'];
    $stats['tables'] = [];
    
    foreach ($tables as $table) {
        $result = $conn->query("SELECT COUNT(*) as count FROM $table");
        $count = $result ? $result->fetch_assoc()['count'] : 0;
        
        $result = $conn->query("SELECT 
            ROUND((data_length + index_length) / 1024 / 1024, 2) AS size_mb 
            FROM information_schema.TABLES 
            WHERE table_schema = DATABASE() AND table_name = '$table'");
        $size = $result ? $result->fetch_assoc()['size_mb'] : 0;
        
        $stats['tables'][$table] = [
            'rows' => number_format($count),
            'size_mb' => $size
        ];
    }
    
    // Oldest and newest records
    $result = $conn->query("SELECT MIN(dt) as oldest, MAX(dt) as newest FROM rec_data");
    if ($row = $result->fetch_assoc()) {
        $stats['oldest_record'] = $row['oldest'] ?: 'N/A';
        $stats['newest_record'] = $row['newest'] ?: 'N/A';
        
        // Calculate data span
        if ($row['oldest'] && $row['newest']) {
            $oldest = strtotime($row['oldest']);
            $newest = strtotime($row['newest']);
            $days = round(($newest - $oldest) / 86400);
            $stats['data_span_days'] = $days;
        }
    }
    
    // Check event scheduler status
    $result = $conn->query("SHOW VARIABLES LIKE 'event_scheduler'");
    if ($row = $result->fetch_assoc()) {
        $stats['event_scheduler'] = $row['Value'];
    }
    
    // Database version
    $result = $conn->query("SELECT VERSION() as version");
    if ($row = $result->fetch_assoc()) {
        $stats['db_version'] = $row['version'];
    }
    
    return $stats;
}

// Get all statistics
$conn = new mysqli($servername, $username, $password, $dbname);
$dbConnected = !$conn->connect_error;

if ($dbConnected) {
    // Get event logs
    $sql = "SELECT event_name, execution_time, rows_affected, status, error_message 
            FROM event_log 
            ORDER BY execution_time DESC 
            LIMIT 20";
    $result = $conn->query($sql);
    $eventLogs = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $eventLogs[] = $row;
        }
    }
    
    // Get event statistics
    $sql = "SELECT 
                event_name,
                COUNT(*) as executions,
                AVG(rows_affected) as avg_rows,
                MAX(execution_time) as last_run
            FROM event_log
            WHERE execution_time >= NOW() - INTERVAL 7 DAY
            GROUP BY event_name";
    $result = $conn->query($sql);
    $eventStats = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $eventStats[] = $row;
        }
    }
    
    // Get database statistics
    $databaseStats = getDatabaseStats($conn);
    
    $conn->close();
} else {
    error_log("Database connection failed: " . $conn->connect_error);
    $eventLogs = [];
    $eventStats = [];
    $databaseStats = [];
}

$systemStats = getSystemStats();
$networkInfo = getNetworkInfo();
$serviceStatus = getServiceStatus();
?>
<!DOCTYPE html>
<html>
<head>
    <title>System Status Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #eef2f5;
            padding: 20px;
            margin: 0;
        }
        
        .nav-button {
            display: inline-block;
            padding: 10px 20px;
            margin: 10px 10px 20px 0;
            background-color: #007bff;
            color: white;
            text-align: center;
            text-decoration: none;
            font-size: 16px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }
        
        .nav-button:hover {
            background-color: #0056b3;
        }
        
        .nav-button.logout {
            background-color: #dc3545;
        }
        
        .nav-button.logout:hover {
            background-color: #c82333;
        }
        
        .user-info {
            float: right;
            margin: 10px 0;
            color: #666;
            font-style: italic;
        }
        
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
            clear: both;
        }
        
        .card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        
        .card h2 {
            margin: 0 0 15px 0;
            font-size: 1.3rem;
            color: #333;
            border-bottom: 2px solid #007bff;
            padding-bottom: 10px;
        }
        
        .stat-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }
        
        .stat-row:last-child {
            border-bottom: none;
        }
        
        .stat-label {
            font-weight: bold;
            color: #555;
        }
        
        .stat-value {
            color: #333;
        }
        
        .progress-bar {
            width: 100%;
            height: 20px;
            background-color: #e0e0e0;
            border-radius: 10px;
            overflow: hidden;
            margin-top: 5px;
        }
        
        .progress-fill {
            height: 100%;
            background-color: #28a745;
            transition: width 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 12px;
            font-weight: bold;
        }
        
        .progress-fill.warning {
            background-color: #ffc107;
        }
        
        .progress-fill.danger {
            background-color: #dc3545;
        }
        
        .table-container {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th {
            background-color: #007bff;
            color: white;
            padding: 12px;
            text-align: left;
            font-weight: bold;
        }
        
        td {
            padding: 10px 12px;
            border-bottom: 1px solid #eee;
        }
        
        tr:hover {
            background-color: #f5f5f5;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.85em;
            font-weight: bold;
        }
        
        .badge-success {
            background-color: #d4edda;
            color: #155724;
        }
        
        .badge-warning {
            background-color: #fff3cd;
            color: #856404;
        }
        
        .badge-danger {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .status-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 5px;
        }
        
        .status-online {
            background-color: #28a745;
        }
        
        .status-offline {
            background-color: #dc3545;
        }
        
        .refresh-time {
            text-align: center;
            color: #666;
            font-style: italic;
            margin-top: 20px;
        }
        
        .ip-list {
            list-style: none;
            padding: 0;
            margin: 5px 0;
        }
        
        .ip-list li {
            padding: 3px 0;
            font-family: monospace;
        }
    </style>
</head>
<body>
    <div style="overflow: auto;">
        <div style="float: left;">
            <a href="current.html" class="nav-button">Current Readings</a>
            <a href="graph.html" class="nav-button">View Graph</a>
            <a href="system_status.php" class="nav-button">Refresh Status</a>
            <a href="?logout" class="nav-button logout">Logout</a>
        </div>
        <div class="user-info">
            Logged in as: <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong>
        </div>
    </div>
    
    <h1 style="text-align: center; color: #333;">System Status Dashboard</h1>
    
    <div class="dashboard-grid">
        <!-- Memory Card -->
        <div class="card">
            <h2>💾 Memory Usage</h2>
            <div class="stat-row">
                <span class="stat-label">Total:</span>
                <span class="stat-value"><?php echo $systemStats['mem_total']; ?> MB</span>
            </div>
            <div class="stat-row">
                <span class="stat-label">Used:</span>
                <span class="stat-value"><?php echo $systemStats['mem_used']; ?> MB</span>
            </div>
            <div class="stat-row">
                <span class="stat-label">Free:</span>
                <span class="stat-value"><?php echo $systemStats['mem_free']; ?> MB</span>
            </div>
            <div class="progress-bar">
                <div class="progress-fill <?php 
                    echo $systemStats['mem_percent'] > 90 ? 'danger' : 
                        ($systemStats['mem_percent'] > 75 ? 'warning' : ''); 
                ?>" style="width: <?php echo $systemStats['mem_percent']; ?>%">
                    <?php echo $systemStats['mem_percent']; ?>%
                </div>
            </div>
        </div>
        
        <!-- Swap Card -->
        <div class="card">
            <h2>🔄 Swap Usage</h2>
            <div class="stat-row">
                <span class="stat-label">Total:</span>
                <span class="stat-value"><?php echo $systemStats['swap_total']; ?> MB</span>
            </div>
            <div class="stat-row">
                <span class="stat-label">Used:</span>
                <span class="stat-value"><?php echo $systemStats['swap_used']; ?> MB</span>
            </div>
            <div class="stat-row">
                <span class="stat-label">Free:</span>
                <span class="stat-value"><?php echo $systemStats['swap_free']; ?> MB</span>
            </div>
            <div class="progress-bar">
                <div class="progress-fill <?php 
                    echo $systemStats['swap_percent'] > 50 ? 'danger' : 
                        ($systemStats['swap_percent'] > 25 ? 'warning' : ''); 
                ?>" style="width: <?php echo $systemStats['swap_percent']; ?>%">
                    <?php echo $systemStats['swap_percent']; ?>%
                </div>
            </div>
        </div>
        
        <!-- CPU Card -->
        <div class="card">
            <h2>⚙️ CPU Load</h2>
            <div class="stat-row">
                <span class="stat-label">Cores:</span>
                <span class="stat-value"><?php echo $systemStats['cpu_cores']; ?></span>
            </div>
            <div class="stat-row">
                <span class="stat-label">1 min avg:</span>
                <span class="stat-value"><?php echo number_format($systemStats['cpu_load_1'], 2); ?></span>
            </div>
            <div class="stat-row">
                <span class="stat-label">5 min avg:</span>
                <span class="stat-value"><?php echo number_format($systemStats['cpu_load_5'], 2); ?></span>
            </div>
            <div class="stat-row">
                <span class="stat-label">15 min avg:</span>
                <span class="stat-value"><?php echo number_format($systemStats['cpu_load_15'], 2); ?></span>
            </div>
        </div>
        
        <!-- Disk Card -->
        <div class="card">
            <h2>💿 Disk Usage (/)</h2>
            <div class="stat-row">
                <span class="stat-label">Total:</span>
                <span class="stat-value"><?php echo $systemStats['disk_size']; ?></span>
            </div>
            <div class="stat-row">
                <span class="stat-label">Used:</span>
                <span class="stat-value"><?php echo $systemStats['disk_used']; ?></span>
            </div>
            <div class="stat-row">
                <span class="stat-label">Free:</span>
                <span class="stat-value"><?php echo $systemStats['disk_free']; ?></span>
            </div>
            <div class="progress-bar">
                <div class="progress-fill <?php 
                    echo $systemStats['disk_percent'] > 90 ? 'danger' : 
                        ($systemStats['disk_percent'] > 75 ? 'warning' : ''); 
                ?>" style="width: <?php echo $systemStats['disk_percent']; ?>%">
                    <?php echo $systemStats['disk_percent']; ?>%
                </div>
            </div>
        </div>
        
        <!-- Uptime Card -->
        <div class="card">
            <h2>⏱️ System Uptime</h2>
            <div class="stat-row">
                <span class="stat-label">Last Boot:</span>
                <span class="stat-value"><?php echo $systemStats['boot_time']; ?></span>
            </div>
            <div class="stat-row">
                <span class="stat-label">Uptime:</span>
                <span class="stat-value"><?php echo $systemStats['uptime_formatted']; ?></span>
            </div>
        </div>
        
        <!-- Updates Card -->
        <div class="card">
            <h2>📦 System Updates</h2>
            <div class="stat-row">
                <span class="stat-label">Available:</span>
                <span class="stat-value">
                    <?php 
                    if ($systemStats['updates_available'] === 'N/A') {
                        echo 'N/A (Check permissions)';
                    } else {
                        echo $systemStats['updates_available'];
                    }
                    ?>
                </span>
            </div>
            <div class="stat-row">
                <span class="stat-label">Security:</span>
                <span class="stat-value">
                    <?php 
                    if ($systemStats['security_updates'] === 'N/A') {
                        echo 'N/A';
                    } else {
                        echo $systemStats['security_updates'];
                    }
                    ?>
                </span>
            </div>
        </div>
        
        <!-- Network Information Card -->
        <div class="card">
            <h2>🌐 Network Information</h2>
            <div class="stat-row">
                <span class="stat-label">Hostname:</span>
                <span class="stat-value"><?php echo htmlspecialchars($networkInfo['hostname']); ?></span>
            </div>
            <div class="stat-row">
                <span class="stat-label">Local IPs:</span>
                <span class="stat-value">
                    <?php if (!empty($networkInfo['local_ips'])): ?>
                        <ul class="ip-list">
                            <?php foreach ($networkInfo['local_ips'] as $ip): ?>
                                <li><?php echo htmlspecialchars($ip); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        N/A
                    <?php endif; ?>
                </span>
            </div>
            <div class="stat-row">
                <span class="stat-label">External IP:</span>
                <span class="stat-value"><?php echo htmlspecialchars($networkInfo['external_ip']); ?></span>
            </div>
            <div class="stat-row">
                <span class="stat-label">Internet:</span>
                <span class="stat-value">
                    <span class="status-indicator <?php echo $networkInfo['internet_connected'] ? 'status-online' : 'status-offline'; ?>"></span>
                    <?php echo $networkInfo['internet_connected'] ? 'Connected' : 'Disconnected'; ?>
                </span>
            </div>
        </div>
        
        <!-- Service Status Card -->
        <div class="card">
            <h2>🔧 Service Status</h2>
            <div class="stat-row">
                <span class="stat-label">PHP Version:</span>
                <span class="stat-value"><?php echo $serviceStatus['php_version']; ?></span>
            </div>
            <div class="stat-row">
                <span class="stat-label">Web Server:</span>
                <span class="stat-value">
                    <span class="status-indicator <?php echo $serviceStatus['webserver_status'] === 'Running' ? 'status-online' : 'status-offline'; ?>"></span>
                    <?php echo $serviceStatus['webserver']; ?>
                    <?php if (isset($serviceStatus['webserver_version'])): ?>
                        (<?php echo $serviceStatus['webserver_version']; ?>)
                    <?php endif; ?>
                </span>
            </div>
            <div class="stat-row">
                <span class="stat-label">Database:</span>
                <span class="stat-value">
                    <span class="status-indicator <?php echo $serviceStatus['database_status'] === 'Running' ? 'status-online' : 'status-offline'; ?>"></span>
                    <?php echo $serviceStatus['database_status']; ?>
                    <?php if ($dbConnected && isset($databaseStats['db_version'])): ?>
                        (<?php echo $databaseStats['db_version']; ?>)
                    <?php endif; ?>
                </span>
            </div>
            <?php if ($dbConnected && isset($databaseStats['event_scheduler'])): ?>
            <div class="stat-row">
                <span class="stat-label">Event Scheduler:</span>
                <span class="stat-value">
                    <span class="status-indicator <?php echo strtoupper($databaseStats['event_scheduler']) === 'ON' ? 'status-online' : 'status-offline'; ?>"></span>
                    <?php echo strtoupper($databaseStats['event_scheduler']); ?>
                </span>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Database Statistics Card -->
        <?php if ($dbConnected && !empty($databaseStats)): ?>
        <div class="card">
            <h2>🗄️ Database Statistics</h2>
            <div class="stat-row">
                <span class="stat-label">Total Size:</span>
                <span class="stat-value"><?php echo $databaseStats['total_size_mb']; ?> MB</span>
            </div>
            <div class="stat-row">
                <span class="stat-label">Data Span:</span>
                <span class="stat-value">
                    <?php echo isset($databaseStats['data_span_days']) ? $databaseStats['data_span_days'] . ' days' : 'N/A'; ?>
                </span>
            </div>
            <div class="stat-row">
                <span class="stat-label">Oldest Record:</span>
                <span class="stat-value"><?php echo $databaseStats['oldest_record']; ?></span>
            </div>
            <div class="stat-row">
                <span class="stat-label">Newest Record:</span>
                <span class="stat-value"><?php echo $databaseStats['newest_record']; ?></span>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Database Tables Detail -->
    <?php if ($dbConnected && !empty($databaseStats['tables'])): ?>
    <div class="table-container" style="margin-bottom: 30px;">
        <h2>📊 Database Tables</h2>
        <table>
            <thead>
                <tr>
                    <th>Table Name</th>
                    <th>Row Count</th>
                    <th>Size (MB)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($databaseStats['tables'] as $tableName => $tableData): ?>
                <tr>
                    <td><?php echo htmlspecialchars($tableName); ?></td>
                    <td><?php echo $tableData['rows']; ?></td>
                    <td><?php echo $tableData['size_mb']; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <!-- Event Statistics -->
    <?php if (!empty($eventStats)): ?>
    <div class="table-container" style="margin-bottom: 30px;">
        <h2>📊 Event Statistics (Last 7 Days)</h2>
        <table>
            <thead>
                <tr>
                    <th>Event Name</th>
                    <th>Executions</th>
                    <th>Avg Rows Affected</th>
                    <th>Last Run</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($eventStats as $stat): ?>
                <tr>
                    <td><?php echo htmlspecialchars($stat['event_name']); ?></td>
                    <td><?php echo $stat['executions']; ?></td>
                    <td><?php echo number_format($stat['avg_rows'], 1); ?></td>
                    <td><?php echo $stat['last_run']; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <!-- Recent Event Logs -->
    <?php if (!empty($eventLogs)): ?>
    <div class="table-container">
        <h2>📝 Recent Event Executions (Last 20)</h2>
        <table>
            <thead>
                <tr>
                    <th>Event Name</th>
                    <th>Execution Time</th>
                    <th>Rows Affected</th>
                    <th>Status</th>
                    <th>Error Message</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($eventLogs as $log): ?>
                <tr>
                    <td><?php echo htmlspecialchars($log['event_name']); ?></td>
                    <td><?php echo $log['execution_time']; ?></td>
                    <td><?php echo $log['rows_affected']; ?></td>
                    <td>
                        <span class="badge <?php 
                            echo $log['status'] === 'SUCCESS' ? 'badge-success' : 'badge-danger'; 
                        ?>">
                            <?php echo htmlspecialchars($log['status']); ?>
                        </span>
                    </td>
                    <td><?php echo htmlspecialchars($log['error_message'] ?? '-'); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php elseif ($dbConnected): ?>
    <div class="card">
        <p style="text-align: center; color: #666;">No event logs found. Make sure the event_log table exists and events are configured with logging.</p>
    </div>
    <?php endif; ?>
    
    <p class="refresh-time">Last updated: <?php echo date('Y-m-d H:i:s'); ?></p>
</body>
</html>