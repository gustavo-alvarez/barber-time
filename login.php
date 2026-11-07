<?php
/**
 * INICIO DE SESIÓN
 * ------------------
 * Permite entrar con TELÉFONO o EMAIL (lo que el usuario prefiera)
 * + su contraseña. Al entrar, guardamos en la sesión su id y su rol.
 */

require_once __DIR__ . '/includes/auth.php';

// Si ya está logueado, no mostramos el formulario
if (usuario_actual()) {
    header('Location: panel.php');
    exit;
}

$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validar_csrf();

    // Normalización anti-teclados móviles: minúsculas, sin espacios sobrantes
    $identificador = trim(mb_strtolower($_POST['identificador'] ?? ''));
    // Si no tiene @ pero trae espacios/guiones/puntos (el celular a veces los mete al teléfono), los limpiamos
    if (!str_contains($identificador, '@')) {
        $identificador = preg_replace('/[\s\-\.]/', '', $identificador);
    }
    $pass          = $_POST['pass'] ?? '';

    if (!$identificador || !$pass) {
        $errores[] = 'Completa todos los campos.';
    } else {
        $pdo  = db();
        // Aceptamos teléfono O email en el mismo campo
        $stmt = $pdo->prepare(
            'SELECT id, nombre, pass_hash, rol FROM usuarios
             WHERE activo = 1 AND (telefono = ? OR (email IS NOT NULL AND email = ?))
             LIMIT 1'
        );
        $stmt->execute([$identificador, $identificador]);
        $usuario = $stmt->fetch();

        // password_verify() compara de forma segura contra el hash guardado
        if (!$usuario || !password_verify($pass, $usuario['pass_hash'])) {
            $errores[] = 'Teléfono/email o contraseña incorrectos.';
        }
    }

    if (!$errores) {
        // Guardamos lo mínimo en la sesión (jamás la contraseña)
        $_SESSION['usuario_id'] = (int) $usuario['id'];
        session_regenerate_id(true); // evita ataques de "secuestro de sesión"
        flash('👋 ¡Hola, ' . $usuario['nombre'] . '!');

        // Cada rol aterriza en SU página:
        //  admin   → citas del día · barbero → sus turnos · cliente → sus citas
        $destino = match ($usuario['rol']) {
            'admin'   => 'admin.php',
            'barbero' => is_file(__DIR__ . '/mis_citas.php') ? 'mis_citas.php' : 'panel.php',
            default   => 'panel.php',
        };
        header('Location: ' . $destino);
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
    <title>Iniciar sesión · <?= e(nombreNegocio()) ?></title>
    <link rel="stylesheet" href="assets/css/estilos.css">
</head>
<body>
<div class="container">
    <div class="tarjeta" style="margin-top:2rem; text-align:center">
        <h1 style="font-size:2rem">💈</h1>
        <h1><?= e(nombreNegocio()) ?></h1>
        <p class="texto-suave">Tu corte, a una cita de distancia</p>
        <hr style="text-align:left">

        <?= mostrar_flash() ?>

        <?php foreach ($errores as $err): ?>
            <div class="alerta alerta-mal"><?= e($err) ?></div>
        <?php endforeach; ?>

        <form method="post">
            <?= campo_csrf() ?>

            <div class="campo">
                <label for="identificador">Teléfono o email</label>
                <!-- atributos anti-teclado-móvil: sin mayúsculas automáticas, sin autocorrección -->
                <input id="identificador" name="identificador" type="text"
                       autocomplete="username" autocapitalize="none" autocorrect="off"
                       spellcheck="false" enterkeyhint="go"
                       placeholder="Tu celular o correo" required>
            </div>

            <div class="campo">
                <label for="pass">Contraseña</label>
                <input id="pass" name="pass" type="password" autocomplete="current-password"
                       autocapitalize="none" autocorrect="off" spellcheck="false" required>
            </div>

            <button class="btn" type="submit">Entrar</button>
        </form>

        <p style="margin-top:1rem">
            ¿Primera vez? <a href="registro.php">Crea tu cuenta</a>
        </p>
    </div>
</div>
</body>
</html>
