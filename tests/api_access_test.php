<?php
// HTTP access-control regression; run against the isolated runner fixture.
[$_, $api, $sub, $secret] = $argv + [null, 'http://127.0.0.1:48099/api.php', 'demo', 'smartolt-local-test-fleet'];
if (!in_array(parse_url($api, PHP_URL_HOST), ['127.0.0.1', 'localhost'], true)) throw new RuntimeException('Local test endpoint required.');
$token = hash_hmac('sha256', $sub, $secret);
$fails = 0;
function accessCheck(string $label, bool $ok): void { global $fails; echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL; if (!$ok) $fails++; }
function accessRequest(string $method, array $data, string $authorization = ''): array {
    global $api;
    $headers = ['Content-Type: application/json'];
    if ($authorization !== '') $headers[] = 'Authorization: ' . $authorization;
    $ch = curl_init($api . ($method === 'GET' ? '?' . http_build_query($data) : ''));
    $responseHeaders = '';
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_HTTPHEADER=>$headers, CURLOPT_TIMEOUT=>20,
        CURLOPT_HEADERFUNCTION=>function ($ch, $line) use (&$responseHeaders) { $responseHeaders .= $line; return strlen($line); }]);
    if ($method !== 'GET') curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    $body = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$status, json_decode((string)$body, true), $responseHeaders];
}
[$status, $body, $headers] = accessRequest('GET', ['action'=>'ping'], 'Bearer ' . $token);
accessCheck('authorized GET read remains supported', $status === 200 && !empty($body['ok']));
accessCheck('API responses prohibit caching', stripos($headers, 'Cache-Control: no-store') !== false);
[$status, $body] = accessRequest('POST', ['action'=>'ping'], 'bearer ' . $token);
accessCheck('Bearer scheme is case-insensitive', $status === 200 && !empty($body['ok']));
[$status, $body] = accessRequest('POST', ['action'=>'ping','token'=>$token]);
accessCheck('JSON body token compatibility remains supported', $status === 200 && !empty($body['ok']));
[$status] = accessRequest('GET', ['action'=>'ping','token'=>$token]);
accessCheck('query string cannot carry API credentials', $status === 401);
[$status] = accessRequest('GET', ['action'=>'olt.delete','id'=>1], 'Bearer ' . $token);
accessCheck('GET cannot delete an OLT', $status === 405);
[$status] = accessRequest('GET', ['action'=>'onu.reboot','id'=>1], 'Bearer ' . $token);
accessCheck('GET cannot reboot equipment', $status === 405);
[$status] = accessRequest('PUT', ['action'=>'ping'], 'Bearer ' . $token);
accessCheck('unsupported methods are rejected', $status === 405);
[$status] = accessRequest('POST', ['action'=>'ping','token'=>['invalid']]);
accessCheck('non-string API tokens are rejected cleanly', $status === 401);
[$status] = accessRequest('POST', ['action'=>['olt.delete'],'id'=>1], 'Bearer ' . $token);
accessCheck('non-string actions are rejected cleanly', $status === 400);
[$status] = accessRequest('POST', ['action'=>'olt.delete','id'=>[1]], 'Bearer ' . $token);
accessCheck('array IDs cannot cast into a different target ID', $status === 400);
[$status, $body] = accessRequest('POST', ['action'=>'onu.bulk','ids'=>[-1], 'bulk_action'=>'reboot'], 'Bearer ' . $token);
accessCheck('failed bulk action is not reported as success', $status === 502 && $body['ok'] === false && count($body['results'] ?? []) === 1 && $body['results'][0]['ok'] === false);
$ch = curl_init($api . '?action=olt.delete&id=1');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>'{broken', CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer ' . $token]]);
$body = json_decode((string)curl_exec($ch), true); $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
accessCheck('malformed JSON never falls back to query-string action', $status === 400 && $body['ok'] === false);
exit($fails ? 1 : 0);
