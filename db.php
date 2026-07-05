<?php
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $host = getenv('DB_HOST');
        $port = getenv('DB_PORT') ?: '5432';
        $name = getenv('DB_NAME');
        $user = getenv('DB_USER');
        $pass = getenv('DB_PASS');

        $dsn = "pgsql:host=$host;port=$port;dbname=$name";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

// Crea la tabla de configuración si no existe, con una sola fila (id=1)
// Horarios separados para Relé y Tira LED
function ensureConfigTable(): void {
    $pdo = getDB();
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS config (
            id INT PRIMARY KEY DEFAULT 1,

            relay_schedule_on_time  TIME NOT NULL DEFAULT '18:30',
            relay_schedule_off_time TIME NOT NULL DEFAULT '22:30',
            relay_schedule_enabled  BOOLEAN NOT NULL DEFAULT false,

            led_schedule_on_time  TIME NOT NULL DEFAULT '18:30',
            led_schedule_off_time TIME NOT NULL DEFAULT '22:30',
            led_schedule_enabled  BOOLEAN NOT NULL DEFAULT false,

            weather_check_enabled BOOLEAN NOT NULL DEFAULT true,
            weather_auto_off      BOOLEAN NOT NULL DEFAULT false,
            last_weather_check TIMESTAMP NULL,
            last_weather_state TEXT NULL,

            CONSTRAINT single_row CHECK (id = 1)
        );
    ");

    $count = $pdo->query("SELECT COUNT(*) AS c FROM config")->fetch()['c'];
    if ($count == 0) {
        $pdo->exec("INSERT INTO config (id) VALUES (1);");
    }
}

function getConfig(): array {
    ensureConfigTable();
    return getDB()->query("SELECT * FROM config WHERE id = 1")->fetch();
}
