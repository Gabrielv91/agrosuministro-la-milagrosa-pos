<?php
// 1. SEGURIDAD 
session_start();

if (!isset($_SESSION['usuario_id']) || ($_SESSION['usuario_rol'] != 'admin' && $_SESSION['usuario_rol'] != 'vendedor')) {
    header("Location: index.php");
    exit;
}

require_once 'conexion.php';

// 3. Consultar las tasas del día
$query_tasa = "SELECT tasa_dia, tasa_euro FROM configuracion ORDER BY id DESC LIMIT 1";
$resultado_tasa = $conexion->query($query_tasa);
$tasa = 36.50; 
$tasa_euro = 1.08; 

if ($resultado_tasa && $resultado_tasa->num_rows > 0) {
    $fila = $resultado_tasa->fetch_assoc();
    $tasa = $fila['tasa_dia'];
    $tasa_euro = $fila['tasa_euro'] ?? 1.08;
}

// 4. Consultar todos los productos incluyendo los nuevos campos de precios
$query_productos = "SELECT id, nombre, codigo_barras, precio_usd, precio_mayor_bcv, precio_efectivo_detal, precio_efectivo_mayor, tipo_unidad, stock FROM productos";
$resultado_productos = $conexion->query($query_productos);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Punto de Venta</title>
    <link rel="stylesheet" href="css/style.css?v=<?php echo time(); ?>">
    <style>
        #panel-camara { background: #f8fafc; padding: 1rem; border-radius: 8px; border: 2px dashed #cbd5e1; margin-top: 1rem; text-align: center; }
        #video-camara { width: 100%; border-radius: 6px; background: #000; display: block; }
        #foto-preview { width: 100%; border-radius: 6px; display: none; border: 2px solid #10b981; }

        /* Estilo para el selector de Tarifa Mayor / Detal */
        .selector-tarifa { margin-bottom: 1rem; padding: 0.8rem; background: #f8fafc; border-radius: 8px; border: 1px solid #cbd5e1; }
        .selector-tarifa select { width: 100%; padding: 0.6rem; border-radius: 5px; border: 1px solid #cbd5e1; font-weight: bold; font-size: 1rem; color: #1e3a8a; background: white; }

        @media (max-width: 768px) {
            .top-bar { flex-direction: column; align-items: flex-start !important; padding: 1rem; gap: 1rem; }
            .top-bar > div { width: 100%; justify-content: space-between; }
            .contenedor-principal { display: flex; flex-direction: column; padding: 0.5rem; gap: 1.5rem; }
            .panel-productos, .panel-factura { width: 100% !important; box-sizing: border-box; margin: 0; }
            .cuadricula-productos { grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 0.8rem; }
            .tarjeta-producto { padding: 0.8rem; }
            .tarjeta-producto h3 { font-size: 0.95rem; }
            .accion-rapida { display: flex; flex-direction: column; gap: 0.5rem; }
            .accion-rapida input, .accion-rapida button { width: 100%; box-sizing: border-box; margin: 0; font-size: 16px; }
            .cuadricula-pagos { grid-template-columns: repeat(2, 1fr); gap: 0.5rem; }
            .opciones-pago, .totales { padding: 1rem 0; }
        }
    </style>
</head>
<body>
    <header class="top-bar">
        <div style="display: flex; align-items: center; gap: 1rem;">
            <a href="menu.php" style="text-decoration: none; font-size: 1.5rem; color: var(--texto-principal);" title="Volver al Menú Principal">⬅️</a>
            <h1>Agrosuministro La Milagrosa</h1>
        </div>

        <div style="display: flex; align-items: center; gap: 2rem;">
            <div style="font-size: 0.95rem; color: var(--texto-secundario);">
                Cajero: <strong style="color: var(--primario);"><?php echo $_SESSION['usuario_nombre']; ?></strong>
            </div>
            
            <div class="tasa-indicador" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                <span>Tasa Dólar: <strong id="tasa-actual" style="color: #10b981;"><?php echo number_format($tasa, 2, '.', ''); ?> Bs</strong></span>
                <span style="color: #64748b; margin-left: 5px;">| Euro: <strong id="tasa-euro-actual">$<?php echo number_format($tasa_euro, 2, '.', ''); ?></strong></span>
                <?php if($_SESSION['usuario_rol'] == 'admin'): ?>
                    <button onclick="cambiarTasaManual()" style="background: none; border: none; cursor: pointer; font-size: 1.2rem; margin-left: 5px;" title="Actualizar Tasas">✏️</button>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main class="contenedor-principal">
        <section class="panel-productos">
            <div class="buscador">
                <input type="text" id="buscar-producto" placeholder="Escanear código o buscar nombre..." style="font-size: 16px;">
            </div>
            
            <div class="cuadricula-productos">
                <?php 
                if ($resultado_productos && $resultado_productos->num_rows > 0):
                    while ($producto = $resultado_productos->fetch_assoc()): 
                        $id = $producto['id'];
                        $nombre = htmlspecialchars($producto['nombre']);
                        $codigo = htmlspecialchars($producto['codigo_barras']);
                        $tipo_unidad = $producto['tipo_unidad'];
                        $stock = $producto['stock'];
                        
                        $p_bcv_detal = floatval($producto['precio_usd']);
                        $p_bcv_mayor = floatval($producto['precio_mayor_bcv'] > 0 ? $producto['precio_mayor_bcv'] : $p_bcv_detal);
                        $p_efec_detal = floatval($producto['precio_efectivo_detal'] > 0 ? $producto['precio_efectivo_detal'] : $p_bcv_detal);
                        $p_efec_mayor = floatval($producto['precio_efectivo_mayor'] > 0 ? $producto['precio_efectivo_mayor'] : $p_bcv_detal);

                        $precio_bs_defecto = $p_bcv_detal * $tasa;
                        $unidad_texto = ($tipo_unidad == 'kg') ? 'Kg' : (($tipo_unidad == 'm') ? 'm' : 'Und');
                        $stock_mostrar = ($tipo_unidad == 'kg' || $tipo_unidad == 'm') ? number_format($stock, 2, ',', '.') : number_format($stock, 0, ',', '.');
                ?>
                        <div class="tarjeta-producto" style="display: none;" 
                             data-id="<?php echo $id; ?>" 
                             data-nombre="<?php echo strtolower($nombre); ?>" 
                             data-codigo="<?php echo strtolower($codigo); ?>"
                             data-precio="<?php echo $p_bcv_detal; ?>" 
                             data-precio-bcv-detal="<?php echo $p_bcv_detal; ?>"
                             data-precio-bcv-mayor="<?php echo $p_bcv_mayor; ?>"
                             data-precio-efectivo-detal="<?php echo $p_efec_detal; ?>"
                             data-precio-efectivo-mayor="<?php echo $p_efec_mayor; ?>"
                             data-unidad="<?php echo $tipo_unidad; ?>"
                             data-stock="<?php echo $stock; ?>">
                            
                            <h3><?php echo $nombre; ?> <small style="color: #64748b; font-size: 0.8rem;">(<?php echo $codigo; ?>)</small></h3>
                            <p class="precio" id="render-precio-usd-<?php echo $id; ?>">$<?php echo number_format($p_bcv_detal, 2); ?> / <?php echo $unidad_texto; ?></p>
                            <p class="precio-bs" id="render-precio-bs-<?php echo $id; ?>" style="font-weight: bold; color: #10b981;">
                                <?php echo number_format($precio_bs_defecto, 2, ',', '.'); ?> Bs
                            </p>
                            
                            <p style="font-size: 0.85rem; color: #f59e0b; margin-top: -5px; margin-bottom: 5px;">
                                📦 Disp: <strong><?php echo $stock_mostrar; ?></strong> <?php echo $unidad_texto; ?>
                            </p>
                            
                            <div class="accion-rapida">
                                <?php if ($tipo_unidad == 'kg'): ?>
                                    <input type="number" step="0.001" placeholder="Ej: 0.400" class="input-cantidad" name="cantidad_<?php echo $id; ?>">
                                <?php else: ?>
                                    <input type="number" step="1" value="1" class="input-cantidad" name="cantidad_<?php echo $id; ?>">
                                <?php endif; ?>
                                <button class="btn-agregar">Añadir</button>
                            </div>
                        </div>
                <?php 
                    endwhile; 
                else: 
                ?>
                    <p>No se encontraron productos en la base de datos.</p>
                <?php endif; ?>
            </div>
        </section>

        <aside class="panel-factura">
            <h2>Ticket de Compra</h2>
            
            <div class="panel-cliente" style="background: white; padding: 1rem; border-radius: 8px; border: 1px solid var(--borde); margin-bottom: 1rem; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
                <div id="contenedor-buscador-cliente" style="position: relative;">
                    <label style="font-size: 0.85rem; font-weight: bold; color: var(--texto-secundario); display: block; margin-bottom: 0.5rem;">👤 Asociar Cliente al Ticket:</label>
                    <input type="text" id="buscador-cliente-pos" placeholder="🔍 Buscar por Cédula o Nombre..." style="width: 100%; box-sizing: border-box; padding: 0.8rem 1rem; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 16px; background-color: #f8fafc;" autocomplete="off">
                    <div id="sugerencias-clientes" style="position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid var(--borde); border-radius: 4px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); z-index: 100; display: none; max-height: 200px; overflow-y: auto;"></div>
                </div>

                <div id="tarjeta-cliente-seleccionado" style="display: none; background: #eff6ff; border: 1px solid #bfdbfe; border-left: 4px solid #3b82f6; padding: 0.8rem; border-radius: 6px; align-items: center; justify-content: space-between;">
                    <div>
                        <div style="font-size: 0.75rem; color: #3b82f6; font-weight: bold; text-transform: uppercase; margin-bottom: 2px;">Cliente Seleccionado</div>
                        <div id="display-cliente-nombre" style="font-weight: bold; color: #1e3a8a; font-size: 1rem;"></div>
                        <div id="display-cliente-cedula" style="font-size: 0.85rem; color: #475569;"></div>
                    </div>
                    <button type="button" onclick="quitarCliente()" style="background: #fee2e2; color: #ef4444; border: none; width: 32px; height: 32px; border-radius: 50%; cursor: pointer; font-weight: bold; display: flex; align-items: center; justify-content: center;" title="Remover cliente">✖</button>
                </div>
                <input type="hidden" id="cliente-cedula"><input type="hidden" id="cliente-nombre"><input type="hidden" id="cliente-telefono">
            </div>

            <div class="lista-ticket" id="lista-ticket">
                <p style="color: #64748b; text-align: center; margin-top: 2rem;">Ningún producto seleccionado</p>
            </div>

            <div class="opciones-pago">
                <div class="selector-tarifa">
                    <label style="font-size: 0.85rem; font-weight: bold; color: #475569; display:block; margin-bottom:0.3rem;">🎯 Tipo de Tarifa / Precio:</label>
                    <select id="modalidad-tarifa">
                        <option value="bcv_detal">🛒 Precio BCV al Detal</option>
                        <option value="bcv_mayor">📦 Precio BCV al Mayor</option>
                        <option value="efectivo_detal">💵 Precio Efectivo $ Detal</option>
                        <option value="efectivo_mayor">💰 Precio Efectivo $ Mayor</option>
                    </select>
                </div>

                <label>Método de Pago:</label>
                <div class="cuadricula-pagos">
                    <label class="caja-pago"><input type="radio" name="metodo-pago" value="efectivo_usd" checked><div class="contenido-caja">💵 Efectivo $</div></label>
                    <label class="caja-pago"><input type="radio" name="metodo-pago" value="efectivo_bs"><div class="contenido-caja">💵 Efectivo Bs</div></label>
                    <label class="caja-pago"><input type="radio" name="metodo-pago" value="punto_venta"><div class="contenido-caja">💳 Punto Venta</div></label>
                    <label class="caja-pago"><input type="radio" name="metodo-pago" value="pago_movil"><div class="contenido-caja">📱 Pago Móvil</div></label>
                    <label class="caja-pago"><input type="radio" name="metodo-pago" value="biopago"><div class="contenido-caja">👆 Biopago</div></label>
                    <label class="caja-pago"><input type="radio" name="metodo-pago" value="mixto"><div class="contenido-caja">🔄 Pago Mixto</div></label>
                    <label class="caja-pago"><input type="radio" name="metodo-pago" value="otro" data-id="radio-otro"><div class="contenido-caja">💳 Otro</div></label>
                    <label class="caja-pago"><input type="radio" name="metodo-pago" value="credito"><div class="contenido-caja">📒 A Crédito</div></label>
                </div>

                <div id="panel-pago-mixto" style="display: none; margin-top: 1rem; background: #f0fdf4; padding: 1rem; border-radius: 6px; border: 1px solid #bbf7d0;">
                    <h4 style="color: #166534; margin-top:0; margin-bottom: 1rem; text-align: center;">🧮 Distribución del Pago Mixto</h4>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-bottom: 0.5rem;">
                        <label style="font-weight: bold; font-size: 0.85rem; display: flex; align-items: center;">💵 Efectivo ($):</label>
                        <input type="number" step="0.01" id="mix-usd" class="input-cliente input-mixto" placeholder="0.00" style="padding: 0.4rem;">
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-bottom: 0.5rem;">
                        <label style="font-weight: bold; font-size: 0.85rem; display: flex; align-items: center;">💵 Efectivo (Bs):</label>
                        <input type="number" step="0.01" id="mix-efec-bs" class="input-cliente input-mixto" placeholder="0.00" style="padding: 0.4rem;">
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-bottom: 0.5rem;">
                        <label style="font-weight: bold; font-size: 0.85rem; display: flex; align-items: center;">📱 Pago Móvil (Bs):</label>
                        <input type="number" step="0.01" id="mix-pm-bs" class="input-cliente input-mixto" placeholder="0.00" style="padding: 0.4rem;">
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-bottom: 0.5rem;">
                        <label style="font-weight: bold; font-size: 0.85rem; display: flex; align-items: center;">💳 Punto Venta (Bs):</label>
                        <input type="number" step="0.01" id="mix-pv-bs" class="input-cliente input-mixto" placeholder="0.00" style="padding: 0.4rem;">
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-bottom: 0.5rem;">
                        <label style="font-weight: bold; font-size: 0.85rem; display: flex; align-items: center;">👆 Biopago (Bs):</label>
                        <input type="number" step="0.01" id="mix-bp-bs" class="input-cliente input-mixto" placeholder="0.00" style="padding: 0.4rem;">
                    </div>

                    <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px dashed #4ade80;">
                        <div style="display: flex; justify-content: space-between; font-weight: bold; font-size: 0.9rem;">
                            <span>Total a Pagar:</span>
                            <span id="mix-total-pagar" style="color: #1e3a8a;">0.00 Bs</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-weight: bold; font-size: 1rem; margin-top: 0.5rem;">
                            <span>Resta por cobrar:</span>
                            <span id="mix-falta" style="color: #ef4444;">0.00 Bs</span>
                        </div>
                    </div>
                </div>

                <div id="panel-otro-detalle" style="display: none; margin-top: 1rem; background: #fffbeb; padding: 1rem; border-radius: 6px; border: 1px solid #fde68a;">
                    <label style="font-weight: bold; color: #d97706; font-size: 0.9rem;">Especifique el método:</label>
                    <input type="text" id="detalle-otro" placeholder="Zelle, Trueque, etc..." class="input-cliente" style="margin-top: 0.5rem; background: white; border-color: #fcd34d; font-size: 16px;">
                </div>

                <div id="panel-referencia-principal" style="display: none; margin-top: 1rem; background: #f8fafc; padding: 1rem; border-radius: 6px; border: 1px solid var(--borde);">
                    <label style="font-weight: bold; color: var(--texto-secundario); font-size: 0.9rem;">N° de Referencia de Pago Móvil:</label>
                    <input type="text" id="referencia-principal" placeholder="Ingrese los últimos dígitos" class="input-cliente" style="margin-top: 0.5rem; background: white; font-size: 16px;">
                </div>

                <div id="panel-credito" class="panel-credito" style="display: none; margin-top: 1rem;">
                    <div id="panel-camara">
                        <label style="font-weight: bold; color: #ef4444; font-size: 0.9rem; margin-bottom: 0.5rem; display: block;">📸 Foto de Evidencia</label>
                        <video id="video-camara" autoplay playsinline></video>
                        <canvas id="canvas-camara" style="display:none;"></canvas>
                        <img id="foto-preview" />
                        
                        <button type="button" id="btn-tomar-foto" class="btn-login" style="background: #3b82f6; margin-top: 0.8rem; width: 100%;">📸 Capturar Foto</button>
                        
                        <button type="button" id="btn-cambiar-camara" class="btn-login" style="background: #64748b; margin-top: 0.5rem; width: 100%;">🔄 Cambiar Cámara Frontal/Trasera</button>
                        
                        <button type="button" id="btn-retomar-foto" class="btn-login" style="background: #f59e0b; margin-top: 0.8rem; width: 100%; display:none;">🔄 Volver a Tomar</button>
                        
                        <input type="hidden" id="foto_base64" name="foto_base64">
                    </div>

                    <div style="margin-top: 1.5rem;">
                        <label>Abono Inicial (Opcional):</label>
                        <div class="grupo-abono" style="margin-bottom: 0.5rem; display: flex; gap: 0.5rem;">
                            <input type="number" step="0.01" id="abono-inicial" placeholder="Monto" class="input-cliente" style="flex: 2; font-size: 16px;">
                            <select id="moneda-abono" class="input-cliente" style="flex: 1; font-size: 16px;">
                                <option value="usd">USD</option>
                                <option value="bs">Bs</option>
                            </select>
                        </div>
                        <label style="font-size: 0.85rem; color: var(--texto-secundario); font-weight: bold;">¿Cómo pagó este abono?</label>
                        <select id="metodo-abono-select" class="input-cliente" style="width: 100%; margin-bottom: 0.5rem; background-color: #f8fafc; font-size: 16px;">
                            <option value="ninguno">Sin Abono ($0.00)</option>
                            <option value="efectivo_usd">Efectivo $</option>
                            <option value="efectivo_bs">Efectivo Bs</option>
                            <option value="pago_movil">Pago Móvil</option>
                            <option value="punto_venta">Punto de Venta</option> <option value="biopago">Biopago</option>
                            <option value="otro">Otro</option>
                        </select>
                        <input type="text" id="referencia-abono" placeholder="N° de Referencia del Abono" class="input-cliente" style="display: none; margin-bottom: 1rem; font-size: 16px;">
                        <input type="text" id="observaciones-credito" placeholder="Ej: Crédito a 15 días." class="input-cliente" style="width: 100%; box-sizing: border-box; font-size: 15px;">
                        <div class="deuda-info" style="margin-top: 1rem;">
                            Resta por pagar: <strong id="monto-deuda" style="color: #ef4444;">$0.00 / 0.00 Bs</strong>
                        </div>
                    </div>
                </div>
            </div>

            <div class="totales">
                <div class="total-fila subtotal"><span>Subtotal USD:</span><span id="total-usd">$0.00</span></div>
                <div class="total-fila gran-total"><span>Total a Pagar:</span><span id="total-bs">0.00 Bs</span></div>
                <button class="btn-facturar btn-login" style="width: 100%; margin-top: 1rem; font-size: 1.2rem; padding: 1rem;" onclick="validarCheckoutAntesDeGuardar(event)">Procesar Venta</button>
            </div>
        </aside>
    </main>

    <script src="js/main.js?v=<?php echo time(); ?>"></script>
    <script>
        let streamCamara = null;
        let modoCamara = 'user'; // 'user' para cámara frontal, 'environment' para cámara trasera
        const tasaDelDia = <?php echo floatval($tasa); ?>;

        // FUNCIÓN QUE ARREGLA LA DIFERENCIA MATEMÁTICA EN LA PANTALLA
        function corregirRedondeoGlobal() {
            let elemUsd = document.getElementById('total-usd');
            let elemBs = document.getElementById('total-bs');
            if (elemUsd && elemBs) {
                let txtUsd = elemUsd.innerText.replace('$', '').trim();
                let valUsd = parseFloat(txtUsd) || 0;
                if (valUsd > 0) {
                    let exactoBs = valUsd * tasaDelDia;
                    elemBs.innerText = exactoBs.toLocaleString('es-VE', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' Bs';
                }
            }
        }

        // Observador que actualiza el Bs global cada vez que main.js toca los montos
        const observadorTotal = new MutationObserver(() => corregirRedondeoGlobal());
        document.addEventListener("DOMContentLoaded", () => {
            let elemUsd = document.getElementById('total-usd');
            if(elemUsd) observadorTotal.observe(elemUsd, { childList: true, characterData: true, subtree: true });
        });

        // FUNCIÓN MAESTRA QUE RECALCULA PRECIOS SEGÚN LA LISTA SELECCIONADA
        function recalcularPreciosPorTarifa() {
            const tarifa = document.getElementById('modalidad-tarifa').value;
            const tarjetas = document.querySelectorAll('.tarjeta-producto');
            
            tarjetas.forEach(tarjeta => {
                let id = tarjeta.getAttribute('data-id');
                let nuevoPrecioUsd = 0;
                
                if (tarifa === 'bcv_detal') nuevoPrecioUsd = parseFloat(tarjeta.getAttribute('data-precio-bcv-detal'));
                else if (tarifa === 'bcv_mayor') nuevoPrecioUsd = parseFloat(tarjeta.getAttribute('data-precio-bcv-mayor'));
                else if (tarifa === 'efectivo_detal') nuevoPrecioUsd = parseFloat(tarjeta.getAttribute('data-precio-efectivo-detal'));
                else if (tarifa === 'efectivo_mayor') nuevoPrecioUsd = parseFloat(tarjeta.getAttribute('data-precio-efectivo-mayor'));

                tarjeta.setAttribute('data-precio', nuevoPrecioUsd);
                
                let txtUsd = document.getElementById(`render-precio-usd-${id}`);
                let txtBs = document.getElementById(`render-precio-bs-${id}`);
                if(txtUsd) txtUsd.innerText = `$${nuevoPrecioUsd.toFixed(2)} / ${tarjeta.getAttribute('data-unidad') === 'kg' ? 'Kg' : (tarjeta.getAttribute('data-unidad') === 'm' ? 'm' : 'Und')}`;
                if(txtBs) txtBs.innerText = `${(nuevoPrecioUsd * tasaDelDia).toLocaleString('es-VE', {minimumFractionDigits: 2, maximumFractionDigits: 2})} Bs`;
            });

            if (typeof actualizarTotalesCarrito === 'function') actualizarTotalesCarrito();
            else if (typeof calcularTotales === 'function') calcularTotales();
            setTimeout(corregirRedondeoGlobal, 50);
        }

        document.getElementById('modalidad-tarifa').addEventListener('change', recalcularPreciosPorTarifa);

        // CHEQUEO DINÁMICO DE REFERENCIA EN PAGO MIXTO
        document.querySelectorAll('.input-mixto').forEach(input => {
            input.addEventListener('input', function() {
                const metodo = document.querySelector('input[name="metodo-pago"]:checked')?.value;
                if (metodo === 'mixto') {
                    let valPM = parseFloat(document.getElementById('mix-pm-bs').value) || 0;
                    document.getElementById('panel-referencia-principal').style.display = (valPM > 0) ? 'block' : 'none';
                }
            });
        });

        // CONTROL CENTRAL DE VISIBILIDAD DE PANELS
        function actualizarPanelesPago() {
            const val = document.querySelector('input[name="metodo-pago"]:checked')?.value;
            const panelRefPrin = document.getElementById('panel-referencia-principal');
            const panelOtro = document.getElementById('panel-otro-detalle');
            const panelCredito = document.getElementById('panel-credito');
            const panelMixto = document.getElementById('panel-pago-mixto');
            
            // Ocultar absolutamente todos los paneles secundarios por defecto
            panelRefPrin.style.display = 'none'; 
            panelOtro.style.display = 'none'; 
            panelCredito.style.display = 'none';
            if(panelMixto) panelMixto.style.display = 'none';
            
            if (val !== 'pago_movil' && val !== 'mixto') {
                document.getElementById('referencia-principal').value = '';
            }
            if (val !== 'credito') detenerCamara();

            // 🚨 REGLA ESTRICTA: SÓLO PAGO MÓVIL MUESTRA LA CAJA DE REFERENCIA
            if (val === 'pago_movil') {
                panelRefPrin.style.display = 'block';
            } else if (val === 'mixto') {
                if(panelMixto) panelMixto.style.display = 'block';
                let valPM = parseFloat(document.getElementById('mix-pm-bs').value) || 0;
                if (valPM > 0) panelRefPrin.style.display = 'block';
            } else if (val === 'otro' || (val && val.startsWith('otro_'))) {
                panelOtro.style.display = 'block';
            } else if (val === 'credito') {
                panelCredito.style.display = 'block';
                iniciarCamara();
            }
        }

        // Automatizar el cambio de tarifa visual y controlar los paneles de pago al tocar un radio
        document.querySelectorAll('input[name="metodo-pago"]').forEach(radio => {
            radio.addEventListener('change', function() {
                const selector = document.getElementById('modalidad-tarifa');
                if (this.value === 'efectivo_usd') selector.value = 'efectivo_detal';
                else if (this.value === 'punto_venta' || this.value === 'pago_movil' || this.value === 'biopago') selector.value = 'bcv_detal';
                
                recalcularPreciosPorTarifa();
                actualizarPanelesPago();
            });
        });

        // ==========================================
        // CÁMARA Y CAPTURA FOTOGRÁFICA
        // ==========================================
        function iniciarCamara() {
            const video = document.getElementById('video-camara');
            video.setAttribute('playsinline', ''); 
            
            if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
                // Usamos 'ideal' para no obligar al teléfono y evitar que colapse
                let opcionesCamara = { video: { facingMode: { ideal: modoCamara } } };
                
                navigator.mediaDevices.getUserMedia(opcionesCamara)
                .then(function(stream) { 
                    streamCamara = stream; 
                    video.srcObject = stream; 
                })
                .catch(function(err) { 
                    console.warn("Fallo modo ideal, intentando cámara básica...", err);
                    
                    // PLAN B: Si el teléfono rechaza frontal/trasera, abrimos la que sea por defecto
                    navigator.mediaDevices.getUserMedia({ video: true })
                    .then(function(streamFallback) {
                        streamCamara = streamFallback; 
                        video.srcObject = streamFallback; 
                    })
                    .catch(function(errFallback) {
                        console.error("Error definitivo:", errFallback);
                        // Alerta con el nombre exacto del error para saber qué pasa
                        alert("⚠️ Error (" + errFallback.name + "): El navegador bloqueó la cámara. Toca el icono del Candado 🔒 arriba en la barra de dirección y asegúrate de que la cámara esté en 'Permitir'.");
                    });
                });
            } else {
                alert("🚫 Tu navegador no soporta cámaras web o la conexión no es segura (HTTPS).");
            }
        }

        function detenerCamara() {
            if (streamCamara) { streamCamara.getTracks().forEach(track => track.stop()); streamCamara = null; }
        }

        // Evento para alternar entre cámara frontal y trasera
        const btnCambiarCamara = document.getElementById('btn-cambiar-camara');
        if (btnCambiarCamara) {
            btnCambiarCamara.addEventListener('click', function() {
                modoCamara = (modoCamara === 'user') ? 'environment' : 'user';
                detenerCamara();
                iniciarCamara();
            });
        }

        document.getElementById('btn-tomar-foto').addEventListener('click', function() {
            const video = document.getElementById('video-camara');
            const canvas = document.getElementById('canvas-camara');
            const preview = document.getElementById('foto-preview');
            canvas.width = video.videoWidth; canvas.height = video.videoHeight;
            canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
            const dataURL = canvas.toDataURL('image/jpeg', 0.8);
            document.getElementById('foto_base64').value = dataURL;
            preview.src = dataURL; video.style.display = 'none'; preview.style.display = 'block';
            this.style.display = 'none'; document.getElementById('btn-retomar-foto').style.display = 'block';
            if(btnCambiarCamara) btnCambiarCamara.style.display = 'none';
        });

        document.getElementById('btn-retomar-foto').addEventListener('click', function() {
            document.getElementById('video-camara').style.display = 'block';
            document.getElementById('foto-preview').style.display = 'none';
            document.getElementById('btn-tomar-foto').style.display = 'block';
            if(btnCambiarCamara) btnCambiarCamara.style.display = 'block';
            this.style.display = 'none'; document.getElementById('foto_base64').value = ''; 
        });

        document.getElementById('detalle-otro').addEventListener('input', function() {
            const radioOtro = document.querySelector('input[data-id="radio-otro"]');
            if (this.value.trim() !== '') {
                let textoLimpio = this.value.trim().replace(/[^a-zA-Z0-9 ]/g, '').replace(/\s+/g, '_').toLowerCase();
                radioOtro.value = 'otro_' + textoLimpio;
            } else { radioOtro.value = 'otro'; }
        });

        function validarCheckoutAntesDeGuardar(evento) {
            const metodoSeleccionado = document.querySelector('input[name="metodo-pago"]:checked');
            
            // 🚨 BLINDAJE FINAL: SÓLO EXIGE REFERENCIA SI ES PAGO MÓVIL DIRECTO O MIXTO CON PAGO MÓVIL
            let exigeRef = false;
            if (metodoSeleccionado && metodoSeleccionado.value === 'pago_movil') exigeRef = true;
            if (metodoSeleccionado && metodoSeleccionado.value === 'mixto') {
                let valPM = parseFloat(document.getElementById('mix-pm-bs').value) || 0;
                if (valPM > 0) exigeRef = true;
            }

            if (exigeRef && document.getElementById('referencia-principal').value.trim() === '') {
                alert("⚠️ Por favor, ingrese el número de referencia del Pago Móvil.");
                evento.stopImmediatePropagation(); return false;
            }

            if (metodoSeleccionado && metodoSeleccionado.value === 'credito') {
                if (document.getElementById('foto_base64').value === '') {
                    alert("🚫 ¡Cámara Obligatoria! Tómale una foto al cliente con la mercancía antes de fiar.");
                    evento.stopImmediatePropagation(); return false;
                }
            }
        }

        function cambiarTasaManual() {
            let tasaActual = document.getElementById('tasa-actual').innerText.replace(' Bs', '');
            let tasaEuroActual = document.getElementById('tasa-euro-actual').innerText.replace('$', '');
            let nuevaTasa = prompt("1. Ingresa la nueva tasa del DÓLAR:", tasaActual);
            if (nuevaTasa !== null && nuevaTasa.trim() !== '') {
                let nuevaTasaEuro = prompt("2. Ingresa la cotización del EURO:", tasaEuroActual);
                if (nuevaTasaEuro !== null && nuevaTasaEuro.trim() !== '') {
                    nuevaTasa = parseFloat(nuevaTasa.replace(',', '.'));
                    nuevaTasaEuro = parseFloat(nuevaTasaEuro.replace(',', '.'));
                    if (!isNaN(nuevaTasa) && nuevaTasa > 0 && !isNaN(nuevaTasaEuro) && nuevaTasaEuro > 0) {
                        let formData = new URLSearchParams();
                        formData.append('tasa', nuevaTasa); formData.append('tasa_euro', nuevaTasaEuro);
                        fetch('actualizar_tasa.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                            body: formData
                        }).then(() => window.location.reload());
                    }
                }
            }
        }
    </script> 
</body>
</html>