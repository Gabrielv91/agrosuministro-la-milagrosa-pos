<?php
// 1. OBLIGAR A PHP A USAR EL HORARIO DE CARACAS (Evita que registre ventas al día siguiente si trabajas de noche)
date_default_timezone_set('America/Caracas');

$host = "localhost";
$user = "root";
$password = ""; // Por defecto en XAMPP viene vacío
$database = "punto_venta";

$conexion = new mysqli($host, $user, $password, $database);

// Verificar si hay errores de conexión
if ($conexion->connect_error) {
    die("Error de conexión a la base de datos: " . $conexion->connect_error);
}

// Configurar el conjunto de caracteres a UTF-8 para evitar problemas con acentos o eñes
$conexion->set_charset("utf8");

// 2. OBLIGAR A MYSQL A INDIZAR CON EL MISMO HORARIO (UTC-4 de Venezuela)
$conexion->query("SET time_zone = '-04:00'");
?>