<?php
/**
 * PÁGINA DE INICIO (catálogo público)
 * ------------------------------------
 * Primera pantalla del proyecto, pensada para el teléfono:
 * servicios con precio/duración y el equipo de barberos.
 * No requiere sesión: cualquiera puede ver el catálogo.
 */

require_once __DIR__ . '/includes/auth.php'; // trae db(), e() y la sesión
require_once __DIR__ . '/includes/citas.php'; // constantes del horario del negocio

$pdo       = db();
$servicios = $pdo->query('SELECT * FROM servicios WHERE activo = 1 ORDER BY precio')->fetchAll();
$barberos  = $pdo->query('SELECT * FROM barberos  WHERE activo = 1 ORDER BY id')->fetchAll();

$logueado  = usuario_actual();
$destinoAgendar = 'agendar.php'; // esta página pide login si hace falta

/** Formato de precio: 20000 → $20.000 */
function precioBonito($valor): string
{
    return '$' . number_format((float) $valor, 0, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#111111">
    <link rel="icon" href="assets/icono.svg" type="image/svg+xml">
    <link rel="manifest" href="manifest.php">
    <title><?= e(nombreNegocio()) ?> · Tu corte, a una cita de distancia</title>
    <link rel="stylesheet" href="assets/css/estilos.css">
</head>
<body>
<div class="container">

    <!-- Portada -->
    <header class="tarjeta hero" style="margin-top:1rem">
        <div class="marca">
            <img class="logo" src="<?= e(logoOIcono()) ?>" alt="Logo"
                 onerror="this.style.display='none'">
            <h1><?= e(nombreNegocio()) ?></h1>
        </div>
        <p>Reserva tu corte en segundos, sin llamadas ni esperas.</p>
        <a class="btn" href="<?= $destinoAgendar ?>">📅 Agendar ahora</a>
        <p class="texto-suave centro" style="margin:.6rem 0 0"><?= horarioResumen(db()) ?></p>
    </header>

    <!-- Servicios -->
    <h2 class="seccion">✂️ Nuestros servicios</h2>
    <?php foreach ($servicios as $s): ?>
        <div class="tarjeta servicio">
            <div>
                <strong><?= e($s['nombre']) ?></strong><br>
                <span class="texto-suave"><?= e($s['descripcion']) ?></span><br>
                <small class="texto-suave">⏱ <?= (int) $s['duracion_min'] ?> min</small>
            </div>
            <div class="lado">
                <span class="precio"><?= precioBonito($s['precio']) ?></span>
                <a class="btn btn-inline" href="<?= $destinoAgendar ?>">Agendar</a>
            </div>
        </div>
    <?php endforeach; ?>

    <!-- Barberos -->
    <h2 class="seccion">💈 Nuestro equipo</h2>
    <?php foreach ($barberos as $b): ?>
        <div class="tarjeta barbero">
            <?php if ($b['foto']): ?>
                <img class="avatar avatar-foto" src="<?= e($b['foto']) ?>" alt="Foto de <?= e($b['nombre']) ?>">
            <?php else: ?>
                <span class="avatar"><?= e($b['emoji']) ?></span>
            <?php endif; ?>
            <div>
                <strong><?= e($b['nombre']) ?></strong><br>
                <span class="texto-suave">✂️ <?= e($b['especialidades']) ?></span>
            </div>
        </div>
    <?php endforeach; ?>

    <!-- Contacto del negocio (solo si el admin lo configuró) -->
    <?php if (whatsappNegocio() !== '' || direccionNegocio() !== ''): ?>
        <footer class="contacto-pie tarjeta">
            <h2 class="seccion" style="margin-top:0">📍 Visítanos</h2>
            <?php if (direccionNegocio() !== ''): ?>
                <p>📍 <?= e(direccionNegocio()) ?></p>
            <?php endif; ?>
            <?php if (whatsappNegocio() !== ''): ?>
                <p>📲 <a href="https://wa.me/<?= e(whatsappNegocio()) ?>">Escríbenos al WhatsApp</a></p>
            <?php endif; ?>
        </footer>
    <?php endif; ?>

    <p class="centro texto-suave" style="margin:1.5rem 0 2rem">
        <?= $logueado
            ? 'Sesión iniciada: <a href="panel.php">ir a mi panel</a>'
            : '¿Ya eres cliente? <a href="login.php">Inicia sesión</a>' ?>
    </p>
</div>

<?php barraInferior('inicio'); ?>
</body>
</html>
