<?php
session_start();
if (!isset($_SESSION['usuario_id']) || ($_SESSION['usuario_rol'] != 'admin' && $_SESSION['usuario_rol'] != 'vendedor')) {
    header("Location: index.php");
    exit;
}

require_once 'conexion.php';

$mensaje = '';
$query_tasa = "SELECT tasa_dia FROM configuracion ORDER BY id DESC LIMIT 1";
$resultado_tasa = $conexion->query($query_tasa);
$tasa = ($resultado_tasa && $resultado_tasa->num_rows > 0) ? floatval($resultado_tasa->fetch_assoc()['tasa_dia']) : 36.50;

// AUTO-PARCHES DE BASE DE DATOS
$check_tasa = $conexion->query("SHOW COLUMNS FROM historico_abonos LIKE 'tasa_pago'");
if ($check_tasa && $check_tasa->num_rows == 0) {
    $conexion->query("ALTER TABLE historico_abonos ADD tasa_pago DECIMAL(10,2) NULL");
    $conexion->query("UPDATE historico_abonos ha JOIN ventas v ON ha.venta_id = v.id SET ha.tasa_pago = v.tasa_aplicada");
}
$check_foto = $conexion->query("SHOW COLUMNS FROM ventas LIKE 'foto_evidencia'");
if ($check_foto && $check_foto->num_rows == 0) {
    $conexion->query("ALTER TABLE ventas ADD foto_evidencia VARCHAR(255) NULL");
}

