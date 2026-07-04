<?php
    // 1. SEGURIDAD DE SESIÓN Y ROLES
    session_start();
    if (!isset($_SESSION['usuario_id']) || ($_SESSION['usuario_rol'] != 'admin' && $_SESSION['usuario_rol'] != 'almacenista')) {
        header("Location: index.php");
        exit;
    }

    require_once 'conexion.php';
    $mensaje = '';
    $tab_activa = isset($_GET['tab']) ? $_GET['tab'] : 'inventario';

    // =========================================================================
    // 2. AUTO-PARCHE DE BASE DE DATOS
    // =========================================================================
    $columnas_nuevas = [
        'stock_minimo' => "DECIMAL(10,2) NOT NULL DEFAULT 5.00",
        'fecha_vencimiento' => "DATE NULL",
        'proveedor_id' => "INT NULL",
        'precio_mayor_bcv' => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
        'precio_efectivo_detal' => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
        'precio_efectivo_mayor' => "DECIMAL(10,2) NOT NULL DEFAULT 0.00"
    ];
    foreach ($columnas_nuevas as $columna => $definicion) {
        $check = $conexion->query("SHOW COLUMNS FROM productos LIKE '$columna'");
        if ($check && $check->num_rows == 0) {
            $conexion->query("ALTER TABLE productos ADD $columna $definicion");
        }
    }

    $conexion->query("CREATE TABLE IF NOT EXISTS combo_detalles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        combo_id INT NOT NULL,
        producto_id INT NOT NULL,
        cantidad DECIMAL(10,3) NOT NULL,
        FOREIGN KEY (combo_id) REFERENCES productos(id) ON DELETE CASCADE,
        FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE
    )");

    // =========================================================================
    // 3. PROCESAR ACCIONES DEL INVENTARIO
    // =========================================================================
    if (isset($_POST['guardar_producto_integrado'])) {
        $codigo = $conexion->real_escape_string($_POST['codigo_barras']);
        $nombre = $conexion->real_escape_string($_POST['nombre']);
        $precio = floatval($_POST['precio_usd']);
        $p_mayor_bcv = floatval($_POST['precio_mayor_bcv']);
        $p_efectivo_detal = floatval($_POST['precio_efectivo_detal']);
        $p_efectivo_mayor = floatval($_POST['precio_efectivo_mayor']);
        $unidad = $conexion->real_escape_string($_POST['tipo_unidad']);
        $stock_inicial = floatval($_POST['stock_inicial']);
        $stock_minimo = floatval($_POST['stock_minimo']);
        $prov_id = !empty($_POST['proveedor_id']) ? intval($_POST['proveedor_id']) : "NULL";
        $f_vence = !empty($_POST['fecha_vencimiento']) ? "'" . $conexion->real_escape_string($_POST['fecha_vencimiento']) . "'" : "NULL";

        $check_code = $conexion->query("SELECT id FROM productos WHERE codigo_barras = '$codigo'");
        if ($check_code && $check_code->num_rows > 0) {
            $mensaje = "❌ Error: Ya existe un producto con el código '$codigo'.";
        } else {
            $q_insert = "INSERT INTO productos (codigo_barras, nombre, precio_usd, precio_mayor_bcv, precio_efectivo_detal, precio_efectivo_mayor, tipo_unidad, stock, stock_minimo, proveedor_id, fecha_vencimiento) 
                        VALUES ('$codigo', '$nombre', $precio, $p_mayor_bcv, $p_efectivo_detal, $p_efectivo_mayor, '$unidad', $stock_inicial, $stock_minimo, $prov_id, $f_vence)";
            if ($conexion->query($q_insert)) $mensaje = "✅ Producto registrado con éxito.";
            else $mensaje = "❌ Error al guardar: " . $conexion->error;
        }
    }

    if (isset($_POST['editar_producto'])) {
        $id_edit = intval($_POST['producto_id_edit']);
        $codigo = $conexion->real_escape_string($_POST['codigo_barras']);
        $nombre = $conexion->real_escape_string($_POST['nombre']);
        $precio = floatval($_POST['precio_usd']);
        $p_mayor_bcv = floatval($_POST['precio_mayor_bcv']);
        $p_efectivo_detal = floatval($_POST['precio_efectivo_detal']);
        $p_efectivo_mayor = floatval($_POST['precio_efectivo_mayor']);
        $unidad = $conexion->real_escape_string($_POST['tipo_unidad']);
        $stock_actual = floatval($_POST['stock_actual']); 
        $stock_minimo = floatval($_POST['stock_minimo']);
        $prov_id = !empty($_POST['proveedor_id']) ? intval($_POST['proveedor_id']) : "NULL";
        $f_vence = !empty($_POST['fecha_vencimiento']) ? "'" . $conexion->real_escape_string($_POST['fecha_vencimiento']) . "'" : "NULL";

        $check_code = $conexion->query("SELECT id FROM productos WHERE codigo_barras = '$codigo' AND id != $id_edit");
        if ($check_code && $check_code->num_rows > 0) {
            $mensaje = "❌ Error: El código '$codigo' ya está siendo usado.";
        } else {
            $q_update = "UPDATE productos SET codigo_barras='$codigo', nombre='$nombre', precio_usd=$precio, precio_mayor_bcv=$p_mayor_bcv, precio_efectivo_detal=$p_efectivo_detal, precio_efectivo_mayor=$p_efectivo_mayor, tipo_unidad='$unidad', stock=$stock_actual, stock_minimo=$stock_minimo, proveedor_id=$prov_id, fecha_vencimiento=$f_vence WHERE id=$id_edit";
            if ($conexion->query($q_update)) $mensaje = "✏️ Datos actualizados correctamente.";
            else $mensaje = "❌ Error al actualizar: " . $conexion->error;
        }
    }

    if (isset($_POST['eliminar_producto'])) {
        $id_eliminar = intval($_POST['producto_id_eliminar']);
        $check_stock = $conexion->query("SELECT stock, nombre FROM productos WHERE id = $id_eliminar");
        if ($check_stock && $check_stock->num_rows > 0) {
            $prod_data = $check_stock->fetch_assoc();
            if (floatval($prod_data['stock']) > 0) {
                $mensaje = "❌ Acción denegada: Aún tienes inventario.";
            } else {
                try {
                    if ($conexion->query("DELETE FROM productos WHERE id = $id_eliminar")) $mensaje = "🗑️ Producto eliminado.";
                    else $mensaje = "❌ Error: Producto amarrado a historial de ventas.";
                } catch (Exception $e) { $mensaje = "❌ Error: Producto amarrado a historial de ventas."; }
            }
        }
    }

    // =========================================================================
    // 4. PROCESAR ACCIONES DE RECETAS Y COMBOS
    // =========================================================================
    
    // Crear un Combo Maestro Nuevo
    if (isset($_POST['crear_combo_directo'])) {
        $nombre_combo = $conexion->real_escape_string(trim($_POST['nombre_combo']));
        $precio_combo = floatval($_POST['precio_combo']);
        $codigo_combo = 'CMB-' . rand(10000, 99999); 

        $q_insert_combo = "INSERT INTO productos (codigo_barras, nombre, precio_usd, stock, stock_minimo, tipo_unidad) 
                           VALUES ('$codigo_combo', 'COMBO: $nombre_combo', $precio_combo, 9999, 0, 'und')";
        if ($conexion->query($q_insert_combo)) {
            header("Location: productos.php?tab=combos&msg=combo_creado"); exit;
        } else {
            $mensaje = "❌ Error al crear el combo: " . $conexion->error;
        }
    }

    // Editar un Combo Maestro (Nombre y Precio)
    if (isset($_POST['editar_combo_maestro'])) {
        $id_combo = intval($_POST['combo_id_edit']);
        $nombre_combo = $conexion->real_escape_string(trim($_POST['nombre_combo']));
        $precio_combo = floatval($_POST['precio_combo']);
        $nombre_final = 'COMBO: ' . $nombre_combo;

        $q_update = "UPDATE productos SET nombre='$nombre_final', precio_usd=$precio_combo WHERE id=$id_combo";
        if ($conexion->query($q_update)) {
            header("Location: productos.php?tab=combos&msg=combo_editado"); exit;
        } else {
            $mensaje = "❌ Error al actualizar combo: " . $conexion->error;
        }
    }

    // Eliminar un Combo Maestro Completo
    if (isset($_POST['eliminar_combo_maestro'])) {
        $id_combo = intval($_POST['combo_id_eliminar']);
        $conexion->query("DELETE FROM combo_detalles WHERE combo_id = $id_combo");
        try {
            if ($conexion->query("DELETE FROM productos WHERE id = $id_combo")) {
                header("Location: productos.php?tab=combos&msg=combo_eliminado"); exit;
            } else {
                header("Location: productos.php?tab=combos&msg=error_eliminar"); exit;
            }
        } catch (Exception $e) {
            header("Location: productos.php?tab=combos&msg=error_eliminar"); exit;
        }
    }

    // Asignar ingredientes a un combo
    if (isset($_POST['agregar_ingrediente'])) {
        $combo_id = intval($_POST['combo_id']);
        $ingrediente_id = intval($_POST['ingrediente_id']);
        $cantidad = floatval($_POST['cantidad_ingrediente']);

        if ($combo_id == $ingrediente_id) {
            $mensaje = "❌ Error: Un combo no puede ser ingrediente de sí mismo.";
        } else {
            $q_insert = "INSERT INTO combo_detalles (combo_id, producto_id, cantidad) VALUES ($combo_id, $ingrediente_id, $cantidad)";
            if ($conexion->query($q_insert)) {
                header("Location: productos.php?tab=combos&msg=agregado"); exit;
            } else {
                $mensaje = "❌ Error al agregar ingrediente: " . $conexion->error;
            }
        }
    }

    // Quitar ingrediente de un combo
    if (isset($_GET['eliminar_detalle'])) {
        $id_detalle = intval($_GET['eliminar_detalle']);
        $conexion->query("DELETE FROM combo_detalles WHERE id = $id_detalle");
        header("Location: productos.php?tab=combos&msg=eliminado"); exit;
    }

    // Mensajes de confirmación
    if (isset($_GET['msg'])) {
        if ($_GET['msg'] == 'eliminado') $mensaje = "🗑️ Ingrediente removido del combo.";
        if ($_GET['msg'] == 'agregado') $mensaje = "✅ Ingrediente añadido exitosamente a la receta.";
        if ($_GET['msg'] == 'combo_creado') $mensaje = "🍔 ¡Combo creado! Ahora añádele los ingredientes.";
        if ($_GET['msg'] == 'combo_editado') $mensaje = "✏️ Datos del combo actualizados.";
        if ($_GET['msg'] == 'combo_eliminado') $mensaje = "🗑️ Combo eliminado del sistema por completo.";
        if ($_GET['msg'] == 'error_eliminar') $mensaje = "❌ No se puede borrar este combo porque ya tiene ventas registradas en el historial.";
        $tab_activa = 'combos';
    }

    // =========================================================================
    // 5. CONSULTAS BASE PARA LA VISTA
    // =========================================================================
    $query_tasa = "SELECT tasa_dia FROM configuracion ORDER BY id DESC LIMIT 1";
    $tasa = ($res = $conexion->query($query_tasa)) ? ($res->fetch_assoc()['tasa_dia'] ?? 36.50) : 36.50;

    $q_valorizacion = $conexion->query("SELECT SUM(stock * precio_usd) as total_usd FROM productos WHERE stock > 0 AND nombre NOT LIKE 'COMBO:%'");
    $total_inventario_usd = ($q_valorizacion) ? floatval($q_valorizacion->fetch_assoc()['total_usd']) : 0;
    $total_inventario_bs = $total_inventario_usd * $tasa;

    $prov_array = [];
    $lista_proveedores = $conexion->query("SELECT id, empresa FROM proveedores ORDER BY empresa ASC");
    if ($lista_proveedores) { while($pr = $lista_proveedores->fetch_assoc()) { $prov_array[] = $pr; } }

    $lista_productos_combo = [];
    $lista_combos_maestros = [];
    $resultado_productos = $conexion->query("SELECT p.*, prov.empresa as nombre_proveedor FROM productos p LEFT JOIN proveedores prov ON p.proveedor_id = prov.id ORDER BY p.nombre ASC");
    
    if ($resultado_productos) {
        while($p = $resultado_productos->fetch_assoc()) { 
            if (strpos($p['nombre'], 'COMBO:') === 0) {
                $lista_combos_maestros[] = $p; 
            } else {
                $lista_productos_combo[] = $p; 
            }
        }
        $resultado_productos->data_seek(0);
    }

    $query_combos_activos = "SELECT cd.id as detalle_id, pc.nombre as combo_nombre, pi.nombre as ingrediente_nombre, cd.cantidad, pi.tipo_unidad FROM combo_detalles cd JOIN productos pc ON cd.combo_id = pc.id JOIN productos pi ON cd.producto_id = pi.id ORDER BY pc.nombre ASC";
    $detalles_combo = $conexion->query($query_combos_activos);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventario y Combos - Mi Negocio POS</title>
    <link rel="stylesheet" href="css/style.css?v=<?php echo time(); ?>">
    <style>
        /* TABLA COMPACTA Y ELEGANTE */
        table#tabla-productos { border-collapse: collapse; width: 100%; }
        table#tabla-productos th, table#tabla-productos td { padding: 8px 12px; vertical-align: middle; border-bottom: 1px solid #e2e8f0; font-size: 0.95rem; }
        table#tabla-productos th { background-color: #f8fafc; color: #475569; font-size: 0.85rem; text-transform: uppercase; }
        
        .barra-filtros { background: white; padding: 1.2rem; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); border: 1px solid var(--borde); margin-bottom: 1.5rem; display: flex; flex-direction: column; gap: 1rem; }
        .grupo-botones { display: flex; gap: 0.5rem; flex-wrap: wrap; }
        .btn-filtro { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; padding: 0.5rem 1rem; border-radius: 6px; font-weight: bold; cursor: pointer; transition: 0.2s; }
        .btn-filtro.activo { background: #1e3a8a; color: white; border-color: #1e3a8a; }
        .badge-stock { padding: 2px 6px; border-radius: 4px; font-weight: bold; font-size: 0.85rem; }
        .bg-critico { background: #fee2e2; color: #ef4444; }
        .bg-advertencia { background: #fef3c7; color: #d97706; }
        .bg-ok { background: #d1fae5; color: #10b981; }
        .btn-accion { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; padding: 0.4rem 0.6rem; border-radius: 4px; cursor: pointer; font-size: 0.85rem; transition: 0.2s; }
        .btn-accion:hover { background: #e2e8f0; }

        .tabs-contenedor { display: flex; gap: 0.5rem; margin-bottom: 1.5rem; border-bottom: 2px solid #cbd5e1; padding-bottom: 0.5rem; }
        .btn-tab { background: transparent; color: #64748b; border: none; font-size: 1.1rem; font-weight: bold; padding: 0.5rem 1rem; cursor: pointer; border-bottom: 3px solid transparent; transition: 0.2s; }
        .btn-tab:hover { color: #1e3a8a; }
        .btn-tab.activo { color: #3b82f6; border-bottom-color: #3b82f6; }

        @media print {
            body { background: white !important; color: black !important; padding: 0 !important; font-family: Arial, sans-serif !important; }
            .top-bar, .tabs-contenedor, .tarjeta-capital, .ocultar-impresion, .btn-login, .barra-filtros, form, .badge-rol, .btn-accion, #panel-combos, .modal-fondo { display: none !important; }
            .contenedor-menu { max-width: 100% !important; padding: 0 !important; margin: 0 !important; }
            table#tabla-productos { border-collapse: collapse !important; width: 100% !important; margin-top: 10px !important; }
            table#tabla-productos th, table#tabla-productos td { border: 1px solid #000 !important; padding: 6px !important; color: black !important; font-size: 14px !important; text-align: left; }
            .badge-stock { background: transparent !important; color: black !important; padding: 0 !important; font-weight: normal; }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        }
    </style>
</head>
<body style="background-color: #f1f5f9;">
    <header class="top-bar">
        <div style="display: flex; align-items: center; gap: 1rem;">
            <a href="menu.php" style="text-decoration: none; font-size: 1.5rem; color: var(--texto-principal);" title="Volver al Menú">⬅️</a>
            <h1>Gestión de Almacén y Combos</h1>
        </div>
        <div style="display: flex; align-items: center; gap: 1rem;">
            <span style="color: var(--texto-secundario);">Usuario: <strong style="color: var(--primario);"><?php echo $_SESSION['usuario_nombre']; ?></strong></span>
            <span class="badge-rol"><?php echo strtoupper($_SESSION['usuario_rol']); ?></span>
        </div>
    </header>

    <main class="contenedor-menu" style="max-width: 1400px; padding: 0 2rem; box-sizing: border-box;">
        
        <?php if($mensaje): ?>
            <div class="ocultar-impresion" style="background: #eff6ff; color: #1e40af; padding: 1rem; border-radius: 6px; margin-bottom: 1.5rem; font-weight: bold; border-left: 4px solid #3b82f6;"><?php echo $mensaje; ?></div>
        <?php endif; ?>

        <div class="tabs-contenedor ocultar-impresion">
            <button id="btn-tab-inventario" class="btn-tab <?php echo ($tab_activa == 'inventario') ? 'activo' : ''; ?>" onclick="cambiarTab('inventario')">📦 Inventario General</button>
            <button id="btn-tab-combos" class="btn-tab <?php echo ($tab_activa == 'combos') ? 'activo' : ''; ?>" onclick="cambiarTab('combos')">🍔 Recetas y Combos</button>
        </div>

        <!-- ========================================== -->
        <!-- PANEL 1: INVENTARIO GENERAL -->
        <!-- ========================================== -->
        <div id="panel-inventario" style="display: <?php echo ($tab_activa == 'inventario') ? 'block' : 'none'; ?>;">
            
            <div class="tarjeta-capital ocultar-impresion" style="background: white; padding: 1.5rem; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); margin-bottom: 1.5rem; border-left: 5px solid #10b981; display: inline-block; min-width: 320px;">
                <h3 style="color: #64748b; font-size: 0.9rem; text-transform: uppercase; margin: 0; margin-bottom: 0.5rem;">Capital en Mercancía</h3>
                <div style="font-size: 1.8rem; font-weight: bold; color: #065f46;">$<?php echo number_format($total_inventario_usd, 2); ?></div>
                <div style="color: #10b981; font-weight: bold; font-size: 1rem;"><?php echo number_format($total_inventario_bs, 2, ',', '.'); ?> Bs</div>
            </div>

            <div style="display: flex; gap: 1rem; margin-bottom: 1.5rem;" class="ocultar-impresion">
                <button class="btn-login" style="width: auto; margin-top: 0; padding: 0.8rem 1.5rem; background-color: #3b82f6; color: white; font-weight: bold;" onclick="abrirModal('modal-nuevo')">➕ Nuevo Producto</button>
                <button class="btn-login" style="width: auto; margin-top: 0; padding: 0.8rem 1.5rem; background-color: #f59e0b; color: white; font-weight: bold;" onclick="abrirModal('modal-entrada')">📦 Registrar Entrada</button>
                <button class="btn-login" style="width: auto; margin-top: 0; padding: 0.8rem 1.5rem; background-color: #64748b; color: white; font-weight: bold;" onclick="window.print()">🖨️ Imprimir Inventario</button>
            </div>

            <div class="barra-filtros ocultar-impresion">
                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1rem;">
                    <input type="text" id="buscar-tabla" placeholder="🔍 Escanear código o buscar artículo..." class="input-cliente" style="width: 100%; padding: 0.7rem; box-sizing: border-box;" onkeyup="filtrarInventario()">
                    <select id="filtro-proveedor" class="input-cliente" style="width: 100%; padding: 0.7rem;" onchange="filtrarInventario()">
                        <option value="">🏢 Filtrar por Proveedor</option>
                        <?php foreach($prov_array as $pr): ?>
                            <option value="<?php echo $pr['id']; ?>"><?php echo htmlspecialchars($pr['empresa']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; border-top: 1px dashed #e2e8f0; padding-top: 1rem;">
                    <div>
                        <span style="font-size: 0.85rem; font-weight: bold; color: #64748b; display: block; margin-bottom: 0.3rem;">ESTADO DEL STOCK:</span>
                        <div class="grupo-botones">
                            <button class="btn-filtro activo" data-tipo="stock" data-val="todos" onclick="cambiarFiltroBoton(this)">Todos</button>
                            <button class="btn-filtro" data-tipo="stock" data-val="bajo" onclick="cambiarFiltroBoton(this)">⚠️ Bajos</button>
                            <button class="btn-filtro" data-tipo="stock" data-val="agotado" onclick="cambiarFiltroBoton(this)">🔴 Agotados</button>
                        </div>
                    </div>
                    <div>
                        <span style="font-size: 0.85rem; font-weight: bold; color: #64748b; display: block; margin-bottom: 0.3rem;">VENCIMIENTOS:</span>
                        <div class="grupo-botones">
                            <button class="btn-filtro activo" data-tipo="vence" data-val="todos" onclick="cambiarFiltroBoton(this)">Todos</button>
                            <button class="btn-filtro" data-tipo="vence" data-val="por_vencer" onclick="cambiarFiltroBoton(this)">⏳ Por Vencer</button>
                            <button class="btn-filtro" data-tipo="vence" data-val="vencido" onclick="cambiarFiltroBoton(this)">❌ Vencidos</button>
                        </div>
                    </div>
                </div>
            </div>

            <div style="background: white; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                <table class="tabla-inventario" id="tabla-productos">
                    <thead>
                        <tr>
                            <th style="width: 15%;">CÓDIGO</th>
                            <th style="width: 35%;">NOMBRE DEL PRODUCTO</th>
                            <th class="ocultar-impresion">PROVEEDOR</th>
                            <th style="width: 10%;">DISP.</th>
                            <th class="ocultar-impresion" style="width: 12%;">VENCIMIENTO</th>
                            <th style="width: 15%;" class="col-precio-print">PRECIO ($ BCV)</th>
                            <th style="text-align: center; width: 10%;" class="ocultar-impresion">ACCIONES</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if ($resultado_productos && $resultado_productos->num_rows > 0):
                            $hoy = date('Y-m-d');
                            $limite_alerta = date('Y-m-d', strtotime('+30 days'));

                            while ($producto = $resultado_productos->fetch_assoc()): 
                                if (strpos($producto['nombre'], 'COMBO:') === 0) continue;

                                $id_prod = $producto['id'];
                                $stock_db = floatval($producto['stock']);
                                $minimo = floatval($producto['stock_minimo']);
                                $unidad = ($producto['tipo_unidad'] == 'kg') ? 'Kg' : (($producto['tipo_unidad'] == 'm') ? 'm' : 'Und');
                                
                                $stock_mostrar = ($producto['tipo_unidad'] == 'kg' || $producto['tipo_unidad'] == 'm') ? number_format($stock_db, 2, ',', '.') : number_format($stock_db, 0, ',', '.');
                                
                                $stock_status = 'ok'; $clase_estado = 'bg-ok';
                                if ($stock_db <= 0) { $stock_status = 'agotado'; $clase_estado = 'bg-critico'; } 
                                elseif ($stock_db <= $minimo) { $stock_status = 'bajo'; $clase_estado = 'bg-advertencia'; }

                                $vence_status = 'ok'; $vence_texto = '-'; $vence_color = '#64748b'; $fecha_cruda = '';
                                if (!empty($producto['fecha_vencimiento'])) {
                                    $fecha_cruda = $producto['fecha_vencimiento'];
                                    $vence_texto = date('d/m/Y', strtotime($producto['fecha_vencimiento']));
                                    if ($producto['fecha_vencimiento'] <= $hoy) { $vence_status = 'vencido'; $vence_color = '#ef4444'; } 
                                    elseif ($producto['fecha_vencimiento'] <= $limite_alerta) { $vence_status = 'por_vencer'; $vence_color = '#d97706'; }
                                }
                        ?>
                            <tr class="fila-producto" data-prov-id="<?php echo $producto['proveedor_id']; ?>" data-stock-status="<?php echo $stock_status; ?>" data-vence-status="<?php echo $vence_status; ?>">
                                
                                <td class="col-codigo" style="font-family: monospace; color: #475569;"><?php echo htmlspecialchars($producto['codigo_barras']); ?></td>
                                <td class="col-nombre" style="font-weight: bold; color: #1e293b;"><?php echo htmlspecialchars($producto['nombre']); ?></td>
                                <td class="ocultar-impresion" style="font-size: 0.85rem; color: #64748b;"><?php echo $producto['nombre_proveedor'] ?: '<em>S/N</em>'; ?></td>
                                
                                <td>
                                    <span class="badge-stock <?php echo $clase_estado; ?>"><?php echo $stock_mostrar; ?> <?php echo $unidad; ?></span>
                                </td>
                                
                                <td class="ocultar-impresion" style="font-weight: bold; font-size: 0.85rem; color: <?php echo $vence_color; ?>;">
                                    <?php echo $vence_texto; ?>
                                </td>
                                
                                <td style="vertical-align: middle;">
                                    <div style="font-weight: bold; font-size: 1.1rem; color: #065f46;">$<?php echo number_format($producto['precio_usd'], 2); ?></div>
                                    <div class="ocultar-impresion" style="font-size: 0.8rem; color: #64748b; margin-top: 2px;"><?php echo number_format($producto['precio_usd'] * $tasa, 2, ',', '.'); ?> Bs</div>
                                </td>
                                
                                <td class="ocultar-impresion" style="display: flex; gap: 0.4rem; justify-content: center;">
                                    <button class="btn-accion" title="Editar / Ver todos los precios" onclick="abrirModalEditar(<?php echo $id_prod; ?>, '<?php echo addslashes($producto['codigo_barras']); ?>', '<?php echo addslashes($producto['nombre']); ?>', <?php echo $producto['precio_usd']; ?>, <?php echo $stock_db; ?>, <?php echo $minimo; ?>, '<?php echo $producto['proveedor_id']; ?>', '<?php echo $fecha_cruda; ?>', '<?php echo $producto['tipo_unidad']; ?>', <?php echo $producto['precio_mayor_bcv']; ?>, <?php echo $producto['precio_efectivo_detal']; ?>, <?php echo $producto['precio_efectivo_mayor']; ?>)">✏️</button>
                                    <form method="POST" style="margin: 0; padding: 0;" onsubmit="return confirm('¿Seguro deseas ELIMINAR esto?');">
                                        <input type="hidden" name="producto_id_eliminar" value="<?php echo $id_prod; ?>">
                                        <button type="submit" name="eliminar_producto" class="btn-accion" style="color: #ef4444; border-color: #fca5a5;" title="Eliminar">❌</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; else: ?>
                            <tr><td colspan="7" style="text-align: center; padding: 2rem;">No hay productos registrados.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- PANEL 2: RECETAS Y COMBOS (NUEVO DISEÑO LÓGICO) -->
        <!-- ========================================== -->
        <div id="panel-combos" class="ocultar-impresion" style="display: <?php echo ($tab_activa == 'combos') ? 'block' : 'none'; ?>;">
            
            <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 2rem;">
                
                <!-- PASO 1: CREAR EL COMBO -->
                <div style="background: white; padding: 1.5rem; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); border-top: 5px solid #3b82f6; height: fit-content;">
                    <h3 style="margin-bottom: 0.5rem; color: #1e3a8a;">1️⃣ Crear Nuevo Combo</h3>
                    <p style="color: #64748b; font-size: 0.85rem; margin-bottom: 1rem;">Dale un nombre y un precio especial a tu combo. Luego podrás asignarle los ingredientes al lado derecho.</p>
                    
                    <form method="POST" action="productos.php?tab=combos">
                        <div style="margin-bottom: 1rem;">
                            <label style="font-weight: bold; font-size: 0.85rem;">Nombre del Combo (Ej: Hamburguesa + Refresco):</label>
                            <input type="text" name="nombre_combo" required class="input-cliente" style="width: 100%; padding: 0.8rem; box-sizing: border-box;" placeholder="Escribe el nombre aquí...">
                        </div>
                        <div style="margin-bottom: 1rem;">
                            <label style="font-weight: bold; font-size: 0.85rem; color: #065f46;">Precio Especial de Venta ($ BCV):</label>
                            <input type="number" step="0.01" name="precio_combo" required class="input-cliente" style="width: 100%; padding: 0.8rem; box-sizing: border-box;" placeholder="Ej: 5.00">
                        </div>
                        <button type="submit" name="crear_combo_directo" class="btn-login" style="background: #3b82f6; width: 100%; margin: 0; padding: 0.8rem;">Crear Combo</button>
                    </form>
                </div>

                <!-- PASO 2: ASIGNAR INGREDIENTES A COMBOS EXISTENTES -->
                <div style="background: white; padding: 1.5rem; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); border-top: 5px solid #f59e0b;">
                    <h3 style="margin-bottom: 0.5rem; color: #d97706;">2️⃣ Armar la Receta del Combo</h3>
                    <p style="color: #64748b; font-size: 0.85rem; margin-bottom: 1rem;">
                        Selecciona un combo y dile al sistema qué productos reales (y qué cantidad) debe descontar de tu inventario al venderlo.
                    </p>
                    
                    <form method="POST" action="productos.php?tab=combos" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; align-items: end;">
                        <div style="grid-column: span 2;">
                            <label style="font-weight: bold; font-size: 0.85rem;">1. Elige el Combo:</label>
                            <select name="combo_id" required class="input-cliente" style="width: 100%; padding: 0.8rem; background: #fffbeb; border: 1px solid #fcd34d;">
                                <option value="">-- Selecciona el Combo --</option>
                                <?php foreach($lista_combos_maestros as $c): ?>
                                    <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars(str_replace('COMBO: ', '', $c['nombre'])); ?> (Venta: $<?php echo $c['precio_usd']; ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label style="font-weight: bold; font-size: 0.85rem;">2. Elige el Ingrediente real:</label>
                            <select name="ingrediente_id" required class="input-cliente" style="width: 100%; padding: 0.8rem;">
                                <option value="">-- Buscar en Inventario --</option>
                                <?php foreach($lista_productos_combo as $p): ?>
                                    <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['nombre']); ?> (<?php echo $p['tipo_unidad']; ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="display: flex; gap: 1rem; align-items: end;">
                            <div style="flex: 1;">
                                <label style="font-weight: bold; font-size: 0.85rem;">3. Cantidad a restar:</label>
                                <input type="number" step="0.001" name="cantidad_ingrediente" required placeholder="Ej: 1" class="input-cliente" style="width: 100%; padding: 0.8rem; box-sizing: border-box;">
                            </div>
                            <button type="submit" name="agregar_ingrediente" class="btn-login" style="background: #10b981; margin: 0; padding: 0.8rem; flex: 1;">Añadir</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- GRILLA DE GESTIÓN (MIS COMBOS Y RECETAS ARMADAS) -->
            <div style="display: grid; grid-template-columns: 1fr 1.5fr; gap: 2rem; margin-top: 2rem;">
                
                <!-- TABLA MIS COMBOS (Editar y Borrar Combos) -->
                <div style="background: white; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); overflow: hidden; align-self: start;">
                    <h3 style="padding: 1.5rem; margin: 0; background: #f8fafc; border-bottom: 2px solid #e2e8f0; color: #1e3a8a;">🍔 Mis Combos (Gestión)</h3>
                    <table style="width: 100%; border-collapse: collapse; text-align: left;">
                        <thead>
                            <tr>
                                <th style="padding: 1rem; background: white; border-bottom: 1px solid #e2e8f0;">Nombre</th>
                                <th style="padding: 1rem; background: white; border-bottom: 1px solid #e2e8f0;">Precio Venta</th>
                                <th style="padding: 1rem; background: white; border-bottom: 1px solid #e2e8f0; text-align: center;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($lista_combos_maestros) > 0): ?>
                                <?php foreach ($lista_combos_maestros as $c): ?>
                                    <tr style="border-bottom: 1px solid #f1f5f9;">
                                        <td style="padding: 1rem; font-weight: bold; color: #3b82f6;"><?php echo htmlspecialchars(str_replace('COMBO: ', '', $c['nombre'])); ?></td>
                                        <td style="padding: 1rem; font-weight: bold; color: #065f46;">$<?php echo number_format($c['precio_usd'], 2); ?></td>
                                        <td style="padding: 1rem; text-align: center; display: flex; gap: 0.5rem; justify-content: center;">
                                            <button onclick="abrirModalEditarCombo(<?php echo $c['id']; ?>, '<?php echo addslashes($c['nombre']); ?>', <?php echo $c['precio_usd']; ?>)" class="btn-accion" title="Editar Nombre y Precio">✏️</button>
                                            <form method="POST" action="productos.php?tab=combos" style="margin: 0;" onsubmit="return confirm('¿Seguro deseas borrar este combo por completo?');">
                                                <input type="hidden" name="combo_id_eliminar" value="<?php echo $c['id']; ?>">
                                                <button type="submit" name="eliminar_combo_maestro" class="btn-accion" style="color: #ef4444; border-color: #fca5a5;" title="Eliminar Combo">❌</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="3" style="padding: 2rem; text-align: center; color: #64748b;">No has creado ningún combo.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- TABLA RECETAS ARMADAS -->
                <div style="background: white; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); overflow: hidden; align-self: start;">
                    <h3 style="padding: 1.5rem; margin: 0; background: #f8fafc; border-bottom: 2px solid #e2e8f0; color: #d97706;">📋 Ingredientes por Combo</h3>
                    <table style="width: 100%; border-collapse: collapse; text-align: left;">
                        <thead>
                            <tr>
                                <th style="padding: 1rem; background: white; border-bottom: 1px solid #e2e8f0;">Combo Perteneciente</th>
                                <th style="padding: 1rem; background: white; border-bottom: 1px solid #e2e8f0;">Descuenta Producto</th>
                                <th style="padding: 1rem; background: white; border-bottom: 1px solid #e2e8f0;">Cantidad</th>
                                <th style="padding: 1rem; background: white; border-bottom: 1px solid #e2e8f0; text-align: center;">Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($detalles_combo && $detalles_combo->num_rows > 0): ?>
                                <?php while ($d = $detalles_combo->fetch_assoc()): ?>
                                    <tr style="border-bottom: 1px solid #f1f5f9;">
                                        <td style="padding: 1rem; font-weight: bold; color: #1e3a8a;"><?php echo htmlspecialchars(str_replace('COMBO: ', '', $d['combo_nombre'])); ?></td>
                                        <td style="padding: 1rem; color: #475569;">🍔 <?php echo htmlspecialchars($d['ingrediente_nombre']); ?></td>
                                        <td style="padding: 1rem; font-weight: bold; color: #ef4444;">
                                            -<?php echo floatval($d['cantidad']) . ' ' . ($d['tipo_unidad'] == 'kg' ? 'Kg' : ($d['tipo_unidad'] == 'm' ? 'm' : 'Und')); ?>
                                        </td>
                                        <td style="padding: 1rem; text-align: center;">
                                            <a href="productos.php?tab=combos&eliminar_detalle=<?php echo $d['detalle_id']; ?>" onclick="return confirm('¿Seguro que deseas quitar este ingrediente de la receta?')" class="btn-accion" style="color: #ef4444; border-color: #fca5a5; text-decoration: none;">❌ Quitar</a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="4" style="padding: 2rem; text-align: center; color: #64748b;">Los combos no tienen ingredientes asignados.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </div>
        </div>

    </main>

    <!-- ========================================== -->
    <!-- MODALES DE REGISTRO, EDICIÓN Y ENTRADA -->
    <!-- ========================================== -->
    <div id="modal-nuevo" class="modal-fondo ocultar-impresion" style="display: none;">
        <div class="modal-caja" style="max-width: 550px; padding: 2rem; background: white; border-radius: 10px;">
            <div class="modal-cabecera">
                <h3 style="color: #1e3a8a; margin: 0;">📦 Registrar Nuevo Artículo</h3>
                <button class="btn-cerrar" onclick="cerrarModal('modal-nuevo')" style="background: none; border: none; font-size: 1.2rem; cursor: pointer;">X</button>
            </div>
            <form method="POST" style="margin-top: 1rem;">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                    <div><label style="font-size: 0.85rem; font-weight: bold;">Código de Barras</label><input type="text" name="codigo_barras" required class="input-cliente" style="width: 100%; box-sizing: border-box; padding: 0.6rem;"></div>
                    <div>
                        <label style="font-size: 0.85rem; font-weight: bold;">Tipo de Unidad</label>
                        <select name="tipo_unidad" class="input-cliente" style="width: 100%; padding: 0.6rem;">
                            <option value="und">Unidad (Und)</option>
                            <option value="kg">Kilo (Kg)</option>
                            <option value="m">Metros (m)</option>
                        </select>
                    </div>
                </div>

                <div style="margin-bottom: 1rem;">
                    <label style="font-size: 0.85rem; font-weight: bold;">Descripción del Producto</label>
                    <input type="text" name="nombre" required class="input-cliente" style="width: 100%; box-sizing: border-box; padding: 0.6rem;">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem; background: #f0fdf4; padding: 1rem; border-radius: 6px; border: 1px dashed #bbf7d0;">
                    <div><label style="font-size: 0.85rem; font-weight: bold; color: #166534;">Precio Detal BCV ($)</label><input type="number" step="0.01" name="precio_usd" required class="input-cliente" style="width: 100%; padding: 0.6rem; box-sizing: border-box;"></div>
                    <div><label style="font-size: 0.85rem; font-weight: bold; color: #1e40af;">Precio Mayor BCV ($)</label><input type="number" step="0.01" name="precio_mayor_bcv" value="0.00" required class="input-cliente" style="width: 100%; padding: 0.6rem; box-sizing: border-box;"></div>
                    <div><label style="font-size: 0.85rem; font-weight: bold; color: #9a3412;">Precio Detal Efec ($)</label><input type="number" step="0.01" name="precio_efectivo_detal" value="0.00" required class="input-cliente" style="width: 100%; padding: 0.6rem; box-sizing: border-box;"></div>
                    <div><label style="font-size: 0.85rem; font-weight: bold; color: #581c87;">Precio Mayor Efec ($)</label><input type="number" step="0.01" name="precio_efectivo_mayor" value="0.00" required class="input-cliente" style="width: 100%; padding: 0.6rem; box-sizing: border-box;"></div>
                </div>

                <div style="margin-bottom: 1rem;">
                    <label style="font-size: 0.85rem; font-weight: bold;">Proveedor</label>
                    <select name="proveedor_id" class="input-cliente" style="width: 100%; padding: 0.6rem;">
                        <option value="">-- Ninguno --</option>
                        <?php foreach($prov_array as $pr): ?>
                            <option value="<?php echo $pr['id']; ?>"><?php echo htmlspecialchars($pr['empresa']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem; background: #f8fafc; padding: 1rem; border-radius: 6px; border: 1px dashed #cbd5e1;">
                    <div><label style="font-size: 0.85rem; font-weight: bold; color: #10b981;">Stock Inicial Actual</label><input type="number" step="0.001" name="stock_inicial" required class="input-cliente" value="0" style="width: 100%; padding: 0.6rem; box-sizing: border-box;"></div>
                    <div><label style="font-size: 0.85rem; font-weight: bold; color: #f59e0b;">Alerta Stock Mínimo</label><input type="number" step="0.001" name="stock_minimo" required class="input-cliente" value="5" style="width: 100%; padding: 0.6rem; box-sizing: border-box;"></div>
                </div>

                <div style="margin-bottom: 1.5rem;">
                    <label style="font-size: 0.85rem; font-weight: bold; color: #ef4444;">Fecha de Vencimiento (Opcional)</label>
                    <input type="date" name="fecha_vencimiento" class="input-cliente" style="width: 100%; box-sizing: border-box; padding: 0.6rem;">
                </div>

                <button type="submit" name="guardar_producto_integrado" class="btn-login" style="width: 100%; margin: 0; background: #3b82f6; color: white; font-weight: bold; padding: 0.8rem;">Guardar Producto</button>
            </form>
        </div>
    </div>

    <div id="modal-editar" class="modal-fondo ocultar-impresion" style="display: none;">
        <div class="modal-caja" style="max-width: 550px; padding: 2rem; background: white; border-radius: 10px;">
            <div class="modal-cabecera">
                <h3 style="color: #f59e0b; margin: 0;">✏️ Editar Artículo o Precios</h3>
                <button class="btn-cerrar" onclick="cerrarModal('modal-editar')" style="background: none; border: none; font-size: 1.2rem; cursor: pointer;">X</button>
            </div>
            <form method="POST" style="margin-top: 1rem;">
                <input type="hidden" name="producto_id_edit" id="edit-id">
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                    <div><label style="font-size: 0.85rem; font-weight: bold;">Código Barras</label><input type="text" name="codigo_barras" id="edit-codigo" required class="input-cliente" style="width: 100%; padding: 0.6rem; box-sizing: border-box;"></div>
                    <div>
                        <label style="font-size: 0.85rem; font-weight: bold;">Tipo Unidad</label>
                        <select name="tipo_unidad" id="edit-unidad" class="input-cliente" style="width: 100%; padding: 0.6rem;">
                            <option value="und">Unidad (Und)</option>
                            <option value="kg">Kilo (Kg)</option>
                            <option value="m">Metros (m)</option>
                        </select>
                    </div>
                </div>

                <div style="margin-bottom: 1rem;">
                    <label style="font-size: 0.85rem; font-weight: bold;">Descripción del Producto</label>
                    <input type="text" name="nombre" id="edit-nombre" required class="input-cliente" style="width: 100%; box-sizing: border-box; padding: 0.6rem;">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem; background: #fffbeb; padding: 1rem; border-radius: 6px; border: 1px dashed #fcd34d;">
                    <div><label style="font-size: 0.85rem; font-weight: bold; color: #166534;">Precio Detal BCV ($)</label><input type="number" step="0.01" name="precio_usd" id="edit-precio" required class="input-cliente" style="width: 100%; padding: 0.6rem; box-sizing: border-box;"></div>
                    <div><label style="font-size: 0.85rem; font-weight: bold; color: #1e40af;">Precio Mayor BCV ($)</label><input type="number" step="0.01" name="precio_mayor_bcv" id="edit-precio-mayor-bcv" required class="input-cliente" style="width: 100%; padding: 0.6rem; box-sizing: border-box;"></div>
                    <div><label style="font-size: 0.85rem; font-weight: bold; color: #9a3412;">Precio Detal Efec ($)</label><input type="number" step="0.01" name="precio_efectivo_detal" id="edit-precio-efectivo-detal" required class="input-cliente" style="width: 100%; padding: 0.6rem; box-sizing: border-box;"></div>
                    <div><label style="font-size: 0.85rem; font-weight: bold; color: #581c87;">Precio Mayor Efec ($)</label><input type="number" step="0.01" name="precio_efectivo_mayor" id="edit-precio-efectivo-mayor" required class="input-cliente" style="width: 100%; padding: 0.6rem; box-sizing: border-box;"></div>
                </div>

                <div style="margin-bottom: 1rem;">
                    <label style="font-size: 0.85rem; font-weight: bold;">Proveedor</label>
                    <select name="proveedor_id" id="edit-proveedor" class="input-cliente" style="width: 100%; padding: 0.6rem;">
                        <option value="">-- Ninguno --</option>
                        <?php foreach($prov_array as $pr): ?>
                            <option value="<?php echo $pr['id']; ?>"><?php echo htmlspecialchars($pr['empresa']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem; background: #f8fafc; padding: 1rem; border-radius: 6px; border: 1px solid #cbd5e1;">
                    <div><label style="font-size: 0.85rem; font-weight: bold; color: #b45309;">Corregir Stock</label><input type="number" step="0.001" name="stock_actual" id="edit-stock" required class="input-cliente" style="width: 100%; padding: 0.6rem; box-sizing: border-box;"></div>
                    <div><label style="font-size: 0.85rem; font-weight: bold; color: #f59e0b;">Alerta Mínimo</label><input type="number" step="0.001" name="stock_minimo" id="edit-minimo" required class="input-cliente" style="width: 100%; padding: 0.6rem; box-sizing: border-box;"></div>
                </div>

                <div style="margin-bottom: 1.5rem;">
                    <label style="font-size: 0.85rem; font-weight: bold; color: #ef4444;">Corregir Vencimiento</label>
                    <input type="date" name="fecha_vencimiento" id="edit-vence" class="input-cliente" style="width: 100%; box-sizing: border-box; padding: 0.6rem;">
                </div>

                <button type="submit" name="editar_producto" class="btn-login" style="width: 100%; margin: 0; background: #f59e0b; color: white; font-weight: bold; padding: 0.8rem;">Actualizar Datos</button>
            </form>
        </div>
    </div>

    <!-- Modal Editar Combo -->
    <div id="modal-editar-combo" class="modal-fondo ocultar-impresion" style="display: none;">
        <div class="modal-caja" style="max-width: 400px; padding: 2rem; background: white; border-radius: 10px; border-top: 5px solid #3b82f6;">
            <div class="modal-cabecera">
                <h3 style="color: #1e3a8a; margin: 0;">✏️ Editar Combo</h3>
                <button class="btn-cerrar" onclick="cerrarModal('modal-editar-combo')" style="background: none; border: none; font-size: 1.2rem; cursor: pointer;">X</button>
            </div>
            <form method="POST" action="productos.php?tab=combos" style="margin-top: 1rem;">
                <input type="hidden" name="combo_id_edit" id="edit-combo-id">
                
                <div style="margin-bottom: 1rem;">
                    <label style="font-size: 0.85rem; font-weight: bold;">Nombre del Combo:</label>
                    <input type="text" name="nombre_combo" id="edit-combo-nombre" required class="input-cliente" style="width: 100%; box-sizing: border-box; padding: 0.8rem;">
                </div>
                
                <div style="margin-bottom: 1.5rem;">
                    <label style="font-size: 0.85rem; font-weight: bold; color: #065f46;">Precio Especial ($ BCV):</label>
                    <input type="number" step="0.01" name="precio_combo" id="edit-combo-precio" required class="input-cliente" style="width: 100%; box-sizing: border-box; padding: 0.8rem;">
                </div>

                <button type="submit" name="editar_combo_maestro" class="btn-login" style="width: 100%; margin: 0; background: #3b82f6; color: white; font-weight: bold; padding: 0.8rem;">Actualizar Combo</button>
            </form>
        </div>
    </div>

    <!-- Modal Entrada -->
    <div id="modal-entrada" class="modal-fondo ocultar-impresion" style="display: none;">
        <div class="modal-caja" style="background: white; padding: 2rem; border-radius: 10px; max-width: 500px;">
            <div class="modal-cabecera">
                <h3 style="margin: 0;">📦 Registrar Entrada</h3>
                <button class="btn-cerrar" onclick="cerrarModal('modal-entrada')" style="background: none; border: none; font-size: 1.2rem; cursor: pointer;">X</button>
            </div>
            <form id="form-entrada" action="registrar_entrada.php" method="POST" style="margin-top: 1rem;">
                <div class="grupo-form" style="margin-bottom: 1rem;">
                    <label style="font-size: 0.85rem; font-weight: bold;">Buscar Producto</label>
                    <input list="opciones-productos" id="buscador-entrada" class="input-cliente" placeholder="Escriba el código o nombre..." autocomplete="off" required style="width: 100%; box-sizing: border-box; padding: 0.8rem;">
                    <datalist id="opciones-productos">
                        <?php foreach($lista_productos_combo as $prod): $stock_lista = ($prod['tipo_unidad'] == 'kg') ? number_format($prod['stock'], 2, ',', '.') : number_format($prod['stock'], 0, ',', '.'); ?>
                            <option data-id="<?php echo $prod['id']; ?>" value="<?php echo htmlspecialchars($prod['codigo_barras'] . ' - ' . $prod['nombre']); ?>">(Disp: <?php echo $stock_lista; ?>)</option>
                        <?php endforeach; ?>
                    </datalist>
                    <input type="hidden" name="producto_id" id="producto-id-oculto" required>
                </div>
                <div class="grupo-form" style="margin-bottom: 1rem;">
                    <label style="font-size: 0.85rem; font-weight: bold;">Cantidad a Sumar</label>
                    <input type="number" step="0.001" name="cantidad" required placeholder="Ej: 10" style="width: 100%; box-sizing: border-box; padding: 0.8rem;">
                </div>
                <div class="grupo-form" style="margin-bottom: 1.5rem;">
                    <label style="font-size: 0.85rem; font-weight: bold;">Observación</label>
                    <input type="text" name="observacion" placeholder="Opcional" style="width: 100%; box-sizing: border-box; padding: 0.8rem;">
                </div>
                <button type="submit" class="btn-login" style="background-color: #f59e0b; color: white; width: 100%; padding: 0.8rem; margin: 0;">Confirmar</button>
            </form>
        </div>
    </div>

    <script>
        // Pestañas
        function cambiarTab(tab) {
            document.querySelectorAll('.btn-tab').forEach(b => b.classList.remove('activo'));
            document.getElementById('btn-tab-' + tab).classList.add('activo');
            document.getElementById('panel-inventario').style.display = (tab === 'inventario') ? 'block' : 'none';
            document.getElementById('panel-combos').style.display = (tab === 'combos') ? 'block' : 'none';
            window.history.replaceState(null, '', '?tab=' + tab);
        }

        // Modales
        function abrirModal(id) { document.getElementById(id).style.display = 'flex'; }
        function cerrarModal(id) { document.getElementById(id).style.display = 'none'; }

        function abrirModalEditar(id, codigo, nombre, precio, stock, minimo, proveedor, vence, unidad, p_mayor_bcv, p_efec_detal, p_efec_mayor) {
            document.getElementById('edit-id').value = id;
            document.getElementById('edit-codigo').value = codigo;
            document.getElementById('edit-nombre').value = nombre;
            document.getElementById('edit-precio').value = precio;
            document.getElementById('edit-stock').value = stock;
            document.getElementById('edit-minimo').value = minimo;
            document.getElementById('edit-proveedor').value = proveedor;
            document.getElementById('edit-vence').value = vence;
            document.getElementById('edit-unidad').value = unidad;
            document.getElementById('edit-precio-mayor-bcv').value = p_mayor_bcv;
            document.getElementById('edit-precio-efectivo-detal').value = p_efec_detal;
            document.getElementById('edit-precio-efectivo-mayor').value = p_efec_mayor;
            abrirModal('modal-editar');
        }

        function abrirModalEditarCombo(id, nombre, precio) {
            document.getElementById('edit-combo-id').value = id;
            document.getElementById('edit-combo-nombre').value = nombre.replace('COMBO: ', '');
            document.getElementById('edit-combo-precio').value = precio;
            abrirModal('modal-editar-combo');
        }

        // Buscador
        document.getElementById('buscador-entrada').addEventListener('input', function(e) {
            let inputValor = e.target.value;
            let opciones = document.getElementById('opciones-productos').options;
            let idOculto = document.getElementById('producto-id-oculto');
            idOculto.value = ''; 
            for (let i = 0; i < opciones.length; i++) {
                if (opciones[i].value === inputValor) { idOculto.value = opciones[i].getAttribute('data-id'); break; }
            }
        });

        // Filtros
        let filtroStockActual = 'todos';
        let filtroVenceActual = 'todos';

        function cambiarFiltroBoton(boton) {
            const tipo = boton.getAttribute('data-tipo');
            document.querySelectorAll(`.btn-filtro[data-tipo="${tipo}"]`).forEach(b => b.classList.remove('activo'));
            boton.classList.add('activo');
            if (tipo === 'stock') filtroStockActual = boton.getAttribute('data-val');
            if (tipo === 'vence') filtroVenceActual = boton.getAttribute('data-val');
            filtrarInventario();
        }

        function filtrarInventario() {
            let texto = document.getElementById('buscar-tabla').value.toLowerCase().trim();
            let provId = document.getElementById('filtro-proveedor').value;
            let filas = document.querySelectorAll('.fila-producto');

            filas.forEach(fila => {
                let codigo = fila.querySelector('.col-codigo').innerText.toLowerCase();
                let nombre = fila.querySelector('.col-nombre').innerText.toLowerCase();
                let rowProvId = fila.getAttribute('data-prov-id');
                let rowStockStatus = fila.getAttribute('data-stock-status');
                let rowVenceStatus = fila.getAttribute('data-vence-status');

                let cumpleTexto = (texto === '' || codigo.includes(texto) || nombre.includes(texto));
                let cumpleProveedor = (provId === '' || rowProvId === provId);
                let cumpleStock = (filtroStockActual === 'todos' || rowStockStatus === filtroStockActual);
                let cumpleVence = (filtroVenceActual === 'todos' || rowVenceStatus === filtroVenceActual);

                fila.style.display = (cumpleTexto && cumpleProveedor && cumpleStock && cumpleVence) ? '' : 'none';
            });
        }
    </script>
</body>
</html>