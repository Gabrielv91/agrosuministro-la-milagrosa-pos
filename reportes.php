<?php
session_start();

if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_rol'] != 'admin') {
    header("Location: menu.php");
    exit;
}

require_once 'conexion.php';

// Obtener tasa actual para cálculos cruzados
$q_tasa = $conexion->query("SELECT tasa_dia FROM configuracion ORDER BY id DESC LIMIT 1");
$tasa_actual = ($q_tasa && $q_tasa->num_rows > 0) ? floatval($q_tasa->fetch_assoc()['tasa_dia']) : 1;

// AUTO-PARCHE DE BASE DE DATOS
$check = $conexion->query("SHOW COLUMNS FROM historico_abonos LIKE 'tasa_pago'");
if ($check && $check->num_rows == 0) {
    $conexion->query("ALTER TABLE historico_abonos ADD tasa_pago DECIMAL(10,2) NULL");
    $conexion->query("UPDATE historico_abonos ha JOIN ventas v ON ha.venta_id = v.id SET ha.tasa_pago = v.tasa_aplicada");
}

// --- 1. RESUMEN RÁPIDO ---
$query_hoy = "SELECT SUM(total_usd) as usd, SUM(total_bs) as bs FROM ventas WHERE DATE(fecha) = CURDATE()";
$res_hoy = $conexion->query($query_hoy)->fetch_assoc();
$ventas_usd_hoy = $res_hoy['usd'] ?? 0;
$ventas_bs_hoy = $res_hoy['bs'] ?? 0;

function obtenerMejorVendedor($conexion, $condicion) {
    $query = "SELECT u.nombre, SUM(v.total_usd) as total FROM ventas v JOIN usuarios u ON v.usuario_id = u.id WHERE $condicion GROUP BY v.usuario_id ORDER BY total DESC LIMIT 1";
    $res = $conexion->query($query);
    return $res ? $res->fetch_assoc() : null;
}

$mejor_dia = obtenerMejorVendedor($conexion, "DATE(v.fecha) = CURDATE()");
$mejor_mes = obtenerMejorVendedor($conexion, "MONTH(v.fecha) = MONTH(CURDATE()) AND YEAR(v.fecha) = YEAR(CURDATE())");
$mejor_ano = obtenerMejorVendedor($conexion, "YEAR(v.fecha) = YEAR(CURDATE())");

// --- 2. DATOS PARA LA GRÁFICA ---
$query_grafica = "SELECT MONTH(fecha) as mes_num, SUM(total_usd) as total FROM ventas WHERE YEAR(fecha) = YEAR(CURDATE()) GROUP BY MONTH(fecha) ORDER BY MONTH(fecha)";
$res_grafica = $conexion->query($query_grafica);
$meses_nombres = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
$datos_ventas = array_fill(0, 12, 0); 
if ($res_grafica) {
    while ($fila = $res_grafica->fetch_assoc()) {
        $datos_ventas[$fila['mes_num'] - 1] = floatval($fila['total']);
    }
}

// --- 3. LÓGICA DE LOS FILTROS ---
$where_ventas = ["1=1"]; 
$where_abonos = ["1=1"]; 
$where_gastos = ["1=1"];
$filtro_inicio = $_GET['fecha_inicio'] ?? '';
$filtro_fin = $_GET['fecha_fin'] ?? '';
$filtro_vendedor = $_GET['vendedor_id'] ?? '';

if (!empty($filtro_inicio)) {
    $fecha_esc = $conexion->real_escape_string($filtro_inicio);
    $where_ventas[] = "DATE(v.fecha) >= '$fecha_esc'";
    $where_abonos[] = "DATE(ha.fecha) >= '$fecha_esc'";
    $where_gastos[] = "DATE(g.fecha) >= '$fecha_esc'";
}
if (!empty($filtro_fin)) {
    $fecha_esc = $conexion->real_escape_string($filtro_fin);
    $where_ventas[] = "DATE(v.fecha) <= '$fecha_esc'";
    $where_abonos[] = "DATE(ha.fecha) <= '$fecha_esc'";
    $where_gastos[] = "DATE(g.fecha) <= '$fecha_esc'";
}
if (!empty($filtro_vendedor)) {
    $ven_id = intval($filtro_vendedor);
    $where_ventas[] = "v.usuario_id = $ven_id";
    $where_abonos[] = "ha.usuario_id = $ven_id";
    $where_gastos[] = "g.usuario_id = $ven_id";
}

$where_sql_ventas = implode(" AND ", $where_ventas);
$where_sql_abonos = implode(" AND ", $where_abonos);
$where_sql_gastos = implode(" AND ", $where_gastos);

