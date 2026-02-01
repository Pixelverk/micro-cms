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

// ----------------------------
// Get POST data
// ----------------------------
$replaceId   = !empty($_POST['replace_id']) ? (int)$_POST['replace_id'] : null;
$altText     = trim($_POST['alt_text'] ?? '');
$description = trim($_POST['description'] ?? '');
$file        = $_FILES['file'] ?? null;
$hasNewUpload = $file && $file['error'] === UPLOAD_ERR_OK;

// ----------------------------
// Config
// ----------------------------
$maxSize = 10 * 1024 * 1024; // 10MB
$allowedExtensions = ['jpg','jpeg','png','gif','webp','svg','pdf','mp4','webm'];
$imageWidths = [400, 800, 1200, 2000]; // widths to generate

// ----------------------------
// Helpers
// ----------------------------
function sanitizeFilename(string $name): string {
    return preg_replace('/[^a-zA-Z0-9_\-]/', '-', strtolower($name));
}

function save_resized_image(string $sourcePath, string $destPath, int $targetWidth, string $format): array {
    $img = new Imagick();

    try {
        $img->readImage($sourcePath);
        $img->stripImage();
        if (method_exists($img, 'autoOrient')) $img->autoOrient();

        $origWidth = $img->getImageWidth();
        $origHeight = $img->getImageHeight();

        $width = min($targetWidth, $origWidth);
        $img->thumbnailImage($width, 0); // keep aspect ratio

        // Format-specific settings
        switch ($format) {
            case 'webp': $img->setImageCompressionQuality(80); break;
            case 'png':  $img->setImageCompressionQuality(9); break;
            default:     $img->setImageCompressionQuality(80); break;
        }

        $img->setImageFormat($format);

        $dir = dirname($destPath);
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $img->writeImage($destPath);

        $result = [
            'path' => $destPath,
            'width'=> $img->getImageWidth(),
            'height'=> $img->getImageHeight(),
            'size'=> filesize($destPath)
        ];

        //error_log("RESIZE SUCCESS: {$sourcePath} -> {$destPath} | {$result['width']}x{$result['height']}");

    } catch (ImagickException $e) {
        error_log("RESIZE FAILED: {$sourcePath} | width {$targetWidth} | format {$format} | " . $e->getMessage());
        throw $e;
    }

    $img->clear();
    $img->destroy();
    return $result;
}

function generate_lqip(string $sourcePath, int $width = 20): string {
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
        return '';
    }
}

// ----------------------------
// Handle file upload
// ----------------------------
$relativeBasePath = $originalName = $mimeType = null;
$originalSize = $width = $height = null;
$formats = [];
$sizes = [];
$lqip = null;

