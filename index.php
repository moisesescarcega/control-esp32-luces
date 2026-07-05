<?php
require_once __DIR__ . '/db.php';

define('ESP32_IP', getenv('ESP32_IP') ?: '192.168.100.25');

// ===== API =====
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    // --- Config: obtener estado actual de programación/clima ---
    if ($action === 'get_config') {
        try {
            echo json_encode(getConfig());
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Error de base de datos']);
        }
        exit;
    }

    // --- Config: guardar horario ---
    if ($action === 'set_schedule') {
        $on  = $_GET['on']  ?? null;
        $off = $_GET['off'] ?? null;
        if (!$on || !$off) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Faltan parámetros']);
            exit;
        }
        try {
            $pdo = getDB();
            $stmt = $pdo->prepare("UPDATE config SET schedule_on_time = ?, schedule_off_time = ? WHERE id = 1");
            $stmt->execute([$on, $off]);
            echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Error al guardar']);
        }
        exit;
    }

    // --- Config: activar/desactivar toggles ---
    if ($action === 'set_toggle') {
        $field = $_GET['field'] ?? '';
        $value = ($_GET['value'] ?? '0') === '1' ? 'true' : 'false';
        $allowed = ['schedule_enabled', 'weather_check_enabled', 'weather_auto_off'];
        if (!in_array($field, $allowed, true)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Campo inválido']);
            exit;
        }
        try {
            $pdo = getDB();
            $pdo->exec("UPDATE config SET $field = $value WHERE id = 1");
            echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Error al guardar']);
        }
        exit;
    }

    // --- Rutas hacia el ESP32 ---
    $routes = [
        'relay_on'  => '/relay/on',
        'relay_off' => '/relay/off',
        'ir_on'     => '/ir/on',
        'ir_off'    => '/ir/off',
        'status'    => '/status',
    ];

    if (!array_key_exists($action, $routes)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Acción inválida']);
        exit;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'http://' . ESP32_IP . $routes[$action]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 1500);
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, 2500);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code == 200) {
        $json_data = json_decode($response, true);
        if ($json_data !== null) {
            echo $response;
        } else {
            echo json_encode(['status' => 'success', 'payload' => $response]);
        }
    } else {
        http_response_code(502);
        echo json_encode(['status' => 'error', 'message' => 'ESP32 inaccesible']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Control Hub</title>
  <link rel="manifest" href="data:application/manifest+json,{'name':'Control Luces Hub','short_name':'Luces','start_url':'/','display':'standalone','background_color':'#1a1a2e','theme_color':'#1a1a2e'}">
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:sans-serif;background:#1a1a2e;color:#eee;display:flex;flex-direction:column;align-items:center;padding:2rem 1rem;min-height:100vh}
    h1{margin-bottom:1.5rem;font-size:1.5rem;color:#a0c4ff}
    .card{background:#16213e;border-radius:12px;padding:1.5rem;width:100%;max-width:360px;margin-bottom:1.5rem;border:1px solid #0f3460}
    h2{font-size:1rem;color:#a0c4ff;margin-bottom:1rem}
    .btn{display:block;width:100%;padding:1rem;border:none;border-radius:8px;font-size:1.1rem;font-weight:bold;cursor:pointer;margin-bottom:.6rem;transition:opacity 0.1s}
    .btn:active{opacity:.6}
    .btn-on{background:#4caf50;color:#fff}
    .btn-off{background:#e53935;color:#fff}
    .btn-save{background:#0f3460;color:#a0c4ff;border:1px solid #a0c4ff}
    .status{font-size:.85rem;color:#aaa;text-align:center;margin-top:.5rem}
    .error-bar{background:#e53935;color:white;padding:.5rem;border-radius:6px;margin-bottom:1rem;font-size:.85rem;display:none;width:100%;max-width:360px;text-align:center}
    label{font-size:.85rem;color:#aaa;display:block;margin-bottom:.3rem}
    input[type="time"]{background:#0f3460;border:1px solid #a0c4ff;color:#eee;border-radius:6px;padding:.4rem .6rem;font-size:.95rem;width:100%;margin-bottom:.8rem}
    .toggle-row{display:flex;align-items:center;gap:.5rem;margin-bottom:.8rem}
    .toggle-row input{width:auto;margin:0}
    .toggle-row label{margin:0}
  </style>
</head>
<body>
  <h1>💡 Panel Domótico (Hub)</h1>
  <div id="error-msg" class="error-bar"></div>

  <div class="card">
    <h2>🔌 Foco (Relé)</h2>
    <button class="btn btn-on" onclick="sendCmd('relay_on')">Encender</button>
    <button class="btn btn-off" onclick="sendCmd('relay_off')">Apagar</button>
    <p class="status" id="relay-status">Estado: Verificando...</p>
  </div>

  <div class="card">
    <h2>🌈 Tira LED (IR)</h2>
    <button class="btn btn-on" onclick="sendCmd('ir_on')">Encender</button>
    <button class="btn btn-off" onclick="sendCmd('ir_off')">Apagar</button>
    <p class="status" id="led-status">Estado: Verificando...</p>
  </div>

  <div class="card">
    <h2>⏰ Programación (Foco)</h2>
    <div class="toggle-row">
      <input type="checkbox" id="scheduleEnabled" onchange="setToggle('schedule_enabled', this.checked)">
      <label for="scheduleEnabled">Programación activa</label>
    </div>
    <label>Hora de encendido</label>
    <input type="time" id="onTime" value="18:30">
    <label>Hora de apagado</label>
    <input type="time" id="offTime" value="22:30">
    <button class="btn btn-save" onclick="saveSchedule()">Guardar horario</button>
    <p class="status" id="schedule-status"></p>
  </div>

  <div class="card">
    <h2>☁️ Control por Clima</h2>
    <p class="status" style="margin-bottom:.8rem">Enciende el foco si detecta clima nublado (solo entre 12:00–19:00, Coyoacán)</p>
    <div class="toggle-row">
      <input type="checkbox" id="weatherEnabled" onchange="setToggle('weather_check_enabled', this.checked)">
      <label for="weatherEnabled">Activar consulta de clima</label>
    </div>
    <div class="toggle-row">
      <input type="checkbox" id="weatherAutoOff" onchange="setToggle('weather_auto_off', this.checked)">
      <label for="weatherAutoOff">Apagar automático si despeja</label>
    </div>
    <p class="status" id="weather-status">Último estado: --</p>
  </div>

  <script>
    const errDiv = document.getElementById('error-msg');

    function sendCmd(action) {
      errDiv.style.display = 'none';
      fetch(`?action=${action}`)
        .then(r => {
          if (!r.ok) throw new Error('Fallo en la comunicación con el Hub/ESP32');
          return r.json();
        })
        .then(data => {
          if (action === 'status' || data.relay !== undefined) {
             updateUI(data);
          } else {
             setTimeout(checkStatus, 200);
          }
        })
        .catch(err => {
          errDiv.textContent = err.message;
          errDiv.style.display = 'block';
        });
    }

    function updateUI(data) {
      document.getElementById('relay-status').textContent = 'Estado: ' + (data.relay ? 'ON' : 'OFF');
      document.getElementById('led-status').textContent = 'Estado: ' + (data.led ? 'ON' : 'OFF');
    }

    function checkStatus() {
      fetch('?action=status')
        .then(r => r.json())
        .then(data => updateUI(data))
        .catch(() => {
          document.getElementById('relay-status').textContent = 'Estado: Desconectado';
          document.getElementById('led-status').textContent = 'Estado: Desconectado';
        });
    }

    function setToggle(field, checked) {
      fetch(`?action=set_toggle&field=${field}&value=${checked ? '1' : '0'}`)
        .catch(() => {
          errDiv.textContent = 'No se pudo guardar la configuración';
          errDiv.style.display = 'block';
        });
    }

    function saveSchedule() {
      const on  = document.getElementById('onTime').value;
      const off = document.getElementById('offTime').value;
      fetch(`?action=set_schedule&on=${on}&off=${off}`)
        .then(r => r.json())
        .then(data => {
          document.getElementById('schedule-status').textContent =
            data.status === 'success' ? `Horario guardado: ${on} – ${off}` : 'Error al guardar';
        })
        .catch(() => {
          document.getElementById('schedule-status').textContent = 'Error al guardar';
        });
    }

    function loadConfig() {
      fetch('?action=get_config')
        .then(r => r.json())
        .then(cfg => {
          document.getElementById('scheduleEnabled').checked = cfg.schedule_enabled;
          document.getElementById('onTime').value  = cfg.schedule_on_time.slice(0,5);
          document.getElementById('offTime').value = cfg.schedule_off_time.slice(0,5);
          document.getElementById('weatherEnabled').checked = cfg.weather_check_enabled;
          document.getElementById('weatherAutoOff').checked = cfg.weather_auto_off;
          if (cfg.last_weather_state) {
            document.getElementById('weather-status').textContent =
              `Último estado: ${cfg.last_weather_state} (${cfg.last_weather_check ?? '--'})`;
          }
        })
        .catch(() => {
          errDiv.textContent = 'No se pudo cargar la configuración';
          errDiv.style.display = 'block';
        });
    }

    checkStatus();
    loadConfig();
    setInterval(checkStatus, 10000);
  </script>
</body>
</html>
