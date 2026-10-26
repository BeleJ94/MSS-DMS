<?php

declare(strict_types=1);

// Isolated configuration and log files; no database and no real e-mail.
$root=dirname(__DIR__);
$temporary=sys_get_temp_dir().'/mss-mail-'.bin2hex(random_bytes(8));
mkdir($temporary.'/storage/logs',0700,true);
define('BASE_PATH',$temporary);
require $root.'/app/Core/Env.php';
require $root.'/app/Core/MailSettingsValidationException.php';
require $root.'/app/Core/MailSettings.php';
require $root.'/app/Core/Mailer.php';

use App\Core\Env;
use App\Core\Mailer;
use App\Core\MailSettings;

$checks=0;
function checkMail(bool $condition,string $label):void{global $checks;if(!$condition){throw new RuntimeException($label);} $checks++;echo 'OK - '.$label.PHP_EOL;}
function invalidMail(array $values,string $label):void{try{MailSettings::validate($values,[]);}catch(RuntimeException $e){checkMail(true,$label);return;}throw new RuntimeException($label);}

$environment=[];
foreach(MailSettings::DEFAULTS as $key=>$default){$environment[$key]=getenv($key);putenv($key);}
try{
    $input=MailSettings::DEFAULTS;
    $input['MAIL_FROM_ADDRESS']='notifications@example.com';
    $input['MAIL_HOST']='smtp.example.com';
    $input['MAIL_PASSWORD']=' secret"$#=word\' ';
    $values=MailSettings::validate($input,[]);
    $path=$temporary.'/.env';
    file_put_contents($path,"# Keep database configuration\nDB_DATABASE=existing_database\nMAIL_HOST=old.example.com\nMAIL_HOST=duplicate.example.com\n");
    MailSettings::save($values,$path);
    $saved=file_get_contents($path);
    checkMail(strpos($saved,'DB_DATABASE=existing_database')!==false,'les paramètres existants sont préservés');
    checkMail(substr_count($saved,'MAIL_HOST=')===1,'les anciennes clés mail sont remplacées sans doublons');
    checkMail(Env::get('MAIL_PASSWORD')===$input['MAIL_PASSWORD'],'le mot de passe conserve espaces et caractères spéciaux');
    checkMail(MailSettings::current()['MAIL_HOST']==='smtp.example.com','les nouveaux paramètres sont lus par le service mail');
    $input['MAIL_PASSWORD']='';
    checkMail(MailSettings::validate($input,$values)['MAIL_PASSWORD']===$values['MAIL_PASSWORD'],'un mot de passe vide conserve le secret');
    $input['clear_password']='1';
    checkMail(MailSettings::validate($input,$values)['MAIL_PASSWORD']==='','la suppression explicite du secret est possible');
    unset($input['clear_password']);
    invalidMail(array_replace($input,['MAIL_HOST'=>"smtp.example.com\nDB_PASSWORD=bad"]),'les injections de configuration sont rejetées');
    invalidMail(array_replace($input,['MAIL_PORT'=>'65536']),'les ports invalides sont rejetés');
    invalidMail(array_replace($input,['MAIL_FROM_ADDRESS'=>'invalid']),'les expéditeurs invalides sont rejetés');
    invalidMail(array_replace($input,['MAIL_DRIVER'=>'smtp','MAIL_HOST'=>'tcp://smtp.example.com:25']),'les URL SMTP invalides sont rejetées');
    invalidMail(array_replace($input,['MAIL_DRIVER'=>['smtp']]),'les paramètres de type tableau sont rejetés');
    putenv('MAIL_HOST=managed.example.com');
    try{MailSettings::save($values,$path);throw new LogicException('Une variable serveur ne doit pas être écrasée.');}catch(RuntimeException $e){checkMail(file_get_contents($path)===$saved,'les variables imposées par le serveur ne sont pas écrasées');}
    putenv('MAIL_HOST');
    Mailer::send('test@example.com','','Configuration de test','Test sans envoi.',[],array_replace($values,['MAIL_FROM_ADDRESS'=>'test-sender@example.com']));
    $log=file_get_contents($temporary.'/storage/logs/mail.log');
    checkMail(strpos($log,'<test-sender@example.com>')!==false,'le test utilise les paramètres saisis sans les enregistrer');
    checkMail(file_get_contents($path)===$saved,'un test ne modifie pas la configuration enregistrée');
    checkMail(strpos($log,$values['MAIL_PASSWORD'])===false,'le mot de passe ne figure pas dans le journal mail');
    echo 'MAIL_SETTINGS_OK: '.$checks.' checks'.PHP_EOL;
}finally{
    foreach($environment as $key=>$value){putenv($value===false?$key:$key.'='.$value);}
    foreach([$temporary.'/storage/logs/mail.log',$temporary.'/.env',$temporary.'/.env.lock'] as $file){if(is_file($file)){unlink($file);}}
    rmdir($temporary.'/storage/logs');rmdir($temporary.'/storage');rmdir($temporary);
}
