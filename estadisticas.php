<?php
/**
 * ESTADÍSTICAS (solo admin)
 * -------------------------
 * Radiografía del negocio, con cálculos SQL (nada en JS):
 *   • Tarjetas: ingresos y citas completadas del MES, citas y
 *     pendientes de HOY.
 *   • TOPS de los últimos 90 días: servicios, barberos y clientes
 *     MÁS solicitados (por citas completadas).
 *   • Gráfica de los últimos 6 MESES de ingresos (solo CSS: barras
 *     cuya altura es un % del mes más fuerte).
 * Ingresos = suma del precio del servicio de citas COMPLETADAS
 * (lo cobrado de verdad, no lo reservado ni lo cancelado).
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/citas.php'; // fechaBonita

requiere_rol('admin');
$pdo = db();
$moneda = fn (float $v): string => '$' . number_format($v, 0, ',', '.');

$hoy    = date('Y-m-d');
$mesIni = date('Y-m-01');
$desde90 = date('Y-m-d', strtotime('-90 days'));

/* ---------------- Tarjetas ---------------- */
$ingMes = (float) $pdo->query(
    "SELECT COALESCE(SUM(s.precio), 0)
     FROM citas c JOIN servicios s ON s.id = c.servicio_id
     WHERE c.estado = 'completada' AND c.fecha >= '$mesIni'"
)->fetchColumn();

$comMes = (int) $pdo->query(
    "SELECT COUNT(*)
     FROM citas c JOIN servicios s ON s.id = c.servicio_id
     WHERE c.estado = 'completada' AND c.fecha >= '$mesIni'"
)->fetchColumn();

$citasHoy = (int) $pdo->query(
    "SELECT COUNT(*) FROM citas WHERE fecha = '$hoy' AND estado <> 'cancelada'"
)->fetchColumn();

$pendHoy = (int) $pdo->query(
    "SELECT COUNT(*) FROM citas WHERE fecha = '$hoy' AND estado IN ('pendiente', 'confirmada')"
)->fetchColumn();

/* ---------------- TOPS (últimos 90 días, por completadas) ---------------- */
$topServicios = $pdo->query(
    "SELECT s.nombre, COUNT(*) AS n, SUM(s.precio) AS ing
     FROM citas c JOIN servicios s ON s.id = c.servicio_id
     WHERE c.estado = 'completada' AND c.fecha >= '$desde90'
     GROUP BY c.servicio_id
     ORDER BY n DESC, ing DESC LIMIT 5"
)->fetchAll();

$topBarberos = $pdo->query(
    "SELECT b.nombre, b.emoji, COUNT(*) AS n, COALESCE(SUM(s.precio), 0) AS ing
     FROM citas c
     JOIN barberos b ON b.id = c.barbero_id
     JOIN servicios s ON s.id = c.servicio_id
     WHERE c.estado = 'completada' AND c.fecha >= '$desde90'
     GROUP BY b.id
     ORDER BY n DESC LIMIT 5"
)->fetchAll();

$topClientes = $pdo->query(
    "SELECT COALESCE(u.nombre, c.visitante, 'Cliente de la calle') AS cliente,
            COUNT(*) AS n, COALESCE(SUM(s.precio), 0) AS ing
     FROM citas c
     LEFT JOIN usuarios u ON u.id = c.usuario_id
     JOIN servicios s ON s.id = c.servicio_id
     WHERE c.estado = 'completada' AND c.fecha >= '$desde90'
     GROUP BY cliente
     ORDER BY n DESC LIMIT 5"
)->fetchAll();

/* ---------------- Gráfica de 6 meses (ingresos) ---------------- */
$mesesNombre = [1 => 'ene', 2 => 'feb', 3 => 'mar', 4 => 'abr', 5 => 'may',
                6 => 'jun', 7 => 'jul', 8 => 'ago', 9 => 'sep', 10 => 'oct',
                11 => 'nov', 12 => 'dic'];
$ini6 = date('Y-m-01', strtotime('-5 months'));
$porMes = [];
$q = $pdo->query(
    "SELECT DATE_FORMAT(c.fecha, '%Y-%m') AS ym, COALESCE(SUM(s.precio), 0) AS ing
     FROM citas c JOIN servicios s ON s.id = c.servicio_id
     WHERE c.estado = 'completada' AND c.fecha >= '$ini6'
     GROUP BY ym"
);
foreach ($q->fetchAll() as $f) { $porMes[$f['ym']] = (float) $f['ing']; }

$meses = []; // [nombre, valor]
for ($i = 5; $i >= 0; $i--) {
    $d = new DateTime('first day of this month');
    $d->modify("-$i months");
    $ym = $d->format('Y-m');
    $meses[] = [$mesesNombre[(int) $d->format('n')], $porMes[$ym] ?? 0.0];
}
$maxMes = max($meses[0][1], $meses[1][1], $meses[2][1], $meses[3][1], $meses[4][1], $meses[5][1]);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#111111">
    <link rel="icon" href="assets/icono.svg" type="image/svg+xml">
    <link rel="manifest" href="manifest.php">
    <title>Estadísticas · <?= e(nombreNegocio()) ?></title>
    <link rel="stylesheet" href="assets/css/estilos.css">
