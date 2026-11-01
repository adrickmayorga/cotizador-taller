<?php
declare(strict_types=1);

// Protege la respuesta PDF contra avisos/espacios accidentales antes de las cabeceras.
if (ob_get_level() === 0) { ob_start(); }

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/bbva_pyme.php';

// El HTML usa la misma cookie: liberar la sesión antes de solicitarlo.
$srnuPdfCookie = session_name() . '=' . session_id();
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$id = (int)($_GET['id'] ?? 0);
$tipo = (($_GET['tipo'] ?? 'factura') === 'sin_factura') ? 'sin_factura' : 'factura';
$accion = (($_GET['accion'] ?? 'descargar') === 'ver') ? 'ver' : 'descargar';

if ($id <= 0) {
    http_response_code(400);
    exit('ID de cotización inválido.');
}

try {
    $cotizacion = srnu_obtener_cotizacion($id);
} catch (Throwable $e) {
    http_response_code(500);
    exit('Error de base de datos: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

if (!$cotizacion) {
    http_response_code(404);
    exit('Cotización no encontrada.');
}

$bbva_pyme = srnu_cotizacion_usa_bbva($cotizacion, $tipo === 'factura');
if ($bbva_pyme) { $tipo = 'factura'; }

$dir = __DIR__ . DIRECTORY_SEPARATOR . 'pdf';
if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
    http_response_code(500);
    exit('No se pudo crear la carpeta pdf.');
}

$filename = srnu_pdf_filename(
    (string)$cotizacion['cliente'],
    (string)$cotizacion['vehiculo'],
    $tipo === 'factura' ? 'si' : 'no'
);

$pdfFile = $dir . DIRECTORY_SEPARATOR . $filename;

/*
 * IMPORTANTE:
 * No mandamos Chrome directamente a una URL PHP dinámica.
 * Primero solicitamos a PHP la cotización COMPLETA ya renderizada,
 * guardamos ese HTML en un archivo temporal y luego Chrome imprime
 * ese archivo local. Así evitamos PDFs en blanco por carga incompleta.
 */
/* Detecta automáticamente la carpeta actual de XAMPP, incluso si contiene espacios. */
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/srnu sql/generar_pdf.php'));
$scriptDir = rtrim($scriptDir, '/');
$encodedDir = implode('/', array_map('rawurlencode', array_filter(explode('/', $scriptDir), 'strlen')));
$encodedDir = $encodedDir !== '' ? '/' . $encodedDir : '';

$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
$baseUrls = [];
if ($host !== '') {
    $baseUrls[] = ($https ? 'https://' : 'http://') . $host . $encodedDir . '/cotizador.php';
}
$baseUrls[] = 'http://localhost' . $encodedDir . '/cotizador.php';
$baseUrls[] = 'http://127.0.0.1' . $encodedDir . '/cotizador.php';
$baseUrls = array_values(array_unique($baseUrls));

$query = http_build_query([
    'id' => $id,
    'modo_pdf' => '1',
    'tipo' => $tipo,
]);

$html = false;
$lastError = '';

foreach ($baseUrls as $baseUrl) {
    $url = $baseUrl . '?' . $query;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPGET => true,
            CURLOPT_USERAGENT => 'SRNU-Cotizador-PDF/1.0',
            CURLOPT_COOKIE => $srnuPdfCookie,
        ]);
        $result = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($result !== false && $httpCode >= 200 && $httpCode < 300) {
            $html = $result;
            break;
        }

        $lastError = 'HTTP ' . $httpCode . ($curlError ? ' - ' . $curlError : '');
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 30,
                'header' => "User-Agent: SRNU-Cotizador-PDF/1.0\r\nCookie: " . $srnuPdfCookie . "\r\n",
            ],
        ]);
        $result = @file_get_contents($url, false, $context);
        if ($result !== false) {
            $html = $result;
            break;
        }
        $lastError = 'No fue posible obtener la página.';
    }
}

