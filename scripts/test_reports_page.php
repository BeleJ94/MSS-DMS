<?php

declare(strict_types=1);

/** @var App\Core\Application $app */
$app = require dirname(__DIR__).'/bootstrap/app.php';

use App\Core\Database;
use App\Core\Env;
use App\Core\Session;
use App\Core\View;
use App\Models\Report;
use App\Controllers\ReportController;
use App\Core\Request;

function expectReport(bool $condition,string $message):void{if(!$condition){throw new RuntimeException($message);}echo "OK - {$message}\n";}

$user=Database::connection()->query("SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id JOIN role_permissions rp ON rp.role_id=r.id JOIN permissions p ON p.id=rp.permission_id WHERE u.is_active=1 AND p.name='reports.view' LIMIT 1")->fetch();
expectReport((bool)$user,'un utilisateur autorisé aux rapports existe');
Session::put('auth_user_id',(int)$user['id']);
$data=Report::operational(30);
expectReport(isset($data['summary']['completion_rate'],$data['summary']['punctuality_rate']),'les indicateurs opérationnels sont calculés');
expectReport(array_key_exists('completion_rate_change',$data['summary'])&&is_array($data['trend']),'les comparaisons et la tendance exécutive sont calculées');
expectReport(isset($data['actions']['overdue'],$data['actions']['unassigned'],$data['actions']['incidents'],$data['actions']['discrepancies']),'les priorités du manager sont consolidées');
$detail=Report::details('planned',30);
expectReport(isset($detail['columns'],$detail['rows'],$detail['count'])&&$detail['title']==='Courses planifiées','le détail compact des KPI est disponible');
foreach(['delivered','completion','punctuality','overdue','unassigned','incidents','discrepancies'] as $detailType){expectReport(isset(Report::details($detailType,30)['rows']),'le détail '.$detailType.' est interrogeable');}
if($data['trend']){$daily=Report::details('day',30,$data['trend'][0]['day']);expectReport(strpos($daily['title'],'Activité du')===0,'un point du graphique ouvre le détail de sa journée');}
try{Report::details('invalid',30);expectReport(false,'un type de détail invalide est refusé');}catch(InvalidArgumentException $e){expectReport(true,'un type de détail invalide est refusé');}
expectReport(is_array($data['clients'])&&is_array($data['recentDeliveries']),'les performances clients et livraisons récentes sont disponibles');
$viewBase=['title'=>'Rapports','page'=>'reports','period'=>30,'baseUrl'=>rtrim((string)Env::get('APP_URL',''),'/')];
$html=View::render('reports/index',$viewBase+['reportView'=>'executive']+$data);
expectReport(strpos($html,'/reports"')!==false&&strpos($html,'nav-item active')!==false,'le menu Rapports est un lien actif et navigable');
expectReport(strpos($html,'Vue CEO')!==false&&strpos($html,'À retenir')!==false&&strpos($html,'executiveTrendChart')!==false,'le rapport CEO expose synthèse, tendances et faits marquants');
expectReport(strpos($html,'reportDetailModal')!==false&&strpos($html,'data-report-detail="planned"')!==false,'les KPI et graphiques disposent de leur modale de détail');
$managerHtml=View::render('reports/index',$viewBase+['reportView'=>'manager']+$data);
expectReport(strpos($managerHtml,'Pilotage manager')!==false&&strpos($managerHtml,'Actions requises')!==false&&strpos($managerHtml,'Courses non affectées')!==false,'le rapport Manager expose priorités et actions opérationnelles');
$_GET=['type'=>'planned','period'=>'30'];$_SERVER['REQUEST_METHOD']='GET';$_SERVER['REQUEST_URI']='/reports/export.xls';$_SERVER['SCRIPT_NAME']='/index.php';
$export=(new ReportController())->export(Request::capture());$reflection=new ReflectionClass($export);$headersProperty=$reflection->getProperty('headers');$headersProperty->setAccessible(true);$contentProperty=$reflection->getProperty('content');$contentProperty->setAccessible(true);$headers=$headersProperty->getValue($export);$content=$contentProperty->getValue($export);
expectReport(strpos($headers['Content-Type'],'application/vnd.ms-excel')===0&&strpos($content,'<table')!==false,'l’export Excel reprend le tableau détaillé');
echo "REPORTS_PAGE_OK\n";
