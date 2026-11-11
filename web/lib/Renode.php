<?php

final class MonitorTimeout extends RuntimeException
{
}

/** Telnet monitor client for the local Renode S1C31W74 process. */
final class Renode
{
    public string $root;
    public string $runtime;
    public string $exe;
    public int $port = 21324;

    private const FLASH_SIZE = 0x80000;
    private const PIN_INJECT = 0x40002FF0;
    private const BUTTON_PORT = 2;
    private const BUTTON_MASK = 0x1F;

    public function __construct()
    {
        $this->root = dirname(__DIR__, 2);
        $local = getenv('LOCALAPPDATA') ?: getenv('TEMP');
        $this->runtime = $local . DIRECTORY_SEPARATOR . 's1c31-tools' . DIRECTORY_SEPARATOR . 'runtime';
        $this->exe = $local . DIRECTORY_SEPARATOR . 's1c31-tools' . DIRECTORY_SEPARATOR
            . 'renode' . DIRECTORY_SEPARATOR . 'renode_1.17.0-portable' . DIRECTORY_SEPARATOR . 'renode.exe';
        if (!is_dir($this->runtime)) {
            mkdir($this->runtime, 0777, true);
        }
    }

    public function meta(): array
    {
        $this->ensure();
        $session = $this->session();
        $examples = [];
        foreach ($this->examples() as $id => $ex) {
            $examples[] = [
                'id' => $id,
                'label' => $ex['label'],
                'available' => is_file($this->root . DIRECTORY_SEPARATOR . $ex['file']),
            ];
        }
        return [
            'firmware' => $session['title'] ?? $session['label'] ?? null,
            'kind' => $session['kind'] ?? null,
            'addr' => $session['addr'] ?? 0,
            'examples' => $examples,
            'renode' => is_file($this->exe),
        ];
    }

    public function snapshot(): array
    {
        $this->ensure();
        $snap = $this->slash($this->runtime . DIRECTORY_SEPARATOR . 'snapshot.py');
        $text = $this->command('python "execfile(r\'' . $snap . '\')"');
        $kv = [];
        foreach (preg_split('/\R/', $text) as $line) {
            if (preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', trim($line), $m)) {
                $kv[$m[1]] = $m[2];
            }
        }
        if (!isset($kv['PC']) && !isset($kv['P0DAT'])) {
            throw new RuntimeException('snapshot failed: ' . trim($text));
        }
        $session = $this->session();
        $kv['FIRMWARE'] = $session['title'] ?? $session['label'] ?? '';
        return $kv;
    }

    public function control(string $op, int $ms = 100, int $steps = 1): array
    {
        $this->ensure();
        // "cpu Step" leaves the core in SingleStep mode. RunFor/start in that
        // mode wait for step commands forever, so every path that lets time
        // run puts the core back to Continuous while it is still paused.
        if ($op === 'start') {
            $this->command('pause');
            $this->continuous();
            $this->command('start');
        } elseif ($op === 'pause') {
            $this->command('pause');
        } elseif ($op === 'step') {
            $n = max(1, min(100000, $steps));
            $this->command('pause');
            $stepped = $this->command('cpu Step ' . $n, 30);
            if (stripos($stepped, 'error') !== false || stripos($stepped, 'exception') !== false) {
                throw new RuntimeException(trim($stepped));
            }
            $this->command('pause');
        } elseif ($op === 'runfor') {
            $sec = max(1, min(5000, $ms)) / 1000;
            $this->command('pause');
            $this->continuous();
            $this->command(sprintf('emulation RunFor "%g"', $sec), 60);
        } elseif ($op === 'reset') {
            $this->reload(false);
        } elseif ($op === 'restart') {
            $this->kill();
            $this->ensure();
        } elseif ($op === 'wipe-eeprom') {
            // The model only reads the file when the machine is created.
            $this->kill();
            @unlink($this->runtime . DIRECTORY_SEPARATOR . 'eeprom.bin');
            $this->ensure();
        } else {
            throw new InvalidArgumentException('unknown control');
        }
        return $this->snapshot();
    }

    public function loadExample(string $id, bool $run): array
    {
        $examples = $this->examples();
        if (!isset($examples[$id])) {
            throw new InvalidArgumentException('unknown example');
        }
        $src = $this->root . DIRECTORY_SEPARATOR . $examples[$id]['file'];
        if (!is_file($src)) {
            throw new RuntimeException($examples[$id]['file'] . ' is not built yet');
        }
        $this->ensure();
        $this->installImage($src, 'elf', 0, $examples[$id]['label'], $run);
        return $this->snapshot();
    }

