<?php
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = $_SERVER['REQUEST_URI'];

// Extraer la acción de la URL
preg_match('/auth\/([^?]*)/', $path, $matches);
$action = isset($matches[1]) ? $matches[1] : '';

if ($method === 'POST' && $action === 'login') {
    // Login
    $input = json_decode(file_get_contents('php://input'), true);
    $username = isset($input['username']) ? $input['username'] : '';
    $password = isset($input['password']) ? $input['password'] : '';
    
    if (empty($username) || empty($password)) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'message' => 'Usuario y contraseña son requeridos'
        ]);
        exit;
    }
    
    // Buscar usuario
    $response = supabaseRequest("whitelabel_1_users?username=eq." . urlencode($username) . "&select=*");
    
    if ($response['status'] !== 200 || empty($response['data'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false, 
            'message' => 'Credenciales inválidas'
        ]);
        exit;
    }
    
    $user = $response['data'][0];
    
    // Verificar contraseña
    if ($password !== $user['password']) {
        http_response_code(401);
        echo json_encode([
            'success' => false, 
            'message' => 'Credenciales inválidas'
        ]);
        exit;
    }
    
    // Actualizar última conexión
    $updateData = ['last_conexion' => date('c')];
    supabaseRequest(
        "whitelabel_1_users?id=eq." . $user['id'], 
        'PATCH', 
        $updateData
    );
    
    // Generar token
    $tokenPayload = [
        'id' => $user['id'],
        'username' => $user['username'],
        'key' => $user['key']
    ];
    
    $token = generateJWT($tokenPayload);
    
    // Remover contraseña antes de enviar
    unset($user['password']);
    
    echo json_encode([
        'success' => true,
        'token' => $token,
        'user' => $user
    ]);
    
} elseif ($method === 'GET' && $action === 'profile') {
    // Obtener perfil (requiere autenticación)
    $user = requireAuth();
    
    $response = supabaseRequest("whitelabel_1_users?id=eq." . $user['id'] . "&select=*");
    
    if ($response['status'] === 200 && !empty($response['data'])) {
        $profile = $response['data'][0];
        unset($profile['password']);
        
        echo json_encode([
            'success' => true,
            'user' => $profile
        ]);
    } else {
        http_response_code(404);
        echo json_encode([
            'success' => false, 
            'message' => 'Usuario no encontrado'
        ]);
    }
    
} elseif ($method === 'POST' && $action === 'logout') {
    // Logout (opcional, solo para limpiar en el servidor si necesitas)
    echo json_encode([
        'success' => true,
        'message' => 'Sesión cerrada'
    ]);
    
} else {
    http_response_code(404);
    echo json_encode([
        'success' => false, 
        'message' => 'Endpoint no encontrado'
    ]);
}
?>