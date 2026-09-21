<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Media upload processing
|--------------------------------------------------------------------------
| Filename slugs, resized variants and the LQIP placeholder. The admin upload
| and replace handler posts files; the Imagick work lives here so the handler
| is request handling rather than image processing.
*/

function sanitize_filename(string $name): string {
    return preg_replace('/[^a-zA-Z0-9_\-]/', '-', strtolower($name));
}

function save_resized_image(string $sourcePath, string $destPath, int $targetWidth, string $format, int $quality = 80, bool $stripMeta = true): array {
    $img = new Imagick();
    try {
        $img->readImage($sourcePath);
        if ($stripMeta) $img->stripImage();
        if (method_exists($img, 'autoOrient')) $img->autoOrient();

        $origWidth = $img->getImageWidth();
        $origHeight = $img->getImageHeight();
        $width = min($targetWidth, $origWidth);
        $img->thumbnailImage($width, 0);

        switch ($format) {
            case 'webp':
            case 'jpeg':
            case 'jpg':
                $img->setImageCompressionQuality($quality);
                break;
            case 'png':
                $img->setImageCompressionQuality(9);
                break;
        }

        $img->setImageFormat($format);

        $dir = dirname($destPath);
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $img->writeImage($destPath);

        return [
            'path'   => $destPath,
            'width'  => $img->getImageWidth(),
            'height' => $img->getImageHeight(),
            'size'   => filesize($destPath)
        ];
    } finally {
        $img->clear();
        $img->destroy();
    }
}

function generate_lqip(string $sourcePath, int $width = 20): ?string {
    try {
        $img = new Imagick($sourcePath);
        $img->stripImage();
        if (method_exists($img, 'autoOrient')) $img->autoOrient();
        $img->thumbnailImage($width, 0);
        $img->setImageFormat('jpeg');
        $data = $img->getImageBlob();
        $img->clear();
        $img->destroy();
        return 'data:image/jpeg;base64,' . base64_encode($data);
    } catch (Exception $e) {
        error_log("LQIP FAILED: {$sourcePath} | error: " . $e->getMessage());
        return null;
    }
}
