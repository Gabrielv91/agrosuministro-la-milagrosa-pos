<?php
session_start();

// Si el usuario ya tiene una sesión abierta, lo mandamos directo al menú principal
if (isset($_SESSION['usuario_id'])) {
    header("Location: menu.php");
    exit;
}

require_once 'conexion.php';
$error = '';

// Lógica de autenticación intacta y segura
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $usuario = trim($_POST['usuario']);
    $clave = trim($_POST['clave']);

    if (!empty($usuario) && !empty($clave)) {
        $usuario_esc = $conexion->real_escape_string($usuario);
        $query = "SELECT * FROM usuarios WHERE usuario = '$usuario_esc' LIMIT 1";
        $resultado = $conexion->query($query);

        if ($resultado && $resultado->num_rows > 0) {
            $user = $resultado->fetch_assoc();
            
            // Verificación de credenciales
            if ($clave === $user['clave']) {
                $_SESSION['usuario_id'] = $user['id'];
                $_SESSION['usuario_nombre'] = $user['nombre'];
                $_SESSION['usuario_rol'] = $user['rol'];
                
                header("Location: menu.php");
                exit;
            } else {
                $error = '❌ Contraseña incorrecta. Inténtalo de nuevo.';
            }
        } else {
            $error = '❌ El usuario ingresado no existe.';
        }
    } else {
        $error = '⚠️ Por favor, complete ambos campos.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <link rel="manifest" href="manifest.json">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agrosuministro La Milagrosa - Acceso al Sistema</title>
    <link rel="stylesheet" href="css/style.css?v=<?php echo time(); ?>">
    <style>
        .cuerpo-login {
            background: linear-gradient(135deg, #f0fdf4, #dcfce7, #cbd5e1);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            padding: 1rem;
            box-sizing: border-box;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        .tarjeta-autenticacion {
            background: white;
            padding: 2.5rem 2rem;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(20, 83, 45, 0.15);
            border-top: 6px solid #15803d; /* Verde agrícola intenso del escudo */
            width: 100%;
            max-width: 420px; 
            box-sizing: border-box;
            text-align: center;
        }
        .contenedor-logo {
            margin-bottom: 1.2rem;
        }
        /* Estilo para que el escudo de La Milagrosa resalte impecable */
        .logo-empresa {
            width: 100%;
            max-width: 210px; 
            height: auto;
            object-fit: contain;
            margin: 0 auto;
            display: block;
            filter: drop-shadow(0 6px 8px rgba(0,0,0,0.18));
        }
        .encabezado-bienvenida {
            margin-bottom: 1.8rem;
        }
        .encabezado-bienvenida h3 {
            margin: 0.5rem 0 0.2rem 0;
            color: #14532d; /* Verde bosque oscuro y formal */
            font-size: 1.3rem;
            font-weight: 800;
        }
        .encabezado-bienvenida p {
            margin: 0;
            color: #64748b;
            font-size: 0.9rem;
            line-height: 1.4;
        }
        .subtitulo-agro {
            font-size: 0.72rem;
            font-weight: 800;
            color: #ca8a04; /* Amarillo dorado estilo sol de La Milagrosa */
            letter-spacing: 1px;
            margin-top: 0.6rem;
        }
        .alerta-error {
            background: #fee2e2;
            color: #ef4444;
            padding: 0.8rem;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: bold;
            margin-bottom: 1.5rem;
            border: 1px solid #fca5a5;
            text-align: left;
        }
        .bloque-formulario {
            text-align: left;
            margin-bottom: 1.2rem;
        }
        .bloque-formulario label {
            display: block;
            font-size: 0.85rem;
            font-weight: 700;
            color: #334155;
            margin-bottom: 0.5rem;
        }
        .campo-entrada {
            width: 100%;
            box-sizing: border-box;
            padding: 0.8rem 1rem;
            border: 1px solid #cbd5e1;
            background-color: #f8fafc;
            border-radius: 8px;
            font-size: 16px; 
            transition: border-color 0.2s, box-shadow 0.2s, background-color 0.2s;
        }
        .campo-entrada:focus {
            outline: none;
            background-color: white;
            border-color: #16a34a; /* Verde brillante al hacer foco */
            box-shadow: 0 0 0 3px rgba(22, 163, 74, 0.18);
        }
        .btn-ingresar {
            width: 100%;
            background: #16a34a; /* Verde agrícola vibrante */
            color: white;
            border: none;
            padding: 1rem;
            font-size: 1.05rem;
            font-weight: bold;
            border-radius: 8px;
            cursor: pointer;
            transition: background 0.2s, transform 0.1s;
            margin-top: 0.5rem;
            box-shadow: 0 4px 6px rgba(22, 163, 74, 0.2);
        }
        .btn-ingresar:hover {
            background: #15803d;
        }
        .btn-ingresar:active {
            transform: scale(0.98);
        }
        
        /* =========================================================
           NUEVO: ESTILOS PARA EL BOTÓN DE INSTALACIÓN PWA
           ========================================================= */
        .btn-instalar-pwa {
            width: 100%;
            background: #f0fdf4;
            color: #15803d;
            border: 2px dashed #16a34a;
            padding: 0.8rem;
            font-size: 0.95rem;
            font-weight: 700;
            border-radius: 8px;
            cursor: pointer;
            margin-top: 1.2rem;
            transition: all 0.2s;
        }
        .btn-instalar-pwa:hover {
            background: #dcfce7;
            transform: translateY(-1px);
        }
        
        .pie-login {
            margin-top: 2rem;
            font-size: 0.8rem;
            color: #94a3b8;
        }
    </style>
</head>
<body class="cuerpo-login">

    <div class="tarjeta-autenticacion">
        
        <div class="contenedor-logo">
            <img src="img/logo.png" onerror="this.src='img/logo.jpg'" alt="Agrosuministro La Milagrosa" class="logo-empresa">
            <div class="subtitulo-agro">MAQUINARIA • INSUMOS • ALIMENTOS • VETERINARIA</div>
        </div>

        <div class="encabezado-bienvenida">
            <h3>¡Bienvenido a La Milagrosa!</h3>
            <p>Ingresa tus credenciales para iniciar el turno de trabajo.</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alerta-error">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="index.php" autocomplete="off">
            <div class="bloque-formulario">
                <label for="usuario">Nombre de Usuario</label>
                <input type="text" name="usuario" id="usuario" class="campo-entrada" required placeholder="Ej: admin" autofocus>
            </div>

            <div class="bloque-formulario">
                <label for="clave">Contraseña de Seguridad</label>
                <input type="password" name="clave" id="clave" class="campo-entrada" required placeholder="••••••••">
            </div>

            <button type="submit" class="btn-ingresar">Iniciar Sesión</button>
        </form>

        <button type="button" id="btnInstalarApp" class="btn-instalar-pwa" style="display: none;">
            📲 Instalar App en este Dispositivo
        </button>

        <div class="pie-login">
            LiaPOS &copy; <?php echo date('Y'); ?> - Sistema Inteligente de Ventas
        </div>
    </div>

    <script>
        let eventoInstalacion = null;
        const botonInstalar = document.getElementById('btnInstalarApp');

        // Escuchamos cuando el navegador en el VPS con HTTPS esté listo para instalar
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            eventoInstalacion = e;
            // Hacemos visible el botón con un diseño que combina perfecto
            botonInstalar.style.display = 'block';
            console.log("🟢 Listo para instalar LiaPOS nativamente.");
        });

        // Al hacer clic en el botón de instalar
        botonInstalar.addEventListener('click', async () => {
            if (!eventoInstalacion) return;
            
            eventoInstalacion.prompt();
            const { outcome } = await eventoInstalacion.userChoice;
            
            if (outcome === 'accepted') {
                console.log('✅ App instalada exitosamente.');
                botonInstalar.style.display = 'none';
            }
            eventoInstalacion = null;
        });

        // Si ya lo instalaron o lo abren desde el ícono nativo, ocultar botón
        window.addEventListener('appinstalled', () => {
            botonInstalar.style.display = 'none';
        });
    </script>
</body>
</html>