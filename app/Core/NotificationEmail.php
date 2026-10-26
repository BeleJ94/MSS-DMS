<?php

declare(strict_types=1);

namespace App\Core;

/** Table-based HTML with inline styles for mail clients; all business content is escaped. */
final class NotificationEmail
{
    public static function render(string $subject,string $text,string $event,string $reference): string
    {
        $escape=static function(string $value):string{return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');};
        $brand=$escape((string)Env::get('MAIL_FROM_NAME',Env::get('APP_NAME','MSS-DMS')));
        $states=[
            'dispatch'=>['EN ACHEMINEMENT','Votre livraison est en route.','#245e91','#edf4fa'],
            'arrival'=>['ARRIVÉE À DESTINATION','Votre chauffeur est arrivé.','#245e91','#edf4fa'],
            'delivery'=>['LIVRAISON EFFECTUÉE','Votre livraison a été réceptionnée.','#276749','#edf6f0'],
            'configuration'=>['VÉRIFICATION DE L’ENVOI','Votre test de messagerie.','#245e91','#edf4fa'],
        ];
        [$status,$title,$accent,$soft]=$states[$event]??['INFORMATION LIVRAISON','Le suivi de votre livraison.','#245e91','#edf4fa'];
        $test=strpos($subject,'[TEST]')===0;
        $content='';
        foreach(preg_split('/\n\s*\n/',str_replace(["\r\n","\r"],"\n",trim($text))) as $paragraph){
            $paragraph=trim($paragraph);
            if($paragraph==='' || strpos($paragraph,'MESSAGE DE TEST')===0 || strpos($paragraph,'Cordialement,')===0 || strpos($paragraph,'Ce message est envoyé automatiquement')===0){continue;}
            $safe=nl2br($escape($paragraph),false);
            if(strpos($paragraph,'Réserves constatées')===0){
                $content.='<tr><td style="padding:0 32px 22px"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #ead8b8;background:#fffaf0"><tr><td style="padding:18px 20px;color:#79551e;font-size:14px;line-height:24px">'.$safe.'</td></tr></table></td></tr>';
            }elseif(preg_match('/^(Chauffeur|Véhicule|Destination\(s\)|Lieu|Réceptionnée par)\s*:/u',$paragraph)){
                $lines=explode("\n",$paragraph);$rows='';
                foreach($lines as $line){
                    if(preg_match('/^([^:]+)\s*:\s*(.*)$/u',trim($line),$match)){
                        $rows.='<tr><td style="padding:7px 0;font-size:11px;line-height:18px;color:#69788a;vertical-align:top;width:118px">'.$escape(trim($match[1])).'</td><td style="padding:7px 0;font-size:14px;line-height:21px;color:#1e3044;font-weight:600">'.$escape($match[2]).'</td></tr>';
                    }else{$rows.='<tr><td colspan="2" style="padding:6px 0;font-size:14px;line-height:22px;color:#1e3044">'.$escape(trim($line)).'</td></tr>';}
                }
                $content.='<tr><td style="padding:0 32px 22px"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f5f7fa;border:1px solid #e6ebf0"><tr><td style="padding:12px 20px"><table role="presentation" width="100%" cellspacing="0" cellpadding="0">'.$rows.'</table></td></tr></table></td></tr>';
            }elseif(strpos($paragraph,'Le bon de livraison est joint')===0){
                $content.='<tr><td style="padding:0 32px 24px"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #d8e3ef"><tr><td style="padding:16px 20px;color:#245e91;font-size:13px;line-height:22px"><strong>DOCUMENT DE LIVRAISON</strong><br>'.$safe.'</td></tr></table></td></tr>';
            }else{$content.='<tr><td style="padding:0 32px 20px;color:#435268;font-size:14px;line-height:25px">'.$safe.'</td></tr>';}
        }
        $notice=$test?'<tr><td style="padding:14px 32px;background:#fff5df;color:#79551e;font-size:12px;line-height:20px"><strong>MESSAGE DE TEST</strong> &nbsp; Cet envoi ne confirme aucun changement de statut de la livraison.</td></tr>':'';
        return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><title>'.$escape($subject).'</title></head>'
            .'<body style="margin:0;padding:0;background:#eef2f6;font-family:Arial,Helvetica,sans-serif;-webkit-text-size-adjust:100%">'
            .'<div style="display:none;font-size:1px;line-height:1px;color:#eef2f6;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all">'.$escape($reference).' · '.$escape($title).'</div>'
            .'<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef2f6"><tr><td align="center" style="padding:28px 12px">'
            .'<!--[if mso]><table role="presentation" width="640" cellspacing="0" cellpadding="0"><tr><td><![endif]-->'
            .'<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border:1px solid #dde5ed">'
            .'<tr><td style="padding:26px 32px;background:#14283f;border-top:4px solid #b8a170"><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td style="font-size:20px;font-weight:700;letter-spacing:1px;color:#ffffff">'.$brand.'</td><td align="right" style="font-size:10px;line-height:17px;letter-spacing:1px;color:#b9c7d6">SUIVI<br>DES LIVRAISONS</td></tr></table></td></tr>'
            .$notice
            .'<tr><td style="padding:30px 32px 12px"><span style="display:inline-block;padding:6px 10px;background:'.$soft.';color:'.$accent.';font-size:10px;line-height:16px;font-weight:700;letter-spacing:1px">'.$status.'</span></td></tr>'
            .'<tr><td style="padding:0 32px 14px;color:#14283f;font-size:28px;line-height:36px;font-weight:700;letter-spacing:-.6px">'.$escape($title).'</td></tr>'
            .'<tr><td style="padding:0 32px 26px;color:#748194;font-size:12px;line-height:20px">RÉFÉRENCE &nbsp; <strong style="color:#203b59">'.$escape($reference).'</strong></td></tr>'
            .$content
            .'<tr><td style="padding:4px 32px 30px;color:#435268;font-size:14px;line-height:24px">Nous vous remercions de votre confiance.<br><strong style="color:#14283f">L’équipe '.$brand.'</strong></td></tr>'
            .'<tr><td style="padding:20px 32px;background:#f7f9fb;border-top:1px solid #e6ebf0;color:#7a8797;font-size:11px;line-height:19px">Notification automatique de suivi logistique.<br>Pour toute précision, contactez votre interlocuteur habituel. Merci de ne pas répondre à cet e-mail.</td></tr>'
            .'</table><!--[if mso]></td></tr></table><![endif]-->'
            .'<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px"><tr><td align="center" style="padding:18px 12px;color:#8793a3;font-size:10px;line-height:18px">'.$brand.' &nbsp;·&nbsp; Information de livraison</td></tr></table>'
            .'</td></tr></table></body></html>';
    }
}
