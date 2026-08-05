<?php

declare(strict_types=1);

/** @var App\Core\Application $app */
$app=require dirname(__DIR__).'/bootstrap/app.php';

use App\Core\Database;
use App\Core\Session;
use App\Core\View;
use App\Models\Dashboard;

function expectDashboard(bool $condition,string $message):void{if(!$condition){throw new RuntimeException($message);}echo "OK - {$message}\n";}
$data=Dashboard::data();$required=['deliveries_today','to_prepare','ready','in_transit','delivered_today','overdue','open_incidents','available_drivers','available_vehicles'];
foreach($required as $key){expectDashboard(array_key_exists($key,$data['kpis'])&&is_numeric($data['kpis'][$key]),'KPI '.$key.' calculé');}
expectDashboard(count($data['period'])===14,'série livraisons sur 14 jours');
expectDashboard(isset($data['punctuality']['rate'],$data['punctuality']['on_time'],$data['punctuality']['late']),'taux de livraison à temps calculé');
expectDashboard(count($data['incidents'])===count(App\Models\Incident::TYPES),'répartition complète des types d’incidents');
expectDashboard(count($data['performance']['labels'])===4&&count($data['performance']['values'])===4,'performance opérationnelle calculée');
foreach($data['performance']['values'] as $value){expectDashboard((float)$value>=0&&(float)$value<=100,'ratio opérationnel borné entre 0 et 100');}
$direct=(int)Database::connection()->query("SELECT COUNT(*) FROM deliveries WHERE scheduled_at<NOW() AND status NOT IN ('Livrée','Clôturée','Annulée')")->fetchColumn();expectDashboard((int)$data['kpis']['overdue']===$direct,'KPI retard cohérent avec les livraisons actives');
$adminId=(int)Database::connection()->query('SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE r.slug="administrateur" AND u.is_active=1 LIMIT 1')->fetchColumn();Session::put('auth_user_id',$adminId);$html=View::render('home/index',['title'=>'Tableau de bord','page'=>'dashboard']+$data);expectDashboard(strpos($html,'Tableau de bord Direction')!==false,'rendu Direction disponible');foreach(['periodDeliveriesChart','punctualityChart','incidentTypesChart','operationalPerformanceChart'] as $canvas){expectDashboard(strpos($html,'id="'.$canvas.'"')!==false,'graphique '.$canvas.' rendu');}expectDashboard(strpos($html,'window.MSS_DASHBOARD=')!==false,'données Chart.js injectées dans le rendu');
echo "DASHBOARD_OK\n";
