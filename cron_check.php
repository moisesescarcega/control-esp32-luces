<?php
// Este script lo ejecuta cron cada minuto. No tiene salida HTTP, solo logs.
require_once __DIR__ . '/db.php';

define('ESP32_IP', getenv('ESP32_IP') ?: '192.168.100.25');
define('OPENWEATHERMAP_API_KEY', getenv('OPENWEATHERMAP_API_KEY') ?: '');

// Coordenadas de Coyoacán, CDMX
define('LAT', 19.3086);
define('LON', -99.1725);

const WEATHER_CHECK_INTERVAL_MIN = 12;

function logMsg(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . "] $msg\n";
}

function callESP32(string $path): bool {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'http://' . ESP32_IP . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 1500);
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, 2500);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code == 200;
}

// Devuelve true si el clima actual es nublado, según OpenWeatherMap
function isCloudyNow(): ?bool {
    if (empty(OPENWEATHERMAP_API_KEY)) {
        logMsg("OPENWEATHERMAP_API_KEY no configurada");
        return null;
    }
    $url = "https://api.openweathermap.org/data/4.0/onecall/current?lat=" . LAT .
           "&lon=" . LON . "&units=metric&lang=en&appid=" . OPENWEATHERMAP_API_KEY;

    logMsg("Consultando OpenWeatherMap...");
    $ctx = stream_context_create(['http' => ['timeout' => 5]]);
    $raw = @file_get_contents($url, false, $ctx);

    if ($raw === false) {
        logMsg("Fallo al contactar OpenWeatherMap (file_get_contents).");
        return null;
    }
    logMsg("Respuesta recibida: $raw");

    $data = json_decode($raw, true);
    $clouds = $data['data'][0]['clouds'] ?? null;

    if ($clouds === null) {
        if (isset($data['message'])) {
            logMsg("Error de OpenWeatherMap: " . $data['message']);
        } else {
            logMsg("Respuesta JSON inválida o sin datos de nubes.");
        }
        return null;
    }

    // Consideramos nublado si la nubosidad es >= 90%
    return $clouds >= 90;
}

try {
    $cfg = getConfig();
} catch (Exception $e) {
    logMsg("Error de BD: " . $e->getMessage());
    exit(1);
}

$now = new DateTime('now', new DateTimeZone('America/Mexico_City'));
$nowTime = $now->format('H:i');

// ===== 1. Programación horaria — RELÉ =====
if ($cfg['relay_schedule_enabled']) {
    $onTime  = substr($cfg['relay_schedule_on_time'], 0, 5);
    $offTime = substr($cfg['relay_schedule_off_time'], 0, 5);

    if ($nowTime === $onTime) {
        logMsg("[Relé] Hora de encendido programado ($onTime) → relay ON");
        callESP32('/relay/on');
    }
    if ($nowTime === $offTime) {
        logMsg("[Relé] Hora de apagado programado ($offTime) → relay OFF");
        callESP32('/relay/off');
    }
}

// ===== 2. Programación horaria — TIRA LED =====
if ($cfg['led_schedule_enabled']) {
    $onTime  = substr($cfg['led_schedule_on_time'], 0, 5);
    $offTime = substr($cfg['led_schedule_off_time'], 0, 5);

    if ($nowTime === $onTime) {
        logMsg("[LED] Hora de encendido programado ($onTime) → ir ON");
        callESP32('/ir/on');
    }
    if ($nowTime === $offTime) {
        logMsg("[LED] Hora de apagado programado ($offTime) → ir OFF");
        callESP32('/ir/off');
    }
}

// ===== 3. Control por clima (solo 12:00-20:00, afecta al Rele) =====
if ($cfg['weather_check_enabled']) {
    $hour = (int)$now->format('H');
    $withinWindow = ($hour >= 12 && $hour < 20);
    logMsg("Revisando control por clima. Ventana horaria (12-19h): " . ($withinWindow ? 'Si' : 'No'));

    if ($withinWindow) {
        $lastCheckEpoch = $cfg['last_weather_check_epoch'] ?? null;
        $minutesSince = $lastCheckEpoch ? ($now->getTimestamp() - $lastCheckEpoch) / 60 : 999;
        logMsg("Minutos desde ultima consulta: " . round($minutesSince));

        if ($minutesSince >= WEATHER_CHECK_INTERVAL_MIN) {
            $cloudy = isCloudyNow();

            if ($cloudy !== null) {
                $state = $cloudy ? 'nublado' : 'despejado';
                logMsg("Clima consultado: $state");

                $pdo = getDB();
                $stmt = $pdo->prepare("UPDATE config SET last_weather_check = NOW(), last_weather_state = ? WHERE id = 1");
                $stmt->execute([$state]);

                if ($cloudy) {
                    logMsg("Nublado detectado → relay ON");
                    callESP32('/relay/on');
                } elseif ($cfg['weather_auto_off']) {
                    logMsg("Despejado y auto-apagado activo → relay OFF");
                    callESP32('/relay/off');
                }
            } else {
                logMsg("No se pudo obtener clima (OpenWeatherMap sin respuesta o con error)");
            }
        }
    }
}

