<?php
require_once 'conexion.php';

$busqueda = isset($_GET['q']) ? $conexion->real_escape_string($_GET['q']) : '';

if($busqueda === '') {
    echo json_encode([]);
    exit;
}

// Busca por cédula o por nombre
$query = "SELECT id, cedula, nombre, telefono FROM clientes WHERE cedula LIKE '%$busqueda%' OR nombre LIKE '%$busqueda%' LIMIT 5";
$resultado = $conexion->query($query);

$clientes = [];
while($fila = $resultado->fetch_assoc()) {
    $clientes[] = $fila;
}

echo json_encode($clientes);
?>