<?php

declare(strict_types=1);

ini_set('session.save_path',sys_get_temp_dir());
require dirname(__DIR__).'/bootstrap/app.php';

use App\Core\Mailer;
use App\Core\NotificationEmail;

$checks=0;
function checkEmail(bool $condition,string $label):void{global $checks;if(!$condition){throw new RuntimeException($label);}$checks++;echo 'OK - '.$label.PHP_EOL;}

// Decode the actual generated MIME tree, including nested alternatives and binary attachments.
function mimeLeaves(string $message):array{
    [$headers,$body]=explode("\r\n\r\n",$message,2);
    if(preg_match('/Content-Type: multipart\/[a-z]+; boundary="([^"]+)"/i',$headers,$match)){
        $leaves=[];
        foreach(array_slice(explode('--'.$match[1],$body),1) as $part){
            if(strpos($part,'--')===0){break;}
            $leaves=array_merge($leaves,mimeLeaves(ltrim($part,"\r\n")));
        }
        return $leaves;
    }
    checkEmail(strpos($headers,'Content-Transfer-Encoding: base64')!==false,'la partie MIME utilise un encodage explicite');
    $decoded=base64_decode(preg_replace('/\s+/','',$body),true);
    checkEmail($decoded!==false,'la partie MIME se décode sans perte');
    return [['headers'=>$headers,'body'=>$decoded]];
}

$subject='Livraison LIV-2026-0042 effectuée';
$text="Bonjour Direction,\n\nVotre livraison a été effectuée.\n\nLieu : Entrepôt central\nRéceptionnée par : Élodie\n\nRéserves constatées à la livraison :\n  - Colis : Partielle (8 / 10 colis)\n\nLe bon de livraison est joint à ce message.\n\nCordialement,\nMSS-DMS";
$html=NotificationEmail::render($subject,$text,'delivery','LIV-2026-0042');
checkEmail(strpos($html,'DOCUMENT DE LIVRAISON')!==false && strpos($html,'Réserves constatées')!==false,'le PDF et les réserves sont mis en évidence');
checkEmail(strpos($html,'Bonjour Direction')!==false && strpos($html,'Élodie')!==false,'le destinataire et les accents sont préservés');
checkEmail(strpos($html,'role="presentation"')!==false && strpos($html,'max-width:640px')!==false,'le rendu utilise des tableaux et une largeur adaptée aux e-mails');
$unsafe=NotificationEmail::render('<script>alert(1)</script>','Bonjour <img src=x onerror=alert(1)>','dispatch','<script>reference</script>');
checkEmail(strpos($unsafe,'<script>')===false && strpos($unsafe,'<img src=x')===false && strpos($unsafe,'&lt;img')!==false,'les données métier ne peuvent pas injecter du HTML');
checkEmail(strpos(NotificationEmail::render('[TEST] '.$subject,$text,'delivery','LIV-42'),'MESSAGE DE TEST')!==false,'le bandeau de test reste clairement visible');
$builder=new ReflectionMethod(Mailer::class,'build');$builder->setAccessible(true);
$pdf="%PDF-1.4\nBinary\x00\x01\xFF";
foreach([false,true] as $attach){
    $attachments=$attach?[['name'=>'Bon-de-livraison.pdf','mime'=>'application/pdf','data'=>$pdf]]:[];
    $message=$builder->invoke(null,'sender@example.com','MSS-DMS','receiver@example.com','Direction',$subject,$text,$attachments,$html);
    checkEmail(strpos($message,$attach?'Content-Type: multipart/mixed':'Content-Type: multipart/alternative')!==false,'la structure MIME correspond à la présence de pièces jointes');
    $leaves=mimeLeaves($message);
    checkEmail(count($leaves)===($attach?3:2),'les parties texte, HTML et PDF sont distinctes');
    checkEmail($leaves[0]['body']===str_replace("\n","\r\n",$text),'la version texte reste lisible sans HTML');
    checkEmail($leaves[1]['body']===str_replace("\n","\r\n",$html),'la version HTML est transmise sans altération');
    if($attach){checkEmail($leaves[2]['body']===$pdf && strpos($leaves[2]['headers'],'attachment;')!==false,'le PDF binaire est joint sans corruption');}
}
$legacy=$builder->invoke(null,'sender@example.com','MSS-DMS','receiver@example.com','','Legacy','Text only',[]);
checkEmail(strpos($legacy,'multipart/')===false && mimeLeaves($legacy)[0]['body']==='Text only','les appels existants sans HTML restent compatibles');
session_write_close();
echo 'NOTIFICATION_EMAIL_OK: '.$checks.' checks'.PHP_EOL;
