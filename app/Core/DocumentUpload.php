<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class DocumentUpload
{
    public static function read(?array $file): array
    {
        if($file===null||($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE){throw new RuntimeException('Sélectionnez un document.');}
        if(($file['error']??UPLOAD_ERR_OK)!==UPLOAD_ERR_OK){throw new RuntimeException('Le transfert du document a échoué.');}
        $size=(int)($file['size']??0);if($size<1||$size>5*1024*1024){throw new RuntimeException('Le document doit peser moins de 5 Mo.');}
        $temporary=(string)($file['tmp_name']??'');$mime=(new \finfo(FILEINFO_MIME_TYPE))->file($temporary);$allowed=['application/pdf','image/jpeg','image/png','image/webp'];if(!in_array($mime,$allowed,true)){throw new RuntimeException('Format invalide. Utilisez PDF, JPG, PNG ou WebP.');}
        $data=file_get_contents($temporary);if($data===false){throw new RuntimeException('Impossible de lire le document.');}
        $name=preg_replace('/[^a-zA-Z0-9._-]+/','-',basename((string)($file['name']??'document')));return ['name'=>$name?:'document','mime'=>$mime,'size'=>$size,'data'=>$data];
    }
}

