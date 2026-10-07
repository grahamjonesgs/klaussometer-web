<?php
// Daily house report - run from cron once a day (as www-data):
//   php /var/www/watsonia22.com/scripts/daily_report.php
// Summarises yesterday's sensor and solar data with Claude and writes
// cache/daily_report.json, which report.php serves to current.html.
// Use --dry-run to print the data sent to Claude without calling the API.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/../vars.php';
require_once __DIR__ . '/../solarman_lib.php';

use Anthropic\Client;

// The server and database run on UTC; the report uses the house's local time
define('LOCAL_TZ', 'Africa/Johannesburg');
date_default_timezone_set(LOCAL_TZ);

define('REPORT_FILE', __DIR__ . '/../cache/daily_report.json');
define('REPORT_MODEL', 'claude-opus-5-5');

// Same names as js/config.js
$roomNames = [
    'livingroom' => 'Living Room',
    'bedroom' => 'Bedroom',
    'guest' => 'Playroom',
    'cave' => 'Cave',
    'outside' => 'Outside',
    // The CO2/PM2.5 sensor still publishes as "kitchen" but now sits in the bedroom
    'kitchen' => 'Bedroom'
];

$typeNames = [
    'tempset-ambient' => 'temperature (°C)',
    'tempset-humidity' => 'humidity (%)',
    'co2' => 'CO2 (ppm)',
    'pm25' => 'PM2.5 (µg/m³)',
    'battery' => 'sensor battery (V)'
];

$dryRun = in_array('--dry-run', $argv, true);
$day = date('Y-m-d', strtotime('yesterday'));

function roomName($roomId) {
    global $roomNames;
    return $roomNames[$roomId] ?? $roomId;
}

function fail($message) {
    fwrite(STDERR, "daily_report: $message\n");
    error_log("daily_report: $message");
    exit(1);
}

