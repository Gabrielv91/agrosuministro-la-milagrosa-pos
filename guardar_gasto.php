<?php
session_start();
require_once 'conexion.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $descripcion = $conexion->real_escape_string($_POST['descripcion']);
    $monto = floatval($_POST['monto']);
    $metodo_pago = $conexion->real_escape_string($_POST['metodo_pago']);
    $usuario_id = $_SESSION['usuario_id'];

    $sql = "INSERT INTO gastos (descripcion, monto, metodo_pago, usuario_id) 
            VALUES ('$descripcion', $monto, '$metodo_pago', $usuario_id)";
    
    if ($conexion->query($sql)) {
        // Redirigir de vuelta a reportes con un mensaje de éxito
        header("Location: reportes.php?gasto=ok");
    } else {
        header("Location: reportes.php?gasto=error");
    }
}
?>