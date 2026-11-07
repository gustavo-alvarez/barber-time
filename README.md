# 💈 Barber-Time

Agendador de citas para barberías: **reserva web para clientes + agenda para el equipo**, con avisos por WhatsApp, ciclo de vida de cada cita y estadísticas del negocio.

> Pensado para correr primero en **XAMPP (Windows)** y luego migrar a **cPanel** (PHP 8.x + MariaDB). Todo en PHP plano, sin frameworks.

## ✨ Qué puede hacer

| Módulo | Qué hace |
|---|---|
| 🗓️ Agendar público | El cliente elige servicio → barbero → día/hora (solo horas libres, con la duración real del servicio) |
| 🧑‍💼 Panel de citas | La barbería ve todo por día/filtro y crea turnos de "cliente de la calle" (por teléfono) |
| 🚦 Ciclo de vida | Cada cita: ⏳ pendiente → ✅ confirmada → ✔ completada · 🚫 ausente · ❌ cancelada, con botón **↩️ Deshacer** si se marca algo por error |
| 🔓 Disponibilidad viva | Una hora se libera de la agenda si la cita queda cancelada o ausente (otro cliente puede tomarla) |
| 💬 WhatsApp | Botón que abre WhatsApp con el mensaje ya escrito (turno nuevo, movimiento, cancelación) y confirma turnos pendientes al vuelo |
| ⏰ Recordatorios | Aviso en la app cuando la cita del cliente empieza pronto (Piso 1; Web Push tras migrar = Piso 2) |
| 📊 Estadísticas | Ingresos del mes, citas del día, pendientes, tops de servicios/barberos/clientes y gráfica de 6 meses (solo citas `completada` cuentan ingresos) |
| 👤 Barberos | Ficha por barbero con foto, horarios propios por día (o heredados del negocio), activar/desactivar |
| 🔒 Seguridad | CSRF en todos los POST, contraseñas con `password_hash()`, archivos subidos validados por contenido (no por extensión), roles por sesión |

## 🚀 Instalación en XAMPP (10 minutos)

1. **XAMPP** con **Apache** y **MySQL** corriendo (PHP 8.1+).
2. Copia esta carpeta dentro de `C:\xampp\htdocs\barber-time`.
3. **Conecta la BD:** copia `config/database.example.php` a `config/database.php` (XAMPP por defecto ya sirve: usuario `root`, sin clave, BD `barber_time`).
4. Abre **`http://localhost/barber-time/instalar.php`** → crea las tablas con los datos iniciales.
5. Crea el usuario admin y a los barberos desde la misma pantalla, o directamente en `phpMyAdmin`.
6. ¡Listo! Desde otra PC o el celular: `http://TU-IP/barber-time/`.

### ⚠️ Dos archivos que NO están en el repo (a propósito)

- `config/database.php` → cada ambiente usa los suyos (usa la plantilla `config/database.example.php`).
- `assets/uploads/` → las fotos del negocio viven solo en el servidor (los archivos de prueba del repo van a `assets/uploads/ejemplo/` si hacen falta).

## 🗺️ Mapa rápido del proyecto

```
barber-time/
├── index.php            entrada pública (servicios + barberos)
├── agendar.php          reserva del cliente (3 pasos)
├── mis_citas.php        agenda del barbero y del admin (tarjetas de turno)
├── admin.php            agenda del día del admin (rápidos por cita)
├── perfil.php · mi cuenta
├── clientes.php · estadisticas.php · ajustes.php · horarios.php
├── editar_barberos.php · editar_servicios.php
├── login.php · registro.php · logout.php
├── includes/            helpers: db, auth (sesión/CSRF/roles), citas (reglas del negocio), schema (tablas), imagenes (uploads seguros)
├── assets/              css, iconos y uploads
└── config/database.php  credenciales (NO está en el repo)
```

## 🗃️ Estados de una cita

`pendiente` → reservada por el cliente en la web · `confirmada` → confirmada por la barbería (los turnos creados manual nacen aquí) · `completada` → servicio realizado (cuenta ingresos) · `ausente` → el cliente no llegó (libera la hora) · `cancelada` → cancelada (libera la hora, no cuenta ingresos).

## 🇨🇴 Notas de uso

- El teléfono de WhatsApp se normaliza a Colombia (prefijo `+57` automático para números de 10 dígitos).
- La zona horaria se configura en `config/database.php` (`America/Bogota` por defecto).

## 🔮 En camino

- Migración a cPanel con despliegue vía Git.
- Piso 2 de recordatorios: Web Push (la columna `citas.recordado_en` ya está creada).
- Historial de audición… si el negocio lo pide 🙂
