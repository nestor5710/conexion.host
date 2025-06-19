<?php
require_once 'config.php';

// Log de inicio del script
logDebug('=== AUTH.PHP INICIADO ===');
logDebug('Method: ' . $_SERVER['REQUEST_METHOD']);
logDebug('URI: ' . $_SERVER['REQUEST_URI']);
logDebug('Headers: ' . json_encode(getAllHeaders()));

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $path = $_SERVER['REQUEST_URI'];

    // Extraer la acción de la URL de forma más robusta
    $pathParts = explode('/', trim(parse_url($path, PHP_URL_PATH), '/'));
    $action = '';
    
    // Buscar 'auth' en el path y tomar la siguiente parte
    $authIndex = array_search('auth', $pathParts);
    if ($authIndex !== false && isset($pathParts[$authIndex + 1])) {
        $action = $pathParts[$authIndex + 1];
    }
    
    logDebug('Acción extraída: ' . $action);

    if ($method === 'POST' && $action === 'login') {
        handleLogin();
    } elseif ($method === 'GET' && $action === 'profile') {
        handleProfile();
    } elseif ($method === 'POST' && $action === 'logout') {
        handleLogout();
    } else {
        handleError('Endpoint no encontrado', 404, [
            'method' => $method,
            'action' => $action,
            'available_endpoints' => [
                'POST /api/auth/login',
                'GET /api/auth/profile',
                'POST /api/auth/logout'
            ]
        ]);
    }

} catch (Exception $e) {
    logError('Error general en auth.php: ' . $e->getMessage());
    handleError('Error interno del servidor', 500);
}

function handleLogin() {
    try {
        logDebug('=== PROCESANDO LOGIN ===');
        
        $rawInput = file_get_contents('php://input');
        logDebug('Raw input: ' . $rawInput);
        
        $input = json_decode($rawInput, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            logError('JSON decode error: ' . json_last_error_msg());
            handleError('Datos JSON inválidos', 400);
        }
        
        $username = isset($input['username']) ? trim($input['username']) : '';
        $password = isset($input['password']) ? trim($input['password']) : '';
        
        logDebug('Datos de login: username=' . $username . ', password_length=' . strlen($password));
        
        if (empty($username) || empty($password)) {
            handleError('Usuario y contraseña son requeridos', 400);
        }
        
        // Buscar usuario en Supabase
        $response = supabaseRequest("whitelabel_1_users?username=eq." . urlencode($username) . "&select=*");
        
        logDebug('Supabase response for user search: ', [
            'status' => $response['status'],
            'data_count' => is_array($response['data']) ? count($response['data']) : 0,
            'error' => $response['error'] ?? null
        ]);
        
        if ($response['status'] !== 200) {
            logError('Error en Supabase: HTTP ' . $response['status']);
            handleError('Error de base de datos', 500, $response['error']);
        }
        
        if (empty($response['data'])) {
            logDebug('Usuario no encontrado: ' . $username);
            handleError('Credenciales inválidas', 401);
        }
        
        $user = $response['data'][0];
        logDebug('Usuario encontrado: ' . $user['username']);
        
        // Verificar contraseña (en producción usar password_verify)
        if ($password !== $user['password']) {
            logDebug('Contraseña incorrecta para usuario: ' . $username);
            handleError('Credenciales inválidas', 401);
        }
        
        // Actualizar última conexión
        $updateData = ['last_conexion' => date('c')];
        $updateResponse = supabaseRequest(
            "whitelabel_1_users?id=eq." . $user['id'],
            'PATCH',
            $updateData
        );
        
        logDebug('Update last_conexion response: ', $updateResponse);
        
        // Generar token JWT
        $tokenPayload = [
            'id' => $user['id'],
            'username' => $user['username'],
            'key' => $user['key']
        ];
        
        $token = generateJWT($tokenPayload);
        
        if (!$token) {
            logError('Error generando JWT token');
            handleError('Error generando token de autenticación', 500);
        }
        
        // Remover contraseña antes de enviar
        unset($user['password']);
        
        logDebug('Login exitoso para usuario: ' . $user['username']);
        
        sendJsonResponse([
            'success' => true,
            'token' => $token,
            'user' => $user
        ]);
        
    } catch (Exception $e) {
        logError('Error en handleLogin: ' . $e->getMessage());
        handleError('Error interno durante el login', 500);
    }
}

function handleProfile() {
    try {
        logDebug('=== PROCESANDO PROFILE ===');
        
        // Requiere autenticación
        $user = requireAuth();
        
        logDebug('Obteniendo perfil para usuario ID: ' . $user['id']);
        
        $response = supabaseRequest("whitelabel_1_users?id=eq." . $user['id'] . "&select=*");
        
        if ($response['status'] === 200 && !empty($response['data'])) {
            $profile = $response['data'][0];
            unset($profile['password']); // Nunca enviar la contraseña
            
            logDebug('Perfil obtenido exitosamente');
            
            sendJsonResponse([
                'success' => true,
                'user' => $profile
            ]);
        } else {
            logError('Usuario no encontrado en base de datos', ['user_id' => $user['id']]);
            handleError('Usuario no encontrado', 404);
        }
        
    } catch (Exception $e) {
        logError('Error en handleProfile: ' . $e->getMessage());
        handleError('Error obteniendo perfil', 500);
    }
}

function handleLogout() {
    try {
        logDebug('=== PROCESANDO LOGOUT ===');
        
        // El logout es principalmente del lado del cliente
        // Aquí podrías invalidar el token si mantienes una lista de tokens válidos
        
        sendJsonResponse([
            'success' => true,
            'message' => 'Sesión cerrada exitosamente'
        ]);
        
    } catch (Exception $e) {
        logError('Error en handleLogout: ' . $e->getMessage());
        handleError('Error durante logout', 500);
    }
}

?>