// Yesterday's hourly stats per room and sensor type, compared with the
// previous 7 days
function getSensorSummary($conn, $day) {
    global $typeNames;

    // Local day boundaries converted to UTC for the database
    $utc = new DateTimeZone('UTC');
    $start = (new DateTime($day))->setTimezone($utc)->format('Y-m-d H:i:s');
    $end = (new DateTime($day . ' +1 day'))->setTimezone($utc)->format('Y-m-d H:i:s');

    $stmt = $conn->prepare("SELECT room_id, type, dt_hour, avg_value
        FROM hourly_avg WHERE dt_hour >= ? AND dt_hour < ? ORDER BY room_id, type, dt_hour");
    $stmt->bind_param("ss", $start, $end);
    $stmt->execute();
    $result = $stmt->get_result();

    $series = [];
    while ($row = $result->fetch_assoc()) {
        $localHour = (int) (new DateTime($row['dt_hour'], $utc))->setTimezone(new DateTimeZone(LOCAL_TZ))->format('G');
        $series[$row['room_id']][$row['type']][$localHour] = (float) $row['avg_value'];
    }
    $stmt->close();

    // daily_avg days are UTC days; close enough for a weekly comparison

    $stmt = $conn->prepare("SELECT room_id, type, AVG(avg_value) AS week_avg
        FROM daily_avg WHERE dt_day BETWEEN ? - INTERVAL 7 DAY AND ? - INTERVAL 1 DAY
        GROUP BY room_id, type");
    $stmt->bind_param("ss", $day, $day);
    $stmt->execute();
    $result = $stmt->get_result();

    $weekAvg = [];
    while ($row = $result->fetch_assoc()) {
        $weekAvg[$row['room_id']][$row['type']] = (float) $row['week_avg'];
    }
    $stmt->close();

    $lines = [];
    foreach ($series as $roomId => $types) {
        foreach ($types as $type => $hours) {
            $min = min($hours);
            $max = max($hours);
            $line = sprintf("%s %s: avg %.1f, min %.1f at %02d:00, max %.1f at %02d:00",
                roomName($roomId), $typeNames[$type] ?? $type,
                array_sum($hours) / count($hours),
                $min, array_search($min, $hours), $max, array_search($max, $hours));
            if (isset($weekAvg[$roomId][$type])) {
                $line .= sprintf(" (previous 7-day avg %.1f)", $weekAvg[$roomId][$type]);
            }
            if (count($hours) < 24) {
                $line .= sprintf(" [only %d of 24 hours reported]", count($hours));
            }
            $lines[] = $line;
        }
    }
    return $lines;
}

// Sensors that have stopped reporting, and the outside sensor battery trend
function getSensorHealth($conn) {
    $lines = [];

    $result = $conn->query("SELECT room_id, type, MAX(dt_hour) AS last_seen
        FROM hourly_avg WHERE dt_hour >= NOW() - INTERVAL 14 DAY
        GROUP BY room_id, type HAVING last_seen < NOW() - INTERVAL 3 HOUR");
    while ($result && $row = $result->fetch_assoc()) {
        $lines[] = sprintf("%s %s has not reported since %s",
            roomName($row['room_id']), $row['type'], $row['last_seen']);
    }

    $result = $conn->query("SELECT room_id, dt_day, avg_value FROM daily_avg
        WHERE type = 'battery' AND dt_day >= CURDATE() - INTERVAL 30 DAY
        ORDER BY room_id, dt_day");
    $battery = [];
    while ($result && $row = $result->fetch_assoc()) {
        $battery[$row['room_id']][$row['dt_day']] = (float) $row['avg_value'];
    }
    foreach ($battery as $roomId => $days) {
        $first = reset($days);
        $last = end($days);
        $lines[] = sprintf("%s sensor battery: %.2f V on %s, %.2f V on %s",
            roomName($roomId), $first, array_key_first($days), $last, array_key_last($days));
    }

    if (!$lines) {
        $lines[] = "All sensors reporting normally";
    }
    return $lines;
}

function solarmanHistory($token, $day, $timeType) {
    $ch = curl_init('https://' . SOLAR_URL . '/station/v1.0/history?language=en');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: bearer ' . $token],
        CURLOPT_POSTFIELDS => json_encode([
            'stationId' => SOLAR_STATIONID,
            'startTime' => $day,
            'endTime' => $day,
            'timeType' => $timeType  // 1 = 5-minute readings, 2 = daily totals
        ])
    ]);
    $data = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return ($data['success'] ?? false) ? ($data['stationDataItems'] ?? []) : null;
}

function getSolarSummary($day) {
    $token = getAccessToken();
    if (!$token) {
        return ["Solar data unavailable"];
    }

    $lines = [];
    $totals = solarmanHistory($token, $day, 2);
    if ($totals) {
        $t = $totals[0];
        $lines[] = sprintf("Solar generated %.1f kWh; house used %.1f kWh; bought from grid %.1f kWh; exported to grid %.1f kWh",
            $t['generationValue'] ?? 0, $t['useValue'] ?? 0, $t['buyValue'] ?? 0, $t['gridValue'] ?? 0);
        $lines[] = sprintf("Home battery charged %.1f kWh, discharged %.1f kWh",
            $t['chargeValue'] ?? 0, $t['dischargeValue'] ?? 0);
    }

    $readings = solarmanHistory($token, $day, 1);
    if ($readings) {
        $minSoc = 101;
        $maxSoc = -1;
        $fullAt = null;
        $peakGen = 0;
        $peakAt = null;
        foreach ($readings as $r) {
            // Solarman's day starts an hour early (23:00); keep the local day only
            if (date('Y-m-d', $r['dateTime']) !== $day) {
                continue;
            }
            $time = date('H:i', $r['dateTime']);
            $soc = $r['batterySoc'] ?? null;
            if ($soc !== null) {
                if ($soc < $minSoc) { $minSoc = $soc; $minSocAt = $time; }
                if ($soc > $maxSoc) { $maxSoc = $soc; }
                if ($soc >= 99 && $fullAt === null) { $fullAt = $time; }
            }
            if (($r['generationPower'] ?? 0) > $peakGen) {
                $peakGen = $r['generationPower'];
                $peakAt = $time;
            }
        }
        if ($maxSoc >= 0) {
            $lines[] = sprintf("Home battery level: lowest %d%% at %s, highest %d%%%s",
                $minSoc, $minSocAt, $maxSoc, $fullAt ? ", first full at $fullAt" : ", never reached full");
        }
        if ($peakAt) {
            $lines[] = sprintf("Peak solar generation %.1f kW at %s", $peakGen / 1000, $peakAt);
        }
    }

    return $lines ?: ["Solar data unavailable"];
}

// Gather data
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    fail("database connection failed: " . $conn->connect_error);
}

$data = "Report date: " . date('l j F Y', strtotime($day)) . "\n\n"
    . "Indoor and outdoor sensors (hourly averages):\n- " . implode("\n- ", getSensorSummary($conn, $day)) . "\n\n"
    . "Sensor health:\n- " . implode("\n- ", getSensorHealth($conn)) . "\n\n"
    . "Solar and power:\n- " . implode("\n- ", getSolarSummary($day));
$conn->close();

if ($dryRun) {
    echo $data, "\n";
    exit;
}

if (!defined('ANTHROPIC_API_KEY') || ANTHROPIC_API_KEY === '') {
    fail("ANTHROPIC_API_KEY is not set in vars.php");
}

$system = <<<PROMPT
You write a short daily summary for a home dashboard, based on yesterday's
sensor and solar data. The reader is the homeowner, glancing at it over
breakfast.

Write 3 to 5 bullet points. Each starts with "- " on its own line. No heading,
no other text, no markdown formatting.

Lead with whatever is most notable or actionable: unusually high CO2 or PM2.5
(CO2 above about 1000 ppm means a room needs airing), a room much warmer or
colder than its weekly average, a sensor that stopped reporting or a sensor
battery running low, or how well solar covered the house's usage. Give
specific numbers and times. Offer a practical suggestion when there is a
clear one. Skip anything unremarkable, and don't repeat the data back
line by line. Keep each bullet to one or two short sentences.
PROMPT;

try {
    $client = new Client(apiKey: ANTHROPIC_API_KEY);
    $message = $client->beta->messages->create(
        model: REPORT_MODEL,
        maxTokens: 16000,
        system: $system,
        messages: [['role' => 'user', 'content' => $data]],
        outputConfig: ['effort' => 'low'],
        // Retry on a fallback model if the request is declined
        fallbacks: 'default',
        betas: ['server-side-fallback-2026-07-01'],
    );
} catch (\Anthropic\Core\Exceptions\APIStatusException $e) {
    fail("API error: " . $e->getMessage());
} catch (\Throwable $e) {
    fail("request failed: " . $e->getMessage());
}

if ($message->stopReason === 'refusal') {
    fail("request declined: " . ($message->stopDetails->explanation ?? 'no explanation'));
}

$text = '';
foreach ($message->content as $block) {
    if ($block->type === 'text') {
        $text .= $block->text;
    }
}
$text = trim($text);
if ($text === '') {
    fail("empty response (stop reason: {$message->stopReason})");
}

$report = [
    'date' => $day,
    'generated_at' => date('Y-m-d H:i:s'),
    'model' => $message->model,
    'text' => $text
];

$tmpFile = REPORT_FILE . '.tmp';
if (file_put_contents($tmpFile, json_encode($report)) === false || !rename($tmpFile, REPORT_FILE)) {
    fail("could not write " . REPORT_FILE);
}

echo $text, "\n";
