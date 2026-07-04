<?php
// 1. SEGURIDAD (Debe ser la línea 1, sin espacios antes)
session_start();

// Si no está logueado, o si es almacenista (no tiene permiso aquí), lo pateamos al login
// Nota: Deberías verificar si el rol 'vendedor' debe tener permiso para eliminar, por seguridad
if (!isset($_SESSION['usuario_id']) || ($_SESSION['usuario_rol'] != 'admin' && $_SESSION['usuario_rol'] != 'vendedor')) {
    header("Location: index.php");
    exit; 
}

// 2. Incluir la conexión a la base de datos
// Asegúrate de tener este archivo 'conexion.php' creado con tus credenciales de base de datos
require_once 'conexion.php'; 

// Variables para el mensaje de alerta
$mensaje = '';
$tipo_alerta = ''; // puede ser 'exito' o 'error'

// --- LÓGICA DE ELIMINACIÓN CON ESCUDO DE SEGURIDAD ---
if (isset($_GET['eliminar_id'])) {
    $id_eliminar = intval($_GET['eliminar_id']);
    
    // Consultamos si tiene deuda acumulada o si tiene facturas registradas
    $stmt_check = $conexion->prepare("SELECT SUM(deuda_usd) as total_deuda, COUNT(id) as total_ventas FROM ventas WHERE cliente_id = ?");
    $stmt_check->bind_param("i", $id_eliminar);
    $stmt_check->execute();
    $resultado_check = $stmt_check->get_result()->fetch_assoc();
    $stmt_check->close();

    $total_deuda = floatval($resultado_check['total_deuda']);
    $total_ventas = intval($resultado_check['total_ventas']);

    if ($total_deuda > 0) {
        // Bloqueo 1: Tiene dinero pendiente por pagar
        $mensaje = "⚠️ No se puede eliminar: El cliente tiene una deuda activa de $" . number_format($total_deuda, 2);
        $tipo_alerta = "error";
    } elseif ($total_ventas > 0) {
        // Bloqueo 2: No debe dinero, pero tiene historial de facturas
        $mensaje = "⚠️ No se puede eliminar: Este cliente tiene historial de compras. Borrarlo dañaría tus reportes de ventas.";
        $tipo_alerta = "error";
    } else {
        // Vía libre: Es un cliente sin historial, se puede borrar sin peligro
        $query_delete = "DELETE FROM clientes WHERE id = $id_eliminar";
        
        if ($conexion->query($query_delete)) {
            $mensaje = "Cliente eliminado correctamente.";
            $tipo_alerta = "exito";
        } else {
            $mensaje = "Error técnico al eliminar: " . $conexion->error;
            $tipo_alerta = "error";
        }
    }
}

// --- LÓGICA DE GUARDAR (Maneja la petición POST, sirve para Crear y Editar) ---
if (isset($_POST['guardar_cliente'])) {
    // Escapar datos para seguridad básica (o usa sentencias preparadas si prefieres)
    $cedula = $conexion->real_escape_string($_POST['cedula']);
    $nombre = $conexion->real_escape_string($_POST['nombre']);
    $telefono = $conexion->real_escape_string($_POST['telefono']);
    $id_cliente = $_POST['id_cliente']; // Campo oculto crucial para saber si editamos

    if (empty($id_cliente)) {
        // --- CREAR NUEVO CLIENTE ---
        $query_save = "INSERT INTO clientes (cedula, nombre, telefono) VALUES ('$cedula', '$nombre', '$telefono')";
        $accion_texto = "registrado";
    } else {
        // --- ACTUALIZAR CLIENTE EXISTENTE ---
        $id_cliente = intval($id_cliente); // Asegurar que es entero
        $query_save = "UPDATE clientes SET cedula = '$cedula', nombre = '$nombre', telefono = '$telefono' WHERE id = $id_cliente";
        $accion_texto = "actualizado";
    }

    if ($conexion->query($query_save)) {
        $mensaje = "Cliente $accion_texto correctamente.";
        $tipo_alerta = "exito";
    } else {
        $mensaje = "Error al intentar guardar el cliente: " . $conexion->error;
        $tipo_alerta = "error";
    }
}

