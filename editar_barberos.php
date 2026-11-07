<?php
/**
 * BARBEROS (solo admin) — parte del menú de administración
 * -----------------------------------------------------------
 * 1) Crear, editar y mostrar/ocultar barberos. No hay "borrar":
 *    se desactiva para no romper el historial de citas.
 * 2) 🔑 Acceso de sesión: el admin CREA o RESTABLECE la cuenta
 *    (teléfono + contraseña) con la que el barbero entra a la app.
 * 3) 📸 Foto: el admin también puede subirla/cambiarla aquí.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/citas.php'; // pillEstado

requiere_rol('admin');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validar_csrf();
    $accion = $_POST['accion'] ?? '';

    /* ---------- 1) Guardar datos de la ficha ---------- */
    if ($accion === 'guardar_barbero') {
        $id     = (int) ($_POST['id'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        $esp    = trim($_POST['especialidades'] ?? '');
        $bio    = trim($_POST['biografia'] ?? '');
        $emoji  = trim($_POST['emoji'] ?? '');
        $activo = isset($_POST['activo']) ? 1 : 0;

        $errores = [];
        if (mb_strlen($nombre) < 3) $errores[] = 'El nombre del barbero debe tener al menos 3 letras.';
        if ($emoji === '')          $emoji = '💈';
        if (mb_strlen($emoji) > 8)  $errores[] = 'El emoji/avatar es muy largo.';

        if ($errores) {
            foreach ($errores as $er) { flash($er, 'mal'); }
        } elseif ($id === 0) {
            $pdo->prepare('INSERT INTO barberos (nombre, especialidades, biografia, emoji, activo) VALUES (?,?,?,?,?)')
                ->execute([$nombre, $esp ?: null, $bio ?: null, $emoji, $activo]);
            flash('✅ Barbero "' . $nombre . '" creado. Ahora dale un 🔑 acceso y su ⏰ horario.');
        } else {
            $upd = $pdo->prepare('UPDATE barberos SET nombre=?, especialidades=?, biografia=?, emoji=?, activo=? WHERE id=?')
                ->execute([$nombre, $esp ?: null, $bio ?: null, $emoji, $activo, $id]);
            // El nombre en la app sale de la ficha: sincronizamos la cuenta si existe
            $pdo->prepare('UPDATE usuarios SET nombre = ? WHERE barbero_id = ? AND rol = "barbero"')
                ->execute([$nombre, $id]);
            flash('✅ Barbero "' . $nombre . '" actualizado.');
        }
        header('Location: editar_barberos.php');
        exit;
    }

    /* ---------- 2) 🔑 Acceso de sesión (crear / restablecer) ---------- */
    if ($accion === 'acceso_barbero') {
        $idBar = (int) ($_POST['id'] ?? 0);
        $tel   = trim($_POST['telefono'] ?? '');
        $pass  = $_POST['pass'] ?? '';

        $st = $pdo->prepare('SELECT * FROM barberos WHERE id = ?');
        $st->execute([$idBar]);
        $bar = $st->fetch();

        $cuenta = null;
        if ($bar) {
            $st = $pdo->prepare('SELECT * FROM usuarios WHERE barbero_id = ? AND rol = "barbero"');
            $st->execute([$idBar]);
            $cuenta = $st->fetch();
        }

        // Campos: al crear son OBLIGATORIOS; al restablecer, opcionales (vacío = sin cambio)
        $tel  = preg_replace('/[\s\-\.]/', '', $tel);
        $errores = [];
        if (!$bar) $errores[] = 'Barbero inexistente. 🤔';
        if ($tel !== '' && !preg_match('/^[0-9]{7,15}$/', $tel)) $errores[] = 'El teléfono debe tener 7 a 15 dígitos numéricos.';
        if ($tel === '' && !$cuenta) $errores[] = 'Para crear el acceso necesitas el teléfono.';
        if ($pass !== '' && strlen($pass) < 6) $errores[] = 'La contraseña debe tener al menos 6 caracteres.';
        if ($pass === '' && !$cuenta) $errores[] = 'Para crear el acceso necesitas la contraseña.';

        // ¿El teléfono ya es de otra cuenta?
        if (!$errores && $tel !== '') {
            $st = $pdo->prepare('SELECT id FROM usuarios WHERE telefono = ? AND id <> ?');
            $st->execute([$tel, $cuenta['id'] ?? 0]);
            if ($st->fetch()) $errores[] = 'Ese teléfono ya es la clave de otra cuenta.';
        }

        if ($errores) {
            foreach ($errores as $er) { flash($er, 'mal'); }
        } elseif ($cuenta) {
            // Restablecer: solo lo que el admin escribió cambia
            if ($tel !== '' && $pass !== '') {
                $pdo->prepare('UPDATE usuarios SET telefono=?, pass_hash=? WHERE id=?')
                    ->execute([$tel, password_hash($pass, PASSWORD_DEFAULT), $cuenta['id']]);
                flash('🔑 Teléfono y contraseña del barbero actualizados.');
            } elseif ($tel !== '') {
                $pdo->prepare('UPDATE usuarios SET telefono=? WHERE id=?')->execute([$tel, $cuenta['id']]);
                flash('🔑 Teléfono del acceso actualizado.');
            } elseif ($pass !== '') {
                $pdo->prepare('UPDATE usuarios SET pass_hash=? WHERE id=?')->execute([password_hash($pass, PASSWORD_DEFAULT), $cuenta['id']]);
                flash('🔑 Contraseña restablecida. Entrégasela al barbero de forma segura.');
            }
        } else {
            // Crear la cuenta nueva rol barbero + vínculo con la ficha
            $pdo->prepare('INSERT INTO usuarios (nombre, telefono, pass_hash, rol, barbero_id) VALUES (?,?,?,?,?)')
                ->execute([$bar['nombre'], $tel, password_hash($pass, PASSWORD_DEFAULT), 'barbero', $idBar]);
            flash('🔑 Acceso creado. Entrega al barbero: teléfono ' . $tel . ' y su contraseña. Entra a la app y verá "Mis Citas".');
        }
        header('Location: editar_barberos.php');
        exit;
    }

    /* ---------- 3) 📸 Foto del barbero (admin) ---------- */
    if ($accion === 'foto_barbero') {
        $idBar = (int) ($_POST['id'] ?? 0);
        $st = $pdo->prepare('SELECT * FROM barberos WHERE id = ?');
        $st->execute([$idBar]);
        $bar = $st->fetch();

        if (!$bar) {
            flash('Barbero inexistente. 🤔', 'mal');
        } else {
            $res = imagenSubida($_FILES['foto'] ?? [], 'barbero-' . $idBar);
            if (!$res['ok']) {
                flash($res['error'], 'mal');
            } else {
                borrarImagenSubida($bar['foto']);
                $pdo->prepare('UPDATE barberos SET foto = ? WHERE id = ?')->execute([$res['ruta'], $idBar]);
                flash('📸 Foto de ' . $bar['nombre'] . ' actualizada en el catálogo.');
            }
        }
        header('Location: editar_barberos.php');
        exit;
    }

    flash('Acción inválida. 🤨', 'mal');
    header('Location: editar_barberos.php');
    exit;
}

/* Barberos + su cuenta (si tiene) para pintar los accesos */
$barberos = $pdo->query('SELECT * FROM barberos ORDER BY activo DESC, id')->fetchAll();
$cuentas = [];
foreach ($pdo->query("SELECT id, telefono, barbero_id FROM usuarios WHERE rol = 'barbero' AND barbero_id IS NOT NULL")->fetchAll() as $c) {
    $cuentas[(int) $c['barbero_id']] = $c;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#111111">
    <link rel="icon" href="<?= e(logoOIcono()) ?>">
    <link rel="manifest" href="manifest.php">
    <title>Barberos · <?= e(nombreNegocio()) ?></title>
    <link rel="stylesheet" href="assets/css/estilos.css">
</head>
<body>
<div class="container">

    <?= mostrar_flash() ?>

    <h1 style="margin-top:1rem">💈 Barberos</h1>
    <p class="texto-suave">
        <a href="admin.php">← Volver al panel de citas</a> ·
        <a href="editar_servicios.php">✂️ Servicios</a> ·
        <a href="horarios.php">⏰ Horarios</a> ·
        <a href="ajustes.php">🏪 Negocio</a>
    </p>
    <hr>

    <form class="editor" method="post">
        <?= campo_csrf() ?>
        <input type="hidden" name="accion" value="guardar_barbero">
        <input type="hidden" name="id" value="0">
        <strong>➕ Nuevo barbero</strong>
        <div class="campo"><input name="nombre" type="text" placeholder="Nombre (ej: Felipe Gómez)" required></div>
        <div class="campo"><input name="especialidades" type="text" maxlength="255" placeholder="Especialidades (ej: degradados, barba)"></div>
        <div class="campo"><input name="biografia" type="text" maxlength="255" placeholder="Frase o biografía corta"></div>
        <div class="fila">
            <div class="campo"><input name="emoji" type="text" maxlength="8" placeholder="Avatar (emoji)"></div>
        </div>
        <label class="chk"><input type="checkbox" name="activo" checked> Visible en la web</label>
        <button class="btn" type="submit">Crear barbero</button>
    </form>

    <?php foreach ($barberos as $b): ?>
        <form class="editor <?= $b['activo'] ? '' : 'editor-apagado' ?>" method="post">
            <?= campo_csrf() ?>
            <input type="hidden" name="accion" value="guardar_barbero">
            <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
            <div class="fila" style="align-items:center">
                <label>✏️ ID #<?= (int) $b['id'] ?></label>
                <?= pillEstado($b['activo'] ? 'confirmada' : 'cancelada') ?>
            </div>
            <div class="campo"><input name="nombre" type="text" value="<?= e($b['nombre']) ?>" maxlength="100" required></div>
            <div class="campo"><input name="especialidades" type="text" value="<?= e($b['especialidades'] ?? '') ?>" maxlength="255"></div>
            <div class="campo"><input name="biografia" type="text" value="<?= e($b['biografia'] ?? '') ?>" maxlength="255"></div>
            <div class="fila">
                <div class="campo">
                    <label class="mini">😀 Avatar</label>
                    <input name="emoji" type="text" maxlength="8" value="<?= e($b['emoji']) ?>">
                </div>
            </div>
            <label class="chk"><input type="checkbox" name="activo" <?= $b['activo'] ? 'checked' : '' ?>> Visible en la web</label>
            <button class="btn btn-sec" type="submit">Guardar cambios</button>
        </form>

        <!-- 🔑 acceso de sesion -->
        <?php $cuenta = $cuentas[(int) $b['id']] ?? null; ?>
        <form class="editor" method="post">
            <?= campo_csrf() ?>
            <input type="hidden" name="accion" value="acceso_barbero">
            <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
            <strong>🔑 Acceso de <?= e($b['nombre']) ?></strong>
            <?php if ($cuenta): ?>
                <p class="texto-suave">✅ Tiene acceso · entra con el teléfono <strong><?= e($cuenta['telefono']) ?></strong>.
                    Deja en blanco lo que no quieras cambiar.</p>
            <?php else: ?>
                <p class="texto-suave">🆓 Sin acceso: el barbero aún no puede iniciar sesión (rol <em>barbero</em>).</p>
            <?php endif; ?>
            <div class="campo">
                <label class="mini">📱 Teléfono de acceso (con él inicia sesión)</label>
                <input name="telefono" type="tel" inputmode="numeric"
                       value="<?= $cuenta ? e($cuenta['telefono']) : '' ?>">
            </div>
            <div class="campo">
                <label class="mini"><?= $cuenta ? 'Nueva contraseña (opcional)' : '🔒 Contraseña (mínimo 6)' ?></label>
                <input name="pass" type="password" autocomplete="new-password" <?= $cuenta ? '' : 'required' ?>>
            </div>
            <button class="btn btn-sec" type="submit"><?= $cuenta ? 'Actualizar acceso' : 'Crear acceso del barbero' ?></button>
        </form>

        <!-- 📸 foto del barbero (también la puede subir el propio barbero desde su Cuenta) -->
        <form class="editor" method="post" enctype="multipart/form-data">
            <?= campo_csrf() ?>
            <input type="hidden" name="accion" value="foto_barbero">
            <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
            <strong>📸 Foto de <?= e($b['nombre']) ?></strong>
            <?php if ($b['foto']): ?>
                <p class="texto-suave"><img src="<?= e($b['foto']) ?>" alt="Foto de <?= e($b['nombre']) ?>"
                    style="width:3.2rem;height:3.2rem;border-radius:50%;object-fit:cover;vertical-align:middle">
                    Tiene foto. Puedes cambiarla.</p>
            <?php endif; ?>
            <div class="campo">
                <input name="foto" type="file" accept="image/png,image/jpeg,image/webp" required>
            </div>
            <button class="btn btn-sec" type="submit">Guardar foto</button>
        </form>
    <?php endforeach; ?>

    <p class="texto-suave centro" style="margin:1rem 0 2rem">
        🔒 Los barberos con citas en su historial no se borran, solo se ocultan.
        Horarios de cada uno: sección <a href="horarios.php">⏰ Horarios</a>.
    </p>

    <div style="height:5.5rem"></div>
</div>

<?php barraInferior(''); ?>
</body>
</html>
