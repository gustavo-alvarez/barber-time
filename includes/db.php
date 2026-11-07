<?php
/**
 * FUNCIÓN DE CONEXIÓN (PDO)
 * --------------------------
 * Devuelve un objeto $pdo listo para usar en cualquier parte del proyecto.
 *
 * ¿Por qué PDO? Es la forma moderna y segura de conectarse a MySQL:
 *  - Nos protege de la inyección SQL cuando usamos consultas preparadas.
 *  - El código queda más corto y legible que con mysqli.
 *
 * Ejemplo de uso en el futuro:
 *   require_once __DIR__ . '/../includes/db.php';
 *   $pdo = db();
 */

require_once __DIR__ . '/../config/database.php';

function db(): PDO
{
    // DSN = "Data Source Name": la "dirección" que le dice a PDO dónde conectar
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            // Si hay un error en la BD, lanzamos una excepción (no fallar en silencio)
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            // Devolver siempre los resultados como arrays asociativos => $fila['nombre']
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Usar consultas preparadas nativas (más seguro)
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        // En desarrollo mostramos el error; en producción NO se debe mostrar el detalle
        if (ES_LOCALHOST) {
            die('❌ Error de conexión: ' . $e->getMessage());
        }
        die('❌ Error de conexión. Intenta más tarde.');
    }
}