if ($html === false || strlen($html) < 500) {
    http_response_code(500);
    echo '<!doctype html><html lang="es"><meta charset="utf-8"><body style="font-family:Arial;padding:30px">';
    echo '<h2>No se pudo preparar el PDF</h2>';
    echo '<p>El servidor no pudo cargar la cotización completa.</p>';
    echo '<p><strong>Detalle:</strong> ' . htmlspecialchars($lastError, ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<p>Prueba primero abrir directamente la cotización en:</p>';
    echo '<p><a href="cotizador.php?id=' . $id . '&modo_pdf=1&tipo=' . urlencode($tipo) . '">Abrir cotización para imprimir</a></p>';
    echo '</body></html>';
    exit;
}

/* Verificación básica para no crear un PDF blanco. */
if (stripos($html, 'ORDEN DE TRABAJO') === false) {
    http_response_code(500);
    exit('La cotización fue cargada, pero no contiene el contenido esperado de la Orden de Trabajo.');
}

/* Evita que el HTML intente abrir otro cuadro de impresión dentro de Chrome. */
$html = preg_replace(
    '/<script\b[^>]*>.*?window\.print\(\).*?<\/script>/is',
    '',
    $html
) ?? $html;

/* También eliminamos cualquier referencia al auto-print que haya quedado. */
$html = preg_replace('/window\.addEventListener\([\s\S]*?window\.print\(\)\s*;?[\s\S]*?\}\);/i', '', $html) ?? $html;

$tempHtml = tempnam(sys_get_temp_dir(), 'srnu_pdf_');
if ($tempHtml === false) {
    http_response_code(500);
    exit('No se pudo crear el archivo temporal para el PDF.');
}

/* Chrome puede leer directamente archivos locales; usamos .html para que lo interprete como HTML. */
$tempHtmlReal = $tempHtml . '.html';
@rename($tempHtml, $tempHtmlReal);
$tempHtml = $tempHtmlReal;

if (@file_put_contents($tempHtml, $html) === false) {
    @unlink($tempHtml);
    http_response_code(500);
    exit('No se pudo guardar el HTML temporal.');
}

$chromeCandidates = [];
if (PHP_OS_FAMILY === 'Windows') {
    $programFiles = getenv('ProgramFiles') ?: 'C:\\Program Files';
    $programFilesX86 = getenv('ProgramFiles(x86)') ?: 'C:\\Program Files (x86)';
    $localAppData = getenv('LOCALAPPDATA') ?: '';

    $chromeCandidates = [
        $programFiles . '\\Google\\Chrome\\Application\\chrome.exe',
        $programFilesX86 . '\\Google\\Chrome\\Application\\chrome.exe',
        $localAppData . '\\Google\\Chrome\\Application\\chrome.exe',
        $programFiles . '\\Microsoft\\Edge\\Application\\msedge.exe',
        $programFilesX86 . '\\Microsoft\\Edge\\Application\\msedge.exe',
        'C:\\Program Files\\Chromium\\Application\\chrome.exe',
    ];
} else {
    $chromeCandidates = [
        '/usr/bin/google-chrome',
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
    ];
}

$chrome = null;
foreach ($chromeCandidates as $candidate) {
    if ($candidate && is_file($candidate)) {
        $chrome = $candidate;
        break;
    }
}

if ($chrome === null || !function_exists('exec')) {
    @unlink($tempHtml);

    header('Content-Type: text/html; charset=utf-8');
    $tabTitle = trim((string)$cotizacion['cliente']) . ' - ' . trim((string)$cotizacion['vehiculo']);
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><title>' . htmlspecialchars($tabTitle, ENT_QUOTES, 'UTF-8') . '</title><link rel="icon" type="image/png" href="favicon.png"><link rel="shortcut icon" type="image/png" href="favicon.png"></head><body style="font-family:Arial;padding:30px">';
    echo '<h2>Cotización lista</h2>';
    echo '<p>No se encontró Google Chrome o Microsoft Edge para generar el PDF automáticamente.</p>';
    echo '<p><a href="cotizador.php?id=' . $id . '&modo_pdf=1&tipo=' . urlencode($tipo) . '" target="_blank">Abrir cotización e imprimir</a></p>';
    echo '</body></html>';
    exit;
}

$profile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'srnu_chrome_' . $id . '_' . bin2hex(random_bytes(4));
@mkdir($profile, 0700, true);

/* file:/// requiere ruta absoluta; realpath evita problemas de rutas relativas. */
$localHtml = realpath($tempHtml);
$localPdf = realpath($dir);
if ($localHtml === false || $localPdf === false) {
    @unlink($tempHtml);
    http_response_code(500);
    exit('No se pudo resolver la ruta temporal del PDF.');
}

$pdfPath = $localPdf . DIRECTORY_SEPARATOR . basename($pdfFile);
$fileUrl = 'file:///' . str_replace('\\', '/', ltrim($localHtml, '/\\'));

$cmd = escapeshellarg($chrome)
    . ' --headless=new'
    . ' --disable-gpu'
    . ' --no-sandbox'
    . ' --disable-dev-shm-usage'
    . ' --disable-extensions'
    . ' --user-data-dir=' . escapeshellarg($profile)
    . ' --allow-file-access-from-files'
    . ' --run-all-compositor-stages-before-draw'
    . ' --virtual-time-budget=4000'
    . ' --no-pdf-header-footer'
    . ' --print-to-pdf=' . escapeshellarg($pdfPath)
    . ' ' . escapeshellarg($fileUrl)
    . ' 2>&1';

$output = [];
$code = 0;
exec($cmd, $output, $code);

@unlink($tempHtml);

/* Limpiar perfil temporal de Chrome. */
if (is_dir($profile)) {
    if (function_exists('exec')) {
        if (PHP_OS_FAMILY === 'Windows') {
            @exec('rmdir /S /Q ' . escapeshellarg($profile));
        } else {
            @exec('rm -rf ' . escapeshellarg($profile));
        }
    }
}

clearstatcache(true, $pdfPath);
$generatedSize = is_file($pdfPath) ? (int)@filesize($pdfPath) : 0;

if ($code === 0 && $generatedSize > 5000) {
    // Los PDFs nuevos llevan un respaldo estructurado recuperable. No contiene
    // costos internos, pagos ni notas privadas.
    @srnu_inyectar_respaldo_pdf($pdfPath, $cotizacion);

    // IMPORTANTE: la inyección anterior modifica el tamaño del PDF. PHP almacena
    // resultados de stat()/filesize() en caché, así que debemos refrescarlo antes
    // de enviar Content-Length; de lo contrario el navegador puede recibir un PDF
    // truncado y la descarga falla o queda dañada.
    clearstatcache(true, $pdfPath);
    $finalSize = (int)@filesize($pdfPath);
    if ($finalSize <= 5000) {
        http_response_code(500);
        exit('El PDF fue generado, pero el archivo final no es válido.');
    }

    // Evita que notices/warnings capturados antes de este punto contaminen los bytes PDF.
    while (ob_get_level() > 0) { @ob_end_clean(); }

    $disposition = $accion === 'ver' ? 'inline' : 'attachment';
    $downloadName = basename($pdfPath);
    $fallbackName = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $downloadName);
    if (!is_string($fallbackName) || trim($fallbackName) === '') $fallbackName = 'cotizacion-srnu.pdf';
    $fallbackName = preg_replace('/[^A-Za-z0-9 .,_()-]+/', '_', $fallbackName) ?? 'cotizacion-srnu.pdf';
    $fallbackName = str_replace(['"', "\r", "\n"], '', $fallbackName);

    header('Content-Type: application/pdf');
    header('X-Content-Type-Options: nosniff');
    header('Content-Transfer-Encoding: binary');
    header('Accept-Ranges: bytes');
    header("Content-Disposition: {$disposition}; filename=\"{$fallbackName}\"; filename*=UTF-8''" . rawurlencode($downloadName));
    header('Content-Length: ' . $finalSize);
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');

    $fh = @fopen($pdfPath, 'rb');
    if ($fh === false) {
        http_response_code(500);
        exit('No se pudo abrir el PDF final para enviarlo al navegador.');
    }
    fpassthru($fh);
    fclose($fh);
    exit;
}

http_response_code(500);
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html lang="es"><meta charset="utf-8"><body style="font-family:Arial;padding:30px">';
echo '<h2>No se pudo generar el PDF</h2>';
echo '<p>Chrome/Edge terminó sin crear un PDF válido.</p>';
if (!empty($output)) {
    echo '<pre style="white-space:pre-wrap;background:#f5f5f5;padding:15px;border-radius:8px">' . htmlspecialchars(implode("\n", $output), ENT_QUOTES, 'UTF-8') . '</pre>';
}
echo '<p><a href="cotizador.php?id=' . $id . '&modo_pdf=1&tipo=' . urlencode($tipo) . '" target="_blank">Abrir cotización e imprimir manualmente</a></p>';
echo '</body></html>';
