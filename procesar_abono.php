<?php
// 1. SEGURIDAD DE SESIÓN
session_start();
if (!isset($_SESSION['usuario_id']) || ($_SESSION['usuario_rol'] != 'admin' && $_SESSION['usuario_rol'] != 'vendedor')) {
    die("Error de seguridad: Acceso denegado.");
}

require_once 'conexion.php';

// Activar reporte de errores estricto para MySQL
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Extraer variables del formulario de cobro
    $cliente_id = intval($_POST['cliente_id']);
    $monto_abono = floatval($_POST['monto_abono']);
    $metodo_pago = trim($_POST['metodo_pago']);
    $referencia_bruta = trim($_POST['referencia'] ?? '');
    $usuario_id = $_SESSION['usuario_id']; // El cajero que recibe el dinero

    // Convertir referencia vacía a NULL
    $referencia = ($referencia_bruta === '') ? NULL : $referencia_bruta;

    // Validar datos mínimos obligatorios
    if ($cliente_id <= 0 || $monto_abono <= 0 || empty($metodo_pago)) {
        die("Error: Datos de formulario inválidos.");
    }

    // Validación de seguridad de referencia desde el backend
    if (($metodo_pago == 'pago_movil' || $metodo_pago == 'biopago') && empty($referencia)) {
        die("Error de Seguridad: Para pagos digitales es obligatoria la referencia.");
    }

    try {
        // Iniciamos la transacción para proteger las deudas y la caja
        $conexion->begin_transaction();

        // 2. BUSCAR TODAS LAS VENTAS PENDIENTES DEL CLIENTE (MODIFICACIÓN: Traemos también foto_evidencia)
        $query_ventas = "SELECT id, deuda_usd, foto_evidencia FROM ventas WHERE cliente_id = ? AND deuda_usd > 0 ORDER BY fecha ASC FOR UPDATE";
        $stmt_ventas = $conexion->prepare($query_ventas);
        $stmt_ventas->bind_param("i", $cliente_id);
        $stmt_ventas->execute();
        $resultado_ventas = $stmt_ventas->get_result();

        $dinero_restante = $monto_abono;

        // 3. REPARTIR EL DINERO EN LAS FACTURAS
        while ($venta = $resultado_ventas->fetch_assoc()) {
            if ($dinero_restante <= 0) break; // Si ya distribuimos todo el abono, salimos del ciclo

            $venta_id = $venta['id'];
            $deuda_actual = floatval($venta['deuda_usd']);

            // Determinar cuánto le vamos a descontar a ESTA factura en específico
            if ($dinero_restante >= $deuda_actual) {
                // El abono cubre o supera la deuda de esta factura. Queda saldada en $0
                $abono_a_esta_venta = $deuda_actual;
                $nueva_deuda_venta = 0;
            } else {
                // El dinero restante no alcanza para saldarla completa, solo la reduce un poco
                $abono_a_esta_venta = $dinero_restante;
                $nueva_deuda_venta = $deuda_actual - $abono_a_esta_venta;
            }

            // Descontamos el dinero usado de nuestra billetera de abono temporal
            $dinero_restante -= $abono_a_esta_venta;

            // 4. ACTUALIZAR LA FACTURA EN LA BASE DE DATOS
            $query_update_venta = "UPDATE ventas SET deuda_usd = ?, abono_usd = abono_usd + ? WHERE id = ?";
            $stmt_update = $conexion->prepare($query_update_venta);
            $stmt_update->bind_param("ddi", $nueva_deuda_venta, $abono_a_esta_venta, $venta_id);
            $stmt_update->execute();
            $stmt_update->close();

            // 5. GUARDAR LA EVIDENCIA EN EL HISTORIAL (Con referencia y método de pago exacto)
            $query_historico = "INSERT INTO historico_abonos (venta_id, usuario_id, monto_usd, metodo_pago, referencia) VALUES (?, ?, ?, ?, ?)";
            $stmt_hist = $conexion->prepare($query_historico);
            $stmt_hist->bind_param("iidss", $venta_id, $usuario_id, $abono_a_esta_venta, $metodo_pago, $referencia);
            $stmt_hist->execute();
            $stmt_hist->close();

            // =========================================================================
            // AGREGADO: AUTO-LIMPIEZA DE IMÁGENES CUANDO LA FACTURA QUEDA SALDADA ($0.00)
            // =========================================================================
            if ($nueva_deuda_venta <= 0.01) {
                $ruta_foto = trim($venta['foto_evidencia']);
                
                // Si la factura guardaba una ruta y el archivo físico de verdad existe en la PC
                if (!empty($ruta_foto) && file_exists($ruta_foto)) {
                    unlink($ruta_foto); // Destruye el archivo físico (.jpg/.jpeg) de la galería
                }

                // Dejamos el campo de la foto limpio en la base de datos
                $query_clean_photo = "UPDATE ventas SET foto_evidencia = '' WHERE id = ?";
                $stmt_clean = $conexion->prepare($query_clean_photo);
                $stmt_clean->bind_param("i", $venta_id);
                $stmt_clean->execute();
                $stmt_clean->close();
            }
            // =========================================================================
        }

        $stmt_ventas->close();

        // Confirmamos todos los cambios matemáticos de forma definitiva
        $conexion->commit();

        // Devolvemos al panel de créditos con la bandera verde de éxito
        header("Location: creditos.php?exito=1");
        exit;

    } catch (Exception $e) {
        // Si hay una falla técnica, cancelamos para no alterar los balances
        $conexion->rollback();
        die("Error crítico al procesar el abono de crédito: " . $e->getMessage());
    }
} else {
    // Si alguien entra directo a la URL sin enviar datos, lo botamos
    header("Location: creditos.php");
    exit;
}
?>