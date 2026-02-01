<?php
declare(strict_types=1);

// ----------------------------
// Only allow POST
// ----------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

$pdo = db();
$now = time();
$settings = load_settings();

// ----------------------------
// Get POST data
// ----------------------------
$replaceId    = !empty($_POST['replace_id']) ? (int)$_POST['replace_id'] : null;
$altText      = trim($_POST['alt_text'] ?? '');
$description  = trim($_POST['description'] ?? '');
$file         = $_FILES['file'] ?? null;
$hasNewUpload = $file && $file['error'] === UPLOAD_ERR_OK;

// ----------------------------
// Config from settings
// ----------------------------
$maxSize = 10 * 1024 * 1024; // 10MB
$allowedExtensions = ['jpg','jpeg','png','gif','webp','svg','pdf','mp4','webm'];

$imageQuality = (int)($settings['image_quality'] ?? 80);
$stripMeta    = (bool)($settings['strip_metadata'] ?? true);
$generateWebp = (bool)($settings['generate_webp'] ?? true);

$defaultWidths = [320, 640, 1280];
$sizesSetting = $settings['media_sizes'] ?? null;
if (is_string($sizesSetting) && trim($sizesSetting) !== '') {
     $imageWidths = array_map('intval', array_filter(array_map('trim', explode(',', $sizesSetting))));
} elseif (is_array($sizesSetting) && !empty($sizesSetting)) {
    $imageWidths = array_map('intval', $sizesSetting);
} else {
    $imageWidths = $defaultWidths;
}

// ----------------------------
// Helpers
// ----------------------------
function sanitizeFilename(string $name): string {
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

// ----------------------------
// Initialize variables
// ----------------------------
$relativeBasePath = $originalName = $mimeType = null;
$originalSize = $width = $height = null;
$formats = [];
$sizes = [];
$lqip = null;

// ----------------------------
// Handle file upload
// ----------------------------
if ($hasNewUpload) {
    if ($file['error'] !== UPLOAD_ERR_OK) redirect_with_toast('media', 'error', 'Upload error.');
    if ($file['size'] > $maxSize) redirect_with_toast('media', 'error', 'File too large.');

    $originalName = $file['name'];
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($extension, $allowedExtensions, true)) redirect_with_toast('media', 'error', 'Invalid file type.');

    // Build YYYY/MM/unique folder
    $year  = date('Y');
    $month = date('m');
    $uniqueFolder = bin2hex(random_bytes(6));
    $targetDir = STORAGE_PATH . "/media/{$year}/{$month}/{$uniqueFolder}";
    if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);
    $relativeBasePath = "{$year}/{$month}/{$uniqueFolder}";

    // Move original file
    $baseName = sanitizeFilename(pathinfo($originalName, PATHINFO_FILENAME));
    $targetOriginal = "{$targetDir}/original.{$extension}";
    if (!move_uploaded_file($file['tmp_name'], $targetOriginal)) redirect_with_toast('media', 'error', 'Failed to move uploaded file.');

    $mimeType = mime_content_type($targetOriginal);
    $originalSize = filesize($targetOriginal);

    // Determine if image
    $isImage = str_starts_with($mimeType, 'image/');

    if ($isImage) {
        $img = new Imagick($targetOriginal);
        if (method_exists($img, 'autoOrient')) $img->autoOrient();

        // CMYK → RGB
        if ($img->getImageColorspace() === Imagick::COLORSPACE_CMYK) {
            $img->transformImageColorspace(Imagick::COLORSPACE_RGB);
        }

        $origWidth = $img->getImageWidth();
        $origHeight = $img->getImageHeight();
        $maxOrigWidth = max($imageWidths);
        if ($origWidth > $maxOrigWidth) {
            $img->thumbnailImage($maxOrigWidth, 0);
            $processedOriginal = "{$targetOriginal}.proc.jpg";
            $img->setImageFormat('jpeg');
            $img->writeImage($processedOriginal);
        } else {
            $processedOriginal = $targetOriginal;
        }

        $width = $img->getImageWidth();
        $height = $img->getImageHeight();
        $img->clear();
        $img->destroy();

        $formats = [];
        $sizes = [];
        $formatsToGenerate = $generateWebp ? ['webp', $extension] : [$extension];

        foreach ($formatsToGenerate as $fmt) {
            // Always create at least one file for this format
            $dest = "{$targetDir}/original.{$fmt}";
            try {
                $resized = save_resized_image($targetOriginal, $dest, $width, $fmt, $imageQuality, $stripMeta);
                $formats[$fmt][] = "{$relativeBasePath}/original.{$fmt}";
                $sizes[$width] = ['width' => $resized['width'], 'height' => $resized['height']];
            } catch (Exception $e) {
                error_log("FORMAT FAILED: {$targetOriginal} | format {$fmt} | " . $e->getMessage());
            }

            // Then also generate additional widths if larger than 1px
            foreach ($imageWidths as $w) {
                if ($w >= $width) continue; // skip sizes larger than original (optional)
                $destW = "{$targetDir}/{$w}.{$fmt}";
                try {
                    $resized = save_resized_image($targetOriginal, $destW, $w, $fmt, $imageQuality, $stripMeta);
                    $formats[$fmt][] = "{$relativeBasePath}/{$w}.{$fmt}";
                    $sizes[$w] = ['width' => $resized['width'], 'height' => $resized['height']];
                } catch (Exception $e) {
                    error_log("RESIZE FAILED: {$targetOriginal} | width {$w} | format {$fmt} | " . $e->getMessage());
                }
            }
        }

        $lqip = generate_lqip($processedOriginal);
    } else {
        // Non-images: just store original
        $formats = [$extension => ["{$relativeBasePath}/original.{$extension}"]];
        $sizes = [];
        $lqip = null;
        $width = 0;
        $height = 0;
    }
}

