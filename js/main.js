document.addEventListener('DOMContentLoaded', () => {
    let carrito = []; 
    
    const inputBuscador = document.getElementById('buscar-producto');
    const tarjetasProductos = document.querySelectorAll('.tarjeta-producto');
    const listaTicket = document.getElementById('lista-ticket');
    const spanTotalUsd = document.getElementById('total-usd');
    const spanTotalBs = document.getElementById('total-bs');
    
    const radiosPago = document.querySelectorAll('input[name="metodo-pago"]');
    const panelCredito = document.getElementById('panel-credito');
    const inputAbono = document.getElementById('abono-inicial');
    const selectMonedaAbono = document.getElementById('moneda-abono');
    const spanDeuda = document.getElementById('monto-deuda');
    const btnFacturar = document.querySelector('.btn-facturar');
    const inputCedula = document.getElementById('cliente-cedula');
    const inputNombre = document.getElementById('cliente-nombre');

    const textoTasa = document.getElementById('tasa-actual').innerText;
    const tasa = parseFloat(textoTasa) || 36.50; 

    // --- BUSCADOR PREDICTIVO DE CLIENTES ---
    const inputBuscadorCliente = document.getElementById('buscador-cliente-pos');
    const cajaSugerencias = document.getElementById('sugerencias-clientes');

    if (inputBuscadorCliente) {
        inputBuscadorCliente.addEventListener('input', async function() {
            let query = this.value.trim();
            if (query.length < 2) { cajaSugerencias.style.display = 'none'; return; }
            try {
                let resp = await fetch('buscar_cliente.php?q=' + encodeURIComponent(query));
                let clientes = await resp.json();
                if (clientes.length > 0) {
                    cajaSugerencias.innerHTML = '';
                    clientes.forEach(c => {
                        let div = document.createElement('div');
                        div.style.padding = '10px'; div.style.borderBottom = '1px solid #eee'; div.style.cursor = 'pointer';
                        div.innerHTML = `<strong>${c.cedula}</strong> - ${c.nombre}`;
                        div.addEventListener('click', () => {
                            document.getElementById('cliente-cedula').value = c.cedula;
                            document.getElementById('cliente-nombre').value = c.nombre;
                            if(document.getElementById('cliente-telefono')) document.getElementById('cliente-telefono').value = c.telefono;
                            document.getElementById('display-cliente-nombre').innerText = c.nombre;
                            document.getElementById('display-cliente-cedula').innerText = c.cedula;
                            document.getElementById('contenedor-buscador-cliente').style.display = 'none';
                            document.getElementById('tarjeta-cliente-seleccionado').style.display = 'flex';
                            inputBuscadorCliente.value = ''; cajaSugerencias.style.display = 'none';
                        });
                        div.addEventListener('mouseover', () => div.style.backgroundColor = '#f1f5f9');
                        div.addEventListener('mouseout', () => div.style.backgroundColor = 'white');
                        cajaSugerencias.appendChild(div);
                    });
                    cajaSugerencias.style.display = 'block';
                } else { cajaSugerencias.style.display = 'none'; }
            } catch (error) { console.error(error); }
        });
        document.addEventListener('click', (e) => { if (e.target !== inputBuscadorCliente && e.target !== cajaSugerencias) cajaSugerencias.style.display = 'none'; });
    }

    if (inputBuscador) {
        inputBuscador.addEventListener('input', (evento) => {
            const textoBusqueda = evento.target.value.toLowerCase().trim();
            tarjetasProductos.forEach(t => {
                if (textoBusqueda === '') { t.style.display = 'none'; return; }
                const nombre = t.getAttribute('data-nombre') || '';
                const codigo = t.getAttribute('data-codigo') || '';
                t.style.display = (nombre.includes(textoBusqueda) || codigo.includes(textoBusqueda)) ? 'block' : 'none';
            });
        });
    }

    document.body.addEventListener('click', (evento) => {
        if (evento.target.classList.contains('btn-agregar')) {
            evento.preventDefault(); evento.stopImmediatePropagation(); 
            const tarjeta = evento.target.closest('.tarjeta-producto');
            const idProducto = tarjeta.getAttribute('data-id');
            const nombre = tarjeta.getAttribute('data-nombre').toUpperCase(); 
            const tipoUnidad = tarjeta.getAttribute('data-unidad');
            const stockDisponible = parseFloat(tarjeta.getAttribute('data-stock')); 
            const selTarifa = document.getElementById('modalidad-tarifa');
            const tarifaActiva = selTarifa ? selTarifa.value : 'bcv_detal';
            let precioUsd = 0;
            if (tarifaActiva === 'bcv_detal') precioUsd = parseFloat(tarjeta.getAttribute('data-precio-bcv-detal')) || parseFloat(tarjeta.getAttribute('data-precio'));
            else if (tarifaActiva === 'bcv_mayor') precioUsd = parseFloat(tarjeta.getAttribute('data-precio-bcv-mayor'));
            else if (tarifaActiva === 'efectivo_detal') precioUsd = parseFloat(tarjeta.getAttribute('data-precio-efectivo-detal'));
            else if (tarifaActiva === 'efectivo_mayor') precioUsd = parseFloat(tarjeta.getAttribute('data-precio-efectivo-mayor'));
            if(isNaN(precioUsd) || precioUsd <= 0) precioUsd = parseFloat(tarjeta.getAttribute('data-precio')) || 0;
            
            const inputCantidad = tarjeta.querySelector('.input-cantidad');
            let valorIngresado = inputCantidad.value.trim().replace(',', '.');
            const cantidad = parseFloat(valorIngresado);
            if (isNaN(cantidad) || cantidad <= 0) { alert('⚠️ Ingresa una cantidad válida mayor a cero.'); return; }
            const indexExistente = carrito.findIndex(item => item.id == idProducto);
            const cantidadEnCarrito = (indexExistente !== -1) ? carrito[indexExistente].cantidad : 0;
            if ((cantidad + cantidadEnCarrito) > stockDisponible) { alert('⚠️ Inventario insuficiente.'); return; }

            if (indexExistente !== -1) {
                carrito[indexExistente].precioUsd = precioUsd; 
                carrito[indexExistente].typePrecio = tarifaActiva;
                carrito[indexExistente].cantidad += cantidad; 
            } else {
                carrito.push({ id: idProducto, nombre: nombre, precioUsd: precioUsd, tipoUnidad: tipoUnidad, cantidad: cantidad, stock: stockDisponible, typePrecio: tarifaActiva }); 
            }
            inputCantidad.value = (tipoUnidad === 'und') ? '1' : ''; inputBuscador.value = '';
            tarjetasProductos.forEach(t => t.style.display = 'none'); inputBuscador.focus(); actualizarTicket(); 
        }

        if (evento.target.closest('.btn-borrar')) {
            evento.preventDefault(); evento.stopImmediatePropagation();
            carrito.splice(evento.target.closest('.btn-borrar').getAttribute('data-index'), 1); actualizarTicket();
        }

        if (evento.target.closest('.btn-editar')) {
            evento.preventDefault(); evento.stopImmediatePropagation();
            const index = evento.target.closest('.btn-editar').getAttribute('data-index');
            const item = carrito[index];
            let indicativo = 'Unidades';
            if (item.tipoUnidad === 'kg') indicativo = 'Kilos (Ej: 0.500)'; if (item.tipoUnidad === 'm') indicativo = 'Metros (Ej: 3.5)';
            let nuevaCantidadStr = prompt(`✏️ Editar cantidad para ${item.nombre}\nIngrese la nueva cantidad en ${indicativo} (Max: ${item.stock}):`, item.cantidad);
            if (nuevaCantidadStr !== null) {
                let nuevaCantidad = parseFloat(nuevaCantidadStr.trim().replace(',', '.'));
                if (!isNaN(nuevaCantidad) && nuevaCantidad > 0) {
                    if (nuevaCantidad > item.stock) { alert(`⚠️ Inventario insuficiente.`); return; }
                    carrito[index].cantidad = nuevaCantidad; actualizarTicket();
                } else { alert("Cantidad no válida."); }
            }
        }
    });

    document.body.addEventListener('change', (e) => {
        if (e.target.id === 'modalidad-tarifa') {
            const tarifaActiva = e.target.value;
            tarjetasProductos.forEach(t => {
                const id = t.getAttribute('data-id'); let nPrecio = 0;
                if (tarifaActiva === 'bcv_detal') nPrecio = parseFloat(t.getAttribute('data-precio-bcv-detal'));
                else if (tarifaActiva === 'bcv_mayor') nPrecio = parseFloat(t.getAttribute('data-precio-bcv-mayor'));
                else if (tarifaActiva === 'efectivo_detal') nPrecio = parseFloat(t.getAttribute('data-precio-efectivo-detal'));
                else if (tarifaActiva === 'efectivo_mayor') nPrecio = parseFloat(t.getAttribute('data-precio-efectivo-mayor'));
                if(isNaN(nPrecio) || nPrecio <= 0) nPrecio = parseFloat(t.getAttribute('data-precio')) || 0;
                const txtUsd = document.getElementById(`render-precio-usd-${id}`), txtBs = document.getElementById(`render-precio-bs-${id}`);
                const uni = t.getAttribute('data-unidad') === 'kg' ? 'Kg' : (t.getAttribute('data-unidad') === 'm' ? 'm' : 'Und');
                if(txtUsd) txtUsd.innerText = `$${nPrecio.toFixed(2)} / ${uni}`;
                if(txtBs) txtBs.innerText = `${(nPrecio * tasa).toLocaleString('es-VE', {minimumFractionDigits: 2, maximumFractionDigits: 2})} Bs`;
            });
            carrito.forEach(item => {
                const tb = document.querySelector(`.tarjeta-producto[data-id="${item.id}"]`);
                if (tb) {
                    let nPrecioItem = 0;
                    if (tarifaActiva === 'bcv_detal') nPrecioItem = parseFloat(tb.getAttribute('data-precio-bcv-detal'));
                    else if (tarifaActiva === 'bcv_mayor') nPrecioItem = parseFloat(tb.getAttribute('data-precio-bcv-mayor'));
                    else if (tarifaActiva === 'efectivo_detal') nPrecioItem = parseFloat(tb.getAttribute('data-precio-efectivo-detal'));
                    else if (tarifaActiva === 'efectivo_mayor') nPrecioItem = parseFloat(tb.getAttribute('data-precio-efectivo-mayor'));
                    if(!isNaN(nPrecioItem) && nPrecioItem > 0) { item.precioUsd = nPrecioItem; item.typePrecio = tarifaActiva; }
                }
            });
            actualizarTicket();
        }
    });

    // MATEMÁTICA EN VIVO DEL PAGO MIXTO MULTIMONEDA
    function calcularPagoMixto() {
        const totalCompraUsd = carrito.reduce((suma, item) => suma + (item.precioUsd * item.cantidad), 0);
        const totalCompraBs = totalCompraUsd * tasa;

        let mUsd = parseFloat(document.getElementById('mix-usd').value) || 0;
        let mEfBs = parseFloat(document.getElementById('mix-efec-bs').value) || 0;
        let mPmBs = parseFloat(document.getElementById('mix-pm-bs').value) || 0;
        let mPvBs = parseFloat(document.getElementById('mix-pv-bs').value) || 0;
        let mBpBs = parseFloat(document.getElementById('mix-bp-bs').value) || 0;

        let abonadoBs = (mUsd * tasa) + mEfBs + mPmBs + mPvBs + mBpBs;
        let faltaBs = totalCompraBs - abonadoBs;

        if (document.getElementById('mix-total-pagar')) document.getElementById('mix-total-pagar').innerText = totalCompraBs.toFixed(2) + ' Bs';
        const labelFalta = document.getElementById('mix-falta');
        if (labelFalta) {
            if (faltaBs <= 0.1) { // 0.1 de tolerancia por decimales
                labelFalta.innerText = '0.00 Bs (Completo ✅)';
                labelFalta.style.color = '#10b981';
            } else {
                labelFalta.innerText = faltaBs.toFixed(2) + ' Bs';
                labelFalta.style.color = '#ef4444';
            }
        }
    }

    const inputsMixto = document.querySelectorAll('.input-mixto');
    inputsMixto.forEach(inp => inp.addEventListener('input', calcularPagoMixto));

    radiosPago.forEach(radio => {
        radio.addEventListener('change', (e) => {
            const met = e.target.value;
            const pRef = document.getElementById('panel-referencia-principal');
            const pMix = document.getElementById('panel-pago-mixto');
            const pCred = document.getElementById('panel-credito');
            if(pRef) pRef.style.display = 'none'; if(pMix) pMix.style.display = 'none'; if(pCred) pCred.style.display = 'none';
            
            if (met === 'pago_movil') { if(pRef) pRef.style.display = 'block'; } 
            else if (met === 'mixto') { 
                if(pMix) pMix.style.display = 'block'; 
                if(pRef) pRef.style.display = 'block'; // Pedir referencia por si hubo transferencias
                inputsMixto.forEach(inp => inp.value = ''); 
                calcularPagoMixto();
            } 
            else if (met === 'credito') { 
                if(pCred) pCred.style.display = 'block'; 
                if(typeof iniciarCamara === 'function') iniciarCamara(); 
            }
            if(met !== 'credito' && typeof detenerCamara === 'function') detenerCamara();
            calcularDeuda();
        });
    });

    if (document.getElementById('metodo-abono-select')) {
        document.getElementById('metodo-abono-select').addEventListener('change', function() {
            let refAbono = document.getElementById('referencia-abono');
            if(refAbono) { refAbono.style.display = (this.value === 'pago_movil' || this.value === 'biopago') ? 'block' : 'none'; refAbono.value = ''; }
        });
    }

    if(inputAbono) inputAbono.addEventListener('input', calcularDeuda);
    if(selectMonedaAbono) selectMonedaAbono.addEventListener('change', calcularDeuda);

    function actualizarTicket() {
        listaTicket.innerHTML = ''; let totalUsd = 0;
        if (carrito.length === 0) listaTicket.innerHTML = '<p style="color: #64748b; text-align: center; margin-top: 2rem;">Ningún producto seleccionado</p>';
        carrito.forEach((item, index) => {
            const subtotalItemUsd = item.precioUsd * item.cantidad; const subtotalItemBs = subtotalItemUsd * tasa; totalUsd += subtotalItemUsd;
            const indicativoUnidad = (item.tipoUnidad === 'kg') ? 'Kg' : (item.tipoUnidad === 'm' ? 'm' : 'Und');
            const html = `<div style="padding-bottom: 0.8rem; margin-bottom: 0.8rem; border-bottom: 1px dashed var(--borde);"><div style="display: flex; justify-content: space-between; align-items: flex-start;"><div><span style="font-weight: bold; color: var(--texto-principal);">${item.nombre}</span><div style="font-size: 0.85rem; color: #64748b;">${item.cantidad} ${indicativoUnidad} x $${item.precioUsd.toFixed(2)}</div></div><div style="text-align: right;"><span style="font-weight: bold;">$${subtotalItemUsd.toFixed(2)}</span><br><span style="color: #10b981; font-weight: bold; font-size: 0.9rem;">${subtotalItemBs.toFixed(2)} Bs</span></div></div><div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 0.5rem;"><button class="btn-editar" data-index="${index}" style="background:none; border:none; cursor:pointer;" title="Editar">✏️</button><button class="btn-borrar" data-index="${index}" style="background:none; border:none; cursor:pointer;" title="Eliminar">❌</button></div></div>`;
            listaTicket.insertAdjacentHTML('beforeend', html);
        });
        const totalBs = totalUsd * tasa;
        spanTotalUsd.innerText = `$${totalUsd.toFixed(2)}`; spanTotalBs.innerText = `${totalBs.toFixed(2)} Bs`;
        
        if (document.getElementById('panel-pago-mixto') && document.getElementById('panel-pago-mixto').style.display === 'block') {
            calcularPagoMixto();
        }
        calcularDeuda(); 
    }

    function calcularDeuda() {
        const metodoRadio = document.querySelector('input[name="metodo-pago"]:checked');
        if (!metodoRadio || metodoRadio.value !== 'credito') return;
        const totalCompraUsd = carrito.reduce((suma, item) => suma + (item.precioUsd * item.cantidad), 0);
        let valorAbonoStr = inputAbono.value.trim().replace(',', '.'); const montoAbono = parseFloat(valorAbonoStr) || 0;
        let abUsd = (selectMonedaAbono.value === 'usd') ? montoAbono : (montoAbono / tasa);
        let deUsd = totalCompraUsd - abUsd; if (deUsd < 0) deUsd = 0; 
        spanDeuda.innerHTML = `$${deUsd.toFixed(2)} / ${(deUsd * tasa).toFixed(2)} Bs`;
    }

    if (btnFacturar) {
        btnFacturar.addEventListener('click', async () => {
            if (carrito.length === 0) { alert('El ticket está vacío. Agrega productos primero.'); return; }
            const radMetodo = document.querySelector('input[name="metodo-pago"]:checked');
            if(!radMetodo) { alert('Selecciona un método de pago.'); return; }
            
            const metodoOriginal = radMetodo.value;
            let metodoSeleccionado = metodoOriginal;
            const totalCompraUsd = carrito.reduce((s, i) => s + (i.precioUsd * i.cantidad), 0);
            const totalCompraBs = totalCompraUsd * tasa;

            let objMixto = { usd: 0, efecBs: 0, pmBs: 0, pvBs: 0, bpBs: 0 };

            if(metodoOriginal === 'mixto') {
                objMixto.usd = parseFloat(document.getElementById('mix-usd').value) || 0;
                objMixto.efecBs = parseFloat(document.getElementById('mix-efec-bs').value) || 0;
                objMixto.pmBs = parseFloat(document.getElementById('mix-pm-bs').value) || 0;
                objMixto.pvBs = parseFloat(document.getElementById('mix-pv-bs').value) || 0;
                objMixto.bpBs = parseFloat(document.getElementById('mix-bp-bs').value) || 0;
                
                let abonadoTotalBs = (objMixto.usd * tasa) + objMixto.efecBs + objMixto.pmBs + objMixto.pvBs + objMixto.bpBs;
                if(abonadoTotalBs < (totalCompraBs - 0.5)) {
                    alert('⚠️ El pago mixto está incompleto. Faltan bolívares por cubrir el total de la factura.');
                    return;
                }
            }

            if (metodoOriginal === 'credito') {
                if (document.getElementById('foto_base64') && document.getElementById('foto_base64').value === '') {
                    alert("🚫 ¡Cámara Obligatoria! Tómale una foto al cliente."); return; 
                }
            }

            let abonoUsd = 0; let deudaUsd = 0; let referenciaFinal = '';
            const cedulaCliente = inputCedula ? inputCedula.value.trim() : '';
            const nombreCliente = inputNombre ? inputNombre.value.trim() : '';

            if (metodoOriginal === 'credito') {
                if (cedulaCliente === '' || nombreCliente === '') { alert('⚠️ Para ventas A CRÉDITO es obligatorio registrar Cliente.'); return; }
                const montoAbono = parseFloat(inputAbono.value.trim().replace(',', '.')) || 0;
                const metodoAbono = document.getElementById('metodo-abono-select').value;
                const refAbonoElement = document.getElementById('referencia-abono');
                if (montoAbono > 0 && metodoAbono === 'ninguno') { alert('⚠️ Falta método del abono.'); return; }
                if (montoAbono > 0 && (metodoAbono === 'pago_movil' || metodoAbono === 'biopago') && (!refAbonoElement || refAbonoElement.value.trim() === '')) {
                    alert('⚠️ Falta Referencia del abono.'); return;
                }
                abonoUsd = (selectMonedaAbono.value === 'usd') ? montoAbono : (montoAbono / tasa);
                deudaUsd = totalCompraUsd - abonoUsd; if (deudaUsd < 0) deudaUsd = 0;
                if (metodoAbono !== 'ninguno') metodoSeleccionado = 'credito_' + metodoAbono;
                referenciaFinal = refAbonoElement ? refAbonoElement.value.trim() : '';
            } else {
                abonoUsd = totalCompraUsd; 
                if (metodoOriginal === 'pago_movil') {
                    const refPrinElement = document.getElementById('referencia-principal');
                    if (refPrinElement && refPrinElement.value.trim() === '') {
                        alert('⚠️ El número de referencia bancaria es obligatorio.'); refPrinElement.focus(); return;
                    }
                    referenciaFinal = refPrinElement ? refPrinElement.value.trim() : '';
                }
            }

            btnFacturar.disabled = true; btnFacturar.innerText = 'Procesando...';
            const obsElement = document.getElementById('observaciones-credito');

            const paqueteDatos = {
                clienteCedula: cedulaCliente, clienteNombre: nombreCliente, tasa: tasa, totalUsd: totalCompraUsd, totalBs: totalCompraBs,
                metodoPago: metodoSeleccionado, abonoUsd: abonoUsd, deudaUsd: deudaUsd, referencia: referenciaFinal, carrito: carrito,
                foto_base64: document.getElementById('foto_base64') ? document.getElementById('foto_base64').value : '',
                observaciones: obsElement ? obsElement.value.trim() : '',
                mixtoUsd: objMixto.usd, mixtoEfecBs: objMixto.efecBs, mixtoPmBs: objMixto.pmBs, mixtoPvBs: objMixto.pvBs, mixtoBpBs: objMixto.bpBs
            };

            try {
                const respuesta = await fetch('guardar_venta.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(paqueteDatos) });
                const resultado = await respuesta.json();
                if (resultado.exito) { window.open('imprimir_ticket.php?id=' + resultado.id_venta, '_blank'); window.location.reload(); } 
                else { alert('❌ Error: ' + resultado.mensaje); }
            } catch (error) { alert('❌ Error de comunicación con el servidor.'); } 
            finally { btnFacturar.disabled = false; btnFacturar.innerText = 'Procesar Venta'; }
        });
    }
});

window.quitarCliente = function() {
    document.getElementById('cliente-cedula').value = ''; document.getElementById('cliente-nombre').value = '';
    if(document.getElementById('cliente-telefono')) document.getElementById('cliente-telefono').value = '';
    document.getElementById('tarjeta-cliente-seleccionado').style.display = 'none';
    document.getElementById('contenedor-buscador-cliente').style.display = 'block';
};