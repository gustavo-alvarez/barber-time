<?php
/**
 * LÓGICA DE CITAS Y HORARIOS
 * ---------------------------
 * Reglas del negocio de la barbería. Cambiar horarios, días
 * cerrados o el paso entre turnos se hace SOLO en este archivo.
 */

require_once __DIR__ . '/../config/database.php'; // fija la zona horaria del negocio

define('PASO_CITAS',    30);      // minutos entre cada "turno" ofertado
define('MAX_DIAS_ADELANTE', 30);  // antelación máxima para agendar
define('LIMITE_CANCELAR_HORAS', 2); // cancelar solo hasta X horas antes (justo para el barbero)

/**
 * Valida una fecha(Y-m-d): formato, no pasada, no lejana.
 * Los DÍAS CERRADOS ya no van aquí: son configurables (tabla `horarios`)
 * y se revisan con horarioDia(). Al fallar deja el motivo en $error.
 */
function fechaValida(string $fecha, ?string &$error = null): bool
{
    $dt = DateTime::createFromFormat('Y-m-d|', $fecha);
    if (!$dt || $dt->format('Y-m-d') !== $fecha) {
        $error = 'Fecha inválida.';
        return false;
    }
    $hoy = new DateTime('today');
    if ($dt < $hoy) {
        $error = 'No puedes agendar en fechas pasadas.';
        return false;
    }
    $tope = (clone $hoy)->modify('+' . MAX_DIAS_ADELANTE . ' days');
    if ($dt > $tope) {
        $error = 'Solo puedes agendar con ' . MAX_DIAS_ADELANTE . ' días de anticipación.';
        return false;
    }
    return true;
}

/**
 * Horario que regirá un barbero (o el negocio si $barberoId = null) en una fecha.
 * Devuelve ['abre' => '07:00', 'cierra' => '18:00'] o null si ese día descansa.
 * Prioridad: horario propio del barbero → horario del negocio → descanso.
 */
function horarioDia(PDO $pdo, ?int $barberoId, string $fecha): ?array
{
    $dia = (int) (new DateTime($fecha))->format('N'); // 1 = lunes ... 7 = domingo

    if ($barberoId !== null) {
        $stmt = $pdo->prepare('SELECT abre, cierra FROM horarios WHERE barbero_id = ? AND dia = ?');
        $stmt->execute([$barberoId, $dia]);
        $fila = $stmt->fetch();
        if ($fila) {
            return $fila['abre'] === null
                ? null // descanso propio
                : ['abre' => $fila['abre'], 'cierra' => $fila['cierra']];
        }
        // Sin fila: hereda el horario del negocio ↓
    }

    $stmt = $pdo->prepare('SELECT abre, cierra FROM horarios WHERE barbero_id IS NULL AND dia = ?');
    $stmt->execute([$dia]);
    $fila = $stmt->fetch();
    if (!$fila || $fila['abre'] === null) {
        return null; // el negocio cierra ese día
    }
    return ['abre' => $fila['abre'], 'cierra' => $fila['cierra']];
}

