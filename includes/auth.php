<?php
/**
 * AUTENTICACIÓN Y SESIONES
 * -------------------------
 * El "candado" del proyecto. Todas las páginas que requieran
 * usuario logueado usarán las funciones de aquí.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ajustes.php';        // ajustes del negocio (nombre, logo, contacto)
require_once __DIR__ . '/imagenes.php';       // subida segura de imágenes (logo, fotos de barberos)

// Arrancar la sesión solo una vez por página
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* -------------------------------------------------
 * USUARIO ACTUAL
 * Devuelve los datos del usuario logueado (o null)
 * ------------------------------------------------- */
function usuario_actual(): ?array
{
    if (empty($_SESSION['usuario_id'])) {
        return null;
    }
    static $usuario = null;
    if ($usuario === null) {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT id, nombre, telefono, email, rol, barbero_id, creado_en FROM usuarios WHERE id = ? AND activo = 1');
        $stmt->execute([$_SESSION['usuario_id']]);
        $usuario = $stmt->fetch() ?: null;
    }
    return $usuario;
}

/* -------------------------------------------------
 * PROTECCIÓN DE PÁGINAS
 * ------------------------------------------------- */
function requiere_login(): void
{
    if (!usuario_actual()) {
        header('Location: login.php');
        exit;
    }
}

function requiere_rol(string $rol): void
{
    requiere_login();
    if (usuario_actual()['rol'] !== $rol) {
        // No tiene permiso: lo mandamos a su panel
        header('Location: panel.php');
        exit;
    }
}

/* -------------------------------------------------
 * CSRF (Cross-Site Request Forgery)
 * Token invisible en cada formulario para que nadie
 * pueda enviar datos "suplantando" al usuario.
 * ------------------------------------------------- */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function campo_csrf(): string
{
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}

function validar_csrf(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(403);
        die('Token de seguridad inválido. Vuelve atrás e intenta de nuevo.');
    }
}

/* -------------------------------------------------
 * MENSAJES FLASH (aviso de una sola vez entre páginas)
 * Ej: "Registro exitoso, ahora inicia sesión"
 * ------------------------------------------------- */
function flash(string $mensaje, string $tipo = 'ok'): void
{
    $_SESSION['flash'] = ['mensaje' => $mensaje, 'tipo' => $tipo];
}

function mostrar_flash(): string
{
    if (empty($_SESSION['flash'])) {
        return '';
    }
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    $clase = $f['tipo'] === 'mal' ? 'alerta alerta-mal' : 'alerta alerta-ok';
    return '<div class="' . $clase . '">' . htmlspecialchars($f['mensaje']) . '</div>';
}

/* -------------------------------------------------
 * ESCAPE DE HTML (obligatorio al mostrar datos)
 * Evita que un usuario inyecte código en la página.
 * ------------------------------------------------- */
function e(?string $texto): string
{
    return htmlspecialchars($texto ?? '', ENT_QUOTES, 'UTF-8');
}

/* -------------------------------------------------
 * AVATAR PEQUEÑO INLINE (foto real o emoji de respaldo)
 * -------------------------------------------------
 * Se usa en listados (mis citas, cancelar, agenda del
 * admin): si el barbero tiene foto se muestra, y si no,
 * su emoji — igual que el catálogo, pero miniatura.
 */
function avatarMini(?string $foto, ?string $emoji, string $nombre = ''): string
{
    return $foto
        ? '<img class="avatar-mini" src="' . e($foto) . '" alt="Foto de ' . e($nombre) . '">'
        : '<span class="avatar-mini">' . e($emoji ?: '💈') . '</span>';
}

/* -------------------------------------------------
 * BARRA INFERIOR ÚNICA (navegación tipo app)
 * -------------------------------------------------
 * Una sola fuente de verdad: se pinta igual en todas
 * las páginas y según el ROL del usuario logueado.
 *   $activo: clave del ítem resaltado
 *   ('inicio' | 'agendar' | 'citas' | 'cuenta' | 'salir')
 * Sin sesión NO se pinta (públicas: index, login, registro).
 * ------------------------------------------------- */
function barraInferior(string $activo): void
{
    $u = usuario_actual();
    if (!$u) {
        // Visitante anónimo: barra mínima pública (solo en páginas abiertas)
        echo '<nav class="barra-inferior">'
           . '<a href="index.php"' . ($activo === 'inicio' ? ' class="activo"' : '') . '><span class="icono">🏚️</span>Inicio</a>'
           . '<a href="agendar.php"' . ($activo === 'agendar' ? ' class="activo"' : '') . '><span class="icono">📆</span>Agendar</a>'
           . '<a href="login.php"><span class="icono">😎</span>Entrar</a>'
           . '</nav>';
        return;
    }

    $esAdmin   = $u['rol'] === 'admin';
    $esBarbero = $u['rol'] === 'barbero';
    $esCliente = $u['rol'] === 'cliente';

    // --- Destino del ítem "Citas" según el rol ---
    // cliente: sus citas agendadas (panel)
    // barbero: la gestión de SUS turnos (mis_citas, se habilita con su rol)
    // admin:   la gestión completa de las citas de todos
    // Puente temporal: mis_citas.php existe? úsala; si aún no (llega en la
    // sub-etapa 3), el admin usa la página de hoy (admin.php).

    if ($esAdmin) {
        $urlCitas = is_file(__DIR__ . '/../mis_citas.php') ? 'mis_citas.php' : 'admin.php';
        $lblCitas = 'Las Citas';
    } elseif ($esBarbero) {
        $urlCitas = 'mis_citas.php';
        $lblCitas = 'Mis Citas';
    } else {
        $urlCitas = 'panel.php';
        $lblCitas = 'Mis Citas';
    }

    $items = [
        'inicio'  => ['index.php',    '🏚️', 'Inicio'],
        'agendar' => ['agendar.php',  '📆', 'Agendar'],
        'citas'   => [$urlCitas,      '🕓', $lblCitas],
        'cuenta'  => ['perfil.php',   '😎', 'Mi Cuenta'],
        'salir'   => ['logout.php',   '🚪', 'Salir'],
    ];

    echo '<nav class="barra-inferior">';
    foreach ($items as $clave => [$url, $icono, $texto]) {
        echo '<a href="' . e($url) . '"' . ($clave === $activo ? ' class="activo"' : '') . '>'
           . '<span class="icono">' . $icono . '</span>' . e($texto)
           . '</a>';
    }
    echo '</nav>';
}
