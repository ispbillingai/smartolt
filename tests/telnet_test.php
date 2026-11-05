<?php
require_once __DIR__ . '/../backend/lib/Telnet.php';
// This script starts its own local fixture; no OLT or database required.
if (($argv[1] ?? '') === '--server') {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$server) exit(2);
    echo stream_socket_get_name($server, false) . "\n"; flush();
    $socket = stream_socket_accept($server, 5);
    if (!$socket) exit(3);
    stream_set_timeout($socket, 5);
    foreach (["\xff", "\xfb", "\x01User", "\xff\xfa\x18", 'ignored option', "\xff", "\xf0name: "] as $fragment) {
        fwrite($socket, $fragment); usleep(50000);
    }
    // The first response must accept the echo option despite every byte arriving separately.
    $reply = '';
    while (strpos($reply, "\n") === false && !feof($socket)) $reply .= fread($socket, 1);
    if (substr($reply, 0, 3) !== "\xff\xfd\x01") exit(4);
    fwrite($socket, "\r\nOLT#");
    fgets($socket);
    fwrite($socket, "row one\r\n---- More ( Press 'Q' to break ) ----");
    if (fread($socket, 1) !== ' ') exit(5);
    fwrite($socket, "\r\nrow two\r\n{ <cr>|option<K> }: ");
    if (fread($socket, 1) !== "\n") exit(6);
    fwrite($socket, "\r\nOLT#");
    fgets($socket);
    fwrite($socket, "display configuration\r\npassword subscriber-secret\r\nOLT#");
    fgets($socket);
    fwrite($socket, "display after-config\r\nnormal response\r\nOLT#");
    fgets($socket); // graceful quit from close()
    fclose($socket); fclose($server); exit(0);
}
$fails = 0;
function checkTelnet(string $label, bool $ok): void { global $fails; echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n"; if (!$ok) $fails++; }
$process = proc_open([PHP_BINARY, __FILE__, '--server'], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
if (!is_resource($process)) throw new RuntimeException('Could not start Telnet fixture.');
try {
    $address = trim(fgets($pipes[1]));
    $port = (int)substr($address, strrpos($address, ':') + 1);
    $telnet = new Telnet('127.0.0.1', $port, 2, 3);
    $text = $telnet->readUntil('/Username:\s*$/');
    checkTelnet('fragmented option and subnegotiation frames are preserved', $text === 'Username: ');
    $telnet->write("test-secret\n", true);
    $telnet->readUntil(Telnet::PROMPT);
    $telnet->write("display fixture\n");
    $text = $telnet->readUntil(Telnet::PROMPT);
    checkTelnet('paging continues and optional parameter prompt is answered', strpos($text, 'row one') !== false && strpos($text, 'row two') !== false && strpos($text, '<cr>') === false && strpos($text, 'More') === false);
    checkTelnet('password is excluded from transcript', strpos($telnet->transcript(), 'test-secret') === false);
    $sensitive = $telnet->withoutTranscript(function () use ($telnet) { $telnet->write("display configuration\n"); return $telnet->readUntil(Telnet::PROMPT); });
    checkTelnet('config read remains available without leaking credentials to activity log', strpos($sensitive,'subscriber-secret')!==false && strpos($telnet->transcript(),'subscriber-secret')===false && strpos($telnet->transcript(),'display configuration')===false);
    $telnet->write("display after-config\n"); $telnet->readUntil(Telnet::PROMPT);
    checkTelnet('normal transcript recording resumes after configuration read', strpos($telnet->transcript(),'normal response')!==false);
    checkTelnet('terminal escapes and backspaces are removed', Telnet::clean("ab\x08c\x1b[0m\r\n") === "ac\n");
    $telnet->close();
} finally {
    proc_terminate($process);
    foreach ($pipes as $pipe) fclose($pipe);
    proc_close($process);
}
require_once __DIR__ . '/../backend/lib/Huawei.php';
class ScriptedTelnet extends Telnet {
    public array $commands = [];
    private array $responses;
    public function __construct(array $responses) { $this->responses = $responses; }
    public function write(string $text, bool $secret = false): void { $this->commands[] = $text; }
    public function readUntil(string $pattern, ?int $timeout = null): string {
        if (!$this->responses) throw new RuntimeException('Unexpected device read.');
        return array_shift($this->responses);
    }
}
$script = new ScriptedTelnet(["save\nAre you sure? (y/n)[n]:", "\nThe data of save is completed\nOLT(config)#"]);
$driver = new Huawei($script);
checkTelnet('Huawei save answers firmware confirmation', strpos($driver->saveConfig(), 'completed') !== false && $script->commands === ["save\n", "y\n"]);
$driver = new Huawei(new ScriptedTelnet(["display ont info 0 all\nFailure: The required ONT does not exist\nOLT(config)#"]));
checkTelnet('new OLT with no ONUs remains a valid inventory', $driver->onts() === []);
$driver = new Huawei(new ScriptedTelnet(["display ont info 0 all\n% Unknown command\nOLT(config)#"]));
try { $driver->onts(); checkTelnet('failed inventory is never reported as an empty success', false); }
catch (OltError $e) { checkTelnet('failed inventory is never reported as an empty success', strpos($e->getMessage(), 'Unknown command') !== false); }
$inventory="F/S/P ONT SN Control Run Config Match Protect\n0/ 1/0 0 4857544300000001 active online normal match no\nThe total of ONTs are: 2\n";
$driver = new Huawei(new ScriptedTelnet(["display ont info 0 all\n".$inventory."OLT(config)#"]));
try { $driver->onts(); checkTelnet('truncated inventory count fails before reconciliation',false); }
catch (OltError $e) { checkTelnet('truncated inventory count fails before reconciliation',strpos($e->getMessage(),'incomplete')!==false); }
$driver = new Huawei(new ScriptedTelnet(["display ont info 0 all\n".str_replace('ONTs are: 2','ONTs are: 1',$inventory)."OLT(config)#"]));
checkTelnet('complete inventory count is explicitly verified', count($driver->onts())===1 && $driver->inventoryComplete());
exit($fails ? 1 : 0);