// --- CONSULTA PARA LLENAR LA TABLA (Muestra siempre los datos más actualizados) ---
$query_clientes = "SELECT * FROM clientes ORDER BY nombre ASC";
$resultado_clientes = $conexion->query($query_clientes);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Clientes - Mi Negocio POS</title>
    <link rel="stylesheet" href="css/style.css?v=<?php echo time(); ?>">
    <style>
        .area-mensajes { margin-bottom: 20px; text-align: center; width: 100%; max-width: 1200px; margin-left: auto; margin-right: auto; }
        .alerta-exito { background-color: #d1fae5; color: #065f46; padding: 10px; border-radius: 5px; border: 1px solid #059669; }
        .alerta-error { background-color: #fee2e2; color: #991b1b; padding: 10px; border-radius: 5px; border: 1px solid #dc2626; }
        
        .tabla-clientes button, .tabla-clientes a.btn-accion { 
            background: none; border: none; cursor: pointer; font-size: 1.2rem; padding: 5px; text-decoration: none; display: inline-block;
        }
        .tabla-clientes a.btn-eliminar { color: #ef4444; }
        .tabla-clientes a.btn-eliminar:hover { color: #dc2626; }
        .tabla-clientes button.btn-editar { color: #3b82f6; }
        .tabla-clientes button.btn-editar:hover { color: #2563eb; }
    </style>
</head>
<body style="background-color: #f8fafc; font-family: sans-serif;">
    
    <header class="top-bar" style="display: flex; justify-content: space-between; align-items: center; width: 100%; box-sizing: border-box;">
        <div style="display: flex; align-items: center; gap: 1rem;">
            <a href="menu.php" style="text-decoration: none; font-size: 1.5rem; color: var(--texto-principal); transition: transform 0.2s; display: inline-block;" onmouseover="this.style.transform='scale(1.2)'" onmouseout="this.style.transform='scale(1)'" title="Volver al Menú Principal">⬅️</a>
            
            <h1>Directorio de Clientes</h1>
        </div>
        
        <div style="font-size: 0.95rem; color: var(--texto-secundario);">
            Usuario: <strong style="color: var(--primario);"><?php echo $_SESSION['usuario_nombre']; ?></strong>
        </div>
    </header>

    <main class="contenedor-principal" style="padding: 20px; max-width: 1200px; margin: 0 auto; display: flex; flex-direction: column; align-items: center;">
        
        <?php if (!empty($mensaje)): ?>
            <div class="area-mensajes">
                <div class="alerta-<?php echo $tipo_alerta; ?>">
                    <?php echo htmlspecialchars($mensaje); ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="layout-clientes" style="display: flex; gap: 30px; width: 100%; align-items: flex-start;">
            
            <section class="panel-formulario-cliente" style="background: white; padding: 25px; border-radius: 8px; border: 1px solid #e2e8f0; flex: 0 0 350px;">
                <h2 style="color: #1e3a8a; margin-top: 0; margin-bottom: 20px;" id="titulo-formulario">Registrar / Editar Cliente</h2>
                
                <form action="clientes.php" method="POST" id="form-cliente">
                    <input type="hidden" name="id_cliente" id="id_cliente_input" value="">
                    
                    <div class="grupo-form" style="margin-bottom: 15px;">
                        <label for="cedula" style="display: block; color: #64748b; font-size: 0.9rem; margin-bottom: 5px; font-weight: bold;">Cédula o RIF</label>
                        <input type="text" name="cedula" id="cedula_input" placeholder="Ej: V-12345678" style="width: 100%; padding: 10px; border-radius: 5px; border: 1px solid #e2e8f0; background: #f8fafc;" required>
                    </div>
                    
                    <div class="grupo-form" style="margin-bottom: 15px;">
                        <label for="nombre" style="display: block; color: #64748b; font-size: 0.9rem; margin-bottom: 5px; font-weight: bold;">Nombre Completo</label>
                        <input type="text" name="nombre" id="nombre_input" placeholder="Ej: Juan Pérez" style="width: 100%; padding: 10px; border-radius: 5px; border: 1px solid #e2e8f0; background: #f8fafc;" required>
                    </div>
                    
                    <div class="grupo-form" style="margin-bottom: 25px;">
                        <label for="telefono" style="display: block; color: #64748b; font-size: 0.9rem; margin-bottom: 5px; font-weight: bold;">WhatsApp</label>
                        <input type="text" name="telefono" id="telefono_input" placeholder="Ej: 04141234567" style="width: 100%; padding: 10px; border-radius: 5px; border: 1px solid #e2e8f0; background: #f8fafc;">
                    </div>
                    
                    <div style="display: flex; gap: 10px; flex-direction: column;">
                        <button type="submit" name="guardar_cliente" style="background-color: #10b981; color: white; border: none; padding: 12px; border-radius: 5px; font-weight: bold; cursor: pointer; font-size: 1rem; width: 100%;">
                            Guardar Cliente
                        </button>
                        
                        <button type="button" id="btn-cancelar-edicion" onclick="resetFormulario()" style="background-color: #fee2e2; color: #dc2626; border: 1px solid #dc2626; padding: 10px; border-radius: 5px; font-weight: bold; cursor: pointer; display: none;">
                            Cancelar Edición
                        </button>
                    </div>
                </form>
            </section>

            <section class="panel-tabla-clientes" style="background: white; border-radius: 8px; border: 1px solid #e2e8f0; flex: 1; overflow: hidden;">
                <table class="tabla-inventario tabla-clientes" style="width: 100%; border-collapse: collapse;">
                    <thead style="background-color: white;">
                        <tr>
                            <th style="text-align: left; padding: 15px; border-bottom: 1px solid #e2e8f0; color: #64748b; text-transform: uppercase; font-size: 0.85rem;">CÉDULA</th>
                            <th style="text-align: left; padding: 15px; border-bottom: 1px solid #e2e8f0; color: #64748b; text-transform: uppercase; font-size: 0.85rem;">NOMBRE</th>
                            <th style="text-align: left; padding: 15px; border-bottom: 1px solid #e2e8f0; color: #64748b; text-transform: uppercase; font-size: 0.85rem;">WHATSAPP</th>
                            <th style="text-align: center; padding: 15px; border-bottom: 1px solid #e2e8f0; color: #64748b; text-transform: uppercase; font-size: 0.85rem; width: 100px;">ACCIONES</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if ($resultado_clientes && $resultado_clientes->num_rows > 0):
                            while ($cliente = $resultado_clientes->fetch_assoc()): 
                        ?>
                                <tr style="border-bottom: 1px solid #f1f5f9;">
                                    <td style="padding: 15px; color: #334155;"><?php echo htmlspecialchars($cliente['cedula']); ?></td>
                                    <td style="padding: 15px; font-weight: bold; color: black;"><?php echo htmlspecialchars($cliente['nombre']); ?></td>
                                    <td style="padding: 15px;">
                                        <?php if(!empty($cliente['telefono'])): ?>
                                            <a href="https://wa.me/58<?php echo preg_replace('/[^0-9]/', '', $cliente['telefono']); ?>" target="_blank" style="color: #10b981; text-decoration: none; display: flex; align-items: center; gap: 5px;">
                                                <span style="font-size: 1.1rem;">📞</span> <?php echo htmlspecialchars($cliente['telefono']); ?>
                                            </a>
                                        <?php else: ?>
                                            <span style="color: #cbd5e1; font-style: italic;">Sin número</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 15px; text-align: center;">
                                        <div style="display: flex; gap: 5px; justify-content: center;">
                                            <button type="button" class="btn-editar" title="Editar este contacto" 
                                                    onclick="cargarParaEditar('<?php echo $cliente['id']; ?>', '<?php echo htmlspecialchars($cliente['cedula'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($cliente['nombre'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($cliente['telefono'], ENT_QUOTES); ?>')">
                                                ✏️
                                            </button>
                                            
                                            <a href="clientes.php?eliminar_id=<?php echo $cliente['id']; ?>" class="btn-accion btn-eliminar" title="Eliminar este contacto" 
                                               onclick="return confirm('¿Estás seguro de que deseas eliminar al cliente <?php echo htmlspecialchars($cliente['nombre'], ENT_QUOTES); ?>? Esta acción no se puede deshacer.')">
                                                🗑️
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                        <?php 
                            endwhile; 
                        else: 
                        ?>
                            <tr>
                                <td colspan="4" style="padding: 30px; text-align: center; color: #64748b; font-style: italic;">
                                    No hay clientes registrados en la base de datos.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </section>
        </div>
    </main>

    <script>
        // --- FUNCIÓN JAVASCRIPT CLAVE PARA LA EDICIÓN ---
        // Se ejecuta al pulsar el botón editar ✏️ en la tabla
        function cargarParaEditar(id, cedula, nombre, telefono) {
            // 1. Cambiamos el título del formulario para indicar edición
            document.getElementById('titulo-formulario').innerText = "✏️ Editando: " + nombre;
            
            // 2. Rellenamos los campos de texto con los datos actuales de la fila
            document.getElementById('cedula_input').value = cedula;
            document.getElementById('nombre_input').value = nombre;
            document.getElementById('telefono_input').value = telefono;
            
            // 3. CAMBIAMOS EL VALOR DEL CAMPO OCULTO.
            // Al hacer el submit POST, el PHP sabrá que debe ejecutar UPDATE en lugar de INSERT.
            document.getElementById('id_cliente_input').value = id;
            
            // 4. Mostramos el botón de cancelar para poder salir del modo edición
            document.getElementById('btn-cancelar-edicion').style.display = 'block';
            
            // Opcional: enfocar el primer campo para facilidad del usuario
            document.getElementById('cedula_input').focus();
            
            // Scroll suave hacia arriba si la tabla es muy larga y el formulario quedó arriba
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        // --- Función para volver el formulario a su estado original (Registrar) ---
        function resetFormulario() {
            document.getElementById('titulo-formulario').innerText = "Registrar / Editar Cliente";
            document.getElementById('form-cliente').reset(); // Limpia campos visibles
            document.getElementById('id_cliente_input').value = ""; // Vacia el campo oculto
            document.getElementById('btn-cancelar-edicion').style.display = 'none'; // Oculta cancelar
        }
    </script>
</body>
</html>