// --- 4. CAJAS FUERTES ---
$caja = [
    'pago_movil'   => ['nombre' => '📱 PAGO MÓVIL', 'usd' => 0, 'bs' => 0, 'tipo' => 'bs', 'color' => '#3b82f6'],
    'biopago'      => ['nombre' => '👆 BIOPAGO', 'usd' => 0, 'bs' => 0, 'tipo' => 'bs', 'color' => '#2563eb'],
    'efectivo_bs'  => ['nombre' => '💵 EFECTIVO BS', 'usd' => 0, 'bs' => 0, 'tipo' => 'bs', 'color' => '#1e40af'],
    'punto_venta'  => ['nombre' => '💳 PUNTO VENTA', 'usd' => 0, 'bs' => 0, 'tipo' => 'bs', 'color' => '#475569'],
    'efectivo_usd' => ['nombre' => '💵 EFECTIVO USD', 'usd' => 0, 'bs' => 0, 'tipo' => 'usd', 'color' => '#10b981'],
    'otro'         => ['nombre' => '💳 OTROS', 'usd' => 0, 'bs' => 0, 'tipo' => 'usd', 'color' => '#94a3b8']
];

// Sumar Ventas a la Caja (Soporta Desgloses Mixtos y Simples)
$q_v = "SELECT metodo_pago, total_usd, total_bs, mixto_usd, mixto_efec_bs, mixto_pm_bs, mixto_pv_bs, mixto_bp_bs FROM ventas v WHERE $where_sql_ventas AND metodo_pago NOT LIKE 'credito%'";
$r_v = $conexion->query($q_v);
if ($r_v) {
    while($row = $r_v->fetch_assoc()){
        $m = strtolower(trim($row['metodo_pago']));
        
        if ($m == 'mixto') {
            $caja['efectivo_usd']['usd'] += floatval($row['mixto_usd']);
            $caja['efectivo_usd']['bs']  += floatval($row['mixto_usd'] * $tasa_actual);
            
            $caja['efectivo_bs']['bs']   += floatval($row['mixto_efec_bs']);
            $caja['efectivo_bs']['usd']  += floatval($row['mixto_efec_bs'] / $tasa_actual);
            
            $caja['pago_movil']['bs']    += floatval($row['mixto_pm_bs']);
            $caja['pago_movil']['usd']   += floatval($row['mixto_pm_bs'] / $tasa_actual);
            
            $caja['punto_venta']['bs']   += floatval($row['mixto_pv_bs']);
            $caja['punto_venta']['usd']  += floatval($row['mixto_pv_bs'] / $tasa_actual);
            
            $caja['biopago']['bs']       += floatval($row['mixto_bp_bs']);
            $caja['biopago']['usd']      += floatval($row['mixto_bp_bs'] / $tasa_actual);
        } else {
            if(isset($caja[$m])) { 
                $caja[$m]['usd'] += floatval($row['total_usd']); 
                $caja[$m]['bs'] += floatval($row['total_bs']); 
            } else { 
                $caja['otro']['usd'] += floatval($row['total_usd']); 
                $caja['otro']['bs'] += floatval($row['total_bs']); 
            }
        }
    }
}

// Sumar Abonos a la Caja
$q_a = "SELECT ha.metodo_pago, SUM(ha.monto_usd) as usd, SUM(ha.monto_usd * IFNULL(ha.tasa_pago, v.tasa_aplicada)) as bs 
        FROM historico_abonos ha JOIN ventas v ON ha.venta_id = v.id WHERE $where_sql_abonos GROUP BY ha.metodo_pago";
$r_a = $conexion->query($q_a);
if ($r_a) {
    while($row = $r_a->fetch_assoc()){
        $m = str_replace('credito_', '', strtolower(trim($row['metodo_pago'])));
        if(isset($caja[$m])) { $caja[$m]['usd'] += $row['usd']; $caja[$m]['bs'] += $row['bs']; } 
        else { $caja['otro']['usd'] += $row['usd']; $caja['otro']['bs'] += $row['bs']; }
    }
}

// --- 5. DETALLES DE MOVIMIENTOS ---
$movimientos = [];

// 5.1 Extraer las Ventas
$q_detalles = "
    SELECT v.id as ticket_id, v.fecha, u.nombre as vendedor, v.total_usd, v.total_bs, v.metodo_pago, v.abono_usd, v.deuda_usd, v.referencia, v.tasa_aplicada,
           v.mixto_usd, v.mixto_efec_bs, v.mixto_pm_bs, v.mixto_pv_bs, v.mixto_bp_bs,
           GROUP_CONCAT(CONCAT(TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM dv.cantidad)), 'x ', p.nombre) SEPARATOR '<br>') as detalle_productos
    FROM ventas v
    JOIN usuarios u ON v.usuario_id = u.id
    LEFT JOIN detalle_ventas dv ON dv.venta_id = v.id
    LEFT JOIN productos p ON dv.producto_id = p.id
    WHERE $where_sql_ventas
    GROUP BY v.id
