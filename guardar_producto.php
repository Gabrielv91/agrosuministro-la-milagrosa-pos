<?php
session_start();

// Validar seguridad
if (!isset($_SESSION['usuario_id']) || ($_SESSION['usuario_rol'] != 'admin' && $_SESSION['usuario_rol'] != 'almacenista')) {
    die("Error de seguridad: Acceso denegado.");
}

require_once 'conexion.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $codigo_barras = trim($_POST['codigo_barras']);
    $nombre = strtoupper(trim($_POST['nombre'])); // Lo pasamos a mayúsculas para estandarizar
    $precio_usd = floatval($_POST['precio_usd']);
    $tipo_unidad = $_POST['tipo_unidad'];

    // Insertar el producto nuevo (notar que le pasamos un 0 directamente al stock)
    $stmt = $conexion->prepare("INSERT INTO productos (codigo_barras, nombre, precio_usd, tipo_unidad, stock) VALUES (?, ?, ?, ?, 0)");
    $stmt->bind_param("ssds", $codigo_barras, $nombre, $precio_usd, $tipo_unidad);
    
    if ($stmt->execute()) {
        $stmt->close();
        header("Location: inventario.php");
        exit;
    } else {
        // Por si intentan crear un producto con un código de barras que ya existe
        die("Error al guardar: " . $conexion->error);
    }
}
?>