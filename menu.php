<?php
// 1. SEGURIDAD
session_start();
// Destruir la memoria caché del navegador para esta página
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

if (!isset($_SESSION['usuario_id'])) {
    header("Location: index.php");
    exit;
}

require_once 'conexion.php';

$nombreUsuario = $_SESSION['usuario_nombre'];
$rolUsuario = $_SESSION['usuario_rol'];

// 2. SISTEMA DE ALERTAS: Buscar créditos morosos
$query_morosos = "SELECT COUNT(*) as total_morosos FROM ventas WHERE deuda_usd > 0 AND fecha < DATE_SUB(NOW(), INTERVAL 1 MONTH)";
$resultado_morosos = $conexion->query($query_morosos);
$morosos = $resultado_morosos->fetch_assoc()['total_morosos'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Menú Principal - AGROSUMINISTRO LA MILAGROSA</title>
    <link rel="stylesheet" href="css/style.css?v=<?php echo time(); ?>">
    <style>
        .contenedor-menu-ancho {
            padding: 3rem;
            max-width: 1100px;
            margin: 0 auto;
        }

        .fila-menu {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 2rem;
        }

        @media (max-width: 900px) {
            .fila-menu { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 600px) {
            .fila-menu { grid-template-columns: 1fr; }
        }

        .tarjeta-menu {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 2.5rem 1.5rem;
            border-radius: 12px;
            text-decoration: none;
            color: white;
            transition: transform 0.2s, box-shadow 0.2s;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            position: relative; 
            height: 100%;
        }

        .tarjeta-menu:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 15px rgba(0,0,0,0.2);
        }

        /* Colores mantenidos, funcionan perfecto con cualquier identidad */
        .bg-pos { background: linear-gradient(135deg, #10b981, #059669); }
        .bg-clientes { background: linear-gradient(135deg, #3b82f6, #1d4ed8); }
        .bg-creditos { background: linear-gradient(135deg, #ef4444, #b91c1c); }
        .bg-inventario { background: linear-gradient(135deg, #8b5cf6, #6d28d9); }
        .bg-reportes { background: linear-gradient(135deg, #475569, #334155); }
        .bg-proveedores { background: linear-gradient(135deg, #f59e0b, #d97706); }

        .alerta-burbuja {
            position: absolute;
            top: -15px;
            right: -15px;
            background-color: #fef08a;
            color: #854d0e;
            font-weight: bold;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.2);
            animation: latido 1.5s infinite;
        }

        @keyframes latido {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
    </style>
</head>
<body style="background-color: #f8fafc;">
    <header class="top-bar" style="display: flex; justify-content: space-between; align-items: center;">
        <div style="display: flex; align-items: center; gap: 1rem;">
            <!-- Cambio de nombre a la nueva marca -->
            <h1>Agrosuministro La Milagrosa</h1>
            <span class="badge-rol" style="background-color: var(--primario); color: white;"><?php echo strtoupper($rolUsuario); ?></span>
        </div>
        
        <div style="display: flex; align-items: center; gap: 1.5rem;">
            
            <a href="respaldar.php" style="display: flex; align-items: center; gap: 6px; color: #1e3a8a; text-decoration: none; font-weight: bold; font-size: 0.95rem; transition: 0.2s;" onmouseover="this.style.color='#1d4ed8'" onmouseout="this.style.color='#1e3a8a'">
                📥 Respaldar Sistema
            </a>
            <a href="#" onclick="enviarPagoMovil(); return false;" style="display: flex; align-items: center; gap: 6px; color: #10b981; text-decoration: none; font-weight: bold; font-size: 0.95rem; transition: 0.2s;" onmouseover="this.style.color='#059669'" onmouseout="this.style.color='#10b981'">
                📱 Enviar Pago Móvil
            </a>

            <?php if ($rolUsuario == 'admin'): ?>
                <a href="usuarios.php" style="display: flex; align-items: center; gap: 6px; color: #475569; text-decoration: none; font-weight: bold; font-size: 0.95rem; transition: 0.2s;" onmouseover="this.style.color='#1e3a8a'" onmouseout="this.style.color='#475569'">
                    ⚙️ Configuración
                </a>
            <?php endif; ?>

            <span>Hola, <strong><?php echo $nombreUsuario; ?></strong></span>
            <a href="logout.php" class="btn-salir" style="background: #ef4444; color: white; padding: 0.5rem 1rem; border-radius: 5px; text-decoration: none;">Cerrar Sesión</a>
        </div>
    </header>

    <main class="contenedor-menu-ancho">
        <h2 style="margin-bottom: 2.5rem; color: var(--texto-secundario); text-align: center;">¿Qué deseas hacer hoy?</h2>
        
        <div class="fila-menu">
            
            <?php if ($rolUsuario == 'admin' || $rolUsuario == 'vendedor'): ?>
            <a href="pos.php" class="tarjeta-menu bg-pos">
                <div class="icono-menu" style="font-size: 3rem; margin-bottom: 1rem;">🛒</div>
                <h3 style="margin-bottom: 0.5rem;">Punto de Venta</h3>
                <p style="font-size: 0.9rem; opacity: 0.9;">Facturación y cobro a clientes</p>
            </a>
            <?php endif; ?>

            <?php if ($rolUsuario == 'admin' || $rolUsuario == 'vendedor'): ?>
            <a href="clientes.php" class="tarjeta-menu bg-clientes">
                <div class="icono-menu" style="font-size: 3rem; margin-bottom: 1rem;">👥</div>
                <h3 style="margin-bottom: 0.5rem;">Directorio de Clientes</h3>
                <p style="font-size: 0.9rem; opacity: 0.9;">Registrar clientes y WhatsApp</p>
            </a>
            <?php endif; ?>

            <?php if ($rolUsuario == 'admin' || $rolUsuario == 'vendedor'): ?>
            <a href="creditos.php" class="tarjeta-menu bg-creditos">
                <?php if ($morosos > 0): ?>
                    <div class="alerta-burbuja">⚠️ <?php echo $morosos; ?> Vencidos</div>
                <?php endif; ?>
                <div class="icono-menu" style="font-size: 3rem; margin-bottom: 1rem;">📒</div>
                <h3 style="margin-bottom: 0.5rem;">Créditos Pendientes</h3>
                <p style="font-size: 0.9rem; opacity: 0.9;">Cobrar deudas y ver morosos</p>
            </a>
            <?php endif; ?>

            <?php if ($rolUsuario == 'admin' || $rolUsuario == 'almacenista'): ?>
            <a href="productos.php" class="tarjeta-menu bg-inventario">
                <div class="icono-menu" style="font-size: 3rem; margin-bottom: 1rem;">📦</div>
                <h3 style="margin-bottom: 0.5rem;">Inventario</h3>
                <p style="font-size: 0.9rem; opacity: 0.9;">Agregar mercancía y precios</p>
            </a>
            <?php endif; ?>

            <?php if ($rolUsuario == 'admin'): ?>
            <a href="proveedores.php" class="tarjeta-menu bg-proveedores">
                <div class="icono-menu" style="font-size: 3rem; margin-bottom: 1rem;">🏢</div>
                <h3 style="margin-bottom: 0.5rem;">Cuentas por Pagar</h3>
                <p style="font-size: 0.9rem; opacity: 0.9;">Proveedores, facturas y abonos</p>
            </a>
            <?php endif; ?>

            <?php if ($rolUsuario == 'admin'): ?>
            <a href="reportes.php" class="tarjeta-menu bg-reportes">
                <div class="icono-menu" style="font-size: 3rem; margin-bottom: 1rem;">📊</div>
                <h3 style="margin-bottom: 0.5rem;">Reportes y Caja</h3>
                <p style="font-size: 0.9rem; opacity: 0.9;">Ventas, cuadres y estadísticas</p>
            </a>
            <?php endif; ?>

        </div>
    </main>

    <script>
        function enviarPagoMovil() {
            let telefono = prompt("📱 Ingresa el número de WhatsApp del cliente (Ej: 04141234567):");
            
            if (telefono && telefono.trim() !== "") {
                // Limpiar el número de espacios o guiones
                let numLimpio = telefono.replace(/\D/g, '');
                
                // Ajustar al código internacional de Venezuela (+58)
                if (numLimpio.startsWith('0')) {
                    numLimpio = '58' + numLimpio.substring(1);
                } else if (!numLimpio.startsWith('58')) {
                    numLimpio = '58' + numLimpio;
                }

                // NUEVO MENSAJE: Adaptado a AGROSUMINISTRO LA MILAGROSA, C.A con los datos del Provincial
                let mensaje = `¡Hola! Te saludamos de *AGROSUMINISTRO LA MILAGROSA*\n📄 Rif 197839330\n\nAquí tienes nuestros datos para realizar tu Pago Móvil:\n\n🏦 *Banco Banesco (0134)*\n📄 C.I: V-19.783.933\n📱 Cel: 0414-5747277 \n\nPor favor, envíanos el número de referencia una vez realizada la transferencia. ¡Gracias por tu compra! ✅`;

                // Codificar para URL
                let textoCodificado = encodeURIComponent(mensaje);

                // Abrir la pestaña de WhatsApp
                window.open(`https://wa.me/${numLimpio}?text=${textoCodificado}`, '_blank');
            }
        }
    </script>
    <script>
        // Si el navegador intenta cargar esta página desde el caché (botón atrás)
        window.onpageshow = function(event) {
            if (event.persisted) {
                window.location.reload(); // Obliga a recargar para que PHP bloquee el acceso
            }
        };
    </script>
</body>
</html>