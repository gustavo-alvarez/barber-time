<?php
/**
 * AGENDAR CITA — asistente de 3 pasos
 * ------------------------------------
 * Paso 1: elegir servicio
 * Paso 2: elegir barbero
 * Paso 3: elegir fecha (calendario) y hora (botones de horas libres)
 *
 * Todo se valida en el servidor. Los pasos viajan por URL (GET),
 * la confirmación final va por POST con token CSRF.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/citas.php';

requiere_login();
$usuario = usuario_actual();
$pdo     = db();
$errores = [];

/* ---------------------------------------------
 * CONFIRMACIÓN FINAL (POST: el usuario pulsó una hora)
 * --------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validar_csrf();

    $servicioId = (int) ($_POST['servicio_id'] ?? 0);
    $barberoId  = (int) ($_POST['barbero_id'] ?? 0);
    $fecha      = trim($_POST['fecha'] ?? '');
    $hora       = trim($_POST['hora'] ?? '');
    $notas      = trim($_POST['notas'] ?? '');

    // 1) ¿Existen el servicio y el barbero elegidos (y están activos)?
    $chkServ = $pdo->prepare('SELECT id, nombre, duracion_min FROM servicios WHERE id = ? AND activo = 1');
    $chkServ->execute([$servicioId]);
    $servicio = $chkServ->fetch();

    $chkBar = $pdo->prepare('SELECT id, nombre FROM barberos WHERE id = ? AND activo = 1');
    $chkBar->execute([$barberoId]);
    $barbero = $chkBar->fetch();

    if (!$servicio) $errores[] = 'Selecciona un servicio válido.';
    if (!$barbero)  $errores[] = 'Selecciona un barbero válido.';

    // 2) ¿La fecha cumple las reglas? (no past, no domingo, no lejana)
    if (!$servicio || !$barbero) {
        $errores[] = 'Vuelve a empezar seleccionando servicio y barbero.';
    } else {
        $errFecha = null;
        if (!fechaValida($fecha, $errFecha)) {
            $errores[] = $errFecha;
        } else {
            // 3) ¿La hora sigue libre? (otra persona pudo tomarla mientras elegía)
            $disponibles = horasDisponibles($pdo, $barberoId, $fecha, (int) $servicio['duracion_min']);
            if (!in_array($hora, $disponibles, true)) {
                $errores[] = 'Esa hora ya se ocupó 😕. Elige otra disponible.';
            }
        }
    }

    // 4) Guardar
    if (!$errores) {
        $stmt = $pdo->prepare(
            "INSERT INTO citas (usuario_id, servicio_id, barbero_id, fecha, hora, estado, notas)
             VALUES (?, ?, ?, ?, ?, 'pendiente', ?)"
        );
        $stmt->execute([
            $usuario['id'],
            $servicioId,
            $barberoId,
            $fecha,
            $hora,
            $notas !== '' ? $notas : null,
        ]);
        flash('🎉 ¡Cita agendada! ' . fechaBonita($fecha) . ' a las ' . $hora . ' con ' . $barbero['nombre'] . '.');
        header('Location: panel.php');
        exit;
    }

    // Si hubo error, conservamos la selección para que el usuario reintente
    $_GET['servicio_id'] = (string) $servicioId;
    $_GET['barbero_id']  = (string) $barberoId;
    $_GET['fecha']       = $fecha;
}

/* ---------------------------------------------
 * NAVEGACIÓN DEL ASISTENTE (GET)
 * --------------------------------------------- */
$servicios = $pdo->query('SELECT id, nombre, descripcion, duracion_min, precio FROM servicios WHERE activo = 1 ORDER BY precio')->fetchAll();
$barberos  = $pdo->query('SELECT id, nombre, especialidades, foto, emoji FROM barberos WHERE activo = 1 ORDER BY id')->fetchAll();

$servicioId = (int) ($_GET['servicio_id'] ?? 0);
$barberoId  = (int) ($_GET['barbero_id'] ?? 0);
$fecha      = trim($_GET['fecha'] ?? '');

$servicio = null;
if ($servicioId) {
    foreach ($servicios as $s) {
        if ((int) $s['id'] === $servicioId) {
            $servicio = $s;
            break;
        }
    }
}

$barbero = null;
if ($barberoId) {
    foreach ($barberos as $b) {
        if ((int) $b['id'] === $barberoId) {
            $barbero = $b;
            break;
        }
    }
}

$horas    = [];
$fechaOK  = false;
$errFecha = null;
if ($servicio && $barbero && $fecha !== '') {
    $fechaOK = fechaValida($fecha, $errFecha);
    if ($fechaOK) {
        $horas = horasDisponibles($pdo, (int) $barberoId, $fecha, (int) $servicio['duracion_min']);
    }
}

$minFecha = date('Y-m-d');
$maxFecha = (new DateTime('today'))->modify('+' . MAX_DIAS_ADELANTE . ' days')->format('Y-m-d');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#111111">
    <link rel="icon" href="assets/icono.svg" type="image/svg+xml">
    <link rel="manifest" href="manifest.php">
    <title>Agendar cita · <?= e(nombreNegocio()) ?></title>
    <link rel="stylesheet" href="assets/css/estilos.css">
