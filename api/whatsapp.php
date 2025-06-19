<?php
require_once 'config.php';

// Log de inicio
logDebug('=== WHATSAPP.PHP INICIADO ===');
logDebug('Method: ' . $_SERVER['REQUEST_METHOD']);
logDebug('URI: ' . $_SERVER['REQUEST_URI']);

try {
    // Requiere autenticación para todos los endpoints
    $user = requireAuth();
    logDebug('Usuario autenticado: ' . json_encode($user));
    
    $method = $_SERVER['REQUEST_METHOD'];
    $path = $_SERVER['REQUEST_URI'];

    // Extraer la acción de forma más robusta
    $pathParts = explode('/', trim(parse_url($path, PHP_URL_PATH), '/'));
    $action = '';
    
    $whatsappIndex = array_search('whatsapp', $pathParts);
    if ($whatsappIndex !== false && isset($pathParts[$whatsappIndex + 1])) {
        $action = $pathParts[$whatsappIndex + 1];
    }

    logDebug('Acción extraída: ' . $action);
    logDebug('User key: ' . ($user['key'] ?? 'no key'));

    // Enrutar según la acción
    switch (true) {
        case ($method === 'GET' && $action === 'status'):
            handleStatus($user);
            break;
            
        case ($method === 'POST' && $action === 'create-instance'):
            handleCreateInstance($user);
            break;
            
        case ($method === 'GET' && strpos($action, 'check-connection') === 0):
            $instanceName = $pathParts[$whatsappIndex + 2] ?? '';
            handleCheckConnection($instanceName);
            break;
            
        case ($method === 'POST' && $action === 'update-status'):
            handleUpdateStatus($user);
            break;
            
        default:
            handleError('Endpoint no encontrado', 404, [
                'method' => $method,
                'action' => $action,
                'available_endpoints' => [
                    'GET /api/whatsapp/status',
                    'POST /api/whatsapp/create-instance',
                    'GET /api/whatsapp/check-connection/{instanceName}',
                    'POST /api/whatsapp/update-status'
                ]
            ]);
    }

} catch (Exception $e) {
    logError('Error general en whatsapp.php: ' . $e->getMessage());
    handleError('Error interno del servidor', 500);
}

// Función para Evolution API
function evolutionRequest($endpoint, $method = 'GET', $data = null) {
    $url = EVOLUTION_API_URL . $endpoint;
    
    logDebug('Evolution Request: ' . $method . ' ' . $url);
    if ($data) {
        logDebug('Evolution Data: ' . json_encode($data));
    }
    
    $headers = [
        'apikey: ' . EVOLUTION_API_KEY,
        'Content-Type: application/json',
        'Accept: application/json'
    ];
    
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
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    logDebug('Evolution Response Code: ' . $httpCode);
    if ($error) {
        logError('Evolution CURL Error: ' . $error);
    }
    
    if ($error) {
        return ['data' => null, 'error' => $error, 'status' => 0];
    }
    
    $decodedResponse = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        logError('Evolution JSON decode error: ' . json_last_error_msg());
        return ['data' => null, 'error' => 'Invalid JSON response', 'status' => $httpCode];
    }
    
    return [
        'data' => $decodedResponse,
        'status' => $httpCode
    ];
}

function handleStatus($user) {
    try {
        logDebug('=== PROCESANDO STATUS ===');
        
        if (!isset($user['key']) || empty($user['key'])) {
            logError('Error: Usuario no tiene key definida');
            handleError('Usuario no tiene key definida', 400);
        }
        
        $userKey = $user['key'];
        logDebug('Buscando cuenta con key: ' . $userKey);
        
        $response = supabaseRequest("whitelabel_2_accounts?id=eq." . urlencode($userKey) . "&select=*");
        
        logDebug('Supabase response: ', [
            'status' => $response['status'],
            'data_count' => is_array($response['data']) ? count($response['data']) : 0
        ]);
        
        if ($response['status'] === 200 && !empty($response['data'])) {
            $account = $response['data'][0];
            
            // Verificar estado real si dice que está conectado
            if ($account['wa_conexion_status'] === 'connected') {
                $instanceStatus = evolutionRequest("/instance/connectionState/" . $account['wa_instance_name']);
                
                if ($instanceStatus['status'] !== 200 || 
                    !isset($instanceStatus['data']['state']) || 
                    $instanceStatus['data']['state'] !== 'open') {
                    
                    // Actualizar estado a desconectado
                    $updateResult = supabaseRequest(
                        "whitelabel_2_accounts?id=eq." . urlencode($userKey),
                        'PATCH',
                        ['wa_conexion_status' => 'disconnected']
                    );
                    logDebug('Updated disconnected status: ' . json_encode($updateResult));
                    $account['wa_conexion_status'] = 'disconnected';
                }
            }
            
            sendJsonResponse([
                'success' => true,
                'account' => $account
            ]);
        } else {
            logDebug('No se encontró cuenta para el usuario');
            sendJsonResponse([
                'success' => true,
                'status' => 'not_found',
                'message' => 'No se encontró información de WhatsApp'
            ]);
        }
        
    } catch (Exception $e) {
        logError('Error en handleStatus: ' . $e->getMessage());
        handleError('Error obteniendo estado de WhatsApp', 500);
    }
}

