<?php
require_once 'config.php';

// Log de inicio
error_log('=== WHATSAPP.PHP INICIADO ===');
error_log('Method: ' . $_SERVER['REQUEST_METHOD']);
error_log('URI: ' . $_SERVER['REQUEST_URI']);

// Requiere autenticación para todos los endpoints
try {
    $user = requireAuth();
    error_log('Usuario autenticado exitosamente: ' . json_encode($user));
} catch (Exception $e) {
    error_log('Error en autenticación: ' . $e->getMessage());
    http_response_code(401);
    echo json_encode([
        'success' => false, 
        'message' => 'Error de autenticación: ' . $e->getMessage()
    ]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$path = $_SERVER['REQUEST_URI'];

// Extraer la acción
preg_match('/whatsapp\/([^?]*)/', $path, $matches);
$action = isset($matches[1]) ? $matches[1] : '';

error_log('Acción extraída: ' . $action);
error_log('User key: ' . ($user['key'] ?? 'no key'));

// Función para Evolution API
function evolutionRequest($endpoint, $method = 'GET', $data = null) {
    $url = EVOLUTION_API_URL . $endpoint;
    
    error_log('Evolution Request: ' . $method . ' ' . $url);
    if ($data) {
        error_log('Evolution Data: ' . json_encode($data));
    }
    
    $headers = [
        'apikey: ' . EVOLUTION_API_KEY,
        'Content-Type: application/json'
    ];
    
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
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    error_log('Evolution Response Code: ' . $httpCode);
    if ($error) {
        error_log('Evolution CURL Error: ' . $error);
    }
    
    if ($error) {
        return ['data' => null, 'error' => $error, 'status' => 0];
    }
    
    return [
        'data' => json_decode($response, true),
        'status' => $httpCode
    ];
}

if ($method === 'GET' && $action === 'status') {
    error_log('=== PROCESANDO STATUS ===');
    
    // Obtener estado
    if (!isset($user['key']) || empty($user['key'])) {
        error_log('Error: Usuario no tiene key definida');
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Usuario no tiene key definida'
        ]);
        exit;
    }
    
    $userKey = $user['key'];
    error_log('Buscando cuenta con key: ' . $userKey);
    
    $response = supabaseRequest("whitelabel_2_accounts?id=eq." . urlencode($userKey) . "&select=*");
    
    error_log('Supabase response status: ' . $response['status']);
    error_log('Supabase response data: ' . json_encode($response['data']));
    
    if ($response['status'] === 200 && !empty($response['data'])) {
        $account = $response['data'][0];
        
        // Verificar estado real si dice que está conectado
        if ($account['wa_conexion_status'] === 'connected') {
            $instanceStatus = evolutionRequest("/instance/connectionState/" . $account['wa_instance_name']);
            
            if (!isset($instanceStatus['data']['state']) || $instanceStatus['data']['state'] !== 'open') {
                // Actualizar estado
                $updateResult = supabaseRequest(
                    "whitelabel_2_accounts?id=eq." . urlencode($userKey),
                    'PATCH',
                    ['wa_conexion_status' => 'disconnected']
                );
                error_log('Updated disconnected status: ' . json_encode($updateResult));
                $account['wa_conexion_status'] = 'disconnected';
            }
        }
        
        echo json_encode([
            'success' => true,
            'account' => $account
        ]);
    } else {
        error_log('No se encontró cuenta para el usuario');
        echo json_encode([
            'success' => true,
            'status' => 'not_found',
            'message' => 'No se encontró información de WhatsApp'
        ]);
    }
    
} elseif ($method === 'POST' && $action === 'create-instance') {
    error_log('=== PROCESANDO CREATE-INSTANCE ===');
    
    // Crear instancia
    $input = json_decode(file_get_contents('php://input'), true);
    $instanceName = isset($input['instanceName']) ? $input['instanceName'] : '';
    
    error_log('Instance name recibido: ' . $instanceName);
    
    if (empty($instanceName)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Nombre de instancia requerido'
        ]);
        exit;
    }
    
    // Primero verificar si ya existe
    $checkInstance = evolutionRequest("/instance/fetchInstances?instanceName=" . urlencode($instanceName));
    
    if ($checkInstance['status'] === 200 && !empty($checkInstance['data'])) {
        error_log('Instancia ya existe, obteniendo QR...');
        // Ya existe, intentar obtener QR
        $connectionState = evolutionRequest("/instance/connectionState/" . $instanceName);
        
        if (isset($connectionState['data']['qrcode'])) {
            echo json_encode([
                'success' => true,
                'qrcode' => $connectionState['data']['qrcode']
            ]);
            exit;
        }
    }
    
    // Crear nueva instancia
    $instanceData = [
        'instanceName' => $instanceName,
        'qrcode' => true,
        'integration' => 'WHATSAPP-BAILEYS',
        'rejectCall' => true,
        'groupsIgnore' => true,
        'alwaysOnline' => false,
        'readMessages' => false,
        'readStatus' => false,
        'syncFullHistory' => false,
        'webhook' => [
            'url' => N8N_WEBHOOK_URL . '/msjreceived',
            'byEvents' => false,
            'base64' => true,
            'events' => [
                'CONNECTION_UPDATE',
                'LOGOUT_INSTANCE',
                'MESSAGES_UPSERT',
                'REMOVE_INSTANCE'
            ]
        ]
    ];
    
    error_log('Creando instancia con datos: ' . json_encode($instanceData));
    
    $response = evolutionRequest('/instance/create', 'POST', $instanceData);
    
    error_log('Respuesta de creación: Status ' . $response['status']);
    error_log('Respuesta de creación: Data ' . json_encode($response['data']));
    
    if ($response['status'] === 200 || $response['status'] === 201) {
        echo json_encode([
            'success' => true,
            'qrcode' => isset($response['data']['qrcode']) ? $response['data']['qrcode'] : null
        ]);
    } else {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Error al crear instancia',
            'details' => $response['data'],
            'evolution_status' => $response['status']
        ]);
    }