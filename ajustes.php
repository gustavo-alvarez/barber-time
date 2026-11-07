<?php
/**
 * AJUSTES DEL NEGOCIO (solo admin) — Mejora A
 * --------------------------------------------
 * Nombre, WhatsApp, dirección y logo. Todo queda en la tabla
 * `opciones` y la app entera lo refleja (títulos, portada, PWA).
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/citas.php'; // pillEstado para el badge de "visible"

requiere_rol('admin');
$pdo = db();

/* ----------------- Guardar (POST) ----------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validar_csrf();

    $nombre    = trim($_POST['nombre'] ?? '');
    $whatsapp  = trim($_POST['whatsapp'] ?? '');
    $direccion = trim($_POST['direccion'] ?? '');

    $errores = [];
    if (mb_strlen($nombre) < 2)  $errores[] = 'El nombre del negocio debe tener al menos 2 letras.';
    if (mb_strlen($direccion) > 200) $errores[] = 'La dirección es muy larga (máximo 200).';

    // WhatsApp se guarda solo con dígitos (lo que pide wa.me)
    $whatsapp = preg_replace('/\D/', '', $whatsapp);
    if ($whatsapp !== '' && !preg_match('/^[0-9]{7,15}$/', $whatsapp)) {
        $errores[] = 'El WhatsApp debe ser solo números (7 a 15 dígitos), con el indicativo de tu país.';
    }

    /* ---------- Logo (opcional) ---------- */
    if (!empty($_FILES['logo']['name'])) {
        $doc = $_FILES['logo'];

        if ($doc['error'] !== UPLOAD_ERR_OK) {
            $errores[] = 'No se pudo subir la imagen del logo (código ' . (int) $doc['error'] . ').';
        } elseif ($doc['size'] > 2 * 1024 * 1024) {
            $errores[] = 'El logo pesa más de 2 MB. Escal Bustelo a menos.';
        } else {
            // Verificación REAL del tipo por contenido (no por la extensión que diga el navegador)
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($doc['tmp_name']) ?: '';
            $permitidos = [
                'image/png'  => 'png',
                'image/jpeg' => 'jpg',
                'image/webp' => 'webp',
            ];

            if (!isset($permitidos[$mime]) || getimagesize($doc['tmp_name']) === false) {
                $errores[] = 'Formato no admitido. Usa PNG, JPG o WEBP.';
            } else {
                $dir = __DIR__ . '/assets/uploads';
                if (!is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }

                // Blindaje: dentro de uploads no se ejecuta nada (ni PHP ni scripts)
                $htaccess = $dir . '/.htaccess';
                if (!file_exists($htaccess)) {
                    file_put_contents($htaccess, "<FilesMatch \"\\.(php|phar|phtml)$\">\n Require all denied\n </FilesMatch>\nOptions -Indexes\n");
                }

                $ext  = $permitidos[$mime];
                $file = 'logo-' . time() . '.' . $ext;
                if (!move_uploaded_file($doc['tmp_name'], $dir . '/' . $file)) {
                    $errores[] = 'No se pudo guardar el logo en el servidor.';
                } else {
                    // Borramos el logo anterior (si era subido) para no acumular basura
                    $previo = logoNegocio();
                    if ($previo !== '' && str_contains($previo, 'assets/uploads/')) {
                        @unlink(__DIR__ . '/' . $previo);
                    }
                    guardarOpcion('logo', 'assets/uploads/' . $file);
                }
            }
        }
    }

    if ($errores) {
        foreach ($errores as $er) { flash($er, 'mal'); }
    } else {
        guardarOpcion('nombre_negocio', $nombre);
        guardarOpcion('whatsapp', $whatsapp);
        guardarOpcion('direccion', $direccion);
        flash('✅ Ajustes del negocio guardados.');
    }
    header('Location: ajustes.php');
    exit;
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
    <title>Ajustes · <?= e(nombreNegocio()) ?></title>
    <link rel="stylesheet" href="assets/css/estilos.css">
</head>
<body>
<div class="container">
    <div class="tarjeta" style="margin-top:1.5rem">
        <?= mostrar_flash() ?>

        <h1>⚙️ Ajustes del negocio</h1>
        <p class="texto-suave">
            <a href="admin.php">← Volver al panel de citas</a> ·
            <a href="editar_barberos.php">💈 Barberos</a> ·
            <a href="editar_servicios.php">✂️ Servicios</a> ·
            <a href="horarios.php">⏰ Horarios</a>
        </p>
        <hr>

        <div class="tarjeta" style="background:#0d0d0d; text-align:center">
            <img class="logo logo-grande" src="<?= e(logoOIcono()) ?>" alt="Logo actual"
                 onerror="this.style.display='none'">
            <p><strong><?= e(nombreNegocio()) ?></strong></p>
            <?php if (whatsappNegocio()): ?><p class="texto-suave">📲 WhatsApp: <?= e(whatsappNegocio()) ?></p><?php endif; ?>
            <?php if (direccionNegocio()): ?><p class="texto-suave">📍 <?= e(direccionNegocio()) ?></p><?php endif; ?>
        </div>

        <form method="post" enctype="multipart/form-data" style="margin-top:1rem">
            <?= campo_csrf() ?>

            <div class="campo">
                <label for="nombre">Nombre del negocio *</label>
                <input id="nombre" name="nombre" type="text" maxlength="60" required
                       value="<?= e(nombreNegocio()) ?>">
            </div>

            <div class="campo">
                <label for="whatsapp">WhatsApp (solo números, opcional)</label>
                <input id="whatsapp" name="whatsapp" type="text" inputmode="numeric"
                       placeholder="5730055fijo" value="<?= e(whatsappNegocio()) ?>">
            </div>

            <div class="campo">
                <label for="direccion">Dirección de la barbería</label>
                <input id="direccion" name="direccion" type="text" maxlength="200"
                       placeholder="Av. Siempre Viva 742" value="<?= e(direccionNegocio()) ?>">
            </div>

            <div class="campo">
                <label for="logo">Logo (PNG / JPG / WEBP, máx 2 MB)</label>
                <input id="logo" name="logo" type="file" accept="image/png,image/jpeg,image/webp">
                <p class="texto-suave" style="margin:.3rem 0 0">
                    Déjalo vacío para conservar el logo actual. Es el que verán los clientes
                    y el ícono de la app instalada en su teléfono.
                </p>
            </div>

            <button class="btn" type="submit">Guardar ajustes</button>
        </form>

        <p class="texto-suave centro" style="margin-top:1rem">
            💡 Los cambios se reflejan al instante en la portada, el login,
            el registro y la app instalada.
        </p>
    </div>

    <div style="height:5.5rem"></div>
</div>

<?php barraInferior(''); ?>
</body>
</html>
