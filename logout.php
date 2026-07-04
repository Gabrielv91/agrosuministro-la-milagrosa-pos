<?php
session_start();
session_unset(); // Borra las variables
session_destroy(); // Destruye la sesión
header("Location: index.php"); // Lo devuelve al login
exit;
?>