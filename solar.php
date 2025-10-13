<?php
header('Content-Type: application/json');

// Load configuration (database and Solarman credentials)
include 'vars.php';

// Cache file to store token - use web directory (where solar.php is located)
$tokenCacheFile = __DIR__ . '/cache/solarman_token.json';

// Ensure cache directory exists
$cacheDir = dirname($tokenCacheFile);
if (!is_dir($cacheDir)) {
    if (!@mkdir($cacheDir, 0755, true)) {
        error_log("Failed to create cache directory: $cacheDir");
        // Fallback to /tmp (will be cleared on reboot but better than failing)
        $tokenCacheFile = '/tmp/solarman_token.json';
    }
}

/**
 * Get access token (with caching)
 */
function getAccessToken() {
    global $tokenCacheFile;
    
    // Check if we have a cached token
    if (file_exists($tokenCacheFile)) {
        $tokenData = json_decode(file_get_contents($tokenCacheFile), true);
        
        if ($tokenData && 
            isset($tokenData['token']) && 
            isset($tokenData['expires_at']) && 
            $tokenData['expires_at'] > time()) {
            return $tokenData['token'];
        }
    }
    
    error_log("Solarman: Fetching new token from API");
    
    // Request new token - matching ESP32 format
    $authData = [
        'appSecret' => SOLAR_SECRET,
        'email' => SOLAR_USERNAME,
        'password' => SOLAR_PASSHASH
    ];
    
    // AppId goes in URL query string
    $url = 'https://' . SOLAR_URL . '/account/v1.0/token?appId=' . SOLAR_APPID;
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($authData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        error_log("Solarman API auth curl error: $curlError");
        return null;
    }
    
    if ($httpCode !== 200 || !$response) {
        error_log("Solarman API auth failed: HTTP $httpCode, Response: $response");
        return null;
    }
    
    $data = json_decode($response, true);
    
    // Check for errors
    if (isset($data['success']) && $data['success'] === false) {
        error_log("Solarman API auth failed: " . ($data['msg'] ?? 'Unknown error'));
        return null;
    }
    
    if (!isset($data['access_token'])) {
        error_log("Solarman API: No access token in response: " . json_encode($data));
        return null;
    }
    
    $accessToken = $data['access_token'];
    
    // Determine expiration (default to 23 hours if not provided)
    $expiresIn = isset($data['expires_in']) ? $data['expires_in'] : (23 * 3600);
    
    // Cache token
    $tokenData = [
        'token' => $accessToken,
        'expires_at' => time() + $expiresIn,
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    if (file_put_contents($tokenCacheFile, json_encode($tokenData)) === false) {
        error_log("Solarman: Failed to write token cache");
    } else {
        error_log("Solarman: Token cached successfully");
    }
    
    return $accessToken;
}

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