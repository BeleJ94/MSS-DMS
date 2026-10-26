<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Minimal SMTP client (no Composer dependency).
 * MAIL_DRIVER=smtp sends through MAIL_HOST; MAIL_DRIVER=log writes messages to storage/logs/mail.log.
 */
final class Mailer
{
    private $socket;
    private $stage = 'connexion';

    /** @param array<int,array{name:string,mime:string,data:string}> $attachments */
    public static function send(string $toAddress, string $toName, string $subject, string $text, array $attachments = [], ?array $settings = null, ?string $html = null): void
    {
        $settings = $settings ?? MailSettings::current();
        if (!filter_var($toAddress, FILTER_VALIDATE_EMAIL)) { throw new RuntimeException('Adresse e-mail du destinataire invalide.'); }
        $from = $settings['MAIL_FROM_ADDRESS'];
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) { throw new RuntimeException('MAIL_FROM_ADDRESS n’est pas configurée.'); }
        $message = self::build($from, $settings['MAIL_FROM_NAME'], $toAddress, $toName, $subject, $text, $attachments, $html);

        $driver = mb_strtolower($settings['MAIL_DRIVER']);
        if ($driver === 'log') {
            if (@file_put_contents(BASE_PATH.'/storage/logs/mail.log', '==== '.date('Y-m-d H:i:s').' → '.$toAddress."\r\n".$message."\r\n\r\n", FILE_APPEND | LOCK_EX) === false) {
                throw new RuntimeException('Simulation impossible : le journal mail.log ne peut pas être écrit. Vérifiez les droits d’écriture de PHP sur storage/logs et l’espace disque disponible. Aucun e-mail n’a été envoyé.');
            }
            return;
        }
        if ($driver !== 'smtp') { throw new RuntimeException('MAIL_DRIVER doit valoir smtp ou log.'); }
        (new self())->deliver($from, $toAddress, $message, $settings);
    }

    private function deliver(string $from, string $to, string $message, array $settings): void
    {
        $host = $settings['MAIL_HOST'];
        $port = (int) $settings['MAIL_PORT'];
        $encryption = mb_strtolower($settings['MAIL_ENCRYPTION']);
        if ($host === '') { throw new RuntimeException('MAIL_HOST n’est pas configuré.'); }

        $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://').$host.':'.$port;
        $this->socket = @stream_socket_client($remote, $errno, $error, 20, STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => ['peer_name' => $host]]));
        if (!$this->socket) { throw new RuntimeException('Impossible de joindre le serveur SMTP '.$host.' sur le port '.$port.' ('.$encryption.'). Vérifiez le nom du serveur, le port et le chiffrement auprès du fournisseur. Vérifiez aussi que le pare-feu autorise les connexions SMTP sortantes. Avec SSL/TLS, vérifiez la validité du certificat du serveur.'); }
        stream_set_timeout($this->socket, 30);
        try {
            $this->expect(220);
            $helo = gethostname() ?: 'localhost';
            $this->command('EHLO '.$helo, 250, 'présentation au serveur');
            if ($encryption === 'tls') {
                $this->command('STARTTLS', 220, 'activation du chiffrement STARTTLS');
                if (!@stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) { throw new RuntimeException('La connexion est établie, mais la négociation TLS a échoué. Vérifiez le couple port/chiffrement (souvent 587 avec STARTTLS), le certificat du serveur et les certificats de confiance installés pour PHP.'); }
                $this->command('EHLO '.$helo, 250);
            }
            $username = $settings['MAIL_USERNAME'];
            if ($username !== '') {
                $this->command('AUTH LOGIN', 334, 'authentification');
                $this->command(base64_encode($username), 334);
                $this->command(base64_encode($settings['MAIL_PASSWORD']), 235);
            }
            $this->command('MAIL FROM:<'.$from.'>', 250, 'validation de l’expéditeur');
            $this->command('RCPT TO:<'.$to.'>', [250, 251], 'validation du destinataire');
            $this->command('DATA', 354, 'transmission du message');
            // Dot-stuffing (RFC 5321 §4.5.2).
            $this->command(preg_replace('/^\./m', '..', $message)."\r\n.", 250);
            // The message is already accepted: a disconnect during QUIT must not trigger a duplicate retry.
            @fwrite($this->socket, "QUIT\r\n");
        } finally {
            fclose($this->socket);
        }
    }

    /** @param int|int[] $expected */
    private function command(string $line, $expected, ?string $stage = null): string
    {
        if($stage!==null){$this->stage=$stage;}
        if(@fwrite($this->socket, $line."\r\n")===false){throw new RuntimeException('La connexion SMTP a été interrompue pendant l’étape « '.$this->stage.' ». Vérifiez la disponibilité du serveur avant de réessayer.');}
        return $this->expect($expected);
    }

    /** @param int|int[] $expected */
    private function expect($expected): string
    {
        $response = '';
        while (($line = @fgets($this->socket, 1024)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') { break; }
        }
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, (array) $expected, true)) {
            if($code===0){$meta=stream_get_meta_data($this->socket);throw new RuntimeException(($meta['timed_out']?'Le serveur SMTP n’a pas répondu dans le délai prévu':'Le serveur SMTP a fermé la connexion').' pendant l’étape « '.$this->stage.' ». Vérifiez le port, le chiffrement et la disponibilité du serveur.');}
            if($code===530){$help='Le serveur exige une authentification ou une connexion chiffrée avant l’envoi. Vérifiez le chiffrement, l’identifiant SMTP et le mot de passe selon les instructions du fournisseur.';}
            elseif($this->stage==='authentification'){$help='L’authentification SMTP a été refusée. Vérifiez l’identifiant complet, le mot de passe et l’activation de l’accès SMTP. Si votre fournisseur l’exige, utilisez un mot de passe d’application.';}
            elseif($this->stage==='validation de l’expéditeur'){$help='Le serveur refuse l’adresse de l’expéditeur. Utilisez une adresse autorisée pour ce compte SMTP ou faites valider votre domaine par le fournisseur.';}
            elseif($this->stage==='validation du destinataire'){$help='Le serveur refuse le destinataire. Vérifiez son adresse et que ce compte SMTP est autorisé à envoyer vers ce domaine (relais SMTP).';}
            elseif($this->stage==='activation du chiffrement STARTTLS'){$help='Le serveur refuse STARTTLS. Vérifiez le mode de chiffrement et le port fournis par votre hébergeur.';}
            else{$help='Le serveur refuse cette étape. Vérifiez les restrictions d’envoi, le quota du compte et la configuration auprès du fournisseur.';}
            if($code>=400 && $code<500){$help.=' Le refus est temporaire ; attendez quelques minutes avant de réessayer.';}
            throw new RuntimeException($help.' Code SMTP : '.$code.'. Étape : '.$this->stage.'.', $code);
        }
        return $response;
    }

    private static function build(string $from, string $fromName, string $to, string $toName, string $subject, string $text, array $attachments, ?string $html = null): string
    {
        $domain = substr((string) strrchr($from, '@'), 1) ?: 'localhost';
        $headers = [
            'Date: '.date('r'),
            'From: '.self::address($from, $fromName),
            'To: '.self::address($to, $toName),
            'Subject: '.self::header($subject),
            'Message-ID: <'.bin2hex(random_bytes(12)).'@'.$domain.'>',
            'MIME-Version: 1.0',
        ];
        $body = 'Content-Type: text/plain; charset=UTF-8'."\r\nContent-Transfer-Encoding: base64\r\n\r\n".self::encodeBody($text);
        if ($html !== null) {
            $alternative = 'mss-alt-'.bin2hex(random_bytes(12));
            $body = 'Content-Type: multipart/alternative; boundary="'.$alternative.'"'."\r\n\r\n"
                .'--'.$alternative."\r\n".$body
                .'--'.$alternative."\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".self::encodeBody($html)
                .'--'.$alternative."--\r\n";
        }
        if ($attachments === []) {
            return implode("\r\n", $headers)."\r\n".$body;
        }
        $boundary = 'mss-'.bin2hex(random_bytes(12));
        $headers[] = 'Content-Type: multipart/mixed; boundary="'.$boundary.'"';
        $parts = "--{$boundary}\r\n".$body;
        foreach ($attachments as $file) {
            $name = self::header($file['name']);
            $parts .= "--{$boundary}\r\nContent-Type: {$file['mime']}; name=\"{$name}\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"{$name}\"\r\n\r\n".chunk_split(base64_encode($file['data']));
        }
        return implode("\r\n", $headers)."\r\n\r\n".$parts."--{$boundary}--";
    }

    private static function encodeBody(string $body): string
    {
        return chunk_split(base64_encode(str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $body)))) . "\r\n";
    }

    private static function address(string $email, string $name): string
    {
        $name = trim(str_replace(["\r", "\n", '"'], '', $name));
        return $name === '' ? '<'.$email.'>' : self::header($name).' <'.$email.'>';
    }

    private static function header(string $value): string
    {
        $value = str_replace(["\r", "\n"], ' ', $value);
        return preg_match('/[^\x20-\x7E]/', $value) ? '=?UTF-8?B?'.base64_encode($value).'?=' : $value;
    }
}
