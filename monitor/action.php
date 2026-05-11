<?php
/**
 * NTP Monitor — action.php
 * API для управляющих действий: перезапуск, диагностика, Telegram, конфиг.
 * Все строки серверных сообщений теперь берутся из файлов локализации.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Access-Control-Allow-Origin: *');

// ─── Загрузка языкового файла для серверных сообщений ────────────────────
$lang = $_GET['lang'] ?? $_POST['lang'] ?? 'ru';
$localeFile = __DIR__ . '/' . ($lang === 'en' ? 'en_locale.txt' : 'ru_locale.txt');
$locale = [];
if (file_exists($localeFile)) {
    $locale = json_decode(file_get_contents($localeFile), true) ?? [];
}

// Функция для получения перевода серверного сообщения (плейсхолдеры как у sprintf)
function __($key, ...$args) {
    global $locale;
    $msg = $locale['server_messages'][$key] ?? $key;
    if (!empty($args)) {
        return vsprintf($msg, $args);
    }
    return $msg;
}

// ─── Helpers ──────────────────────────────────────────────────────────────

function run($cmd) {
    $out = []; $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    return ['out' => implode("\n", $out), 'code' => $code];
}

function ok($output, $extra = [])  { echo json_encode(['success'=>true,  'output'=>$output] + $extra, JSON_UNESCAPED_UNICODE); }
function err($output, $extra = []) { echo json_encode(['success'=>false, 'output'=>$output] + $extra, JSON_UNESCAPED_UNICODE); }

function loadConfig() {
    $f = __DIR__ . '/config.json';
    if (!file_exists($f)) return [];
    return json_decode(file_get_contents($f), true) ?? [];
}

function getServerIP() {
    $r = run("hostname -I | awk '{print $1}'");
    return trim($r['out']);
}

function getServerHostname() {
    $r = run('hostname -f 2>/dev/null || hostname');
    return trim($r['out']);
}

// ─── Chrony holdover guard ─────────────────────────────────────────────────

function checkChronyCanHoldover($maxSec = 60) {
    $r = run('chronyc tracking 2>/dev/null');
    if (preg_match('/Stratum\s*:\s*(\d+)/', $r['out'], $m)) {
        $stratum = (int)$m[1];
        if ($stratum >= 10) return false;
    }
    return true;
}

// ─── Читать NMEA через gpsd JSON API (без остановки gpsd) ─────────────────

function readNmeaViaGpsd($lines = 20, $timeout = 4) {
    $r = run("timeout {$timeout} gpspipe -r -n {$lines} 2>/dev/null");
    return $r['out'];
}

// ─── action: raw_port ──────────────────────────────────────────────────────

function actionRawPort() {
    $nmea = readNmeaViaGpsd(25, 4);
    $filtered = array_filter(explode("\n", $nmea), fn($l) => str_starts_with(trim($l), '$'));
    if (count($filtered) > 0) {
        ok(implode("\n", array_slice($filtered, 0, 20)), [
            'source' => 'gpsd',
            'baud'   => null,
        ]);
        return;
    }

    if (!checkChronyCanHoldover()) {
        err(__('holdover_warning'));
        return;
    }

    run('sudo systemctl stop gpsd gpsd.socket 2>/dev/null');
    sleep(1);

    $result = '';
    $foundBaud = null;
    foreach ([9600, 38400, 4800, 19200, 57600, 115200] as $baud) {
        run("stty -F /dev/ttyAMA0 {$baud} raw 2>/dev/null");
        $r = run("timeout 2 cat /dev/ttyAMA0 2>/dev/null | strings | grep -a '^\\\$' | head -10");
        if (!empty(trim($r['out']))) {
            $result    = $r['out'];
            $foundBaud = $baud;
            break;
        }
    }

    run('sudo systemctl start gpsd');
    sleep(1);

    if ($result) {
        ok($result, ['source' => 'direct', 'baud' => $foundBaud]);
    } else {
        err(__('no_nmea_data'));
    }
}

// ─── action: port_info ────────────────────────────────────────────────────

function actionPortInfo() {
    $stat  = run('ls -la /dev/ttyAMA0 /dev/pps0 2>&1');
    $lsof  = run('sudo lsof /dev/ttyAMA0 /dev/pps0 2>/dev/null | tail -n +2');
    $groups= run('id www-data 2>/dev/null');

    $baud = null;
    $nmea = run("timeout 3 gpspipe -w -n 3 2>/dev/null");
    foreach (explode("\n", $nmea['out']) as $line) {
        $j = json_decode(trim($line), true);
        if (!$j || $j['class'] !== 'DEVICES') continue;
        foreach ($j['devices'] ?? [] as $dev) {
            if (str_contains($dev['path'] ?? '', 'ttyAMA')) {
                $baud = $dev['bps'] ?? null;
                break 2;
            }
        }
    }

    $output = __("device_info_header", $stat['out']) .
              __("processes_header", $lsof['out'] ?: __('none')) .
              __("baudrate_header", $baud ? "{$baud} bps" : __('baud_not_determined')) .
              __("permissions_header", $groups['out']);

    ok($output, ['baud' => $baud]);
}

// ─── action: switch_mode ──────────────────────────────────────────────────

function actionSwitchMode($mode) {
    if (!in_array($mode, ['time', 'ucenter'])) { err('Неверный режим'); return; }

    if ($mode === 'ucenter') {
        $check = run('which ser2net 2>/dev/null || dpkg -l ser2net 2>/dev/null | grep -c "^ii"');
        if (empty(trim($check['out']))) {
            err(__('ser2net_not_installed'));
            return;
        }

        if (!checkChronyCanHoldover()) {
            err(__('holdover_warning'));
            return;
        }

        // Получаем скорость из запроса или из конфига
        $reqBaud = isset($_GET['baud']) ? (int)$_GET['baud'] : null;
        $cfgU = loadConfig();
        $ucenterBaud = $reqBaud ?: ($cfgU['ser2net']['baudrate'] ?? 9600);
        $port = $cfgU['ser2net']['port'] ?? 2000;

        // Автосоздание конфига ser2net
        $cfgPath = '/etc/ser2net.yaml';
        if (!file_exists($cfgPath)) {
            $configContent = "connection: &con1\n"
                . "  accepter: tcp,{$port}\n"
                . "  enable: on\n"
                . "  connector: serialdev,/dev/ttyAMA0,{$ucenterBaud}n81,local\n";
            if (file_put_contents($cfgPath, $configContent) === false) {
                err(__('config_write_failed'));
                return;
            }
        }
        run("stty -F /dev/ttyAMA0 {$ucenterBaud} raw 2>/dev/null");
        run('sudo systemctl stop gpsd gpsd.socket chrony 2>/dev/null');
        sleep(1);
        $r = run('sudo systemctl start ser2net');
        sleep(1);

        $st = run("ss -tlnp | grep ':{$port}'");
        if (empty(trim($st['out']))) {
            run('sudo systemctl stop ser2net 2>/dev/null');
            run('sudo systemctl start gpsd chrony');
            err(__('port_not_open', $port));
            return;
        }

        $st = run('systemctl is-active ser2net');
        if (trim($st['out']) === 'active') {
            $ip = getServerIP();
            ok(__('mode_ucenter_active', $ip, $port, $ip, $port), ['mode'=>'ucenter']);
        } else {
            run('sudo systemctl stop ser2net 2>/dev/null');
            run('sudo systemctl start gpsd chrony');
            err("Не удалось запустить ser2net\n" . $r['out']);
        }

    } elseif ($mode === 'time') {
        run('sudo systemctl stop ser2net 2>/dev/null');
        sleep(1);

        // === Сброс приёмника в NMEA-режим ===
        $cfg    = loadConfig();
        $baud   = $cfg['gnss']['baudrate'] ?? 38400;
        $device = $cfg['gnss']['device']    ?? '/dev/ttyAMA0';

        $pubxBody = "PUBX,41,1,0007,0001,{$baud},0";
        $checksum = 0;
        for ($i = 0; $i < strlen($pubxBody); $i++) {
            $checksum ^= ord($pubxBody[$i]);
        }
        $checksumHex = strtoupper(sprintf('%02X', $checksum));
        $command = "\$" . $pubxBody . "*" . $checksumHex . "\\r\\n";

        run("stty -F {$device} {$baud} raw 2>/dev/null");
        run("echo -e '$command' > {$device}");
        sleep(3);

        // Запускаем сервисы
        run('sudo systemctl start gpsd');
        sleep(2);
        run('sudo systemctl start chrony');
        sleep(2);

        $gs = trim(run('systemctl is-active gpsd')['out']);
        $cs = trim(run('systemctl is-active chrony')['out']);
        if ($gs === 'active' && $cs === 'active') {
            ok(__('mode_ntp_active', $gs, $cs), ['mode'=>'time']);
        } else {
            err(__('service_error', $gs, $cs));
        }
    }
}

// ─── action: telegram_test ────────────────────────────────────────────────

function sendTelegram($token, $chatId, $text, $proxy = null) {
    $url    = "https://api.telegram.org/bot{$token}/sendMessage";
    $params = http_build_query(['chat_id'=>$chatId,'text'=>$text,'parse_mode'=>'HTML','disable_web_page_preview'=>'true']);
    $fullUrl = "{$url}?{$params}";

    if (function_exists('curl_init')) {
        $ch = curl_init($fullUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        if ($proxy && !empty($proxy['host'])) {
            $proxyUrl = $proxy['host'] . ':' . ($proxy['port'] ?? 8080);
            curl_setopt($ch, CURLOPT_PROXY, $proxyUrl);
            $proxyType = strtolower($proxy['type'] ?? 'http');
            curl_setopt($ch, CURLOPT_PROXYTYPE,
                str_starts_with($proxyType, 'socks') ? CURLPROXY_SOCKS5 : CURLPROXY_HTTP);
            if (!empty($proxy['user'])) {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy['user'] . ':' . ($proxy['pass'] ?? ''));
            }
        }
        $response = curl_exec($ch);
        $curlErr  = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlErr) return ['success'=>false, 'error'=>"curl: {$curlErr}", 'http_code'=>0, 'response'=>null];
        if (empty($response)) return ['success'=>false, 'error'=>'Пустой ответ от Telegram', 'http_code'=>$httpCode, 'response'=>null];
        $decoded = json_decode($response, true);
        return ['success'=>$httpCode===200&&($decoded['ok']??false), 'http_code'=>$httpCode, 'response'=>$decoded, 'error'=>null];
    }

    if ($proxy && !empty($proxy['host'])) {
        return ['success'=>false, 'error'=>'curl не установлен, proxy недоступен. Установите: sudo apt install php-curl', 'http_code'=>0, 'response'=>null];
    }
    $ctx = stream_context_create(['http'=>['timeout'=>15],'ssl'=>['verify_peer'=>true]]);
    $response = @file_get_contents($fullUrl, false, $ctx);
    if ($response === false) {
        return ['success'=>false, 'error'=>'file_get_contents: запрос не удался. Установите php-curl для лучшей совместимости.', 'http_code'=>0, 'response'=>null];
    }
    $decoded = json_decode($response, true);
    return ['success'=>($decoded['ok']??false), 'http_code'=>200, 'response'=>$decoded, 'error'=>null];
}

function actionTelegramTest() {
    $cfg = loadConfig();
    $tg  = $cfg['telegram'] ?? [];

    if (empty($tg['bot_token']) || empty($tg['chat_id'])) {
        err(__('telegram_not_configured'));
        return;
    }

    $proxy = null;
    if (!empty($tg['proxy_enabled'])) {
        $proxy = [
            'host' => $tg['proxy_host'] ?? '',
            'port' => $tg['proxy_port'] ?? 8080,
            'type' => $tg['proxy_type'] ?? 'http',
            'user' => $tg['proxy_user'] ?? '',
            'pass' => $tg['proxy_pass'] ?? '',
        ];
    }

    $hostname = getServerHostname();
    $ip       = getServerIP();
    $text     = "🦔 NTP ALERT: тестовое сообщение\nСервер: {$hostname} ({$ip})\nВремя: " . date('Y-m-d H:i:s T');

    $result = sendTelegram($tg['bot_token'], $tg['chat_id'], $text, $proxy);

    $output = "HTTP код: " . $result['http_code'] . "\n";
    if ($result['error']) $output .= "Ошибка curl: " . $result['error'] . "\n";
    if ($result['response']) $output .= "Ответ Telegram:\n" . json_encode($result['response'], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);

    if ($result['success']) ok($output);
    else err($output);
}

// ─── action: config_read ──────────────────────────────────────────────────

function actionConfigRead() {
    $f = __DIR__ . '/config.json';
    if (!file_exists($f)) { err('config.json не найден'); return; }
    $raw = file_get_contents($f);
    echo json_encode(['success'=>true,'config'=>json_decode($raw,true),'raw'=>$raw], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
}

// ─── action: config_write ─────────────────────────────────────────────────

function actionConfigWrite() {
    $f = __DIR__ . '/config.json';
    $body = file_get_contents('php://input');
    $data = json_decode($body, true);
    if ($data === null) { err('Невалидный JSON: ' . json_last_error_msg()); return; }

    unset($data['_dangerous']);

    $written = file_put_contents($f, json_encode($data, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    if ($written === false) { err('Ошибка записи файла (проверь права)'); return; }
    ok('Настройки сохранены');
}

// ─── action: server_info ──────────────────────────────────────────────────

function actionServerInfo() {
    $ip       = getServerIP();
    $hostname = getServerHostname();
    $cfg      = loadConfig();
    $ntpPort  = $cfg['server']['ntp_port'] ?? 123;
    $ser2Port = $cfg['ser2net']['port']     ?? 2000;
    $ser2Baud = $cfg['ser2net']['baudrate']  ?? 9600;
    $baudOpts = $cfg['ser2net']['baudrate_options'] ?? [4800, 9600, 19200, 38400, 57600, 115200];

    echo json_encode([
        'success'          => true,
        'ip'               => $ip,
        'hostname'         => $hostname,
        'ntp_port'         => $ntpPort,
        'ser2_port'        => $ser2Port,
        'ser2_baudrate'    => $ser2Baud,
        'baudrate_options' => $baudOpts,
    ], JSON_UNESCAPED_UNICODE);
}

// ─── action: telegram_status ──────────────────────────────────────────────

function actionTelegramStatus() {
    $cfg = loadConfig();
    $tg  = $cfg['telegram'] ?? [];
    $enabled  = !empty($tg['enabled']) && !empty($tg['bot_token']) && !empty($tg['chat_id']);
    $proxy    = !empty($tg['proxy_enabled']) ? ($tg['proxy_host'].':'.$tg['proxy_port']) : 'нет';
    echo json_encode(['success'=>true,'enabled'=>$enabled,'proxy'=>$proxy], JSON_UNESCAPED_UNICODE);
}

// ─── Router ───────────────────────────────────────────────────────────────

$needsAuth = [
    'makestep', 'restart_gpsd', 'restart_chrony',
    'switch_mode', 'config_write',
];

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if (in_array($action, $needsAuth)) {
    $authPassFile = __DIR__ . '/.monitor_pass';
    $providedPass = $_SERVER['PHP_AUTH_PW'] ?? $_POST['auth_pass'] ?? $_GET['auth_pass'] ?? '';
    $authenticated = false;
    
    if (file_exists($authPassFile) && !empty($providedPass)) {
        $storedHash = trim(file_get_contents($authPassFile));
        if (password_verify($providedPass, $storedHash)) {
            $authenticated = true;
        }
    }
    
    if (!$authenticated && getenv('MONITOR_PASSWORD') && $providedPass === getenv('MONITOR_PASSWORD')) {
        $authenticated = true;
    }
    
    if (!$authenticated) {
        http_response_code(401);
        echo json_encode(['success'=>false,'error'=>'Требуется пароль', 'auth_required'=>true]);
        exit;
    }
}

switch ($action) {

    case 'makestep':
        $r = run('sudo chronyc makestep');
        $r['code']===0 ? ok($r['out']) : err($r['out']);
        break;

    case 'restart_gpsd':
        run('sudo systemctl restart gpsd');
        sleep(2);
        $s = trim(run('systemctl is-active gpsd')['out']);
        $s==='active' ? ok(__('gpsd_restarted', $s)) : err("gpsd: {$s}");
        break;

    case 'restart_chrony':
        run('sudo systemctl restart chrony');
        sleep(2);
        $s = trim(run('systemctl is-active chrony')['out']);
        $s==='active' ? ok(__('chrony_restarted', $s)) : err("chrony: {$s}");
        break;

    case 'raw_port':
        actionRawPort();
        break;

    case 'port_info':
        actionPortInfo();
        break;

    case 'switch_mode':
        actionSwitchMode($_GET['mode'] ?? '');
        break;

    case 'current_mode':
        $gpsd  = trim(run('systemctl is-active gpsd 2>/dev/null')['out']);
        $chrony= trim(run('systemctl is-active chrony 2>/dev/null')['out']);
        $ser2  = trim(run('systemctl is-active ser2net 2>/dev/null')['out']);
        echo json_encode([
            'success'=>true,
            'mode'   => $ser2==='active' ? 'ucenter' : 'time',
            'gpsd'   => $gpsd, 'chrony'=>$chrony, 'ser2net'=>$ser2,
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'telegram_test':
        actionTelegramTest();
        break;

    case 'telegram_status':
        actionTelegramStatus();
        break;

    case 'config_read':
        actionConfigRead();
        break;

    case 'config_write':
        actionConfigWrite();
        break;

    case 'server_info':
        actionServerInfo();
        break;

    default:
        http_response_code(400);
        echo json_encode(['success'=>false,'error'=>'Unknown action: '.htmlspecialchars($action)]);
}