<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('error_log', __DIR__ . '/device.log');

require_once __DIR__ . '/backend/config/database.php';

try {
    // Initialize database connection
    $database = new Database();
    $db = $database->getConnection();

    // Fetch the user's timezone from the settings table
    $userTimezone = 'Africa/Nairobi'; // Default to Africa/Nairobi if not set
    try {
        $stmt = $db->prepare("SELECT value FROM settings WHERE setting = 'timezone' LIMIT 1");
        $stmt->execute();
        $setting = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($setting && !empty($setting['value'])) {
            $userTimezone = $setting['value'];
            error_log("[" . date('Y-m-d H:i:s') . "] Fetched timezone from settings: " . $userTimezone);
        } else {
            error_log("[" . date('Y-m-d H:i:s') . "] Timezone not found in settings, using default: " . $userTimezone);
        }
    } catch (PDOException $e) {
        error_log("[" . date('Y-m-d H:i:s') . "] Database error fetching timezone from settings: " . $e->getMessage());
    }

    // Get device ID from URL
    $deviceId = isset($_GET['id']) ? $_GET['id'] : null;

    if (!$deviceId) {
        header('Location: index.php');
        exit;
    }

    // Fetch device details
    function getDevice($db, $id) {
        try {
            $sql = "SELECT * FROM devices WHERE id = :id";
            
            $stmt = $db->prepare($sql);
            $stmt->execute([':id' => $id]);
            
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($row) {
                $device = [
                    'id' => $row['id'],
                    'name' => $row['name'], // Added to fetch device name
                    'serialNumber' => $row['serial_number'],
                    'manufacturer' => $row['manufacturer'],
                    'model' => $row['model_name'],
                    'status' => $row['status'],
                    'lastContact' => $row['last_contact'],
                    'ipAddress' => $row['ip_address'],
                    'softwareVersion' => $row['software_version'],
                    'hardwareVersion' => $row['hardware_version'],
                    'ssid' => $row['ssid'],
                    'ssidPassword' => $row['ssid_password']
                ];
                return $device;
            }
            
            return null;
        } catch (PDOException $e) {
            error_log("[" . date('Y-m-d H:i:s') . "] Error in getDevice function: " . $e->getMessage());
            return null;
        }
    }

    $device = getDevice($db, $deviceId);

    if (!$device) {
        header('Location: index.php');
        exit;
    }

    // Calculate tenMinutesAgo in the user's timezone
    $tenMinutesAgoUtc = new DateTime('now - 15 minutes', new DateTimeZone('UTC'));
    $tenMinutesAgo = $tenMinutesAgoUtc->setTimezone(new DateTimeZone($userTimezone));
    $tenMinutesAgoFormatted = $tenMinutesAgo->format('Y-m-d H:i:s');

    // Convert lastContact to user's timezone for comparison
    if ($device['lastContact']) {
        $lastContact = new DateTime($device['lastContact'], new DateTimeZone('UTC'));
        $lastContact->setTimezone(new DateTimeZone($userTimezone));
        $device['lastContactFormatted'] = $lastContact->format('Y-m-d H:i:s');
    } else {
        $device['lastContactFormatted'] = 'N/A';
    }

    // Check if device is online (last contact within 15 minutes in user's timezone)
    $isOnline = $device['lastContact'] && strtotime($device['lastContactFormatted']) >= strtotime($tenMinutesAgoFormatted);
    $device['status'] = $isOnline ? 'online' : 'offline';

    // Get pending or in progress tasks for this device, filtered by type
    function getActiveTasks($db, $deviceId, $userTimezone) {
        try {
            $sql = "SELECT * FROM device_tasks 
                    WHERE device_id = :device_id 
                    AND status IN ('pending', 'in_progress') 
                    AND task_type IN ('wan', 'wifi', 'reboot') 
                    ORDER BY created_at DESC";
            
            $stmt = $db->prepare($sql);
            $stmt->execute([':device_id' => $deviceId]);
            $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Convert timestamps to user's timezone
            foreach ($tasks as &$task) {
                if ($task['created_at']) {
                    $createdAt = new DateTime($task['created_at'], new DateTimeZone('UTC'));
                    $createdAt->setTimezone(new DateTimeZone($userTimezone));
                    $task['createdAtFormatted'] = $createdAt->format('Y-m-d H:i');
                } else {
                    $task['createdAtFormatted'] = 'N/A';
                }
            }
            unset($task);

            return $tasks;
        } catch (PDOException $e) {
            error_log("[" . date('Y-m-d H:i:s') . "] Error fetching active tasks: " . $e->getMessage());
            return [];
        }
    }

    // Get completed or failed tasks for this device, filtered by type
    function getCompletedTasks($db, $deviceId, $userTimezone, $limit = 5) {
        try {
            $sql = "SELECT * FROM device_tasks 
                    WHERE device_id = :device_id 
                    AND status IN ('completed', 'failed') 
                    AND task_type IN ('wan', 'wifi', 'reboot') 
                    ORDER BY updated_at DESC 
                    LIMIT :limit";
            
            $stmt = $db->prepare($sql);
            $stmt->bindParam(':device_id', $deviceId, PDO::PARAM_INT);
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Convert timestamps to user's timezone
            foreach ($tasks as &$task) {
                if ($task['updated_at']) {
                    $updatedAt = new DateTime($task['updated_at'], new DateTimeZone('UTC'));
                    $updatedAt->setTimezone(new DateTimeZone($userTimezone));
                    $task['updatedAtFormatted'] = $updatedAt->format('Y-m-d H:i');
                } else {
                    $task['updatedAtFormatted'] = 'N/A';
                }
            }
            unset($task);

            return $tasks;
        } catch (PDOException $e) {
            error_log("[" . date('Y-m-d H:i:s') . "] Error fetching completed tasks: " . $e->getMessage());
            return [];
        }
    }

    $activeTasks = getActiveTasks($db, $deviceId, $userTimezone);
    $completedTasks = getCompletedTasks($db, $deviceId, $userTimezone);

} catch (Exception $e) {
    die("An error occurred");
}

