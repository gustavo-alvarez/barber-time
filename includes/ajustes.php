<?php
/**
 * AJUSTES DEL NEGOCIO
 * --------------------
 * Lee la tabla `opciones` (clave → valor) con caché por página.
 * Si la tabla aún no existe (proyecto recién clonado), devuelve
 * los valores por defecto sin quebrar nada.
 */

require_once __DIR__ . '/db.php';

function opcion(string $clave, string $defecto = ''): string
{
    static $cache = null;

    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query('SELECT clave, valor FROM opciones')->fetchAll() as $fila) {
                $cache[$fila['clave']] = (string) $fila['valor'];
            }
        } catch (Throwable) {
            // BD/tabla no disponible todavía: valores por defecto
        }
    }
    return $cache[$clave] ?? $defecto;
}

/** Escribe o crea una opción (INSERT ... ON DUPLICATE UPDATE) */
function guardarOpcion(string $clave, string $valor): void
{
    db()->prepare('INSERT INTO opciones (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)')
        ->execute([$clave, $valor]);
}

/* ---------- Accesores cómodos ---------- */
function nombreNegocio(): string   { return opcion('nombre_negocio', 'Barber-Time'); }
function logoNegocio(): string     { return opcion('logo', ''); }          // '' = usar el ícono por defecto
function whatsappNegocio(): string { return opcion('whatsapp', ''); }
function direccionNegocio(): string{ return opcion('direccion', ''); }

/** Ruta del logo para <img> y para la PWA: el subido o el ícono genérico */
function logoOIcono(): string
{
    $logo = logoNegocio();
    return $logo !== '' ? $logo : 'assets/icono.svg';
}
