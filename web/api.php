<?php

require __DIR__ . '/lib/Renode.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($ip, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'localhost only']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'snapshot';

try {
    $r = new Renode();
    switch ($action) {
        case 'meta':
            $out = $r->meta();
            break;
        case 'snapshot':
            $out = $r->snapshot();
            break;
        case 'control':
            $out = $r->control(
                (string) ($_POST['op'] ?? ''),
                (int) ($_POST['ms'] ?? 100),
                (int) ($_POST['steps'] ?? 1)
            );
            break;
        case 'example':
            $out = $r->loadExample((string) ($_POST['id'] ?? ''), !empty($_POST['run']));
            break;
        case 'upload':
            $files = uploaded_files('fw');
            if ($files === []) {
                throw new InvalidArgumentException('no file');
            }
            $boot = ($_POST['boot'] ?? 'app') === 'chain' ? 'chain' : 'app';
            if (count($files) > 1) {
                $out = $r->loadFlashSet($files, !empty($_POST['run']), $boot);
                break;
            }
            $out = $r->loadUpload(
                $files[0]['tmp'],
                $files[0]['name'],
                parse_addr((string) ($_POST['addr'] ?? '0')),
                !empty($_POST['run']),
                $boot
            );
            break;
        case 'realtime':
            $out = $r->realtime(!empty($_POST['on']));
            break;
        case 'monitor':
            $out = ['text' => $r->monitor((string) ($_POST['cmd'] ?? ''))];
            break;
        case 'peek':
            $addr = parse_addr((string) ($_GET['addr'] ?? '0'));
            $out = $r->peek($addr, (int) ($_GET['len'] ?? 64));
            break;
        case 'buttons':
            $out = $r->buttons((int) ($_POST['pressed'] ?? 0));
            break;
        case 'poke':
            $addr = parse_addr((string) ($_POST['addr'] ?? '0'));
            $value = parse_addr((string) ($_POST['value'] ?? '0'));
            $out = $r->poke($addr, $value, (int) ($_POST['width'] ?? 4));
            break;
        default:
            throw new InvalidArgumentException('unknown action');
    }
    echo json_encode($out, JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

/** @return list<array{tmp: string, name: string}> */
function uploaded_files(string $field): array
{
    $f = $_FILES[$field] ?? null;
    if ($f === null) {
        return [];
    }
    $tmp = (array) $f['tmp_name'];
    $name = (array) $f['name'];
    $out = [];
    foreach ($tmp as $i => $t) {
        if ($t !== '' && is_uploaded_file($t)) {
            $out[] = ['tmp' => $t, 'name' => (string) ($name[$i] ?? 'fw.bin')];
        }
    }
    return $out;
}

function parse_addr(string $s): int
{
    $s = trim($s);
    if ($s === '') {
        return 0;
    }
    if (!preg_match('/^(?:0x)?[0-9a-fA-F]+$/', $s)) {
        throw new InvalidArgumentException('bad address');
    }
    return (int) hexdec(preg_replace('/^0x/i', '', $s) ?? $s) & 0xFFFFFFFF;
}
