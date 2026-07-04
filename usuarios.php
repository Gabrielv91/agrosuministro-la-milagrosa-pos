<?php
// 1. SEGURIDAD DE ALTO NIVEL: Solo el Administrador puede entrar aquí
session_start();
if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_rol'] != 'admin') {
    header("Location: menu.php");
    exit;
}

require_once 'conexion.php';
$mensaje = '';

// =========================================================================
// 2. PROCESAR NUEVO USUARIO
// =========================================================================
if (isset($_POST['crear_usuario'])) {
    $nombre = $conexion->real_escape_string($_POST['nombre']);
    $usuario = $conexion->real_escape_string($_POST['usuario']);
    $clave = $conexion->real_escape_string($_POST['clave']);
    $rol = $conexion->real_escape_string($_POST['rol']);

    // IMPORTANTE: Si tu archivo de login (index.php) usa password_hash(), descomenta la siguiente línea:
    // $clave = password_hash($clave, PASSWORD_DEFAULT);

    $check = $conexion->query("SELECT id FROM usuarios WHERE usuario = '$usuario'");
    if ($check && $check->num_rows > 0) {
        $mensaje = "❌ Error: El nombre de usuario '$usuario' ya existe. Elige otro.";
    } else {
        $conexion->query("INSERT INTO usuarios (nombre, usuario, clave, rol) VALUES ('$nombre', '$usuario', '$clave', '$rol')");
        $mensaje = "✅ Usuario '$nombre' creado exitosamente como $rol.";
    }
}

// =========================================================================
// 3. PROCESAR CAMBIO DE CONTRASEÑA
// =========================================================================
if (isset($_POST['cambiar_clave'])) {
    $id_usuario = intval($_POST['id_usuario']);
    $nueva_clave = $conexion->real_escape_string($_POST['nueva_clave']);
    
    // Si tu sistema usa hash, descomenta esto:
    // $nueva_clave = password_hash($nueva_clave, PASSWORD_DEFAULT);

    $conexion->query("UPDATE usuarios SET clave = '$nueva_clave' WHERE id = $id_usuario");
    $mensaje = "🔒 Contraseña actualizada correctamente.";
}

// =========================================================================
// 4. PROCESAR EDICIÓN DE DATOS (Nombre y Rol)
// =========================================================================
if (isset($_POST['editar_usuario'])) {
    $id_usuario = intval($_POST['id_usuario']);
    $nombre = $conexion->real_escape_string($_POST['nombre']);
    $usuario = $conexion->real_escape_string($_POST['usuario']);
    $rol = $conexion->real_escape_string($_POST['rol']);

    // Validar que el nuevo nombre de usuario no lo tenga otra persona
    $check = $conexion->query("SELECT id FROM usuarios WHERE usuario = '$usuario' AND id != $id_usuario");
    if ($check && $check->num_rows > 0) {
        $mensaje = "❌ Error: El usuario '$usuario' ya está en uso por otra persona.";
    } else {
        $conexion->query("UPDATE usuarios SET nombre = '$nombre', usuario = '$usuario', rol = '$rol' WHERE id = $id_usuario");
        $mensaje = "✏️ Datos del usuario actualizados.";
    }
}

