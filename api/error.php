<?php
// error.php - Manejador de errores personalizado

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, PATCH, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

$errorCode = $_GET['code'] ?? 500;
$errorMessages = [
    400 => 'Solicitud incorrecta',
    401 => 'No autorizado',
    403 => 'Prohibido',
    404 => 'No encontrado',
    405 => 'Método no permitido',
    500 => 'Error interno del servidor',
    502 => 'Gateway incorrecto',
    503 => 'Servicio no disponible'
];

$message = $errorMessages[$errorCode] ?? 'Error desconocido';

http_response_code($errorCode);

// Log del error
error_log("Error $errorCode: $message - URI: " . ($_SERVER['REQUEST_URI'] ?? 'unknown'));

echo json_encode([
    'success' => false,
    'error' => [
        'code' => $errorCode,
        'message' => $message,
        'timestamp' => date('c')
    ]
], JSON_UNESCAPED_UNICODE);

exit;
?>
