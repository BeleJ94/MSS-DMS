<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class EmailRecipients
{
    public static function parse($value): array
    {
        if($value===null || $value===''){return [];}
        if(!is_string($value) || strlen($value)>6000){throw new RuntimeException('La liste des destinataires doit contenir au maximum 20 adresses e-mail.');}
        $emails=[];
        foreach(preg_split('/[;,\r\n]+/',$value) as $email){
            $email=mb_strtolower(trim($email));if($email===''){continue;}
            if(strlen($email)>190 || !filter_var($email,FILTER_VALIDATE_EMAIL)){throw new RuntimeException('Adresse e-mail invalide : « '.$email.' ». Séparez les adresses par une virgule, un point-virgule ou un retour à la ligne.');}
            $emails[$email]=$email;
        }
        if(count($emails)>20){throw new RuntimeException('Vous pouvez renseigner au maximum 20 adresses e-mail par destination.');}
        return array_values($emails);
    }

    public static function normalize($value): ?string
    {
        $emails=self::parse($value);return $emails?implode("\n",$emails):null;
    }
}
