<#
Runs the complete local test suite in a disposable copy of the application and a new MariaDB
data directory. Never reads or changes the site's database.php or connects to an existing DB.
Example: powershell -File tests/run-local.ps1 -MariaDbBin F:\xampp\mysql\bin
Logs and the disposable database are retained under .test-runtime for inspection.
#>
[CmdletBinding()]
param(
    [string]$Php = 'php.exe',
    [string]$MariaDbBin = 'F:\xampp\mysql\bin',
    [string]$Bash = 'C:\Program Files\Git\bin\bash.exe',
    [string]$Node = 'node.exe',
    [string]$Apache = '',
    [string]$PhpRoot = 'F:\xampp\php',
    [switch]$BrowserTest,
    [switch]$Components
)
$ErrorActionPreference = 'Stop'
$project = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$runtime = Join-Path $project ('.test-runtime\local-' + [Guid]::NewGuid().ToString('N'))
$site = Join-Path $runtime 'site'
$data = Join-Path $runtime 'mysql'
$processes = [System.Collections.Generic.List[System.Diagnostics.Process]]::new()
$failures = [System.Collections.Generic.List[string]]::new()
$Php = (Get-Command $Php -ErrorAction Stop).Source
$Bash = (Get-Command $Bash -ErrorAction Stop).Source
if ($BrowserTest -or $Components) {
    $Node = (Get-Command $Node -ErrorAction Stop).Source
    $nodeVersion = & $Node -p 'process.versions.node.split(".")[0]'
    if ($LASTEXITCODE -ne 0 -or [int]$nodeVersion -lt 20) { throw 'BrowserTest needs Node.js 20 or newer. Pass its path using -Node.' }
    & $Node -e 'require("playwright")'
    if ($LASTEXITCODE -ne 0) { throw 'Playwright is not available. Set NODE_PATH to its package directory.' }
}
$server = Join-Path $MariaDbBin 'mysqld.exe'
$installer = Join-Path $MariaDbBin 'mysql_install_db.exe'
foreach ($binary in @($server, $installer)) { if (!(Test-Path -LiteralPath $binary -PathType Leaf)) { throw "Missing MariaDB binary: $binary" } }
$modules = & $Php -m
if ($LASTEXITCODE) { throw 'PHP could not start' }
$phpFlags = @()
foreach ($extension in @('pdo_mysql', 'mbstring', 'curl', 'openssl')) {
    if ($modules -notcontains $extension) { $phpFlags += @('-d', "extension=$extension") }
}
$modules = & $Php @phpFlags -m
foreach ($extension in @('pdo_mysql', 'mbstring', 'curl', 'openssl')) {
    if ($modules -notcontains $extension) { throw "PHP extension $extension is required" }
}

