<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class PhotoUpload
{
    public static function store(?array $file): ?array
    {
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { return null; }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) { throw new RuntimeException('Le transfert de la photo a échoué.'); }
        if ((int)($file['size'] ?? 0) > 3 * 1024 * 1024) { throw new RuntimeException('La photo ne doit pas dépasser 3 Mo.'); }
        $temporary = (string)($file['tmp_name'] ?? '');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($temporary);
        if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) { throw new RuntimeException('Format photo invalide. Utilisez JPG, PNG ou WebP.'); }
        $data = file_get_contents($temporary);
        if ($data === false) { throw new RuntimeException('Impossible de lire la photo.'); }
        return ['mime' => $mime, 'data' => $data];
    }
}