";
$res_detalles = $conexion->query($q_detalles);
if($res_detalles){
    while($v = $res_detalles->fetch_assoc()){
        $v['tipo_registro'] = 'venta';
        $movimientos[] = $v;
    }
}

// 5.2 Extraer los Abonos
$q_hist_abonos = "
    SELECT 
        ha.fecha, 
        u.nombre as vendedor,
        SUM(ha.monto_usd) as abono_usd, 
        SUM(ha.monto_usd * IFNULL(ha.tasa_pago, v.tasa_aplicada)) as abono_bs,
        ha.metodo_pago, 
        ha.referencia, 
        MAX(IFNULL(ha.tasa_pago, v.tasa_aplicada)) as tasa_aplicada,
        GROUP_CONCAT(ha.venta_id SEPARATOR ', ') as tickets_afectados
    FROM historico_abonos ha
    JOIN ventas v ON ha.venta_id = v.id
    JOIN usuarios u ON ha.usuario_id = u.id
    WHERE $where_sql_abonos
      AND TIMESTAMPDIFF(SECOND, v.fecha, ha.fecha) > 2 
    GROUP BY ha.fecha, ha.usuario_id, ha.metodo_pago, ha.referencia
";
$res_hist_abonos = $conexion->query($q_hist_abonos);
if($res_hist_abonos){
    while($a = $res_hist_abonos->fetch_assoc()){
        $a['tipo_registro'] = 'abono';
        $t_arr = explode(', ', $a['tickets_afectados']);
        $tickets_format = array_map(function($t) { return "#".str_pad($t, 5, "0", STR_PAD_LEFT); }, $t_arr);
        $a['detalle_productos'] = "💰 <strong>Abono a Cuenta</strong><br><small style='color:#64748b;'>Aplicado a deudas de: " . implode(', ', $tickets_format) . "</small>";
        $movimientos[] = $a;
    }
}

// 5.3 Extraer los Gastos y Restar de la Caja
$total_gastos_usd = 0;
$total_gastos_bs = 0;
$q_gastos = "SELECT g.*, u.nombre as vendedor FROM gastos g JOIN usuarios u ON g.usuario_id = u.id WHERE $where_sql_gastos";
$res_gastos = $conexion->query($q_gastos);

if($res_gastos){
    while($g = $res_gastos->fetch_assoc()){
        $m = strtolower(trim($g['metodo_pago']));
        $monto_crudo = floatval($g['monto']);
        
        $es_bs = (strpos($m, 'bs') !== false || strpos($m, 'movil') !== false || strpos($m, 'biopago') !== false || strpos($m, 'punto') !== false);
        
        if ($es_bs) {
            $m_bs = $monto_crudo;
            $m_usd = $monto_crudo / $tasa_actual;
        } else {
            $m_usd = $monto_crudo;
            $m_bs = $monto_crudo * $tasa_actual;
        }

        $total_gastos_usd += $m_usd;
        $total_gastos_bs += $m_bs;

        if(isset($caja[$m])) { 
            $caja[$m]['usd'] -= $m_usd; 
            $caja[$m]['bs'] -= $m_bs; 
        } else { 
            $caja['otro']['usd'] -= $m_usd; 
            $caja['otro']['bs'] -= $m_bs; 
        }

        $g['tipo_registro'] = 'gasto';
        $g['monto_usd_calc'] = $m_usd;
        $g['monto_bs_calc'] = $m_bs;
        $movimientos[] = $g;
    }
}

// 5.4 Ordenar cronológicamente todo mezclado
usort($movimientos, function($a, $b) {
    return strtotime($b['fecha']) - strtotime($a['fecha']);
});

