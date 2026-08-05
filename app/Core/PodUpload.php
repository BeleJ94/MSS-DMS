<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class PodUpload
{
    public static function signature(string $dataUrl): array
    {
        if (!preg_match('#^data:image/(?:jpeg|png);base64,([A-Za-z0-9+/=\r\n]+)$#', $dataUrl, $matches)) {
            throw new RuntimeException('La signature est obligatoire.');
        }
        $data = base64_decode($matches[1], true);
        if ($data === false || strlen($data) < 300 || strlen($data) > 2 * 1024 * 1024) {
            throw new RuntimeException('La signature tactile est invalide.');
        }
        return self::jpeg($data, 1000, 420, 88, true);
    }

    public static function photo(?array $file, bool $required, string $label): ?array
    {
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            if ($required) { throw new RuntimeException($label.' est obligatoire.'); }
            return null;
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Le transfert de '.$label.' a échoué.');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size < 1 || $size > 2 * 1024 * 1024) {
            throw new RuntimeException($label.' doit peser moins de 2 Mo.');
        }
        $temporary = (string) ($file['tmp_name'] ?? '');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($temporary);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new RuntimeException($label.' doit être au format JPG, PNG ou WebP.');
        }
        $data = file_get_contents($temporary);
        if ($data === false) { throw new RuntimeException('Impossible de lire '.$label.'.'); }
        return self::jpeg($data, 1600, 1600, 82, false);
    }

    private static function jpeg(string $data, int $maxWidth, int $maxHeight, int $quality, bool $whiteBackground): array
    {
        $source = @imagecreatefromstring($data);
        if ($source === false) { throw new RuntimeException('Une image de la preuve est illisible.'); }
        $width = imagesx($source); $height = imagesy($source);
        if ($width < 20 || $height < 20) { imagedestroy($source); throw new RuntimeException('Une image de la preuve est trop petite.'); }
        $ratio = min(1, $maxWidth / $width, $maxHeight / $height);
        $targetWidth = max(1, (int) round($width * $ratio));
        $targetHeight = max(1, (int) round($height * $ratio));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        $background = imagecolorallocate($target, $whiteBackground ? 255 : 248, $whiteBackground ? 255 : 248, $whiteBackground ? 255 : 248);
        imagefill($target, 0, 0, $background);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        ob_start(); imagejpeg($target, null, $quality); $jpeg = (string) ob_get_clean();
        imagedestroy($source); imagedestroy($target);
        return ['mime' => 'image/jpeg', 'data' => $jpeg, 'width' => $targetWidth, 'height' => $targetHeight];
    }
}
