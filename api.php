<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

const API_KEY = 't.me/evtvpn143';

$all_panels_config = require_once __DIR__ . '/panels.php';

function generateUUID(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    return round($bytes / pow(1024, $pow), $precision) . ' ' . $units[$pow];
}

function curlRequest($url, $options = []) {
    $ch = curl_init($url);
    $default = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_FOLLOWLOCATION => true
    ];
    curl_setopt_array($ch, $default + $options);
    $res = curl_exec($ch);
    if (curl_errno($ch)) return ['error' => curl_error($ch)];
    curl_close($ch);
    return $res;
}

function api_login($url, $user, $pass) {
    $res = curlRequest(rtrim($url, '/') . '/login', [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['username' => $user, 'password' => $pass]),
        CURLOPT_HEADER => true
    ]);
    if (isset($res['error'])) return false;
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $res, $matches);
    return !empty($matches[1]) ? implode('; ', $matches[1]) : false;
}

function api_call($url, $cookie, $endpoint, $data = [], $method = "POST") {
    $full_url = rtrim($url, '/') . '/' . ltrim($endpoint, '/');
    $options = [
        CURLOPT_HTTPHEADER => ["Cookie: $cookie", "Content-Type: application/json", "Accept: application/json"],
        CURLOPT_CUSTOMREQUEST => $method
    ];
    if ($method === "POST" && !empty($data)) {
        $options[CURLOPT_POSTFIELDS] = json_encode($data);
    }
    $res = curlRequest($full_url, $options);
    if (isset($res['error'])) return ['success' => false, 'msg' => $res['error']];
    return json_decode($res, true) ?: $res;
}

// Check API Key
$api_key = $_GET['api_key'] ?? '';
if ($api_key !== API_KEY) {
    die(json_encode(['error' => 'Unauthorized', 'dev' => '@evtvpn143']));
}

$panelIdx = (int)($_GET['panel'] ?? 1);

if (empty($all_panels_config)) {
    die(json_encode(['error' => 'No panels configured', 'dev' => '@evtvpn143', 'hint' => 'Use Bot /addpanel to add server']));
}

$premium_panels = [];
$idx = 1;
foreach ($all_panels_config as $n => $c) {
    if (($c['type'] ?? '') === 'Premium') {
        $premium_panels[$idx++] = ['name' => $n, 'config' => $c];
    }
}

$p = $premium_panels[$panelIdx] ?? null;
if (!$p) {
    die(json_encode(['error' => 'Panel index not found', 'dev' => '@evtvpn143']));
}

$url = $p['config']['url'];
$cookie = api_login($url, $p['config']['username'], $p['config']['password']);

