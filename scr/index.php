<?php
// Dirección IP estática del ESP32 satélite
define('ESP32_IP', '192.168.100.201');

// Procesar el puente de comandos (Backend)
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];
    
    // Mapeo de acciones a rutas del ESP32
    $routes = [
        'relay_on'  => '/relay/on',
        'relay_off' => '/relay/off',
        'ir_on'     => '/ir/on',
        'ir_off'    => '/ir/off',
        'status'    => '/status'
    ];

    if (!array_key_exists($action, $routes)) {
        echo json_encode(['status' => 'error', 'message' => 'Acción inválida']);
        exit;
    }

    // Petición cURL ultra rápida con timeout estricto para no bloquear
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'http://' . ESP32_IP . $routes[$action]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 800); // 0.8 segundos máx para conectar
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, 1500);        // 1.5 segundos máx de espera
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code == 200) {
        // Si el ESP32 responde con JSON (en status), lo pasamos directo, si no, mandamos OK
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
    .status{font-size:.85rem;color:#aaa;text-align:center;margin-top:.5rem}
    .error-bar{background:#e53935;color:white;padding:.5rem;border-radius:6px;margin-bottom:1rem;font-size:.85rem;display:none;width:100%;max-width:360px;text-align:center}
  </style>
</head>
<body>
  <h1>💡 Panel Domótico (Hub)</h1>
  
  <div id="error-msg" class="error-bar"></div>

  <!-- FOCO RELÉ -->
  <div class="card">
    <h2>🔌 Foco (Relé)</h2>
    <button class="btn btn-on" onclick="sendCmd('relay_on')">Encender</button>
    <button class="btn btn-off" onclick="sendCmd('relay_off')">Apagar</button>
    <p class="status" id="relay-status">Estado: Verificando...</p>
  </div>

  <!-- TIRA LED IR -->
  <div class="card">
    <h2>🌈 Tira LED (IR)</h2>
    <button class="btn btn-on" onclick="sendCmd('ir_on')">Encender</button>
    <button class="btn btn-off" onclick="sendCmd('ir_off')">Apagar</button>
    <p class="status" id="led-status">Estado: Verificando...</p>
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
             // Si fue un comando exitoso, refrescar estado tras 200ms
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

    // Verificar estado inicial al cargar la página en Android
    checkStatus();
    // Auto-refrescar cada 10 segundos para mantener sincronía
    setInterval(checkStatus, 10000);
  </script>
</body>
</html>