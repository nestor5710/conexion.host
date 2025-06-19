<?php
// check.php - Script para verificar la configuración del servidor

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$checks = [];

// Verificar PHP
$checks['php_version'] = [
    'status' => version_compare(PHP_VERSION, '7.4.0', '>='),
    'value' => PHP_VERSION,
    'message' => 'PHP 7.4+ requerido'
];

// Verificar extensiones necesarias
$required_extensions = ['curl', 'json', 'openssl'];
foreach ($required_extensions as $ext) {
    $checks["extension_$ext"] = [
        'status' => extension_loaded($ext),
        'value' => extension_loaded($ext) ? 'Instalado' : 'No instalado',
        'message' => "Extensión $ext requerida"
    ];
}

// Verificar archivos
$required_files = ['config.php', 'auth.php', 'whatsapp.php'];
foreach ($required_files as $file) {
    $checks["file_$file"] = [
        'status' => file_exists(__DIR__ . "/$file"),
        'value' => file_exists(__DIR__ . "/$file") ? 'Existe' : 'No existe',
        'message' => "Archivo $file requerido"
    ];
}

// Verificar permisos de escritura
$checks['write_permissions'] = [
    'status' => is_writable(__DIR__),
    'value' => is_writable(__DIR__) ? 'Escribible' : 'No escribible',
    'message' => 'Directorio debe ser escribible para logs'
];

// Verificar .htaccess
$checks['htaccess'] = [
    'status' => file_exists(__DIR__ . '/.htaccess'),
    'value' => file_exists(__DIR__ . '/.htaccess') ? 'Existe' : 'No existe',
    'message' => 'Archivo .htaccess requerido para reescritura de URLs'
];

// Verificar mod_rewrite
$checks['mod_rewrite'] = [
    'status' => function_exists('apache_get_modules') ? in_array('mod_rewrite', apache_get_modules()) : null,
    'value' => function_exists('apache_get_modules') ? 
        (in_array('mod_rewrite', apache_get_modules()) ? 'Habilitado' : 'Deshabilitado') : 
        'No se puede verificar',
    'message' => 'mod_rewrite requerido para URLs amigables'
];

// Verificar variables de entorno/configuración
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
    
    $checks['supabase_url'] = [
        'status' => defined('SUPABASE_URL') && !empty(SUPABASE_URL),
        'value' => defined('SUPABASE_URL') ? SUPABASE_URL : 'No definido',
        'message' => 'URL de Supabase configurada'
    ];
    
    $checks['evolution_url'] = [
        'status' => defined('EVOLUTION_API_URL') && !empty(EVOLUTION_API_URL),
        'value' => defined('EVOLUTION_API_URL') ? EVOLUTION_API_URL : 'No definido',
        'message' => 'URL de Evolution API configurada'
    ];
    
    $checks['jwt_secret'] = [
        'status' => defined('JWT_SECRET') && !empty(JWT_SECRET),
        'value' => defined('JWT_SECRET') ? 'Configurado' : 'No configurado',
        'message' => 'Secreto JWT configurado'
    ];
}

// Test de conectividad
$connectivity_tests = [];

// Test Supabase
if (defined('SUPABASE_URL')) {
    $ch = curl_init(SUPABASE_URL . '/rest/v1/');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    $connectivity_tests['supabase'] = [
        'status' => $httpCode === 200 || $httpCode === 401, // 401 es normal sin auth
        'value' => $httpCode ? "HTTP $httpCode" : "Error: $error",
        'message' => 'Conectividad con Supabase'
    ];
}

// Test Evolution API
if (defined('EVOLUTION_API_URL')) {
    $ch = curl_init(EVOLUTION_API_URL . '/manager/getInstances');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'apikey: ' . (defined('EVOLUTION_API_KEY') ? EVOLUTION_API_KEY : '')
    ]);
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    $connectivity_tests['evolution'] = [
        'status' => $httpCode === 200,
        'value' => $httpCode ? "HTTP $httpCode" : "Error: $error",
        'message' => 'Conectividad con Evolution API'
    ];
}

// Preparar respuesta
$overall_status = true;
foreach ($checks as $check) {
    if ($check['status'] === false) {
        $overall_status = false;
        break;
    }
}

$response = [
    'overall_status' => $overall_status,
    'timestamp' => date('Y-m-d H:i:s'),
    'server_info' => [
        'php_version' => PHP_VERSION,
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
        'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'Unknown'
    ],
    'checks' => $checks,
    'connectivity_tests' => $connectivity_tests
];

echo json_encode($response, JSON_PRETTY_PRINT);
?>