<?php
session_start();
require_once 'conexion.php';

// Solo el administrador puede editar o eliminar gastos
if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_rol'] != 'admin') {
    header("Location: reportes.php?error=no_autorizado");
    exit;
}

// 1. LÓGICA PARA ELIMINAR UN GASTO
if (isset($_GET['eliminar']) && !empty($_GET['eliminar'])) {
    $id_gasto = intval($_GET['eliminar']);
    $sql = "DELETE FROM gastos WHERE id = $id_gasto";
    
    if ($conexion->query($sql)) {
        header("Location: reportes.php?gasto=eliminado");
    } else {
        header("Location: reportes.php?error=bd");
    }
    exit;
}

// 2. LÓGICA PARA ACTUALIZAR UN GASTO EDITADO
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['accion']) && $_POST['accion'] == 'editar') {
    $id_gasto = intval($_POST['gasto_id']);
    $descripcion = $conexion->real_escape_string($_POST['descripcion']);
    $monto = floatval($_POST['monto']);
    $metodo_pago = $conexion->real_escape_string($_POST['metodo_pago']);

    $sql = "UPDATE gastos SET 
            descripcion = '$descripcion', 
            monto = $monto, 
            metodo_pago = '$metodo_pago' 
            WHERE id = $id_gasto";

    if ($conexion->query($sql)) {
        header("Location: reportes.php?gasto=actualizado");
    } else {
        header("Location: reportes.php?error=bd");
    }
    exit;
}

header("Location: reportes.php");
exit;
?>