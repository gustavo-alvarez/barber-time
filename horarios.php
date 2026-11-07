<?php
/**
 * HORARIOS (solo admin)
 * ----------------------
 * Dos bloques:
 *  1) Horario del negocio: por día, abierto / cerrado + horas.
 *  2) Horario de cada barbero: por día "igual al negocio" (default),
 *     propio (con horas) o descanso propio.
 * Toda la app (portada, agendamiento, disponibilidad de horas)
 * se lettera solo según estas tablas.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/citas.php';

requiere_rol('admin');
$pdo = db();

/* ---------------- Guardado (POST) ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validar_csrf();

    $accion = $_POST['accion'] ?? '';

    // Valida "HH:MM" y que cierre después de abrir
    $validaVentana = function (string $abre, string $cierra, string $diaNombre, ?string &$err): bool {
        $fmt = '/^([01]\d|2[0-3]):[0-5]\d(:\d{2})?$/';
        if (!preg_match($fmt, $abre) || !preg_match($fmt, $cierra)) {
            $err = "Hora inválida el {$diaNombre}.";
            return false;
        }
        if (substr($cierra, 0, 5) <= substr($abre, 0, 5)) {
            $err = "El cierre debe ser después de la apertura el {$diaNombre}.";
            return false;
        }
        return true;
    };

    if ($accion === 'guardar_negocio') {
        $errores = [];
        for ($d = 1; $d <= 7; $d++) {
            if (!isset($_POST['negocio'][$d]['abierto'])) continue; // día cerrado
            $err = null;
            $validaVentana($_POST['negocio'][$d]['abre'] ?? '', $_POST['negocio'][$d]['cierra'] ?? '', diaNombre($d), $err);
            if ($err) $errores[] = $err;
        }

        if ($errores) {
            foreach ($errores as $e) { flash($e, 'mal'); }
        } else {
            $del = $pdo->prepare('DELETE FROM horarios WHERE barbero_id IS NULL AND dia = ?');
            $ins = $pdo->prepare('INSERT INTO horarios (barbero_id, dia, abre, cierra) VALUES (NULL, ?, ?, ?)');
            for ($d = 1; $d <= 7; $d++) {
                if (isset($_POST['negocio'][$d]['abierto'])) {
                    $del->execute([$d]);
                    $ins->execute([$d, $_POST['negocio'][$d]['abre'] . ':00', $_POST['negocio'][$d]['cierra'] . ':00']);
                } else {
                    $del->execute([$d]); // ausencia de fila = cerrado
                }
            }
            flash('✅ Horario del negocio guardado.');
        }
        header('Location: horarios.php');
        exit;
    }

    if ($accion === 'guardar_barbero') {
        $id = (int) ($_POST['bar_id'] ?? 0);
        $chk = $pdo->prepare('SELECT COUNT(*) FROM barberos WHERE id = ?');
        $chk->execute([$id]);

        if (!$chk->fetchColumn()) {
            flash('Barbero inválido. 🤔', 'mal');
        } else {
            $del = $pdo->prepare('DELETE FROM horarios WHERE barbero_id = ? AND dia = ?');
            $ins = $pdo->prepare('INSERT INTO horarios (barbero_id, dia, abre, cierra) VALUES (?, ?, ?, ?)');
            $upd = $pdo->prepare('UPDATE horarios SET abre = NULL, cierra = NULL WHERE barbero_id = ? AND dia = ?');

            for ($d = 1; $d <= 7; $d++) {
                $igual   = isset($_POST['bar'][$d]['igual']);
                $abierto = isset($_POST['bar'][$d]['abierto']);

                if ($igual) {
                    $del->execute([$id, $d]); // hereda del negocio
                } elseif ($abierto) {
                    // Ventana propia (validamos de nuevo aquí)
                    $err = null;
                    $validaVentana($_POST['bar'][$d]['abre'] ?? '', $_POST['bar'][$d]['cierra'] ?? '', diaNombre($d), $err);
                    if ($err) { flash($err, 'mal'); continue; }
                    $del->execute([$id, $d]);
                    $ins->execute([$id, $d, $_POST['bar'][$d]['abre'] . ':00', $_POST['bar'][$d]['cierra'] . ':00']);
                } else {
                    // Descanso propio explícito (aunque el negocio abra ese día)
                    $del->execute([$id, $d]);
                    $ins->execute([$id, $d, null, null]);
                }
            }
            flash('✅ Horario de ' . $id . ' guardado.');
        }
        header('Location: horarios.php');
        exit;
    }

    flash('Acción inválida. 🤨', 'mal');
    header('Location: horarios.php');
    exit;
}

/* ---------------- Vista (GET) ---------------- */
$negocio = array_fill(1, 7, null);
foreach ($pdo->query('SELECT dia, abre, cierra FROM horarios WHERE barbero_id IS NULL')->fetchAll() as $f) {
    $negocio[(int) $f['dia']] = $f;
}
$barberos = $pdo->query('SELECT * FROM barberos WHERE activo = 1 ORDER BY id')->fetchAll();
$rowsBarbero = [];
foreach ($pdo->query('SELECT barbero_id, dia, abre, cierra FROM horarios WHERE barbero_id IS NOT NULL')->fetchAll() as $f) {
    $rowsBarbero[(int) $f['barbero_id']][$f['dia']] = $f;
}