</head>
<body>
<div class="container">

    <!-- asistente de pasos: sabrás en qué parte del flujo estás -->
    <div style="margin:1rem 0 .5rem">
        <span class="miga <?= $servicio ? '' : 'miga-activa' ?>">1. Servicio</span>
        <span class="miga <?= ($servicio && !$barbero) ? 'miga-activa' : '' ?>">2. Barbero <?= $servicio ? '✅' : '' ?></span>
        <span class="miga <?= ($servicio && $barbero) ? 'miga-activa' : '' ?>">3. Fecha y hora <?= $barbero ? '✅' : '' ?></span>
    </div>

    <?php foreach ($errores as $err): ?>
        <div class="alerta alerta-mal"><?= e($err) ?></div>
    <?php endforeach; ?>

    <?php if (!$servicio): ?>
        <!-- ============ PASO 1: SERVICIO ============ -->
        <h1>Elige tu servicio ✂️</h1>
        <?php foreach ($servicios as $s): ?>
            <a class="tarjeta servicio" href="agendar.php?servicio_id=<?= (int) $s['id'] ?>">
                <div>
                    <strong><?= e($s['nombre']) ?></strong><br>
                    <small class="texto-suave">⏱ <?= (int) $s['duracion_min'] ?> min</small>
                </div>
                <span class="precio">$<?= number_format((float) $s['precio'], 0, ',', '.') ?></span>
            </a>
        <?php endforeach; ?>

    <?php elseif (!$barbero): ?>
        <!-- ============ PASO 2: BARBERO ============ -->
        <h1>¿Con quién te atiende? 💈</h1>
        <?php foreach ($barberos as $b): ?>
            <a class="tarjeta barbero" href="agendar.php?servicio_id=<?= $servicioId ?>&barbero_id=<?= (int) $b['id'] ?>">
                <?php if ($b['foto']): ?>
                    <img class="avatar avatar-foto" src="<?= e($b['foto']) ?>" alt="Foto de <?= e($b['nombre']) ?>">
                <?php else: ?>
                    <span class="avatar">💈</span>
                <?php endif; ?>
                <div>
                    <strong><?= e($b['nombre']) ?></strong><br>
                    <small class="texto-suave">✂️ <?= e($b['especialidades']) ?></small>
                </div>
            </a>
        <?php endforeach; ?>
        <a class="texto-suave" href="agendar.php">← Cambiar servicio</a>

    <?php else: ?>
        <!-- ============ PASO 3: FECHA Y HORA ============ -->
        <h1>¿Cuándo quieres venir? 📅</h1>
        <p class="texto-suave">
            <strong><?= e($servicio['nombre']) ?></strong> ·
            <strong><?= e($barbero['nombre']) ?></strong> ·
            ⏱ <?= (int) $servicio['duracion_min'] ?> min
            <a href="agendar.php">(cambiar)</a>
        </p>
        <?php $ventana = $fechaOK ? horarioDia($pdo, $barberoId, $fecha) : null; ?>
        <p class="texto-suave">
            <small><?= $ventana
                ? '⏰ Atiende ' . e(substr($ventana['abre'], 0, 5)) . ' – ' . e(substr($ventana['cierra'], 0, 5))
                : '⏰ Ese día este barbero no atiende' ?></small>
        </p>
        <hr>

        <?php if ($fecha === '' || !$fechaOK): ?>
            <?php if ($errFecha): ?><div class="alerta alerta-mal"><?= e($errFecha) ?></div><?php endif; ?>
            <form method="get">
                <input type="hidden" name="servicio_id" value="<?= $servicioId ?>">
                <input type="hidden" name="barbero_id" value="<?= $barberoId ?>">
                <div class="campo">
                    <label for="fecha">Elige el día</label>
                    <!-- El celular abre su calendario nativo: cero fricción en móvil -->
                    <input id="fecha" name="fecha" type="date"
                           min="<?= $minFecha ?>" max="<?= $maxFecha ?>" value="<?= e($fecha) ?>" required>
                </div>
                <button class="btn" type="submit">Ver horas disponibles →</button>
            </form>

        <?php else: ?>
            <div class="tarjeta" style="background:#0d0d0d">
                <strong><?= fechaBonita($fecha) ?></strong><br>
                <?php $ventanaDia = horarioDia($pdo, $barberoId, $fecha); ?>
                <small class="texto-suave"><?= $ventanaDia
                    ? 'Atiende ' . e(substr($ventanaDia['abre'], 0, 5)) . ' – ' . e(substr($ventanaDia['cierra'], 0, 5))
                    : '⏰ Ese día no hay servicio' ?></small>
            </div>

            <?php if (!$horas): ?>
                <div class="alerta alerta-mal" style="margin-top:1rem">
                    😵 No hay horas libres ese día con este barbero. Prueba otro día u otro barbero.
                </div>
                <a class="btn btn-sec" href="agendar.php?servicio_id=<?= $servicioId ?>&barbero_id=<?= $barberoId ?>">⬅ Cambiar de día</a>
            <?php else: ?>
                <form method="post" style="margin-top:1rem">
                    <?= campo_csrf() ?>
                    <input type="hidden" name="servicio_id" value="<?= $servicioId ?>">
                    <input type="hidden" name="barbero_id" value="<?= $barberoId ?>">
                    <input type="hidden" name="fecha" value="<?= e($fecha) ?>">

                    <p class="texto-suave">Toca una hora para confirmar tu cita:</p>
                    <div class="horarios">
                        <?php foreach ($horas as $h): ?>
                            <button class="slot" type="submit" name="hora" value="<?= e($h) ?>"><?= e($h) ?></button>
                        <?php endforeach; ?>
                    </div>

                    <div class="campo">
                        <label for="notas">Nota para el barbero (opcional)</label>
                        <input id="notas" name="notas" type="text" maxlength="255"
                               placeholder="Ej: fade en los lados, dejo mierken arriba">
                    </div>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>

    <div style="height:5.5rem"></div> <!-- espacio para la barra inferior -->
</div>

<?php barraInferior('agendar'); ?>
</body>
</html>
