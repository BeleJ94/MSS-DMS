<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class MailSettings
{
    public const DEFAULTS = [
        'MAIL_DRIVER'=>'log', 'MAIL_HOST'=>'', 'MAIL_PORT'=>'587', 'MAIL_ENCRYPTION'=>'tls',
        'MAIL_USERNAME'=>'', 'MAIL_PASSWORD'=>'', 'MAIL_FROM_ADDRESS'=>'', 'MAIL_FROM_NAME'=>'MSS-DMS',
    ];

    public static function current(): array
    {
        $values=[];
        foreach(self::DEFAULTS as $key=>$default){$values[$key]=(string)Env::get($key,$default);}
        return $values;
    }

    public static function validate(array $input, array $current): array
    {
        $labels=['MAIL_DRIVER'=>'Mode d’envoi','MAIL_HOST'=>'Serveur SMTP','MAIL_PORT'=>'Port','MAIL_ENCRYPTION'=>'Chiffrement','MAIL_USERNAME'=>'Identifiant SMTP','MAIL_PASSWORD'=>'Mot de passe SMTP','MAIL_FROM_ADDRESS'=>'Adresse de l’expéditeur','MAIL_FROM_NAME'=>'Nom de l’expéditeur'];
        $values=[];
        foreach(self::DEFAULTS as $key=>$default){
            if(isset($input[$key]) && !is_scalar($input[$key])){throw new MailSettingsValidationException($key,'Le champ « '.$labels[$key].' » doit contenir une valeur simple. Rechargez la page et recommencez.');}
            $values[$key]=(string)($input[$key]??$default);
            if($key!=='MAIL_PASSWORD'){$values[$key]=trim($values[$key]);}
            if(preg_match('/[\x00-\x1F\x7F]/',$values[$key]) || strlen($values[$key])>1000){throw new MailSettingsValidationException($key,'Le champ « '.$labels[$key].' » contient un caractère de contrôle ou dépasse 1 000 caractères. Supprimez les retours à la ligne et vérifiez la valeur copiée.');}
        }
        if($values['MAIL_PASSWORD']===''){$values['MAIL_PASSWORD']=$current['MAIL_PASSWORD']??'';}
        if(($input['clear_password']??'')==='1'){$values['MAIL_PASSWORD']='';}
        if(!in_array($values['MAIL_DRIVER'],['smtp','log'],true)){throw new MailSettingsValidationException('MAIL_DRIVER','Choisissez SMTP pour envoyer des e-mails ou Journal pour simuler les envois.');}
        if(!in_array($values['MAIL_ENCRYPTION'],['tls','ssl','none'],true)){throw new MailSettingsValidationException('MAIL_ENCRYPTION','Choisissez STARTTLS, SSL/TLS ou Sans chiffrement selon les instructions de votre fournisseur mail.');}
        if(!ctype_digit($values['MAIL_PORT']) || (int)$values['MAIL_PORT']<1 || (int)$values['MAIL_PORT']>65535){throw new MailSettingsValidationException('MAIL_PORT','Le port SMTP doit être un nombre entre 1 et 65535. Les valeurs habituelles sont 587 avec STARTTLS ou 465 avec SSL/TLS.');}
        if(!filter_var($values['MAIL_FROM_ADDRESS'],FILTER_VALIDATE_EMAIL)){throw new MailSettingsValidationException('MAIL_FROM_ADDRESS','L’adresse de l’expéditeur est absente ou invalide. Saisissez une adresse complète, par exemple notifications@votre-domaine.com.');}
        if($values['MAIL_DRIVER']==='smtp' && !preg_match('/\A[a-zA-Z0-9](?:[a-zA-Z0-9.-]*[a-zA-Z0-9])?\z/',$values['MAIL_HOST'])){throw new MailSettingsValidationException('MAIL_HOST','Le serveur SMTP est absent ou invalide. Saisissez uniquement son nom (exemple : smtp.votre-domaine.com), sans https://, espace ni numéro de port.');}
        return $values;
    }

    public static function save(array $values, ?string $path=null): void
    {
        $path=$path??BASE_PATH.'/.env';
        foreach(self::DEFAULTS as $key=>$default){
            if(getenv($key)!==false){throw new RuntimeException('Le serveur impose '.$key.'. Modifiez cette variable côté hébergement avant d’utiliser cet écran.');}
        }
        $lock=@fopen($path.'.lock','c');
        if(!$lock){throw new RuntimeException('Enregistrement impossible : PHP ne peut pas créer ou ouvrir le fichier .env.lock. Demandez à l’hébergeur de donner au compte exécutant PHP les droits d’écriture sur le dossier de l’application.');}
        $temporary=null;
        try{
            if(!flock($lock,LOCK_EX)){throw new RuntimeException('La configuration est occupée. Réessayez.');}
            $contents=is_file($path)?@file_get_contents($path):'';
            if($contents===false){throw new RuntimeException('Le fichier .env ne peut pas être lu. Vérifiez ses droits de lecture pour le compte exécutant PHP. Aucun paramètre n’a été modifié.');}
            $lines=preg_split('/\r\n|\n|\r/',$contents);
            $lines=array_filter($lines,function(string $line):bool{
                return !preg_match('/^\s*(MAIL_DRIVER|MAIL_HOST|MAIL_PORT|MAIL_ENCRYPTION|MAIL_USERNAME|MAIL_PASSWORD|MAIL_FROM_ADDRESS|MAIL_FROM_NAME)\s*=/',$line);
            });
            $contents=rtrim(implode("\n",$lines))."\n\n# Configuration mail administrée depuis MSS-DMS\n";
            foreach(self::DEFAULTS as $key=>$default){$contents.=$key.'="'.$values[$key].'"'."\n";}
            $temporary=@tempnam(dirname($path),'.mail-settings-');
            if($temporary===false){throw new RuntimeException('Impossible de préparer la configuration.');}
            if(!chmod($temporary,0600)){throw new RuntimeException('Impossible de protéger la configuration.');}
            if(@file_put_contents($temporary,$contents)!==strlen($contents) || !@rename($temporary,$path)){throw new RuntimeException('Enregistrement impossible : le fichier .env ne peut pas être remplacé. Vérifiez les droits d’écriture de PHP sur ce fichier et son dossier, ainsi que l’espace disque disponible. La configuration précédente est conservée.');}
            $temporary=null;
            Env::load($path);
        } finally {
            if($temporary!==null && is_file($temporary)){unlink($temporary);}
            flock($lock,LOCK_UN);fclose($lock);
        }
    }
}
