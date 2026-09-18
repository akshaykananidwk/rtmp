<?php

/**
 * Fallback front controller for hosts whose document root points at the project
 * root instead of public/ and where mod_rewrite is unavailable.
 * The correct setup remains: document root = <project>/public
 */
$publicIndex = __DIR__.'/public/index.php';

if (! is_file($publicIndex)) {
    http_response_code(500);
    exit('Application not installed correctly: public/index.php is missing.');
}

// Serve real files inside public/ (assets) directly when this file is hit for them.
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
$asset = __DIR__.'/public'.$uri;
if ($uri !== '/' && is_file($asset) && ! str_contains($uri, '..')) {
    $types = ['css' => 'text/css', 'js' => 'application/javascript', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'ico' => 'image/x-icon', 'woff2' => 'font/woff2', 'txt' => 'text/plain', 'xml' => 'application/xml'];
    $ext = strtolower(pathinfo($asset, PATHINFO_EXTENSION));
    if (isset($types[$ext])) {
        header('Content-Type: '.$types[$ext]);
        header('X-Content-Type-Options: nosniff');
        readfile($asset);
        exit;
    }
}

$_SERVER['SCRIPT_FILENAME'] = $publicIndex;
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

require $publicIndex;
