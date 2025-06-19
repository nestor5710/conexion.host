<?php
// test.php - Prueba súper simple que solo devuelve JSON

// Sin includes, sin dependencias, solo JSON básico
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

echo '{"status":"ok","message":"Servidor funcionando","timestamp":"' . date('Y-m-d H:i:s') . '","php_version":"' . PHP_VERSION . '"}';
?>
