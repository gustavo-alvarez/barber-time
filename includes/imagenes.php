<?php
/**
 * SUBIDA SEGURA DE IMÁGENES (logo, fotos de barberos, etc.)
 * ---------------------------------------------------------
 * Un único camino fortificado para cualquier imagen que suba
 * un usuario al servidor:
 *   1) Verificación REAL del tipo por CONTENIDO (finfo + getimagesize),
 *      no por el nombre del archivo que diga el navegador.
 *   2) Solo PNG, JPG o WEBP, máximo 2 MB.
 *   3) Carpeta `assets/uploads` con .htaccess que impide ejecutar código.
 *   4) Nombre propio del servidor (nunca el del usuario) con prefijo para saber de qué es.
 * Devuelve ['ok'=>bool, 'ruta'=>?string, 'error'=>?string].
 */

function imagenSubida(array $doc, string $prefijo): array
{
    // Nada en $_FILES['foto']: el navegador no envió el campo (o no eligieron archivo)
    if (!$doc || ($doc['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'ruta' => null, 'error' => 'Primero elige una foto de tu galería 📱 y vuelve a pulsar Subir.'];
    }
    if ($doc['error'] !== UPLOAD_ERR_OK) {
        // Mensajes "humanos" para los códigos típicos (4 = no se eligió archivo)
        $causas = [
            UPLOAD_ERR_NO_FILE   => 'Primero elige una foto de tu galería 📱 y vuelve a pulsar Subir.',
            UPLOAD_ERR_INI_SIZE  => 'La foto supera el límite del servidor. Intenta con una más pequeña.',
            UPLOAD_ERR_FORM_SIZE => 'La foto supera el límite del formulario.',
            UPLOAD_ERR_PARTIAL   => 'La subida llegó incompleta. Reintenta con mejor señal.',
        ];
        $causa = $causas[$doc['error']] ?? 'No se pudo procesar la subida (código ' . $doc['error'] . '). Intenta de nuevo.';
        return ['ok' => false, 'ruta' => null, 'error' => $causa];
    }
    if ($doc['size'] > 2 * 1024 * 1024) {
        return ['ok' => false, 'ruta' => null, 'error' => 'La imagen pesa más de 2 MB. Usa una más pequeña.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($doc['tmp_name']) ?: '';
    $permitidos = [
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    if (!isset($permitidos[$mime]) || getimagesize($doc['tmp_name']) === false) {
        return ['ok' => false, 'ruta' => null, 'error' => 'Formato no admitido. Usa PNG, JPG o WEBP.'];
    }

    $dir = __DIR__ . '/../assets/uploads';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    // Blindaje: dentro de uploads no se ejecuta nada (ni PHP ni scripts)
    $htaccess = $dir . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "<FilesMatch \"\\.(php|phar|phtml)$\">\n Require all denied\n </FilesMatch>\nOptions -Indexes\n");
    }

    $ext  = $permitidos[$mime];
    $file = $prefijo . '-' . bin2hex(random_bytes(4)) . '-' . time() . '.' . $ext;
    if (!move_uploaded_file($doc['tmp_name'], $dir . '/' . $file)) {
        return ['ok' => false, 'ruta' => null, 'error' => 'No se pudo guardar la imagen en el servidor.'];
    }

    return ['ok' => true, 'ruta' => 'assets/uploads/' . $file, 'error' => null];
}

/** Borra un archivo subido anteriormente (solo si vive en assets/uploads) */
function borrarImagenSubida(?string $ruta): void
{
    if ($ruta && str_contains($ruta, 'assets/uploads/')) {
        @unlink(__DIR__ . '/../' . $ruta);
    }
}
