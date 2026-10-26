$ErrorActionPreference = 'Stop'
$projectPath = Split-Path -Parent $PSScriptRoot
$xamppPath = Split-Path -Parent (Split-Path -Parent $projectPath)
$phpPath = Join-Path $xamppPath 'php\php.exe'
$workerPath = Join-Path $PSScriptRoot 'send_notifications.php'
$cachePath = Join-Path $projectPath 'storage\cache'
$logPath = Join-Path $projectPath 'storage\logs\notifications.log'

try {
    if (!(Test-Path -LiteralPath $phpPath -PathType Leaf)) {
        throw "PHP introuvable : $phpPath"
    }
    & $phpPath -d "session.save_path=$cachePath" $workerPath 50 2>&1 |
        Out-File -LiteralPath $logPath -Append -Encoding utf8
    exit $LASTEXITCODE
} catch {
    ('{0:u} Worker notifications : {1}' -f (Get-Date), $_.Exception.Message) |
        Out-File -LiteralPath $logPath -Append -Encoding utf8
    exit 1
}
