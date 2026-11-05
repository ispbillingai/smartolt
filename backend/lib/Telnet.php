<?php
// Minimal Telnet client for OLT command lines. Speaks just enough of the protocol to refuse every
// option the OLT offers, then reads plain text up to the next prompt, paging through "More" screens
// and answering the "{ <cr>|... }:" parameter prompts Huawei shows when a command can take more.

class TelnetError extends RuntimeException {}

class Telnet
{
    private const IAC = "\xFF", DONT = "\xFE", DO = "\xFD", WONT = "\xFC", WILL = "\xFB", SB = "\xFA", SE = "\xF0";

    /** @var resource|null */
    private $sock = null;
    private string $buffer = '';
    private string $negotiation = '';
    private string $log = '';
    private bool $recordTranscript = true;
    private int $commandTimeout;

    // A device prompt: hostname, optional "(config...)" mode, then > or #, at the very end of the output.
    public const PROMPT = '/(?:^|\n)[^\s>#()]{1,64}(?:\([^)\n]*\))?[>#][ \t]*$/';

    public function __construct(string $host, int $port, int $connectTimeout = 8, int $commandTimeout = 25)
    {
        if ($port < 1 || $port > 65535 || $connectTimeout < 1 || $commandTimeout < 1) throw new InvalidArgumentException('Invalid Telnet connection settings.');
        $this->commandTimeout = $commandTimeout;
        $errno = 0;
        $errstr = '';
        $endpoint = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[{$host}]" : $host;
        $sock = @stream_socket_client("tcp://{$endpoint}:{$port}", $errno, $errstr, $connectTimeout);
        if (!$sock) {
            throw new TelnetError("Could not reach {$host}:{$port} ({$errstr})");
        }
        stream_set_blocking($sock, false);
        $this->sock = $sock;
    }

    public function __destruct()
    {
        $this->close();
    }

    public function close(): void
    {
        if ($this->sock) {
            @fwrite($this->sock, "quit\n");
            @fclose($this->sock);
            $this->sock = null;
        }
    }

    /** Everything sent and received, for the action log. Passwords are never written to it. */
    public function transcript(): string
    {
        return $this->log;
    }

    /** Device configurations may contain subscriber credentials; never copy them to action logs. */
    public function withoutTranscript(callable $read)
    {
        $previous = $this->recordTranscript;
        $this->recordTranscript = false;
        try { return $read(); }
        finally { $this->recordTranscript = $previous; }
    }

    public function write(string $text, bool $secret = false): void
    {
        if ($this->recordTranscript) $this->log .= $secret ? "********\n" : $text;
        $deadline = microtime(true) + $this->commandTimeout;
        while ($text !== '') {
            $written = @fwrite($this->sock, $text);
            if ($written === false || feof($this->sock)) throw new TelnetError('The OLT closed the connection.');
            if ($written === 0) {
                if (microtime(true) > $deadline) throw new TelnetError('The OLT did not accept the command in time.');
                usleep(10000);
            } else {
                $text = substr($text, $written);
            }
        }
    }

    /**
     * Read until $pattern matches the accumulated output. Pages through "More" screens and answers
     * parameter prompts on the way. Returns everything read, with paging noise removed.
     */
    public function readUntil(string $pattern, ?int $timeout = null): string
    {
        $deadline = microtime(true) + ($timeout ?? $this->commandTimeout);
        while (true) {
            $this->fill();
            if (preg_match('/-+ ?More ?\([^)]*\) ?-+/', $this->buffer)) {
                $this->buffer = preg_replace('/-+ ?More ?\([^)]*\) ?-+/', '', $this->buffer);
                $this->write(' ');
                continue;
            }
            $clean = self::clean($this->buffer);
            if (preg_match($pattern, $clean)) {
                $this->buffer = '';
                if ($this->recordTranscript) $this->log .= $clean;
                return $clean;
            }
            // "{ <cr>|ontid<U><0,127> }:" means the command line would accept more; Enter takes it as typed.
            if (preg_match('/\{[^{}\n]*<cr>[^{}\n]*\}:\s*$/', $clean)) {
                $this->buffer = preg_replace('/\{[^{}\n]*<cr>[^{}\n]*\}:\s*$/', '', $clean);
                $this->write("\n");
                continue;
            }
            if (microtime(true) > $deadline) {
                if ($this->recordTranscript) $this->log .= $clean;
                throw new TelnetError('The OLT did not answer in time.');
            }
            usleep(30000);
        }
    }

    /** Pull whatever has arrived, answering Telnet option negotiation and stripping it from the text. */
    private function fill(): void
    {
        $data = @fread($this->sock, 65536);
        if ($data === false || ($data === '' && feof($this->sock))) {
            throw new TelnetError('The OLT closed the connection.');
        }
        if ($data === '') {
            return;
        }
        // Negotiations may be split at any TCP packet boundary, even between IAC and its option.
        $data = $this->negotiation . $data;
        $this->negotiation = '';
        $out = '';
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $c = $data[$i];
            if ($c !== self::IAC) {
                $out .= $c;
                continue;
            }
            $start = $i;
            if ($i + 1 >= $len) { $this->negotiation = substr($data, $start); break; }
            $cmd = $data[++$i];
            if ($cmd === self::DO || $cmd === self::DONT || $cmd === self::WILL || $cmd === self::WONT) {
                if ($i + 1 >= $len) { $this->negotiation = substr($data, $start); break; }
                $opt = $data[++$i];
                if ($cmd === self::DO) {
                    @fwrite($this->sock, self::IAC . self::WONT . $opt);
                } elseif ($cmd === self::WILL) {
                    // Let the OLT echo and suppress go-ahead (options 1 and 3); refuse anything else.
                    $answer = ($opt === "\x01" || $opt === "\x03") ? self::DO : self::DONT;
                    @fwrite($this->sock, self::IAC . $answer . $opt);
                }
            } elseif ($cmd === self::SB) {
                $end = strpos($data, self::IAC . self::SE, $i);
                if ($end === false) {
                    $this->negotiation = substr($data, $start);
                    if (strlen($this->negotiation) > 65536) throw new TelnetError('The OLT sent an invalid Telnet negotiation.');
                    break;
                }
                $i = $end + 1;
            } elseif ($cmd === self::IAC) {
                $out .= self::IAC;
            }
        }
        $this->buffer .= $out;
    }

    /** Remove cursor movement codes, carriage returns and the backspaces some OLTs send after paging. */
    public static function clean(string $s): string
    {
        $s = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $s);
        $s = str_replace("\r", '', $s);
        while (strpos($s, "\x08") !== false) {
            $s = preg_replace('/[^\x08\n]\x08|^\x08/', '', $s, -1, $n);
            if (!$n) {
                $s = str_replace("\x08", '', $s);
            }
        }
        return $s;
    }
}
