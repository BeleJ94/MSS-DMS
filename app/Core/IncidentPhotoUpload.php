<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class IncidentPhotoUpload
{
    public static function many(?array $files): array
    {
        if (!$files || !isset($files['name'])) { return []; }
        $names = is_array($files['name']) ? $files['name'] : [$files['name']];
        if (count($names) > 3) { throw new RuntimeException('Ajoutez au maximum 3 photos.'); }
        $result = [];
        foreach ($names as $index => $name) {
            $file = [
                'name' => $name,
                'type' => is_array($files['type']) ? ($files['type'][$index] ?? '') : ($files['type'] ?? ''),
                'tmp_name' => is_array($files['tmp_name']) ? ($files['tmp_name'][$index] ?? '') : ($files['tmp_name'] ?? ''),
                'error' => is_array($files['error']) ? ($files['error'][$index] ?? UPLOAD_ERR_NO_FILE) : ($files['error'] ?? UPLOAD_ERR_NO_FILE),
                'size' => is_array($files['size']) ? ($files['size'][$index] ?? 0) : ($files['size'] ?? 0),
            ];
            $photo = PodUpload::photo($file, false, 'La photo de l’incident');
            if ($photo) { $result[] = $photo; }
        }
        return $result;
    }
}
