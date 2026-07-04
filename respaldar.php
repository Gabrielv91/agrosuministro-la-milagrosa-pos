<?php
session_start();
// Seguridad: Solo el administrador puede descargar respaldos
if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_rol'] != 'admin') {
    header("Location: index.php");
    exit;
}

require_once 'conexion.php';

// Configurar zona horaria para el nombre del archivo
date_default_timezone_get();
$fecha = date('Y-m-d_H-i-s');
$nombre_archivo = 'respaldo_Agrosuministro_La_Milagrosa_' . $fecha . '.sql';

// Configurar cabeceras para forzar la descarga del archivo SQL
header("Content-Type: application/octet-stream");
header("Content-Disposition: attachment; filename=\"$nombre_archivo\"");
header("Pragma: no-cache");
header("Expires: 0");

// Obtener listado de tablas
$tablas = array();
$resultado = $conexion->query("SHOW TABLES");
while ($fila = $resultado->fetch_row()) {
    $tablas[] = $fila[0];
}

$sql_output = "-- ======================================================\n";
$sql_output .= "-- RESPALDO AUTOMÁTICO - Agrosuministro La Milagrosa\n";
$sql_output .= "-- Generado el: " . date('d/m/Y H:i:A') . "\n";
$sql_output .= "-- ======================================================\n\n";
$sql_output .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

// Procesar cada tabla
foreach ($tablas as $tabla) {
    // Estructura de la tabla
    $res_estructura = $conexion->query("SHOW CREATE TABLE `$tabla`");
    $fila_estructura = $res_estructura->fetch_row();
    
    $sql_output .= "DROP TABLE IF EXISTS `$tabla`;\n";
    $sql_output .= $fila_estructura[1] . ";\n\n";
    
    // Datos de la tabla
    $res_datos = $conexion->query("SELECT * FROM `$tabla`");
    $num_columnas = $res_datos->field_count;
    
    while ($fila_datos = $res_datos->fetch_row()) {
        $sql_output .= "INSERT INTO `$tabla` VALUES(";
        for ($i = 0; $i < $num_columnas; $i++) {
            if (isset($fila_datos[$i])) {
                // Escapar caracteres especiales para evitar errores de sintaxis SQL
                $valor = $conexion->real_escape_string($fila_datos[$i]);
                $sql_output .= '"' . $valor . '"';
            } else {
                $sql_output .= 'NULL';
            }
            if ($i < ($num_columnas - 1)) {
                $sql_output .= ',';
            }
        }
        $sql_output .= ");\n";
    }
    $sql_output .= "\n";
}

$sql_output .= "SET FOREIGN_KEY_CHECKS=1;\n";

// Imprimir el contenido (esto es lo que descarga el navegador)
echo $sql_output;
exit;
?>