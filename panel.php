<?php
/**
 * PANEL DEL CLIENTE — "Mis citas"
 * --------------------------------
 * Muestra las citas del usuario logueado (por fecha y hora).
 * La cancelación y edición llegan en la Etapa 5.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/citas.php';

requiere_login();
$usuario = usuario_actual();
$esAdmin = $usuario['rol'] === 'admin';

$pdo  = db();
$stmt = $pdo->prepare(
    "SELECT c.id, c.fecha, c.hora, c.estado, c.notas,
            s.nombre AS servicio, s.duracion_min,
            b.nombre AS barbero, b.emoji, b.foto AS barbero_foto
     FROM citas c
     JOIN servicios s ON s.id = c.servicio_id
     JOIN barberos  b ON b.id = c.barbero_id
     WHERE c.usuario_id = ?
     ORDER BY (c.fecha < CURDATE()) ASC, c.fecha ASC, c.hora ASC"
);
$stmt->execute([$usuario['id']]);
$citas = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#111111">
    <link rel="icon" href="assets/icono.svg" type="image/svg+xml">
    <link rel="manifest" href="manifest.php">
    <title>Mi panel · <?= e(nombreNegocio()) ?></title>
    <link rel="stylesheet" href="assets/css/estilos.css">
</head>
<body>
<div class="container">
    <div style="margin-top:1.5rem">
        <?= mostrar_flash() ?>

        <h1>Hola, <?= e($usuario['nombre']) ?> 👋</h1>
        <p class="texto-suave">Tu teléfono: <?= e($usuario['telefono']) ?> · <?= $esAdmin ? '🛡️ Administrador' : '👤 Cliente' ?></p>
        <?= avisoCita(citaPorRecordar($pdo, $usuario)) ?>
        <hr>

        <h2 class="seccion">📅 Mis citas</h2>

        <?php if (!$citas): ?>
            <div class="tarjeta centro" style="background:#0d0d0d">
                <p style="font-size:2rem; margin:.5rem 0">💇</p>
                <p class="texto-suave">Aún no tienes citas.<br>¡Agenda tu primer corte!</p>
                <a class="btn" href="agendar.php">📅 Agendar ahora</a>
            </div>
        <?php else: ?>
            <?php foreach ($citas as $c):
                $resta      = minutosRestantes($c['fecha'], $c['hora']);
                $cancelable = in_array($c['estado'], ['pendiente', 'confirmada'], true) && $resta >= LIMITE_CANCELAR_HORAS * 60;
            ?>
                <div class="tarjeta cita">
                    <div>
                        <strong><?= e($c['servicio']) ?></strong> · ⏱ <?= (int) $c['duracion_min'] ?> min<br>
                        <span class="texto-suave"><?= avatarMini($c['barbero_foto'], $c['emoji'], $c['barbero']) ?> <?= e($c['barbero']) ?></span><br>
                        <small class="texto-suave"><?= fechaBonita($c['fecha']) ?> · <strong style="color:var(--dorado)"><?= e(substr($c['hora'], 0, 5)) ?></strong></small>
                        <?php if ($c['notas']): ?><br><small class="texto-suave">📝 <?= e($c['notas']) ?></small><?php endif; ?>
                    </div>
                    <div class="lado">
                        <?= pillEstado($c['estado']) ?>
                        <?php if ($cancelable): ?>
                            <a class="btn btn-inline btn-rojo" href="cancelar.php?id=<?= (int) $c['id'] ?>">Cancelar</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($esAdmin): ?>
            <hr>
            <p class="texto-suave">🛡️ Eres administrador: <a href="admin.php">abrir el panel de administración</a>.</p>
        <?php endif; ?>

        <div style="height:5.5rem"></div>
    </div>
</div>

<?php barraInferior('citas'); ?>
</body>
</html>