// ========================================================================
// 1. PROCESAR NUEVO ABONO (FIFO) CON CONVERSIÓN DE MONEDA
// ========================================================================
if (isset($_POST['procesar_abono'])) {
    $cliente_id = intval($_POST['cliente_id']);
    $monto_ingresado = floatval($_POST['monto_abono']);
    $moneda = $_POST['moneda_abono'] ?? 'usd';
    $metodo_pago = $conexion->real_escape_string($_POST['metodo_pago']);
    $referencia = $conexion->real_escape_string($_POST['referencia']);
    $usuario_id = $_SESSION['usuario_id'];

    if ($moneda === 'bs' && $tasa > 0) {
        $monto_disponible = $monto_ingresado / $tasa;
    } else {
        $monto_disponible = $monto_ingresado;
    }

    if ($monto_disponible > 0) {
        $q_facturas = "SELECT id, deuda_usd, foto_evidencia FROM ventas WHERE cliente_id = $cliente_id AND deuda_usd > 0 ORDER BY fecha ASC";
        $res_facturas = $conexion->query($q_facturas);

        while ($factura = $res_facturas->fetch_assoc()) {
            if ($monto_disponible <= 0) break; 

            $id_venta = $factura['id'];
            $deuda_factura = floatval($factura['deuda_usd']);
            $ruta_foto = $factura['foto_evidencia'];

            if ($monto_disponible >= $deuda_factura) {
                $pago_aplicado = $deuda_factura;
                $monto_disponible -= $deuda_factura;
                $nueva_deuda = 0;
            } else {
                $pago_aplicado = $monto_disponible;
                $nueva_deuda = $deuda_factura - $monto_disponible;
                $monto_disponible = 0;
            }

            $conexion->query("UPDATE ventas SET deuda_usd = $nueva_deuda WHERE id = $id_venta");

            if ($nueva_deuda == 0 && !empty($ruta_foto) && file_exists($ruta_foto)) {
                unlink($ruta_foto); 
                $conexion->query("UPDATE ventas SET foto_evidencia = NULL WHERE id = $id_venta");
            }

            $metodo_historial = 'credito_' . $metodo_pago;
            $conexion->query("INSERT INTO historico_abonos (venta_id, monto_usd, metodo_pago, referencia, usuario_id, tasa_pago) 
                              VALUES ($id_venta, $pago_aplicado, '$metodo_historial', '$referencia', $usuario_id, $tasa)");
        }
        $mensaje = "Abono procesado con éxito.";
    }
}

// ========================================================================
// 2. EDITAR ABONO EXISTENTE CON CONVERSIÓN DE MONEDA
// ========================================================================
if (isset($_POST['editar_abono'])) {
    $abono_id = intval($_POST['abono_id']);
    $monto_ingresado = floatval($_POST['monto_abono']);
    $moneda = $_POST['moneda_abono'] ?? 'usd';
    $metodo = $conexion->real_escape_string($_POST['metodo_pago']);
    $referencia = $conexion->real_escape_string($_POST['referencia']);

    if ($moneda === 'bs' && $tasa > 0) {
        $nuevo_monto_usd = $monto_ingresado / $tasa;
    } else {
        $nuevo_monto_usd = $monto_ingresado;
    }

    $q_viejo = $conexion->query("SELECT venta_id, monto_usd FROM historico_abonos WHERE id = $abono_id");
    if ($q_viejo && $q_viejo->num_rows > 0) {
        $viejo = $q_viejo->fetch_assoc();
        $venta_id = $viejo['venta_id'];
        $monto_viejo = floatval($viejo['monto_usd']);

        $diferencia = $nuevo_monto_usd - $monto_viejo;

        $conexion->query("UPDATE ventas SET deuda_usd = deuda_usd - ($diferencia) WHERE id = $venta_id");
        $conexion->query("UPDATE historico_abonos SET monto_usd = $nuevo_monto_usd, metodo_pago = '$metodo', referencia = '$referencia' WHERE id = $abono_id");
        $mensaje = "El pago fue corregido y la deuda actualizada.";
    }
}

// ========================================================================
// 3. ELIMINAR/REVERSAR ABONO
// ========================================================================
if (isset($_POST['eliminar_abono'])) {
    $abono_id = intval($_POST['abono_id_elim']);
    $q_viejo = $conexion->query("SELECT venta_id, monto_usd FROM historico_abonos WHERE id = $abono_id");
    if ($q_viejo && $q_viejo->num_rows > 0) {
        $viejo = $q_viejo->fetch_assoc();
        $venta_id = $viejo['venta_id'];
        $monto_viejo = floatval($viejo['monto_usd']);

        $conexion->query("UPDATE ventas SET deuda_usd = deuda_usd + $monto_viejo WHERE id = $venta_id");
        $conexion->query("DELETE FROM historico_abonos WHERE id = $abono_id");
        $mensaje = "Pago reversado. La deuda se ha sumado nuevamente al cliente.";
    }
}

// ========================================================================
// 4. CONSULTA ÉLITE ACTUALIZADA
// ========================================================================
$conexion->query("SET SESSION group_concat_max_len = 10000;");

$query_creditos = "
    SELECT 
        c.id, 
        c.nombre, 
        c.cedula,
        c.telefono, 
        SUM(v.deuda_usd) as deuda_total, 
        MAX(v.fecha) as ultima_compra,
        DATEDIFF(CURDATE(), MIN(v.fecha)) as dias_mora,
            (
            SELECT GROUP_CONCAT(CONCAT(v_sub.id, '☻', DATE_FORMAT(v_sub.fecha, '%d/%m/%Y %h:%i %p'), '☻', v_sub.deuda_usd, '☻', v_sub.prods, '☻', IFNULL(v_sub.foto_evidencia, ''), '☻', IFNULL(v_sub.observaciones, '')) SEPARATOR '◘')
            FROM (
                SELECT v2.id, v2.fecha, v2.deuda_usd, v2.cliente_id, v2.foto_evidencia, v2.observaciones,
                       GROUP_CONCAT(CONCAT(TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM dv.cantidad)), 'x ', p.nombre) SEPARATOR ', ') as prods
                FROM ventas v2
                JOIN detalle_ventas dv ON v2.id = dv.venta_id
                JOIN productos p ON dv.producto_id = p.id
                WHERE v2.deuda_usd > 0
                GROUP BY v2.id
            ) as v_sub
            WHERE v_sub.cliente_id = c.id
        ) as desglose_compras,
        IFNULL((
            SELECT GROUP_CONCAT(CONCAT(ha.id, '☻', ha.monto_usd, '☻', ha.metodo_pago, '☻', IFNULL(ha.referencia, ''), '☻', DATE_FORMAT(ha.fecha, '%d/%m/%Y %h:%i %p')) ORDER BY ha.fecha DESC SEPARATOR '◘')
            FROM historico_abonos ha
            JOIN ventas v3 ON ha.venta_id = v3.id
            WHERE v3.cliente_id = c.id
        ), 'Ninguno') as historial_abonos
    FROM clientes c
    JOIN ventas v ON c.id = v.cliente_id
    WHERE v.deuda_usd > 0
    GROUP BY c.id
    ORDER BY dias_mora DESC, deuda_total DESC
