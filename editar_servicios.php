<?php
/**
 * SERVICIOS (solo admin) — parte del menú de administración
 * -----------------------------------------------------------
 * Crear, editar y mostrar/ocultar servicios. No hay "borrar":
 * se desactiva para no romper el historial de citas.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/citas.php'; // pillEstado para las etiquetas

requiere_rol('admin');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validar_csrf();

    if (($_POST['accion'] ?? '') !== 'guardar_servicio') {
        flash('Acción inválida. 🤨', 'mal');
        header('Location: editar_servicios.php');
        exit;
    }

    $id       = (int) ($_POST['id'] ?? 0);
    $nombre   = trim($_POST['nombre'] ?? '');
    $desc     = trim($_POST['descripcion'] ?? '');
    $duracion = (int) ($_POST['duracion_min'] ?? 0);
    $precioCr = str_replace(',', '.', trim($_POST['precio'] ?? ''));
    $precio   = is_numeric($precioCr) ? round((float) $precioCr, 2) : -1;
    $activo   = isset($_POST['activo']) ? 1 : 0;

    $errores = [];
    if (mb_strlen($nombre) < 3)          $errores[] = 'El nombre del servicio debe tener al menos 3 letras.';
    if ($duracion < 5 || $duracion > 240) $errores[] = 'La duración debe ser de 5 a 240 minutos.';
    if ($precio < 0)                     $errores[] = 'El precio es obligatorio (0 o más).';

    if ($errores) {
        foreach ($errores as $er) { flash($er, 'mal'); }
    } elseif ($id === 0) {
        $pdo->prepare('INSERT INTO servicios (nombre, descripcion, duracion_min, precio, activo) VALUES (?,?,?,?,?)')
            ->execute([$nombre, $desc ?: null, $duracion, $precio, $activo]);
        flash('✅ Servicio "' . $nombre . '" creado.');
    } else {
        $pdo->prepare('UPDATE servicios SET nombre=?, descripcion=?, duracion_min=?, precio=?, activo=? WHERE id=?')
            ->execute([$nombre, $desc ?: null, $duracion, $precio, $activo, $id]);
        flash('✅ Servicio "' . $nombre . '" actualizado.');
    }
    header('Location: editar_servicios.php');
    exit;
}

$servicios = $pdo->query('SELECT * FROM servicios ORDER BY activo DESC, precio')->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#111111">
    <link rel="icon" href="<?= e(logoOIcono()) ?>">
    <link rel="manifest" href="manifest.php">
    <title>Servicios · <?= e(nombreNegocio()) ?></title>
    <link rel="stylesheet" href="assets/css/estilos.css">
</head>
<body>
<div class="container">

    <?= mostrar_flash() ?>

    <h1 style="margin-top:1rem">✂️ Servicios</h1>
    <p class="texto-suave">
        <a href="admin.php">← Volver al panel de citas</a> ·
        <a href="editar_barberos.php">💈 Barberos</a> ·
        <a href="horarios.php">⏰ Horarios</a> ·
        <a href="ajustes.php">🏪 Negocio</a>
    </p>
    <hr>

    <form class="editor" method="post">
        <?= campo_csrf() ?>
        <input type="hidden" name="accion" value="guardar_servicio">
        <input type="hidden" name="id" value="0">
        <strong>➕ Nuevo servicio</strong>
        <div class="campo"><input name="nombre" type="text" placeholder="Nombre (ej: Corte premium)" required></div>
        <div class="campo"><input name="descripcion" type="text" maxlength="255" placeholder="Descripción corta"></div>
        <div class="fila">
            <div class="campo">
                <input name="duracion_min" type="number" min="5" max="240" step="5" value="30" placeholder="Minutos">
            </div>
            <div class="campo">
                <input name="precio" type="number" min="0" step="500" placeholder="Precio">
            </div>
        </div>
        <label class="chk"><input type="checkbox" name="activo" checked> Visible en la web</label>
        <button class="btn" type="submit">Crear servicio</button>
    </form>

    <?php foreach ($servicios as $s): ?>
        <form class="editor <?= $s['activo'] ? '' : 'editor-apagado' ?>" method="post">
            <?= campo_csrf() ?>
            <input type="hidden" name="accion" value="guardar_servicio">
            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
            <div class="fila" style="align-items:center">
                <label>✏️ ID #<?= (int) $s['id'] ?></label>
                <?= pillEstado($s['activo'] ? 'confirmada' : 'cancelada') ?>
            </div>
            <div class="campo"><input name="nombre" type="text" value="<?= e($s['nombre']) ?>" maxlength="100" required></div>
            <div class="campo"><input name="descripcion" type="text" value="<?= e($s['descripcion'] ?? '') ?>" maxlength="255" placeholder="Descripción"></div>
            <div class="fila">
                <div class="campo">
                    <label class="mini">⏱ Minutos</label>
                    <input name="duracion_min" type="number" min="5" max="240" step="5" value="<?= (int) $s['duracion_min'] ?>">
                </div>
                <div class="campo">
                    <label class="mini">💰 Precio</label>
                    <input name="precio" type="number" min="0" step="500" value="<?= (int) $s['precio'] ?>">
                </div>
            </div>
            <label class="chk"><input type="checkbox" name="activo" <?= $s['activo'] ? 'checked' : '' ?>> Visible en la web</label>
            <button class="btn btn-sec" type="submit">Guardar cambios</button>
        </form>
    <?php endforeach; ?>

    <p class="texto-suave centro" style="margin:1rem 0 2rem">
        🔒 Los servicios ya usados en citas no se borran, solo se ocultan,
        para proteger el historial.
    </p>

    <div style="height:5.5rem"></div>
</div>

<?php barraInferior(''); ?>
</body>
</html>
