<?php
// Configuración mejorada con mejor manejo de errores y CORS

// Función para manejar CORS de forma más robusta
function handleCORS() {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
    
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, PATCH, DELETE");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin");
    header("Content-Type: application/json; charset=UTF-8");
    
    // Manejar preflight OPTIONS
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit(0);
    }
}

// Llamar CORS al inicio
handleCORS();

// Configuración - IMPORTANTE: Cambia estos valores
define('JWT_SECRET', 'tu_clave_secreta_muy_larga_y_segura_' . bin2hex(random_bytes(16)));
define('SUPABASE_URL', 'https://57supabase57.dragonfly.com.mx');
define('SUPABASE_ANON_KEY', 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJyb2xlIjoiYW5vbiIsImlzcyI6InN1cGFiYXNlIiwiaWF0IjoxNzQ4NDk4NDAwLCJleHAiOjE5MDYyNjQ4MDB9.Dt37HC6_jt-jPEtlQxbpslByEJADktuNyQWj4YdTg7M');
define('EVOLUTION_API_URL', 'https://57evolution57.dragonfly.com.mx');
define('EVOLUTION_API_KEY', '429683C4C977415CAAFCCE10F7D57E11');
define('N8N_WEBHOOK_URL', 'https://57n8n57.dragonfly.com.mx/webhook/recibeconexion');

// Función para logging de errores
function logError($message, $context = []) {
    $timestamp = date('Y-m-d H:i:s');
    $contextStr = !empty($context) ? json_encode($context) : '';
    error_log("[$timestamp] ERROR: $message $contextStr");
}

// Función para logging de debug
function logDebug($message, $data = null) {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] DEBUG: $message";
    if ($data !== null) {
        $logMessage .= ' - Data: ' . json_encode($data);
    }
    error_log($logMessage);
}

// Función mejorada para hacer peticiones a Supabase
function supabaseRequest($endpoint, $method = 'GET', $data = null, $headers = []) {
    $url = SUPABASE_URL . '/rest/v1/' . $endpoint;
    
    logDebug("Supabase Request: $method $url", $data);
    
    $defaultHeaders = [
        'apikey: ' . SUPABASE_ANON_KEY,
        'Authorization: Bearer ' . SUPABASE_ANON_KEY,
        'Content-Type: application/json',
        'Accept: application/json'
    ];
    
    if ($method === 'PATCH') {
        $defaultHeaders[] = 'Prefer: return=minimal';
    }
    
    $headers = array_merge($defaultHeaders, $headers);
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3
    ]);
    
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
    
    logDebug("Supabase Response: HTTP $httpCode", ['error' => $error, 'response_length' => strlen($response)]);
    
    if ($error) {
        logError("CURL Error in Supabase request: $error");
        return [
            'data' => null,
            'error' => $error,
            'status' => 0
        ];
    }
    
    $decodedResponse = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        logError("JSON decode error: " . json_last_error_msg(), ['response' => substr($response, 0, 500)]);
        return [
            'data' => null,
            'error' => 'Invalid JSON response from Supabase',
            'status' => $httpCode
        ];
    }
    
    return [
        'data' => $decodedResponse,
        'status' => $httpCode
    ];
}

// Función mejorada para generar JWT
function generateJWT($payload) {
    try {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        
        $payload['iat'] = time();
        $payload['exp'] = time() + (7 * 24 * 60 * 60); // 7 días
        $payload = json_encode($payload);
        $payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
        
        $signature = hash_hmac('sha256', "$header.$payload", JWT_SECRET, true);
        $signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        
        return "$header.$payload.$signature";
    } catch (Exception $e) {
        logError("Error generating JWT: " . $e->getMessage());
        return false;
    }
}

// Función mejorada para verificar JWT
function verifyJWT($token) {
    try {
        logDebug('Verificando JWT: ' . substr($token, 0, 20) . '...');
        
        if (empty($token)) {
            logError('JWT Error: Token vacío');
            return false;
        }
        
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            logError('JWT Error: Token no tiene 3 partes');
            return false;
        }
        
        list($header, $payload, $signature) = $parts;
        
        // Verificar firma
        $validSignature = hash_hmac('sha256', "$header.$payload", JWT_SECRET, true);
        $validSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($validSignature));
        
        if (!hash_equals($signature, $validSignature)) {
            logError('JWT Error: Firma inválida');
            return false;
        }
        
        // Decodificar payload
        $payloadData = base64_decode(str_replace(['-', '_'], ['+', '/'], $payload));
        $payload = json_decode($payloadData, true);
        
        if (!$payload) {
            logError('JWT Error: No se pudo decodificar el payload');
            return false;
        }
        
        // Verificar expiración
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            logError('JWT Error: Token expirado. Exp: ' . $payload['exp'] . ', Now: ' . time());
            return false;
        }
        
        logDebug('JWT válido para usuario: ' . ($payload['username'] ?? 'unknown'));
        return $payload;
    } catch (Exception $e) {
        logError('JWT Exception: ' . $e->getMessage());
        return false;
    }
}

// Función mejorada para obtener headers
function getAllHeaders() {
    $headers = [];
    
    if (function_exists('getallheaders')) {
        return getallheaders();
    }
    
    foreach ($_SERVER as $name => $value) {
        if (substr($name, 0, 5) == 'HTTP_') {
            $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
        }
    }
    
    return $headers;
}

// Middleware de autenticación mejorado
function requireAuth() {
    try {
        $authHeader = '';
        
        // Múltiples métodos para obtener el header Authorization
        $headers = getAllHeaders();
        
        if (isset($headers['Authorization'])) {
            $authHeader = $headers['Authorization'];
        } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        } elseif (function_exists('apache_request_headers')) {
            $requestHeaders = apache_request_headers();
            if (isset($requestHeaders['Authorization'])) {
                $authHeader = $requestHeaders['Authorization'];
            }
        }
        
        logDebug('Auth header encontrado: ' . substr($authHeader, 0, 20) . '...');
        
        if (empty($authHeader)) {
            logError('JWT Error: No se encontró header Authorization');
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'message' => 'Token de autorización no proporcionado',
                'debug' => [
                    'headers_available' => array_keys($headers),
                    'server_auth_vars' => array_filter($_SERVER, function($key) {
                        return strpos($key, 'AUTH') !== false || strpos($key, 'HTTP_') === 0;
                    }, ARRAY_FILTER_USE_KEY)
                ]
            ]);
            exit;
        }
        
        // Extraer token
        if (strpos($authHeader, 'Bearer ') === 0) {
            $token = substr($authHeader, 7);
        } else {
            $token = $authHeader;
        }
        
        if (empty($token)) {
            logError('JWT Error: Token vacío después de extraer');
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Token vacío']);
            exit;
        }
        
        $user = verifyJWT($token);
        
        if (!$user) {
            logError('JWT Error: Token inválido o expirado');
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Token inválido o expirado']);
            exit;
        }
        
        logDebug('Usuario autenticado exitosamente: ' . $user['username']);
        return $user;
        
    } catch (Exception $e) {
        logError('Error en requireAuth: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error interno de autenticación'
        ]);
        exit;
    }
}

// Función para enviar respuesta JSON
function sendJsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// Función para manejar errores de forma consistente
function handleError($message, $statusCode = 500, $debug = null) {
    logError($message, $debug ? ['debug' => $debug] : []);
    sendJsonResponse([
        'success' => false,
        'message' => $message,
        'debug' => $debug
    ], $statusCode);
}

?>
