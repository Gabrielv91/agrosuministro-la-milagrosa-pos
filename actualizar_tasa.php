<?php
session_start();
if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_rol'] != 'admin') {
    exit('Acceso denegado');
}

require_once 'conexion.php';

if (isset($_POST['tasa'])) {
    $nueva_tasa = floatval($_POST['tasa']);
    
    // Si no enviaron la del euro por alguna razón, mantenemos 1.08 por seguridad
    $nueva_tasa_euro = isset($_POST['tasa_euro']) ? floatval($_POST['tasa_euro']) : 1.08;

    if ($nueva_tasa > 0) {
        $conexion->query("UPDATE configuracion SET tasa_dia = $nueva_tasa, tasa_euro = $nueva_tasa_euro");
        echo "ok";
    }
}
?>