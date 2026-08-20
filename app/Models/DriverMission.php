<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Database;
use RuntimeException;

final class DriverMission
{
    public static function driver(): ?array
    {
        $s=Database::connection()->prepare('SELECT id,first_name,last_name,phone FROM drivers WHERE user_id=:user AND is_active=1');$s->execute(['user'=>Auth::id()]);$row=$s->fetch();return $row?:null;
    }

    public static function listing(): array
    {
        $sql='SELECT d.id,d.reference,d.scheduled_at,d.priority,d.status,c.company_name,fd.label site_name,fd.city,fd.address_line address_line1,(SELECT COUNT(*) FROM delivery_destinations dc WHERE dc.delivery_id=d.id) destination_count,(SELECT COUNT(*) FROM delivery_destinations dc WHERE dc.delivery_id=d.id AND dc.status="Livrée") delivered_destination_count FROM deliveries d JOIN drivers dr ON dr.id=d.driver_id JOIN clients c ON c.id=d.client_id LEFT JOIN delivery_destinations fd ON fd.id=(SELECT dx.id FROM delivery_destinations dx WHERE dx.delivery_id=d.id AND dx.status NOT IN ("Livrée","Annulée") ORDER BY dx.stop_order LIMIT 1) WHERE dr.user_id=:user AND d.status<>"Annulée" ORDER BY FIELD(d.status,"Partie","En transit","Arrivée","Incident","Chargée","Chargement","Prête","À préparer","Affectée","Brouillon","Livrée","Clôturée"),d.scheduled_at DESC';
        $s=Database::connection()->prepare($sql);$s->execute(['user'=>Auth::id()]);return $s->fetchAll();
    }

    public static function findOwned(int $id): ?array
    {
        $sql='SELECT d.*,c.company_name,fd.id current_destination_id,fd.stop_order current_stop_order,fd.label site_name,fd.address_line site_address,fd.city site_city,fd.latitude,fd.longitude,fd.instructions delivery_instructions,fd.contact_name,fd.contact_phone,NULL contact_email,v.registration_number FROM deliveries d JOIN drivers dr ON dr.id=d.driver_id JOIN clients c ON c.id=d.client_id LEFT JOIN delivery_destinations fd ON fd.id=(SELECT dx.id FROM delivery_destinations dx WHERE dx.delivery_id=d.id AND dx.status NOT IN ("Livrée","Annulée") ORDER BY dx.stop_order LIMIT 1) LEFT JOIN vehicles v ON v.id=d.vehicle_id WHERE d.id=:id AND dr.user_id=:user';
        $s=Database::connection()->prepare($sql);$s->execute(['id'=>$id,'user'=>Auth::id()]);$d=$s->fetch();if(!$d){return null;}$dest=Database::connection()->prepare('SELECT * FROM delivery_destinations WHERE delivery_id=:id ORDER BY stop_order');$dest->execute(['id'=>$id]);$d['destinations']=$dest->fetchAll();if($d['current_destination_id']){$g=Database::connection()->prepare('SELECT description_snapshot,quantity,unit,destination_id FROM delivery_goods WHERE delivery_id=:id AND (destination_id=:destination OR destination_id IS NULL) ORDER BY id');$g->execute(['id'=>$id,'destination'=>$d['current_destination_id']]);}else{$g=Database::connection()->prepare('SELECT description_snapshot,quantity,unit,destination_id FROM delivery_goods WHERE delivery_id=:id ORDER BY id');$g->execute(['id'=>$id]);}$d['goods']=$g->fetchAll();return $d;
    }

    public static function perform(int $id,string $action,?string $description=null): string
    {
        $mission=self::findOwned($id);if(!$mission){throw new RuntimeException('Mission introuvable ou non autorisée.');}
        if($action==='accept'){
            if($mission['status']==='Brouillon'){Delivery::transition($id,'Affectée','Mission affectée confirmée par le chauffeur');$mission=self::findOwned($id);}
            if($mission['status']!=='Affectée'){throw new RuntimeException('Cette mission ne peut plus être acceptée à cette étape.');}Delivery::transition($id,'À préparer','Mission acceptée par le chauffeur');return 'Mission acceptée. Vous êtes désormais responsable de sa progression.';
        }
        if($action==='prepare'){if($mission['status']!=='À préparer'){throw new RuntimeException('La préparation ne peut pas être confirmée à cette étape.');}Delivery::transition($id,'Prête','Préparation confirmée par le chauffeur');return 'Préparation terminée. La mission est prête au chargement.';}
        if($action==='load'){if($mission['status']!=='Prête'){throw new RuntimeException('Le chargement ne peut pas être démarré à cette étape.');}Delivery::transition($id,'Chargement','Chargement démarré par le chauffeur');return 'Chargement démarré.';}
        if($action==='loaded'){if($mission['status']!=='Chargement'){throw new RuntimeException('La fin du chargement ne peut pas être confirmée à cette étape.');}Delivery::transition($id,'Chargée','Chargement terminé et contrôlé par le chauffeur');return 'Chargement confirmé. La mission peut démarrer.';}
        if($action==='start'){
            if($mission['status']==='Chargée'){Delivery::transition($id,'Partie','Mission démarrée par le chauffeur');Delivery::transition($id,'En transit','Chauffeur en route');return 'Mission démarrée.';}
            if($mission['status']==='Partie'){Delivery::transition($id,'En transit','Chauffeur en route');return 'Mission démarrée.';}
            if($mission['status']==='En transit'){return 'Mission déjà active.';}
            throw new RuntimeException('La mission doit être chargée avant le départ.');
        }
        if($action==='arrive'){if($mission['status']!=='En transit'){throw new RuntimeException('Cette mission ne peut pas encore être marquée arrivée.');}if(!$mission['current_destination_id']){throw new RuntimeException('Aucune destination restante.');}Delivery::transition($id,'Arrivée','Arrivée à la destination '.$mission['current_stop_order']);Database::connection()->prepare('UPDATE delivery_destinations SET status="Arrivée",arrived_at=NOW() WHERE id=:id')->execute(['id'=>$mission['current_destination_id']]);return 'Arrivée confirmée à la destination '.$mission['current_stop_order'].'.';}
        if($action==='deliver'){throw new RuntimeException('Complétez la preuve de livraison numérique.');}
        if($action==='incident'){throw new RuntimeException('Utilisez le formulaire complet de signalement d’incident.');}
        throw new RuntimeException('Action inconnue.');
    }

    public static function nextAction(string $status): ?array
    {
        $actions=['Brouillon'=>['action'=>'accept','label'=>'Accepter la mission','icon'=>'clipboard-check'],'Affectée'=>['action'=>'accept','label'=>'Accepter la mission','icon'=>'clipboard-check'],'À préparer'=>['action'=>'prepare','label'=>'Confirmer la préparation','icon'=>'list-checks'],'Prête'=>['action'=>'load','label'=>'Commencer le chargement','icon'=>'package-open'],'Chargement'=>['action'=>'loaded','label'=>'Chargement terminé','icon'=>'package-check'],'Chargée'=>['action'=>'start','label'=>'Démarrer la mission','icon'=>'play'],'Partie'=>['action'=>'start','label'=>'Continuer la mission','icon'=>'navigation'],'En transit'=>['action'=>'arrive','label'=>'Confirmer mon arrivée','icon'=>'map-pin-check']];return $actions[$status]??null;
    }
}
