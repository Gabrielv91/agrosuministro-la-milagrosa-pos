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
        /* Optimización térmica máxima (Sin márgenes externos para no botar papel) */
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Courier New', Courier, monospace; }
        body { background: #fff; display: flex; justify-content: center; color: #000; padding: 0; }
        
        /* Ancho estándar de impresora térmica pequeña (58mm aprox) */
        .ticket { width: 260px; padding: 5px; font-size: 11px; line-height: 1.2; }
        
        .centro { text-align: center; }
        .divisor { border-top: 1px dashed #000; margin: 4px 0; }
        .flex { display: flex; justify-content: space-between; align-items: center; }
        .bold { font-weight: bold; }
        
        /* Tabla ultra compacta */
        .tabla-prods { width: 100%; margin: 4px 0; border-collapse: collapse; }
        .tabla-prods th { text-align: left; border-bottom: 1px solid #000; font-size: 10px; padding-bottom: 2px; }
        .tabla-prods td { font-size: 11px; padding: 2px 0; vertical-align: top; }
        
        .total-destacado { font-size: 13px; font-weight: bold; margin: 4px 0; border-top: 1px solid #000; border-bottom: 1px solid #000; padding: 4px 0; }
        .alerta-credito { border: 1px solid #000; padding: 4px; text-align: center; margin-top: 4px; font-weight: bold; font-size: 11px; }
        
        @media print {
            body { background: white; }
            .ticket { width: 100%; }
        }
    </style>
</head>
<body onload="window.print(); setTimeout(() => window.close(), 1000);">
    <div class="ticket">
        <div class="centro">
            <h2 style="font-size: 13px;">Agrosuministro La Milagrosa</h2>
            <p style="font-size: 10px;">Rif 197839330</p>
            <p style="font-size: 9px;">DIRECCION </p>
            <p style="font-size: 10px;">LIBERTAD - BARINAS</p>
            <p style="font-size: 10px;">Tel: 0000-0000000</p>
            <p class="bold" style="margin-top: 2px;">NOTA DE ENTREGA</p>
        </div>
        
        <div class="divisor"></div>
        
        <div class="flex"><span><strong>Ticket:</strong> #<?php echo str_pad($id_venta, 6, "0", STR_PAD_LEFT); ?></span><span><?php echo date('d/m/Y h:ia', strtotime($venta['fecha'])); ?></span></div>
        <p><strong>Cajero:</strong> <?php echo substr($venta['cajero'], 0, 15); ?></p>
        <p><strong>Cliente:</strong> <?php echo $venta['nombre'] ? substr($venta['nombre'], 0, 18) : 'Consumidor Final'; ?></p>
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
                    <td><?php echo substr($item['nombre'], 0, 14); ?></td>
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
                <span style="font-size: 9px; font-weight: normal;">Abono: <?php echo number_format($abono_bs, 2, ',', '.'); ?> Bs</span><br>
                <div class="divisor"></div>
                RESTA POR PAGAR:<br>
                <span style="font-size: 13px;"><?php echo number_format($deuda_bs, 2, ',', '.'); ?> Bs</span><br>
                <span style="font-size: 9px; font-weight: normal;">(Ref: $<?php echo number_format($venta['deuda_usd'], 2); ?>)</span>
            </div>
        <?php else: ?>
            <p class="centro bold" style="margin-top: 4px; font-size: 12px;">PAGADO</p>
        <?php endif; ?>

        <div class="divisor"></div>
        
        <div class="centro" style="margin-top: 4px; font-size: 10px;">
            <p>¡Gracias por su compra!</p>
            <p>Conserve este comprobante.</p>
        </div>
    </div>
</body>
</html>