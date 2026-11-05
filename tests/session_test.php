<?php
require_once __DIR__.'/../backend/config/database.php';
$base=rtrim((new Database())->getAppUrl(),'/');
if (parse_url($base,PHP_URL_HOST)!=='127.0.0.1' || !str_contains(file_get_contents(__DIR__.'/../backend/config/database.php'),'LOCAL TEST FIXTURE ONLY')) throw new RuntimeException('Local fixture only.');
$fails=0;
function cookieCheck(string $label,bool $ok): void { global $fails;echo ($ok?'PASS ':'FAIL ').$label."\n";if(!$ok)$fails++; }
function loginHeaders(bool $remember): array {
    global $base;
    $jar=tempnam(sys_get_temp_dir(),'olt-session-');$headers=[];
    $c=curl_init($base.'/index.php');
    curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEJAR=>$jar,CURLOPT_COOKIEFILE=>$jar,CURLOPT_POSTFIELDS=>http_build_query(['username'=>'admin','password'=>'Fibre-Test-2026']+($remember?['remember_me'=>'1']:[])),CURLOPT_HEADERFUNCTION=>function($c,$h)use(&$headers){$headers[]=$h;return strlen($h);},CURLOPT_TIMEOUT=>10]);
    curl_exec($c);$code=curl_getinfo($c,CURLINFO_HTTP_CODE);curl_close($c);
    return [$headers,$code,$jar];
}
foreach([false,true] as $remember){
    [$headers,$code,$jar]=loginHeaders($remember);
    $cookies=array_values(array_filter($headers,fn($h)=>str_starts_with(strtolower($h),'set-cookie: phpsessid=')));
    $last=end($cookies)?:'';
    cookieCheck($remember?'Remember me creates a persistent session cookie':'Unchecked Remember me uses a browser-session cookie',$code===302 && ($remember?stripos($last,'expires=')!==false:stripos($last,'expires=')===false));
    cookieCheck('Login cookie is HTTP-only and same-site',stripos($last,'HttpOnly')!==false && stripos($last,'SameSite=Strict')!==false);
    $c=curl_init($base.'/logout.php');$headers=[];
    curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$jar,CURLOPT_COOKIEJAR=>$jar,CURLOPT_HEADERFUNCTION=>function($c,$h)use(&$headers){$headers[]=$h;return strlen($h);},CURLOPT_TIMEOUT=>10]);curl_exec($c);curl_close($c);
    cookieCheck('Logout expires the cookie using the local connection scheme',(bool)array_filter($headers,fn($h)=>str_starts_with(strtolower($h),'set-cookie: phpsessid=') && stripos($h,'Max-Age=0')!==false && stripos($h,'; secure')===false));
    unlink($jar);
}
exit($fails?1:0);
