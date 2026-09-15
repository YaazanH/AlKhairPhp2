<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class InvoiceOcrService
{
    /** @return list<array{label: string, image: string}> Full pages and overlapping detail views. */
    public function images(UploadedFile $file): array
    {
        $directory = storage_path('framework/cache/invoice-ocr/'.Str::uuid());
        File::ensureDirectoryExists($directory, 0700);
        $deadline = microtime(true) + (int) config('invoice_capture.timeout_seconds', 45);
        try {
            if ($file->getMimeType() !== 'application/pdf') {
                return $this->prepareImage($file->getRealPath(), 1);
            }
            $info = $this->run([(string) config('invoice_capture.pdfinfo_binary'), $file->getRealPath()], $deadline);
            if (! preg_match('/^Pages:\s+(\d+)/m', $info, $match)) {
                throw new RuntimeException('invoice_capture.errors.unreadable');
            }
            $pages = (int) $match[1];
            if ($pages < 1 || $pages > (int) config('invoice_capture.max_pages', 3)) {
                throw new RuntimeException('invoice_capture.errors.page_limit');
            }
            $images = [];
            for ($page = 1; $page <= $pages; $page++) {
                $prefix = $directory.'/page-'.$page;
                $this->run([
                    (string) config('invoice_capture.pdftoppm_binary'), '-f', (string) $page, '-l', (string) $page,
                    '-singlefile', '-scale-to', '3200', '-png', $file->getRealPath(), $prefix,
                ], $deadline);
                array_push($images, ...$this->prepareImage($prefix.'.png', $page));
            }

            return $images;
        } finally {
            File::deleteDirectory($directory);
        }
    }

    private function prepareImage(string $path, int $page): array
    {
        $dimensions = @getimagesize($path);
        if (! $dimensions || (int) config('invoice_capture.max_image_pixels') < $dimensions[0] * $dimensions[1]) {
            throw new RuntimeException('invoice_capture.errors.image_size');
        }
        $image = @imagecreatefromstring(File::get($path));
        if (! $image) {
            throw new RuntimeException('invoice_capture.errors.unreadable');
        }
        if ($dimensions[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $orientation = (int) ((@exif_read_data($path) ?: [])['Orientation'] ?? 1);
            if (in_array($orientation, [2, 4, 5, 7], true)) {
                imageflip($image, IMG_FLIP_HORIZONTAL);
            }
            $angle = match ($orientation) {
                3, 4 => 180, 5, 6 => -90, 7, 8 => 90, default => 0
            };
            if ($angle) {
                $image = imagerotate($image, $angle, 0xFFFFFF);
            }
        }
        $width = imagesx($image);
        $height = imagesy($image);
        $views = [['label' => "Page {$page}: full page", 'image' => $this->encodeView($image, 0, 0, $width, $height, 1280)]];
        // Overlap keeps digits/rows near a crop edge visible in another view. Keep the
        // original ink: denoising and thresholding can erase small handwritten zeros.
        $cropWidth = (int) ceil($width * 0.6);
        $cropHeight = (int) ceil($height * 0.6);
        foreach ([
            ['top left', 0, 0], ['top right', $width - $cropWidth, 0],
            ['bottom left', 0, $height - $cropHeight], ['bottom right', $width - $cropWidth, $height - $cropHeight],
        ] as [$position, $x, $y]) {
            $views[] = ['label' => "Page {$page}: {$position} detail (same page, overlapping)", 'image' => $this->encodeView($image, $x, $y, $cropWidth, $cropHeight, 1536)];
        }

        return $views;
    }

    private function encodeView(\GdImage $image, int $x, int $y, int $width, int $height, int $edge): string
    {
        $scale = min(3, $edge / max($width, $height));
        $canvas = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $image, 0, 0, $x, $y, imagesx($canvas), imagesy($canvas), $width, $height);
        ob_start();
        imagepng($canvas);

        return base64_encode((string) ob_get_clean());
    }

    public function read(UploadedFile $file): string
    {
        $directory = storage_path('framework/cache/invoice-ocr/'.Str::uuid());
        File::ensureDirectoryExists($directory, 0700);
        $deadline = microtime(true) + (int) config('invoice_capture.timeout_seconds', 45);

        try {
            if ($file->getMimeType() !== 'application/pdf') {
                $dimensions = @getimagesize($file->getRealPath());
                if (! $dimensions || (int) config('invoice_capture.max_image_pixels') < $dimensions[0] * $dimensions[1]) {
                    throw new RuntimeException('invoice_capture.errors.image_size');
                }

                return $this->recognize($file->getRealPath(), $deadline);
            }

            $info = $this->run([(string) config('invoice_capture.pdfinfo_binary'), $file->getRealPath()], $deadline);
            if (! preg_match('/^Pages:\s+(\d+)/m', $info, $matches)) {
                throw new RuntimeException('invoice_capture.errors.unreadable');
            }

            $pages = (int) $matches[1];
            if ($pages > (int) config('invoice_capture.max_pages', 3)) {
                throw new RuntimeException('invoice_capture.errors.page_limit');
            }

            $texts = [];
            for ($page = 1; $page <= $pages; $page++) {
                $text = $this->run([
                    (string) config('invoice_capture.pdftotext_binary'), '-f', (string) $page, '-l', (string) $page,
                    '-layout', '-enc', 'UTF-8', $file->getRealPath(), '-',
                ], $deadline);

                // Read real PDF text first; rasterise pages that contain only a scan.
                if (mb_strlen(preg_replace('/\s+/u', '', $text) ?? '') < 30) {
                    $prefix = $directory.'/page-'.$page;
                    $this->run([
                        (string) config('invoice_capture.pdftoppm_binary'), '-f', (string) $page, '-l', (string) $page,
                        '-singlefile', '-scale-to', '2400', '-png', $file->getRealPath(), $prefix,
                    ], $deadline);
                    $text = $this->recognize($prefix.'.png', $deadline);
                }

                $texts[] = $text;
            }

            return implode("\n\n", $texts);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    private function recognize(string $path, float $deadline): string
    {
        $command = [(string) config('invoice_capture.tesseract_binary'), $path, 'stdout'];
        if ($directory = config('invoice_capture.tessdata_directory')) {
            array_push($command, '--tessdata-dir', (string) $directory);
        }
        array_push($command, '-l', (string) config('invoice_capture.languages'), '--psm', '6', '-c', 'preserve_interword_spaces=1');

        return $this->run($command, $deadline);
    }

    private function run(array $command, float $deadline): string
    {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            throw new RuntimeException('invoice_capture.errors.timed_out');
        }

        $process = new Process($command, null, ['OMP_THREAD_LIMIT' => '2', 'LC_ALL' => 'C']);
        $process->setTimeout($remaining);
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new RuntimeException('invoice_capture.errors.timed_out');
        }

        if (! $process->isSuccessful()) {
            $error = $process->getErrorOutput();
            if (in_array($process->getExitCode(), [126, 127], true) || str_contains($error, 'Error opening data file')) {
                throw new RuntimeException('invoice_capture.errors.unavailable');
            }
            throw new RuntimeException('invoice_capture.errors.unreadable');
        }

        return mb_substr($process->getOutput(), 0, 60000);
    }
}
