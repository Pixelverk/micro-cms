<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Zip
|--------------------------------------------------------------------------
|
| One place that writes a zip, so the static export and the backup work
| whether or not the optional zip extension is installed. ZipArchive is
| preferred when present; PharData (ext-phar, enabled by default) is the
| fallback and writes the same deflated archive.
|
*/

function zip_available(): bool
{
    return class_exists('ZipArchive') || class_exists('PharData');
}

/**
 * Write entries to a zip archive, replacing any existing file.
 *
 * @param list<array{name: string, source?: string, content?: string}> $entries
 */
function zip_write(string $archive, array $entries): void
{
    if (!zip_available()) {
        throw new RuntimeException('Creating a zip needs the PHP zip or phar extension, and neither is available.');
    }

    @unlink($archive);

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();

        if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the archive.');
        }

        foreach ($entries as $entry) {
            if (isset($entry['source'])) {
                $zip->addFile($entry['source'], $entry['name']);
            } else {
                $zip->addFromString($entry['name'], (string) ($entry['content'] ?? ''));
            }
        }

        $zip->close();

        return;
    }

    $phar = new PharData($archive);

    foreach ($entries as $entry) {
        if (isset($entry['source'])) {
            $phar->addFile($entry['source'], $entry['name']);
        } else {
            $phar->addFromString($entry['name'], (string) ($entry['content'] ?? ''));
        }
    }

    // PharData stores entries uncompressed unless it is asked to deflate them.
    if ($entries) {
        $phar->compressFiles(Phar::GZ);
    }

    unset($phar);
}
