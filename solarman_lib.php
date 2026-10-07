<?php
// Shared Solarman API helpers (used by solar.php and scripts/daily_report.php)
// Requires vars.php to be loaded first

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