    public function loadUpload(string $tmp, string $orig, int $addr, bool $run, string $boot = 'app'): array
    {
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!in_array($ext, ['elf', 'bin', 'hex'], true)) {
            throw new InvalidArgumentException('want .elf, .bin or .hex');
        }
        $size = filesize($tmp);
        if ($size === false || $size < 16 || $size > 2 * 1024 * 1024) {
            throw new InvalidArgumentException('firmware must be 16 bytes .. 2 MiB');
        }
        $dir = $this->runtime . DIRECTORY_SEPARATOR . 'uploads';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        if ($ext === 'bin' && $addr === 0 && $this->imageHeader((string) file_get_contents($tmp, false, null, 0, 0x40)) !== null) {
            return $this->loadFlashSet([['tmp' => $tmp, 'name' => $orig]], $run, $boot);
        }
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($orig)) ?: ('fw.' . $ext);
        $dest = $dir . DIRECTORY_SEPARATOR . bin2hex(random_bytes(3)) . '_' . $safe;
        if (!move_uploaded_file($tmp, $dest) && !rename($tmp, $dest)) {
            throw new RuntimeException('failed to store upload');
        }
        $this->ensure();
        $this->installImage($dest, $ext === 'bin' ? 'bin' : ($ext === 'hex' ? 'hex' : 'elf'), $addr, $safe, $run);
        return $this->snapshot();
    }

    /**
     * Places Suunto .bin images at their header load address in one 0xFF
     * flash image. fw/boot0.elf stands in for stage 0 unless an image
     * covers 0x0.
     *
     * $boot 'app' writes the boot0 hint so the application starts without
     * the bootloaders; 'chain' leaves it erased (BL1 first).
     *
     * @param list<array{tmp: string, name: string}> $files
     */
    public function loadFlashSet(array $files, bool $run, string $boot = 'app'): array
    {
        if (count($files) < 1 || count($files) > 8) {
            throw new InvalidArgumentException('flash set wants 1..8 .bin images');
        }
        $flash = str_repeat("\xFF", self::FLASH_SIZE);
        $used = [];
        $parts = [];
        foreach ($files as $f) {
            $data = (string) file_get_contents($f['tmp']);
            $hdr = $this->imageHeader($data);
            if ($hdr === null) {
                throw new InvalidArgumentException($f['name'] . ': no DA11C10C image header (upload it alone with a load address)');
            }
            $start = $hdr['load'];
            $end = $start + strlen($data);
            if ($end > self::FLASH_SIZE) {
                throw new InvalidArgumentException(sprintf('%s: 0x%X..0x%X is past the 512 KiB flash', $f['name'], $start, $end));
            }
            foreach ($used as [$a, $b, $n]) {
                if ($start < $b && $a < $end) {
                    throw new InvalidArgumentException(sprintf('%s overlaps %s at 0x%X', $f['name'], $n, max($a, $start)));
                }
            }
            $flash = substr_replace($flash, $data, $start, strlen($data));
            $used[] = [$start, $end, $f['name']];
            $role = [0x2000 => 'BL1', 0xA000 => 'BL2', 0x12000 => 'App'][$start] ?? basename($f['name']);
            $parts[$start] = sprintf('%s %s @%X', $role, $hdr['version'], $start);
        }
        ksort($parts);
        $stub = substr($flash, 0, 4) === "\xFF\xFF\xFF\xFF";
        $title = 'Corium UNPACKED: ' . implode(' + ', $parts) . ($stub ? ' + boot0 stub' : '');
        if ($stub && $boot === 'app' && isset($parts[0x12000])) {
            $flash = substr_replace($flash, pack('V', 0x12000), 0x1FFC, 4);
            $title .= ' → App';
        }
        $dir = $this->runtime . DIRECTORY_SEPARATOR . 'uploads';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $name = 'flash_' . bin2hex(random_bytes(3)) . '.bin';
        if (file_put_contents($dir . DIRECTORY_SEPARATOR . $name, $flash) !== self::FLASH_SIZE) {
            throw new RuntimeException('failed to store flash image');
        }
        $this->ensure();
        $this->installImage($dir . DIRECTORY_SEPARATOR . $name, 'flash', 0, $name, $run, $title);
        return $this->snapshot();
    }

    /** Header in vector words 7..10, version (major.minor.patch) in word 13. */
    private function imageHeader(string $data): ?array
    {
        if (strlen($data) < 0x40) {
            return null;
        }
        $w = array_values(unpack('V16', $data));
        if ($w[7] !== 0xDA11C10C || $w[10] !== 0xDA11C10C || ($w[8] & 0x3FF) !== 0) {
            return null;
        }
        return [
            'load' => $w[8],
            'end' => $w[9],
            'version' => sprintf('%d.%d.%d', $w[13] >> 24, ($w[13] >> 16) & 0xFF, $w[13] & 0xFFFF),
        ];
    }

    public function realtime(bool $on): array
    {
        $this->ensure();
        $session = $this->session();
        $session['realtime'] = $on;
        $this->writeSession($session);
        $this->withMonitor(function ($sock) use ($on) {
            $this->applyRealtime($sock, $on);
        });
        return $this->snapshot();
    }

    private function applyRealtime($sock, bool $on): void
    {
        $script = $this->slash($this->runtime . DIRECTORY_SEPARATOR . 'realtime.py');
        $out = $this->ask(
            $sock,
            'python "execfile(r\'' . $script . '\'); rt_install(' . ($on ? 'True' : 'False') . ')"',
            15
        );
        if (stripos($out, 'Traceback') !== false || stripos($out, 'There was an error') !== false) {
            throw new RuntimeException(trim($out));
        }
    }

    public function monitor(string $line): string
    {
        $line = trim($line);
        if ($line === '' || strlen($line) > 500 || preg_match('/[\r\n]/', $line)) {
            throw new InvalidArgumentException('one monitor command, 500 chars');
        }
        $this->ensure();
        return $this->command($line, 30);
    }

    public function peek(int $addr, int $len): array
    {
        $addr &= 0xFFFFFFFF;
        $len = max(1, min(1024, $len));
        $this->ensure();
        $py = sprintf(
            'python "b=monitor.Machine[\'sysbus.cpu\'].Bus; a=%d; print \' \'.join(\'%%02X\' %% (int(getattr(b.ReadByte(a+i), \'RawValue\', b.ReadByte(a+i))) & 255) for i in range(%d))"',
            $addr,
            $len
        );
        $text = $this->command($py, 20);
        if (!preg_match('/([0-9A-F]{2}(?: [0-9A-F]{2})*)/', $text, $m)) {
            throw new RuntimeException('peek failed: ' . trim($text));
        }
        return ['addr' => sprintf('%08X', $addr), 'hex' => $m[1]];
    }

    // Case buttons are P2.0-P2.4, active low with external pull-ups; the
    // model's PIN_INJECT backdoor takes (port << 8) | pin levels.
    public function buttons(int $pressed): array
    {
        $pressed &= self::BUTTON_MASK;
        $this->ensure();
        $this->command(sprintf(
            'sysbus WriteWord 0x%08X 0x%04X',
            self::PIN_INJECT,
            (self::BUTTON_PORT << 8) | (self::BUTTON_MASK & ~$pressed)
        ));
        return ['pressed' => $pressed];
    }

    public function poke(int $addr, int $value, int $width): array
    {
        $addr &= 0xFFFFFFFF;
        $cmds = [1 => 'WriteByte', 2 => 'WriteWord', 4 => 'WriteDoubleWord'];
        if (!isset($cmds[$width])) {
            throw new InvalidArgumentException('width must be 1, 2 or 4');
        }
        $mask = $width === 1 ? 0xFF : ($width === 2 ? 0xFFFF : 0xFFFFFFFF);
        $this->ensure();
        $this->command(sprintf('sysbus %s 0x%X 0x%X', $cmds[$width], $addr, $value & $mask));
        return $this->peek($addr, $width);
    }

    private function examples(): array
    {
        return [
            'core' => ['file' => 'fw' . DIRECTORY_SEPARATOR . 'core_demo.elf', 'label' => 'core_demo.elf'],
            'blink' => ['file' => 'fw' . DIRECTORY_SEPARATOR . 'blink.elf', 'label' => 'blink.elf'],
            'happy' => ['file' => 'fw' . DIRECTORY_SEPARATOR . 'happy.elf', 'label' => 'happy.elf'],
            'wdt' => ['file' => 'fw' . DIRECTORY_SEPARATOR . 'wdt_reset.elf', 'label' => 'wdt_reset.elf'],
        ];
    }

    private function ensure(): void
    {
        $this->syncArtifacts();
        $session = $this->session();
        $pid = $this->readPid();
        // A dead Renode shows up as a failed connect in withMonitor().
        if ($pid > 0 && ($session['pid'] ?? 0) === $pid && !empty($session['ready'])) {
            return;
        }
        $this->ensureProcess();
        $pid = $this->readPid();
        $fresh = false;
        $this->withMonitor(function ($sock) use ($pid, &$fresh) {
            $probe = $this->ask($sock, 'cpu PC', 10);
            if (stripos($probe, 'no such') !== false || stripos($probe, 'no machine') !== false) {
                $log = $this->slash($this->runtime . DIRECTORY_SEPARATOR . 'renode.log');
                $this->ask($sock, 'logFile @' . $log . ' true', 8);
                $this->ask($sock, 'mach add "s1c31w74"', 8);
                $this->ask($sock, 'mach set "s1c31w74"', 4);
                $repl = $this->slash($this->runtime . DIRECTORY_SEPARATOR . 's1c31w74.repl');
                $loaded = $this->ask($sock, 'machine LoadPlatformDescription "' . $repl . '"', 25);
                if (stripos($loaded, 'error') !== false || stripos($loaded, 'exception') !== false) {
                    throw new RuntimeException($loaded);
                }
                $fresh = true;
            }
            $session = $this->session();
            $session['pid'] = $pid;
            $session['ready'] = true;
            $this->writeSession($session);
        });
        if ($fresh) {
            // Ready is already stored, so loadCorium's ensure() does not re-enter.
            $this->loadCorium(true);
        }
    }

    /** BL1 @0x2000 + BL2 @0xA000 + Application @0x12000, boot0 stub into the app. */
    public function loadCorium(bool $run = true): array
    {
        $dir = $this->coriumDir();
        $names = $this->coriumImages();
        if ($dir === '' || $names === []) {
            throw new RuntimeException('set CORIUM_UNPACKED and CORIUM_IMAGES, or corium.dir and corium.images in local.properties');
        }
        $files = [];
        foreach ($names as $n) {
            $path = $dir . DIRECTORY_SEPARATOR . $n;
            if (!is_file($path)) {
                throw new RuntimeException('missing Corium image ' . $path);
            }
            $files[] = ['tmp' => $path, 'name' => $n];
        }
        return $this->loadFlashSet($files, $run, 'app');
    }

    /** User-supplied image directory. Firmware is never part of this repository. */
    private function coriumDir(): string
    {
        $env = getenv('CORIUM_UNPACKED');
        if (is_string($env) && $env !== '') {
            return $env;
        }
        return $this->localProperties()['corium.dir'] ?? '';
    }

    /** @return list<string> */
    private function coriumImages(): array
    {
        $env = getenv('CORIUM_IMAGES');
        $raw = (is_string($env) && $env !== '') ? $env : ($this->localProperties()['corium.images'] ?? '');
        if ($raw === '') {
            return [];
        }
        $names = [];
        foreach (explode(',', $raw) as $name) {
            $name = trim($name);
            if ($name !== '' && !str_contains($name, '/') && !str_contains($name, '\\')) {
                $names[] = $name;
            }
        }
        return $names;
    }

    /** @return array<string, string> */
    private function localProperties(): array
    {
        $path = $this->root . DIRECTORY_SEPARATOR . 'local.properties';
        if (!is_file($path)) {
            return [];
        }
        $out = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $out[trim(substr($line, 0, $eq))] = trim(substr($line, $eq + 1));
        }
        return $out;
    }

    private function installImage(string $src, string $kind, int $addr, string $label, bool $run, string $title = ''): void
    {
        $stub = null;
        if ($kind === 'flash' && (string) file_get_contents($src, false, null, 0, 4) === "\xFF\xFF\xFF\xFF") {
            $stub = $this->root . DIRECTORY_SEPARATOR . 'fw' . DIRECTORY_SEPARATOR . 'boot0.elf';
            if (!is_file($stub)) {
                throw new RuntimeException('fw/boot0.elf is not built yet');
            }
            $stub = $this->slash($stub);
        }
        $dir = $this->runtime . DIRECTORY_SEPARATOR . 'images';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $dest = $dir . DIRECTORY_SEPARATOR . $label;
        if (!@copy($src, $dest)) {
            $dest = $src;
        }
        $path = $this->slash($dest);
        $boot = $this->slash($this->runtime . DIRECTORY_SEPARATOR . 'boot_cpu.py');
        $this->withMonitor(function ($sock) use ($path, $kind, $addr, $run, $boot, $stub) {
            $this->ask($sock, 'pause', 6);
            $this->ask($sock, 'machine Reset', 20);
            $wiped = $this->ask($sock, 'python "execfile(r\'' . $boot . '\'); wipe_mem()"', 20);
            if (stripos($wiped, 'Traceback') !== false || stripos($wiped, 'There was an error') !== false) {
                throw new RuntimeException(trim($wiped));
            }
            if ($kind === 'elf') {
                $out = $this->ask($sock, 'sysbus LoadELF "' . $path . '"', 20);
            } elseif ($kind === 'hex') {
                $out = $this->ask($sock, 'sysbus LoadHEX "' . $path . '"', 20);
            } else {
                $out = $this->ask($sock, sprintf('sysbus LoadBinary "%s" 0x%X', $path, $addr & 0xFFFFFFFF), 20);
            }
            if ($stub !== null) {
                $out .= $this->ask($sock, 'sysbus LoadELF "' . $stub . '"', 20);
            }
            if (stripos($out, 'error') !== false || stripos($out, 'exception') !== false || stripos($out, 'no such') !== false) {
                throw new RuntimeException(trim($out));
            }
            $this->ask($sock, 'cpu Reset', 10);
            $booted = $this->ask(
                $sock,
                'python "execfile(r\'' . $boot . '\'); boot_cpu(' . ($run ? 'True' : 'False') . ')"',
                15
            );
            if (stripos($booted, 'Traceback') !== false || stripos($booted, 'There was an error') !== false) {
                throw new RuntimeException(trim($booted));
            }
            $this->applyRealtime($sock, $this->session()['realtime'] ?? true);
            if ($run) {
                $this->ask($sock, 'start', 8);
            }
        });
        $session = $this->session();
        $session['image'] = $dest;
        $session['kind'] = $kind;
        $session['addr'] = $addr & 0xFFFFFFFF;
        $session['label'] = $label;
        $session['title'] = $title !== '' ? $title : $label;
        $session['pid'] = $this->readPid();
        $session['ready'] = true;
        $this->writeSession($session);
    }

    private function reload(bool $run): void
    {
        $this->loadCorium($run);
    }

    private function continuous(): void
    {
        $out = $this->command(
            'python "from Antmicro.Renode.Peripherals.CPU import ExecutionMode; '
            . 'monitor.Machine[\'sysbus.cpu\'].ExecutionMode = ExecutionMode.Continuous"'
        );
        if (stripos($out, 'There was an error') !== false) {
            throw new RuntimeException(trim($out));
        }
    }

    private function command(string $line, float $timeout = 8): string
    {
        return $this->withMonitor(function ($sock) use ($line, $timeout) {
            return $this->ask($sock, $line, $timeout);
        });
    }

    private function withMonitor(callable $fn)
    {
        $lockPath = $this->runtime . DIRECTORY_SEPARATOR . 'monitor.lock';
        $lock = fopen($lockPath, 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('monitor lock failed');
        }
        try {
            $errno = 0;
            $err = '';
            $sock = @fsockopen('127.0.0.1', $this->port, $errno, $err, 0.5);
            if ($sock === false) {
                $pid = $this->readPid();
                $this->ensureProcess();
                if ($this->readPid() !== $pid) {
                    $session = $this->session();
                    $session['ready'] = false;
                    $this->writeSession($session);
                    throw new RuntimeException('Renode was not running and has been restarted; retry');
                }
                $sock = @fsockopen('127.0.0.1', $this->port, $errno, $err, 5);
            }
            if ($sock === false) {
                throw new RuntimeException('monitor connect failed: ' . $err);
            }
            stream_set_blocking($sock, false);
            $this->drain($sock);
            try {
                return $fn($sock);
            } catch (MonitorTimeout $e) {
                // A wedged monitor blocks every later request on the
                // single-threaded PHP server. Kill Renode; the next request
                // respawns it and reloads the last image.
                fclose($sock);
                $sock = null;
                $this->kill();
                throw new RuntimeException($e->getMessage() . "\nRenode was killed; the next request restarts it and reloads the image.");
            } finally {
                if ($sock !== null) {
                    fclose($sock);
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function ask($sock, string $line, float $timeout): string
    {
        fwrite($sock, $line . "\n");
        $raw = '';
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $r = [$sock];
            $w = null;
            $e = null;
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                break;
            }
            $sec = (int) $left;
            $usec = (int) (($left - $sec) * 1000000);
            $n = @stream_select($r, $w, $e, $sec, $usec);
            if ($n === false || $n === 0) {
                continue;
            }
            $c = fread($sock, 16384);
            if ($c === false || $c === '') {
                continue;
            }
            $raw .= $c;
            $text = $this->stripTelnet($raw);
            $pos = strpos($text, $line);
            if ($pos === false) {
                continue;
            }
            $after = substr($text, $pos + strlen($line));
            if (preg_match('/\([^)\r\n]+\) $/', $after)) {
                $after = preg_replace('/\([^)\r\n]+\) $/', '', $after);
                return trim(str_replace("\r", '', $after));
            }
        }
        throw new MonitorTimeout('monitor timeout on: ' . $line . "\n" . $this->stripTelnet($raw));
    }

    private function drain($sock): void
    {
        $raw = '';
        $deadline = microtime(true) + 0.35;
        while (microtime(true) < $deadline) {
            $r = [$sock];
            $w = null;
            $e = null;
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                break;
            }
            // Renode's banner has no prompt until telnet negotiation, so
            // stop once it has gone quiet instead of waiting out the window.
            $usec = $raw === '' ? (int) ($left * 1000000) : min((int) ($left * 1000000), 40000);
            if (@stream_select($r, $w, $e, 0, $usec) <= 0) {
                if ($raw !== '') {
                    break;
                }
                continue;
            }
            $c = fread($sock, 8192);
            if ($c === false || $c === '') {
                break;
            }
            $raw .= $c;
            if (preg_match('/\([^)\r\n]+\) $/', $this->stripTelnet($raw))) {
                return;
            }
        }
    }

    private function stripTelnet(string $buf): string
    {
        $out = '';
        $n = strlen($buf);
        for ($i = 0; $i < $n; $i++) {
            $b = ord($buf[$i]);
            if ($b !== 255 || $i + 1 >= $n) {
                if ($b !== 255) {
                    $out .= $buf[$i];
                }
                continue;
            }
            $cmd = ord($buf[$i + 1]);
            if ($cmd === 255) {
                $out .= "\xFF";
                $i++;
                continue;
            }
            if ($cmd >= 251 && $cmd <= 254 && $i + 2 < $n) {
                $i += 2;
                continue;
            }
            if ($cmd === 250) {
                $end = strpos($buf, "\xFF\xF0", $i);
                $i = $end === false ? $n : $end + 1;
                continue;
            }
            $i++;
        }
        return $out;
    }

    private function portOpen(float $timeout): bool
    {
        $errno = 0;
        $err = '';
        $s = @fsockopen('127.0.0.1', $this->port, $errno, $err, $timeout);
        if ($s === false) {
            return false;
        }
        fclose($s);
        return true;
    }

    private function waitPort(float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            if ($this->portOpen(0.3)) {
                return true;
            }
            usleep(200000);
        }
        return false;
    }

    private function ensureProcess(): void
    {
        if ($this->portOpen(0.2)) {
            return;
        }
        // Renode stops listening while a monitor command runs, so a closed
        // port with our process still alive means "busy", not "gone".
        $pid = $this->readPid();
        if ($pid > 0 && $this->alive($pid)) {
            if ($this->waitPort(25)) {
                return;
            }
            $this->kill();
        }
        if (!is_file($this->exe)) {
            throw new RuntimeException('renode.exe missing at ' . $this->exe);
        }
        $args = implode(',', [
            $this->psQuote('--disable-gui'),
            $this->psQuote('--hide-monitor'),
            $this->psQuote('--plain'),
            $this->psQuote('-P'),
            $this->psQuote((string) $this->port),
        ]);
        $ps = '(Start-Process -PassThru -FilePath ' . $this->psQuote($this->exe)
            . ' -WorkingDirectory ' . $this->psQuote(dirname($this->exe))
            . ' -WindowStyle Hidden -ArgumentList ' . $args . ').Id';
        $out = [];
        exec('powershell -NoProfile -Command ' . escapeshellarg($ps), $out);
        $spawned = (int) trim(implode('', $out));
        if ($spawned <= 0) {
            throw new RuntimeException('failed to start renode');
        }
        file_put_contents($this->runtime . DIRECTORY_SEPARATOR . 'renode.pid', (string) $spawned);
        if (!$this->waitPort(25)) {
            throw new RuntimeException('renode did not open port ' . $this->port);
        }
    }

    private function alive(int $pid): bool
    {
        $out = [];
        exec('tasklist /FI "PID eq ' . $pid . '" /FO CSV /NH 2>NUL', $out);
        return stripos(implode("\n", $out), 'renode') !== false;
    }

    private function kill(): void
    {
        $pid = $this->readPid();
        if ($pid > 0) {
            exec('taskkill /PID ' . $pid . ' /T /F 2>NUL');
            $deadline = microtime(true) + 8;
            while ($this->alive($pid) && microtime(true) < $deadline) {
                usleep(200000);
            }
        }
        $session = $this->session();
        $image = $session['image'] ?? null;
        $kind = $session['kind'] ?? null;
        $addr = $session['addr'] ?? 0;
        $this->writeSession([
            'image' => $image,
            'kind' => $kind,
            'addr' => $addr,
            'label' => $session['label'] ?? null,
            'title' => $session['title'] ?? null,
            'realtime' => $session['realtime'] ?? true,
            'ready' => false,
            'pid' => 0,
        ]);
        $deadline = microtime(true) + 8;
        while (microtime(true) < $deadline) {
            $errno = 0;
            $err = '';
            $s = @fsockopen('127.0.0.1', $this->port, $errno, $err, 0.2);
            if ($s === false) {
                break;
            }
            fclose($s);
            usleep(200000);
        }
    }

    private function syncArtifacts(): void
    {
        $pySrc = $this->root . DIRECTORY_SEPARATOR . 'platform' . DIRECTORY_SEPARATOR . 's1c31w74.py';
        $pyDst = $this->runtime . DIRECTORY_SEPARATOR . 's1c31w74.py';
        if (is_file($pySrc)) {
            $eeprom = $this->runtime . DIRECTORY_SEPARATOR . 'eeprom.bin';
            $py = preg_replace(
                '/^EEPROM_FILE = None(?=\r?$)/m',
                'EEPROM_FILE = r"' . $eeprom . '"',
                (string) file_get_contents($pySrc)
            );
            if (!is_file($pyDst) || file_get_contents($pyDst) !== $py) {
                file_put_contents($pyDst, $py);
            }
        }
        $snapSrc = $this->root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'snapshot.py';
        if (is_file($snapSrc)) {
            copy($snapSrc, $this->runtime . DIRECTORY_SEPARATOR . 'snapshot.py');
        }
        foreach (['boot_cpu.py', 'realtime.py'] as $name) {
            $src = $this->root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . $name;
            if (is_file($src)) {
                copy($src, $this->runtime . DIRECTORY_SEPARATOR . $name);
            }
        }
        $replSrc = $this->root . DIRECTORY_SEPARATOR . 'platform' . DIRECTORY_SEPARATOR . 's1c31w74.repl';
        $repl = is_file($replSrc) ? file_get_contents($replSrc) : '';
        $repl = preg_replace(
            '/filename:\s*"[^"]*"/',
            'filename: "' . $this->slash($pyDst) . '"',
            $repl
        );
        file_put_contents($this->runtime . DIRECTORY_SEPARATOR . 's1c31w74.repl', $repl);
    }

    private function session(): array
    {
        $path = $this->runtime . DIRECTORY_SEPARATOR . 'session.json';
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    private function writeSession(array $data): void
    {
        file_put_contents(
            $this->runtime . DIRECTORY_SEPARATOR . 'session.json',
            json_encode($data, JSON_PRETTY_PRINT)
        );
    }

    private function readPid(): int
    {
        $path = $this->runtime . DIRECTORY_SEPARATOR . 'renode.pid';
        if (!is_file($path)) {
            return 0;
        }
        return (int) trim((string) file_get_contents($path));
    }

    private function slash(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    private function psQuote(string $s): string
    {
        return "'" . str_replace("'", "''", $s) . "'";
    }
}