/** Nombres de los días de la semana, 1 = Lunes */
function diaNombre(int $dia, bool $corto = false): string
{
    $largos  = [1 => 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
    $cortos  = [1 => 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
    return $corto ? $cortos[$dia] : $largos[$dia];
}

/** Texto compacto para la portada: "Lun–Vie 09:00–19:00 · Sáb 10:00–16:00 · Dom cerrado" */
function horarioResumen(PDO $pdo): string
{
    $ventanas = array_fill(1, 7, null);
    foreach ($pdo->query('SELECT dia, abre, cierra FROM horarios WHERE barbero_id IS NULL')->fetchAll() as $f) {
        $ventanas[(int) $f['dia']] = $f['abre'] !== null ? [$f['abre'], $f['cierra']] : null;
    }

    $partes = [];
    $d = 1;
    while ($d <= 7) {
        $actual = $ventanas[$d];
        $desde  = $d;
        while ($d <= 7 && $ventanas[$d] === $actual) {
            $d++;
        }
        $etiqueta = ($desde === $d - 1) ? diaNombre($desde, true) : diaNombre($desde, true) . '–' . diaNombre($d - 1, true);
        $partes[] = $actual
            ? $etiqueta . ' ' . substr($actual[0], 0, 5) . '–' . substr($actual[1], 0, 5)
            : $etiqueta . ' cerrado';
    }
    return implode(' · ', $partes);
}

/**
 * Devuelve las horas de inicio disponibles para un barbero en una fecha,
 * considerando la duración real del servicio elegido (evita solapes).
 */
function horasDisponibles(PDO $pdo, int $barberoId, string $fecha, int $duracionMin): array
{
    // Citas ya tomadas de ese barbero ese día (con la duración de su servicio)
    $stmt = $pdo->prepare(
        "SELECT c.hora, s.duracion_min
         FROM citas c JOIN servicios s ON s.id = c.servicio_id
         WHERE c.barbero_id = ? AND c.fecha = ? AND c.estado NOT IN ('cancelada', 'ausente')"
    );
    $stmt->execute([$barberoId, $fecha]);

    $ocupados = [];
    foreach ($stmt->fetchAll() as $r) {
        $ini = new DateTime($fecha . ' ' . $r['hora']);
        $fin = (clone $ini)->modify('+' . (int) $r['duracion_min'] . ' minutes');
        $ocupados[] = [$ini, $fin];
    }

    // Ventana del día según el horario del barbero o del negocio (configurable)
    $ventana = horarioDia($pdo, $barberoId, $fecha);
    if ($ventana === null) {
        return []; // ese día no se atiende
    }

    $inicio = new DateTime($fecha . ' ' . $ventana['abre']);
    $cierre = new DateTime($fecha . ' ' . $ventana['cierra']);
    $limiteEnt = new DateTime();                // ahora
    $limiteEnt->modify('+10 minutes');          // margen: no ofrecer horas que ya pasaron
    $esHoy     = ($fecha === (new DateTime())->format('Y-m-d'));

    $libres = [];
    $t = clone $inicio;
    while ($t <= $cierre) {
        $fin = (clone $t)->modify('+' . $duracionMin . ' minutes');
        if ($fin > $cierre) {
            break; // no cabe antes del cierre
        }

        // ¿Se cruza con una cita existente? (solape de intervalos)
        $libre = true;
        foreach ($ocupados as [$oIni, $oFin]) {
            if ($t < $oFin && $fin > $oIni) { // se traslapan
                $libre = false;
                break;
            }
        }

        // ¿Es una hora que ya pasó?
        if ($esHoy && $t < $limiteEnt) {
            $libre = false;
        }

        if ($libre) {
            $libres[] = $t->format('H:i');
        }
        $t->modify('+' . PASO_CITAS . ' minutes');
    }
    return $libres;
}

/**
 * ¿El turno (fecha + hora + duración) cuadra para un barbero?
 * - La fecha debe ser válida (no pasada, no lejana, formato correcto).
 * - Debe vivir DENTRO de la ventana de ese día (horario propio o del negocio).
 * - No debe solapar con otras citas del barbero (con la duración de su servicio).
 * $excepto: id de cita a IGNORAR (cuando re-agendas ese mismo turno).
 * Devuelve true, o el motivo por el que NO cuadra.
 */
function turnoDisponible(PDO $pdo, int $barberoId, string $fecha, string $hora,
                         int $duracionMin, ?int $excepto = null): bool|string
{
    $error = null;
    if (!fechaValida($fecha, $error)) {
        return $error ?? 'Fecha inválida.';
    }
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) {
        return 'Hora inválida.';
    }

    $ventana = horarioDia($pdo, $barberoId, $fecha);
    if ($ventana === null) {
        return 'Ese día el barbero no atiende (descanso o negocio cerrado).';
    }

    $inicio = new DateTime($fecha . ' ' . $hora);
    $fin    = (clone $inicio)->modify('+' . $duracionMin . ' minutes');
    $apertura = new DateTime($fecha . ' ' . $ventana['abre']);
    $cierre   = new DateTime($fecha . ' ' . $ventana['cierra']);
    if ($inicio < $apertura || $fin > $cierre) {
        return 'El turno se sale del horario de ese día ('
             . substr($ventana['abre'], 0, 5) . '–' . substr($ventana['cierra'], 0, 5) . ').';
    }

    // Citas que ya tiene ese barbero ese día (con la duración de SU servicio)
    $stmt = $pdo->prepare('SELECT c.id, c.hora, s.duracion_min
                           FROM citas c JOIN servicios s ON s.id = c.servicio_id
                           WHERE c.barbero_id = ? AND c.fecha = ? AND c.estado NOT IN ("cancelada", "ausente")');
    $stmt->execute([$barberoId, $fecha]);
    foreach ($stmt->fetchAll() as $r) {
        if ($excepto && (int) $r['id'] === $excepto) {
            continue; // el turno mismo no se cuenta como conflicto
        }
        $oIni = new DateTime($fecha . ' ' . $r['hora']);
        $oFin = (clone $oIni)->modify('+' . (int) $r['duracion_min'] . ' minutes');
        if ($inicio < $oFin && $fin > $oIni) {
            return 'Se cruza con otra cita (' . substr((string) $r['hora'], 0, 5) . ').';
        }
    }
    return true;
}

/** ¿Cuántos minutos faltan para el inicio de una cita? (negativo = ya empezó/pasó) */
function minutosRestantes(string $fecha, string $hora): int
{
    $cita  = new DateTime($fecha . ' ' . $hora);
    $ahora = new DateTime();
    return (int) round(($cita->getTimestamp() - $ahora->getTimestamp()) / 60);
}

function fechaBonita(string $fecha): string
{
    $dt = DateTime::createFromFormat('Y-m-d|', $fecha);
    if (!$dt) {
        return e($fecha);
    }
    $dias = [
        'Mon' => 'Lunes', 'Tue' => 'Martes', 'Wed' => 'Miércoles', 'Thu' => 'Jueves',
        'Fri' => 'Viernes', 'Sat' => 'Sábado', 'Sun' => 'Domingo',
    ];
    $meses = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
              'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    return $dias[$dt->format('D')] . ' ' . (int) $dt->format('j') . ' de ' . $meses[(int) $dt->format('n')];
}

/** Estado de una cita como etiqueta de color: ⏳ ✅ ✔ ❌ */
function pillEstado(string $estado): string
{
    $mapa = [
        'pendiente'  => ['⏳ Pendiente',  'pill-pendiente'],
        'confirmada' => ['✅ Confirmada', 'pill-confirmada'],
        'completada' => ['✔ Completada',  'pill-completada'],
        'ausente'    => ['🚫 Ausente',   'pill-ausente'],
        'cancelada'  => ['❌ Cancelada',  'pill-cancelada'],
    ];
    [$texto, $clase] = $mapa[$estado] ?? [$estado, 'pill-pendiente'];
    return '<span class="pill ' . $clase . '">' . e($texto) . '</span>';
}

/* -------------------------------------------------
 * CICLO DE VIDA DE LA CITA (marcar / deshacer)
 * -------------------------------------------------
 * Centraliza las reglas para que barbero y admin
 * usen EXACTAMENTE las mismas transiciones:
 *   confirmar:  pendiente → confirmada
 *   completar:  pendiente|confirmada → completada
 *   ausente:    pendiente|confirmada → ausente (cliente no llegó; la hora libera)
 *   cancelar:   pendiente|confirmada → cancelada
 *   deshacer:   completada|ausente → confirmada · cancelada → pendiente
 * Devuelve [ok(bool), mensaje para flash].
 */
function transicionCita(PDO $pdo, int $citaId, string $accion, ?int $dueñoBarId): array
{
    $st = $pdo->prepare('SELECT c.*, s.duracion_min
                         FROM citas c JOIN servicios s ON s.id = c.servicio_id WHERE c.id = ?');
    $st->execute([$citaId]);
    $cita = $st->fetch();
    if (!$cita) {
        return [false, 'La cita no existe. 🤷'];
    }
    if ($dueñoBarId !== null && (int) $cita['barbero_id'] !== $dueñoBarId) {
        return [false, 'Ese turno no es tuyo. 🤔'];
    }

    $actual = $cita['estado'];
    $activos = ['pendiente', 'confirmada'];
    $finales = ['completada', 'ausente', 'cancelada'];

    switch ($accion) {
        case 'confirmar':
            if ($actual !== 'pendiente') {
                return [false, 'Solo las citas pendientes se pueden confirmar. ✋'];
            }
            $nuevo = 'confirmada';
            $msg   = '✅ Turno confirmado.';
            break;

        case 'completar':
            if (!in_array($actual, $activos, true)) {
                return [false, 'Solo una cita activa se puede marcar como completada. ✋'];
            }
            $nuevo = 'completada';
            $msg   = '🏁 Turno completado. 💰';
            break;

        case 'ausente':
            if (!in_array($actual, $activos, true)) {
                return [false, 'Solo una cita activa se puede marcar como ausente. ✋'];
            }
            $nuevo = 'ausente';
            $msg   = '🚫 Cliente ausente. La hora queda libre para otra cita.';
            break;

        case 'cancelar':
            if (!in_array($actual, $activos, true)) {
                return [false, 'Ese turno ya está en ' . $actual . ' y no se puede cancelar. ✋'];
            }
            $nuevo = 'cancelada';
            $msg   = '🗑️ Turno cancelado. Queda en el historial.';
            break;

        case 'deshacer':
            if (!in_array($actual, $finales, true)) {
                return [false, 'Ese turno está activo: no hay nada que deshacer. ✋'];
            }
            $nuevo = $actual === 'cancelada' ? 'pendiente' : 'confirmada';
            $msg   = $actual === 'cancelada'
                ? '↩️ Cancelación deshecha: la cita volvió a pendiente.'
                : '↩️ Deshecho: la cita volvió a confirmada.';
            // Si la hora ya la tomó otra cita no se puede volver a activar.
            // Los días PASADOS no se agendan: ahí siempre se puede deshacer.
            if ($cita['fecha'] >= date('Y-m-d')) {
                $queda = turnoDisponible($pdo, (int) $cita['barbero_id'], $cita['fecha'],
                    substr((string) $cita['hora'], 0, 5), (int) $cita['duracion_min'], $citaId);
                if ($queda !== true) {
                    return [false, 'No se puede deshacer: ' . $queda];
                }
            }
            break;

        default:
            return [false, 'Acción inválida. 🤨'];
    }

    $upd = $pdo->prepare('UPDATE citas SET estado = ? WHERE id = ?');
    $upd->execute([$nuevo, $citaId]);
    return [true, $msg];
}

/* -------------------------------------------------
 * AVISO POR WHATSAPP (link wa.me prellenado)
 * -------------------------------------------------
 * 1) telWhatsapp($tel): normaliza el número para wa.me.
 *    - Le quita espacios, guiones y todo lo que no sea dígito.
 *    - 10 dígitos (formato local colombiano 3xx...) le agrega 57.
 *    - Si queda con longitud razonable (10 a 13) lo devuelve;
 *      si no, devuelve NULL (no se puede avisar).
 * 2) mensajeWhatsapp($tipo, $cita): el texto listo para enviar.
 *    WhatsApp usa *palabra* para negrita.
 * 3) linkWhatsapp($tel, $texto): el link https://wa.me/...?text=...
 *    listo para un <a href>. NULL si el número no sirve.
 */
function telWhatsapp(?string $tel): ?string
{
    $d = preg_replace('/\D/', '', (string) $tel);
    if ($d === '') {
        return null;
    }
    if (strlen($d) === 10) {
        $d = '57' . $d; // Colombia: código de país automático
    }
    return (strlen($d) >= 10 && strlen($d) <= 13) ? $d : null;
}

function mensajeWhatsapp(string $tipo, array $c): string
{
    $nombre   = $c['usuario_id'] === null ? ($c['visitante'] ?: 'cliente') : ($c['cliente'] ?? 'cliente');
    $fechaTxt = fechaBonita($c['fecha']);
    $horaTxt  = substr((string) $c['hora'], 0, 5);
    $negocio  = nombreNegocio();

    return match ($tipo) {
        'cancelado' => "Hola *$nombre* 👋 Tu turno en *$negocio* 💈 quedó *cancelado*:\n\n"
                     . "✂️ " . ($c['servicio'] ?? '') . " · 📅 $fechaTxt · ⏰ $horaTxt\n\n"
                     . "Te esperamos otra vez cuando quieras. 🙏",
        'movido' => "Hola *$nombre* 👋 *Actualizamos tu turno* de *$negocio* 💈:\n\n"
                  . "✂️ " . ($c['servicio'] ?? '') . " · 🥷 " . ($c['barbero'] ?? '') . "\n"
                  . "✅ *Ahora: $fechaTxt · $horaTxt*\n\n"
                  . "¿Te cuadra? Responde por aquí si algo. 🙏",
        default => // 'nuevo'
                  "Hola *$nombre* 👋 ¡Quedaste agendado en *$negocio*! 💈\n\n"
                . "✂️ *" . ($c['servicio'] ?? '') . "*\n"
                . "🥷 Con: " . ($c['barbero'] ?? '') . "\n"
                . "📅 $fechaTxt · ⏰ *$horaTxt*\n\n"
                . "Si necesitas mover o cancelar el turno, respóndenos por aquí. 😉",
    };
}

function linkWhatsapp(?string $tel, string $texto): ?string
{
    $num = telWhatsapp($tel);
    if ($num === null) {
        return null;
    }
    return 'https://wa.me/' . $num . '?text=' . rawurlencode($texto);
}

/* -------------------------------------------------
 * RECORDATORIO IN-APP (Piso 1 de notificaciones)
 * -------------------------------------------------
 * citaPorRecordar(): la cita VIVA del cliente cuya
 * hora está cerca (en 60 min o menos) o ya arrancó
 * (en curso). De aquí nace la barra de recordatorio.
 * Solo depende de la BD: puede llamarse en cualquier página.
 */
function citaPorRecordar(PDO $pdo, array $usuario): ?array
{
    if (($usuario['rol'] ?? '') !== 'cliente') {
        return null; // solo interesa a los clientes
    }

    $st = $pdo->prepare(
        "SELECT c.id, c.fecha, c.hora, c.estado,
                s.nombre AS servicio, s.duracion_min,
                b.nombre AS barbero
         FROM citas c
         JOIN servicios s ON s.id = c.servicio_id
         JOIN barberos  b ON b.id = c.barbero_id
         WHERE c.usuario_id = ?
           AND c.fecha = CURDATE()
           AND c.estado IN ('pendiente', 'confirmada')
         ORDER BY c.hora"
    );
    $st->execute([(int) $usuario['id']]);

    $ahora = new DateTime('now');

    foreach ($st->fetchAll() as $c) {
        $ini = DateTime::createFromFormat('Y-m-d', $c['fecha']);
        $ini->setTime(...array_map('intval', explode(':', substr($c['hora'], 0, 8))));
        $fin = (clone $ini)->modify('+' . (int) $c['duracion_min'] . ' minutes');

        if ($fin < $ahora) {
            continue; // cita de hoy que ya terminó
        }
        if ($ini > $ahora) {
            if ($ini > (clone $ahora)->modify('+60 minutes')) {
                break; // la más próxima quedó a más de 1 hora: nada que avisar
            }
            $c['aviso']  = 'proxima';
            $c['faltan'] = (int) round(($ini->getTimestamp() - $ahora->getTimestamp()) / 60);
            return $c;
        }
        // ini <= ahora < fin: en curso
        $c['aviso'] = 'ahora';
        return $c;
    }
    return null;
}

/** Barra dorada de recordatorio junto a "Bienvenido" (panel.php) */
function avisoCita(?array $c): void
{
    if (!$c) {
        return; // nada cerca: ni rastro de la barra
    }
    $hora = e(substr((string) $c['hora'], 0, 5));
    $tu = e($c['servicio']) . ' con ' . e($c['barbero']);
    if ($c['aviso'] === 'ahora') {
        $titulo  = '⏰ ¡Tu cita es AHORA MISMO! Ven corriendo 🏃';
        $detalle = $tu . ' · ' . $hora;
    } else {
        $titulo  = '⏰ Tu cita: ' . $tu;
        $detalle = '🗓 Hoy · ⏰ ' . $hora . ' · empieza en ' . (int) $c['faltan'] . ' min';
    }
    ?>
    <div class="aviso-cita">
        <strong><?= $titulo ?></strong>
        <span class="mini"><?= e($detalle) ?></span>
    </div>
    <?php
}
