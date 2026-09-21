<?php
/** Read-only routing/security tests; no database connection or fixtures required. */
declare(strict_types=1);
ini_set('session.save_path', sys_get_temp_dir());
$app=require dirname(__DIR__).'/bootstrap/app.php';
require dirname(__DIR__).'/routes/web.php';

function requestMobile(string $method,string $path,array $body=[]): App\Core\Request {
    $_SERVER['REQUEST_METHOD']=$method; $_SERVER['REQUEST_URI']=$path; $_SERVER['SCRIPT_NAME']='/index.php';
    $_SERVER['CONTENT_TYPE']='application/x-www-form-urlencoded'; $_POST=$body; $_GET=[];
    return App\Core\Request::capture();
}
function inspectMobile(App\Core\Response $response): array {
    $reflection=new ReflectionClass($response); $result=[];
    foreach(['status','content'] as $field){$p=$reflection->getProperty($field);$p->setAccessible(true);$result[$field]=$p->getValue($response);}
    $result['json']=json_decode($result['content'],true);return $result;
}
$checks=0;
function checkMobile(bool $ok,string $label):void {global $checks;if(!$ok){throw new RuntimeException($label);} $checks++;}
$bootstrap=inspectMobile($app->router()->dispatch(requestMobile('GET','/api/mobile/bootstrap')));
checkMobile($bootstrap['status']===200 && strlen($bootstrap['json']['csrf_token']??'')===64,'CSRF bootstrap');
foreach(['/api/mobile/me','/api/mobile/missions','/api/mobile/missions/7'] as $path){
    $r=inspectMobile($app->router()->dispatch(requestMobile('GET',$path)));
    checkMobile($r['status']===401 && isset($r['json']['message']),'Anonymous access denied: '.$path);
}
foreach(['action','positions','pod','incident','start','deliver'] as $action){
    $r=inspectMobile($app->router()->dispatch(requestMobile('POST','/api/mobile/missions/7/'.$action)));
    checkMobile($r['status']===401,'Anonymous write denied: '.$action);
}
$r=inspectMobile($app->router()->dispatch(requestMobile('POST','/api/mobile/login',['email'=>'x@example.com','password'=>'x'])));
checkMobile($r['status']===419,'Login requires CSRF');
$r=inspectMobile($app->router()->dispatch(requestMobile('POST','/api/mobile/login',['_token'=>$bootstrap['json']['csrf_token'],'email'=>'invalid','password'=>'x'])));
checkMobile($r['status']===422,'Invalid credentials shape rejected');
$r=inspectMobile((new App\Controllers\MobileDriverController())->action(requestMobile('POST','/api/mobile/missions/7/action',['action'=>'start'])));
checkMobile($r['status']===422,'Missing GPS rejected before domain mutations');
session_write_close();
echo "MOBILE_API_OK: $checks checks (no database writes)\n";
