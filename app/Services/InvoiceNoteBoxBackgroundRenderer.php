<?php

namespace App\Services;

final class InvoiceNoteBoxBackgroundRenderer
{
    private static ?string $cachedDataUri = null;

    public function dataUri(): string
    {
        if (self::$cachedDataUri !== null) {
            return self::$cachedDataUri;
        }

        $image = imagecreatetruecolor(480, 180);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 255, 255, 255, 127));
        imagealphablending($image, true);

        $stroke = imagecolorallocate($image, 228, 199, 90);
        $fill = imagecolorallocate($image, 255, 244, 191);
        $this->fillRoundedRectangle($image, 1, 1, 478, 178, 40, $stroke);
        $this->fillRoundedRectangle($image, 3, 3, 476, 176, 38, $fill);

        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return self::$cachedDataUri = 'data:image/png;base64,'.base64_encode($png);
    }

    private function fillRoundedRectangle(\GdImage $image, int $left, int $top, int $right, int $bottom, int $radius, int $color): void
    {
        imagefilledrectangle($image, $left + $radius, $top, $right - $radius, $bottom, $color);
        imagefilledrectangle($image, $left, $top + $radius, $right, $bottom - $radius, $color);
        imagefilledellipse($image, $left + $radius, $top + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $right - $radius, $top + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $left + $radius, $bottom - $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $right - $radius, $bottom - $radius, $radius * 2, $radius * 2, $color);
    }
}
