<?php

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$publicPath = __DIR__.'/public'.$uri;

if ($uri !== '/' && file_exists($publicPath) && ! is_dir($publicPath)) {
    return false;
}

require_once __DIR__.'/public/index.php';
