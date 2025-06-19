<?php
// debug.php - Script para depurar problemas rápidamente

// Mostrar todos los errores
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Headers básicos
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Información del sistema
$debug_info = [
    'timestamp' => date('Y-m-d H:i:s'),
    'php_version' => PHP_VERSION,
    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
    'request_method' => $_SERVER['REQUEST_METHOD'],
    'request_uri' => $_SERVER['REQUEST_URI'],
    'script_name' => $_SERVER['SCRIPT_NAME'],
    'query_string' => $_SERVER['QUERY_STRING'] ?? '',
    'document_root' => $_SERVER['DOCUMENT_ROOT'],
    'current_directory' => __DIR__,
    'current_file' => __FILE__
];

// Verificar archivos críticos
$files_check = [];
$critical_files = [
    'config.php',
    'auth.php',
    'whatsapp.php',
    '.htaccess'
];

foreach ($critical_files as $file) {
    $path = __DIR__ . '/' . $file;
    $files_check[$file] = [
        'exists' => file_exists($path),
        'readable' => file_exists($path) ? is_readable($path) : false,
        'size' => file_exists($path) ? filesize($path) : 0,
        'path' => $path
    ];
}

// Verificar extensiones PHP
$extensions_check = [];
$required_extensions = ['curl', 'json', 'openssl', 'hash'];
foreach ($required_extensions as $ext) {
    $extensions_check[$ext] = extension_loaded($ext);
}

// Headers recibidos
$headers_received = [];
if (function_exists('getallheaders')) {
    $headers_received = getallheaders();
} else {
    foreach ($_SERVER as $name => $value) {
        if (substr($name, 0, 5) == 'HTTP_') {
            $headers_received[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
        }
    }
}

// Probar config.php si existe
$config_test = null;
if (file_exists(__DIR__ . '/config.php')) {
    try {
        ob_start();
        include_once __DIR__ . '/config.php';
        $config_output = ob_get_clean();
        
        $config_test = [
            'loaded' => true,
            'output' => $config_output,
            'constants_defined' => [
                'JWT_SECRET' => defined('JWT_SECRET'),
                'SUPABASE_URL' => defined('SUPABASE_URL'),
                'EVOLUTION_API_URL' => defined('EVOLUTION_API_URL')
            ],
            'functions_defined' => [
                'supabaseRequest' => function_exists('supabaseRequest'),
                'generateJWT' => function_exists('generateJWT'),
                'verifyJWT' => function_exists('verifyJWT'),
                'requireAuth' => function_exists('requireAuth')
            ]
        ];
    } catch (Exception $e) {
        $config_test = [
            'loaded' => false,
            'error' => $e->getMessage(),
            'line' => $e->getLine(),
            'file' => $e->getFile()
        ];
    }
}

// Probar una función simple de Supabase si config está cargado
$supabase_test = null;
if ($config_test && $config_test['loaded'] && function_exists('supabaseRequest')) {
    try {
        // Solo hacer un test simple que no requiera datos específicos
        $supabase_test = [
            'function_exists' => true,
            'constants_ok' => defined('SUPABASE_URL') && defined('SUPABASE_ANON_KEY')
        ];
    } catch (Exception $e) {
        $supabase_test = [
            'function_exists' => true,
            'error' => $e->getMessage()
        ];
    }
}

// Respuesta final
$response = [
    'status' => 'debug_info',
    'timestamp' => $debug_info['timestamp'],
    'system' => $debug_info,
    'files' => $files_check,
    'extensions' => $extensions_check,
    'headers' => $headers_received,
    'config_test' => $config_test,
    'supabase_test' => $supabase_test,
    'suggestions' => []
];

// Agregar sugerencias basadas en los problemas encontrados
if (!$files_check['config.php']['exists']) {
    $response['suggestions'][] = 'El archivo config.php no existe';
}

if (!$files_check['.htaccess']['exists']) {
    $response['suggestions'][] = 'El archivo .htaccess no existe';
}

if (!$extensions_check['curl']) {
    $response['suggestions'][] = 'La extensión cURL no está instalada';
}

if ($config_test && !$config_test['loaded']) {
    $response['suggestions'][] = 'Error cargando config.php: ' . ($config_test['error'] ?? 'Unknown error');
}

// Output
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit;
?>
