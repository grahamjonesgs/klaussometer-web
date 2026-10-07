<?php
header('Content-Type: application/json');

// Load configuration (database and Solarman credentials)
include 'vars.php';

require_once 'solarman_lib.php';

/**
 * Get current solar data
 */
function getSolarData($token, $stationId) {
    // Real-time data endpoint - POST request
    $url = 'https://' . SOLAR_URL . '/station/v1.0/realTime?language=en';
    
    $postData = [
        'stationId' => $stationId
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: bearer ' . $token  // Lowercase 'bearer'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || !$response) {
        error_log("Solarman API data fetch failed: HTTP $httpCode");
        return null;
    }
    
    $data = json_decode($response, true);
    
    // Check for token expiration
    if (isset($data['msg']) && $data['msg'] === 'auth invalid token') {
        error_log("Solarman: Token expired, clearing cache");
        global $tokenCacheFile;
        if (file_exists($tokenCacheFile)) {
            unlink($tokenCacheFile);
        }
        return null;
    }
    
    // Check for success
    if (!isset($data['success']) || $data['success'] !== true) {
        error_log("Solarman API returned error: " . ($data['msg'] ?? 'Unknown'));
        return null;
    }
    
    return $data;
}

// Main execution
try {
    // Get station ID from config
    $stationId = SOLAR_STATIONID;
    
    // Get token (cached or fresh)
    $token = getAccessToken();
    if (!$token) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to authenticate with Solarman API', 'status' => 'offline']);
        exit;
    }
    
    // Get solar data
    $solarData = getSolarData($token, $stationId);
    if (!$solarData) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch solar data', 'status' => 'offline']);
        exit;
    }
    
    // Extract metrics - matching ESP32 field names
    // Convert from W to kW for display
    $result = [
        'current_power' => isset($solarData['generationPower']) ? $solarData['generationPower'] : 0,  // Solar generation in W
        'battery_soc' => isset($solarData['batterySoc']) ? $solarData['batterySoc'] : null,          // Battery % 
        'battery_power' => isset($solarData['batteryPower']) ? $solarData['batteryPower'] : 0,       // Battery power in W
        'using_power' => isset($solarData['usePower']) ? $solarData['usePower'] : 0,                 // Power consumption in W
        'grid_power' => isset($solarData['wirePower']) ? $solarData['wirePower'] : 0,                // Grid power in W (+ = import, - = export)
        'last_update' => isset($solarData['lastUpdateTime']) ? date('Y-m-d H:i:s', $solarData['lastUpdateTime']) : date('Y-m-d H:i:s'),
        'status' => 'online'
    ];
    
    echo json_encode($result);
    
} catch (Exception $e) {
    error_log("Solarman API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error', 'status' => 'offline']);
}
?>