</head>
<body>
<div class="container">

    <?= mostrar_flash() ?>

    <h1 style="margin-top:1rem">📊 Estadísticas</h1>
    <p class="texto-suave">
        Radiografía del negocio · <a href="admin.php">⬅ volver al panel</a>
    </p>
    <hr>

    <!-- Tarjetas del mes -->
    <div class="resumen">
        <div><strong style="color:var(--dorado)" id="kpi-ingresos"><?= e($moneda($ingMes)) ?></strong><span class="texto-suave"><br>💰 ingresos del mes</span></div>
        <div><strong id="kpi-completadas"><?= $comMes ?></strong><br><span class="texto-suave">✔ completadas (mes)</span></div>
        <div><strong><?= $citasHoy ?></strong><br><span class="texto-suave">📆 citas hoy</span></div>
        <div><strong style="color:var(--verde)"><?= $pendHoy ?></strong><br><span class="texto-suave">⏳ pendientes hoy</span></div>
    </div>

    <!-- Gráfica de ingresos (solo CSS, 6 meses) -->
    <h2 class="seccion">📈 Ingresos · últimos 6 meses</h2>
    <div class="tarjeta" style="background:#0d0d0d">
        <div class="grafica">
            <?php foreach ($meses as [$nombre, $val]): ?>
                <div class="gcol">
                    <small class="gval"><?= e($moneda($val)) ?></small>
                    <div class="gbar" style="height:<?= $maxMes > 0 ? max(round($val / $maxMes * 80), 4) : 4 ?>%"></div>
                    <span class="gmes"><?= e($nombre) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="texto-suave centro" style="font-size:.75rem;margin:.4rem 0 0">
            Solo citas completadas (lo cobrado de verdad).
        </p>
    </div>

    <!-- Top servicios -->
    <h2 class="seccion">✂️ Servicios favoritos (90 días)</h2>
    <?php if (!$topServicios): ?>
        <p class="texto-suave centro">Aún no hay citas completadas para calcular tops. 🤷</p>
    <?php endif; ?>
    <?php foreach ($topServicios as $i => $s): ?>
        <div class="tarjeta cita">
            <div>
                <strong class="top-num"><?= $i + 1 ?>º</strong> <?= e($s['nombre']) ?><br>
                <span class="texto-suave"><?= (int) $s['n'] ?> citas · <?= e($moneda((float) $s['ing'])) ?></span>
            </div>
            <div class="lado"><span class="mensaje-ok" style="font-size:.7rem"><?= $i === 0 ? '🏆' : '✂️' ?></span></div>
        </div>
    <?php endforeach; ?>

    <!-- Top barberos -->
    <h2 class="seccion">🥷 Los más solicitados (90 días)</h2>
    <?php if (!$topBarberos): ?>
        <p class="texto-suave centro">Sin datos todavía. 🤷</p>
    <?php endif; ?>
    <?php foreach ($topBarberos as $i => $b): ?>
        <div class="tarjeta cita">
            <div>
                <strong class="top-num"><?= $i + 1 ?>º</strong> <?= e($b['emoji']) ?> <?= e($b['nombre']) ?><br>
                <span class="texto-suave"><?= (int) $b['n'] ?> citas · <?= e($moneda((float) $b['ing'])) ?></span>
            </div>
            <div class="lado"><span class="mensaje-ok" style="font-size:.7rem"><?= $i === 0 ? '🏆' : '💈' ?></span></div>
        </div>
    <?php endforeach; ?>

    <!-- Top clientes -->
    <h2 class="seccion">👥 Clientes estrella (90 días)</h2>
    <?php if (!$topClientes): ?>
        <p class="texto-suave centro">Sin datos todavía. 🤷</p>
    <?php endif; ?>
    <?php foreach ($topClientes as $i => $c): ?>
        <div class="tarjeta cita">
            <div>
                <strong class="top-num"><?= $i + 1 ?>º</strong> <?= e($c['cliente']) ?><br>
                <span class="texto-suave"><?= (int) $c['n'] ?> citas · <?= e($moneda((float) $c['ing'])) ?></span>
            </div>
            <div class="lado"><span class="mensaje-ok" style="font-size:.7rem"><?= $i === 0 ? '⭐' : '👤' ?></span></div>
        </div>
    <?php endforeach; ?>

    <p class="texto-suave centro" style="margin:1rem 0 2rem">
        ℹ️ Los tops consideran solo citas <strong>completadas</strong> de los últimos 90 días.
    </p>

    <div style="height:5.5rem"></div>
</div>

<?php barraInferior('citas'); ?>
</body>
</html>

