# Actual OpenVPN TLS/authentication on loopback using dev=null: no network adapter or routes.
[CmdletBinding()]
param(
    [string]$OpenVpn = 'C:\Program Files\OpenVPN\bin\openvpn.exe',
    [string]$Bash = 'C:\Program Files\Git\bin\bash.exe',
    [string]$Php = 'C:\PHP\php.exe'
)
$ErrorActionPreference = 'Stop'
if (!(Test-Path -LiteralPath $OpenVpn -PathType Leaf)) { throw 'Pass -OpenVpn with the path to an OpenVPN 2.7 or newer executable.' }
$vpnVersion = (& $OpenVpn --version 2>&1 | Select-Object -First 1) -join ''
if ($vpnVersion -notmatch 'OpenVPN\s+(\d+)\.(\d+)' -or ([int]$Matches[1] -lt 2) -or ([int]$Matches[1] -eq 2 -and [int]$Matches[2] -lt 7)) {
    throw 'This adapter-free server test requires OpenVPN 2.7 or newer. Pass -OpenVpn to that executable; the test does not install drivers or services.'
}
$project = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$work = Join-Path $project ('.test-runtime\openvpn-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $work, (Join-Path $work 'ccd') | Out-Null
$unix = $work.Replace('\','/')
function Save([string]$Name, [string]$Text) { [IO.File]::WriteAllText((Join-Path $work $Name), $Text, [Text.UTF8Encoding]::new($false)) }
$oldConversion = $env:MSYS2_ARG_CONV_EXCL
try {
    $env:MSYS2_ARG_CONV_EXCL = '/CN='
    & $Bash --noprofile --norc (Join-Path $project 'vpn/issue_server_certificate.sh') "$unix/pki" 'vpn.smartolt.test'
    if ($LASTEXITCODE) { throw 'Certificate setup failed' }
} finally { $env:MSYS2_ARG_CONV_EXCL = $oldConversion }
$hash = [Convert]::ToHexString([Security.Cryptography.SHA256]::HashData([Text.Encoding]::UTF8.GetBytes('test-password'))).ToLowerInvariant()
Save 'users' "demo-olt1 $hash`n"
Save 'ccd/demo-olt1' "ifconfig-push 10.253.1.1 255.255.0.0`n"
$auth = [IO.File]::ReadAllText((Join-Path $project 'vpn/smartolt-auth.sh')).Replace('/var/lib/smartolt-vpn/users', "$unix/users")
Save 'auth.sh' $auth
$wrapper = @'
<?php
$p = proc_open(['BASH', 'AUTH', $argv[1]], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
if (!is_resource($p)) exit(1);
foreach ($pipes as $pipe) fclose($pipe);
exit(proc_close($p));
'@
Save 'auth.php' ($wrapper.Replace('BASH',$Bash.Replace('\','/')).Replace('AUTH',"$unix/auth.sh"))
function Run-Handshake([string]$Name, [string]$Password, [string]$ExpectedName, [bool]$Accept) {
    $listener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,0)
    $listener.Start(); $port = $listener.LocalEndpoint.Port; $listener.Stop()
    Save "$Name.credentials" "demo-olt1`n$Password`n"
    $common = @"
dev null
disable-dco
ifconfig-noexec
route-noexec
auth SHA1
cipher AES-256-CBC
data-ciphers AES-256-CBC
data-ciphers-fallback AES-256-CBC
tls-version-min 1.2
verb 3
hand-window 8
tls-exit
"@
    Save "$Name.server.ovpn" @"
$common
proto tcp-server
local 127.0.0.1
port $port
tls-server
mode server
topology subnet
server 10.253.0.0 255.255.0.0 nopool
client-config-dir "$unix/ccd"
ccd-exclusive
dh none
ca "$unix/pki/ca.crt"
cert "$unix/pki/issued/server.crt"
key "$unix/pki/private/server.key"
verify-client-cert none
username-as-common-name
script-security 2
auth-user-pass-verify "'$($Php.Replace('\','/'))' '$unix/auth.php'" via-file
"@
    Save "$Name.client.ovpn" @"
$common
proto tcp-client
connect-retry-max 1
connect-timeout 3
remote 127.0.0.1 $port
nobind
tls-client
pull
ca "$unix/pki/ca.crt"
remote-cert-tls server
verify-x509-name "$ExpectedName" name
auth-user-pass "$unix/$Name.credentials"
auth-nocache
"@
    $children = @()
    try {
        foreach ($role in @('server','client')) {
            $process = Start-Process -FilePath $OpenVpn -ArgumentList @('--config', "`"$work/$Name.$role.ovpn`"") -WindowStyle Hidden -PassThru `
                -RedirectStandardOutput "$work/$Name.$role.log" -RedirectStandardError "$work/$Name.$role.err"
            $children += $process
            if ($role -eq 'server') { Start-Sleep -Milliseconds 500 }
        }
        $until = [DateTime]::UtcNow.AddSeconds(15)
        do {
            $log = Get-Content -LiteralPath "$work/$Name.client.log" -Raw -ErrorAction SilentlyContinue
            if ($log -match 'Initialization Sequence Completed|AUTH_FAILED|VERIFY ERROR|VERIFY X509NAME ERROR|Options error') { break }
            if ($children[1].HasExited) { break }
            Start-Sleep -Milliseconds 100
        } while ([DateTime]::UtcNow -lt $until)
        $connected = $log -match 'Initialization Sequence Completed'
        if ($Accept -and !$connected) { throw "$Name did not connect. Logs: $work" }
        if (!$Accept -and ($connected -or $log -notmatch 'AUTH_FAILED|VERIFY ERROR|VERIFY X509NAME ERROR')) { throw "$Name did not prove authentication rejection. Logs: $work" }
        Write-Output "PASS real OpenVPN $Name"
    } finally {
        foreach ($process in $children) { if (!$process.HasExited) { Stop-Process -Id $process.Id; $process.WaitForExit() }; $process.Dispose() }
    }
}
Run-Handshake 'trusted-server-correct-password' 'test-password' 'vpn.smartolt.test' $true
Run-Handshake 'wrong-password-rejected' 'wrong-password' 'vpn.smartolt.test' $false
Run-Handshake 'wrong-server-name-rejected' 'test-password' 'attacker.invalid' $false
Write-Output "OpenVPN TLS/authentication checks passed; dev=null does not test routed traffic. Logs: $work"
