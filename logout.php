<?php
/**
 * CERRAR SESIÓN
 * --------------
 * Borra la sesión completa y devuelve al login.
 */
require_once __DIR__ . '/includes/auth.php';

$_SESSION = [];
session_destroy();

header('Location: login.php');
exit;