function handleCreateInstance($user) {
    try {
        logDebug('=== PROCESANDO CREATE-INSTANCE ===');
        
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            handleError('Datos JSON inválidos', 400);
        }
        
        $instanceName = isset($input['instanceName']) ? trim($input['instanceName']) : '';
        
        logDebug('Instance name recibido: ' . $instanceName);
        
        if (empty($instanceName)) {
            handleError('Nombre de instancia requerido', 400);
        }
        
        // Verificar si ya existe la instancia
        $checkInstance = evolutionRequest("/instance/fetchInstances?instanceName=" . urlencode($instanceName));
        
        if ($checkInstance['status'] === 200 && !empty($checkInstance['data'])) {
            logDebug('Instancia ya existe, obteniendo estado...');
            
            // Intentar obtener QR o estado de conexión
            $connectionState = evolutionRequest("/instance/connectionState/" . $instanceName);
            
            if ($connectionState['status'] === 200 && isset($connectionState['data']['qrcode'])) {
                sendJsonResponse([
                    'success' => true,
                    'qrcode' => $connectionState['data']['qrcode']
                ]);
                return;
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
        
        logDebug('Creando instancia con datos: ' . json_encode($instanceData));
        
        $response = evolutionRequest('/instance/create', 'POST', $instanceData);
        
        logDebug('Respuesta de creación: ', [
            'status' => $response['status'],
            'has_qrcode' => isset($response['data']['qrcode'])
        ]);
        
        if ($response['status'] === 200 || $response['status'] === 201) {
            sendJsonResponse([
                'success' => true,
                'qrcode' => $response['data']['qrcode'] ?? null,
                'instance' => $response['data']
            ]);
        } else {
            handleError('Error al crear instancia', 400, [
                'evolution_response' => $response['data'],
                'evolution_status' => $response['status']
            ]);
        }
        
    } catch (Exception $e) {
        logError('Error en handleCreateInstance: ' . $e->getMessage());
        handleError('Error creando instancia de WhatsApp', 500);
    }
}

function handleCheckConnection($instanceName) {
    try {
        logDebug('=== PROCESANDO CHECK-CONNECTION ===');
        logDebug('Instance name: ' . $instanceName);
        
        if (empty($instanceName)) {
            handleError('Nombre de instancia requerido', 400);
        }
        
        $response = evolutionRequest("/instance/connectionState/" . urlencode($instanceName));
        
        logDebug('Connection state response: ', [
            'status' => $response['status'],
            'has_data' => !empty($response['data'])
        ]);
        
        if ($response['status'] === 200) {
            sendJsonResponse([
                'success' => true,
                'status' => $response['data']
            ]);
        } else {
            handleError('Error verificando conexión', 500, [
                'evolution_status' => $response['status'],
                'evolution_error' => $response['error'] ?? null
            ]);
        }
        
    } catch (Exception $e) {
        logError('Error en handleCheckConnection: ' . $e->getMessage());
        handleError('Error verificando conexión', 500);
    }
}

function handleUpdateStatus($user) {
    try {
        logDebug('=== PROCESANDO UPDATE-STATUS ===');
        
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            handleError('Datos JSON inválidos', 400);
        }
        
        $status = $input['status'] ?? '';
        $instanceName = $input['instanceName'] ?? '';
        
        if (empty($status) || empty($instanceName)) {
            handleError('Status e instanceName son requeridos', 400);
        }
        
        // Actualizar en Supabase
        $updateData = [
            'wa_conexion_status' => $status,
            'wa_instance_name' => $instanceName,
            'wa_conexion_date' => date('c')
        ];
        
        $response = supabaseRequest(
            "whitelabel_2_accounts?id=eq." . urlencode($user['key']),
            'PATCH',
            $updateData
        );
        
        if ($response['status'] === 200 || $response['status'] === 204) {
            sendJsonResponse([
                'success' => true,
                'message' => 'Estado actualizado correctamente'
            ]);
        } else {
            handleError('Error actualizando estado', 500, $response);
        }
        
    } catch (Exception $e) {
        logError('Error en handleUpdateStatus: ' . $e->getMessage());
        handleError('Error actualizando estado', 500);
    }
}

?>
