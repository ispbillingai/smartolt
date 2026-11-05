# Run the real Apache access rules on loopback with a temporary document root; no service changes.
[CmdletBinding()]
param([string]$Apache = 'F:\xampp\apache\bin\httpd.exe', [string]$PhpRoot = 'F:\xampp\php')
$ErrorActionPreference = 'Stop'
$project = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$work = Join-Path $project ('.test-runtime\apache-' + [guid]::NewGuid().ToString('N'))
$site = Join-Path $work 'site'
New-Item -ItemType Directory -Path $site | Out-Null
$apacheRoot = (Resolve-Path (Join-Path (Split-Path $Apache) '..')).Path.Replace('\','/')
$listener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,0)
$listener.Start(); $port = $listener.LocalEndpoint.Port; $listener.Stop()
Copy-Item -LiteralPath (Join-Path $project '.htaccess') -Destination $site
$paths = @('backend/config/database.php','tests/fake_olt.php','vpn/smartolt-vpn','.test-runtime/credentials.txt','.git/config','install.sql','install.sh','README.md','hidden.ps1','status.awk','.env','.htaccess','nested/backend/config/database.php','nested/.test-runtime/credentials.txt','nested/.git/config','nested/vpn/smartolt-vpn')
foreach ($path in $paths | Where-Object { $_ -ne '.htaccess' }) {
    $file = Join-Path $site $path
    New-Item -ItemType Directory -Path (Split-Path $file) -Force | Out-Null
    [IO.File]::WriteAllText($file, 'PRIVATE-FIXTURE-MUST-NOT-BE-SERVED')
}
[IO.File]::WriteAllText((Join-Path $site 'health.html'),'PUBLIC-HEALTH-OK')
[IO.File]::WriteAllText((Join-Path $site 'health.php'),'<?php echo "PHP-EXECUTED-OK " . PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
$phpConfig = ''
if (Test-Path -LiteralPath (Join-Path $PhpRoot 'php8apache2_4.dll')) {
    $phpPath = $PhpRoot.Replace('\','/')
    $phpConfig = @"
LoadFile "$phpPath/php8ts.dll"
LoadFile "$phpPath/libpq.dll"
LoadFile "$phpPath/libsqlite3.dll"
LoadModule php_module "$phpPath/php8apache2_4.dll"
PHPINIDir "$phpPath"
<FilesMatch "\.php$">
    SetHandler application/x-httpd-php
</FilesMatch>
"@
}
$conf = @"
ServerRoot "$apacheRoot"
ServerName 127.0.0.1
Listen 127.0.0.1:$port
PidFile "$($work.Replace('\','/'))/httpd.pid"
ErrorLog "$($work.Replace('\','/'))/error.log"
LoadModule authz_core_module modules/mod_authz_core.so
LoadModule authz_host_module modules/mod_authz_host.so
LoadModule alias_module modules/mod_alias.so
LoadModule dir_module modules/mod_dir.so
LoadModule setenvif_module modules/mod_setenvif.so
LoadModule proxy_module modules/mod_proxy.so
LoadModule proxy_fcgi_module modules/mod_proxy_fcgi.so
$phpConfig
DocumentRoot "$($site.Replace('\','/'))"
<Directory />
    AllowOverride None
    Require all denied
</Directory>
<Directory "$($site.Replace('\','/'))">
    AllowOverride All
    Require all granted
</Directory>
"@
[IO.File]::WriteAllText((Join-Path $work 'httpd.conf'),$conf,[Text.UTF8Encoding]::new($false))
& $Apache -t -f (Join-Path $work 'httpd.conf')
if ($LASTEXITCODE) { throw 'Apache fixture configuration is invalid' }
$p = Start-Process -FilePath $Apache -ArgumentList @('-X','-f',('"' + (Join-Path $work 'httpd.conf') + '"')) -WindowStyle Hidden -PassThru `
    -RedirectStandardOutput (Join-Path $work 'stdout.log') -RedirectStandardError (Join-Path $work 'stderr.log')
$http = [Net.Http.HttpClient]::new()
$http.Timeout = [TimeSpan]::FromSeconds(3)
try {
    $base = "http://127.0.0.1:$port/"
    $ready = $false
    for ($i=0; $i -lt 30; $i++) {
        if ($p.HasExited) { throw "Apache fixture exited. Logs: $work" }
        try {
            $r = $http.GetAsync($base + 'health.html').GetAwaiter().GetResult()
            if ([int]$r.StatusCode -eq 200) { $ready = $true; $r.Dispose(); break }
            $r.Dispose()
        } catch {}
        Start-Sleep -Milliseconds 100
    }
    if (!$ready) { throw "Apache fixture did not become ready. Logs: $work" }
    Write-Output 'PASS real Apache serves permitted public files'
    if ($phpConfig) {
        $r = $http.GetAsync($base + 'health.php').GetAwaiter().GetResult()
        $body = $r.Content.ReadAsStringAsync().GetAwaiter().GetResult()
        if ([int]$r.StatusCode -ne 200 -or $body -notmatch '^PHP-EXECUTED-OK 8\.') { throw 'Repository PHP handler breaks XAMPP mod_php execution.' }
        $r.Dispose()
        Write-Output "PASS real XAMPP executes PHP through the repository access rules ($body)"
    }
    foreach ($path in $paths) {
        $r = $http.GetAsync($base + $path).GetAwaiter().GetResult()
        $body = $r.Content.ReadAsStringAsync().GetAwaiter().GetResult()
        if ([int]$r.StatusCode -notin @(403,404) -or $body.Contains('PRIVATE-FIXTURE')) { throw "Private path exposed or unexpected HTTP $([int]$r.StatusCode): $path" }
        $r.Dispose()
        Write-Output "PASS real Apache denies $path"
    }
    Write-Output "Apache access checks passed. Logs: $work"
} finally {
    $http.Dispose()
    if (!$p.HasExited) { Stop-Process -Id $p.Id; $p.WaitForExit() }
    $p.Dispose()
}
