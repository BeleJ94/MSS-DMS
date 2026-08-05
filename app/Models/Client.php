<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class Client
{
    public static function dataTable(array $filters): array
    {
        $pdo = Database::connection();
        $conditions = ['1 = 1'];
        $params = [];
        if (($filters['status'] ?? '') !== '') { $conditions[] = 'c.status = :status'; $params['status'] = $filters['status']; }
        if (($filters['city'] ?? '') !== '') { $conditions[] = 'c.city = :city'; $params['city'] = $filters['city']; }
        if (($filters['search'] ?? '') !== '') {
            $conditions[] = '(c.company_name LIKE :search_name OR c.code LIKE :search_code OR c.email LIKE :search_email OR c.phone LIKE :search_phone)';
            $term = '%' . $filters['search'] . '%';
            $params['search_name'] = $term; $params['search_code'] = $term; $params['search_email'] = $term; $params['search_phone'] = $term;
        }
        $sql = 'SELECT c.id, c.code, c.company_name, c.email, c.phone, c.city, c.status, c.updated_at,
                       COUNT(DISTINCT s.id) AS sites_count, COUNT(DISTINCT ct.id) AS contacts_count
                FROM clients c LEFT JOIN client_sites s ON s.client_id = c.id LEFT JOIN client_contacts ct ON ct.client_id = c.id
                WHERE ' . implode(' AND ', $conditions) . ' GROUP BY c.id ORDER BY c.company_name';
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }

    public static function cities(): array
    {
        return Database::connection()->query("SELECT DISTINCT city FROM clients WHERE city IS NOT NULL AND city <> '' ORDER BY city")->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function find(int $id): ?array
    {
        $statement = Database::connection()->prepare('SELECT c.*, uc.name AS creator_name, uu.name AS updater_name FROM clients c LEFT JOIN users uc ON uc.id=c.created_by LEFT JOIN users uu ON uu.id=c.updated_by WHERE c.id=:id');
        $statement->execute(['id' => $id]);
        $client = $statement->fetch();
        if (!$client) { return null; }
        $client['contacts'] = self::children('client_contacts', $id);
        $client['sites'] = self::children('client_sites', $id);
        $client['recipients'] = self::children('client_notification_recipients', $id);
        $history = Database::connection()->prepare('SELECT h.*, u.name AS user_name FROM client_history h LEFT JOIN users u ON u.id=h.user_id WHERE h.client_id=:id ORDER BY h.created_at DESC, h.id DESC');
        $history->execute(['id' => $id]);
        $client['history'] = $history->fetchAll();
        return $client;
    }

    public static function create(array $data): int
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $code = self::nextCode($pdo);
            $statement = $pdo->prepare('INSERT INTO clients (code,company_name,legal_name,tax_number,registration_number,email,phone,website,address_line1,address_line2,city,province,country,latitude,longitude,status,notes,created_by,updated_by) VALUES (:code,:company_name,:legal_name,:tax_number,:registration_number,:email,:phone,:website,:address_line1,:address_line2,:city,:province,:country,:latitude,:longitude,:status,:notes,:created_by,:updated_by)');
            $statement->execute(self::clientParams($data, $code));
            $id = (int) $pdo->lastInsertId();
            self::replaceChildren($pdo, $id, $data);
            self::history($pdo, $id, 'created', 'Client créé', ['code' => $code]);
            $pdo->commit();
            return $id;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $exception;
        }
    }

    public static function update(int $id, array $data): bool
    {
        $existing = self::find($id);
        if ($existing === null) { return false; }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $params = self::clientParams($data, $existing['code']);
            $params['id'] = $id;
            $statement = $pdo->prepare('UPDATE clients SET company_name=:company_name,legal_name=:legal_name,tax_number=:tax_number,registration_number=:registration_number,email=:email,phone=:phone,website=:website,address_line1=:address_line1,address_line2=:address_line2,city=:city,province=:province,country=:country,latitude=:latitude,longitude=:longitude,status=:status,notes=:notes,updated_by=:updated_by WHERE id=:id');
            unset($params['code']);
            unset($params['created_by']);
            $statement->execute($params);
            self::replaceChildren($pdo, $id, $data);
            self::history($pdo, $id, 'updated', 'Informations du client mises à jour', ['company_name' => [$existing['company_name'], $data['company_name'] ?? ''], 'status' => [$existing['status'], $data['status'] ?? 'active']]);
            $pdo->commit();
            return true;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $exception;
        }
    }

    public static function archive(int $id): bool
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare("UPDATE clients SET status='archived', updated_by=:user_id WHERE id=:id AND status<>'archived'");
        $statement->execute(['id' => $id, 'user_id' => Auth::id()]);
        if ($statement->rowCount() === 1) { self::history($pdo, $id, 'archived', 'Client archivé', null); return true; }
        return false;
    }

    private static function children(string $table, int $id): array
    {
        $statement = Database::connection()->prepare("SELECT * FROM {$table} WHERE client_id=:id ORDER BY id");
        $statement->execute(['id' => $id]);
        return $statement->fetchAll();
    }

    private static function clientParams(array $data, string $code): array
    {
        $fields = ['company_name','legal_name','tax_number','registration_number','email','phone','website','address_line1','address_line2','city','province','country','latitude','longitude','status','notes'];
        $params = ['code' => $code, 'created_by' => Auth::id(), 'updated_by' => Auth::id()];
        foreach ($fields as $field) { $params[$field] = ($data[$field] ?? '') !== '' ? trim((string) $data[$field]) : null; }
        $params['country'] = $params['country'] ?: 'RDC';
        $params['status'] = $params['status'] ?: 'active';
        return $params;
    }

    private static function replaceChildren(PDO $pdo, int $clientId, array $data): void
    {
        foreach (['client_contacts','client_sites','client_notification_recipients'] as $table) {
            $delete = $pdo->prepare("DELETE FROM {$table} WHERE client_id=:id"); $delete->execute(['id' => $clientId]);
        }
        $contact = $pdo->prepare('INSERT INTO client_contacts (client_id,full_name,job_title,email,phone,is_primary) VALUES (:client_id,:full_name,:job_title,:email,:phone,:is_primary)');
        foreach ($data['contacts'] ?? [] as $row) { if (trim((string)($row['full_name'] ?? '')) === '') { continue; } $contact->execute(['client_id'=>$clientId,'full_name'=>trim($row['full_name']),'job_title'=>self::nullable($row['job_title']??null),'email'=>self::nullable($row['email']??null),'phone'=>self::nullable($row['phone']??null),'is_primary'=>!empty($row['is_primary'])?1:0]); }
        $site = $pdo->prepare('INSERT INTO client_sites (client_id,name,site_code,address_line1,address_line2,city,province,country,latitude,longitude,delivery_instructions,is_default,status) VALUES (:client_id,:name,:site_code,:address_line1,:address_line2,:city,:province,:country,:latitude,:longitude,:delivery_instructions,:is_default,:status)');
        foreach ($data['sites'] ?? [] as $row) { if (trim((string)($row['name']??''))==='' || trim((string)($row['address_line1']??''))==='' || trim((string)($row['city']??''))==='') { continue; } $country=trim((string)($row['country']??''))?:'RDC'; $site->execute(['client_id'=>$clientId,'name'=>trim($row['name']),'site_code'=>self::nullable($row['site_code']??null),'address_line1'=>trim($row['address_line1']),'address_line2'=>self::nullable($row['address_line2']??null),'city'=>trim($row['city']),'province'=>self::nullable($row['province']??null),'country'=>$country,'latitude'=>self::nullable($row['latitude']??null),'longitude'=>self::nullable($row['longitude']??null),'delivery_instructions'=>self::nullable($row['delivery_instructions']??null),'is_default'=>!empty($row['is_default'])?1:0,'status'=>in_array($row['status']??'active',['active','inactive'],true)?$row['status']:'active']); }
        $recipient = $pdo->prepare('INSERT INTO client_notification_recipients (client_id,full_name,email,phone,notify_email,notify_sms,notify_on) VALUES (:client_id,:full_name,:email,:phone,:notify_email,:notify_sms,:notify_on)');
        foreach ($data['recipients'] ?? [] as $row) { if (trim((string)($row['full_name']??''))==='') { continue; } $recipient->execute(['client_id'=>$clientId,'full_name'=>trim($row['full_name']),'email'=>self::nullable($row['email']??null),'phone'=>self::nullable($row['phone']??null),'notify_email'=>!empty($row['notify_email'])?1:0,'notify_sms'=>!empty($row['notify_sms'])?1:0,'notify_on'=>trim((string)($row['notify_on']??'dispatch,arrival,delivery'))]); }
    }

    private static function history(PDO $pdo, int $id, string $action, string $description, ?array $changes): void
    {
        $statement = $pdo->prepare('INSERT INTO client_history (client_id,user_id,action,description,changes_json) VALUES (:client_id,:user_id,:action,:description,:changes)');
        $statement->execute(['client_id'=>$id,'user_id'=>Auth::id(),'action'=>$action,'description'=>$description,'changes'=>$changes===null?null:json_encode($changes,JSON_UNESCAPED_UNICODE)]);
    }

    private static function nextCode(PDO $pdo): string
    {
        $next = (int) $pdo->query('SELECT COALESCE(MAX(id),0)+1 FROM clients')->fetchColumn();
        return 'CLI-' . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
    private static function nullable($value) { $value=trim((string)$value); return $value===''?null:$value; }
}
