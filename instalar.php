<?php
/**
 * INSTALADOR — Etapa 2 y 3
 * -------------------------
 * Ejecuta el esquema (includes/schema.php) y llena los datos iniciales.
 * Idempotente: se puede ejecutar varias veces sin romper ni duplicar nada.
 *
 * En producción se ejecuta UNA vez en el hosting y luego se ELIMINA
 * este archivo por seguridad.
 */

require_once __DIR__ . '/includes/auth.php';   // brinda db() y las funciones auxiliares
require_once __DIR__ . '/includes/schema.php'; // definición de tablas y datos de ejemplo

$pasos  = [];
$error  = null;

try {
    $pdo    = db();
    $pasos  = array_merge($pasos, crear_tablas($pdo));
    $pasos  = array_merge($pasos, datos_iniciales($pdo));
} catch (Throwable $ex) {
    $error = $ex->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Barber-Time · Instalador</title>
    <link rel="stylesheet" href="assets/css/estilos.css">
</head>
<body>
<div class="container">
    <div class="tarjeta" style="margin-top:2rem">
        <h1>💈 Barber-Time</h1>
        <p class="texto-suave">Instalador de la base de datos</p>
        <hr>

        <?php if ($error): ?>
            <div class="alerta alerta-mal">
                ❌ <strong>Error:</strong><br><?= e($error) ?>
            </div>
            <p class="texto-suave">Causas probables: MySQL apagado en XAMPP, o falta crear la base de datos <code>barber_time</code>.</p>
        <?php else: ?>
            <?php foreach ($pasos as $texto): ?>
                <p class="alerta alerta-ok"><?= e($texto) ?></p>
            <?php endforeach; ?>
            <hr>
            <p>👤 <strong>Cuenta de administrador de prueba</strong></p>
            <p class="texto-suave">Email: <code>admin@barber-time.com</code><br>Contraseña: <code>admin123</code></p>
            <p class="alerta alerta-mal">⚠️ Antes de subir a producción cambiaremos la contraseña y borraremos este archivo.</p>
            <a class="btn" href="index.php">Ver la página de inicio →</a>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
