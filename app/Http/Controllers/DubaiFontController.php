<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DubaiFontController extends Controller
{
    private const FILES = [
        'bold' => 'Dubai-Bold',
        'light' => 'Dubai-Light',
        'medium' => 'Dubai-Medium',
        'regular' => 'Dubai-Regular',
    ];

    public function __invoke(string $weight, string $format): BinaryFileResponse
    {
        abort_unless(isset(self::FILES[$weight]), 404);
        abort_unless(in_array($format, ['ttf', 'woff2'], true), 404);

        $path = public_path('fonts/dubai/'.self::FILES[$weight].'.'.$format);
        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'Content-Type' => $format === 'woff2' ? 'font/woff2' : 'font/ttf',
        ]);
    }
}
