<?php
session_start();
require_once 'conexion.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $usuario = trim($_POST['usuario']);
    $clave = trim($_POST['clave']);

    // Consultamos la base de datos
    // Nota: Por simplicidad estamos comparando texto plano, pero en producción idealmente usaríamos password_hash() y password_verify()
    $stmt = $conexion->prepare("SELECT id, nombre, rol, estado FROM usuarios WHERE usuario = ? AND clave = ?");
    $stmt->bind_param("ss", $usuario, $clave);
    $stmt->execute();
    $resultado = $stmt->get_result();

    if ($resultado->num_rows > 0) {
        $user = $resultado->fetch_assoc();
        
        // Verificamos si el administrador no le ha suspendido el acceso
        if ($user['estado'] == 'inactivo') {
            header("Location: index.php?error=inactivo");
            exit;
        }

        // GUARDAMOS LA IDENTIDAD DEL USUARIO EN LA MEMORIA DEL SERVIDOR
        $_SESSION['usuario_id'] = $user['id'];
        $_SESSION['usuario_nombre'] = $user['nombre'];
        $_SESSION['usuario_rol'] = $user['rol'];

        // Lo mandamos al menú principal (que construiremos en el siguiente paso)
        header("Location: menu.php");
    } else {
        // Credenciales inválidas, lo regresamos al login con un error
        header("Location: index.php?error=1");
    }
    
    $stmt->close();
} else {
    // Si alguien intenta entrar a login.php escribiendo la ruta, lo pateamos al index
    header("Location: index.php");
}
?>