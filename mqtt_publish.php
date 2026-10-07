<?php
require_once 'vars.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$payload = isset($_POST['payload']) ? trim($_POST['payload']) : '';
// Topic is fixed server-side so callers can only ever drive the AC
$topic   = MQTT_AC_TOPIC;

if ($payload === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing payload']);
    exit;
}

// Validate payload is valid JSON
$decoded = json_decode($payload);
if ($decoded === null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Payload must be valid JSON']);
    exit;
}

// Sanitise topic — allow only safe characters
if (!preg_match('/^[a-zA-Z0-9\/\-_]+$/', $topic)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid topic']);
    exit;
}

// Credentials go in a temporary mosquitto_pub options file rather than on the
// command line, where any local user could read them from the process list
$configDir = sys_get_temp_dir() . '/mqtt_' . bin2hex(random_bytes(8));
mkdir($configDir, 0700);
$configFile = $configDir . '/mosquitto_pub';
file_put_contents($configFile, '-u ' . MQTT_USER . "\n" . '-P ' . MQTT_PASS . "\n");
chmod($configFile, 0600);

// Build mosquitto_pub command — all arguments individually escaped
$cmd = sprintf(
    'XDG_CONFIG_HOME=%s mosquitto_pub -h %s -p %d -t %s -m %s 2>&1',
    escapeshellarg($configDir),
    escapeshellarg(MQTT_HOST),
    (int) MQTT_PORT,
    escapeshellarg($topic),
    escapeshellarg($payload)
);

$output = [];
$returnCode = 0;
exec($cmd, $output, $returnCode);

unlink($configFile);
rmdir($configDir);

if ($returnCode !== 0) {
    error_log('mosquitto_pub failed: ' . implode(' ', $output));
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to publish message']);
    exit;
}

echo json_encode(['ok' => true]);
