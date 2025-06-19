<?php
// Permitir CORS
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS, PATCH, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

// Si es una petición OPTIONS, terminar aquí
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

// Configuración - IMPORTANTE: Cambia estos valores
define('JWT_SECRET', 'cambia_esto_por_una_clave_secreta_muy_larga_' . bin2hex(random_bytes(32)));
define('SUPABASE_URL', 'https://57supabase57.dragonfly.com.mx');
define('SUPABASE_ANON_KEY', 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJyb2xlIjoiYW5vbiIsImlzcyI6InN1cGFiYXNlIiwiaWF0IjoxNzQ4NDk4NDAwLCJleHAiOjE5MDYyNjQ4MDB9.Dt37HC6_jt-jPEtlQxbpslByEJADktuNyQWj4YdTg7M');
define('EVOLUTION_API_URL', 'https://57evolution57.dragonfly.com.mx');
define('EVOLUTION_API_KEY', '429683C4C977415CAAFCCE10F7D57E11');
define('N8N_WEBHOOK_URL', 'https://57n8n57.dragonfly.com.mx/webhook/recibeconexion');

// Función para hacer peticiones a Supabase
function supabaseRequest($endpoint, $method = 'GET', $data = null, $headers = []) {
    $url = SUPABASE_URL . '/rest/v1/' . $endpoint;
    
    $defaultHeaders = [
        'apikey: ' . SUPABASE_ANON_KEY,
        'Authorization: Bearer ' . SUPABASE_ANON_KEY,
        'Content-Type: application/json'
    ];
    
    if ($method === 'PATCH') {
        $defaultHeaders[] = 'Prefer: return=minimal';
    }
    
    $headers = array_merge($defaultHeaders, $headers);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
    } elseif ($method === 'PATCH') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        error_log('CURL Error: ' . $error);
        return [
            'data' => null,
            'error' => $error,
            'status' => 0
        ];
    }
    
    return [
        'data' => json_decode($response, true),
        'status' => $httpCode
    ];
}

// Función simple para generar JWT
function generateJWT($payload) {
    $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
    $header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
    
    $payload['iat'] = time();
    $payload['exp'] = time() + (7 * 24 * 60 * 60); // 7 días
    $payload = json_encode($payload);
    $payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
    
    $signature = hash_hmac('sha256', "$header.$payload", JWT_SECRET, true);
    $signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
    
    return "$header.$payload.$signature";
}

// Función para verificar JWT
function verifyJWT($token) {
    try {
        error_log('Verificando JWT: ' . substr($token, 0, 20) . '...');
        
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            error_log('JWT Error: Token no tiene 3 partes');
            return false;
        }
        
        list($header, $payload, $signature) = $parts;
        
        // Verificar firma
        $validSignature = hash_hmac('sha256', "$header.$payload", JWT_SECRET, true);
        $validSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($validSignature));
        
        if ($signature !== $validSignature) {
            error_log('JWT Error: Firma inválida');
            error_log('Firma esperada: ' . $validSignature);
            error_log('Firma recibida: ' . $signature);
            return false;
        }
        
        // Decodificar payload
        $payload = base64_decode(str_replace(['-', '_'], ['+', '/'], $payload));
        $payload = json_decode($payload, true);
        
        if (!$payload) {
            error_log('JWT Error: No se pudo decodificar el payload');
            return false;
        }
        
        // Verificar expiración
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            error_log('JWT Error: Token expirado. Exp: ' . $payload['exp'] . ', Now: ' . time());
            return false;
        }
        
        error_log('JWT válido para usuario: ' . ($payload['username'] ?? 'unknown'));
        return $payload;
    } catch (Exception $e) {
        error_log('JWT Exception: ' . $e->getMessage());
        return false;
    }
}

// Función para obtener headers (compatible con diferentes servidores)
if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }
}

// Middleware de autenticación mejorado
function requireAuth() {
    $authHeader = '';
    
    // Método 1: getallheaders()
    $headers = getallheaders();
    if (isset($headers['Authorization'])) {
        $authHeader = $headers['Authorization'];
    } 
    // Método 2: $_SERVER
    elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    }
    // Método 3: Apache workaround
    elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    // Método 4: Para algunos servidores
    elseif (function_exists('apache_request_headers')) {
        $requestHeaders = apache_request_headers();
        if (isset($requestHeaders['Authorization'])) {
            $authHeader = $requestHeaders['Authorization'];
        }
    }
    
    error_log('Auth header encontrado: ' . $authHeader);
    error_log('Todos los headers: ' . json_encode($headers));
    
    if (empty($authHeader)) {
        error_log('JWT Error: No se encontró header Authorization');
        http_response_code(401);
        echo json_encode([
            'success' => false, 
            'message' => 'Token de autorización no proporcionado',
            'debug' => [
                'headers_found' => array_keys($headers),
                'server_vars' => array_keys(array_filter($_SERVER, function($key) {
                    return strpos($key, 'HTTP_') === 0 || strpos($key, 'AUTH') !== false;
                }, ARRAY_FILTER_USE_KEY))
            ]
        ]);
        exit;
    }
    
    // Extraer token del header
    if (strpos($authHeader, 'Bearer ') === 0) {
        $token = substr($authHeader, 7);
    } else {
        $token = $authHeader;
    }
    
    if (empty($token)) {
        error_log('JWT Error: Token vacío después de extraer');
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Token vacío']);
        exit;
    }
    
    $user = verifyJWT($token);
    
    if (!$user) {
        error_log('JWT Error: Token inválido o expirado');
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Token inválido o expirado']);
        exit;
    }
    
    error_log('Usuario autenticado exitosamente: ' . $user['username']);
    return $user;
}

// Función de logging para debug
function logDebug($message, $data = null) {
    $logMessage = '[DEBUG] ' . $message;
    if ($data !== null) {
        $logMessage .= ' - Data: ' . json_encode($data);
    }
    error_log($logMessage);
}

?>