// =========================================================================
// 5. CONSULTAR TODOS LOS USUARIOS
// =========================================================================
$query_usuarios = "SELECT * FROM usuarios ORDER BY rol ASC, nombre ASC";
$lista_usuarios = $conexion->query($query_usuarios);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Personal - Mi Negocio POS</title>
    <link rel="stylesheet" href="css/style.css?v=<?php echo time(); ?>">
    <style>
        .pantalla-completa { padding: 2rem; max-width: 1000px; margin: 0 auto; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; z-index: 1000; }
        .modal-caja { background: white; padding: 2rem; border-radius: 8px; width: 400px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
        
        .badge-rol { padding: 4px 10px; border-radius: 6px; font-weight: bold; font-size: 0.85rem; color: white; text-transform: uppercase;}
        .rol-admin { background: #3b82f6; }
        .rol-vendedor { background: #10b981; }
        .rol-almacenista { background: #f59e0b; }
        
        .btn-accion { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; padding: 0.4rem 0.8rem; border-radius: 4px; font-weight: bold; cursor: pointer; transition: 0.2s; font-size: 0.85rem; }
        .btn-accion:hover { background: #e2e8f0; }
        .btn-clave { background: #fee2e2; color: #ef4444; border-color: #fca5a5; }
        .btn-clave:hover { background: #fecaca; }
    </style>
</head>
<body style="background-color: #f8fafc;">
    
    <header class="top-bar">
        <div style="display: flex; align-items: center; gap: 1rem;">
            <a href="menu.php" style="text-decoration: none; font-size: 1.5rem; color: var(--texto-principal);">⬅️</a>
            <h1>Gestión de Personal y Accesos</h1>
        </div>
        <div>
            <button onclick="abrirModal('modal-nuevo')" class="btn-login" style="margin:0; background: #10b981; padding: 0.6rem 1.2rem;">➕ Crear Usuario</button>
        </div>
    </header>

    <main class="pantalla-completa">
        <?php if($mensaje): ?>
            <div style="background: #eff6ff; color: #1e40af; padding: 1rem; border-radius: 6px; margin-bottom: 1.5rem; font-weight: bold; border-left: 4px solid #3b82f6;"><?php echo $mensaje; ?></div>
        <?php endif; ?>

        <div style="background: white; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); overflow-x: auto;">
            <table class="tabla-inventario" style="width: 100%; margin: 0;">
                <thead>
                    <tr>
                        <th>Nombre del Empleado</th>
                        <th>Nombre de Usuario (Login)</th>
                        <th>Nivel de Acceso</th>
                        <th style="text-align: right;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($lista_usuarios && $lista_usuarios->num_rows > 0): ?>
                        <?php while ($u = $lista_usuarios->fetch_assoc()): 
                            $clase_rol = 'rol-vendedor';
                            if ($u['rol'] == 'admin') $clase_rol = 'rol-admin';
                            if ($u['rol'] == 'almacenista') $clase_rol = 'rol-almacenista';
                        ?>
                        <tr>
                            <td style="font-weight: bold; color: #1e3a8a; font-size: 1.1rem;">
                                <?php echo htmlspecialchars($u['nombre']); ?>
                                <?php if($u['id'] == $_SESSION['usuario_id']) echo '<span style="color: #10b981; font-size: 0.8rem; margin-left: 5px;">(Tú)</span>'; ?>
                            </td>
                            <td style="color: #475569; font-weight: bold;">@<?php echo htmlspecialchars($u['usuario']); ?></td>
                            <td><span class="badge-rol <?php echo $clase_rol; ?>"><?php echo htmlspecialchars($u['rol']); ?></span></td>
                            <td style="text-align: right; display: flex; justify-content: flex-end; gap: 0.5rem;">
                                <button class="btn-accion btn-clave" onclick="abrirModalClave(<?php echo $u['id']; ?>, '<?php echo addslashes($u['nombre']); ?>')">🔑 Cambiar Clave</button>
                                <button class="btn-accion" onclick="abrirModalEditar(<?php echo $u['id']; ?>, '<?php echo addslashes($u['nombre']); ?>', '<?php echo addslashes($u['usuario']); ?>', '<?php echo $u['rol']; ?>')">✏️ Editar</button>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="4" style="text-align: center; padding: 2rem;">No hay usuarios registrados.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <div id="modal-nuevo" class="modal">
        <div class="modal-caja">
            <h3 style="margin-bottom: 1.2rem; color: #1e3a8a; border-bottom: 2px solid #f1f5f9; padding-bottom: 0.5rem;">➕ Registrar Empleado</h3>
            <form method="POST">
                <div style="margin-bottom: 1rem;">
                    <label style="font-size: 0.85rem; font-weight: bold;">Nombre Completo</label>
                    <input type="text" name="nombre" required class="input-cliente" style="width: 100%; box-sizing: border-box; padding: 0.7rem;">
                </div>
                <div style="margin-bottom: 1rem;">
                    <label style="font-size: 0.85rem; font-weight: bold;">Nombre de Usuario (Para iniciar sesión)</label>
                    <input type="text" name="usuario" required class="input-cliente" style="width: 100%; box-sizing: border-box; padding: 0.7rem;" placeholder="Ej: maria_perez">
                </div>
                <div style="margin-bottom: 1rem;">
                    <label style="font-size: 0.85rem; font-weight: bold;">Contraseña</label>
                    <input type="text" name="clave" required class="input-cliente" style="width: 100%; box-sizing: border-box; padding: 0.7rem;">
                </div>
                <div style="margin-bottom: 1.5rem;">
                    <label style="font-size: 0.85rem; font-weight: bold;">Nivel de Permisos</label>
                    <select name="rol" required class="input-cliente" style="width: 100%; box-sizing: border-box; padding: 0.7rem;">
                        <option value="vendedor">Vendedor (Solo Punto de Venta)</option>
                        <option value="almacenista">Almacenista (Solo Inventario)</option>
                        <option value="admin">Administrador (Acceso Total)</option>
                    </select>
                </div>
                <div style="display: flex; gap: 1rem;">
                    <button type="submit" name="crear_usuario" class="btn-login" style="width: 100%; background: #10b981; margin:0;">Guardar</button>
                    <button type="button" onclick="cerrarModal('modal-nuevo')" class="btn-login" style="width: 100%; background: #94a3b8; margin:0;">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <div id="modal-editar" class="modal">
        <div class="modal-caja">
            <h3 style="margin-bottom: 1.2rem; color: #f59e0b; border-bottom: 2px solid #f1f5f9; padding-bottom: 0.5rem;">✏️ Editar Empleado</h3>
            <form method="POST">
                <input type="hidden" name="id_usuario" id="edit-id">
                <div style="margin-bottom: 1rem;">
                    <label style="font-size: 0.85rem; font-weight: bold;">Nombre Completo</label>
                    <input type="text" name="nombre" id="edit-nombre" required class="input-cliente" style="width: 100%; box-sizing: border-box; padding: 0.7rem;">
                </div>
                <div style="margin-bottom: 1rem;">
                    <label style="font-size: 0.85rem; font-weight: bold;">Nombre de Usuario</label>
                    <input type="text" name="usuario" id="edit-usuario" required class="input-cliente" style="width: 100%; box-sizing: border-box; padding: 0.7rem;">
                </div>
                <div style="margin-bottom: 1.5rem;">
                    <label style="font-size: 0.85rem; font-weight: bold;">Nivel de Permisos</label>
                    <select name="rol" id="edit-rol" required class="input-cliente" style="width: 100%; box-sizing: border-box; padding: 0.7rem;">
                        <option value="vendedor">Vendedor</option>
                        <option value="almacenista">Almacenista</option>
                        <option value="admin">Administrador</option>
                    </select>
                </div>
                <div style="display: flex; gap: 1rem;">
                    <button type="submit" name="editar_usuario" class="btn-login" style="width: 100%; background: #f59e0b; margin:0;">Actualizar</button>
                    <button type="button" onclick="cerrarModal('modal-editar')" class="btn-login" style="width: 100%; background: #94a3b8; margin:0;">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <div id="modal-clave" class="modal">
        <div class="modal-caja">
            <h3 style="margin-bottom: 0.5rem; color: #ef4444;">🔑 Cambiar Contraseña</h3>
            <p style="margin-bottom: 1.5rem; color: #64748b; font-size: 0.9rem;">Nueva clave para: <strong id="clave-nombre" style="color: #1e3a8a;"></strong></p>
            <form method="POST">
                <input type="hidden" name="id_usuario" id="clave-id">
                <div style="margin-bottom: 1.5rem;">
                    <input type="text" name="nueva_clave" required class="input-cliente" style="width: 100%; box-sizing: border-box; padding: 0.8rem; font-size: 1.1rem;" placeholder="Escribe la nueva contraseña">
                </div>
                <div style="display: flex; gap: 1rem;">
                    <button type="submit" name="cambiar_clave" class="btn-login" style="width: 100%; background: #ef4444; margin:0;">Guardar Clave</button>
                    <button type="button" onclick="cerrarModal('modal-clave')" class="btn-login" style="width: 100%; background: #94a3b8; margin:0;">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function abrirModal(id) { document.getElementById(id).style.display = 'flex'; }
        function cerrarModal(id) { document.getElementById(id).style.display = 'none'; }

        function abrirModalEditar(id, nombre, usuario, rol) {
            document.getElementById('edit-id').value = id;
            document.getElementById('edit-nombre').value = nombre;
            document.getElementById('edit-usuario').value = usuario;
            document.getElementById('edit-rol').value = rol;
            abrirModal('modal-editar');
        }

        function abrirModalClave(id, nombre) {
            document.getElementById('clave-id').value = id;
            document.getElementById('clave-nombre').innerText = nombre;
            abrirModal('modal-clave');
        }
    </script>
</body>
</html>