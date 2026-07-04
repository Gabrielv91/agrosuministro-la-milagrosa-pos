<?php
// 1. OBLIGAR A PHP A USAR EL HORARIO DE CARACAS
date_default_timezone_set('America/Caracas');

// 2. CONEXIÓN INTELIGENTE (Lee de Easypanel en la nube, o usa XAMPP en tu PC)
$host     = getenv('DB_HOST') ?: "localhost";
$user     = getenv('DB_USER') ?: "root";
$password = getenv('DB_PASS') !== false ? getenv('DB_PASS') : "";
$database = getenv('DB_NAME') ?: "punto_venta";

$conexion = new mysqli($host, $user, $password, $database);

// Verificar si hay errores de conexión
if ($conexion->connect_error) {
    die("Error de conexión a la base de datos: " . $conexion->connect_error);
}

// Configurar juego de caracteres
$conexion->set_charset("utf8");

// 3. OBLIGAR A MYSQL A USAR EL HORARIO CARACAS (-04:00)
$conexion->query("SET time_zone = '-04:00'");
?>