<?php

declare(strict_types=1);
namespace App\Models;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Session;
use RuntimeException;

/** Opaque per-device credential; only its SHA-256 digest is stored server-side. */
final class MobileSession
{
    public static function issue(int $userId): string
    {
        $pdo=Database::connection();
        $s=$pdo->prepare('SELECT password_hash FROM users WHERE id=:id AND is_active=1');$s->execute(['id'=>$userId]);$passwordHash=$s->fetchColumn();
        if(!$passwordHash){throw new RuntimeException('Compte indisponible.');}
        $token=bin2hex(random_bytes(32));
        $s=$pdo->prepare('INSERT INTO mobile_sessions(user_id,token_hash,password_fingerprint,expires_at,last_used_at) VALUES(:user,:token,:fingerprint,DATE_ADD(NOW(),INTERVAL 30 DAY),NOW())');
        $s->execute(['user'=>$userId,'token'=>hash('sha256',$token),'fingerprint'=>hash('sha256',$passwordHash)]);
        Session::put('mobile_session_id',(int)$pdo->lastInsertId());
        return $token;
    }

    private static function usable(array $row): bool
    {
        return (bool)$row['is_active'] && $row['revoked_at']===null && (bool)$row['unexpired'] && hash_equals($row['password_fingerprint'],hash('sha256',$row['password_hash']));
    }

    public static function resume(string $token): bool
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$token)){return false;}
        $pdo=Database::connection();$pdo->beginTransaction();
        try {
            $s=$pdo->prepare('SELECT ms.*,u.password_hash,u.is_active,(ms.expires_at>NOW()) unexpired FROM mobile_sessions ms JOIN users u ON u.id=ms.user_id WHERE ms.token_hash=:token FOR UPDATE');
            $s->execute(['token'=>hash('sha256',$token)]);$row=$s->fetch();
            if(!$row || !self::usable($row)){$pdo->rollBack();return false;}
            Auth::restoreMobileIdentity((int)$row['user_id']);
            if(!Auth::can('driver_app.access') || !DriverMission::driver()){$pdo->rollBack();Auth::logout();return false;}
            $pdo->prepare('UPDATE mobile_sessions SET expires_at=DATE_ADD(NOW(),INTERVAL 30 DAY),last_used_at=NOW() WHERE id=:id')->execute(['id'=>$row['id']]);
            Session::put('mobile_session_id',(int)$row['id']);$pdo->commit();return true;
        } catch(\Throwable $e){if($pdo->inTransaction()){$pdo->rollBack();}throw $e;}
    }

    public static function validCurrent(): bool
    {
        $id=Session::get('mobile_session_id');
        if(!$id){return true;} // Existing web/cookie clients remain compatible.
        $s=Database::connection()->prepare('SELECT ms.*,u.password_hash,u.is_active,(ms.expires_at>NOW()) unexpired FROM mobile_sessions ms JOIN users u ON u.id=ms.user_id WHERE ms.id=:id AND ms.user_id=:user');
        $s->execute(['id'=>$id,'user'=>Auth::id()]);$row=$s->fetch();
        if(!$row || !self::usable($row)){return false;}
        Database::connection()->prepare('UPDATE mobile_sessions SET expires_at=DATE_ADD(NOW(),INTERVAL 30 DAY),last_used_at=NOW() WHERE id=:id AND last_used_at<DATE_SUB(NOW(),INTERVAL 1 HOUR) AND revoked_at IS NULL')->execute(['id'=>$id]);
        return true;
    }

    public static function revokeCurrent(): void
    {
        $id=Session::get('mobile_session_id');
        if($id){Database::connection()->prepare('UPDATE mobile_sessions SET revoked_at=NOW() WHERE id=:id AND user_id=:user')->execute(['id'=>$id,'user'=>Auth::id()]);}
    }
}
