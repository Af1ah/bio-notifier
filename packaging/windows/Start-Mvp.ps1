param([switch]$NoWindow)

$ErrorActionPreference = 'Stop'
$app = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$runtime = Join-Path $app '.desktop'
$php = Join-Path $runtime 'php/php.exe'
$pg = 'C:/Program Files/PostgreSQL/18/bin/pg_ctl.exe'
$gateway = Join-Path $runtime 'device-gateway.exe'
$caddy = (Get-Command caddy.exe).Source

function Start-LocalProcess($name, $exe, $arguments) {
    $pidFile = Join-Path $runtime "$name.pid"
    if (Test-Path -LiteralPath $pidFile) {
        $process = Get-Process -Id ([int](Get-Content -LiteralPath $pidFile -Raw)) -ErrorAction SilentlyContinue
        if ($process -and $process.Path -eq $exe) { return }
    }

    $options = @{
        FilePath = $exe
        WorkingDirectory = $app
        WindowStyle = 'Hidden'
        RedirectStandardOutput = (Join-Path $runtime "$name.out.log")
        RedirectStandardError = (Join-Path $runtime "$name.err.log")
        PassThru = $true
    }
    if ($arguments) { $options.ArgumentList = $arguments }
    $process = Start-Process @options
    Set-Content -LiteralPath $pidFile -Value $process.Id
}

foreach ($file in @($php, $pg, $gateway, "$app/.env", "$app/vendor/autoload.php", "$app/public/build/manifest.json",
        "$runtime/gateway.env")) {
    if (!(Test-Path -LiteralPath $file)) { throw "Missing MVP prerequisite: $file" }
}

& $pg -D "$runtime/pg" status *> $null
if ($LASTEXITCODE -ne 0) {
    & $pg -D "$runtime/pg" -l "$runtime/postgres.log" -o '-h 127.0.0.1 -p 55432' -w start
    if ($LASTEXITCODE -ne 0) { throw 'Local PostgreSQL did not start.' }
}

@'
{
    admin off
    auto_https off
    storage file_system {
        root .desktop/caddy-storage
    }
}

http://127.0.0.1:8787 {
    bind 127.0.0.1
    root * ./public
    php_fastcgi 127.0.0.1:8788 127.0.0.1:8789 127.0.0.1:8790 {
        lb_policy least_conn
    }
    file_server
}
'@ | Set-Content -LiteralPath "$runtime/Caddyfile"

$phpCgi = Join-Path $runtime 'php/php-cgi.exe'
Start-LocalProcess 'php-cgi' $phpCgi @('-b', '127.0.0.1:8788')
foreach ($port in 8789, 8790) {
    Start-LocalProcess "php-cgi-$port" $phpCgi @('-b', "127.0.0.1:$port")
}
Start-LocalProcess 'http' $caddy @('run', '--config', '.desktop/Caddyfile', '--adapter', 'caddyfile')

$savedEnvironment = @{}
try {
    foreach ($line in Get-Content -LiteralPath "$runtime/gateway.env") {
        $name, $value = $line -split '=', 2
        if ($name -notmatch '^[A-Z][A-Z0-9_]+$') { throw 'Invalid gateway environment setting.' }
        $savedEnvironment[$name] = [Environment]::GetEnvironmentVariable($name)
        [Environment]::SetEnvironmentVariable($name, $value)
    }
    Start-LocalProcess 'gateway' $gateway @()
} finally {
    foreach ($name in $savedEnvironment.Keys) {
        [Environment]::SetEnvironmentVariable($name, $savedEnvironment[$name])
    }
}

Start-LocalProcess 'queue' $php @('artisan', 'queue:work', 'database', '--sleep=1')
Start-LocalProcess 'device-queue' $php @('artisan', 'queue:work', 'gateway', '--queue=device-ingest,device-commands,attendance-live,exports-notifications', '--sleep=1')
Start-LocalProcess 'schedule' $php @('artisan', 'schedule:work')

if (!$NoWindow) {
    $url = 'http://127.0.0.1:8787/company/admin'
    $deadline = [DateTime]::UtcNow.AddSeconds(30)
    do {
        try {
            Invoke-WebRequest $url -UseBasicParsing -TimeoutSec 3 | Out-Null
            break
        } catch {
            if ([DateTime]::UtcNow -ge $deadline) { throw "Bio-Notifier did not start. See logs in $runtime." }
            Start-Sleep -Seconds 1
        }
    } while ($true)
    $edge = Join-Path ${env:ProgramFiles(x86)} 'Microsoft/Edge/Application/msedge.exe'
    if (Test-Path -LiteralPath $edge) { Start-Process -FilePath $edge -ArgumentList "--app=$url" }
    else { Start-Process $url }
}
