<?php

declare(strict_types=1);

namespace App\Core {
    // Substitute only the identity layer: exercise real routing, CSRF, controller and views without a database.
    final class Auth {
        public static $authenticated=false;
        public static $admin=false;
        public static function check():bool{return self::$authenticated;}
        public static function user():?array{return self::$authenticated?['name'=>'Test Admin','email'=>'admin@example.com','roles'=>'Test']:null;}
        public static function hasRole(string $role):bool{return self::$authenticated && self::$admin && $role==='administrateur';}
        public static function can(string $permission):bool{return self::$authenticated;}
    }
}

namespace {
    ini_set('session.save_path',sys_get_temp_dir());
    $app=require dirname(__DIR__).'/bootstrap/app.php';
    require dirname(__DIR__).'/routes/web.php';
    $originalPassword=getenv('MAIL_PASSWORD');
    putenv('MAIL_PASSWORD=never-display-this-secret');
    $checks=0;
    function mailResponse($app,string $method,array $body=[],string $path='/settings/mail'):array{
        $_SERVER['REQUEST_METHOD']=$method;$_SERVER['REQUEST_URI']=$path;$_SERVER['SCRIPT_NAME']='/index.php';
        $_SERVER['CONTENT_TYPE']='application/x-www-form-urlencoded';$_POST=$body;$_GET=[];
        $response=$app->router()->dispatch(\App\Core\Request::capture());
        $reflection=new \ReflectionClass($response);$result=[];
        foreach(['status','content','headers'] as $key){$property=$reflection->getProperty($key);$property->setAccessible(true);$result[$key]=$property->getValue($response);}
        return $result;
    }
    function mailAccessCheck(bool $condition,string $message):void{global $checks;if(!$condition){throw new \RuntimeException($message);}$checks++;echo 'OK - '.$message.PHP_EOL;}
    try{
        mailAccessCheck(mailResponse($app,'GET')['status']===302,'la page exige une connexion');
        \App\Core\Auth::$authenticated=true;
        mailAccessCheck(mailResponse($app,'GET')['status']===403,'un utilisateur non administrateur ne voit pas les paramètres');
        $token=\App\Core\Csrf::token();
        mailAccessCheck(mailResponse($app,'POST',['_token'=>$token,'action'=>'save'])['status']===403,'un utilisateur non administrateur ne peut pas enregistrer');
        mailAccessCheck(mailResponse($app,'POST',['_token'=>$token,'action'=>'test'])['status']===403,'un utilisateur non administrateur ne peut pas envoyer de test');
        \App\Core\Auth::$admin=true;
        mailAccessCheck(mailResponse($app,'POST',['action'=>'save'])['status']===419,'les modifications exigent un jeton CSRF');
        mailAccessCheck(mailResponse($app,'POST',['action'=>'test'])['status']===419,'les tests exigent un jeton CSRF');
        $response=mailResponse($app,'GET');
        mailAccessCheck($response['status']===200 && strpos($response['content'],'Tester l’envoi')!==false,'l’administrateur voit le formulaire de configuration et de test');
        mailAccessCheck(strpos($response['content'],'never-display-this-secret')===false,'le mot de passe enregistré est absent du HTML');
        mailAccessCheck(($response['headers']['Cache-Control']??'')==='no-store','la page de configuration ne doit pas être mise en cache');
        $response=mailResponse($app,'POST',['_token'=>$token,'action'=>'test','MAIL_FROM_ADDRESS'=>'invalid','MAIL_PASSWORD'=>'new-secret-not-to-display']);
        mailAccessCheck($response['status']===422 && strpos($response['content'],'new-secret-not-to-display')===false,'une saisie invalide affiche une erreur sans réafficher le secret');
        $_SERVER['HTTP_ACCEPT']='application/json';
        $response=mailResponse($app,'POST',['_token'=>$token,'action'=>'test','MAIL_FROM_ADDRESS'=>'invalid','MAIL_PASSWORD'=>'ajax-secret']);
        $json=json_decode($response['content'],true);
        mailAccessCheck($response['status']===422 && $json['success']===false && isset($json['errors']['MAIL_FROM_ADDRESS']),'AJAX : la validation désigne le champ à corriger');
        mailAccessCheck(strpos($response['content'],'ajax-secret')===false && strpos($response['content'],'never-display-this-secret')===false,'AJAX : aucun secret n’est retourné dans les erreurs');
        $response=mailResponse($app,'POST',['action'=>'test']);
        mailAccessCheck($response['status']===419 && json_decode($response['content'],true)['success']===false,'AJAX : une session CSRF expirée renvoie du JSON');
        \App\Core\Auth::$admin=false;
        $response=mailResponse($app,'POST',['_token'=>$token,'action'=>'test']);
        mailAccessCheck($response['status']===403 && json_decode($response['content'],true)['success']===false,'AJAX : les droits administrateur sont contrôlés');
        \App\Core\Auth::$authenticated=false;
        $response=mailResponse($app,'POST',['_token'=>$token,'action'=>'test']);
        mailAccessCheck($response['status']===401 && json_decode($response['content'],true)['success']===false,'AJAX : une session déconnectée renvoie du JSON');
        $path='/settings/mail/notifications';
        mailAccessCheck(mailResponse($app,'POST',['_token'=>$token,'operation'=>'send'],$path)['status']===401,'les tests de livraison exigent une session connectée');
        \App\Core\Auth::$authenticated=true;
        mailAccessCheck(mailResponse($app,'GET',[],$path)['status']===403,'le sous-écran est réservé aux administrateurs');
        mailAccessCheck(mailResponse($app,'GET',[],$path.'/data')['status']===403,'les données des tests sont réservées aux administrateurs');
        mailAccessCheck(mailResponse($app,'POST',['_token'=>$token,'operation'=>'send'],$path)['status']===403,'les envois de livraison sont réservés aux administrateurs');
        \App\Core\Auth::$admin=true;
        mailAccessCheck(mailResponse($app,'POST',['operation'=>'send'],$path)['status']===419,'les tests de livraison sont protégés par CSRF');
        $response=mailResponse($app,'GET',[],$path);
        mailAccessCheck($response['status']===200 && strpos($response['content'],'notification-test-form')!==false,'le sous-écran et ses actions sont rendus pour l’administrateur');
        $response=mailResponse($app,'POST',['_token'=>$token,'operation'=>'send','delivery_id'=>'invalid'],$path);
        mailAccessCheck($response['status']===422 && json_decode($response['content'],true)['success']===false,'une livraison invalide est refusée avant tout envoi');
        unset($_SERVER['HTTP_ACCEPT']);
        echo 'MAIL_SETTINGS_ACCESS_OK: '.$checks.' checks'.PHP_EOL;
    }finally{
        putenv($originalPassword===false?'MAIL_PASSWORD':'MAIL_PASSWORD='.$originalPassword);
        session_write_close();
    }
}
