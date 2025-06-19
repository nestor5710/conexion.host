<?php
// info.php - Diagnóstico muy básico sin dependencias

// Configurar reporte de errores
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Headers básicos - sin dependencias
header('Content-Type: text/html; charset=UTF-8');

echo "<!DOCTYPE html>";
echo "<html><head><title>Diagnóstico del Servidor</title></head><body>";
echo "<h1>Diagnóstico del Servidor</h1>";
echo "<pre>";

echo "=== INFORMACIÓN BÁSICA ===\n";
echo "Fecha y hora: " . date('Y-m-d H:i:s') . "\n";
echo "Versión de PHP: " . PHP_VERSION . "\n";
echo "Sistema Operativo: " . PHP_OS . "\n";
echo "Servidor Web: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Desconocido') . "\n";
echo "Directorio actual: " . __DIR__ . "\n";
echo "Archivo actual: " . __FILE__ . "\n";

echo "\n=== CONFIGURACIÓN PHP ===\n";
echo "memory_limit: " . ini_get('memory_limit') . "\n";
echo "max_execution_time: " . ini_get('max_execution_time') . "\n";
echo "upload_max_filesize: " . ini_get('upload_max_filesize') . "\n";
echo "post_max_size: " . ini_get('post_max_size') . "\n";
echo "display_errors: " . (ini_get('display_errors') ? 'On' : 'Off') . "\n";
echo "log_errors: " . (ini_get('log_errors') ? 'On' : 'Off') . "\n";

echo "\n=== EXTENSIONES CRÍTICAS ===\n";
$extensions = ['curl', 'json', 'openssl', 'hash', 'mbstring'];
foreach ($extensions as $ext) {
    echo "$ext: " . (extension_loaded($ext) ? 'Instalado ✓' : 'NO INSTALADO ✗') . "\n";
}

echo "\n=== ARCHIVOS EN DIRECTORIO API ===\n";
$apiDir = __DIR__;
if (is_dir($apiDir)) {
    $files = scandir($apiDir);
    foreach ($files as $file) {
        if ($file != '.' && $file != '..') {
            $filepath = $apiDir . '/' . $file;
            $size = is_file($filepath) ? filesize($filepath) : 'DIR';
            $perms = substr(sprintf('%o', fileperms($filepath)), -4);
            echo "$file - Tamaño: $size bytes - Permisos: $perms\n";
        }
    }
} else {
    echo "El directorio API no existe o no es accesible\n";
}

echo "\n=== VARIABLES DEL SERVIDOR ===\n";
$serverVars = ['HTTP_HOST', 'REQUEST_URI', 'SCRIPT_NAME', 'QUERY_STRING', 'REQUEST_METHOD', 'DOCUMENT_ROOT'];
foreach ($serverVars as $var) {
    echo "$var: " . ($_SERVER[$var] ?? 'No definido') . "\n";
}

echo "\n=== PRUEBA DE FUNCIONES BÁSICAS ===\n";

// Prueba de JSON
echo "JSON encode/decode: ";
try {
    $test = ['test' => 'valor'];
    $json = json_encode($test);
    $decoded = json_decode($json, true);
    echo ($decoded['test'] === 'valor') ? "OK ✓\n" : "FALLO ✗\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

// Prueba de cURL
echo "cURL básico: ";
if (function_exists('curl_init')) {
    try {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://httpbin.org/get');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        echo ($httpCode === 200) ? "OK ✓\n" : "HTTP $httpCode\n";
    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
    }
} else {
    echo "cURL no disponible ✗\n";
}

echo "\n=== VERIFICACIÓN DE ARCHIVOS CONFIG ===\n";

// Verificar config.php
$configFile = __DIR__ . '/config.php';
echo "config.php: ";
if (file_exists($configFile)) {
    echo "Existe - Tamaño: " . filesize($configFile) . " bytes\n";
    
    // Intentar incluir sin ejecutar
    echo "Sintaxis de config.php: ";
    $configContent = file_get_contents($configFile);
    if (strpos($configContent, '<?php') === 0) {
        // Verificar sintaxis básica
        $result = php_check_syntax($configFile, $error_message);
        if ($result) {
            echo "OK ✓\n";
        } else {
            echo "ERROR DE SINTAXIS: $error_message\n";
        }
    } else {
        echo "No parece ser un archivo PHP válido\n";
    }
} else {
    echo "NO EXISTE ✗\n";
}

// Verificar .htaccess
$htaccessFile = dirname(__DIR__) . '/.htaccess';
echo ".htaccess: ";
if (file_exists($htaccessFile)) {
    echo "Existe - Tamaño: " . filesize($htaccessFile) . " bytes\n";
} else {
    echo "NO EXISTE ✗\n";
}

echo "\n=== PERMISOS DE DIRECTORIO ===\n";
echo "Directorio API escribible: " . (is_writable(__DIR__) ? 'Sí ✓' : 'No ✗') . "\n";
echo "Directorio raíz escribible: " . (is_writable(dirname(__DIR__)) ? 'Sí ✓' : 'No ✗') . "\n";

echo "\n=== LOGS DE ERROR RECIENTES ===\n";
$errorLog = ini_get('error_log');
if ($errorLog && file_exists($errorLog)) {
    echo "Log de errores: $errorLog\n";
    $lines = file($errorLog);
    $recentLines = array_slice($lines, -10);
    foreach ($recentLines as $line) {
        echo "  " . trim($line) . "\n";
    }
} else {
    echo "No se encontró log de errores o no está configurado\n";
}

echo "</pre>";
echo "<h2>Siguiente paso:</h2>";
echo "<p>Si ves errores arriba, esos son los problemas que necesitas solucionar primero.</p>";
echo "</body></html>";

// Forzar output
if (ob_get_level()) {
    ob_end_flush();
}
flush();
?>
