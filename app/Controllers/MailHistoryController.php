<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Auth;
use App\Core\MailSettings;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\MailHistory;
use RuntimeException;
use Throwable;

final class MailHistoryController
{
    public function index(Request $request): Response
    {
        if(!Auth::hasRole('administrateur')){return new Response(View::render('errors/403',['title'=>'Accès refusé']),403,['Cache-Control'=>'no-store']);}
        $data=['title'=>'Suivi des e-mails','page'=>'mail-history','filters'=>MailHistory::filters([]),'rows'=>[],'counts'=>[],'total'=>0,'pages'=>1,'currentPage'=>1,'detail'=>null,'error'=>null];$status=200;
        try{
            $data['filters']=MailHistory::filters($request->all());
            if($request->param('id')!==null){$data['detail']=MailHistory::detail((int)$request->param('id'),$data['filters']['kind']);if(!$data['detail']){$status=404;$data['error']='Cette notification est introuvable.';}}
            else{$data=array_merge($data,MailHistory::search($data['filters']));}
        }catch(Throwable $e){
            $status=422;
            if($e instanceof RuntimeException && !($e instanceof \PDOException)){$data['error']=$e->getMessage();}
            else{error_log('Suivi e-mails : '.$e->getMessage());$data['error']='Le suivi ne peut pas être chargé. Vérifiez la connexion à la base et les migrations des notifications (000028 et 000030).';$status=503;}
        }
        $secret=MailSettings::current()['MAIL_PASSWORD'];
        $clean=static function(?string $value)use($secret):?string{if($value===null){return null;}return $secret!==''?str_replace([$secret,base64_encode($secret)],'[masqué]',$value):$value;};
        foreach($data['rows'] as &$row){$row['last_error']=$clean($row['last_error']);}unset($row);
        if($data['detail']){$data['detail']['last_error']=$clean($data['detail']['last_error']);}
        return new Response(View::render('settings/mail-history',$data),$status,['Cache-Control'=>'no-store']);
    }
}