// ----------------------------
// Insert / Update DB
// ----------------------------
if ($replaceId) {
    $stmt = $pdo->prepare("SELECT * FROM media WHERE id = ?");
    $stmt->execute([$replaceId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing) redirect_with_toast('media', 'error', 'Media item not found.');

    if (!$hasNewUpload) {
        // Keep old file data
        $originalName = $existing['original_name'];
        $relativeBasePath = $existing['base_path'];
        $mimeType = $existing['mime_type'];
        $originalSize = $existing['original_size'];
        $width = $existing['width'];
        $height = $existing['height'];
        $formats = json_decode($existing['formats_json'], true) ?? [];
        $lqip = $existing['lqip_base64'];
    } else {
        // Delete old folder safely
        $oldFolder = realpath(STORAGE_PATH . '/media/' . $existing['base_path']);
        if ($oldFolder && is_dir($oldFolder)) {
            $it = new RecursiveDirectoryIterator($oldFolder, RecursiveDirectoryIterator::SKIP_DOTS);
            $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $fileObj) {
                $fileObj->isDir() ? rmdir($fileObj->getPathname()) : unlink($fileObj->getPathname());
            }
            rmdir($oldFolder);
        }
    }

    $stmt = $pdo->prepare("
        UPDATE media SET
            original_name = ?,
            base_path     = ?,
            mime_type     = ?,
            original_size = ?,
            width         = ?,
            height        = ?,
            formats_json  = ?,
            lqip_base64   = ?,
            alt_text      = ?,
            description   = ?,
            updated_at    = ?
        WHERE id = ?
    ");

    $stmt->execute([
        $originalName,
        $relativeBasePath,
        $mimeType,
        $originalSize,
        $width,
        $height,
        json_encode($formats, JSON_UNESCAPED_SLASHES),
        $lqip,
        $altText,
        $description,
        $now,
        $replaceId
    ]);

    $msg = 'Media updated successfully.';
} else {
    $stmt = $pdo->prepare("
        INSERT INTO media (
            original_name,
            base_path,
            mime_type,
            original_size,
            width,
            height,
            formats_json,
            sizes_json,
            lqip_base64,
            alt_text,
            description,
            created_at,
            updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $originalName,
        $relativeBasePath,
        $mimeType,
        $originalSize,
        $width,
        $height,
        json_encode($formats, JSON_UNESCAPED_SLASHES),
        json_encode($sizes, JSON_UNESCAPED_SLASHES),
        $lqip,
        $altText,
        $description,
        $now,
        $now
    ]);

    $msg = 'File uploaded successfully.';
}

// ----------------------------
// Done
// ----------------------------
redirect_with_toast('media', 'success', $msg);