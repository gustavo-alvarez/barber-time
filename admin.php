<?php
/**
 * PANEL DE ADMINISTRADOR — Gestión de citas (Etapa 6A)
 * ------------------------------------------------------
 * Solo para rol 'admin'. Permite:
 *  - Ver todas las citas de UN día (navegable) con datos del cliente.
 *  - Resumen con contadores por estado.
 *  - Cambiar estados: confirmar, completar, cancelar.
 * La edición del catálogo (servicios/barberos) llega en la 6B.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/citas.php';

requiere_rol('admin');
$pdo = db();

/* ----------------- Cambio de estado (POST) ----------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validar_csrf();

    $citaId = (int) ($_POST['cita_id'] ?? 0);
    $accion = $_POST['accion'] ?? '';

    // Las reglas viven en UN solo sitio: transicionCita() (el admin es dueño de todo)
    if (!$citaId) {
        flash('Acción inválida. 🤨', 'mal');
    } else {
        [$ok, $msg] = transicionCita($pdo, $citaId, $accion, null);
        flash($msg, $ok ? 'ok' : 'mal');
    }

    // Volvemos al mismo día/filtro que el admin estaba viendo
    header('Location: admin.php?fecha=' . urlencode($_POST['fecha'] ?? date('Y-m-d')) . '&estado=' . urlencode($_POST['estado'] ?? 'todas'));
    exit;
}

/* ----------------- Parámetros de vista (GET) ----------------- */
$fecha = trim($_GET['fecha'] ?? date('Y-m-d'));
if (!DateTime::createFromFormat('Y-m-d|', $fecha)) {
    $fecha = date('Y-m-d');
}
$estado = trim($_GET['estado'] ?? 'todas');
if (!in_array($estado, ['todas', 'pendiente', 'confirmada', 'completada', 'ausente', 'cancelada'], true)) {
    $estado = 'todas';
}

/* ----------------- Citas del día + resumen ----------------- */
$sql = "SELECT c.id, c.hora, c.estado, c.notas, c.visitante, c.tel_visitante,
               s.nombre AS servicio, s.duracion_min,
               b.nombre AS barbero, b.emoji, b.foto AS barbero_foto,
               COALESCE(u.nombre, c.visitante, 'Cliente de la calle') AS cliente,
               COALESCE(u.telefono, c.tel_visitante, '') AS telefono
        FROM citas c
        JOIN servicios s ON s.id = c.servicio_id
        JOIN barberos  b ON b.id = c.barbero_id
        LEFT JOIN usuarios u ON u.id = c.usuario_id
        WHERE c.fecha = ?";
$params = [$fecha];
if ($estado !== 'todas') {
    $sql .= ' AND c.estado = ?';
    $params[] = $estado;
}
$sql .= ' ORDER BY c.hora';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$citas = $stmt->fetchAll();

// Contadores por estado del día completo (sin filtro, para el resumen)
$conteo = ['pendiente' => 0, 'confirmada' => 0, 'completada' => 0, 'ausente' => 0, 'cancelada' => 0];
$r      = $pdo->prepare('SELECT estado, COUNT(*) n FROM citas WHERE fecha = ? GROUP BY estado');
$r->execute([$fecha]);
foreach ($r->fetchAll() as $fila) {
    if (array_key_exists($fila['estado'], $conteo)) {
        $conteo[$fila['estado']] = (int) $fila['n'];
    }
}
$total = array_sum($conteo);

$diaPrev = (new DateTime($fecha))->modify('-1 day')->format('Y-m-d');
$diaSig  = (new DateTime($fecha))->modify('+1 day')->format('Y-m-d');
$hoy     = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#111111">
    <link rel="icon" href="assets/icono.svg" type="image/svg+xml">
    <link rel="manifest" href="manifest.php">
    <title>Admin · <?= e(nombreNegocio()) ?></title>
    <link rel="stylesheet" href="assets/css/estilos.css">
