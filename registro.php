<?php
/**
 * REGISTRO DE CUENTAS
 * --------------------
 * Formulario para crear una cuenta de cliente.
 * Validamos TODO en el servidor (PHP), no solo en el navegador,
 * porque el navegador se puede saltar.
 */

require_once __DIR__ . '/includes/auth.php';

$errores = [];

// ¿Nos enviaron el formulario? (POST con datos)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validar_csrf();

    $nombre     = trim($_POST['nombre'] ?? '');
    $telefono   = trim($_POST['telefono'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $pass       = $_POST['pass'] ?? '';
    $pass2      = $_POST['pass2'] ?? '';

    // ---- Validaciones básicas ----
    if (mb_strlen($nombre) < 3)          $errores[] = 'El nombre debe tener al menos 3 letras.';
    if (!preg_match('/^[0-9]{7,15}$/', $telefono)) $errores[] = 'El teléfono debe tener entre 7 y 15 dígitos (solo números).';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errores[] = 'El email no es válido.';
    if (strlen($pass) < 6)               $errores[] = 'La contraseña debe tener al menos 6 caracteres.';
    if ($pass !== $pass2)                $errores[] = 'Las contraseñas no coinciden.';

    // ---- ¿Teléfono o email ya registrados? ----
    if (!$errores) {
        $pdo    = db();
        $checar = $pdo->prepare('SELECT telefono, email FROM usuarios WHERE telefono = ? OR (email IS NOT NULL AND email = ?)');
        $checar->execute([$telefono, $email ?: '']);
        if ($existe = $checar->fetch()) {
            if ($existe['telefono'] === $telefono) $errores[] = 'Ese teléfono ya está registrado.';
            if ($existe['email'] === $email)       $errores[] = 'Ese email ya está registrado.';
        }
    }

    // ---- Guardar ----
    if (!$errores) {
        $stmt = $pdo->prepare('INSERT INTO usuarios (nombre, telefono, email, pass_hash, rol) VALUES (?, ?, ?, ?, "cliente")');
        $stmt->execute([
            $nombre,
            $telefono,
            $email !== '' ? $email : null,
            password_hash($pass, PASSWORD_DEFAULT), // nunca se guarda la contraseña "en claro"
        ]);
        flash('✅ Cuenta creada. Ahora inicia sesión.');
        header('Location: login.php');
        exit;
    }
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
    <title>Crear cuenta · <?= e(nombreNegocio()) ?></title>
    <link rel="stylesheet" href="assets/css/estilos.css">
</head>
<body>
<div class="container">
    <div class="tarjeta" style="margin-top:1.5rem">
        <h1>💈 <?= e(nombreNegocio()) ?></h1>
        <p class="texto-suave">Crea tu cuenta para agendar tus cortes</p>
        <hr>

        <?= mostrar_flash() ?>

        <?php foreach ($errores as $err): ?>
            <div class="alerta alerta-mal"><?= e($err) ?></div>
        <?php endforeach; ?>

        <!-- novalidate: la validación la hace PHP (sin ella el teléfono se limpia solo) -->
        <form method="post" novalidate>
            <?= campo_csrf() ?>

            <div class="campo">
                <label for="nombre">Nombre completo *</label>
                <input id="nombre" name="nombre" type="text" autocomplete="name"
                       value="<?= e($_POST['nombre'] ?? '') ?>" required>
            </div>

            <div class="campo">
                <label for="telefono">Teléfono (para confirmar tu cita) *</label>
                <!-- inputmode="tel": abre el teclado de números en el celular -->
                <input id="telefono" name="telefono" type="tel" inputmode="numeric"
                       autocomplete="tel" placeholder="Ej: 3012345678"
                       value="<?= e($_POST['telefono'] ?? '') ?>" required>
            </div>

            <div class="campo">
                <label for="email">Email (opcional)</label>
                <input id="email" name="email" type="email" autocomplete="email"
                       value="<?= e($_POST['email'] ?? '') ?>">
            </div>

            <div class="campo">
                <label for="pass">Contraseña (mínimo 6) *</label>
                <input id="pass" name="pass" type="password" autocomplete="new-password" required>
            </div>

            <div class="campo">
                <label for="pass2">Repite la contraseña *</label>
                <input id="pass2" name="pass2" type="password" autocomplete="new-password" required>
            </div>

            <button class="btn" type="submit">Crear cuenta</button>
        </form>

        <p class="centro" style="margin-top:1rem">
            ¿Ya tienes cuenta? <a href="login.php">Inicia sesión</a>
        </p>
    </div>
</div>
</body>
</html>
