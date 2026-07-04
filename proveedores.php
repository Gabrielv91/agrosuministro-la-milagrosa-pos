<?php
session_start();
if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_rol'] != 'admin') {
    header("Location: index.php");
    exit;
}
require_once 'conexion.php';
// Darle la orden a PHP de ir a la base de datos a buscar las tasas
$query_tasa = "SELECT tasa_dia, tasa_euro FROM configuracion ORDER BY id DESC LIMIT 1";
$resultado_tasa = $conexion->query($query_tasa);
$tasa = 36.50; 
$tasa_euro = 1.08;

if ($resultado_tasa && $resultado_tasa->num_rows > 0) {
    $fila = $resultado_tasa->fetch_assoc();
    $tasa = $fila['tasa_dia'];
    $tasa_euro = $fila['tasa_euro'] ?? 1.08;
}

$mensaje = '';

// 1. REGISTRAR PROVEEDOR
if (isset($_POST['guardar_proveedor'])) {
    $empresa = $conexion->real_escape_string($_POST['empresa']);
    $vendedor = $conexion->real_escape_string($_POST['vendedor']);
    $telefono = $conexion->real_escape_string($_POST['telefono']);
    $conexion->query("INSERT INTO proveedores (empresa, vendedor, telefono) VALUES ('$empresa', '$vendedor', '$telefono')");
    $mensaje = "Proveedor registrado con éxito.";
}

// 2. EDITAR PROVEEDOR
if (isset($_POST['editar_proveedor'])) {
    $id = intval($_POST['proveedor_id_edit']);
    $empresa = $conexion->real_escape_string($_POST['empresa']);
    $vendedor = $conexion->real_escape_string($_POST['vendedor']);
    $telefono = $conexion->real_escape_string($_POST['telefono']);
    $conexion->query("UPDATE proveedores SET empresa='$empresa', vendedor='$vendedor', telefono='$telefono' WHERE id=$id");
    $mensaje = "Datos del proveedor actualizados.";
}

// 3. REGISTRAR FACTURA NUEVA
if (isset($_POST['guardar_factura'])) {
    $prov_id = intval($_POST['proveedor_id']);
    $num_factura = $conexion->real_escape_string($_POST['num_factura']);
    $fecha_em = $_POST['fecha_emision'];
    $fecha_ven = $_POST['fecha_vencimiento'];
    $monto = floatval($_POST['monto_usd']);
    
    $conexion->query("INSERT INTO facturas_proveedores (proveedor_id, num_factura, fecha_emision, fecha_vencimiento, total_usd, deuda_usd) VALUES ($prov_id, '$num_factura', '$fecha_em', '$fecha_ven', $monto, $monto)");
    $mensaje = "Factura registrada con éxito.";
}

// 4. EDITAR FACTURA EXISTENTE (Fechas, Montos, Número)
if (isset($_POST['editar_factura'])) {
    $id_fac = intval($_POST['factura_id_edit']);
    $num_factura = $conexion->real_escape_string($_POST['num_factura']);
    $fecha_em = $_POST['fecha_emision'];
    $fecha_ven = $_POST['fecha_vencimiento'];
    $nuevo_total = floatval($_POST['monto_usd']);

    // Extraemos cuánto se ha pagado de esta factura para recalcular la deuda correctamente
    $datos_fac = $conexion->query("SELECT total_usd, deuda_usd FROM facturas_proveedores WHERE id = $id_fac")->fetch_assoc();
    $pagado_hasta_ahora = floatval($datos_fac['total_usd']) - floatval($datos_fac['deuda_usd']);
    
    // La nueva deuda es el nuevo monto total menos lo que ya había abonado el cliente
    $nueva_deuda = $nuevo_total - $pagado_hasta_ahora;

    $conexion->query("UPDATE facturas_proveedores SET num_factura='$num_factura', fecha_emision='$fecha_em', fecha_vencimiento='$fecha_ven', total_usd=$nuevo_total, deuda_usd=$nueva_deuda WHERE id=$id_fac");
    $mensaje = "Datos de la factura actualizados correctamente.";
}

// 5. ELIMINAR FACTURA POR COMPLETO
if (isset($_POST['eliminar_factura'])) {
    $id_fac = intval($_POST['factura_id_eliminar']);
    // Por seguridad, primero borramos los abonos atados a esa factura
    $conexion->query("DELETE FROM abonos_proveedores WHERE factura_id = $id_fac"); 
    // Luego borramos la factura
    $conexion->query("DELETE FROM facturas_proveedores WHERE id = $id_fac");
    $mensaje = "Factura eliminada del sistema.";
}

// 6. REVERSAR ABONO (Borrar pago equivocado)
if (isset($_POST['eliminar_abono'])) {
    $id_abono = intval($_POST['abono_id_eliminar']);
    $id_fac = intval($_POST['factura_id_asociada']);
    $monto_reversar = floatval($_POST['monto_reversar']);

    // Le devolvemos la deuda a la factura
    $conexion->query("UPDATE facturas_proveedores SET deuda_usd = deuda_usd + $monto_reversar WHERE id = $id_fac");
    // Eliminamos el registro del pago
    $conexion->query("DELETE FROM abonos_proveedores WHERE id = $id_abono");
    $mensaje = "Abono eliminado. El saldo deudor fue restaurado.";
}

