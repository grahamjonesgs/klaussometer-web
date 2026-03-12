<?php
// Database (MariaDB/MySQL)
$servername = "localhost";
$username = "xxxxx";        // MySQL read-only user
$password = "xxxxx";
$dbname = "readings";

// Solarman API credentials
define('SOLAR_URL', 'globalapi.solarmanpv.com');   // Without https://
define('SOLAR_APPID', 'xxxxx');
define('SOLAR_SECRET', 'xxxxx');
define('SOLAR_USERNAME', 'xxxxx');                 // Email address
define('SOLAR_PASSHASH', 'xxxxx');                 // MD5 hash of password
define('SOLAR_STATIONID', 'xxxxx');

// MQTT broker settings
define('MQTT_HOST', '127.0.0.1');
define('MQTT_PORT', 1883);
define('MQTT_USER', 'xxxxx');
define('MQTT_PASS', 'xxxxx');
define('MQTT_AC_TOPIC', 'Kitchen/ir-ac/set');
?>