if ($hasNewUpload) {
    if ($file['error'] !== UPLOAD_ERR_OK) redirect_with_toast('media', 'error', 'Upload error.');
    if ($file['size'] > $maxSize) redirect_with_toast('media', 'error', 'File too large.');

    $originalName = $file['name'];
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($extension, $allowedExtensions, true)) redirect_with_toast('media', 'error', 'Invalid file type.');

    // ----------------------------
    // Build YYYY/MM/unique folder
    // ----------------------------
    $year  = date('Y');
    $month = date('m');
    $uniqueFolder = bin2hex(random_bytes(6));
    $targetDir = STORAGE_PATH . "/media/{$year}/{$month}/{$uniqueFolder}";
    if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);
    $relativeBasePath = "{$year}/{$month}/{$uniqueFolder}";

    // ----------------------------
    // Move original file
    // ----------------------------
    $baseName = sanitizeFilename(pathinfo($originalName, PATHINFO_FILENAME));
    $targetOriginal = "{$targetDir}/original.{$extension}";
    if (!move_uploaded_file($file['tmp_name'], $targetOriginal)) redirect_with_toast('media', 'error', 'Failed to move uploaded file.');

    $mimeType = mime_content_type($targetOriginal);
    $originalSize = filesize($targetOriginal);
    //error_log("UPLOAD SUCCESS: {$targetOriginal} | MIME {$mimeType} | size {$originalSize}");

    // ----------------------------
    // Preprocess large images to a manageable size
    // ----------------------------
    if (str_starts_with($mimeType, 'image/')) {
        $img = new Imagick($targetOriginal);

        if (method_exists($img, 'autoOrient')) {
            $img->autoOrient();
            //error_log("AUTO-ORIENT applied to {$originalName}");
        }

        // CMYK -> RGB
        if ($img->getImageColorspace() === Imagick::COLORSPACE_CMYK) {
            $img->transformImageColorspace(Imagick::COLORSPACE_RGB);
            //error_log("Converted {$originalName} from CMYK to RGB");
        }

        // Resize original if too large
        $origWidth = $img->getImageWidth();
        $origHeight = $img->getImageHeight();
        $maxOrigWidth = 2000; // maximum manageable width
        if ($origWidth > $maxOrigWidth) {
            $img->thumbnailImage($maxOrigWidth, 0);
            $processedOriginal = "{$targetOriginal}.proc.jpg";
            $img->setImageFormat('jpeg');
            $img->writeImage($processedOriginal);
            //error_log("RESIZED ORIGINAL for processing: {$origWidth}x{$origHeight} → " . $img->getImageWidth() . "x" . $img->getImageHeight());
        } else {
            $processedOriginal = $targetOriginal;
        }

        $width = $img->getImageWidth();
        $height = $img->getImageHeight();

        $img->clear();
        $img->destroy();

        $formats = ['webp'=>[], $extension=>[]];
        $sizes = [];

        foreach ($imageWidths as $w) {
            foreach (['webp',$extension] as $fmt) {
                if ($w > $width) continue; // skip larger than processed original

                $dest = "{$targetDir}/{$w}.{$fmt}";
                try {
                    $resized = save_resized_image($processedOriginal, $dest, $w, $fmt);
                    $formats[$fmt][] = "{$relativeBasePath}/{$w}.{$fmt}";

                    if (!isset($sizes[$w])) {
                        $sizes[$w] = [
                            'width' => $resized['width'],
                            'height'=> $resized['height']
                        ];
                    }
                } catch (Exception $e) {
                    error_log("RESIZE FAILED: {$processedOriginal} | width {$w} | format {$fmt} | " . $e->getMessage());
                }
            }
        }

        $lqip = generate_lqip($processedOriginal);
    }
}

// ----------------------------
// Insert / Update DB
// ----------------------------
if ($replaceId) {

    $stmt = $pdo->prepare("SELECT * FROM media WHERE id = ?");
    $stmt->execute([$replaceId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        redirect_with_toast('media', 'error', 'Media item not found.');
    }

    // ----------------------------
    // If NO new upload → keep old file data
    // ----------------------------
    if (!$hasNewUpload) {
        $originalName = $existing['original_name'];
        $relativeBasePath = $existing['base_path'];
        $mimeType = $existing['mime_type'];
        $originalSize = $existing['original_size'];
        $width = $existing['width'];
        $height = $existing['height'];
        $formats = json_decode($existing['formats_json'], true) ?? [];
        $lqip = $existing['lqip_base64'];
    }
    // ----------------------------
    // If replacing file → delete old folder
    // ----------------------------
    else {
        $oldFolder = realpath(STORAGE_PATH . '/media/' . $existing['base_path']);
        if ($oldFolder && is_dir($oldFolder)) {
            $it = new RecursiveDirectoryIterator($oldFolder, RecursiveDirectoryIterator::SKIP_DOTS);
            $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);

            foreach ($files as $fileObj) {
                $path = $fileObj->getPathname();

                if ($fileObj->isDir()) {
                    rmdir($path);
                } else {
                    unlink($path);
                }
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