// Include the header
include __DIR__ . '/backend/templates/header.php';
include __DIR__ . '/backend/templates/sidebar.php';
?>

<!-- Main Content -->
<div class="flex-1 pt-20 sm:pt-16 ml-0 md:ml-64">
    <!-- Main Content Area -->
    <main class="p-4 sm:p-6">
        <!-- Page title and breadcrumb -->
        <div class="flex flex-col sm:flex-row justify-between flex-wrap align-items-center mb-4">
            <div>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="devices.php" class="text-decoration-none">Devices</a></li>
                        <li class="breadcrumb-item"><a href="device.php?id=<?php echo $deviceId; ?>" class="text-decoration-none"><?php echo htmlspecialchars($device['name'] ?: 'Unassigned'); ?></a></li>
                        <li class="breadcrumb-item active" aria-current="page">Configure Device</li>
                    </ol>
                </nav>
                <h1 class="text-2xl font-bold mb-0 flex items-center">
                    Configure Device
                    <span class="ml-3 badge <?php echo $device['status'] === 'online' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                        <?php echo ucfirst($device['status']); ?>
                    </span>
                </h1>
                <p class="text-gray-600 mt-2">
                    <?php echo htmlspecialchars($device['manufacturer'] . ' ' . $device['model']); ?> 
                    (S/N: <?php echo htmlspecialchars($device['serialNumber']); ?>)
                </p>
            </div>
            <div class="mt-3 sm:mt-0">
                <a href="device.php?id=<?php echo $deviceId; ?>" class="w-full sm:w-auto px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300 transition duration-200 flex items-center justify-center space-x-2">
                    <i class='bx bx-arrow-back'></i>
                    <span>Back to Device</span>
                </a>
            </div>
        </div>

        <!-- Configuration Panel -->
        <div class="bg-white rounded-lg shadow-lg mb-4">
            <div class="p-4 sm:p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                    <i class='bx bx-cog mr-2'></i>
                    Device Configuration
                </h3>
                <?php if ($device['status'] === 'offline'): ?>
                <div class="text-gray-600 bg-yellow-50 p-3 rounded-lg mb-4">
                    <i class='bx bx-error-circle mr-2'></i>
                    This device is currently offline. Configuration changes may not take effect until the device is back online.
                </div>
                <?php endif; ?>
                
                <?php if (!empty($activeTasks)): ?>
                <div class="text-gray-600 bg-blue-50 p-3 rounded-lg mb-4">
                    <i class='bx bx-info-circle mr-2'></i>
                    There are pending configuration tasks for this device. They will be applied during the next TR-069 session.
                </div>
                <?php endif; ?>
                
                <!-- Configuration Panel Integration -->
                <div id="configuration-panel">
                    <!-- WiFi Configuration -->
                    <div class="mb-4">
                        <h3 class="text-lg font-semibold text-gray-800 mb-3 flex items-center">
                            <i class='bx bx-wifi mr-2 text-blue-500'></i>
                            WiFi Configuration
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="wifi-ssid" class="block text-sm font-medium text-gray-700">WiFi Network Name (SSID)</label>
                                <input type="text" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm" id="wifi-ssid" value="<?php echo htmlspecialchars($device['ssid'] ?? ''); ?>" placeholder="Enter network name">
                            </div>
                            <div>
                                <label for="wifi-password" class="block text-sm font-medium text-gray-700">WiFi Password</label>
                                <input type="password" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm" id="wifi-password" value="<?php echo htmlspecialchars($device['ssidPassword'] ?? ''); ?>" placeholder="Enter password">
                            </div>
                            <div class="col-span-1 md:col-span-2">
                                <button type="button" class="w-full sm:w-auto px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition duration-200 flex items-center justify-center space-x-2" id="update-wifi-btn">
                                    <i class='bx bx-save'></i>
                                    <span>Update WiFi</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- PPPoE Configuration -->
                    <div class="mb-4">
                        <h3 class="text-lg font-semibold text-gray-800 mb-3 flex items-center">
                            <i class='bx bx-globe mr-2 text-blue-500'></i>
                            PPPoE Configuration
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="pppoe-username" class="block text-sm font-medium text-gray-700">PPPoE Username</label>
                                <input type="text" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm" id="pppoe-username" placeholder="Enter username provided by ISP" required>
                            </div>
                            <div>
                                <label for="pppoe-password" class="block text-sm font-medium text-gray-700">PPPoE Password</label>
                                <input type="password" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm" id="pppoe-password" placeholder="Enter password provided by ISP">
                            </div>
                            <div class="col-span-1 md:col-span-2">
                                <button type="button" class="w-full sm:w-auto px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition duration-200 flex items-center justify-center space-x-2" id="update-pppoe-btn">
                                    <i class='bx bx-save'></i>
                                    <span>Update PPPoE</span>
                                </button>
                            </div>
                        </div>
                    </div>