/** Valores a mostrar en los inputs del barbero: los suyos o los del negocio */
function valeursInput(?array $fila, ?array $negDia, int $d): array
{
    if ($fila && $fila['abre'] !== null) {
        return [substr($fila['abre'], 0, 5), substr($fila['cierra'], 0, 5)];
    }
    if ($negDia && $negDia['abre'] !== null) {
        return [substr($negDia['abre'], 0, 5), substr($negDia['cierra'], 0, 5)];
    }
    return ['09:00', '19:00']; // sugerencia por defecto
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
    <title>Horarios · <?= e(nombreNegocio()) ?></title>
    <link rel="stylesheet" href="assets/css/estilos.css">
</head>
<body>
<div class="container">

    <?= mostrar_flash() ?>

    <h1 style="margin-top:1rem">⏰ Horarios</h1>
    <p class="texto-suave">
        <a href="admin.php">← Volver al panel de citas</a> ·
        <a href="editar_barberos.php">💈 Barberos</a> ·
        <a href="editar_servicios.php">✂️ Servicios</a> ·
        <a href="ajustes.php">🏪 Negocio</a>
    </p>
    <hr>

    <!-- ============ HORARIO DEL NEGOCIO ============ -->
    <form method="post" class="editor">
        <?= campo_csrf() ?>
        <input type="hidden" name="accion" value="guardar_negocio">
        <strong>🏪 Horario del negocio</strong>
        <?php foreach ($negocio as $d => $fila): $abierto = $fila && $fila['abre'] !== null; ?>
            <div class="fila-horario">
                <span class="dia"><?= e(diaNombre($d, true)) ?></span>
                <label class="chk-mini"><input type="checkbox" name="negocio[<?= $d ?>][abierto]"
                    <?= $abierto ? 'checked' : '' ?>>Abierto</label>
            </div>
            <div class="fila-horario horas">
                <input type="time" name="negocio[<?= $d ?>][abre]"
                       value="<?= e($abierto ? substr($fila['abre'], 0, 5) : '09:00') ?>" step="60">
                <input type="time" name="negocio[<?= $d ?>][cierra]"
                       value="<?= e($abierto ? substr($fila['cierra'], 0, 5) : '19:00') ?>" step="60">
            </div>
        <?php endforeach; ?>
        <button class="btn btn-sec" type="submit">Guardar horario del negocio</button>
    </form>

    <!-- ============ HORARIO DE CADA BARBERO ============ -->
    <h2 class="seccion">💈 Barberos</h2>
    <p class="texto-suave">Para cada barbero: «Hereda» usa el horario del negocio ese día; si lo marcas, sus horas propias se ignoran.</p>

    <?php foreach ($barberos as $b): $filas = $rowsBarbero[(int) $b['id']] ?? []; ?>
        <details class="tarjeta editor-detalles <?= empty($filas) ? '' : 'con-own' ?>" open>
            <summary>✂️ <?= e($b['nombre']) ?> <small class="texto-suave">(<?= empty($filas) ? 'igual al negocio' : 'horario propio' ?>)</small></summary>
            <form method="post">
                <?= campo_csrf() ?>
                <input type="hidden" name="accion" value="guardar_barbero">
                <input type="hidden" name="bar_id" value="<?= (int) $b['id'] ?>">
                <?php foreach (range(1, 7) as $d): $fila = $filas[$d] ?? null; ?>
                    <?php [$mpre, $mpci] = valeursInput($fila, $negocio[$d], $d); ?>
                    <div class="fila-horario">
                        <span class="dia"><?= e(diaNombre($d, true)) ?></span>
                        <label class="chk-mini"><input type="checkbox" name="bar[<?= $d ?>][abierto]"
                            <?= $fila && $fila['abre'] !== null ? 'checked' : '' ?>>Abierto</label>
                        <label class="chk-mini"><input type="checkbox" name="bar[<?= $d ?>][igual]"
                            <?= !$fila ? 'checked' : '' ?>>Hereda</label>
                    </div>
                    <div class="fila-horario horas">
                        <input type="time" name="bar[<?= $d ?>][abre]" value="<?= e($mpre) ?>" step="60">
                        <input type="time" name="bar[<?= $d ?>][cierra]" value="<?= e($mpci) ?>" step="60">
                    </div>
                <?php endforeach; ?>
                <button class="btn btn-sec" type="submit">Guardar horario de <?= e($b['nombre']) ?></button>
            </form>
        </details>
    <?php endforeach; ?>

    <p class="texto-suave centro" style="margin:1rem 0 2rem">
        🔀 Los cambios aplican de inmediato: la portada y el asistente de agendar
        ofrecerán horas solo dentro del nuevo horario.
    </p>

    <div style="height:5.5rem"></div>
</div>

<?php barraInferior(''); ?>
</body>
</html>
