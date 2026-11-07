<?php
/**
 * ESQUEMA DE LA BASE DE DATOS
 * ----------------------------
 * Aquí se define, en un solo lugar, TODA la estructura de tablas
 * y los datos iniciales del proyecto. instalar.php solo lo ejecuta.
 *
 * Funciona con "IF NOT EXISTS" y verificaciones previas:
 * se puede ejecutar muchas veces sin perder datos.
 */

/**
 * Crea las tablas si no existen. Devuelve mensajes para mostrarle al usuario.
 */
function crear_tablas(PDO $pdo): array
{
    $mensajes = [];

    // ---- usuarios: quien entra al sistema ----
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS usuarios (
            id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            nombre    VARCHAR(100) NOT NULL,
            telefono  VARCHAR(20)  NOT NULL,
            email     VARCHAR(120) DEFAULT NULL,
            pass_hash VARCHAR(255) NOT NULL,
            rol       ENUM('cliente','admin','barbero') NOT NULL DEFAULT 'cliente',
            activo    TINYINT(1)  NOT NULL DEFAULT 1,
            creado_en TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
            barbero_id INT UNSIGNED NULL,   -- si rol = 'barberos': su ficha (sin FK para no reordenar creación)
            UNIQUE KEY uq_telefono (telefono),
            UNIQUE KEY uq_email (email),
            UNIQUE KEY uq_barbero (barbero_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $mensajes[] = '✅ Tabla `usuarios` lista';

    // ---- barberos: el equipo de la barbería ----
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS barberos (
            id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            nombre         VARCHAR(100) NOT NULL,
            especialidades VARCHAR(255) DEFAULT NULL,
            biografia      VARCHAR(255) DEFAULT NULL,
            emoji          VARCHAR(8)   NOT NULL DEFAULT '💈',  -- avatar pequeño hasta tener fotos
            activo         TINYINT(1)  NOT NULL DEFAULT 1,
            creado_en      TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $mensajes[] = '✅ Tabla `barberos` lista';

    // ---- servicios: lo que se puede agendar ----
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS servicios (
            id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            nombre       VARCHAR(100) NOT NULL,
            descripcion  VARCHAR(255) DEFAULT NULL,
            duracion_min INT          NOT NULL DEFAULT 30,
            precio       DECIMAL(9,2) NOT NULL DEFAULT 0,
            activo       TINYINT(1)   NOT NULL DEFAULT 1,
            creado_en    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $mensajes[] = '✅ Tabla `servicios` lista';

    // ---- horarios: por día la ventana de atención (barbero_id NULL = negocio) ----
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS horarios (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            barbero_id INT UNSIGNED NULL,
            dia        TINYINT UNSIGNED NOT NULL,   -- 1 = Lunes ... 7 = Domingo
            abre       TIME NULL,                   -- NULL = descanso / cerrado
            cierra     TIME NULL,
            UNIQUE KEY uq_horario (barbero_id, dia),
            FOREIGN KEY fk_horarios_barbero (barbero_id)
                REFERENCES barberos(id) ON DELETE CASCADE,
            CONSTRAINT chk_dia_valido CHECK (dia BETWEEN 1 AND 7)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $mensajes[] = '✅ Tabla `horarios` lista';

    // ---- citas: la reserva hecha por un cliente ----
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS citas (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            usuario_id  INT UNSIGNED NULL,      -- NULL = turno de 'cliente de la calle' (gestionado por barbero/admin)
            servicio_id INT UNSIGNED NOT NULL,
            barbero_id  INT UNSIGNED NOT NULL,
            fecha     DATE NOT NULL,
            hora      TIME NOT NULL,
            estado    ENUM('pendiente','confirmada','completada','ausente','cancelada') NOT NULL DEFAULT 'pendiente',
            notas     VARCHAR(255) DEFAULT NULL,
            visitante     VARCHAR(100) NULL,  -- nombre del cliente de la calle (si no tiene cuenta)
            tel_visitante VARCHAR(20)  NULL,  -- teléfono del visitante (opcional)
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (usuario_id)  REFERENCES usuarios(id),
            FOREIGN KEY (servicio_id) REFERENCES servicios(id),
            FOREIGN KEY (barbero_id)  REFERENCES barberos(id),
            KEY idx_citas_barbero_fecha (barbero_id, fecha)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $mensajes[] = '✅ Tabla `citas` lista';

    // ---- migración idempotente: estado 'ausente' en el ENUM de citas ----
    $col = $pdo->query("SHOW COLUMNS FROM citas LIKE 'estado'")->fetch();
    if ($col && str_contains((string) ($col['Type'] ?? ''), "enum(")
        && !str_contains((string) $col['Type'], "'ausente'")) {
        $pdo->exec("
            ALTER TABLE citas
                MODIFY estado ENUM('pendiente','confirmada','completada','ausente','cancelada')
                NOT NULL DEFAULT 'pendiente'
        ");
        $mensajes[] = '✅ Migración: citas.estado ahora acepta "ausente"';
    }

    // ---- opciones: ajustes del negocio (nombre, logo, contacto) ----
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS opciones (
            clave VARCHAR(50) PRIMARY KEY,
            valor TEXT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $mensajes[] = '✅ Tabla `opciones` lista';

    /* =========================================================
     * MIGRACIONES para bases de datos ya creadas (idempotentes)
     * MariaDB 10.1 no tiene ADD COLUMN IF NOT EXISTS, así que
     * preguntamos the information_schema antes de cada ALTER.
     * ========================================================= */
    $hayColumna = function (string $tabla, string $columna) use ($pdo): bool {
        $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $q->execute([$tabla, $columna]);
        return (bool) $q->fetchColumn();
    };

    // a) rol 'barberos' en el ENUM (base viejas solo tienen cliente/admin)
    $q = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'rol'");
    $tipRol = (string) ($q->fetch()['Type'] ?? '');
    if (!str_contains($tipRol, 'barbero')) {
        $pdo->exec("ALTER TABLE usuarios MODIFY rol ENUM('cliente','admin','barbero') NOT NULL DEFAULT 'cliente'");
        $mensajes[] = '✅ Rol `barbero` agregado al ENUM de usuarios';
    }

    // b) barbero_id: la cuenta del barbero apunta a su ficha
    if (!$hayColumna('usuarios', 'barbero_id')) {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN barbero_id INT UNSIGNED NULL");
        $mensajes[] = '✅ Columna `usuarios.barbero_id` agregada';
    }
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND INDEX_NAME = 'uq_barbero'");
    $q->execute();
    if (!$q->fetchColumn()) {
        $pdo->exec("ALTER TABLE usuarios ADD UNIQUE KEY uq_barbero (barbero_id)");
        $mensajes[] = '✅ Índice `uq_barbero` agregado (una cuenta por barbero)';
    }

    // c) barberos.foto: foto real del barbero (subida segura)
    if (!$hayColumna('barberos', 'foto')) {
        $pdo->exec("ALTER TABLE barberos ADD COLUMN foto VARCHAR(255) NULL AFTER emoji");
        $mensajes[] = '✅ Columna `barberos.foto` agregada';
    }

    // d) turnos "de la calle": citas sin cuenta de usuario
    $pdo->exec("ALTER TABLE citas MODIFY usuario_id INT UNSIGNED NULL");
    if (!$hayColumna('citas', 'visitante')) {
        $pdo->exec("ALTER TABLE citas ADD COLUMN visitante VARCHAR(100) NULL AFTER notas");
        $mensajes[] = '✅ Columna `citas.visitante` agregada';
    }
    if (!$hayColumna('citas', 'tel_visitante')) {
        $pdo->exec("ALTER TABLE citas ADD COLUMN tel_visitante VARCHAR(20) NULL AFTER visitante");
        $mensajes[] = '✅ Columna `citas.tel_visitante` agregada';
    }

    // e) recordatorios: marca cuándo se avisó (el cron del push la usa para no repetir)
    if (!$hayColumna('citas', 'recordado_en')) {
        $pdo->exec("ALTER TABLE citas ADD COLUMN recordado_en TIMESTAMP NULL AFTER tel_visitante");
        $mensajes[] = '✅ Columna `citas.recordado_en` agregada';
    }

    return $mensajes;
}

/**
 * Inserta datos de ejemplo la PRIMERA vez (si las tablas están vacías).
 * Nunca duplica: todo se valida con COUNT antes de insertar.
 */
function datos_iniciales(PDO $pdo): array
{
    $mensajes = [];

    // ---- Ajustes del negocio (nombre de la marca, contacto, logo) ----
    if ((int) $pdo->query('SELECT COUNT(*) FROM opciones')->fetchColumn() === 0) {
        $stmt = $pdo->prepare('INSERT INTO opciones (clave, valor) VALUES (?, ?)');
        $defaults = [
            ['nombre_negocio', 'Barber-Time'],
            ['whatsapp', ''],
            ['direccion', ''],
            ['logo', ''],
        ];
        foreach ($defaults as $par) {
            $stmt->execute($par);
        }
        $mensajes[] = '✅ Ajustes iniciales del negocio creados';
    } else {
        $mensajes[] = 'ℹ️ Los ajustes del negocio ya existían';
    }

    // ---- Horario base del negocio: Lunes a Sábado 09:00–19:00, domingo de descanso ----
    if ((int) $pdo->query('SELECT COUNT(*) FROM horarios WHERE barbero_id IS NULL')->fetchColumn() === 0) {
        $stmt = $pdo->prepare('INSERT INTO horarios (barbero_id, dia, abre, cierra) VALUES (NULL, ?, ?, ?)');
        for ($d = 1; $d <= 6; $d++) {
            $stmt->execute([$d, '09:00:00', '19:00:00']);
        }
        $stmt->execute([7, null, null]); // domingo cerrado (fila con NULL)
        $mensajes[] = '✅ Horario base del negocio creado (Lun–Sáb 09:00–19:00, Dom cerrado)';
    } else {
        $mensajes[] = 'ℹ️ El horario base del negocio ya existía';
    }

    // ---- Administrador ----
    if ((int) $pdo->query("SELECT COUNT(*) FROM usuarios WHERE rol = 'admin'")->fetchColumn() === 0) {
        $stmt = $pdo->prepare('INSERT INTO usuarios (nombre, telefono, email, pass_hash, rol) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([
            'Administrador',
            '0000000000',
            'admin@barber-time.com',
            password_hash('admin123', PASSWORD_DEFAULT), // ⚠️ cambiar en producción
            'admin',
        ]);
        $mensajes[] = '✅ Administrador inicial creado';
    } else {
        $mensajes[] = 'ℹ️ El administrador ya existía (no se duplicó)';
    }

    // ---- Barberos de ejemplo ----
    if ((int) $pdo->query('SELECT COUNT(*) FROM barberos')->fetchColumn() === 0) {
        $stmt = $pdo->prepare('INSERT INTO barberos (nombre, especialidades, biografia, emoji) VALUES (?, ?, ?, ?)');
        $barberos = [
            ['Carlos Mendoza', 'Clásico, razor', '10 años de experiencia en corte clásico.', '🧔'],
            ['Andrés "El Flow"', 'Degradados, freestyle', 'Especialista en degradados y diseños modernos.', '😎'],
            ['Dani Martínez', 'Barba y afeitado', 'Maestro de la barba y el afeitado con navaja.', '🧔‍♂️'],
        ];
        foreach ($barberos as $b) {
            $stmt->execute($b);
        }
        $mensajes[] = '✅ ' . count($barberos) . ' barberos de ejemplo creados';
    } else {
        $mensajes[] = 'ℹ️ Los barberos ya existían (no se duplicaron)';
    }

    // ---- Servicios de ejemplo (precios en COP, ajústalos luego desde el admin) ----
    if ((int) $pdo->query('SELECT COUNT(*) FROM servicios')->fetchColumn() === 0) {
        $stmt = $pdo->prepare('INSERT INTO servicios (nombre, descripcion, duracion_min, precio) VALUES (?, ?, ?, ?)');
        $servicios = [
            ['Corte clásico', 'Corte a máquina y tijera, con lavado.', 30, 20000],
            ['Corte + barba', 'Corte completo con perfilado de barba.', 45, 30000],
            ['Solo barba', 'Perfilado y cuidado de barba con navaja.', 20, 15000],
            ['Corte niño', 'Para menores de 10 años.', 30, 15000],
        ];
        foreach ($servicios as $s) {
            $stmt->execute($s);
        }
        $mensajes[] = '✅ ' . count($servicios) . ' servicios de ejemplo creados';
    } else {
        $mensajes[] = 'ℹ️ Los servicios ya existían (no se duplicaron)';
    }

    return $mensajes;
}
