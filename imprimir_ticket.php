<?php
session_start();
require_once 'conexion.php';

if (!isset($_GET['id'])) { die("ID de venta no proporcionado."); }
$id_venta = intval($_GET['id']);

// Obtener datos de la venta y el cliente
$query = "SELECT v.*, c.nombre, c.cedula, u.nombre as cajero 
          FROM ventas v 
          LEFT JOIN clientes c ON v.cliente_id = c.id 
          LEFT JOIN usuarios u ON v.usuario_id = u.id 
          WHERE v.id = ?";
$stmt = $conexion->prepare($query);
$stmt->bind_param("i", $id_venta);
$stmt->execute();
$venta = $stmt->get_result()->fetch_assoc();

if (!$venta) { die("Venta no encontrada."); }

// Obtener productos de la venta
$query_det = "SELECT dv.*, p.nombre FROM detalle_ventas dv JOIN productos p ON dv.producto_id = p.id WHERE dv.venta_id = ?";
$stmt_det = $conexion->prepare($query_det);
$stmt_det->bind_param("i", $id_venta);
$stmt_det->execute();
$detalles = $stmt_det->get_result();

// Guardamos la tasa aplicada en una variable
$tasa = floatval($venta['tasa_aplicada']);
if($tasa <= 0) { $tasa = 36.50; } 
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ticket #<?php echo str_pad($id_venta, 6, "0", STR_PAD_LEFT); ?></title>
    <style>
        /* =======================================================
           MAGIA ANTI-DESPERDICIO (TAMAÑO 80mm EXACTO)
           ======================================================= */
        @page {
            margin: 0;
            size: 80mm auto; /* Le dice a Chrome que el papel es de 80mm y alto automático */
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Courier New', Courier, monospace; }
        body { background: #fff; display: flex; justify-content: center; color: #000; padding: 0; }
        
        /* Ajuste específico para impresora de 80mm (El área imprimible es ~72mm) */
        .ticket { width: 72mm; padding: 5px; font-size: 13px; line-height: 1.3; margin: 0 auto; }
        
        .centro { text-align: center; }
        .divisor { border-top: 1px dashed #000; margin: 5px 0; }
        .flex { display: flex; justify-content: space-between; align-items: center; }
        .bold { font-weight: bold; }
        
        /* Tabla ajustada para 80mm */
        .tabla-prods { width: 100%; margin: 5px 0; border-collapse: collapse; }
        .tabla-prods th { text-align: left; border-bottom: 1px solid #000; font-size: 12px; padding-bottom: 3px; }
        .tabla-prods td { font-size: 13px; padding: 3px 0; vertical-align: top; }
        
        .total-destacado { font-size: 15px; font-weight: bold; margin: 5px 0; border-top: 1px solid #000; border-bottom: 1px solid #000; padding: 5px 0; }
        .alerta-credito { border: 1px solid #000; padding: 5px; text-align: center; margin-top: 5px; font-weight: bold; font-size: 12px; }
        
        @media print {
            body { background: white; margin: 0; padding: 0; }
            .ticket { width: 100%; margin: 0; }
        }
    </style>
</head>
<body onload="window.print(); setTimeout(() => window.close(), 1000);">
    <div class="ticket">
        <div class="centro">
            <h2 style="font-size: 16px;">Agrosuministro La Milagrosa</h2>
            <p style="font-size: 12px;">Rif 197839330</p>
            <p style="font-size: 11px;">DIRECCION </p>
            <p style="font-size: 12px;">LIBERTAD - BARINAS</p>
            <p style="font-size: 12px;">Tel: 0000-0000000</p>
            <p class="bold" style="margin-top: 3px; font-size: 13px;">NOTA DE ENTREGA</p>
        </div>
        
        <div class="divisor"></div>
        
        <div class="flex"><span><strong>Ticket:</strong> #<?php echo str_pad($id_venta, 6, "0", STR_PAD_LEFT); ?></span><span style="font-size: 11px;"><?php echo date('d/m/Y h:ia', strtotime($venta['fecha'])); ?></span></div>
        <p><strong>Cajero:</strong> <?php echo substr($venta['cajero'], 0, 20); ?></p>
        <p><strong>Cliente:</strong> <?php echo $venta['nombre'] ? substr($venta['nombre'], 0, 25) : 'Consumidor Final'; ?></p>
        <?php if($venta['cedula']) echo "<p><strong>C.I/RIF:</strong> {$venta['cedula']}</p>"; ?>
        
        <div class="divisor"></div>

        <table class="tabla-prods">
            <thead>
                <tr>
                    <th style="width: 15%;">Cant</th>
                    <th style="width: 50%;">Descripción</th>
                    <th style="width: 35%; text-align:right;">Total(Bs)</th>
                </tr>
            </thead>
            <tbody>
                <?php while($item = $detalles->fetch_assoc()): 
                    $subtotal_bs = floatval($item['subtotal']) * $tasa;
                ?>
                <tr>
                    <td><?php echo floatval($item['cantidad']); ?></td>
                    <td><?php echo substr($item['nombre'], 0, 20); ?></td>
                    <td style="text-align:right;"><?php echo number_format($subtotal_bs, 2, ',', '.'); ?></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>

        <div class="flex total-destacado">
            <span>TOTAL A PAGAR:</span> 
            <span><?php echo number_format($venta['total_bs'], 2, ',', '.'); ?> Bs</span>
        </div>

        <?php if($venta['deuda_usd'] > 0): 
            $abono_bs = floatval($venta['abono_usd']) * $tasa;
            $deuda_bs = floatval($venta['deuda_usd']) * $tasa;
        ?>
            <div class="alerta-credito">
                *** VENTA A CRÉDITO ***<br>
                <span style="font-size: 11px; font-weight: normal;">Abono: <?php echo number_format($abono_bs, 2, ',', '.'); ?> Bs</span><br>
                <div class="divisor"></div>
                RESTA POR PAGAR:<br>
                <span style="font-size: 15px;"><?php echo number_format($deuda_bs, 2, ',', '.'); ?> Bs</span><br>
                <span style="font-size: 11px; font-weight: normal;">(Ref: $<?php echo number_format($venta['deuda_usd'], 2); ?>)</span>
            </div>
        <?php else: ?>
            <p class="centro bold" style="margin-top: 5px; font-size: 14px;">PAGADO</p>
        <?php endif; ?>

        <div class="divisor"></div>
        
        <div class="centro" style="margin-top: 5px; font-size: 12px;">
            <p>¡Gracias por su compra!</p>
            <p>Conserve este comprobante.</p>
        </div>
    </div>
</body>
</html>