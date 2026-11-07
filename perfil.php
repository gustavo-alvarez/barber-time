<?php
/**
 * MI CUENTA / PERFIL
 * --------------------
 * 1) EDICIÓN de datos propios (nombre, teléfono, email): funciona para
 *    cualquier usuario logueado (cliente, admin — y barbero a futuro).
 *    · El teléfono es la "llave" de login: debe seguir siendo único.
 *    · El email también es único SI se diligencia.
 * 2) Cambio de contraseña: pedimos la ACTUAL; si dejan el celular
 *    desbloqueado, nadie puede secuestrar la cuenta ajena.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/citas.php'; // fechaBonita() para "Miembro desde"

requiere_login();
$usuario = usuario_actual();
$pdo     = db();

/* La ficha de barbero (si esta cuenta es de un barbero) */
$fichaBarbero = null;
if ($usuario['rol'] === 'barbero' && $usuario['barbero_id']) {
    $st = $pdo->prepare('SELECT * FROM barberos WHERE id = ?');
    $st->execute([$usuario['barbero_id']]);
    $fichaBarbero = $st->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validar_csrf();
    $accion = $_POST['accion'] ?? '';

    /* ---------------- 1) Guardar datos personales ---------------- */
    if ($accion === 'guardar_datos') {
        $nombre   = trim($_POST['nombre'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $email    = trim($_POST['email'] ?? '');

        // Normalización anti-teclado: quita espacios, guiones y puntos del teléfono
        $telefono = preg_replace('/[\s\-\.]/', '', $telefono);
        $email    = mb_strtolower($email);

        $errores = [];
        if (mb_strlen($nombre) < 3) $errores[] = 'El nombre debe tener al menos 3 letras.';
        if (!preg_match('/^[0-9]{7,15}$/', $telefono)) $errores[] = 'El teléfono debe tener entre 7 y 15 dígitos (solo números).';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errores[] = 'El email no es válido.';

        if (!$errores) {
            // ¿Ese teléfono o email ya pertenecen a OTRA cuenta?
            $checar = $pdo->prepare('SELECT telefono, email FROM usuarios WHERE id <> ? AND (telefono = ? OR (email IS NOT NULL AND email = ?))');
            $checar->execute([$usuario['id'], $telefono, $email ?: '']);
            if ($existe = $checar->fetch()) {
                if ($existe['telefono'] === $telefono) $errores[] = 'Ese teléfono ya lo usa otra cuenta.';
                if ($existe['email'] === $email)       $errores[] = 'Ese email ya lo usa otra cuenta.';
            }
        }

        if ($errores) {
            foreach ($errores as $er) { flash($er, 'mal'); }
        } else {
            $pdo->prepare('UPDATE usuarios SET nombre = ?, telefono = ?, email = ? WHERE id = ?')
                ->execute([$nombre, $telefono, $email !== '' ? $email : null, $usuario['id']]);

            // El nombre del barbero es el que muestra el CATALOGO: se sincroniza
            if ($fichaBarbero) {
                $pdo->prepare('UPDATE barberos SET nombre = ? WHERE id = ?')
                    ->execute([$nombre, $fichaBarbero['id']]);
            }

            $aviso = '✅ Tus datos se actualizaron.';
            if ($telefono !== $usuario['telefono']) {
                $aviso .= ' 📱 OJO: tu forma de iniciar sesión ahora es con el nuevo teléfono.';
            }
            if ($email === '' && ($usuario['email'] ?? '')) {
                $aviso .= ' ✉️ QUITASTE tu email: ya no podrás iniciar sesión con él.';
            }
            flash($aviso);
        }
        header('Location: perfil.php'); // PRG: recarga limpia sin re-envío de datos
        exit;
    }

    /* ---------------- 2) Cambiar contraseña ---------------- */
    if ($accion === 'cambiar_pass') {
        $actual  = $_POST['actual'] ?? '';
        $nueva   = $_POST['nueva'] ?? '';
        $nueva2  = $_POST['nueva2'] ?? '';

        $stmt    = $pdo->prepare('SELECT pass_hash FROM usuarios WHERE id = ?');
        $stmt->execute([$usuario['id']]);
        $hashActual = $stmt->fetchColumn();

        $errores = [];
        if (!$hashActual || !password_verify($actual, $hashActual)) {
            $errores[] = 'La contraseña actual no es correcta.';
        }
        if (strlen($nueva) < 6) {
            $errores[] = 'La nueva contraseña debe tener al menos 6 caracteres.';
        }
        if ($nueva !== $nueva2) {
            $errores[] = 'Las contraseñas nuevas no coinciden.';
        }

        if ($errores) {
            foreach ($errores as $er) { flash($er, 'mal'); }
        } else {
            $pdo->prepare('UPDATE usuarios SET pass_hash = ? WHERE id = ?')
                ->execute([password_hash($nueva, PASSWORD_DEFAULT), $usuario['id']]);
            flash('🔐 Contraseña actualizada con éxito. Úsala desde ahora.');
        }
        header('Location: perfil.php');
        exit;
    }

    /* ---------------- 3) Foto del barbero (solo si es barbero) --------------- */
    if ($accion === 'subir_foto') {
        if (!$fichaBarbero) {
            flash('Solo los barberos tienen foto de perfil. 🤨', 'mal');
        } else {
            $res = imagenSubida($_FILES['foto'] ?? [], 'barbero-' . $fichaBarbero['id']);
            if (!$res['ok']) {
                flash($res['error'], 'mal');
            } else {
                borrarImagenSubida($fichaBarbero['foto']); // la foto vieja no acumula basura
                $pdo->prepare('UPDATE barberos SET foto = ? WHERE id = ?')
                    ->execute([$res['ruta'], $fichaBarbero['id']]);
                flash('📸 ¡Tu foto quedó lista! Ya aparece en el catálogo.');
            }
        }
        header('Location: perfil.php');
        exit;
    }

    if ($accion === 'quitar_foto') {
        if ($fichaBarbero) {
            borrarImagenSubida($fichaBarbero['foto']);
            $pdo->prepare('UPDATE barberos SET foto = NULL WHERE id = ?')
                ->execute([$fichaBarbero['id']]);
            flash('Foto eliminada. Volviste al avatar de emoji. 💈');
        }
        header('Location: perfil.php');
        exit;
    }

    flash('Acción inválida. 🤨', 'mal');
    header('Location: perfil.php');
    exit;
}

/* Etiqueta de rol bonita */
$lblRol = match ($usuario['rol']) {
    'admin'   => '🛡️ Administrador',
    'barbero' => '💈 Barbero',
    default   => '👤 Cliente',
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#111111">
    <link rel="icon" href="assets/icono.svg" type="image/svg+xml">
    <link rel="manifest" href="manifest.php">
    <title>Mi cuenta · <?= e(nombreNegocio()) ?></title>
    <link rel="stylesheet" href="assets/css/estilos.css">
</head>
<body>
<div class="container">
    <div class="tarjeta" style="margin-top:1.5rem">
        <?= mostrar_flash() ?>

        <h1>Mi cuenta 👤</h1>
        <div class="tarjeta" style="background:#0d0d0d; margin:.75rem 0">
            <strong><?= e($usuario['nombre']) ?></strong><br>
            <span class="texto-suave">📱 <?= e($usuario['telefono']) ?></span>
            <small class="texto-suave" style="display:block;font-size:.72rem">(con este teléfono inicias sesión)</small>
            <span class="texto-suave">✉️ <?= e($usuario['email'] ?? 'sin email registrado') ?></span><br>
            <span class="texto-suave"><?= $lblRol ?></span><br>
            <span class="texto-suave">📅 Miembro desde <?= e(fechaBonita(substr((string) $usuario['creado_en'], 0, 10))) ?></span>
        </div>

        <?php if ($usuario['rol'] === 'admin'): ?>
            <a class="btn" href="admin.php" style="display:block;text-align:center;text-decoration:none">
                🛡️ Panel de administración
            </a>
        <?php endif; ?>

        <?php if ($fichaBarbero): ?>
            <p class="texto-suave" style="margin-top:0">
                💡 Este es el <strong>perfil que muestra el catálogo</strong> a tus clientes:
                lo que cambies aquí, cambia en la web.
            </p>
        <?php endif; ?>
        <hr>

        <?php if ($fichaBarbero): ?>
        <h2 class="seccion">📸 Mi foto</h2>
        <div class="tarjeta" style="background:#0d0d0d; margin:.75rem 0; text-align:center">
            <?php if ($fichaBarbero['foto']): ?>
                <img src="<?= e($fichaBarbero['foto']) ?>" alt="Mi foto"
                     style="width:5.5rem;height:5.5rem;border-radius:50%;object-fit:cover">
            <?php else: ?>
                <span class="avatar" style="font-size:2.6rem"><?= e($fichaBarbero['emoji']) ?></span><br>
                <small class="texto-suave">Aún sin foto: el catálogo muestra el emoji</small>
            <?php endif; ?>
        </div>
        <form method="post" enctype="multipart/form-data">
            <?= campo_csrf() ?>
            <input type="hidden" name="accion" value="subir_foto">
            <div class="campo">
                <label for="foto">Nueva foto (PNG, JPG o WEBP · máx 2 MB)</label>
                <input id="foto" name="foto" type="file" accept="image/png,image/jpeg,image/webp" required>
            </div>
            <button class="btn" type="submit">Subir foto</button>
        </form>
        <?php if ($fichaBarbero['foto']): ?>
            <form method="post" style="margin-top:.4rem">
                <?= campo_csrf() ?>
                <input type="hidden" name="accion" value="quitar_foto">
                <button class="btn btn-sec" type="submit">Quitar foto</button>
            </form>
        <?php endif; ?>
        <hr>
        <?php endif; ?>

        <details class="editor-detalles">
            <summary>✏️ Cambiar mis datos</summary>
            <form method="post" novalidate>
                <?= campo_csrf() ?>
                <input type="hidden" name="accion" value="guardar_datos">
                <div class="campo">
                    <label for="nombre">Nombre</label>
                    <input id="nombre" name="nombre" type="text" maxlength="100"
                           value="<?= e($usuario['nombre']) ?>" required>
                </div>
                <div class="campo">
                    <label for="telefono">Teléfono (con él inicias sesión)</label>
                    <input id="telefono" name="telefono" type="tel" inputmode="numeric"
                           value="<?= e($usuario['telefono']) ?>" required>
                </div>
                <div class="campo">
                    <label for="email">Email (opcional)</label>
                    <input id="email" name="email" type="email" maxlength="120"
                           value="<?= e($usuario['email'] ?? '') ?>" placeholder="opcional">
                </div>
                <button class="btn" type="submit">Guardar mis datos</button>
            </form>
        </details>

        <details class="editor-detalles">
            <summary>🔐 Cambiar contraseña</summary>
            <form method="post" novalidate>
                <?= campo_csrf() ?>
                <input type="hidden" name="accion" value="cambiar_pass">
                <div class="campo">
                    <label for="actual">Contraseña actual</label>
                    <input id="actual" name="actual" type="password" autocomplete="current-password" required>
                </div>
                <div class="campo">
                    <label for="nueva">Nueva contraseña (mínimo 6)</label>
                    <input id="nueva" name="nueva" type="password" autocomplete="new-password" required>
                </div>
                <div class="campo">
                    <label for="nueva2">Repite la nueva</label>
                    <input id="nueva2" name="nueva2" type="password" autocomplete="new-password" required>
                </div>
                <button class="btn" type="submit">Guardar nueva contraseña</button>
            </form>
        </details>

        <a class="btn btn-sec" style="margin-top:.5rem" href="<?= $usuario['rol'] === 'admin' ? 'mis_citas.php' : 'panel.php' ?>">⬅ Volver a mis citas</a>
    </div>

    <div style="height:5.5rem"></div>
</div>

<?php barraInferior('cuenta'); ?>
</body>
</html>