function Write-Utf8([string]$Path, [string]$Text) {
    [IO.File]::WriteAllText($Path, $Text, [Text.UTF8Encoding]::new($false))
}
function Free-TcpPort {
    $listener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback, 0)
    $listener.Start()
    try { return $listener.LocalEndpoint.Port } finally { $listener.Stop() }
}
function Start-Local([string]$Name, [string]$Executable, [string[]]$Arguments) {
    # All paths are passed as arguments, including paths containing spaces.
    $quoted = @($Arguments | ForEach-Object { '"' + $_.Replace('"', '\"') + '"' })
    $p = Start-Process -FilePath $Executable -ArgumentList $quoted -WorkingDirectory $site -WindowStyle Hidden -PassThru `
        -RedirectStandardOutput (Join-Path $runtime "$Name.stdout.log") -RedirectStandardError (Join-Path $runtime "$Name.stderr.log")
    $processes.Add($p)
    return $p
}
function Wait-Tcp([System.Diagnostics.Process]$Process, [int]$Port) {
    $deadline = [DateTime]::UtcNow.AddSeconds(30)
    do {
        if ($Process.HasExited) { throw "Local process exited before port $Port became ready. See logs in $runtime" }
        $client = [Net.Sockets.TcpClient]::new()
        try { $client.Connect('127.0.0.1', $Port); return } catch { Start-Sleep -Milliseconds 150 } finally { $client.Dispose() }
    } while ([DateTime]::UtcNow -lt $deadline)
    throw "Local process did not start on port $Port. See logs in $runtime"
}
function Run-Test([string]$Name, [string]$Executable, [string[]]$Arguments) {
    Write-Output "Running $Name"
    & $Executable @Arguments | Tee-Object -FilePath (Join-Path $runtime "$Name-results.txt")
    if ($LASTEXITCODE -ne 0) { $failures.Add($Name) }
}
function Start-TestWeb {
    if (!$Apache) { return Start-Local 'web' $Php ($phpFlags + @('-d', 'display_errors=1', '-d', 'output_buffering=1048576', '-S', "127.0.0.1:$webPort", '-t', $site)) }
    $apacheExe = (Get-Command $Apache -ErrorAction Stop).Source
    $apacheRoot = (Resolve-Path (Join-Path (Split-Path $apacheExe) '..')).Path.Replace('\','/')
    $phpPath = (Resolve-Path -LiteralPath $PhpRoot).Path.Replace('\','/')
    foreach ($dll in @('php8ts.dll','libpq.dll','libsqlite3.dll','php8apache2_4.dll')) {
        if (!(Test-Path -LiteralPath (Join-Path $PhpRoot $dll))) { throw "Missing Apache PHP module dependency: $dll" }
    }
    $sessionPath = Join-Path $runtime 'sessions'
    New-Item -ItemType Directory -Path $sessionPath -Force | Out-Null
    $conf = @"
ServerRoot "$apacheRoot"
ServerName 127.0.0.1
Listen 127.0.0.1:$webPort
PidFile "$($runtime.Replace('\','/'))/httpd.pid"
ErrorLog "$($runtime.Replace('\','/'))/apache-error.log"
LoadModule authz_core_module modules/mod_authz_core.so
LoadModule authz_host_module modules/mod_authz_host.so
LoadModule alias_module modules/mod_alias.so
LoadModule dir_module modules/mod_dir.so
LoadModule mime_module modules/mod_mime.so
LoadModule setenvif_module modules/mod_setenvif.so
LoadModule proxy_module modules/mod_proxy.so
LoadModule proxy_fcgi_module modules/mod_proxy_fcgi.so
TypesConfig "$apacheRoot/conf/mime.types"
LoadFile "$phpPath/php8ts.dll"
LoadFile "$phpPath/libpq.dll"
LoadFile "$phpPath/libsqlite3.dll"
LoadModule php_module "$phpPath/php8apache2_4.dll"
PHPINIDir "$phpPath"
php_admin_value session.save_path "$($sessionPath.Replace('\','/'))"
php_admin_value upload_tmp_dir "$($runtime.Replace('\','/'))"
<FilesMatch "\.php$">
    SetHandler application/x-httpd-php
</FilesMatch>
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
    $configPath = Join-Path $runtime 'httpd.conf'
    Write-Utf8 $configPath $conf
    & $apacheExe -t -f $configPath
    if ($LASTEXITCODE) { throw 'Isolated Apache configuration is invalid' }
    return Start-Local 'web-apache' $apacheExe @('-X','-f',$configPath)
}

New-Item -ItemType Directory -Path $runtime, $site, $data | Out-Null
# Copy just application files, excluding any installation credentials and all live runtime files.
foreach ($dir in @('assets', 'backend', 'tests', 'vpn')) {
    Get-ChildItem -LiteralPath (Join-Path $project $dir) -Recurse -File | ForEach-Object {
        $relative = $_.FullName.Substring($project.Length + 1)
        if ($relative.Replace('\', '/') -ne 'backend/config/database.php') {
            $destination = Join-Path $site $relative
            New-Item -ItemType Directory -Path (Split-Path $destination) -Force | Out-Null
            Copy-Item -LiteralPath $_.FullName -Destination $destination
        }
    }
}
Get-ChildItem -LiteralPath $project -File | Where-Object { $_.Extension -in @('.php', '.sql', '.sh') -or $_.Name -eq '.htaccess' } | Copy-Item -Destination $site
$dbPort = Free-TcpPort
$webPort = Free-TcpPort
while ($webPort -eq $dbPort) { $webPort = Free-TcpPort }
$oltPort = Free-TcpPort
while ($oltPort -in @($dbPort, $webPort)) { $oltPort = Free-TcpPort }
$acsPort = Free-TcpPort
while ($acsPort -in @($dbPort, $webPort, $oltPort)) { $acsPort = Free-TcpPort }
$udp = [Net.Sockets.UdpClient]::new([Net.IPEndPoint]::new([Net.IPAddress]::Loopback, 0))
$snmpPort = $udp.Client.LocalEndPoint.Port
$udp.Dispose()
$password = [Guid]::NewGuid().ToString('N')
$base = "http://127.0.0.1:$webPort"
$fleet = 'smartolt-local-test-fleet'
$fixtureConfig = @'
<?php
// LOCAL TEST FIXTURE ONLY. Never deploy this file.
class Database {
 public function getConnection() { return new PDO('mysql:host=127.0.0.1;port=DBPORT;dbname=smartolt_test;charset=utf8mb4','root','DBPASS',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); }
 public function getAppUrl() { return 'WEBURL'; }
 public function getSubdomain() { return 'demo'; }
 public function getSecretKey() { return base64_encode(hash('sha256','smartolt-local-test-key',true)); }
 public function getFleetSecret() { return 'smartolt-local-test-fleet'; }
}
'@
Write-Utf8 (Join-Path $site 'backend/config/database.php') ($fixtureConfig.Replace('DBPORT', [string]$dbPort).Replace('DBPASS', $password).Replace('WEBURL', $base))
$reset = @'
<?php
// This file is generated only inside a new isolated local test runtime.
$db = new PDO('mysql:host=127.0.0.1;port=DBPORT','root','DBPASS',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('DROP DATABASE IF EXISTS smartolt_test');
$db->exec('CREATE DATABASE smartolt_test CHARACTER SET utf8mb4');
$db->exec('USE smartolt_test');
$db->exec(file_get_contents(__DIR__.'/site/install.sql'));
echo "Isolated test database reset\n";
'@
Write-Utf8 (Join-Path $runtime 'reset.php') ($reset.Replace('DBPORT', [string]$dbPort).Replace('DBPASS', $password))
try {
    $init = Start-Local 'db-init' $installer @("--datadir=$data", "--password=$password", "--port=$dbPort", '--silent')
    $null = $init.Handle
    $init.WaitForExit()
    if ($init.ExitCode -ne 0) { throw "Isolated database initialization failed. See $runtime" }
    $ini = Join-Path $data 'my.ini'
    Write-Utf8 $ini ("[mysqld]`ndatadir=" + $data.Replace('\', '/') + "`nport=$dbPort`nbind-address=127.0.0.1`n")
    $dbProcess = Start-Local 'mysql' $server @("--defaults-file=$ini", '--bind-address=127.0.0.1', '--console')
    Wait-Tcp $dbProcess $dbPort
    & $Php @phpFlags (Join-Path $runtime 'reset.php')
    if ($LASTEXITCODE) { throw 'Database initialization failed' }
    $web = Start-TestWeb
    $olt = Start-Local 'olt' $Php ($phpFlags + @((Join-Path $site 'tests/fake_olt.php'), [string]$oltPort))
    $snmp = Start-Local 'snmp' $Php ($phpFlags + @((Join-Path $site 'tests/fake_snmp.php'), [string]$snmpPort))
    $acs = Start-Local 'acs' $Php ($phpFlags + @('-S', "127.0.0.1:$acsPort", (Join-Path $site 'tests/fake_acs.php')))
    Wait-Tcp $web $webPort
    Wait-Tcp $olt $oltPort
    Wait-Tcp $acs $acsPort
    Start-Sleep -Milliseconds 300
    if ($snmp.HasExited) { throw 'SNMP fixture failed to start' }
    Run-Test 'api' $Php ($phpFlags + @((Join-Path $site 'tests/api_test.php'), "$base/api.php", '127.0.0.1', [string]$oltPort, 'demo', $fleet, [string]$snmpPort))
    Run-Test 'api-access' $Php ($phpFlags + @((Join-Path $site 'tests/api_access_test.php'), "$base/api.php", 'demo', $fleet))
    Run-Test 'storage' $Php ($phpFlags + @((Join-Path $site 'tests/storage_test.php')))
    Run-Test 'catalog-integrity' $Php ($phpFlags + @((Join-Path $site 'tests/catalog_integrity_test.php')))
    Run-Test 'backend-integrity' $Php ($phpFlags + @((Join-Path $site 'tests/backend_integrity_test.php')))
    Stop-Process -Id $olt.Id
    $olt.WaitForExit()
    & $Php @phpFlags (Join-Path $runtime 'reset.php')
    if ($LASTEXITCODE) { throw 'Test database reset failed' }
    $olt = Start-Local 'olt-web' $Php ($phpFlags + @((Join-Path $site 'tests/fake_olt.php'), [string]$oltPort))
    Wait-Tcp $olt $oltPort
    Run-Test 'web' $Bash @('--noprofile', '--norc', (Join-Path $site 'tests/web_test.sh'), $base, [string]$oltPort, [string]$snmpPort)
    Run-Test 'session' $Php ($phpFlags + @((Join-Path $site 'tests/session_test.php'), $base))
    Run-Test 'acs' $Php ($phpFlags + @((Join-Path $site 'tests/acs_test.php'), "http://127.0.0.1:$acsPort", $base))
    Run-Test 'dashboard' $Php ($phpFlags + @((Join-Path $site 'tests/dashboard_test.php')))
    if ($BrowserTest) {
        # Resolve Playwright through the caller's NODE_PATH and browser through SMARTOLT_BROWSER.
        Run-Test 'browser' $Node @((Join-Path $site 'tests/browser_test.cjs'), $base)
    }
    if ($Components) {
        $env:SMARTOLT_TEST_ACS_URL = "http://127.0.0.1:$acsPort"
        Run-Test 'components' $Node @((Join-Path $site 'tests/browser_components.cjs'), $base)
    }
    foreach ($suite in @('telnet', 'snmp', 'snmp_rates', 'vpn', 'time', 'driver_integrity')) {
        Run-Test $suite $Php ($phpFlags + @((Join-Path $site "tests/${suite}_test.php")))
    }
    Run-Test 'vpn-shell' $Bash @('--noprofile', '--norc', (Join-Path $site 'tests/vpn_test.sh'))
    Run-Test 'vpn-certificates' $Bash @('--noprofile', '--norc', (Join-Path $site 'tests/vpn_certificate_test.sh'))
    Run-Test 'installer' $Bash @('--noprofile', '--norc', (Join-Path $site 'tests/install_test.sh'))
    if ($failures.Count) { throw ('Failed suites: ' + ($failures -join ', ')) }
    Write-Output "All local suites passed. Logs: $runtime"
} finally {
    # Only stop child processes this run started; preserve all files and pre-existing services.
    foreach ($p in $processes) {
        if (!$p.HasExited) { Stop-Process -Id $p.Id -ErrorAction SilentlyContinue; $p.WaitForExit(5000) | Out-Null }
        $p.Dispose()
    }
}
