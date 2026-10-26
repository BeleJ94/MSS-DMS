// Local SMTP simulator: no external server, credentials or real e-mail.
const net = require('net');
const { spawn } = require('child_process');
const path = require('path');

async function scenario(failure) {
    let message = '';
    const server = net.createServer(socket => {
        socket.setEncoding('utf8');
        socket.write('220 local test\r\n');
        let buffer = '', data = false;
        socket.on('error', () => {});
        socket.on('data', chunk => {
            buffer += chunk;
            let end;
            while ((end = buffer.indexOf('\r\n')) >= 0) {
                const line = buffer.slice(0, end); buffer = buffer.slice(end + 2);
                if (data) {
                    if (line === '.') { data = false; socket.write('250 message accepted\r\n'); }
                    else { message += line + '\r\n'; }
                } else if (line.startsWith('EHLO')) socket.write('250 local test\r\n');
                else if (line.startsWith('MAIL FROM:')) socket.write('250 sender accepted\r\n');
                else if (line === 'AUTH LOGIN') socket.write('535 authentication failed\r\n');
                else if (line === 'STARTTLS') socket.write('454 TLS unavailable\r\n');
                else if (line.startsWith('RCPT TO:')) socket.write(failure === 'recipient' ? '550 recipient rejected\r\n' : '250 recipient accepted\r\n');
                else if (line === 'DATA') { data = true; socket.write('354 enter message\r\n'); }
                else if (line === 'QUIT') socket.end('221 goodbye\r\n');
                else socket.write('500 unexpected command\r\n');
            }
        });
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    try {
        const port = server.address().port;
        const php = `<?php
define('BASE_PATH',getcwd());
require 'app/Core/Env.php'; require 'app/Core/MailSettings.php'; require 'app/Core/Mailer.php';
$settings=array_replace(App\\Core\\MailSettings::DEFAULTS,['MAIL_DRIVER'=>'smtp','MAIL_HOST'=>'127.0.0.1','MAIL_PORT'=>'${port}','MAIL_ENCRYPTION'=>'${failure === 'tls' ? 'tls' : 'none'}','MAIL_USERNAME'=>'${failure === 'auth' ? 'smtp-user' : ''}','MAIL_PASSWORD'=>'smtp-test-secret','MAIL_FROM_ADDRESS'=>'sender@example.com']);
try { App\\Core\\Mailer::send('recipient@example.com','','SMTP test','Test content',[],$settings); echo 'SMTP_ACCEPTED'; }
catch(Throwable $e) { echo 'SMTP_REJECTED: '.$e->getMessage(); exit(2); }
`;
        const result = await new Promise((resolve, reject) => {
            const child = spawn('php', [], { cwd: path.resolve(__dirname, '..'), windowsHide: true });
            let output = '';
            child.stdout.on('data', chunk => output += chunk);
            child.stderr.on('data', chunk => output += chunk);
            child.on('error', reject);
            const timer = setTimeout(() => { child.kill(); reject(new Error('SMTP test timed out')); }, 10000);
            child.on('close', code => { clearTimeout(timer); resolve({ code, output }); });
            child.stdin.end(php);
        });
        if (failure === 'auth') {
            if (result.code !== 2 || !result.output.includes('Code SMTP : 535') || !result.output.includes('authentification SMTP a été refusée') || result.output.includes('smtp-test-secret')) throw new Error(result.output);
            console.log('OK - un refus d’authentification explique les identifiants à vérifier sans révéler le secret');
        } else if (failure === 'tls') {
            if (result.code !== 2 || !result.output.includes('Code SMTP : 454') || !result.output.includes('Le serveur refuse STARTTLS')) throw new Error(result.output);
            console.log('OK - un refus STARTTLS explique le chiffrement à vérifier');
        } else if (failure === 'recipient') {
            if (result.code !== 2 || !result.output.includes('Code SMTP : 550') || !result.output.includes('Le serveur refuse le destinataire')) throw new Error(result.output);
            console.log('OK - un refus SMTP est remonté comme erreur');
        } else {
            if (result.code !== 0 || !result.output.includes('SMTP_ACCEPTED') || !message.includes('From: MSS-DMS <sender@example.com>') || !message.includes('To: <recipient@example.com>')) throw new Error(result.output);
            console.log('OK - le test transmet un message au serveur SMTP avec les paramètres saisis');
        }
    } finally { await new Promise(resolve => server.close(resolve)); }
}

(async () => { await scenario(null); await scenario('recipient'); await scenario('auth'); await scenario('tls'); console.log('MAIL_SMTP_OK'); })().catch(error => { console.error(error); process.exitCode = 1; });
