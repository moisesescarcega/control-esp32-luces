<?php
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $host = getenv('DB_HOST');
        $port = getenv('DB_PORT') ?: '5432';
        $name = getenv('DB_NAME');
        $user = getenv('DB_USER');
        $pass = getenv('DB_PASS');

        $dsn = "pgsql:host=$host port=$port dbname=$name";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec("SET TIME ZONE 'America/Mexico_City'");
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
    $sql = "
        SELECT
            id,
            relay_schedule_on_time,
            relay_schedule_off_time,
            relay_schedule_enabled,
            led_schedule_on_time,
            led_schedule_off_time,
            led_schedule_enabled,
            weather_check_enabled,
            weather_auto_off,
            -- last_weather_check se guarda como timestamp "naive" en hora de CDMX
            -- (porque la sesión hace SET TIME ZONE 'America/Mexico_City' antes del NOW()).
            -- Por eso hay que reinterpretarlo como America/Mexico_City, NO como UTC,
            -- para obtener la época UNIX real. Interpretarlo como UTC introducía un
            -- desfase constante de 6 horas y rompía el intervalo mínimo entre consultas.
            EXTRACT(EPOCH FROM last_weather_check AT TIME ZONE 'America/Mexico_City') as last_weather_check_epoch,
            last_weather_state
        FROM config WHERE id = 1
    ";
    return getDB()->query($sql)->fetch();
}