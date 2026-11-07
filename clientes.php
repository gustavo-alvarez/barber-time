<?php
/**
 * CLIENTES REGISTRADOS (solo admin)
 * ---------------------------------
 * Lista de usuarios con rol 'cliente': contacto + actividad (citas)
 * y buscador por nombre, teléfono o email.
 * La contraseña NUNCA aparece en la vista (solo vive cifrada en la BD).
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/citas.php'; // fechaBonita

requiere_rol('admin');
$pdo = db();

/* ---------- Consulta base: clientes + agregados de citas ---------- */
$q = trim($_GET['q'] ?? '');

$sql = "SELECT u.id, u.nombre, u.telefono, u.email, u.activo, u.creado_en,
               COUNT(c.id)                                    AS total_citas,
               IFNULL(SUM(c.estado <> 'cancelada'), 0)        AS citas_atendidas,
               MAX(CASE WHEN c.estado <> 'cancelada'
                        THEN c.fecha END)                     AS ultima_cita
        FROM usuarios u
        LEFT JOIN citas c ON c.usuario_id = u.id
        WHERE u.rol = 'cliente'";

$param = null;
if ($q !== '') {
    $sql .= ' AND (u.nombre LIKE ? OR u.telefono LIKE ? OR u.email LIKE ?)';
    $param = '%' . $q . '%';
}
$sql .= ' GROUP BY u.id ORDER BY u.creado_en DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($param === null ? [] : [$param, $param, $param]);
$clientes = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#111111">
    <link rel="icon" href="<?= e(logoOIcono()) ?>">
    <link rel="manifest" href="manifest.php">
    <title>Clientes · <?= e(nombreNegocio()) ?></title>
    <link rel="stylesheet" href="assets/css/estilos.css">
</head>
<body>
<div class="container">

    <?= mostrar_flash() ?>

    <h1 style="margin-top:1rem">👥 Clientes registrados</h1>
    <p class="texto-suave">
        <a href="admin.php">← Volver al panel de citas</a> ·
        <a href="ajustes.php">🏪 Negocio</a>
    </p>
    <hr>

    <!-- Buscador: envía por GET, SIN csrf (no cambia nada del sistema) -->
    <form class="editor" method="get">
        <strong>🔍 Buscar cliente</strong>
        <div class="campo">
            <input type="text" name="q" value="<?= e($q) ?>"
                   placeholder="Nombre, teléfono o email" maxlength="100">
        </div>
        <button class="btn" type="submit">Buscar</button>
        <?php if ($q !== ''): ?>
            <a class="texto-suave" href="clientes.php">✖ Quitar filtro (<?= e($q) ?>)</a>
        <?php endif; ?>
    </form>

    <p class="texto-suave">
        <?= count($clientes) ?> cliente(s)<?= $q !== '' ? ' que coinciden con la búsqueda' : '' ?>
    </p>

    <?php foreach ($clientes as $c): ?>
        <div class="tarjeta fila-cliente">
            <div class="fila" style="align-items:center">
                <strong><?= e($c['nombre']) ?></strong>
                <span class="pill <?= $c['activo'] ? 'pill-ok' : 'pill-mal' ?>">
                    <?= $c['activo'] ? 'activo' : 'inactivo' ?>
                </span>
            </div>
            <p class="texto-suave" style="margin:.15rem 0 0">
                📱 <a href="tel:<?= e($c['telefono']) ?>"><?= e($c['telefono']) ?></a>
                <?php if ($c['email']): ?> · ✉️ <?= e($c['email']) ?><?php endif; ?>
            </p>
            <p class="texto-suave" style="margin:.15rem 0 0">
                🗓 Registrado el <?= e(date('d/m/Y', strtotime($c['creado_en']))) ?>
            </p>
            <hr style="margin:.5rem 0">
            <p style="margin:0">
                🎫 <strong><?= (int) $c['total_citas'] ?></strong> cita(s) en total ·
                💈 <strong><?= (int) $c['citas_atendidas'] ?></strong> atendidas
            </p>
            <p class="texto-suave" style="margin:.15rem 0 0">
                <?= $c['ultima_cita']
                    ? '⏳ Última cita: ' . e(fechaBonita($c['ultima_cita']))
                    : '😴 Aún no tiene citas' ?>
            </p>
        </div>
    <?php endforeach; ?>

    <?php if (!$clientes): ?>
        <div class="alerta alerta-mal">🤷 No hay clientes que coincidan.</div>
    <?php endif; ?>

    <p class="texto-suave centro" style="margin:1rem 0 2rem">
        🔒 Estos datos son privados del negocio y solo el administrador ve esta página.
    </p>

    <div style="height:5.5rem"></div>
</div>

<?php barraInferior(''); ?>
</body>
</html>
