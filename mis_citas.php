<?php
/**
 * MIS CITAS / LAS CITAS (barbero y admin)
 * ---------------------------------------
 * El corazón de la gestión de turnos por rol:
 *   • BARBERO: ve SUS turnos y puede CREAR (cliente registrado o "de la
 *     calle" con nombre y teléfono), CAMBIAR fecha/hora y CANCELAR.
 *   • ADMIN: ve TODO (con selector de barbero y filtro de fecha) y puede
 *     lo mismo que un barbero, pero en cualquier turno.
 *   • CLIENTE: no entra aquí (lo redirigimos a panel.php, su lista).
 *
 * Reglas de negocio viven en includes/citas.php (turnoDisponible):
 * horario del día, duración real del servicio y nada de solapes.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/citas.php'; // fechaValida, turnoDisponible, pillEstado...

requiere_login();
$usuario = usuario_actual();
$pdo     = db();

if ($usuario['rol'] === 'cliente') {
    header('Location: panel.php'); // los clientes gestionan desde SU lista
    exit;
}

$soyAdmin = $usuario['rol'] === 'admin';

if (!$soyAdmin && !$usuario['barbero_id']) {
    // Cuenta de barbero sin ficha (dato raro): no puede operar aquí
    flash('El administrador todavía no te vinculó con una ficha de barbero. 🤔', 'mal');
    header('Location: perfil.php');
    exit;
}

$miBarId   = (int) ($usuario['barbero_id'] ?? 0);
$hoy       = date('Y-m-d');

/* ---------------- POST: crear / mover / cancelar ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validar_csrf();
    $accion = $_POST['accion'] ?? '';

    /* ---------- CREAR turno ---------- */
    if ($accion === 'crear_turno') {
        // El barbero crea SOLO para sí; el admin elige con el selector
        $barId = $soyAdmin ? (int) ($_POST['barbero_id'] ?? 0) : $miBarId;

        $ch3 = $pdo->prepare('SELECT id, duracion_min, nombre FROM servicios WHERE id = ? AND activo = 1');
        $ch3->execute([(int) ($_POST['servicio_id'] ?? 0)]);
        $serv = $ch3->fetch();

        $fecha    = trim($_POST['fecha'] ?? '');
        $hora     = trim($_POST['hora'] ?? '');
        $modo     = $_POST['modo_cliente'] ?? 'calle'; // 'registrado' | 'calle'
        $usuId    = (int) ($_POST['usuario_id'] ?? 0);
        $visNom   = trim($_POST['visitante'] ?? '');
        $visTel   = preg_replace('/\D/', '', trim($_POST['tel_visitante'] ?? ''));

        $errores = [];
        if (!$barId) $errores[] = 'Elige el barbero para el turno.';
        if (!$serv)  $errores[] = 'Elige un servicio válido.';
        if ($modo === 'registrado' && !$usuId) $errores[] = 'Elige el cliente registrado.';
        if ($visTel !== '' && !preg_match('/^[0-9]{7,15}$/', $visTel)) $errores[] = 'El teléfono del visitante debe ser 7 a 15 dígitos.';

        // turnoDisponible YA consume fechaValida: pedimos su motivo
        if (!$errores) {
            $queda = turnoDisponible($pdo, $barId, $fecha, $hora, (int) $serv['duracion_min']);
            if ($queda !== true) $errores[] = $queda;
        }

        if ($errores) {
            foreach ($errores as $er) { flash($er, 'mal'); }
        } else {
            $cliente = $pdo->prepare('SELECT nombre FROM usuarios WHERE id = ?');
            $cliente->execute([$usuId]);
            $esRegistrado = $modo === 'registrado' && $cliente->fetch();

            $pdo->prepare('INSERT INTO citas (usuario_id, servicio_id, barbero_id, fecha, hora, estado, visitante, tel_visitante)
                           VALUES (?, ?, ?, ?, ?, "confirmada", ?, ?)')
                ->execute([
                    $esRegistrado ? $usuId : null,
                    (int) $serv['id'],
                    $barId,
                    $fecha,
                    $hora . ':00',
                    $esRegistrado ? null : $visNom,
                    $esRegistrado ? null : ($visTel ?: null),
                ]);
            flash('✅ Turno creado: ' . $serv['nombre'] . ' el ' . fechaBonita($fecha) . ' a las ' . $hora . '.');
        }
        $volver = $soyAdmin ? "?bar=$barId" : '';
        header('Location: mis_citas.php' . $volver);
        exit;
    }

    /* ---------- Mover (cambiar fecha/hora) ---------- */
    if ($accion === 'fijar_turno') {
        $citaId = (int) ($_POST['cita_id'] ?? 0);
        $st = $pdo->prepare('SELECT c.*, s.duracion_min FROM citas c JOIN servicios s ON s.id = c.servicio_id WHERE c.id = ?');
        $st->execute([$citaId]);
        $cita = $st->fetch();

        $permitido = $cita && ($soyAdmin || (int) $cita['barbero_id'] === $miBarId);
        $editable  = $permitido && in_array($cita['estado'], ['pendiente', 'confirmada'], true);

        if (!$permitido) {
            flash('Ese turno no existe o no es tuyo. 🤔', 'mal');
        } elseif (!$editable) {
            flash('Solo turnos pendientes o confirmados se pueden mover. ✋', 'mal');
        } else {
            $queda = turnoDisponible($pdo, (int) $cita['barbero_id'], trim($_POST['fecha'] ?? ''),
                trim($_POST['hora'] ?? ''), (int) $cita['duracion_min'], $citaId);
            if ($queda !== true) {
                flash($queda, 'mal');
            } else {
                $pdo->prepare('UPDATE citas SET fecha = ?, hora = ? WHERE id = ?')
                    ->execute([trim($_POST['fecha']), trim($_POST['hora']) . ':00', $citaId]);
                flash('✅ Turno movido a ' . fechaBonita(trim($_POST['fecha'])) . ' ' . trim($_POST['hora'])
                    . '. 💬 Recuerda avisar el cambio por WhatsApp (botón en la tarjeta).');
            }
        }
        header('Location: mis_citas.php' . (($_GET['bar'] ?? '') !== '' ? '?bar=' . (int) $_GET['bar'] : ''));
        exit;
    }

    /* ---------- Marcar / deshacer estado (confirmar, completada, ausente, cancelar, deshacer) ---------- */
    if (in_array($accion, ['confirmar', 'completar', 'ausente', 'cancelar', 'deshacer'], true)) {
        $citaId = (int) ($_POST['cita_id'] ?? 0);
        [$ok, $msg] = transicionCita($pdo, $citaId, $accion, $soyAdmin ? null : $miBarId);
        flash($msg, $ok ? 'ok' : 'mal');
        header('Location: mis_citas.php' . (($_GET['bar'] ?? '') !== '' ? '?bar=' . (int) $_GET['bar'] : ''));
        exit;
    }

    /* ---------- 💬 Avisar por WhatsApp (y confirmar si era pendiente) ---------- */
    if ($accion === 'avisar_whatsapp') {
        $citaId = (int) ($_POST['cita_id'] ?? 0);
        $st = $pdo->prepare('SELECT c.*, s.nombre AS servicio, b.nombre AS barbero,
                                    u.nombre AS cliente, u.telefono AS cliente_tel
                             FROM citas c
                             JOIN servicios s ON s.id = c.servicio_id
                             JOIN barberos  b ON b.id = c.barbero_id
                             LEFT JOIN usuarios u ON u.id = c.usuario_id
                             WHERE c.id = ?');
        $st->execute([$citaId]);
        $cita = $st->fetch();

        $permitido = $cita && ($soyAdmin || (int) $cita['barbero_id'] === $miBarId);
        if (!$permitido) {
            flash('Ese turno no existe o no es tuyo. 🤔', 'mal');
        } else {
            $telAviso = $cita['usuario_id'] === null ? $cita['tel_visitante'] : $cita['cliente_tel'];
            $link = linkWhatsapp($telAviso, mensajeWhatsapp('nuevo', $cita));

            if ($cita['estado'] === 'pendiente' && $link) {
                $pdo->prepare("UPDATE citas SET estado = 'confirmada' WHERE id = ?")->execute([$citaId]);
                flash('✅ Turno confirmado. El panel del admin se actualizó también.');
            }

            if ($link) {
                header('Location: ' . $link); // abre WhatsApp con el mensaje listo
                exit;
            }
            flash('📞 Este turno no tiene teléfono para avisar por WhatsApp.', 'mal');
        }
        header('Location: mis_citas.php' . (($_GET['bar'] ?? '') !== '' ? '?bar=' . (int) $_GET['bar'] : ''));
        exit;
    }

    flash('Acción inválida. 🤨', 'mal');
    header('Location: mis_citas.php');
    exit;
}

/* ===================== VISTA ===================== */

/* datos para los formularios */
$barberosEquipo = $soyAdmin
    ? $pdo->query('SELECT id, nombre FROM barberos WHERE activo = 1 ORDER BY id')->fetchAll()
    : [];
$servicios = $pdo->query('SELECT id, nombre, duracion_min FROM servicios WHERE activo = 1 ORDER BY precio')->fetchAll();
$clientes  = $pdo->query("SELECT id, nombre, telefono FROM usuarios WHERE rol = 'cliente' AND activo = 1 ORDER BY nombre")->fetchAll();

/* filtros */
$barFiltro = $soyAdmin ? (int) ($_GET['bar'] ?? 0) : $miBarId; // 0 = todos (solo admin)
$fFiltro   = trim($_GET['f'] ?? '');
if ($fFiltro !== '') {
    $dt = DateTime::createFromFormat('Y-m-d|', $fFiltro);
    if (!$dt || $dt->format('Y-m-d') !== $fFiltro) $fFiltro = ''; // fecha inválida → sin filtro
}

/* consulta de citas */
$sql = "SELECT c.id, c.fecha, c.hora, c.estado, c.usuario_id, c.visitante, c.tel_visitante, c.notas,
               b.id   AS barbero_id, b.nombre AS barbero, b.emoji AS barbero_emoji, b.foto AS barbero_foto,
               s.nombre AS servicio, s.duracion_min, s.precio,
               u.nombre AS cliente, u.telefono AS cliente_tel
        FROM citas c
        JOIN barberos  b ON b.id = c.barbero_id
        JOIN servicios s ON s.id = c.servicio_id
        LEFT JOIN usuarios u ON u.id = c.usuario_id";

$cond = [];
$params = [];
if ($soyAdmin || true) { // el barbero también puede ver todo lo de su agenda futura/pasada
    if ($barFiltro > 0) { $cond[] = 'c.barbero_id = ?'; $params[] = $barFiltro; }
}
if ($fFiltro !== '') { $cond[] = 'c.fecha = ?'; $params[] = $fFiltro; }
$sql .= ($cond ? ' WHERE ' . implode(' AND ', $cond) : '')
      . ' ORDER BY c.fecha DESC, c.hora DESC LIMIT 200';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$citas = $stmt->fetchAll();

/* separar futuras y pasadas (solo si NO hay filtro de fecha) */
$futuras = [];
$pasadas = [];
foreach ($citas as $cita) {
    if ($fFiltro !== '') { $futuras[] = $cita; continue; }
    if ($cita['fecha'] >= $hoy) { $futuras[] = $cita; } else { $pasadas[] = $cita; }
}

/** una tarjeta de turno, reutilizada en ambos listados */
function tarjetaTurno(array $cita, bool $puedeEditar, bool $soyAdmin): void
{
    $futuro  = $cita['fecha'] >= date('Y-m-d');
    $esCalle = $cita['usuario_id'] === null;
    ?>
    <div class="tarjeta editor <?= in_array($cita['estado'], ['cancelada', 'ausente'], true) ? 'editor-apagado' : '' ?>">
        <div class="fila" style="align-items:center">
            <strong><?= e(fechaBonita($cita['fecha'])) ?> · <?= e(substr($cita['hora'], 0, 5)) ?></strong>
            <?= pillEstado($cita['estado']) ?>
        </div>
        <p class="texto-suave" style="margin:.15rem 0 0">
            ✂️ <?= e($cita['servicio']) ?> · ⏱ <?= (int) $cita['duracion_min'] ?> min
            · 💰 $<?= number_format((float) $cita['precio'], 0, ',', '.') ?>
        </p>
        <p style="margin:.15rem 0 0">
            <?php if ($esCalle): ?>
                🚶 <strong><?= e($cita['visitante'] ?: 'Cliente de la calle') ?></strong>
                <?php if ($cita['tel_visitante']): ?> · 📱 <?= e($cita['tel_visitante']) ?><?php endif; ?>
            <?php else: ?>
                👤 <strong><?= e($cita['cliente']) ?></strong> · 📱 <?= e($cita['cliente_tel']) ?>
            <?php endif; ?>
        </p>
        <?php if ($soyAdmin): ?>
            <p class="texto-suave" style="margin:.15rem 0 0"><?= avatarMini($cita['barbero_foto'], $cita['barbero_emoji'], $cita['barbero']) ?> <?= e($cita['barbero']) ?></p>
        <?php endif; ?>

        <?php if ($puedeEditar && $futuro && in_array($cita['estado'], ['pendiente', 'confirmada'], true)): ?>
                <hr style="margin:.5rem 0">
                <details>
                    <summary class="texto-suave" style="cursor:pointer">⏩ Cambiar fecha / hora</summary>
                    <form method="post" style="margin-top:.5rem">
                        <?= campo_csrf() ?>
                        <input type="hidden" name="accion" value="fijar_turno">
                        <input type="hidden" name="cita_id" value="<?= (int) $cita['id'] ?>">
                        <div class="fila">
                            <div class="campo">
                                <input type="date" name="fecha" value="<?= e($cita['fecha']) ?>"
                                       min="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="campo">
                                <input type="time" name="hora" value="<?= e(substr($cita['hora'], 0, 5)) ?>" step="60" required>
                            </div>
                        </div>
                        <button class="btn btn-sec" type="submit">Guardar nueva fecha</button>
                    </form>
                </details>
                <form method="post" class="fila-btn" style="margin-top:.4rem">
                    <?= campo_csrf() ?>
                    <input type="hidden" name="cita_id" value="<?= (int) $cita['id'] ?>">
                    <?php if ($cita['estado'] === 'pendiente'): ?>
                        <button class="btn-inline btn" type="submit" name="accion" value="confirmar">✅</button>
                    <?php endif; ?>
                    <button class="btn-inline btn" type="submit" name="accion" value="completar" title="El servicio ya se realizó">🏁 Completar</button>
                    <button class="btn-inline btn btn-sec" type="submit" name="accion" value="ausente" title="El cliente no asistió; la hora queda libre">🚫 Ausente</button>
                    <button class="btn-inline btn btn-rojo" type="submit" name="accion" value="cancelar" title="Cancelar turno">🗑️</button>
                </form>
                <?php
                /* 💬 Aviso al cliente por WhatsApp + confirmar si estaba pendiente */
                $telAviso = $esCalle ? ($cita['tel_visitante'] ?? null) : ($cita['cliente_tel'] ?? null);
                if (telWhatsapp($telAviso)):
                ?>
                    <form method="post" style="margin-top:.5rem">
                        <?= campo_csrf() ?>
                        <input type="hidden" name="accion" value="avisar_whatsapp">
                        <input type="hidden" name="cita_id" value="<?= (int) $cita['id'] ?>">
                        <button class="btn" type="submit" style="width:100%">
                            💬 <?= $cita['estado'] === 'pendiente' ? 'Confirmar y avisar por WhatsApp' : 'Avisar por WhatsApp' ?>
                        </button>
                    </form>
                <?php else: ?>
                    <p class="texto-suave" style="margin:.5rem 0 0;font-size:.78rem">📞 Sin teléfono: no se puede avisar por WhatsApp.</p>
                <?php endif; ?>
        <?php endif; ?>

        <?php if ($puedeEditar && in_array($cita['estado'], ['completada', 'ausente', 'cancelada'], true)): ?>
            <form method="post" style="margin-top:.4rem">
                <?= campo_csrf() ?>
                <input type="hidden" name="accion" value="deshacer">
                <input type="hidden" name="cita_id" value="<?= (int) $cita['id'] ?>">
                <button class="btn btn-sec" type="submit">↩️ Deshacer</button>
            </form>
        <?php endif; ?>

        <?php if ($cita['estado'] === 'cancelada'): ?>
            <?php
            $telCanc  = $esCalle ? ($cita['tel_visitante'] ?? null) : ($cita['cliente_tel'] ?? null);
            $linkWC = linkWhatsapp($telCanc, mensajeWhatsapp('cancelado', $cita));
            ?>
            <?php if ($linkWC): ?>
                <a class="btn btn-sec" style="margin-top:.5rem;text-decoration:none"
                   href="<?= e($linkWC) ?>" target="_blank" rel="noopener">💬 Notificar cancelación</a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
}
$puedeEditarTurnos = true; // ambos roles aquí pueden operar sus turnos
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#111111">
    <link rel="icon" href="<?= e(logoOIcono()) ?>">
    <link rel="manifest" href="manifest.php">
    <title><?= $soyAdmin ? 'Las Citas' : 'Mis Citas' ?> · <?= e(nombreNegocio()) ?></title>
    <link rel="stylesheet" href="assets/css/estilos.css">
</head>
<body>
<div class="container">

    <?= mostrar_flash() ?>

    <h1 style="margin-top:1rem"><?= $soyAdmin ? '📋 Las Citas' : '📋 Mis Turnos' ?></h1>
    <p class="texto-suave">
        <?php if ($soyAdmin): ?>
            Gestionas los turnos de <strong>todos</strong> los barberos.
        <?php else: ?>
            Tu agenda: crea turnos, muévealos o cancélalos.
        <?php endif; ?>
    </p>
    <hr>

    <!-- ============ ➕ CREAR TURNO ============ -->
    <form class="editor" method="post">
        <?= campo_csrf() ?>
        <input type="hidden" name="accion" value="crear_turno">
        <strong>➕ Crear turno</strong>

        <?php if ($soyAdmin): ?>
            <div class="campo">
                <label class="mini">💈 Barbero</label>
                <select name="barbero_id" required>
                    <option value="">Elegir barbero…</option>
                    <?php foreach ($barberosEquipo as $b): ?>
                        <option value="<?= (int) $b['id'] ?>" <?= $barFiltro === (int) $b['id'] ? 'selected' : '' ?>>
                            <?= e($b['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>

        <div class="campo">
            <label class="mini">✂️ Servicio</label>
            <select name="servicio_id" required>
                <option value="">Elegir servicio…</option>
                <?php foreach ($servicios as $s): ?>
                    <option value="<?= (int) $s['id'] ?>"><?= e($s['nombre']) ?> (<?= (int) $s['duracion_min'] ?> min)</option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="fila">
            <div class="campo">
                <label class="mini">📆 Fecha</label>
                <input type="date" name="fecha" min="<?= $hoy ?>" required>
            </div>
            <div class="campo">
                <label class="mini">⏰ Hora</label>
                <input type="time" name="hora" step="60" required>
            </div>
        </div>

        <strong>👤 Cliente</strong>
        <label class="chk"><input type="radio" name="modo_cliente" value="calle" checked> De la calle (sin cuenta)</label>
        <div class="fila">
            <div class="campo">
                <input type="text" name="visitante" placeholder="Nombre de la calle" maxlength="100">
            </div>
            <div class="campo">
                <input type="tel" name="tel_visitante" inputmode="numeric" placeholder="Teléfono (opcional)">
            </div>
        </div>
        <label class="chk"><input type="radio" name="modo_cliente" value="registrado"> Cliente registrado</label>
        <div class="campo">
            <select name="usuario_id" id="selCliente">
                <option value="">Elegir cliente…</option>
                <?php foreach ($clientes as $c): ?>
                    <option value="<?= (int) $c['id'] ?>"><?= e($c['nombre']) ?> (<?= e($c['telefono']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>

        <button class="btn" type="submit">Guardar turno</button>
    </form>

    <!-- ============ 🔎 FILTROS (solo admin) ============ -->
    <?php if ($soyAdmin): ?>
    <form class="editor" method="get">
        <strong>🔎 Filtrar</strong>
        <div class="campo">
            <label class="mini">💈 Barbero</label>
            <select name="bar">
                <option value="0" <?= $barFiltro === 0 ? 'selected' : '' ?>>Todos los barberos</option>
                <?php foreach ($barberosEquipo as $b): ?>
                    <option value="<?= (int) $b['id'] ?>" <?= $barFiltro === (int) $b['id'] ? 'selected' : '' ?>><?= e($b['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="campo">
            <label class="mini">📆 Un día específico (opcional)</label>
            <input type="date" name="f" value="<?= e($fFiltro) ?>">
        </div>
        <button class="btn btn-sec" type="submit">Aplicar filtro</button>
        <?php if ($barFiltro || $fFiltro): ?>
            <a class="texto-suave" href="mis_citas.php">✖ Quitar filtros</a>
        <?php endif; ?>
    </form>
    <?php endif; ?>

    <!-- ============ LISTADOS ============ -->
    <?php
    /* estado vacío neutro (igual que panel.php / admin.php): no asusta */
    $tarjetaVacia = function (string $emoji, string $texto): void { ?>
        <div class="tarjeta centro" style="background:#0d0d0d">
            <p style="font-size:2rem;margin:.5rem 0"><?= $emoji ?></p>
            <p class="texto-suave"><?= e($texto) ?></p>
        </div>
    <?php };
    ?>
    <?php if ($fFiltro !== ''): ?>
        <h2 class="seccion">📆 <?= e(fechaBonita($fFiltro)) ?></h2>
        <?php if (!$futuras) $tarjetaVacia('😴', 'No hay turnos ese día.'); ?>
        <?php foreach ($futuras as $cita) tarjetaTurno($cita, $puedeEditarTurnos, $soyAdmin); ?>
    <?php else: ?>
        <h2 class="seccion">🔜 Citas próximas (<?= count($futuras) ?>)</h2>
        <?php if (!$futuras) $tarjetaVacia('🤷', 'No hay turnos futuros todavía. Crea uno con ➕ Crear turno.'); ?>
        <?php foreach ($futuras as $cita) tarjetaTurno($cita, $puedeEditarTurnos, $soyAdmin); ?>

        <h2 class="seccion">📜 Historial reciente</h2>
        <?php if (!$pasadas) $tarjetaVacia('📜', 'Aún no hay citas pasadas.'); ?>
        <?php foreach ($pasadas as $cita) tarjetaTurno($cita, false, $soyAdmin); ?>
    <?php endif; ?>

        <p class="texto-suave centro" style="margin:1rem 0 2rem">
            ℹ️ Los turnos se validan contra el horario del día y los demás turnos:
            la app avisa si se cruzan o si se salen del horario.
        </p>

        <div style="height:5.5rem"></div>
</div>

<?php barraInferior('citas'); ?>
</body>
</html>
