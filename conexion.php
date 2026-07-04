<?php
// 1. OBLIGAR A PHP A USAR EL HORARIO DE CARACAS
date_default_timezone_set('America/Caracas');

// 2. LEER CREDENCIALES SEGURAS DEL SERVIDOR (VARIABLES DE ENTORNO)
// Si la variable está definida en Coolify/VPS, la usa. 
// Si no existe (porque estás en tu PC local con XAMPP), usa los valores por defecto locales.
$host     = getenv('DB_HOST') ?: "localhost";
$user     = getenv('DB_USER') ?: "root";
$password = getenv('DB_PASS') !== false ? getenv('DB_PASS') : ""; // Por defecto vacío en XAMPP local
$database = getenv('DB_NAME') ?: "punto_venta";

$conexion = new mysqli($host, $user, $password, $database);

// Verificar conexión
if ($conexion->connect_error) {
    die("Error crítico de conexión a la base de datos: " . $conexion->connect_error);
}

$conexion->set_charset("utf8");
$conexion->query("SET time_zone = '-04:00'");
?>