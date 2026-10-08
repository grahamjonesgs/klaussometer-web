<?php
// Serves the solar forecast written by scripts/solar/model.py (cron, hourly).
header('Content-Type: application/json');

$forecastFile = __DIR__ . '/scripts/solar/data/forecast.json';

if (!file_exists($forecastFile)) {
    http_response_code(404);
    echo json_encode(['error' => 'No forecast yet']);
    exit;
}

echo file_get_contents($forecastFile);
