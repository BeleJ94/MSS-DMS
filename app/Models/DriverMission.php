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
        $sql='SELECT d.id,d.reference,d.scheduled_at,d.priority,d.status,c.company_name,s.name site_name,s.city,s.address_line1 FROM deliveries d JOIN drivers dr ON dr.id=d.driver_id JOIN clients c ON c.id=d.client_id JOIN client_sites s ON s.id=d.client_site_id WHERE dr.user_id=:user AND d.status<>"Annulée" ORDER BY FIELD(d.status,"Partie","En transit","Arrivée","Incident","Chargée","Chargement","Prête","À préparer","Brouillon","Livrée","Clôturée"),d.scheduled_at DESC';
        $s=Database::connection()->prepare($sql);$s->execute(['user'=>Auth::id()]);return $s->fetchAll();
    }

    public static function findOwned(int $id): ?array
    {
        $sql='SELECT d.*,c.company_name,s.name site_name,s.address_line1 site_address,s.city site_city,s.latitude,s.longitude,s.delivery_instructions,ct.full_name contact_name,ct.phone contact_phone,ct.email contact_email,v.registration_number FROM deliveries d JOIN drivers dr ON dr.id=d.driver_id JOIN clients c ON c.id=d.client_id JOIN client_sites s ON s.id=d.client_site_id LEFT JOIN client_contacts ct ON ct.id=d.client_contact_id LEFT JOIN vehicles v ON v.id=d.vehicle_id WHERE d.id=:id AND dr.user_id=:user';
        $s=Database::connection()->prepare($sql);$s->execute(['id'=>$id,'user'=>Auth::id()]);$d=$s->fetch();if(!$d){return null;}$g=Database::connection()->prepare('SELECT description_snapshot,quantity,unit FROM delivery_goods WHERE delivery_id=:id ORDER BY id');$g->execute(['id'=>$id]);$d['goods']=$g->fetchAll();return $d;
    }

    public static function perform(int $id,string $action,?string $description=null): string
    {
        $mission=self::findOwned($id);if(!$mission){throw new RuntimeException('Mission introuvable ou non autorisée.');}
        if($action==='start'){
            if($mission['status']==='Chargée'){Delivery::transition($id,'Partie','Mission démarrée par le chauffeur');Delivery::transition($id,'En transit','Chauffeur en route');return 'Mission démarrée.';}
            if($mission['status']==='Partie'){Delivery::transition($id,'En transit','Chauffeur en route');return 'Mission démarrée.';}
            throw new RuntimeException('La mission doit être chargée avant le départ.');
        }
        if($action==='arrive'){if($mission['status']!=='En transit'){throw new RuntimeException('Cette mission ne peut pas encore être marquée arrivée.');}Delivery::transition($id,'Arrivée','Arrivée confirmée par le chauffeur');return 'Arrivée confirmée.';}
        if($action==='deliver'){throw new RuntimeException('Complétez la preuve de livraison numérique.');}
        if($action==='incident'){throw new RuntimeException('Utilisez le formulaire complet de signalement d’incident.');}
        throw new RuntimeException('Action inconnue.');
    }
}