$vendedores = $conexion->query("SELECT id, nombre FROM usuarios WHERE rol IN ('admin', 'vendedor')");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reportes Estadísticos - Mi Negocio POS</title>
    <link rel="stylesheet" href="css/style.css?v=<?php echo time(); ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .pantalla-completa { padding: 2rem; width: 100%; box-sizing: border-box; }
        .grid-dashboard { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
        .panel-filtros { background: white; padding: 1.5rem; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); margin-bottom: 1.5rem; display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap; }
        .detalle-productos { font-size: 0.85rem; color: #475569; max-width: 300px; line-height: 1.4; }
        
        .cajas-cuadre { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
        .caja-monto { background: white; padding: 1.2rem; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); border-top: 4px solid; text-align: center; }
        .caja-monto .titulo { font-size: 0.8rem; font-weight: bold; color: var(--texto-secundario); margin-bottom: 0.5rem; text-transform: uppercase; }
        .caja-monto .monto-principal { font-size: 1.4rem; font-weight: bold; margin-bottom: 0.2rem; }
        .caja-monto .monto-secundario { font-size: 0.85rem; color: #64748b; font-weight: bold; }
        .caja-monto.bs { border-color: #3b82f6; } .caja-monto.bs .monto-principal { color: #1e3a8a; }
        .caja-monto.usd { border-color: #10b981; } .caja-monto.usd .monto-principal { color: #065f46; }

        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center; }
        .modal-caja { background: white; padding: 2rem; border-radius: 10px; width: 90%; max-width: 400px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
    </style>
</head>
<body style="background-color: #f1f5f9;">
    <header class="top-bar">
        <div style="display: flex; align-items: center; gap: 1rem;">
            <a href="menu.php" style="text-decoration: none; font-size: 1.5rem; color: var(--texto-principal);" title="Volver">⬅️</a>
            <h1>Centro de Comando y Reportes</h1>
        </div>
        <div>
            <button onclick="document.getElementById('modalGasto').style.display='flex'" class="btn-login" style="background-color: #ef4444; margin-right: 0.5rem; padding: 0.5rem 1rem;">💸 Registrar Gasto</button>
            <button onclick="window.print()" class="btn-login" style="background-color: #64748b; margin-right: 1rem; padding: 0.5rem 1rem;">🖨️ Imprimir Cuadre</button>
            <span class="badge-rol" style="background-color: var(--primario); color: white;">MODO ADMINISTRADOR</span>
        </div>
    </header>

    <main class="pantalla-completa">
        
        <div class="grid-dashboard">
            <div class="tarjeta-login" style="padding: 1.5rem; border-left: 5px solid #10b981; margin: 0;">
                <h3 style="color: var(--texto-secundario); font-size: 1rem; margin-bottom: 0.5rem;">Ingresos de Hoy</h3>
                <div style="font-size: 2rem; font-weight: bold; color: var(--texto-principal);">$<?php echo number_format($ventas_usd_hoy, 2); ?></div>
                <div style="color: #10b981; font-weight: bold;"><?php echo number_format($ventas_bs_hoy, 2, ',', '.'); ?> Bs</div>
            </div>
            
            <div class="tarjeta-login" style="padding: 1.5rem; border-left: 5px solid #ef4444; margin: 0;">
                <h3 style="color: var(--texto-secundario); font-size: 1rem; margin-bottom: 0.5rem;">Gastos del Filtro</h3>
                <div style="font-size: 1.8rem; font-weight: bold; color: #ef4444;">-$<?php echo number_format($total_gastos_usd, 2); ?></div>
                <div style="color: #ef4444; font-weight: bold;">-<?php echo number_format($total_gastos_bs, 2, ',', '.'); ?> Bs</div>
            </div>

            <div class="tarjeta-login" style="padding: 1.5rem; border-left: 5px solid #3b82f6; margin: 0;">
                <h3 style="color: var(--texto-secundario); font-size: 1rem; margin-bottom: 0.5rem;">Líder del Día</h3>
                <div style="font-size: 1.2rem; font-weight: bold;"><?php echo $mejor_dia ? htmlspecialchars($mejor_dia['nombre']) : 'N/A'; ?></div>
                <div style="color: #64748b;">$<?php echo $mejor_dia ? number_format($mejor_dia['total'], 2) : '0.00'; ?></div>
            </div>
            <div class="tarjeta-login" style="padding: 1.5rem; border-left: 5px solid #8b5cf6; margin: 0;">
                <h3 style="color: var(--texto-secundario); font-size: 1rem; margin-bottom: 0.5rem;">Líder del Mes</h3>
                <div style="font-size: 1.2rem; font-weight: bold;"><?php echo $mejor_mes ? htmlspecialchars($mejor_mes['nombre']) : 'N/A'; ?></div>
                <div style="color: #64748b;">$<?php echo $mejor_mes ? number_format($mejor_mes['total'], 2) : '0.00'; ?></div>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 2.5fr; gap: 2rem;">
            
            <div class="tarjeta-login" style="padding: 1.5rem; margin: 0; height: fit-content;">
                <h2 style="margin-bottom: 1rem; font-size: 1.2rem;">Crecimiento <?php echo date('Y'); ?></h2>
                <canvas id="graficaVentas"></canvas>
            </div>

            <div>
                <form class="panel-filtros" method="GET" action="reportes.php">
                    <div>
                        <label style="font-size: 0.85rem; font-weight: bold;">Fecha Desde</label><br>
                        <input type="date" name="fecha_inicio" value="<?php echo htmlspecialchars($filtro_inicio); ?>" style="padding: 0.6rem; border: 1px solid var(--borde); border-radius: 5px;">
                    </div>
                    <div>
                        <label style="font-size: 0.85rem; font-weight: bold;">Fecha Hasta</label><br>
                        <input type="date" name="fecha_fin" value="<?php echo htmlspecialchars($filtro_fin); ?>" style="padding: 0.6rem; border: 1px solid var(--borde); border-radius: 5px;">
                    </div>
                    <div>
                        <label style="font-size: 0.85rem; font-weight: bold;">Vendedor</label><br>
                        <select name="vendedor_id" style="padding: 0.6rem; border: 1px solid var(--borde); border-radius: 5px; min-width: 150px;">
                            <option value="">Todos</option>
                            <?php while ($v = $vendedores->fetch_assoc()): ?>
                                <option value="<?php echo $v['id']; ?>" <?php if ($filtro_vendedor == $v['id']) echo 'selected'; ?>><?php echo htmlspecialchars($v['nombre']); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div>
                        <button type="submit" class="btn-login" style="margin: 0; padding: 0.6rem 1.5rem;">Filtrar</button>
                        <a href="reportes.php" style="margin-left: 0.5rem; color: var(--texto-secundario); text-decoration: none; font-size: 0.9rem;">Limpiar</a>
                    </div>
                </form>

                <h3 style="margin-bottom: 1rem; color: var(--texto-secundario);">💰 Dinero en Caja (Desglosado Exacto)</h3>

                <?php 
                $suma_total_bolivares = 0;
                $suma_total_dolares = 0;

                foreach($caja as $key => $datos) {
                    if ($datos['tipo'] == 'bs') {
                        $suma_total_bolivares += $datos['bs'];
                    } else {
                        $suma_total_dolares += $datos['usd'];
                    }
                }
                ?>
                <div style="display: flex; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap;">
                    <div style="background: linear-gradient(135deg, #1e3a8a, #3b82f6); color: white; padding: 1.5rem; border-radius: 10px; flex: 1; min-width: 250px; box-shadow: 0 4px 10px rgba(59,130,246,0.3);">
                        <div style="font-size: 0.9rem; font-weight: bold; text-transform: uppercase; opacity: 0.9; margin-bottom: 0.5rem;">🇻🇪 Total Bolívares en Caja (Neto)</div>
                        <div style="font-size: 2.2rem; font-weight: bold;"><?php echo number_format($suma_total_bolivares, 2, ',', '.'); ?> Bs</div>
                        <div style="font-size: 0.85rem; opacity: 0.8; margin-top: 0.5rem;">(Suma de Punto + Pago Móvil + Biopago + Efec Bs)</div>
                    </div>
                    <div style="background: linear-gradient(135deg, #064e3b, #10b981); color: white; padding: 1.5rem; border-radius: 10px; flex: 1; min-width: 250px; box-shadow: 0 4px 10px rgba(16,185,129,0.3);">
                        <div style="font-size: 0.9rem; font-weight: bold; text-transform: uppercase; opacity: 0.9; margin-bottom: 0.5rem;">💵 Total Dólares en Caja (Neto)</div>
                        <div style="font-size: 2.2rem; font-weight: bold;">$<?php echo number_format($suma_total_dolares, 2); ?></div>
                        <div style="font-size: 0.85rem; opacity: 0.8; margin-top: 0.5rem;">(Suma de Efectivo USD + Otros)</div>
                    </div>
                </div>

                <div class="cajas-cuadre">
                    <?php foreach($caja as $key => $datos): ?>
                        <div class="caja-monto <?php echo $datos['tipo']; ?>" style="border-color: <?php echo $datos['color']; ?>">
                            <div class="titulo"><?php echo $datos['nombre']; ?></div>
                            <?php if($datos['tipo'] == 'bs'): ?>
                                <div class="monto-principal" style="<?php echo $datos['bs'] < 0 ? 'color: red;' : ''; ?>"><?php echo number_format($datos['bs'], 2, ',', '.'); ?> Bs</div>
                                <div class="monto-secundario" style="<?php echo $datos['usd'] < 0 ? 'color: red;' : ''; ?>">Ref: $<?php echo number_format($datos['usd'], 2); ?></div>
                            <?php else: ?>
                                <div class="monto-principal" style="<?php echo $datos['usd'] < 0 ? 'color: red;' : ''; ?>">$<?php echo number_format($datos['usd'], 2); ?></div>
                                <div class="monto-secundario" style="<?php echo $datos['bs'] < 0 ? 'color: red;' : ''; ?>"><?php echo number_format($datos['bs'], 2, ',', '.'); ?> Bs</div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div style="background: white; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); overflow-x: auto;">
                    <table class="tabla-inventario" style="margin: 0; width: 100%;">
                        <thead>
                            <tr>
                                <th>Acción</th>
                                <th>Fecha / Hora</th>
                                <th>Usuario</th>
                                <th style="width: 35%;">Detalle del Registro</th>
                                <th>Método de Pago</th>
                                <th style="text-align: right;">Monto (Ingreso / Egreso)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($movimientos)): ?>
                                <?php foreach ($movimientos as $mov): 
                                    $es_abono = ($mov['tipo_registro'] == 'abono');
                                    $es_gasto = ($mov['tipo_registro'] == 'gasto');
                                    $metodo_limpio = strtolower($mov['metodo_pago']);
                                    $es_moneda_bs = (strpos($metodo_limpio, 'bs') !== false || strpos($metodo_limpio, 'movil') !== false || strpos($metodo_limpio, 'biopago') !== false || strpos($metodo_limpio, 'punto') !== false);
                                    
                                    if ($es_gasto) {
                                        $ingreso_usd = $mov['monto_usd_calc'];
                                        $ingreso_bs = $mov['monto_bs_calc'];
                                    } elseif ($es_abono) {
                                        $ingreso_usd = $mov['abono_usd'];
                                        $ingreso_bs = $mov['abono_bs'];
                                    } else {
                                        $tasa = floatval($mov['tasa_aplicada'] > 0 ? $mov['tasa_aplicada'] : 36.50);
                                        if (strpos($metodo_limpio, 'credito_') === 0) {
                                            $ingreso_usd = $mov['abono_usd'];
                                            $ingreso_bs = $mov['abono_usd'] * $tasa;
                                        } elseif ($metodo_limpio == 'credito') {
                                            $ingreso_usd = 0; $ingreso_bs = 0;
                                        } else {
                                            $ingreso_usd = $mov['total_usd']; $ingreso_bs = $mov['total_bs'];
                                        }
                                    }
                                ?>
                                    <tr style="border-bottom: 1px solid #f1f5f9; <?php echo $es_abono ? 'background-color: #f8fafc;' : ''; echo $es_gasto ? 'background-color: #fef2f2;' : ''; ?>">
                                        <td style="font-weight: bold; text-align: center;">
                                            <?php if ($es_gasto): ?>
                                                <div style="display:flex; gap: 5px; justify-content: center;">
                                                    <button onclick='abrirEditarGasto(<?php echo json_encode($mov); ?>)' style="padding: 0.2rem 0.5rem; background: #3b82f6; color: white; border: none; border-radius: 4px; cursor: pointer;">✏️</button>
                                                    <a href="acciones_gasto.php?eliminar=<?php echo $mov['id']; ?>" onclick="return confirm('¿Seguro que deseas eliminar este gasto? El dinero volverá a sumarse a la caja.')" style="padding: 0.2rem 0.5rem; background: #ef4444; color: white; text-decoration: none; border-radius: 4px; font-size: 0.8rem; display:flex; align-items:center;">🗑️</a>
                                                </div>
                                            <?php elseif ($es_abono): ?>
                                                <div style="color: #10b981; background: #d1fae5; padding: 0.3rem 0.6rem; border-radius: 4px; border: 1px solid #a7f3d0; display: inline-block; font-size: 0.85rem; font-weight: bold;">
                                                    💰 Abono
                                                </div>
                                            <?php else: ?>
                                                <a href="imprimir_ticket.php?id=<?php echo $mov['ticket_id']; ?>" target="_blank" style="text-decoration: none; color: #3b82f6; background: #eff6ff; padding: 0.3rem 0.6rem; border-radius: 4px; border: 1px solid #bfdbfe; display: inline-flex; align-items: center; gap: 5px;">
                                                    🖨️ #<?php echo str_pad($mov['ticket_id'], 5, "0", STR_PAD_LEFT); ?>
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                        <td style="color: var(--texto-secundario); font-size: 0.85rem;"><?php echo date('d/m/Y h:i A', strtotime($mov['fecha'])); ?></td>
                                        <td style="font-size: 0.9rem;"><?php echo htmlspecialchars($mov['vendedor']); ?></td>
                                        
                                        <td class="detalle-productos">
                                            <?php if ($es_gasto): ?>
                                                <strong><?php echo htmlspecialchars($mov['descripcion']); ?></strong>
                                            <?php else: ?>
                                                <?php echo $mov['detalle_productos']; ?>
                                            <?php endif; ?>
                                        </td>
                                        
                                        <td style="font-size: 0.9rem;">
                                            <?php 
                                            if ($es_gasto) {
                                                echo "<span style='font-weight:bold; color: #ef4444;'>" . strtoupper(str_replace('_', ' ', $mov['metodo_pago'])) . "</span>";
                                            } elseif ($es_abono) {
                                                $real = strtoupper(str_replace(['credito_', '_'], ['', ' '], $mov['metodo_pago']));
                                                echo "<span style='font-weight:bold; color: #10b981;'>ABONO (" . $real . ")</span>";
                                                if (!empty($mov['referencia'])) echo "<br><small style='color: #3b82f6; font-weight:bold;'>Ref: " . htmlspecialchars($mov['referencia']) . "</small>";
                                            } else {
                                                if ($metodo_limpio == 'mixto') {
                                                    echo "<span style='font-weight:bold; color: #8b5cf6;'>🔄 PAGO MIXTO</span>";
                                                    if (isset($mov['mixto_usd']) && floatval($mov['mixto_usd']) > 0) echo "<br><small style='color:#166534;'>💵 Efec $: " . number_format($mov['mixto_usd'], 2) . "</small>";
                                                    if (isset($mov['mixto_efec_bs']) && floatval($mov['mixto_efec_bs']) > 0) echo "<br><small style='color:#1e3a8a;'>💵 Efec Bs: " . number_format($mov['mixto_efec_bs'], 2, ',', '.') . "</small>";
                                                    if (isset($mov['mixto_pm_bs']) && floatval($mov['mixto_pm_bs']) > 0) echo "<br><small style='color:#3b82f6;'>📱 PM: " . number_format($mov['mixto_pm_bs'], 2, ',', '.') . "</small>";
                                                    if (isset($mov['mixto_pv_bs']) && floatval($mov['mixto_pv_bs']) > 0) echo "<br><small style='color:#475569;'>💳 PV: " . number_format($mov['mixto_pv_bs'], 2, ',', '.') . "</small>";
                                                    if (isset($mov['mixto_bp_bs']) && floatval($mov['mixto_bp_bs']) > 0) echo "<br><small style='color:#2563eb;'>👆 Bio: " . number_format($mov['mixto_bp_bs'], 2, ',', '.') . "</small>";
                                                    if (!empty($mov['referencia'])) echo "<br><small style='color: #3b82f6; font-weight:bold;'>Ref: " . htmlspecialchars($mov['referencia']) . "</small>";
                                                } elseif (strpos($metodo_limpio, 'credito_') === 0) {
                                                    $real = strtoupper(str_replace(['credito_', '_'], ['', ' '], $mov['metodo_pago']));
                                                    echo "<span style='font-weight:bold; color: #ef4444;'>CRÉDITO</span><br><small style='color: #10b981; font-weight:bold;'>Abonó Inicial en " . $real . "</small>";
                                                } elseif ($metodo_limpio == 'credito') {
                                                    echo "<span style='font-weight:bold; color: #ef4444;'>CRÉDITO TOTAL</span>";
                                                } else {
                                                    echo "<span style='font-weight:bold;'>" . strtoupper(str_replace('_', ' ', $mov['metodo_pago'])) . "</span>";
                                                    if (!empty($mov['referencia'])) echo "<br><small style='color: #3b82f6; font-weight:bold;'>Ref: " . htmlspecialchars($mov['referencia']) . "</small>";
                                                }
                                            }
                                            ?>
                                        </td>

                                        <td style="text-align: right;">
                                            <?php if ($es_gasto): ?>
                                                <span style="font-weight: bold; font-size: 1.1rem; color: #dc2626;">- $<?php echo number_format($ingreso_usd, 2); ?></span><br>
                                                <small style="color: #ef4444; font-weight: bold;">- <?php echo number_format($ingreso_bs, 2, ',', '.'); ?> Bs</small>
                                            <?php elseif ($ingreso_usd == 0 && $ingreso_bs == 0): ?>
                                                <span style="font-weight: bold; font-size: 1.1rem; color: #94a3b8;">$0.00</span><br>
                                                <small style="color: #94a3b8;">(Fiado Sin Abono)</small>
                                            <?php elseif ($es_moneda_bs): ?>
                                                <span style="font-weight: bold; font-size: 1.1rem; color: #1e3a8a;">+ <?php echo number_format($ingreso_bs, 2, ',', '.'); ?> Bs</span><br>
                                                <small style="color: #64748b; font-weight: bold;">Ref: $<?php echo number_format($ingreso_usd, 2); ?></small>
                                            <?php else: ?>
                                                <span style="font-weight: bold; font-size: 1.1rem; color: #065f46;">+ $<?php echo number_format($ingreso_usd, 2); ?></span><br>
                                                <small style="color: #64748b; font-weight: bold;">+ <?php echo number_format($ingreso_bs, 2, ',', '.'); ?> Bs</small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="6" style="text-align: center; padding: 2rem;">No se encontraron movimientos registrados.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <div id="modalGasto" class="modal-overlay">
        <div class="modal-caja">
            <h2 style="margin-bottom: 1rem; color: var(--texto-principal);">💸 Registrar Nuevo Gasto</h2>
            <form action="guardar_gasto.php" method="POST">
                <div style="margin-bottom: 1rem;">
                    <label style="display:block; margin-bottom: 0.5rem; font-weight: bold; font-size: 0.9rem;">Concepto del Gasto</label>
                    <input type="text" name="descripcion" required style="width: 100%; padding: 0.8rem; border: 1px solid var(--borde); border-radius: 5px; box-sizing: border-box;" placeholder="¿Qué se pagó?">
                </div>
                <div style="margin-bottom: 1rem;">
                    <label style="display:block; margin-bottom: 0.5rem; font-weight: bold; font-size: 0.9rem;">¿De dónde salió el dinero?</label>
                    <select name="metodo_pago" required style="width: 100%; padding: 0.8rem; border: 1px solid var(--borde); border-radius: 5px; box-sizing: border-box;">
                        <option value="efectivo_usd">💵 Efectivo USD (En Dólares)</option>
                        <option value="efectivo_bs">💵 Efectivo BS (En Bolívares)</option>
                        <option value="pago_movil">📱 Pago Móvil (En Bolívares)</option>
                        <option value="punto_venta">💳 Punto de Venta (En Bolívares)</option>
                        <option value="biopago">👆 Biopago (En Bolívares)</option>
                    </select>
                </div>
                <div style="margin-bottom: 1.5rem;">
                    <label style="display:block; margin-bottom: 0.5rem; font-weight: bold; font-size: 0.9rem;">Monto a descontar</label>
                    <input type="number" step="0.01" name="monto" required style="width: 100%; padding: 0.8rem; border: 1px solid var(--borde); border-radius: 5px; box-sizing: border-box;" placeholder="Monto crudo en su moneda">
                </div>
                <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                    <button type="button" onclick="document.getElementById('modalGasto').style.display='none'" style="padding: 0.8rem 1.5rem; border: none; border-radius: 5px; background: #e2e8f0; color: #475569; cursor: pointer; font-weight: bold;">Cancelar</button>
                    <button type="submit" class="btn-login" style="margin: 0; padding: 0.8rem 1.5rem; background: #ef4444;">Guardar</button>
                </div>
            </form>
        </div>
    </div>

    <div id="modalEditarGasto" class="modal-overlay">
        <div class="modal-caja" style="border-top: 5px solid #3b82f6;">
            <h2 style="margin-bottom: 1rem; color: var(--texto-principal);">✏️ Editar Gasto</h2>
            <form action="acciones_gasto.php" method="POST">
                <input type="hidden" name="accion" value="editar">
                <input type="hidden" name="gasto_id" id="edit_id">
                <div style="margin-bottom: 1rem;">
                    <label style="display:block; margin-bottom: 0.5rem; font-weight: bold; font-size: 0.9rem;">Concepto del Gasto</label>
                    <input type="text" name="descripcion" id="edit_descripcion" required style="width: 100%; padding: 0.8rem; border: 1px solid var(--borde); border-radius: 5px; box-sizing: border-box;">
                </div>
                <div style="margin-bottom: 1rem;">
                    <label style="display:block; margin-bottom: 0.5rem; font-weight: bold; font-size: 0.9rem;">¿De dónde salió el dinero?</label>
                    <select name="metodo_pago" id="edit_metodo" required style="width: 100%; padding: 0.8rem; border: 1px solid var(--borde); border-radius: 5px; box-sizing: border-box;">
                        <option value="efectivo_usd">💵 Efectivo USD (En Dólares)</option>
                        <option value="efectivo_bs">💵 Efectivo BS (En Bolívares)</option>
                        <option value="pago_movil">📱 Pago Móvil (En Bolívares)</option>
                        <option value="punto_venta">💳 Punto de Venta (En Bolívares)</option>
                        <option value="biopago">👆 Biopago (En Bolívares)</option>
                    </select>
                </div>
                <div style="margin-bottom: 1.5rem;">
                    <label style="display:block; margin-bottom: 0.5rem; font-weight: bold; font-size: 0.9rem;">Monto Corregido</label>
                    <input type="number" step="0.01" name="monto" id="edit_monto" required style="width: 100%; padding: 0.8rem; border: 1px solid var(--borde); border-radius: 5px; box-sizing: border-box;">
                </div>
                <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                    <button type="button" onclick="document.getElementById('modalEditarGasto').style.display='none'" style="padding: 0.8rem 1.5rem; border: none; border-radius: 5px; background: #e2e8f0; color: #475569; cursor: pointer; font-weight: bold;">Cancelar</button>
                    <button type="submit" class="btn-login" style="margin: 0; padding: 0.8rem 1.5rem; background: #3b82f6;">Actualizar Gasto</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function abrirEditarGasto(datos) {
            document.getElementById('edit_id').value = datos.id;
            document.getElementById('edit_descripcion').value = datos.descripcion;
            document.getElementById('edit_monto').value = datos.monto;
            document.getElementById('edit_metodo').value = datos.metodo_pago;
            document.getElementById('modalEditarGasto').style.display = 'flex';
        }

        const ctx = document.getElementById('graficaVentas').getContext('2d');
        const meses = <?php echo json_encode($meses_nombres); ?>;
        const datos_usd = <?php echo json_encode($datos_ventas); ?>;

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: meses,
                datasets: [{
                    label: 'Ingresos Mensuales (USD)',
                    data: datos_usd,
                    borderColor: '#0284c7',
                    backgroundColor: 'rgba(2, 132, 199, 0.1)',
                    borderWidth: 3,
                    pointBackgroundColor: '#10b981',
                    pointRadius: 5,
                    fill: true,
                    tension: 0.3
                }]
            },
            options: { responsive: true, plugins: { legend: { position: 'top' } } }
        });
    </script>
</body>
</html>