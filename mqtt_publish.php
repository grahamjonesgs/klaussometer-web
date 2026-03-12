<?php
require_once 'vars.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$payload = isset($_POST['payload']) ? trim($_POST['payload']) : '';
$topic   = isset($_POST['topic'])   ? trim($_POST['topic'])   : MQTT_AC_TOPIC;

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

// Build mosquitto_pub command — all arguments individually escaped
$cmd = sprintf(
    'mosquitto_pub -h %s -p %d -u %s -P %s -t %s -m %s 2>&1',
    escapeshellarg(MQTT_HOST),
    (int) MQTT_PORT,
    escapeshellarg(MQTT_USER),
    escapeshellarg(MQTT_PASS),
    escapeshellarg($topic),
    escapeshellarg($payload)
);

$output = [];
$returnCode = 0;
exec($cmd, $output, $returnCode);

if ($returnCode !== 0) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'mosquitto_pub failed: ' . implode(' ', $output)]);
    exit;
}

echo json_encode(['ok' => true]);
