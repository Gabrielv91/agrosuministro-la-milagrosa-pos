<?php
session_start();

// Validar seguridad
if (!isset($_SESSION['usuario_id']) || ($_SESSION['usuario_rol'] != 'admin' && $_SESSION['usuario_rol'] != 'almacenista')) {
    die("Error de seguridad: Acceso denegado.");
}

require_once 'conexion.php';

// Activar reporte de errores estricto de MySQL para que nada falle en silencio
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $producto_id = $_POST['producto_id'];
    $cantidad = floatval($_POST['cantidad']);
    $observacion = trim($_POST['observacion']);
    $usuario_id = $_SESSION['usuario_id'];

    if (empty($producto_id) || $cantidad <= 0) {
        die("Error: Datos inválidos.");
    }

    try {
        $conexion->begin_transaction();

        // 1. Bloqueamos momentáneamente el producto y extraemos su stock actual
        $stmt_check = $conexion->prepare("SELECT stock FROM productos WHERE id = ? FOR UPDATE");
        $stmt_check->bind_param("i", $producto_id);
        $stmt_check->execute();
        $resultado = $stmt_check->get_result();
        $fila = $resultado->fetch_assoc();
        $stock_anterior = $fila['stock'];
        $stmt_check->close();

        // 2. Hacemos la matemática exacta
        $nuevo_stock = $stock_anterior + $cantidad;

        // 3. Actualizamos el producto con el resultado exacto
        $stmt_stock = $conexion->prepare("UPDATE productos SET stock = ? WHERE id = ?");
        $stmt_stock->bind_param("di", $nuevo_stock, $producto_id);
        $stmt_stock->execute();
        $stmt_stock->close();

        // 4. Guardamos la evidencia en la cámara de seguridad (auditoría)
        $stmt_movimiento = $conexion->prepare("INSERT INTO movimientos_inventario (producto_id, usuario_id, tipo_movimiento, cantidad, observacion) VALUES (?, ?, 'entrada', ?, ?)");
        $stmt_movimiento->bind_param("iids", $producto_id, $usuario_id, $cantidad, $observacion);
        $stmt_movimiento->execute();
        $stmt_movimiento->close();

        // Confirmamos todos los cambios en la base de datos
        $conexion->commit();

        // ✨ SOLUCIÓN: Redirigimos de vuelta a la página correcta (productos.php)
        header("Location: productos.php");
        exit;

    } catch (Exception $e) {
        $conexion->rollback();
        die("Error crítico al procesar la entrada: " . $e->getMessage());
    }
}
?>