<!-- Device Control  —  Feature still in development -->
<div class="mb-4">
    <h3 class="text-lg font-semibold text-gray-800 mb-3 flex items-center">
        <i class='bx bx-power-off mr-2 text-red-500'></i>
        Device Control
    </h3>

    <div class="text-gray-600 bg-yellow-50 p-3 rounded-lg mb-3 flex items-center">
        <i class='bx bx-info-circle mr-2'></i>
        This feature's <strong>  still in development</strong>. Reboot will be enabled in a future release.
    </div>

    <!-- Disabled button — clickability removed on purpose -->
    <button type="button"
            class="w-full sm:w-auto px-4 py-2 bg-gray-400 text-white rounded-md
                   cursor-not-allowed opacity-70 flex items-center justify-center space-x-2"
            style="pointer-events: none;" 
            disabled>
        <i class='bx bx-refresh'></i>
        <span>Reboot Device (disabled)</span>
    </button>
</div>

                    
                    <!-- Task Status -->
                    <?php if (!empty($activeTasks) || !empty($completedTasks)): ?>
                    <div class="mt-5">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                            <i class='bx bx-list-check mr-2 text-blue-500'></i>
                            Task Status
                        </h3>
                        
                        <?php if (!empty($activeTasks)): ?>
                        <div class="mb-4">
                            <h4 class="text-md font-semibold text-gray-800 mb-3">Pending Tasks</h4>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created</th>
                                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Details</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($activeTasks as $task): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-3 py-2 whitespace-nowrap text-sm text-gray-500"><?php echo $task['id']; ?></td>
                                            <td class="px-3 py-2 whitespace-nowrap text-sm text-gray-500">
                                                <?php
                                                $taskIcon = 'bx-cog';
                                                $taskLabel = ucfirst($task['task_type']);
                                                
                                                switch ($task['task_type']) {
                                                    case 'wifi':
                                                        $taskIcon = 'bx-wifi';
                                                        break;
                                                    case 'wan':
                                                        $taskIcon = 'bx-globe';
                                                        $taskLabel = 'PPPoE';
                                                        break;
                                                    case 'reboot':
                                                        $taskIcon = 'bx-power-off';
                                                        break;
                                                }
                                                ?>
                                                <span><i class='bx <?php echo $taskIcon; ?> mr-1'></i><?php echo $taskLabel; ?></span>
                                            </td>
                                            <td class="px-3 py-2 whitespace-nowrap">
                                                <?php
                                                $statusClass = 'bg-yellow-100 text-yellow-800';
                                                $statusLabel = 'Pending';
                                                
                                                if ($task['status'] == 'in_progress') {
                                                    $statusClass = 'bg-blue-100 text-blue-800';
                                                    $statusLabel = 'In Progress';
                                                }
                                                ?>
                                                <span class="px-2 py-1 text-xs rounded-full font-medium <?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span>
                                            </td>
                                            <td class="px-3 py-2 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($task['createdAtFormatted']); ?></td>
                                            <td class="px-3 py-2 text-sm text-gray-500">
                                                <?php
                                                $taskData = json_decode($task['task_data'], true);
                                                if ($task['task_type'] == 'wifi' && isset($taskData['ssid'])) {
                                                    echo "SSID: " . htmlspecialchars($taskData['ssid']);
                                                } elseif ($task['task_type'] == 'wan' && isset($taskData['pppoe_username'])) {
                                                    echo "Username: " . htmlspecialchars($taskData['pppoe_username']);
                                                } elseif ($task['task_type'] == 'reboot' && isset($taskData['reason'])) {
                                                    echo "Reason: " . htmlspecialchars($taskData['reason']);
                                                } else {
                                                    echo $task['message'] ?? "Waiting for device";
                                                }
                                                ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($completedTasks)): ?>
                        <div>
                            <h4 class="text-md font-semibold text-gray-800 mb-3">Recent Tasks</h4>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Completed</th>
                                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Result</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($completedTasks as $task): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-3 py-2 whitespace-nowrap text-sm text-gray-500"><?php echo $task['id']; ?></td>
                                            <td class="px-3 py-2 whitespace-nowrap text-sm text-gray-500">
                                                <?php
                                                $taskIcon = 'bx-cog';
                                                $taskLabel = ucfirst($task['task_type']);
                                                
                                                switch ($task['task_type']) {
                                                    case 'wifi':
                                                        $taskIcon = 'bx-wifi';
                                                        break;
                                                    case 'wan':
                                                        $taskIcon = 'bx-globe';
                                                        $taskLabel = 'PPPoE';
                                                        break;
                                                    case 'reboot':
                                                        $taskIcon = 'bx-power-off';
                                                        break;
                                                }
                                                ?>
                                                <span><i class='bx <?php echo $taskIcon; ?> mr-1'></i><?php echo $taskLabel; ?></span>
                                            </td>
                                            <td class="px-3 py-2 whitespace-nowrap">
                                                <?php
                                                $statusClass = 'bg-green-100 text-green-800';
                                                
                                                if ($task['status'] == 'failed') {
                                                    $statusClass = 'bg-red-100 text-red-800';
                                                }
                                                ?>
                                                <span class="px-2 py-1 text-xs rounded-full font-medium <?php echo $statusClass; ?>"><?php echo ucfirst($task['status']); ?></span>
                                            </td>
                                            <td class="px-3 py-2 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($task['updatedAtFormatted']); ?></td>
                                            <td class="px-3 py-2 text-sm text-gray-500"><?php echo $task['message'] ?? "No details available"; ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const deviceId = '<?php echo $deviceId; ?>';
            
            // Update WiFi configuration
            document.getElementById('update-wifi-btn').addEventListener('click', function() {
                const ssid = document.getElementById('wifi-ssid').value;
                const password = document.getElementById('wifi-password').value;
                
                if (!ssid) {
                    alert('Please enter a WiFi network name (SSID)');
                    return;
                }
                
                makeConfigRequest('wifi', { ssid, password });
            });
            
            // Update PPPoE configuration
            document.getElementById('update-pppoe-btn').addEventListener('click', function() {
                const username = document.getElementById('pppoe-username').value;
                const password = document.getElementById('pppoe-password').value;
                
                if (!username) {
                    alert('Please enter a PPPoE username');
                    return;
                }
                
                fetch('/backend/api/insert_wan_task.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        device_id: deviceId,
                        pppoe_username: username,
                        pppoe_password: password
                    })
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        alert('PPPoE configuration task created');
                        window.location.reload();
                    } else {
                        alert('Error: ' + (result.message || 'Configuration failed'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error: Configuration request failed');
                });
            });
            
            // Reboot device
            document.getElementById('reboot-device-btn').addEventListener('click', function() {
                if (confirm('Are you sure you want to reboot this device? All connections will be temporarily disrupted.')) {
                    makeConfigRequest('reboot', { reason: 'Webpage-initiated reboot' });
                }
            });
            
            // Function to make configuration request for WiFi and reboot
            function makeConfigRequest(action, data) {
                const formData = new FormData();
                formData.append('device_id', deviceId);
                formData.append('action', action);
                Object.entries(data).forEach(([key, value]) => {
                    formData.append(key, value);
                });
                
                const button = document.getElementById(`update-${action}-btn`) || document.getElementById('reboot-device-btn');
                const originalText = button.innerHTML;
                button.disabled = true;
                button.innerHTML = '<i class="bx bx-loader-alt bx-spin mr-2"></i> Processing...';
                
                fetch('/backend/api/device_configure.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        alert(result.message);
                        window.location.reload();
                    } else {
                        alert('Error: ' + (result.message || 'Configuration failed'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error: Configuration request failed');
                })
                .finally(() => {
                    button.disabled = false;
                    button.innerHTML = originalText;
                });
            }
        });
    </script>

<?php
// Include the footer
include __DIR__ . '/backend/templates/footer.php';
?>