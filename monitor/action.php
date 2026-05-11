<?php
/**
 * NTP Monitor — action.php
 * API для управляющих действий: перезапуск, диагностика, Telegram, конфиг
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Access-Control-Allow-Origin: *');

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
// Перед остановкой gpsd проверяем что chrony переживёт перерыв

function checkChronyCanHoldover($maxSec = 60) {
    $r = run('chronyc tracking 2>/dev/null');
    // Если stratum <= 2 и есть интернет-серверы — можно остановить gpsd ненадолго
    if (preg_match('/Stratum\s*:\s*(\d+)/', $r['out'], $m)) {
        $stratum = (int)$m[1];
        if ($stratum >= 10) return false; // уже в holdover
    }
    return true;
}

// ─── Читать NMEA через gpsd JSON API (без остановки gpsd) ─────────────────

function readNmeaViaGpsd($lines = 20, $timeout = 4) {
    // gpspipe читает из gpsd сокета, не из ttyAMA0 напрямую
    $r = run("timeout {$timeout} gpspipe -r -n {$lines} 2>/dev/null");
    return $r['out'];
}

// ─── action: raw_port ──────────────────────────────────────────────────────
// Стратегия: сначала читаем через gpsd. Если gpsd не даёт данные —
// останавливаем gpsd, читаем порт, запускаем gpsd обратно.

function actionRawPort() {
    // Попытка 1: через gpsd (не трогаем порт)
    $nmea = readNmeaViaGpsd(25, 4);
    $filtered = array_filter(explode("\n", $nmea), fn($l) => str_starts_with(trim($l), '$'));
    if (count($filtered) > 0) {
        ok(implode("\n", array_slice($filtered, 0, 20)), [
            'source' => 'gpsd',
            'baud'   => null,
        ]);
        return;
    }

    // Попытка 2: остановить gpsd, прочитать напрямую, запустить gpsd
    if (!checkChronyCanHoldover()) {
        err("gpsd держит порт, но chrony уже в holdover режиме.\nОстановка gpsd небезопасна. Сначала восстановите синхронизацию.");
        return;
    }

    // Остановка gpsd
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

    // Запуск gpsd обратно
    run('sudo systemctl start gpsd');
    sleep(1);

    if ($result) {
        ok($result, ['source' => 'direct', 'baud' => $foundBaud]);
    } else {
        err("Нет NMEA данных ни на одной скорости.\ngpsd перезапущен.", ['source' => 'direct']);
    }
}

// ─── action: port_info ────────────────────────────────────────────────────

function actionPortInfo() {
    $stat  = run('ls -la /dev/ttyAMA0 /dev/pps0 2>&1');
    $lsof  = run('sudo lsof /dev/ttyAMA0 /dev/pps0 2>/dev/null | tail -n +2');
    $groups= run('id www-data 2>/dev/null');

    // Получаем baudrate из gpsd JSON
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

    $output = "=== Устройства ===\n" . $stat['out'] .
              "\n\n=== Открыто процессами ===\n" . ($lsof['out'] ?: '(никем не открыто)') .
              "\n\n=== Baudrate (из gpsd) ===\n" . ($baud ? "{$baud} bps" : "не определён") .
              "\n\n=== Права www-data ===\n" . $groups['out'];

    ok($output, ['baud' => $baud]);
}

// ─── action: switch_mode ──────────────────────────────────────────────────

function actionSwitchMode($mode) {
    if (!in_array($mode, ['time', 'ucenter'])) { err('Неверный режим'); return; }

    if ($mode === 'ucenter') {
        // Проверяем что ser2net установлен
        $check = run('which ser2net 2>/dev/null || dpkg -l ser2net 2>/dev/null | grep -c "^ii"');
        if (empty(trim($check['out']))) {
            err("ser2net не установлен.\nУстановите: sudo apt install ser2net\nЗатем скопируйте конфиг: sudo cp /var/www/html/monitor/ser2net.yaml /etc/ser2net.yaml");
            return;
        }

        if (!checkChronyCanHoldover()) {
            err("chrony в holdover режиме — остановка gpsd небезопасна.\nСначала восстановите синхронизацию.");
            return;
        }

        run('sudo systemctl stop gpsd gpsd.socket chrony 2>/dev/null');
        sleep(1);
        $r = run('sudo systemctl start ser2net');
        sleep(1);
        $st = run('systemctl is-active ser2net');
        if (trim($st['out']) === 'active') {
            $ip   = getServerIP();
            $cfg  = loadConfig();
            $port = $cfg['ser2net']['port'] ?? 2947;
            ok("Режим u-center активен\nser2net: tcp://{$ip}:{$port}\nu-center: File → Receiver → Network → tcp://{$ip}:{$port}", ['mode'=>'ucenter']);
        } else {
            // Откатываемся
            run('sudo systemctl stop ser2net 2>/dev/null');
            run('sudo systemctl start gpsd chrony');
            err("Не удалось запустить ser2net\n" . $r['out']);
        }

    } elseif ($mode === 'time') {
        run('sudo systemctl stop ser2net 2>/dev/null');
        sleep(1);
        run('sudo systemctl start gpsd');
        sleep(2);
        run('sudo systemctl start chrony');
        sleep(2);
        $gs = trim(run('systemctl is-active gpsd')['out']);
        $cs = trim(run('systemctl is-active chrony')['out']);
        if ($gs === 'active' && $cs === 'active') {
            ok("Режим NTP активен\ngpsd: {$gs}\nchrony: {$cs}", ['mode'=>'time']);
        } else {
            err("Сервисы запущены с ошибкой\ngpsd: {$gs}\nchrony: {$cs}");
        }
    }
}

// ─── action: telegram_test ────────────────────────────────────────────────

function sendTelegram($token, $chatId, $text, $proxy = null) {
    $url    = "https://api.telegram.org/bot{$token}/sendMessage";
    $params = http_build_query(['chat_id'=>$chatId,'text'=>$text,'parse_mode'=>'HTML','disable_web_page_preview'=>'true']);
    $fullUrl = "{$url}?{$params}";

    // Попытка через curl (поддерживает proxy)
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

    // Fallback: file_get_contents (без proxy)
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
        err("Не настроены bot_token или chat_id в config.json");
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

    // Защита от записи опасных полей
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
    $ser2Port = $cfg['ser2net']['port']     ?? 2947;

    echo json_encode([
        'success'  => true,
        'ip'       => $ip,
        'hostname' => $hostname,
        'ntp_port' => $ntpPort,
        'ser2_port'=> $ser2Port,
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

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

    case 'makestep':
        $r = run('sudo chronyc makestep');
        $r['code']===0 ? ok($r['out']) : err($r['out']);
        break;

    case 'restart_gpsd':
        run('sudo systemctl restart gpsd');
        sleep(2);
        $s = trim(run('systemctl is-active gpsd')['out']);
        $s==='active' ? ok("gpsd перезапущен: {$s}") : err("gpsd: {$s}");
        break;

    case 'restart_chrony':
        run('sudo systemctl restart chrony');
        sleep(2);
        $s = trim(run('systemctl is-active chrony')['out']);
        $s==='active' ? ok("chrony перезапущен: {$s}") : err("chrony: {$s}");
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

    case 'get_instructions':
        $cfg  = loadConfig();
        $mode = $_GET['mode'] ?? 'ntp';
        $instrs = $cfg['connection_instructions'][$mode] ?? [];
        $ip     = getServerIP();
        $host   = getServerHostname();
        $ntpP   = $cfg['server']['ntp_port']  ?? 123;
        $s2P    = $cfg['ser2net']['port']      ?? 2947;
        // Replace placeholders
        $result = [];
        foreach ($instrs as $os => $text) {
            $result[$os] = str_replace(['{IP}','{HOST}','{PORT}','{SER2_PORT}'],
                                       [$ip,   $host,   $ntpP,  $s2P], $text);
        }
        echo json_encode(['success'=>true,'instructions'=>$result,'ip'=>$ip,'hostname'=>$host,'ntp_port'=>$ntpP,'ser2_port'=>$s2P], JSON_UNESCAPED_UNICODE);
        break;

    case 'server_info':
        actionServerInfo();
        break;

    default:
        http_response_code(400);
        echo json_encode(['success'=>false,'error'=>'Unknown action: '.htmlspecialchars($action)]);
}
