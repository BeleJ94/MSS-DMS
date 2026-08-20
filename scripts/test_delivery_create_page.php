<?php

declare(strict_types=1);

/** @var App\Core\Application $app */
$app=require dirname(__DIR__).'/bootstrap/app.php';

use App\Core\Database;
use App\Core\Session;
use App\Core\View;
use App\Models\Delivery;

function expectCreatePage(bool $condition,string $message): void{if(!$condition){throw new RuntimeException($message);}echo "OK - {$message}\n";}

$pdo=Database::connection();$user=$pdo->query("SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE u.is_active=1 AND r.slug='administrateur' LIMIT 1")->fetchColumn();
if(!$user){throw new RuntimeException('Administrateur actif requis pour le test.');}Session::put('auth_user_id',(int)$user);
$html=View::render('deliveries/create',['title'=>'Nouvelle livraison','page'=>'deliveries','clients'=>Delivery::clients(),'goodsCatalog'=>Delivery::goodsCatalog(),'drivers'=>Delivery::drivers(),'vehicles'=>Delivery::vehicles(),'statuses'=>array_merge(Delivery::FLOW,Delivery::EXCEPTIONS)]);
expectCreatePage(strpos($html,'class="delivery-create-page"')!==false,'la création utilise une page dédiée');
expectCreatePage(strpos($html,'id="createDeliveryForm"')!==false,'le formulaire de création est rendu');
expectCreatePage(substr_count($html,'course-field-card')===3&&strpos($html,'section-kicker')!==false,'les informations de la course utilisent trois champs en cartes hiérarchisées');
expectCreatePage(strpos($html,'data-delivery-stops')!==false&&strpos($html,'data-add-stop')!==false,'le parcours multi-destinations est disponible');
expectCreatePage(strpos($html,'data-stop-card')!==false&&strpos($html,'Marchandises à livrer ici')!==false,'chaque destination et ses marchandises sont réunies dans une fiche');
expectCreatePage(strpos($html,'data-destination-field="latitude"')===false&&strpos($html,'data-destination-field="longitude"')===false,'le tableau reste limité aux informations opérationnelles demandées');
expectCreatePage(strpos($html,'data-stop-goods')!==false&&strpos($html,'data-goods-field="destination_index"')===false,'les marchandises sont imbriquées sans sélecteur de destination ambigu');
expectCreatePage(strpos($html,'data-stop-up')!==false&&strpos($html,'data-stop-down')!==false,'les fiches complètes peuvent être réordonnées');
expectCreatePage(strpos($html,'data-form-stop-count')!==false&&strpos($html,'data-form-weight')!==false,'un résumé opérationnel accompagne la saisie');
expectCreatePage(strpos($html,'id="deliveryModal"')===false,'aucun modal de création n’est rendu sur la page');
expectCreatePage(strpos($html,'Retour aux livraisons')!==false&&strpos($html,'Créer le brouillon')!==false,'les actions de navigation et de validation sont explicites');
echo "DELIVERY_CREATE_PAGE_OK\n";