// 7. REGISTRAR ABONO GLOBAL (LÓGICA FIFO)
// 7. REGISTRAR ABONO GLOBAL (LÓGICA FIFO CON TASA VENEZOLANA)
if (isset($_POST['guardar_abono_global'])) {
    $proveedor_id = intval($_POST['proveedor_id_pago']);
    
    // Capturamos los dólares netos calculados por el Javascript
    $monto_disponible = floatval($_POST['monto_abono']); 
    $monto_disponible_fijo = $monto_disponible; // Copia para sacar la proporción de Bs luego
    
    $metodo = $conexion->real_escape_string($_POST['metodo_pago']);
    $ref = $conexion->real_escape_string($_POST['referencia']);

    // Captura de datos históricos para la auditoría
    $moneda_pago = (in_array($metodo, ['efectivo_usd', 'zelle'])) ? 'usd' : 'bs';
    $monto_moneda_bruto = floatval($_POST['monto_entregado']);
    $tasa_registrada = ($moneda_pago == 'usd') ? 1.00 : floatval($_POST['tasa_aplicada']);

    if ($monto_disponible > 0) {
        $q_facturas = "SELECT id, deuda_usd FROM facturas_proveedores WHERE proveedor_id = $proveedor_id AND deuda_usd > 0 ORDER BY fecha_emision ASC, id ASC";
        $res_facturas = $conexion->query($q_facturas);

        while ($factura = $res_facturas->fetch_assoc()) {
            if ($monto_disponible <= 0) break;

            $id_fac = $factura['id'];
            $deuda = floatval($factura['deuda_usd']);

            if ($monto_disponible >= $deuda) {
                $pago_aplicado_usd = $deuda;
                $monto_disponible -= $deuda;
                $nueva_deuda = 0;
            } else {
                $pago_aplicado_usd = $monto_disponible;
                $nueva_deuda = $deuda - $monto_disponible;
                $monto_disponible = 0;
            }

            // Matemática fina: ¿Cuántos Bs exactos cubrieron este fragmento de factura?
            $proporcion = ($monto_disponible_fijo > 0) ? ($pago_aplicado_usd / $monto_disponible_fijo) : 1;
            $monto_moneda_fraccion = $monto_moneda_bruto * $proporcion;

            $conexion->query("UPDATE facturas_proveedores SET deuda_usd = $nueva_deuda WHERE id = $id_fac");
            
            // Guardamos el abono con su apellido completo
            $conexion->query("INSERT INTO abonos_proveedores (factura_id, monto_usd, metodo_pago, referencia, moneda_pago, monto_moneda, tasa_cambio) 
                              VALUES ($id_fac, $pago_aplicado_usd, '$metodo', '$ref', '$moneda_pago', $monto_moneda_fraccion, $tasa_registrada)");
        }
        $mensaje = "Abono procesado. Se restaron $" . number_format($monto_disponible_fijo, 2) . " USD de la deuda.";
    }
}
// CONSULTAR EL GRAN TOTAL
$query_gran_total = "SELECT SUM(deuda_usd) as gran_total, COUNT(id) as total_facturas FROM facturas_proveedores WHERE deuda_usd > 0";
$res_gran_total = $conexion->query($query_gran_total)->fetch_assoc();
$gran_total_deuda = floatval($res_gran_total['gran_total'] ?? 0);
$total_facturas_pendientes = intval($res_gran_total['total_facturas'] ?? 0);

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cuentas por Pagar - Mi Negocio</title>
    <link rel="stylesheet" href="css/style.css?v=<?php echo time(); ?>">
    <style>
        .pantalla-completa { padding: 2rem; max-width: 1200px; margin: 0 auto; }
        .alerta-vencida { background: #fee2e2; color: #ef4444; padding: 2px 6px; border-radius: 4px; font-weight: bold; font-size: 0.8rem; }
        .alerta-ok { background: #d1fae5; color: #10b981; padding: 2px 6px; border-radius: 4px; font-weight: bold; font-size: 0.8rem; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; z-index: 1000; }
        .modal-caja { background: white; padding: 2rem; border-radius: 8px; width: 400px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
        .tarjeta-balance-global { background: white; padding: 1.5rem 2rem; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); border-left: 6px solid #f59e0b; margin-bottom: 2rem; display: flex; justify-content: space-between; align-items: center; }
        .historial-pagos-celda { font-size: 0.85rem; color: #64748b; line-height: 1.5; background: #f8fafc; padding: 0.5rem; border-radius: 6px; border: 1px dashed #cbd5e1; }
        .barra-herramientas { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
        .tarjeta-perfil { background: white; padding: 1.5rem; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); margin-bottom: 2rem; display: flex; justify-content: space-between; align-items: center; border-top: 4px solid #1e3a8a;}
        
        .btn-pequeno { padding: 0.3rem 0.6rem; font-size: 0.8rem; border-radius: 4px; border: none; cursor: pointer; color: white; font-weight: bold; text-decoration: none; display: inline-block; }
        .btn-pequeno-editar { background: #f59e0b; }
        .btn-pequeno-borrar { background: #ef4444; }
    </style>
</head>
<body style="background-color: #f8fafc;">
    <header class="top-bar">
        <div style="display: flex; align-items: center; gap: 1rem;">
            <a href="menu.php" style="text-decoration: none; font-size: 1.5rem;">⬅️</a>
            <h1>Cuentas por Pagar a Proveedores</h1>
        </div>
    </header>

    <main class="pantalla-completa">
        <?php if($mensaje): ?>
            <div style="background: #d1fae5; color: #065f46; padding: 1rem; border-radius: 6px; margin-bottom: 1rem; font-weight: bold; border-left: 4px solid #10b981;">✅ <?php echo $mensaje; ?></div>
        <?php endif; ?>

        <?php 
        // =========================================================================
        // VISTA 1: PANTALLA PRINCIPAL (LISTADO DE PROVEEDORES)
        // =========================================================================
        if(!isset($_GET['ver'])): 
            $query_prov = "
                SELECT p.*, 
                (SELECT SUM(deuda_usd) FROM facturas_proveedores WHERE proveedor_id = p.id AND deuda_usd > 0) as deuda_total
                FROM proveedores p ORDER BY empresa ASC
            ";
            $proveedores = $conexion->query($query_prov);
        ?>
            <div class="tarjeta-balance-global">
                <div>
                    <h3 style="color: #64748b; font-size: 0.85rem; font-weight: bold; text-transform: uppercase; margin-bottom: 0.3rem;">Total Obligaciones Pendientes</h3>
                    <div style="font-size: 2.4rem; font-weight: bold; color: #b91c1c;">$<?php echo number_format($gran_total_deuda, 2); ?></div>
                </div>
                <div style="text-align: right;">
                    <span style="background: #fef3c7; color: #d97706; padding: 0.6rem 1.2rem; border-radius: 20px; font-weight: bold; font-size: 0.95rem; border: 1px solid #fde68a;">
                        🏢 <?php echo $total_facturas_pendientes; ?> Facturas por Liquidar
                    </span>
                </div>
            </div>

            <div class="barra-herramientas">
                <h2 style="color: var(--texto-principal); margin: 0;">Directorio de Proveedores</h2>
                <button onclick="document.getElementById('modal-nuevo-prov').style.display='flex'" class="btn-login" style="margin: 0; padding: 0.6rem 1.2rem; background: #3b82f6;">➕ Nuevo Proveedor</button>
            </div>

            <div style="background: white; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); overflow: hidden;">
                <table class="tabla-inventario" style="width: 100%; margin: 0;">
                    <thead><tr><th>Empresa</th><th>Contacto</th><th>Deuda Pendiente</th><th>Acciones</th></tr></thead>
                    <tbody>
                        <?php while($p = $proveedores->fetch_assoc()): 
                            $deuda = floatval($p['deuda_total']);
                            $tlf_limpio = preg_replace('/[^0-9]/', '', $p['telefono']);
                            if (strpos($tlf_limpio, '0') === 0) $tlf_limpio = '58' . substr($tlf_limpio, 1);
                            
                            $texto_wa = "Hola *" . trim($p['vendedor']) . "*, te escribo desde Agrosuministro La Milagrosa para revisar el estado de cuenta de *" . trim($p['empresa']) . "*.\n\n";
                            
                            if ($deuda > 0) {
                                $texto_wa .= "🔹 *Facturas Pendientes:*\n";
                                $prov_id_loop = $p['id'];
                                $q_facturas_wa = $conexion->query("SELECT id, num_factura, total_usd, deuda_usd FROM facturas_proveedores WHERE proveedor_id = $prov_id_loop AND deuda_usd > 0 ORDER BY fecha_vencimiento ASC");
                                
                                while($f_wa = $q_facturas_wa->fetch_assoc()) {
                                    $id_fac = $f_wa['id'];
                                    $texto_wa .= "• Fac #" . $f_wa['num_factura'] . " (Monto Original: $" . number_format($f_wa['total_usd'], 2) . ")\n";
                                    $q_abonos_wa = $conexion->query("SELECT monto_usd, metodo_pago, tasa_cambio, monto_moneda, DATE_FORMAT(fecha, '%d/%m/%Y') as fecha_fmt FROM abonos_proveedores WHERE factura_id = $id_fac ORDER BY fecha ASC");
                                    
                                    if($q_abonos_wa->num_rows > 0) {
                                        while($abono = $q_abonos_wa->fetch_assoc()) {
                                            $monto_dolares = number_format($abono['monto_usd'], 2);
                                            $metodo_limpio = ucwords(str_replace('_', ' ', $abono['metodo_pago']));
                                            
                                            // Armamos la línea base (siempre sale)
                                            $linea_abono = "  ↳ Abono: $" . $monto_dolares . " (" . $metodo_limpio . " - " . $abono['fecha_fmt'] . ")";

                                            // Si existe la tasa guardada y es mayor a 1, calculamos los Bs
                                            $tasa_guardada = isset($abono['tasa_cambio']) ? floatval($abono['tasa_cambio']) : 0;
                                            
                                            if ($tasa_guardada > 1) {
                                                // Recuperamos el monto original en Bs (o lo calculamos si no existe)
                                                $monto_en_bs = isset($abono['monto_moneda']) ? floatval($abono['monto_moneda']) : ($abono['monto_usd'] * $tasa_guardada);
                                                
                                                $linea_abono .= " -> *" . number_format($monto_en_bs, 2, ',', '.') . " Bs* (Tasa: " . number_format($tasa_guardada, 2, ',', '.') . ")";
                                            }

                                            $texto_wa .= $linea_abono . "\n";
                                        }
                                    }
                                    $texto_wa .= "  *-> Resta: $" . number_format($f_wa['deuda_usd'], 2) . "*\n\n";
                                }
                                $texto_wa .= "💰 *Deuda Total: $" . number_format($deuda, 2) . "*";
                            } else {
                                $texto_wa .= "✅ Actualmente tenemos todas las facturas al día con ustedes.";
                            }
                        ?>
                        <tr>
                            <td style="font-weight: bold; font-size: 1.1rem; color: #1e3a8a;"><?php echo htmlspecialchars($p['empresa']); ?></td>
                            <td style="color: #64748b;">👤 <?php echo htmlspecialchars($p['vendedor']); ?><br>📞 <?php echo htmlspecialchars($p['telefono']); ?></td>
                            <td style="font-weight: bold; font-size: 1.2rem; color: <?php echo ($deuda>0)?'#ef4444':'#10b981'; ?>;">
                                $<?php echo number_format($deuda, 2); ?>
                            </td>
                            <td style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                <a href="?ver=<?php echo $p['id']; ?>" class="btn-login" style="padding: 0.4rem 0.8rem; font-size: 0.85rem; background: #475569; text-decoration:none;" title="Abrir Historial">📄 Abrir Perfil</a>
                                <button onclick="abrirModalEditarProv(<?php echo $p['id']; ?>, '<?php echo addslashes($p['empresa']); ?>', '<?php echo addslashes($p['vendedor']); ?>', '<?php echo addslashes($p['telefono']); ?>')" class="btn-login" style="padding: 0.4rem 0.8rem; font-size: 0.85rem; background: #f59e0b;" title="Editar Datos">✏️ Editar</button>
                                <a href="https://wa.me/<?php echo $tlf_limpio; ?>?text=<?php echo urlencode($texto_wa); ?>" target="_blank" class="btn-login" style="padding: 0.4rem 0.8rem; font-size: 0.85rem; background: #25D366; text-decoration:none;" title="Enviar WhatsApp">💬 WA</a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

        <?php 
        // =========================================================================
        // VISTA 2: PERFIL DEL PROVEEDOR (HISTORIAL Y CORRECCIONES)
        // =========================================================================
        else: 
            $id_ver = intval($_GET['ver']);
            $prov_info = $conexion->query("SELECT * FROM proveedores WHERE id = $id_ver")->fetch_assoc();
            
            $facturas_pendientes = $conexion->query("SELECT * FROM facturas_proveedores WHERE proveedor_id = $id_ver AND deuda_usd > 0 ORDER BY fecha_vencimiento ASC");
            $facturas_pagadas = $conexion->query("SELECT * FROM facturas_proveedores WHERE proveedor_id = $id_ver AND deuda_usd <= 0 ORDER BY fecha_emision DESC");
            
            $deuda_prov = $conexion->query("SELECT SUM(deuda_usd) as total FROM facturas_proveedores WHERE proveedor_id = $id_ver AND deuda_usd > 0")->fetch_assoc()['total'] ?? 0;
        ?>

            <div class="barra-herramientas">
                <a href="proveedores.php" class="btn-login" style="background: #94a3b8; text-decoration: none; padding: 0.6rem 1.2rem; margin: 0;">⬅️ Volver al Directorio</a>
                <div style="display: flex; gap: 1rem;">
                    <button onclick="abrirModalFactura(<?php echo $prov_info['id']; ?>, '<?php echo addslashes($prov_info['empresa']); ?>')" class="btn-login" style="background: #3b82f6; margin: 0;">➕ Cargar Factura</button>
                    <?php if($deuda_prov > 0): ?>
                        <button onclick="abrirModalAbonoGlobal(<?php echo $prov_info['id']; ?>, '<?php echo addslashes($prov_info['empresa']); ?>', <?php echo $deuda_prov; ?>)" class="btn-login" style="background: #10b981; margin: 0;">💰 Registrar Pago</button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tarjeta-perfil">
                <div>
                    <h2 style="color: #1e3a8a; margin-bottom: 0.5rem; font-size: 1.8rem;"><?php echo htmlspecialchars($prov_info['empresa']); ?></h2>
                    <p style="color: #64748b; margin: 0; font-size: 1.1rem;">👤 <?php echo htmlspecialchars($prov_info['vendedor']); ?> &nbsp;|&nbsp; 📞 <?php echo htmlspecialchars($prov_info['telefono']); ?></p>
                </div>
                <div style="text-align: right; background: #f8fafc; padding: 1rem 1.5rem; border-radius: 8px; border: 1px solid var(--borde);">
                    <span style="font-size: 0.9rem; color: #64748b; font-weight: bold;">DEUDA ACTUAL</span><br>
                    <span style="font-size: 2rem; font-weight: bold; color: <?php echo ($deuda_prov > 0) ? '#ef4444' : '#10b981'; ?>;">$<?php echo number_format($deuda_prov, 2); ?></span>
                </div>
            </div>

            <div style="background: white; padding: 1.5rem; border-radius: 8px; border-left: 5px solid #ef4444; box-shadow: 0 4px 6px rgba(0,0,0,0.05); margin-bottom: 2rem;">
                <h3 style="margin-bottom: 1rem; color: #b91c1c;">🔴 Facturas Pendientes</h3>
                <table class="tabla-inventario" style="width: 100%;">
                    <thead><tr><th>N° Factura</th><th>Fechas</th><th>Deuda Restante</th><th style="width: 35%;">Detalle de Pagos Realizados</th></tr></thead>
                    <tbody>
                        <?php if($facturas_pendientes->num_rows > 0): ?>
                            <?php while($f = $facturas_pendientes->fetch_assoc()): 
                                $vencida = (strtotime($f['fecha_vencimiento']) < strtotime(date('Y-m-d')));
                                
                                $id_fac = $f['id'];
                                $q_abonos = $conexion->query("SELECT * FROM abonos_proveedores WHERE factura_id = $id_fac ORDER BY fecha ASC");
                                $html_abonos = "";
                                if($q_abonos->num_rows > 0) {
                                    while($ab = $q_abonos->fetch_assoc()) {
                                        $metodo = ucwords(str_replace('_', ' ', $ab['metodo_pago']));
                                        $fecha_fmt = date('d/m/y h:i A', strtotime($ab['fecha']));
                                        $ref = $ab['referencia'] ? " (Ref: ".$ab['referencia'].")" : "";
                                        
                                        // AQUÍ DETECTAMOS SI PAGÓ EN BS O EUROS PARA MOSTRARLO
                                        $detalle_moneda = "";
                                        if (isset($ab['moneda_pago']) && $ab['moneda_pago'] == 'bs') {
                                            $detalle_moneda = "<br><small style='color:#64748b;'>Pagó <b>".number_format($ab['monto_moneda'], 2, ',', '.')." Bs</b> (Tasa: ".$ab['tasa_cambio'].")</small>";
                                        } elseif (isset($ab['moneda_pago']) && $ab['moneda_pago'] == 'eur') {
                                            $detalle_moneda = "<br><small style='color:#64748b;'>Pagó <b>".number_format($ab['monto_moneda'], 2)." €</b> (Cotizado a $".$ab['tasa_cambio'].")</small>";
                                        }

                                        $html_abonos .= "<div style='display:flex; justify-content:space-between; align-items:center; margin-bottom:4px; border-bottom:1px dashed #cbd5e1; padding-bottom:4px;'>
                                            <span style='font-size:0.8rem;'><strong style='color:#10b981;'>$".number_format($ab['monto_usd'],2)."</strong> el $fecha_fmt <br><small style='color:#64748b;'>$metodo $ref</small>$detalle_moneda</span>
                                            <form method='POST' style='margin:0;' onsubmit='return confirm(\"¿Seguro que deseas REVERSAR este pago de $".number_format($ab['monto_usd'],2)."? La deuda volverá a la factura.\");'>
                                                <input type='hidden' name='abono_id_eliminar' value='".$ab['id']."'>
                                                <input type='hidden' name='factura_id_asociada' value='".$id_fac."'>
                                                <input type='hidden' name='monto_reversar' value='".$ab['monto_usd']."'>
                                                <button type='submit' name='eliminar_abono' style='background:none; border:none; color:#ef4444; font-size:1rem; cursor:pointer;' title='Reversar Pago'>❌</button>
                                            </form>
                                        </div>";
                                    }
                                } else {
                                    $html_abonos = "<em>Sin abonos registrados.</em>";
                                }
                            ?>
                            <tr>
                                <td>
                                    <span style="font-weight: bold; font-size: 1.1rem;">#<?php echo htmlspecialchars($f['num_factura']); ?></span>
                                    <div style="display:flex; gap: 0.5rem; margin-top: 0.8rem;">
                                        <button onclick="abrirModalEditarFac(<?php echo $f['id']; ?>, '<?php echo addslashes($f['num_factura']); ?>', <?php echo $f['total_usd']; ?>, '<?php echo $f['fecha_emision']; ?>', '<?php echo $f['fecha_vencimiento']; ?>')" class="btn-pequeno btn-pequeno-editar">✏️ Editar</button>
                                        <form method="POST" style="margin:0;" onsubmit="return confirm('⚠️ ¿Eliminar la factura por completo? (Se borrarán sus abonos también)');">
                                            <input type="hidden" name="factura_id_eliminar" value="<?php echo $f['id']; ?>">
                                            <button type="submit" name="eliminar_factura" class="btn-pequeno btn-pequeno-borrar">🗑️</button>
                                        </form>
                                    </div>
                                </td>
                                <td>
                                    <small style="color: #64748b;">Emisión: <?php echo date('d/m/Y', strtotime($f['fecha_emision'])); ?></small><br>
                                    Vence: <?php echo date('d/m/Y', strtotime($f['fecha_vencimiento'])); ?>
                                    <span class="<?php echo $vencida ? 'alerta-vencida' : 'alerta-ok'; ?>" style="margin-left: 5px;"><?php echo $vencida ? '⚠️ Vencida' : 'A tiempo'; ?></span>
                                </td>
                                <td>
                                    <span style="color: #94a3b8; font-size: 0.85rem; text-decoration: line-through;">Total: $<?php echo number_format($f['total_usd'], 2); ?></span><br>
                                    <span style="color: #ef4444; font-weight: bold; font-size: 1.2rem;">Resta: $<?php echo number_format($f['deuda_usd'], 2); ?></span>
                                </td>
                                <td>
                                    <div class="historial-pagos-celda">
                                        <?php echo $html_abonos; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="4" style="text-align: center; color: #94a3b8; padding: 1.5rem;">No hay facturas pendientes. ¡Todo al día! 🎉</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div style="background: white; padding: 1.5rem; border-radius: 8px; border-left: 5px solid #10b981; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                <h3 style="margin-bottom: 1rem; color: #065f46;">✅ Facturas Pagadas</h3>
                <table class="tabla-inventario" style="width: 100%;">
                    <thead><tr><th>N° Factura</th><th>Emisión</th><th>Total Pagado</th><th style="width: 35%;">Detalle de Pagos Realizados</th><th>Acciones</th></tr></thead>
                    <tbody>
                        <?php if($facturas_pagadas->num_rows > 0): ?>
                            <?php while($fp = $facturas_pagadas->fetch_assoc()): 
                                // Extraer abonos para las facturas CERRADAS
                                $id_fac_cerrada = $fp['id'];
                                $q_abonos_cerrados = $conexion->query("SELECT * FROM abonos_proveedores WHERE factura_id = $id_fac_cerrada ORDER BY fecha ASC");
                                $html_abonos_cerrados = "";
                                
                                if($q_abonos_cerrados->num_rows > 0) {
                                    while($ab_c = $q_abonos_cerrados->fetch_assoc()) {
                                        $metodo_c = ucwords(str_replace('_', ' ', $ab_c['metodo_pago']));
                                        $fecha_fmt_c = date('d/m/y h:i A', strtotime($ab_c['fecha']));
                                        $ref_c = $ab_c['referencia'] ? " (Ref: ".$ab_c['referencia'].")" : "";
                                        
                                        // DETECTAMOS SI PAGÓ EN BS O EUROS PARA LA SECCIÓN VERDE
                                        $detalle_moneda_c = "";
                                        if (isset($ab_c['moneda_pago']) && $ab_c['moneda_pago'] == 'bs') {
                                            $detalle_moneda_c = "<br><small style='color:#047857;'>Pagó <b>".number_format($ab_c['monto_moneda'], 2, ',', '.')." Bs</b> (Tasa: ".$ab_c['tasa_cambio'].")</small>";
                                        } elseif (isset($ab_c['moneda_pago']) && $ab_c['moneda_pago'] == 'eur') {
                                            $detalle_moneda_c = "<br><small style='color:#047857;'>Pagó <b>".number_format($ab_c['monto_moneda'], 2)." €</b> (Cotizado a $".$ab_c['tasa_cambio'].")</small>";
                                        }

                                        $html_abonos_cerrados .= "<div style='margin-bottom:4px; border-bottom:1px dashed #a7f3d0; padding-bottom:4px;'>
                                            <span style='font-size:0.8rem;'><strong style='color:#065f46;'>$".number_format($ab_c['monto_usd'],2)."</strong> el $fecha_fmt_c <br><small style='color:#047857;'>$metodo_c $ref_c</small>$detalle_moneda_c</span>
                                        </div>";
                                    }
                                } else {
                                    $html_abonos_cerrados = "<em>Detalle no disponible.</em>";
                                }
                            ?> <tr>
                                <td style="font-weight: bold; color: #64748b;">#<?php echo htmlspecialchars($fp['num_factura']); ?></td>
                                <td><?php echo date('d/m/Y', strtotime($fp['fecha_emision'])); ?></td>
                                <td style="color: #10b981; font-weight: bold; font-size: 1.1rem;">$<?php echo number_format($fp['total_usd'], 2); ?></td>
                                <td>
                                    <div class="historial-pagos-celda" style="border-color: #a7f3d0; background: #ecfdf5;">
                                        <?php echo $html_abonos_cerrados; ?>
                                    </div>
                                </td>
                                <td>
                                    <form method="POST" style="margin:0;" onsubmit="return confirm('⚠️ ¿Eliminar la factura del historial? (Solo bórrela si fue un error)');">
                                        <input type="hidden" name="factura_id_eliminar" value="<?php echo $fp['id']; ?>">
                                        <button type="submit" name="eliminar_factura" class="btn-pequeno btn-pequeno-borrar">🗑️ Eliminar</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="5" style="text-align: center; color: #94a3b8; padding: 1rem;">No hay historial.</td></tr>
                        <?php endif; ?>
                    </tbody>
                 </table>
            </div>

        <?php endif; ?>
    </main>

    <div id="modal-nuevo-prov" class="modal">
        <div class="modal-caja">
            <h3 style="margin-bottom: 1rem; color: #1e3a8a;">🏢 Registrar Proveedor</h3>
            <form method="POST">
                <div style="margin-bottom: 1rem;"><label>Empresa Distribuidora</label><input type="text" name="empresa" required class="input-cliente" style="width: 100%; padding: 0.6rem;"></div>
                <div style="margin-bottom: 1rem;"><label>Nombre del Vendedor</label><input type="text" name="vendedor" required class="input-cliente" style="width: 100%; padding: 0.6rem;"></div>
                <div style="margin-bottom: 1.5rem;"><label>Teléfono / WhatsApp</label><input type="text" name="telefono" required class="input-cliente" style="width: 100%; padding: 0.6rem;"></div>
                <div style="display: flex; gap: 1rem;">
                    <button type="submit" name="guardar_proveedor" class="btn-login" style="width: 100%; background: #3b82f6;">Guardar</button>
                    <button type="button" onclick="cerrarModal('modal-nuevo-prov')" class="btn-login" style="width: 100%; background: #94a3b8;">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <div id="modal-editar-prov" class="modal">
        <div class="modal-caja">
            <h3 style="margin-bottom: 1rem; color: #f59e0b;">✏️ Editar Proveedor</h3>
            <form method="POST">
                <input type="hidden" name="proveedor_id_edit" id="edit-prov-id">
                <div style="margin-bottom: 1rem;"><label>Empresa</label><input type="text" name="empresa" id="edit-empresa" required class="input-cliente" style="width: 100%; padding: 0.6rem;"></div>
                <div style="margin-bottom: 1rem;"><label>Vendedor</label><input type="text" name="vendedor" id="edit-vendedor" required class="input-cliente" style="width: 100%; padding: 0.6rem;"></div>
                <div style="margin-bottom: 1.5rem;"><label>WhatsApp</label><input type="text" name="telefono" id="edit-telefono" required class="input-cliente" style="width: 100%; padding: 0.6rem;"></div>
                <div style="display: flex; gap: 1rem;">
                    <button type="submit" name="editar_proveedor" class="btn-login" style="width: 100%; background: #f59e0b;">Actualizar</button>
                    <button type="button" onclick="cerrarModal('modal-editar-prov')" class="btn-login" style="width: 100%; background: #94a3b8;">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <div id="modal-factura" class="modal">
        <div class="modal-caja">
            <h3 style="margin-bottom: 1rem; color: #1e3a8a;">Cargar Factura a <span id="nom-prov"></span></h3>
            <form method="POST">
                <input type="hidden" name="proveedor_id" id="input-prov-id">
                <div style="margin-bottom: 1rem;"><label>N° de Factura</label><input type="text" name="num_factura" required class="input-cliente" style="width:100%; padding: 0.6rem;"></div>
                <div style="margin-bottom: 1rem;"><label>Monto Total ($)</label><input type="number" step="0.01" name="monto_usd" required class="input-cliente" style="width:100%; padding: 0.6rem;"></div>
                <div style="margin-bottom: 1rem;"><label>Fecha Emisión</label><input type="date" name="fecha_emision" required class="input-cliente" style="width:100%; padding: 0.6rem;" value="<?php echo date('Y-m-d'); ?>"></div>
                <div style="margin-bottom: 1.5rem;"><label>Fecha Vencimiento</label><input type="date" name="fecha_vencimiento" required class="input-cliente" style="width:100%; padding: 0.6rem;"></div>
                <div style="display: flex; gap: 1rem;">
                    <button type="submit" name="guardar_factura" class="btn-login" style="width: 100%; background: #3b82f6;">Guardar</button>
                    <button type="button" onclick="cerrarModal('modal-factura')" class="btn-login" style="width: 100%; background: #94a3b8;">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <div id="modal-editar-factura" class="modal">
        <div class="modal-caja">
            <h3 style="margin-bottom: 1rem; color: #f59e0b;">✏️ Corregir Datos de Factura</h3>
            <form method="POST">
                <input type="hidden" name="factura_id_edit" id="edit-fac-id">
                <div style="margin-bottom: 1rem;"><label>N° de Factura</label><input type="text" name="num_factura" id="edit-fac-num" required class="input-cliente" style="width:100%; padding: 0.6rem;"></div>
                <div style="margin-bottom: 1rem;"><label>Monto Total Original ($)</label><input type="number" step="0.01" name="monto_usd" id="edit-fac-monto" required class="input-cliente" style="width:100%; padding: 0.6rem;"></div>
                <div style="margin-bottom: 1rem;"><label>Fecha Emisión</label><input type="date" name="fecha_emision" id="edit-fac-fe" required class="input-cliente" style="width:100%; padding: 0.6rem;"></div>
                <div style="margin-bottom: 1.5rem;"><label>Fecha Vencimiento</label><input type="date" name="fecha_vencimiento" id="edit-fac-fv" required class="input-cliente" style="width:100%; padding: 0.6rem;"></div>
                <div style="display: flex; gap: 1rem;">
                    <button type="submit" name="editar_factura" class="btn-login" style="width: 100%; background: #f59e0b;">Actualizar</button>
                    <button type="button" onclick="cerrarModal('modal-editar-factura')" class="btn-login" style="width: 100%; background: #94a3b8;">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <div id="modal-abono-global" class="modal">
        <div class="modal-caja">
            <h3 style="margin-bottom: 1rem; color: #10b981;">💰 Abonar a <span id="txt-prov-pago"></span></h3>
            <p style="margin-bottom: 1rem; color: #ef4444; font-weight: bold;">Deuda Total: $<span id="txt-deuda-global"></span></p>
            <form method="POST">
                <!-- INICIO REEMPLAZO FORMULARIO MODAL -->
        <input type="hidden" name="proveedor_id_pago" id="input-prov-pago-id">
        
        <div style="margin-bottom: 1rem;">
            <label style="font-weight:bold;">Método de Pago</label>
            <select name="metodo_pago" id="prov_metodo" onchange="adaptarModalProveedor()" required class="input-cliente" style="width:100%; padding:0.6rem;">
                <option value="transferencia_bs" data-moneda="bs">Transferencia Bs</option>
                <option value="pago_movil" data-moneda="bs">Pago Móvil</option>
                <option value="efectivo_bs" data-moneda="bs">Efectivo Bs</option>
                <option value="efectivo_usd" data-moneda="usd">Efectivo USD</option>
                <option value="zelle" data-moneda="usd">Zelle / Binance</option>
            </select>
        </div>

        <div style="margin-bottom: 1rem;">
            <label id="label_monto_prov" style="font-weight:bold; color:#1e3a8a;">Monto Entregado (Bs):</label>
            <input type="number" step="0.01" name="monto_entregado" id="prov_monto_entregado" oninput="calcularAbonoProv()" required class="input-cliente" style="width:100%; padding:0.6rem; font-size:1.2rem; font-weight:bold;" placeholder="0.00">
        </div>

        <!-- CAJA DE TASAS (Se oculta sola si eligen Dólares) -->
        <div id="panel_tasas_prov" style="background:#f8fafc; padding:0.8rem 1rem; border-radius:6px; border:1px solid #cbd5e1; margin-bottom:1rem;">
            <label style="font-size:0.85rem; font-weight:bold; color:#475569; display:block; margin-bottom:0.5rem;">¿Con cuál tasa vas a calcular estos Bolívares?</label>
            
            <div style="display:flex; gap:1.5rem; margin-bottom:0.8rem;">
                <label style="cursor:pointer; font-size:0.95rem;">
                    <input type="radio" name="selector_tasa" value="dolar" onchange="aplicarTasaSeleccionada(this.value)" checked> 
                    Tasa Dólar (<strong><?php echo floatval($tasa); ?></strong>)
                </label>
                <label style="cursor:pointer; font-size:0.95rem;">
                    <input type="radio" name="selector_tasa" value="euro" onchange="aplicarTasaSeleccionada(this.value)"> 
                    Tasa Euro (<strong><?php echo floatval($tasa_euro ?? 1.08); ?></strong>)
                </label>
            </div>

            <div style="display:flex; align-items:center; gap:0.5rem;">
                <span style="font-size:0.85rem; color:#64748b;">Tasa a aplicar:</span>
                <input type="number" step="0.0001" name="tasa_aplicada" id="prov_tasa_custom" oninput="calcularAbonoProv()" class="input-cliente" style="width:120px; padding:0.3rem; font-weight:bold;" value="<?php echo floatval($tasa); ?>">
            </div>
        </div>

        <!-- PREVISIÓN DEL DESCUENTO REAL EN LA DEUDA -->
        <div style="background:#ecfdf5; border:1px solid #a7f3d0; padding:0.8rem; border-radius:6px; margin-bottom:1.5rem; text-align:center;">
            <span style="font-size:0.85rem; color:#065f46;">Se restará de la deuda de la factura:</span><br>
            <span style="font-size:1.8rem; font-weight:bold; color:#047857;">$<span id="prov_usd_descontar">0.00</span> USD</span>
            <input type="hidden" name="monto_abono" id="input_usd_final" value="0">
        </div>

        <div style="margin-bottom: 1.5rem;">
            <label>Referencia / N° Recibo</label>
            <input type="text" name="referencia" placeholder="Opcional" class="input-cliente" style="width:100%; padding: 0.6rem;">
        </div>

        <div style="display: flex; gap: 1rem;">
            <button type="submit" name="guardar_abono_global" class="btn-login" style="width: 100%; background: #10b981;">Confirmar Pago</button>
            <button type="button" onclick="cerrarModal('modal-abono-global')" class="btn-login" style="width: 100%; background: #94a3b8;">Cancelar</button>
        </div>
        <!-- FIN REEMPLAZO FORMULARIO MODAL -->
            </form>
        </div>
    </div>

    <script>
        function cerrarModal(id) { document.getElementById(id).style.display = 'none'; }
        
        function abrirModalEditarProv(id, empresa, vendedor, telefono) {
            document.getElementById('edit-prov-id').value = id;
            document.getElementById('edit-empresa').value = empresa;
            document.getElementById('edit-vendedor').value = vendedor;
            document.getElementById('edit-telefono').value = telefono;
            document.getElementById('modal-editar-prov').style.display = 'flex';
        }

        function abrirModalFactura(id, nombre) {
            document.getElementById('input-prov-id').value = id;
            document.getElementById('nom-prov').innerText = nombre;
            document.getElementById('modal-factura').style.display = 'flex';
        }

        function abrirModalEditarFac(id, num, monto, fe, fv) {
            document.getElementById('edit-fac-id').value = id;
            document.getElementById('edit-fac-num').value = num;
            document.getElementById('edit-fac-monto').value = monto;
            document.getElementById('edit-fac-fe').value = fe;
            document.getElementById('edit-fac-fv').value = fv;
            document.getElementById('modal-editar-factura').style.display = 'flex';
        }
        
        function abrirModalAbonoGlobal(id, nombre, deuda) {
            document.getElementById('input-prov-pago-id').value = id;
            document.getElementById('txt-prov-pago').innerText = nombre;
            document.getElementById('txt-deuda-global').innerText = parseFloat(deuda).toFixed(2);
            document.getElementById('modal-abono-global').style.display = 'flex';
        }
    </script>
    <script>
const TASA_DOL_OFICIAL = <?php echo floatval($tasa); ?>;
const TASA_EUR_OFICIAL = <?php echo floatval($tasa_euro ?? 1.08); ?>;

function adaptarModalProveedor() {
    const select = document.getElementById('prov_metodo');
    const moneda = select.options[select.selectedIndex].dataset.moneda;
    
    const label = document.getElementById('label_monto_prov');
    const panelTasas = document.getElementById('panel_tasas_prov');
    const inputTasa = document.getElementById('prov_tasa_custom');

    if (moneda === 'usd') {
        label.innerText = "Monto Entregado ($ USD):";
        panelTasas.style.display = 'none';
        inputTasa.value = 1;
    } else {
        label.innerText = "Monto Entregado (Bolívares Bs):";
        panelTasas.style.display = 'block';
        
        const radioEscogido = document.querySelector('input[name="selector_tasa"]:checked').value;
        inputTasa.value = (radioEscogido === 'euro') ? TASA_EUR_OFICIAL : TASA_DOL_OFICIAL;
    }
    calcularAbonoProv();
}

function aplicarTasaSeleccionada(tipo) {
    const inputTasa = document.getElementById('prov_tasa_custom');
    inputTasa.value = (tipo === 'euro') ? TASA_EUR_OFICIAL : TASA_DOL_OFICIAL;
    calcularAbonoProv();
}

function calcularAbonoProv() {
    const monto = parseFloat(document.getElementById('prov_monto_entregado').value) || 0;
    const tasa = parseFloat(document.getElementById('prov_tasa_custom').value) || 1;
    
    const select = document.getElementById('prov_metodo');
    const moneda = select.options[select.selectedIndex].dataset.moneda;

    let usd = (moneda === 'usd') ? monto : (monto / tasa);

    document.getElementById('prov_usd_descontar').innerText = usd.toFixed(2);
    document.getElementById('input_usd_final').value = usd.toFixed(2); // Esto viaja al PHP
}
</script>
</body>
</html>