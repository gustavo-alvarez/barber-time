<?php
/**
 * PLANTILLA de configuración — Barber-Time
 * ----------------------------------------
 * ⚠️ RENOMBRA este archivo a "database.php" (sin la palabra example)
 *    y rellena tus datos reales. El database.php REAL nunca
 *    se sube a GitHub (está en .gitignore).
 *
 * Con este ejemplo ya corre en XAMPP sin tocar nada.
 */

// Zona horaria: todas las horas de citas se manejan en hora local del negocio
// ⚠️ Cambia 'America/Bogota' por la ciudad de tu barbería si es otra.
date_default_timezone_set('America/Bogota');

// Detectar si estamos en localhost (XAMPP) o en un servidor real.
// OJO: al entrar desde el celular por IP (ej. http://192.168.40.32/el proyecto),
// el servidor reporta la IP como nombre, NO "localhost": por eso también
// se considera local cualquier acceso por IP. En cPanel se entra por dominio.
function esLocalhost(string $host): bool
{
    // trim() y quitar el ":puerto" solo si es host:puerto (IPv6 '::1' tiene DOS ":" y no se toca)
    $host = strtolower(trim($host));
    if (substr_count($host, ':') === 1) {
        $host = explode(':', $host)[0];
    }
    return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
        || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
}

$hostActual = $_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
define('ES_LOCALHOST', esLocalhost($hostActual));

if (ES_LOCALHOST) {
    // ---- Entorno local (XAMPP) ----
    define('DB_HOST', 'localhost');
    define('DB_USER', 'root');
    define('DB_PASS', '');          // XAMPP no trae contraseña por defecto
    define('DB_NAME', 'barber_time');
    define('DB_CHARSET', 'utf8mb4'); // Soporta acentos, ñ y emojis
} else {
    // ---- Entorno de producción (cPanel) ----
    // Los datos exactos los crea el hosting (usualmente USUARIOCPANEL_nombredb)
    define('DB_HOST', 'localhost');  // En cPanel casi siempre es "localhost"
    define('DB_USER', 'USUARIO_CPANEL_barber');
    define('DB_PASS', 'CAMBIAR_ESTA_CONTRASENA');
    define('DB_NAME', 'USUARIO_CPANEL_barber_time');
    define('DB_CHARSET', 'utf8mb4');
}
