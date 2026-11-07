<?php
/**
 * MANIFEST DINÁMICO (PWA)
 * ------------------------
 * Genera el manifest con el NOMBRE y LOGO configurados por el
 * administrador. Al instalar la app, el teléfono usa estos valores.
 * Las etiquetas <link rel="manifest"> apuntan a manifest.php.
 */

require_once __DIR__ . '/includes/ajustes.php';

header('Content-Type: application/manifest+json; charset=utf-8');

$nombre    = nombreNegocio();
$logo      = logoOIcono(); // assets/icono.svg o el archivo subido (png/jpg/webp)

if (str_ends_with($logo, '.svg')) {
    $icons = [
        ['src' => $logo, 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any'],
        ['src' => $logo, 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'maskable'],
    ];
} else {
    $mime = 'image/webp';
    if (str_ends_with($logo, '.png')) $mime = 'image/png';
    if (str_ends_with($logo, '.jpg')) $mime = 'image/jpeg';
    $icons = [
        ['src' => $logo, 'sizes' => '192x192', 'type' => $mime, 'purpose' => 'any'],
        ['src' => $logo, 'sizes' => '512x512', 'type' => $mime, 'purpose' => 'any'],
    ];
}

echo json_encode([
    'name'             => $nombre,
    'short_name'       => mb_strlen($nombre) > 12 ? mb_substr($nombre, 0, 12) : $nombre,
    'description'      => 'Agenda tu corte en segundos, sin llamadas ni esperas',
    'lang'             => 'es',
    'start_url'        => 'index.php',
    'scope'            => '.',
    'display'          => 'standalone',
    'orientation'      => 'portrait',
    'background_color' => '#111111',
    'theme_color'      => '#111111',
    'icons'            => $icons,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