</head>
<body>
<div class="container">

    <?= mostrar_flash() ?>

    <h1 style="margin-top:1rem">🛡️ Panel de administración</h1>
    <p class="texto-suave">Todo el negocio, en un solo lugar.</p>

    <!-- Menú del panel: accesos directos a cada sección -->
    <div class="menu-panel">
        <a href="ajustes.php"><span class="ico">💈</span> Datos del Negocio <small>(nombre, logo, WhatsApp, dirección)</small></a>
        <a href="editar_servicios.php"><span class="ico">✂️</span> Servicios <small>(precios, duración)</small></a>
        <a href="editar_barberos.php"><span class="ico">🥷</span> Barberos <small>(equipo, especialidades)</small></a>
        <a href="horarios.php"><span class="ico">⏰</span> Horarios <small>(negocio y por barbero)</small></a>
        <a href="clientes.php"><span class="ico">👥</span> Clientes <small>(registro y actividad)</small></a>
        <a href="estadisticas.php"><span class="ico">📊</span> Estadísticas <small>(ingresos, tops, tendencia)</small></a>
    </div>

    <h2 class="seccion" style="margin-top:1.5rem">📆 Agenda del día</h2>

    <!-- Navegador de días -->
    <div class="nav-fecha">
        <a class="btn-inline btn btn-sec" href="admin.php?fecha=<?= $diaPrev ?>&estado=<?= $estado ?>">⬅</a>
        <div class="centro">
            <strong><?= fechaBonita($fecha) ?></strong>
            <?php if ($fecha === $hoy): ?><div class="texto-suave">(hoy)</div><?php endif; ?>
        </div>
        <a class="btn-inline btn btn-sec" href="admin.php?fecha=<?= $diaSig ?>&estado=<?= $estado ?>">➡</a>
    </div>
    <form method="get" style="display:flex;gap:.5rem;margin-bottom:.5rem">
        <input type="hidden" name="estado" value="<?= e($estado) ?>">
        <input type="date" name="fecha" value="<?= e($fecha) ?>">
        <button class="btn-inline btn btn-sec" type="submit">Ir</button>
    </form>

    <!-- Filtro por estado -->
    <div style="margin:.5rem 0">
        <a class="miga <?= $estado === 'todas' ? 'miga-activa' : '' ?>" href="admin.php?fecha=<?= e($fecha) ?>">Todas</a>
        <a class="miga <?= $estado === 'pendiente' ? 'miga-activa' : '' ?>" href="admin.php?fecha=<?= e($fecha) ?>&estado=pendiente">⏳</a>
        <a class="miga <?= $estado === 'confirmada' ? 'miga-activa' : '' ?>" href="admin.php?fecha=<?= e($fecha) ?>&estado=confirmada">✅</a>
        <a class="miga <?= $estado === 'completada' ? 'miga-activa' : '' ?>" href="admin.php?fecha=<?= e($fecha) ?>&estado=completada">✔</a>
        <a class="miga <?= $estado === 'ausente' ? 'miga-activa' : '' ?>" href="admin.php?fecha=<?= e($fecha) ?>&estado=ausente">🚫</a>
        <a class="miga <?= $estado === 'cancelada' ? 'miga-activa' : '' ?>" href="admin.php?fecha=<?= e($fecha) ?>&estado=cancelada">❌</a>
    </div>

    <!-- Resumen del día -->
    <div class="resumen">
        <div><strong><?= $total ?></strong><span class="texto-suave"><br>citas</span></div>
        <div style="color:var(--dorado)"><strong><?= $conteo['pendiente'] ?></strong><br><span class="texto-suave">⏳ pendientes</span></div>
        <div style="color:var(--verde)"><strong><?= $conteo['confirmada'] ?></strong><br><span class="texto-suave">✅ confirmadas</span></div>
        <div><strong><?= $conteo['completada'] ?></strong><br><span class="texto-suave">✔ completadas</span></div>
        <div style="color:#c4b5fd"><strong><?= $conteo['ausente'] ?></strong><br><span class="texto-suave">🚫 ausentes</span></div>
    </div>

    <!-- Listado -->
    <h2 class="seccion"><?= $citas ? 'Agenda de ' . fechaBonita($fecha) : '' ?></h2>

    <?php if (!$citas): ?>
        <div class="tarjeta centro" style="background:#0d0d0d">
            <p style="font-size:2rem; margin:.5rem 0">🌤️</p>
            <p class="texto-suave">No hay citas (<?= $estado === 'todas' ? 'aún' : 'con filtro ' . e($estado) ?>) este día.</p>
        </div>
    <?php endif; ?>

    <?php foreach ($citas as $c): ?>
        <div class="tarjeta cita">
            <div>
                <strong style="color:var(--dorado)"><?= e(substr($c['hora'], 0, 5)) ?></strong>
                · <?= e($c['servicio']) ?> (⏱ <?= (int) $c['duracion_min'] ?> min)<br>
                💁 <strong><?= e($c['cliente']) ?></strong>
                <!-- Mobile-first: un tap para llamar al cliente -->
                <?php if ($c['telefono']): ?>
                    <a href="tel:<?= e($c['telefono']) ?>">📞 <?= e($c['telefono']) ?></a>
                <?php endif; ?><br>
                <span class="texto-suave"><?= avatarMini($c['barbero_foto'], $c['emoji']) ?> <?= e($c['barbero']) ?>
                    <?php if ($c['notas']): ?>· 📝 <?= e($c['notas']) ?><?php endif; ?>
                </span>
            </div>
            <div class="lado">
                <?= pillEstado($c['estado']) ?>
            </div>
        </div>

        <!-- Botones de acción debajo de la tarjeta -->
        <?php if (in_array($c['estado'], ['pendiente', 'confirmada'], true)): ?>
            <form method="post" class="fila-btn">
                <?= campo_csrf() ?>
                <input type="hidden" name="cita_id" value="<?= (int) $c['id'] ?>">
                <input type="hidden" name="fecha" value="<?= e($fecha) ?>">
                <input type="hidden" name="estado" value="<?= e($estado) ?>">

                <?php if ($c['estado'] === 'pendiente'): ?>
                    <button class="btn-inline btn" type="submit" name="accion" value="confirmar">✅ Confirmar</button>
                <?php endif; ?>
                <button class="btn-inline btn" type="submit" name="accion" value="completar">✔ Completada</button>
                <button class="btn-inline btn btn-sec" type="submit" name="accion" value="ausente" title="El cliente no asistió; la hora queda libre">🚫 Ausente</button>
                <button class="btn-inline btn btn-rojo" type="submit" name="accion" value="cancelar">❌</button>
            </form>
        <?php elseif (in_array($c['estado'], ['completada', 'ausente', 'cancelada'], true)): ?>
            <form method="post" class="fila-btn">
                <?= campo_csrf() ?>
                <input type="hidden" name="cita_id" value="<?= (int) $c['id'] ?>">
                <input type="hidden" name="fecha" value="<?= e($fecha) ?>">
                <input type="hidden" name="estado" value="<?= e($estado) ?>">
                <button class="btn-inline btn btn-sec" type="submit" name="accion" value="deshacer">↩️ Deshacer</button>
            </form>
        <?php endif; ?>
    <?php endforeach; ?>

    <div style="height:5.5rem"></div>
</div>

<?php barraInferior('citas'); ?>
</body>
</html>
