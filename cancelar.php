<?php
/**
 * CANCELAR CITA
 * --------------
 * Pantalla de confirmación con todos los datos de la cita y botón rojo.
 * Reglas: solo tus propias citas, y hasta LIMITE_CANCELAR_HORAS horas antes.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/citas.php';

requiere_login();
$usuario = usuario_actual();
$pdo     = db();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

// La cita debe existir Y ser del usuario logueado (la consulta filtra por ambos)
$stmt = $pdo->prepare(
    "SELECT c.*, s.nombre AS servicio, s.duracion_min, b.nombre AS barbero, b.emoji, b.foto AS barbero_foto
     FROM citas c
     JOIN servicios s ON s.id = c.servicio_id
     JOIN barberos  b ON b.id = c.barbero_id
     WHERE c.id = ? AND c.usuario_id = ?"
);
$stmt->execute([$id, $usuario['id']]);
$cita = $stmt->fetch();

// Cita inexistente o ajena: mismo mensaje, sin regalar información
if (!$cita) {
    flash('Esa cita no existe o no es tuya. 🕵️', 'mal');
    header('Location: panel.php');
    exit;
}

if (in_array($cita['estado'], ['cancelada', 'completada'], true)) {
    flash('Esta cita ya no se puede cancelar (está cancelada o completada).', 'mal');
    header('Location: panel.php');
    exit;
}

$restaMin = minutosRestantes($cita['fecha'], $cita['hora']);

// Cancelación por POST (el usuario ya pisó el botón rojo)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validar_csrf();

    if ($restaMin < LIMITE_CANCELAR_HORAS * 60) {
        flash('⏰ Solo puedes cancelar hasta ' . LIMITE_CANCELAR_HORAS . ' horas antes. ¿Emergencia? Escríbenos al WhatsApp de la barbería.', 'mal');
        header('Location: panel.php');
        exit;
    }

    $upd = $pdo->prepare("UPDATE citas SET estado = 'cancelada' WHERE id = ? AND usuario_id = ?");
    $upd->execute([$id, $usuario['id']]);
    flash('🧾 Cita cancelada: ' . fechaBonita($cita['fecha']) . ' a las ' . e(substr($cita['hora'], 0, 5)) . '.');
    header('Location: panel.php');
    exit;
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
    <title>Cancelar cita · <?= e(nombreNegocio()) ?></title>
    <link rel="stylesheet" href="assets/css/estilos.css">
</head>
<body>
<div class="container">
    <div class="tarjeta" style="margin-top:1.5rem">
        <h1>Cancelar cita ✋</h1>
        <p class="texto-suave">Revisa que sea la correcta antes de confirmar.</p>
        <hr>

        <div class="tarjeta" style="background:#0d0d0d">
            <h2><?= e($cita['servicio']) ?> · ⏱ <?= (int) $cita['duracion_min'] ?> min</h2>
            <p><?= avatarMini($cita['barbero_foto'], $cita['emoji'], $cita['barbero']) ?> <?= e($cita['barbero']) ?></p>
            <p>📅 <strong><?= fechaBonita($cita['fecha']) ?></strong><br>
               ⏰ <strong style="color:var(--dorado)"><?= e(substr($cita['hora'], 0, 5)) ?></strong></p>
            <?= pillEstado($cita['estado']) ?>
            <?php if ($cita['notas']): ?><p class="texto-suave">📝 <?= e($cita['notas']) ?></p><?php endif; ?>
        </div>

        <?php if ($restaMin < LIMITE_CANCELAR_HORAS * 60): ?>
            <div class="alerta alerta-mal" style="margin-top:1rem">
                ⏰ Faltan menos de <?= LIMITE_CANCELAR_HORAS ?> horas para tu cita, ya no se puede cancelar
                desde la app. ¿Emergencia? Escribe al WhatsApp de la barbería 📲.
            </div>
            <a class="btn btn-sec" href="panel.php">⬅ Volver a mis citas</a>
        <?php else: ?>
            <form method="post" style="margin-top:1rem">
                <?= campo_csrf() ?>
                <input type="hidden" name="id" value="<?= (int) $cita['id'] ?>">
                <button class="btn btn-rojo" type="submit">❌ Sí, cancelar mi cita</button>
            </form>
            <a class="btn btn-sec" style="margin-top:.5rem" href="panel.php">⬅ Mejor no, mantenerla</a>
        <?php endif; ?>

        <p class="texto-suave centro" style="margin-top:1rem">
            Cancelamos con <?= LIMITE_CANCELAR_HORAS ?> h de margen para que el hueco quede libre
            para otro cliente. 💈
        </p>
    </div>
</div>
</body>
</html>