";
$resultado = $conexion->query($query_creditos);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agrosuministro La Milagrosa</title>
    <link rel="stylesheet" href="css/style.css?v=<?php echo time(); ?>">
    <style>
        .pantalla-completa { padding: 2rem; max-width: 1200px; margin: 0 auto; }
        .buscador-grande { width: 100%; padding: 1rem; font-size: 1.1rem; border: 2px solid var(--borde); border-radius: 8px; margin-bottom: 2rem; }
        .btn-cobrar { background-color: #10b981; color: white; border: none; padding: 0.5rem 1rem; border-radius: 6px; font-weight: bold; cursor: pointer; text-decoration: none;}
        .btn-detalles { background-color: #3b82f6; color: white; border: none; padding: 0.5rem 1rem; border-radius: 6px; font-weight: bold; cursor: pointer; margin-right: 0.5rem; text-decoration: none;}
        
        #modal-detalles .modal-caja { display: flex; flex-direction: column; gap: 1.2rem; }
        .modal-fondo { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; z-index: 1000; }

        @media print {
            body * { visibility: hidden; }
            #modal-detalles, #modal-detalles * { visibility: visible; }
            #modal-detalles { position: absolute !important; left: 0 !important; top: 0 !important; width: 100% !important; background: white !important; display: block !important; }
            #modal-detalles .modal-caja { width: 100% !important; max-width: 100% !important; box-shadow: none !important; border: none !important; padding: 0 !important; margin: 0 !important; }
            div[style*="max-height"], div[style*="overflow"] { max-height: none !important; overflow: visible !important; height: auto !important; }
            #detalles-productos, #detalles-abonos { max-height: none !important; overflow: visible !important; height: auto !important; }
            #detalles-productos > div { page-break-inside: avoid !important; }
            .btn-cerrar, .botones-accion { display: none !important; }
            .btn-foto-print, .ocultar-print { display: none !important; }
        }
    </style>
</head>
<body style="background-color: #f8fafc;">
    <header class="top-bar">
        <div style="display: flex; align-items: center; gap: 1rem;">
            <a href="menu.php" style="text-decoration: none; font-size: 1.5rem; color: var(--texto-principal);">⬅️</a>
            <h1>Gestión de Créditos y Cobranzas</h1>
        </div>
        <div>
            Cajero: <strong style="color: var(--primario);"><?php echo $_SESSION['usuario_nombre']; ?></strong>
        </div>
    </header>

    <main class="pantalla-completa">
        <?php if($mensaje): ?>
            <div style="background: #d1fae5; color: #065f46; padding: 1rem; border-radius: 6px; margin-bottom: 1.5rem; font-weight: bold;">✅ <?php echo $mensaje; ?></div>
        <?php endif; ?>

        <input type="text" id="buscador-clientes" class="buscador-grande" placeholder="🔍 Buscar cliente por nombre o cédula...">

        <div style="background: white; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); overflow-x: auto;">
            <table class="tabla-inventario" id="tabla-creditos">
                <thead>
                    <tr>
                        <th>Cédula</th>
                        <th>Nombre</th>
                        <th>Deuda Total</th>
                        <th>Última Compra</th>
                        <th>Mora</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($resultado && $resultado->num_rows > 0): while ($cliente = $resultado->fetch_assoc()): 
                            $deuda_usd = $cliente['deuda_total']; $deuda_bs = $deuda_usd * $tasa; $dias_mora = $cliente['dias_mora'];
                            $telefono = $cliente['telefono'] ?? '';
                            $clase_estado = 'estado-ok'; $texto_estado = 'Al Día';
                            if ($dias_mora > 30) { $clase_estado = 'estado-critico'; $texto_estado = 'Moroso (+30 días)'; }
                            elseif ($dias_mora > 15) { $clase_estado = 'estado-advertencia'; $texto_estado = 'Atención (+15 días)'; }

                            // ARMADO INTELIGENTE DEL MENSAJE WHATSAPP
        $tlf_limpio = preg_replace('/[^0-9]/', '', $telefono);
        if (strpos($tlf_limpio, '0') === 0) $tlf_limpio = '58' . substr($tlf_limpio, 1);
        
        $texto_wa = "Hola *" . trim($cliente['nombre']) . "*, te saludamos de *Agrosuministro La Milagrosa* para compartirte el balance de tu cuenta.\n\n";
        $texto_wa .= "🔹 *Facturas Pendientes:*\n";
        
        $cli_id = $cliente['id'];

        // CONSULTA REFORZADA: Trae la factura + todos sus productos concatenados
        // CONSULTA REFORZADA: Trae la factura + observaciones + productos
        $sql_tickets_wa = "
            SELECT v.id, v.deuda_usd, v.observaciones, DATE_FORMAT(v.fecha, '%d/%m/%Y') as fecha_fmt,
                   GROUP_CONCAT(CONCAT(TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM dv.cantidad)), 'x ', p.nombre) SEPARATOR ', ') as productos_llevados
            FROM ventas v
            LEFT JOIN detalle_ventas dv ON v.id = dv.venta_id
            LEFT JOIN productos p ON dv.producto_id = p.id
            WHERE v.cliente_id = $cli_id AND v.deuda_usd > 0
            GROUP BY v.id
            ORDER BY v.fecha ASC
        ";
        $q_tickets_wa = $conexion->query($sql_tickets_wa);
        
        while($t_wa = $q_tickets_wa->fetch_assoc()) {
            $id_tick = $t_wa['id'];
            $texto_wa .= "• Factura #" . str_pad($id_tick, 5, '0', STR_PAD_LEFT) . " (del " . $t_wa['fecha_fmt'] . ")\n";
            
            // INYECCIÓN DEL DETALLE DE PRODUCTOS EN EL WHATSAPP
            if (!empty($t_wa['productos_llevados'])) {
                $texto_wa .= "   📦 *" . $t_wa['productos_llevados'] . "*\n";
            }
                 if (!empty($t_wa['observaciones'])) {
                $texto_wa .= "   📝 _Acuerdo: " . trim($t_wa['observaciones']) . "_\n";
            }               
                                $q_abonos_wa = $conexion->query("SELECT monto_usd, metodo_pago, referencia, DATE_FORMAT(fecha, '%d/%m/%Y') as fecha_fmt FROM historico_abonos WHERE venta_id = $id_tick ORDER BY fecha ASC");
                                
                                if($q_abonos_wa->num_rows > 0) {
                                    while($ab = $q_abonos_wa->fetch_assoc()) {
                                        $metodo = ucwords(str_replace('_', ' ', str_replace('credito_', '', $ab['metodo_pago'])));
                                        $ref_texto = (!empty($ab['referencia'])) ? " - Ref: " . $ab['referencia'] : "";
                                        $texto_wa .= "  ↳ Abono: $" . number_format($ab['monto_usd'], 2) . " (" . $metodo . " - " . $ab['fecha_fmt'] . $ref_texto . ")\n";
                                    }
                                }
                                $texto_wa .= "  *-> Resta: $" . number_format($t_wa['deuda_usd'], 2) . "*\n\n";
                            }
                            $texto_wa .= "💰 *Deuda Total: $" . number_format($deuda_usd, 2) . "*";
                            $texto_wa_encoded = rawurlencode($texto_wa);
                    ?>
                    <tr class="fila-cliente">
                        <td class="dato-cedula"><?php echo htmlspecialchars($cliente['cedula']); ?></td>
                        <td class="dato-nombre" style="font-weight: bold;"><?php echo htmlspecialchars($cliente['nombre']); ?></td>
                        <td>
                            <strong style="color: #ef4444; font-size: 1.1rem;">$<?php echo number_format($deuda_usd, 2); ?></strong><br>
                            <small><?php echo number_format($deuda_bs, 2, ',', '.'); ?> Bs</small>
                        </td>
                        <td><?php echo date('d/m/Y', strtotime($cliente['ultima_compra'])); ?></td>
                        <td style="font-weight: bold; text-align: center;"><?php echo $dias_mora; ?> días</td>
                        <td><span class="badge-estado <?php echo $clase_estado; ?>"><?php echo $texto_estado; ?></span></td>
                        <td style="display: flex; align-items: center; gap: 0.5rem;">
                            <button class="btn-detalles" onclick="abrirModalDetalles('<?php echo htmlspecialchars($cliente['nombre'], ENT_QUOTES); ?>', '<?php echo number_format($deuda_usd, 2); ?>', '<?php echo htmlspecialchars($cliente['desglose_compras'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($cliente['historial_abonos'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($telefono, ENT_QUOTES); ?>', '<?php echo $texto_wa_encoded; ?>')">📄 Detalles</button>
                            <button class="btn-cobrar" onclick="abrirModalCobro(<?php echo $cliente['id']; ?>, '<?php echo htmlspecialchars($cliente['nombre'], ENT_QUOTES); ?>', <?php echo $deuda_usd; ?>, '<?php echo number_format($deuda_bs, 2, ',', '.'); ?>')">💰 Abonar</button>
                            <a href="https://wa.me/<?php echo $tlf_limpio; ?>?text=<?php echo $texto_wa_encoded; ?>" target="_blank" class="btn-cobrar" style="background: #25D366; padding: 0.5rem 0.8rem;" title="Enviar WhatsApp">💬 WA</a>
                        </td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr><td colspan="7" style="text-align: center; padding: 3rem;">No hay deudores registrados.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <div id="modal-detalles" class="modal-fondo">
        <div class="modal-caja" style="max-width: 600px; background: white; padding: 1.5rem; border-radius: 8px;">
            <div class="modal-cabecera" style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--borde); padding-bottom: 0.5rem; margin-bottom: 1rem;">
                <h3 style="margin: 0;">Estado de Cuenta - Mi Negocio POS</h3>
                <button class="btn-cerrar" onclick="cerrarModal('modal-detalles')" style="background: none; border: none; font-size: 1.2rem; cursor: pointer;">X</button>
            </div>
            
            <div style="display: flex; flex-direction: column; gap: 1.2rem;">
                <div style="display: flex; justify-content: space-between;">
                    <p style="margin: 0; font-size: 1.1rem; color: var(--texto-secundario);">Cliente: <strong id="detalles-nombre" style="color: var(--primario);">---</strong></p>
                    <p style="margin: 0; font-size: 1.1rem; color: var(--texto-secundario);">Deuda: <strong id="detalles-deuda" style="color: #ef4444; font-size: 1.2rem;">$0.00</strong></p>
                </div>
                
                <div style="border-left: 4px solid #3b82f6; padding-left: 1rem; max-height: 250px; overflow-y: auto;">
                    <p style="font-weight: bold; color: #1e3a8a; margin-bottom: 1rem;">📦 Compras Pendientes:</p>
                    <div id="detalles-productos" style="line-height: 1.5;"></div>
                </div>

                <div style="border-left: 4px solid #10b981; padding-left: 1rem; max-height: 200px; overflow-y: auto;">
                    <p style="font-weight: bold; color: #064e3b; margin-bottom: 0.5rem;">📉 Historial Abonos:</p>
                    <div id="detalles-abonos" style="line-height: 1.5; color: #334155;"></div>
                </div>
                
                <p style="text-align: center; font-size: 0.8rem; color: #94a3b8; margin-top: 1rem;">Documento generado el <?php echo date('d/m/Y h:i A'); ?></p>
            </div>

            <div class="botones-accion" style="padding-top: 1rem; margin-top: 1rem; border-top: 1px solid var(--borde); display: flex; gap: 1rem; justify-content: flex-end;">
                <button onclick="window.print()" class="btn-detalles" style="background: #475569;">🖨️ Imprimir</button>
                <a id="btn-whatsapp-cobro" href="#" target="_blank" class="btn-cobrar" style="background: #25D366;">💬 WhatsApp</a>
            </div>
        </div>
    </div>

    <div id="modal-editar-abono" class="modal-fondo">
        <div class="modal-caja" style="max-width: 450px; background: white; padding: 2rem; border-radius: 8px;">
            <h3 style="color: #f59e0b; margin-top: 0; margin-bottom: 1.5rem; border-bottom: 1px solid var(--borde); padding-bottom: 0.5rem;">✏️ Editar Abono</h3>
            <form method="POST">
                <input type="hidden" name="abono_id" id="edit-abono-id">
                
                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                    <div>
                        <label style="font-weight: bold; font-size: 0.85rem;">Corregir Monto</label>
                        <input type="number" step="0.01" name="monto_abono" id="edit-abono-monto" required style="width:100%; box-sizing: border-box; padding:0.8rem; border:1px solid var(--borde); border-radius:4px;">
                    </div>
                    <div>
                        <label style="font-weight: bold; font-size: 0.85rem;">Moneda</label>
                        <select name="moneda_abono" id="edit-abono-moneda" onchange="actualizarMetodosPago('editar')" required style="width:100%; box-sizing: border-box; padding:0.8rem; border:1px solid var(--borde); border-radius:4px; font-weight: bold; background: #f8fafc;">
                            <option value="usd">USD ($)</option>
                            <option value="bs">Bs</option>
                        </select>
                    </div>
                </div>

                <div style="margin-bottom: 1rem;">
                    <label style="font-weight: bold; font-size: 0.85rem;">Método de Pago</label>
                    <select name="metodo_pago" id="edit-abono-metodo" required style="width:100%; box-sizing: border-box; padding:0.8rem; border:1px solid var(--borde); border-radius:4px;">
                        </select>
                </div>
                <div style="margin-bottom: 1.5rem;">
                    <label style="font-weight: bold; font-size: 0.85rem;">Referencia</label>
                    <input type="text" name="referencia" id="edit-abono-ref" style="width:100%; box-sizing: border-box; padding:0.8rem; border:1px solid var(--borde); border-radius:4px;">
                </div>
                <div style="display:flex; gap:1rem;">
                    <button type="submit" name="editar_abono" class="btn-cobrar" style="width:100%; background:#f59e0b;">Guardar</button>
                    <button type="button" onclick="cerrarModal('modal-editar-abono')" class="btn-detalles" style="width:100%; background:#94a3b8; margin:0;">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <div id="modal-cobro" class="modal-fondo">
        <div class="modal-caja" style="max-width: 450px; background: white; padding: 2rem; border-radius: 8px;">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--borde); padding-bottom: 1rem; margin-bottom: 1rem;">
                <h3 style="color: #1e3a8a; margin: 0;">Registrar Pago</h3>
                <button onclick="cerrarModal('modal-cobro')" style="background: none; border: none; font-size: 1.2rem; cursor: pointer;">X</button>
            </div>
            
            <p style="margin-bottom: 1rem; color: #64748b;">Cliente: <strong id="modal-nombre-cliente" style="color: #0f172a;"></strong></p>
            
            <div style="background: #fef2f2; border: 1px solid #fecaca; padding: 1rem; border-radius: 6px; margin-bottom: 1.5rem; text-align: center;">
                <span style="color: #ef4444; font-size: 0.9rem; font-weight: bold;">DEUDA TOTAL</span><br>
                <span style="color: #b91c1c; font-size: 1.8rem; font-weight: bold;">$<span id="modal-deuda-cliente">0.00</span></span><br>
                <span style="color: #64748b; font-size: 1.1rem; font-weight: bold;"><span id="modal-deuda-bs">0,00</span> Bs</span>
            </div>

            <form method="POST">
                <input type="hidden" name="cliente_id" id="modal-cliente-id">
                
                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                    <div>
                        <label style="font-size: 0.85rem; font-weight: bold;">Monto a Abonar</label>
                        <input type="number" step="0.01" name="monto_abono" id="input-monto" required style="width:100%; box-sizing: border-box; padding: 0.8rem; font-size: 1.2rem; font-weight: bold; border: 1px solid var(--borde); border-radius: 4px;" placeholder="0.00">
                    </div>
                    <div>
                        <label style="font-size: 0.85rem; font-weight: bold;">Moneda</label>
                        <select name="moneda_abono" id="moneda-abono-nuevo" onchange="actualizarMetodosPago('nuevo')" required style="width:100%; box-sizing: border-box; padding: 0.8rem; font-size: 1.1rem; font-weight: bold; background: #eff6ff; color: #1e3a8a; border: 1px solid var(--borde); border-radius: 4px;">
                            <option value="usd">USD ($)</option>
                            <option value="bs">Bs</option>
                        </select>
                    </div>
                </div>

                <div style="margin-bottom: 1rem;">
                    <label style="font-size: 0.85rem; font-weight: bold;">Método de Pago</label>
                    <select name="metodo_pago" id="metodo-pago-nuevo" required style="width:100%; box-sizing: border-box; padding: 0.8rem; border: 1px solid var(--borde); border-radius: 4px;">
                        </select>
                </div>
                <div style="margin-bottom: 1.5rem;">
                    <label style="font-size: 0.85rem; font-weight: bold;">Referencia (Opcional)</label>
                    <input type="text" name="referencia" placeholder="Últimos dígitos..." style="width:100%; box-sizing: border-box; padding: 0.8rem; border: 1px solid var(--borde); border-radius: 4px;">
                </div>
                <div style="display: flex; gap: 1rem;">
                    <button type="submit" name="procesar_abono" class="btn-cobrar" style="width: 100%; background: #10b981; font-size: 1.1rem; padding: 0.8rem;">Confirmar Abono</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function cerrarModal(id) { document.getElementById(id).style.display = 'none'; }

        // Función que actualiza las listas desplegables en vivo
        function actualizarMetodosPago(tipo) {
            const monedaSelect = tipo === 'nuevo' ? document.getElementById('moneda-abono-nuevo') : document.getElementById('edit-abono-moneda');
            const metodoSelect = tipo === 'nuevo' ? document.getElementById('metodo-pago-nuevo') : document.getElementById('edit-abono-metodo');
            const prefijo = tipo === 'editar' ? 'credito_' : ''; 
            
            let moneda = monedaSelect.value;
            metodoSelect.innerHTML = ''; 
            
            if (moneda === 'usd') {
                metodoSelect.innerHTML += `<option value="${prefijo}efectivo_usd">Efectivo $</option>`;
                metodoSelect.innerHTML += `<option value="${prefijo}zelle">Zelle / Binance</option>`;
                metodoSelect.innerHTML += `<option value="${prefijo}otro">Otro</option>`;
            } else {
                metodoSelect.innerHTML += `<option value="${prefijo}pago_movil">Pago Móvil</option>`;
                metodoSelect.innerHTML += `<option value="${prefijo}punto">Punto de Venta</option>`;
                metodoSelect.innerHTML += `<option value="${prefijo}biopago">Biopago</option>`;
                metodoSelect.innerHTML += `<option value="${prefijo}efectivo_bs">Efectivo Bs</option>`;
                metodoSelect.innerHTML += `<option value="${prefijo}transferencia_bs">Transferencia Bs</option>`;
                metodoSelect.innerHTML += `<option value="${prefijo}otro">Otro</option>`;
            }
        }

        // Inicializar los menús al cargar la pantalla
        document.addEventListener("DOMContentLoaded", () => {
            actualizarMetodosPago('nuevo');
            actualizarMetodosPago('editar');
        });

        function abrirModalEditarAbono(id, monto, metodo, ref) {
            cerrarModal('modal-detalles'); 
            document.getElementById('edit-abono-id').value = id;
            document.getElementById('edit-abono-monto').value = monto;
            
            // Si el método tiene 'usd' o 'zelle', seteamos el select en USD, sino en Bs.
            let esDolares = (metodo.includes('usd') || metodo.includes('zelle'));
            document.getElementById('edit-abono-moneda').value = esDolares ? 'usd' : 'bs';
            
            actualizarMetodosPago('editar'); // Disparamos la recarga de opciones
            
            document.getElementById('edit-abono-metodo').value = metodo;
            document.getElementById('edit-abono-ref').value = ref;
            document.getElementById('modal-editar-abono').style.display = 'flex';
        }

        function abrirModalCobro(clienteId, nombreCliente, deudaTotal, deudaBs) {
            document.getElementById('modal-cliente-id').value = clienteId;
            document.getElementById('modal-nombre-cliente').innerText = nombreCliente;
            document.getElementById('modal-deuda-cliente').innerText = parseFloat(deudaTotal).toFixed(2);
            document.getElementById('modal-deuda-bs').innerText = deudaBs;
            document.getElementById('input-monto').value = ''; 
            
            // Reiniciar menú por defecto
            document.getElementById('moneda-abono-nuevo').value = 'usd';
            actualizarMetodosPago('nuevo');

            document.getElementById('modal-cobro').style.display = 'flex';
        }

        document.getElementById('buscador-clientes').addEventListener('keyup', function() {
            let texto = this.value.toLowerCase();
            document.querySelectorAll('.fila-cliente').forEach(fila => {
                let nombre = fila.querySelector('.dato-nombre').innerText.toLowerCase();
                let cedula = fila.querySelector('.dato-cedula').innerText.toLowerCase();
                fila.style.display = (nombre.includes(texto) || cedula.includes(texto)) ? '' : 'none';
            });
        });

        function abrirModalDetalles(nombre, deudaTotal, desglose, abonos, telefono, urlWaCodificada) {
            document.getElementById('detalles-nombre').innerText = nombre;
            document.getElementById('detalles-deuda').innerText = '$' + deudaTotal;
            let htmlCompras = '';

            if (desglose && desglose.trim() !== '') {
                let compras = desglose.split('◘'); 
                compras.forEach(compra => {
                    let partes = compra.split('☻'); 
                    if(partes.length >= 4) {
                        let id = partes[0].padStart(5, '0'); 
                        let fecha = partes[1]; 
                        let deudaVenta = parseFloat(partes[2]).toFixed(2); 
                        let prods = partes[3];
                        
                       let fotoUrl = (partes[4] && partes[4].trim() !== '') ? partes[4].trim() : '';
                        let botonFotoHTML = '';
                        if (fotoUrl !== '') {
                            botonFotoHTML = `<a href="${fotoUrl}" target="_blank" class="btn-foto-print" style="background: #fef08a; color: #854d0e; font-size: 0.75rem; padding: 2px 6px; border-radius: 4px; text-decoration: none; border: 1px solid #fde047; margin-left: 8px;">📸 Ver Foto</a>`;
                        }

                        // CAPTURAMOS LA OBSERVACIÓN (ES EL ELEMENTO 5 DEL DETALLE)
                        let observacionVenta = (partes[5] && partes[5].trim() !== '') ? partes[5].trim() : '';
                        let bloqueObservacion = '';
                        if (observacionVenta !== '') {
                            bloqueObservacion = `<div style="margin-top: 5px; padding: 4px 8px; background: #fffbeb; border: 1px solid #fde68a; border-radius: 4px; font-size: 0.85rem; color: #b45309; font-family: inherit;">📝 <b>Acuerdo:</b> ${observacionVenta}</div>`;
                        }

                        htmlCompras += `<div style="margin-bottom: 1rem; border-bottom: 1px dashed #cbd5e1; padding-bottom: 0.8rem;">
                                            <div style="display: flex; justify-content: space-between;">
                                                <div><strong style="color: #1e3a8a;">Ticket #${id} <span style="color: #64748b; font-size: 0.85rem;">(${fecha})</span></strong> ${botonFotoHTML}</div>
                                                <span style="color: #ef4444; font-weight: bold;">Resta: $${deudaVenta}</span>
                                            </div>
                                            <div style="color: #475569;">↳ ${prods}</div>
                                            ${bloqueObservacion}
                                        </div>`;
                    }
                });
            }
            document.getElementById('detalles-productos').innerHTML = htmlCompras;
            
            if (abonos === 'Ninguno' || abonos.trim() === '') {
                document.getElementById('detalles-abonos').innerHTML = '<span style="color: #94a3b8; font-style: italic;">No hay abonos previos.</span>';
            } else {
                let htmlAbonos = '';
                abonos.split('◘').forEach(a => {
                    let p = a.split('☻');
                    if(p.length >= 5) {
                        let id_ab = p[0];
                        let monto = parseFloat(p[1]).toFixed(2);
                        let metodo = p[2];
                        let ref = p[3] !== '' ? ` (Ref: ${p[3]})` : '';
                        let fecha = p[4];
                        let metodoLimpio = metodo.replace('credito_', '').replace('_', ' ');

                        htmlAbonos += `
                        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px dashed #cbd5e1; padding: 6px 0;">
                            <span><strong style="color:#10b981;">$${monto}</strong> el ${fecha} <br><small style="color:#64748b; text-transform:capitalize;">${metodoLimpio}${ref}</small></span>
                            <div style="display:flex; gap:8px;" class="ocultar-print">
                                <button onclick="abrirModalEditarAbono(${id_ab}, ${monto}, '${metodo}', '${p[3]}')" style="background:none; border:none; cursor:pointer; font-size:1rem;" title="Editar Abono">✏️</button>
                                <form method="POST" style="margin:0;" onsubmit="return confirm('⚠️ ¿Seguro que deseas REVERSAR este pago de $${monto}? La deuda volverá a sumarse al cliente.');">
                                    <input type="hidden" name="abono_id_elim" value="${id_ab}">
                                    <button type="submit" name="eliminar_abono" style="background:none; border:none; cursor:pointer; font-size:1rem;" title="Reversar Abono">❌</button>
                                </form>
                            </div>
                        </div>`;
                    }
                });
                document.getElementById('detalles-abonos').innerHTML = htmlAbonos;
            }

            let btnWa = document.getElementById('btn-whatsapp-cobro');
            if (telefono && telefono.trim() !== '') {
                let numLimpio = telefono.replace(/\D/g, ''); 
                if (numLimpio.startsWith('0')) numLimpio = '58' + numLimpio.substring(1);
                
                btnWa.href = `https://wa.me/${numLimpio}?text=${urlWaCodificada}`;
                btnWa.style.display = 'inline-block';
            } else { 
                btnWa.href = `https://wa.me/?text=${urlWaCodificada}`;
                btnWa.style.display = 'inline-block';
            }
            
            document.getElementById('modal-detalles').style.display = 'flex';
        }
    </script>
</body>
</html>