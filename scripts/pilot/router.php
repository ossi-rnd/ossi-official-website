<?php

/*
 * The pilot's router for PHP's built-in server (keep-running.ps1). PILOT ONLY: a real
 * host (Laravel Cloud) compresses and caches by itself.
 *
 * The built-in server sends files uncompressed and with no cache headers, so every
 * page re-downloaded every script and stylesheet in full. For the built files in
 * public/build (their names change whenever their content does) this sends:
 *   - gzip, when the browser accepts it (made once, kept in storage/framework/cache);
 *   - Cache-Control: a year, immutable.
 * Everything else goes to Laravel's own router, unchanged.
 */

$public = getcwd();
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '');
$build = realpath($public.'/build');
$file = str_starts_with($uri, '/build/') ? realpath($public.$uri) : false;

// Only real files inside public/build; anything else (or a ../ trick) falls through.
if ($build !== false && $file !== false && is_file($file) && str_starts_with($file, $build.DIRECTORY_SEPARATOR)) {
    $types = [
        'js' => 'text/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'json' => 'application/json',
        'svg' => 'image/svg+xml',
        'woff2' => 'font/woff2',
        'woff' => 'font/woff',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'webp' => 'image/webp',
    ];
    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

    header('Content-Type: '.($types[$extension] ?? 'application/octet-stream'));
    header('Cache-Control: public, max-age=31536000, immutable');
    header('Vary: Accept-Encoding');

    $text = in_array($extension, ['js', 'css', 'json', 'svg'], true);

    if ($text && str_contains($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip')) {
        $cache = dirname($public).'/storage/framework/cache/pilot-gzip';
        $gzip = $cache.'/'.sha1($file).'.gz';

        if (! is_file($gzip) || filemtime($gzip) < filemtime($file)) {
            is_dir($cache) || mkdir($cache, 0775, true);
            file_put_contents($gzip, gzencode((string) file_get_contents($file), 9), LOCK_EX);
        }

        header('Content-Encoding: gzip');
        $file = $gzip;
    }

    header('Content-Length: '.filesize($file));
    readfile($file);

    return true;
}

// Pages and Livewire's JSON, gzipped too (they are the biggest part of a page load). Only a
// whole response in one piece, of an HTML/JSON/text type, not already encoded: a photo, a
// download or anything flushed in parts passes through untouched.
if (str_contains($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip')) {
    ob_start(function (string $buffer, int $phase): string {
        $whole = ($phase & PHP_OUTPUT_HANDLER_START) && ($phase & PHP_OUTPUT_HANDLER_FINAL);

        if (! $whole || headers_sent() || strlen($buffer) < 1024) {
            return $buffer;
        }

        $type = '';
        foreach (headers_list() as $header) {
            if (stripos($header, 'content-encoding:') === 0) {
                return $buffer;
            }
            if (stripos($header, 'content-type:') === 0) {
                $type = strtolower($header);
            }
        }

        if (preg_match('#text/html|application/json|text/plain#', $type) !== 1) {
            return $buffer;
        }

        header_remove('Content-Length');
        header('Content-Encoding: gzip');
        header('Vary: Accept-Encoding', false);

        return (string) gzencode($buffer, 6);
    });
}

return require dirname($public).'/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';