// Route
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['import'])) {
    $dbData = file_get_contents('php://input');
    if (empty($dbData)) die(json_encode(['success' => false, 'msg' => 'No data']));
    $res = curlRequest(rtrim($url, '/') . "/xui/API/server/importDB", [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ["Cookie: $cookie", "Content-Type: application/octet-stream"],
        CURLOPT_POSTFIELDS => $dbData
    ]);
    echo json_encode(json_decode($res, true) ?: ['success' => false, 'msg' => 'Import failed']);
} elseif (isset($_GET['backup'])) {
    $res = curlRequest(rtrim($url, '/') . "/xui/API/server/getDb", [CURLOPT_HTTPHEADER => ["Cookie: $cookie"]]);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="backup-' . date('Ymd') . '.db"');
    echo $res;
    exit;
} elseif (isset($_GET['restart'])) {
    $res = api_call($url, $cookie, "xui/API/server/restartXrayService", [], "POST");
    $res['dev'] = '@evtvpn143';
    echo json_encode($res, JSON_PRETTY_PRINT);
} elseif (isset($_GET['stop'])) {
    $res = api_call($url, $cookie, "xui/API/server/stopXrayService", [], "POST");
    $res['dev'] = '@evtvpn143';
    echo json_encode($res, JSON_PRETTY_PRINT);
} elseif (isset($_GET['traffic'])) {
    $res = api_call($url, $cookie, "xui/API/inbounds/getClientTraffics/{$_GET['traffic']}", [], "GET");
    if (isset($res['obj'])) {
        $t = $res['obj'];
        echo json_encode([
            'success' => true,
            'email' => $_GET['traffic'],
            'usage' => [
                'up' => formatBytes($t['up']),
                'down' => formatBytes($t['down']),
                'total' => formatBytes($t['up'] + $t['down']),
                'limit' => $t['total'] > 0 ? formatBytes($t['total']) : "Unlimited"
            ],
            'expiry' => $t['expiryTime'] > 0 ? date("Y-m-d H:i:s", (int)($t['expiryTime'] / 1000)) : "Never",
            'dev' => '@evtvpn143'
        ], JSON_PRETTY_PRINT);
    } else {
        echo json_encode(['success' => false, 'msg' => 'Not found']);
    }
} elseif (isset($_GET['status'])) {
    $res = api_call($url, $cookie, "xui/API/server/status", [], "GET");
    $res['dev'] = '@evtvpn143';
    echo json_encode($res, JSON_PRETTY_PRINT);
} elseif (isset($_GET['name']) && isset($_GET['gb']) && isset($_GET['port']) && isset($_GET['path'])) {
    // ========== NEW: Auto-create inbound + client ==========
    $email = $_GET['name'];
    $totalGB = (int)$_GET['gb'] * 1024 * 1024 * 1024;
    $expiry = (int)($_GET['exp'] ?? 0);
    $expiry = $expiry > 0 ? (time() + ($expiry * 86400)) * 1000 : 0;
    $port = (int)$_GET['port'];
    $path = '/' . ltrim($_GET['path'], '/');
    
    // 1. Create new inbound
    $inboundData = [
        "port" => $port,
        "protocol" => "vless",
        "settings" => json_encode([
            "clients" => [],
            "decryption" => "none",
            "fallbacks" => []
        ]),
        "streamSettings" => json_encode([
            "network" => "ws",
            "security" => "none",
            "wsSettings" => [
                "path" => $path
            ]
        ]),
        "sniffing" => json_encode([
            "enabled" => true,
            "destOverride" => ["http", "tls"]
        ]),
        "remark" => "Bot-Created-" . date('YmdHis') . "-Port{$port}"
    ];
    
    $createRes = api_call($url, $cookie, "xui/API/inbounds/add", $inboundData, "POST");
    if (!$createRes['success']) {
        echo json_encode(['success' => false, 'msg' => 'Inbound creation failed: ' . ($createRes['msg'] ?? 'Unknown')]);
        exit;
    }
    
    $inboundId = $createRes['obj']['id'] ?? null;
    if (!$inboundId) {
        echo json_encode(['success' => false, 'msg' => 'Inbound created but no ID returned']);
        exit;
    }
    
    // 2. Create UUID for client
    $uuid = generateUUID();
    
    // 3. Add client to the new inbound
    $clientData = [
        "id" => $inboundId,
        "settings" => json_encode(["clients" => [[
            "id" => $uuid,
            "flow" => "",
            "email" => $email,
            "totalGB" => $totalGB,
            "expiryTime" => $expiry,
            "enable" => true
        ]]])
    ];
    
    $addClientRes = api_call($url, $cookie, "xui/API/inbounds/addClient", $clientData, "POST");
    if (!$addClientRes['success']) {
        echo json_encode(['success' => false, 'msg' => 'Client add failed: ' . ($addClientRes['msg'] ?? 'Unknown')]);
        exit;
    }
    
    // 4. Build config
    $pHost = parse_url($url, PHP_URL_HOST);
    $remark = urlencode('EVT-' . substr($uuid, 0, 8));
    $config = "vless://{$uuid}@{$pHost}:{$port}?type=ws&encryption=none&path=" . urlencode($path) . "&host=&security=none#{$remark}";
    
    echo json_encode([
        'success' => true,
        'user' => $email,
        'uuid' => $uuid,
        'port' => $port,
        'path' => $path,
        'quota' => $_GET['gb'] . 'GB',
        'config' => $config,
        'dev' => '@evtvpn143'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    
} elseif (isset($_GET['delete'])) {
    $list = api_call($url, $cookie, "xui/API/inbounds/", [], "GET");
    $found = false;
    foreach ($list['obj'] as $ib) {
        $settings = json_decode($ib['settings'], true);
        if (isset($settings['clients'])) {
            foreach ($settings['clients'] as $c) {
                if ($c['email'] === $_GET['delete']) {
                    $res = api_call($url, $cookie, "xui/API/inbounds/{$ib['id']}/delClient/{$c['id']}", [], "POST");
                    $res['dev'] = '@evtvpn143';
                    echo json_encode($res, JSON_PRETTY_PRINT);
                    $found = true;
                    exit;
                }
            }
        }
    }
    if (!$found) echo json_encode(['success' => false, 'msg' => 'User not found', 'dev' => '@evtvpn143']);
} else {
    $formatted = [];
    foreach ($premium_panels as $idx => $panel) {
        $formatted[] = [
            'panel_id' => $idx,
            'server_name' => $panel['name'],
            'location' => $panel['config']['location'],
            'type' => $panel['config']['type']
        ];
    }
    echo json_encode([
        'status' => 'ready',
        'dev' => '@evtvpn143',
        'port' => 'auto',
        'total_panels' => count($formatted),
        'panels' => $formatted
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
