<?php
// test-auth.php - Endpoint de prueba simple para auth

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Headers básicos
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Si es OPTIONS, responder inmediatamente
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $input = null;
    
    if ($method === 'POST') {
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('JSON inválido: ' . json_last_error_msg());
        }
    }
    
    $response = [
        'success' => true,
        'message' => 'Endpoint de prueba funcionando',
        'method' => $method,
        'timestamp' => date('Y-m-d H:i:s'),
        'received_data' => $input,
        'server_info' => [
            'PHP_VERSION' => PHP_VERSION,
            'REQUEST_URI' => $_SERVER['REQUEST_URI'],
            'HTTP_HOST' => $_SERVER['HTTP_HOST'] ?? 'unknown'
        ]
    ];
    
    // Si es POST con datos de login, simular respuesta
    if ($method === 'POST' && isset($input['username']) && isset($input['password'])) {
        if ($input['username'] === 'test' && $input['password'] === 'test') {
            $response['auth_test'] = true;
            $response['token'] = 'test_token_' . time();
            $response['user'] = [
                'id' => 1,
                'username' => 'test',
                'name' => 'Usuario de Prueba'
            ];
        } else {
            $response['auth_test'] = false;
            $response['message'] = 'Credenciales de prueba: test/test';
        }
    }
    
    http_response_code(200);
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT);
}

exit;
?>
