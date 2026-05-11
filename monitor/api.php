<?php
/**
 * NTP Monitor API
 * Возвращает JSON со статистикой chrony и gpsd
 * Размести в веб-директории рядом с index.html
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Access-Control-Allow-Origin: *');

// ─── Вспомогательные функции ──────────────────────────────────────────────

function runCommand($cmd) {
    $output = [];
    $code   = 0;
    exec($cmd . ' 2>&1', $output, $code);
    return ['output' => implode("\n", $output), 'code' => $code];
}

function chronyc($args, $sudo = false) {
    $prefix = $sudo ? 'sudo ' : '';
    $r = runCommand($prefix . 'chronyc ' . escapeshellarg($args));
    return $r['code'] === 0 ? $r['output'] : null;
}

function formatSeconds($sec) {
    $sec = (float)$sec;
    $abs = abs($sec);
    if ($abs === 0.0)      return ['value' => '0', 'unit' => 'с'];
    if ($abs < 1e-6)       return ['value' => sprintf('%.2f', $sec * 1e9),  'unit' => 'нс'];
    if ($abs < 1e-3)       return ['value' => sprintf('%.3f', $sec * 1e6),  'unit' => 'мкс'];
    if ($abs < 1.0)        return ['value' => sprintf('%.3f', $sec * 1e3),  'unit' => 'мс'];
    return                        ['value' => sprintf('%.4f', $sec),         'unit' => 'с'];
}

// ─── Tracking ─────────────────────────────────────────────────────────────

function getTracking() {
    $out = chronyc('tracking');
    if ($out === null) return ['error' => 'chronyc tracking failed'];

    $fields = [
        'reference_id'    => '/Reference ID\s*:\s*(\S+(?:\s*\([^)]+\))?)/',
        'stratum'         => '/Stratum\s*:\s*(\d+)/',
        'ref_time'        => '/Ref time \(UTC\)\s*:\s*(.+)/',
        'system_time'     => '/System time\s*:\s*([+-]?\d+\.\d+)\s*seconds/',
        'last_offset'     => '/Last offset\s*:\s*([+-]?\d+\.\d+)\s*seconds/',
        'rms_offset'      => '/RMS offset\s*:\s*([+-]?\d+\.\d+)\s*seconds/',
        'frequency'       => '/Frequency\s*:\s*([+-]?\d+\.\d+)\s*ppm/',
        'residual_freq'   => '/Residual freq\s*:\s*([+-]?\d+\.\d+)\s*ppm/',
        'skew'            => '/Skew\s*:\s*([+-]?\d+\.\d+)\s*ppm/',
        'root_delay'      => '/Root delay\s*:\s*([+-]?\d+\.\d+)\s*seconds/',
        'root_dispersion' => '/Root dispersion\s*:\s*([+-]?\d+\.\d+)\s*seconds/',
        'update_interval' => '/Update interval\s*:\s*([+-]?\d+\.\d+)\s*seconds/',
        'leap_status'     => '/Leap status\s*:\s*(.+)/',
    ];

    $data = ['raw' => $out];
    foreach ($fields as $key => $pattern) {
        if (preg_match($pattern, $out, $m)) {
            $data[$key] = trim($m[1]);
        }
    }

    // Форматированные offset-значения
    foreach (['system_time','last_offset','rms_offset','root_delay','root_dispersion'] as $f) {
        if (isset($data[$f])) {
            $data[$f . '_fmt'] = formatSeconds($data[$f]);
        }
    }

    // Статус
    $sysTime = abs((float)($data['system_time'] ?? 0));
    if ($sysTime > 0.5)     $data['status'] = 'error';
    elseif ($sysTime > 0.1) $data['status'] = 'warning';
    else                     $data['status'] = 'good';

    return $data;
}

// ─── Sources ──────────────────────────────────────────────────────────────

function getSources() {
    $out = chronyc('sources -v');
    if ($out === null) return ['error' => 'chronyc sources failed', 'list' => []];

    $sources = [];
    $hasSelected = false;
    $hasError    = false;

    foreach (explode("\n", $out) as $line) {
        // Строки источников начинаются с #, ^, =
        if (!preg_match('/^([#\^=])([*+\-x~?])\s+(\S+)\s+(\d+)\s+(\d+)\s+(\w+)\s+(\S+)\s+(.+)$/', $line, $m)) continue;

        $mode  = $m[1]; // # = local clock, ^ = server, = = peer
        $state = $m[2];
        $name  = $m[3];

        $isLocalRefclock = ($mode === '#');
        $isNoselect = ($isLocalRefclock && $state === '?');

        if ($state === '*') $hasSelected = true;
        if (!$isNoselect && in_array($state, ['x', '?'])) $hasError = true;

        $sources[] = [
            'mode'       => $mode,
            'state'      => $state,
            'name'       => $name,
            'stratum'    => (int)$m[4],
            'poll'       => (int)$m[5],
            'reach'      => $m[6],
            'last_rx'    => trim($m[7]),
            'offset'     => trim($m[8]),
            'is_refclock'=> $isLocalRefclock,
            'is_noselect'=> $isNoselect,
            'state_label'=> [
                '*' => 'Выбран',
                '+' => 'Комбинируется',
                '-' => 'Не используется',
                'x' => 'Ошибка',
                '~' => 'Нестабилен',
                '?' => 'Недоступен',
            ][$state] ?? $state,
        ];
    }

    $status = 'good';
    if (!$hasSelected) $status = 'error';
    elseif ($hasError)  $status = 'warning';

    return ['list' => $sources, 'status' => $status, 'raw' => $out];
}

// ─── Sourcestats ──────────────────────────────────────────────────────────

function getSourcestats() {
    $out = chronyc('sourcestats');
    if ($out === null) return ['error' => 'chronyc sourcestats failed', 'list' => []];

    $stats = [];
    foreach (explode("\n", $out) as $line) {
        // Пропускаем заголовок и разделитель
        if (preg_match('/^(Name|=)/', $line)) continue;
        // Формат: Name NP NR Span Frequency FreqSkew Offset StdDev
        // Все поля разделены пробелами, имя может быть любым непробельным
        if (!preg_match('/^(\S+)\s+(\d+)\s+(\d+)\s+(\S+)\s+([+-]?\S+)\s+(\S+)\s+([+-]?\S+)\s+(\S+)\s*$/', $line, $m)) continue;
        $stats[] = [
            'name'      => $m[1],
            'np'        => $m[2],
            'nr'        => $m[3],
            'span'      => $m[4],
            'frequency' => $m[5],
            'freq_skew' => $m[6],
            'offset'    => $m[7],
            'std_dev'   => $m[8],
        ];
    }

    return ['list' => $stats, 'raw' => $out];
}

// ─── Activity ─────────────────────────────────────────────────────────────

function getActivity() {
    $out = chronyc('activity');
    if ($out === null) return ['error' => 'chronyc activity failed'];

    $data = ['raw' => $out];
    if (preg_match('/(\d+) sources online/',  $out, $m)) $data['online']  = (int)$m[1];
    if (preg_match('/(\d+) sources offline/', $out, $m)) $data['offline'] = (int)$m[1];
    if (preg_match('/(\d+) sources doing burst/', $out, $m)) $data['burst'] = (int)$m[1];

    $online = $data['online'] ?? 0;
    $data['status'] = $online === 0 ? 'error' : ($online < 2 ? 'warning' : 'good');

    return $data;
}

// ─── Clients ──────────────────────────────────────────────────────────────

function getClients() {
    $out = chronyc('clients', true);
    if ($out === null) return ['error' => 'chronyc clients failed', 'list' => []];

    $clients = [];
    foreach (explode("\n", $out) as $line) {
        if (trim($line) === '' || preg_match('/^(Hostname|=)/', $line)) continue;
        // Формат: Hostname NTP Drop Int IntL Last Cmd Drop Int Last
        // Любое поле может быть числом или '-'
        $f = preg_split('/\s+/', trim($line));
        if (count($f) < 10) continue;
        $clients[] = [
            'hostname' => $f[0],
            'ntp'      => (int)$f[1],
            'drop'     => (int)$f[2],
            'int'      => $f[3],
            'intl'     => $f[4],
            'last'     => $f[5],
        ];
    }

    $status = count($clients) === 0 ? 'warning' : 'good';
    return ['list' => $clients, 'count' => count($clients), 'status' => $status, 'raw' => $out];
}

// ─── Serverstats ──────────────────────────────────────────────────────────

function getServerstats() {
    $out = chronyc('serverstats', true);
    if ($out === null) return ['error' => 'chronyc serverstats failed'];

    $data = ['raw' => $out];
    $patterns = [
        'packets_received' => '/Packets received\s*:\s*(\d+)/',
        'packets_dropped'  => '/Packets dropped\s*:\s*(\d+)/',
        'cmd_packets_received' => '/Command packets received\s*:\s*(\d+)/',
        'nts_ke_connections'   => '/NTS-KE connections accepted\s*:\s*(\d+)/',
    ];
    foreach ($patterns as $key => $pat) {
        if (preg_match($pat, $out, $m)) $data[$key] = (int)$m[1];
    }

    $data['status'] = ($data['packets_received'] ?? 0) === 0 ? 'warning' : 'good';
    return $data;
}

// ─── GPSD ─────────────────────────────────────────────────────────────────

function getGpsd() {
    $data = ['available' => false, 'pps' => file_exists('/dev/pps0')];

    // Проверяем что gpsd запущен
    $r2 = runCommand('systemctl is-active gpsd');
    $data['service_status'] = trim($r2['output']);
    if ($data['service_status'] !== 'active') return $data;

    // gpspipe: читаем 20 сообщений, таймаут 5с
    // --timeout поддерживается не всеми версиями — используем timeout(1)
    $r = runCommand('timeout 5 gpspipe -w -n 20 2>/dev/null');
    $lines = array_filter(explode("\n", $r['output']));

    $tpv     = null;
    $sky     = null;
    $devices = null;

    foreach ($lines as $line) {
        $j = json_decode(trim($line), true);
        if (!is_array($j) || !isset($j['class'])) continue;
        if ($j['class'] === 'TPV'     && !$tpv)     $tpv     = $j;
        if ($j['class'] === 'SKY'     && !$sky)     $sky     = $j;
        if ($j['class'] === 'DEVICES' && !$devices) $devices = $j;
    }

    $data['available'] = true;

    // TPV — позиция и время
    if ($tpv) {
        $modeLabels = [0 => 'Нет данных', 1 => 'Нет фикса', 2 => '2D фикс', 3 => '3D фикс'];
        $data['fix'] = [
            'mode'      => (int)($tpv['mode'] ?? 0),
            'mode_label'=> $modeLabels[$tpv['mode'] ?? 0] ?? 'Неизвестно',
            'time'      => $tpv['time'] ?? null,
            'lat'       => isset($tpv['lat'])  ? round($tpv['lat'], 6)  : null,
            'lon'       => isset($tpv['lon'])  ? round($tpv['lon'], 6)  : null,
            'alt'       => isset($tpv['alt'])  ? round($tpv['alt'], 1)  : null,
            'speed'     => isset($tpv['speed'])? round($tpv['speed'], 3): null,
            'ept'       => $tpv['ept']  ?? null,
            'epx'       => $tpv['epx']  ?? null,
            'epy'       => $tpv['epy']  ?? null,
            'epv'       => $tpv['epv']  ?? null,
        ];
    }

    // SKY — спутники
    if ($sky) {
        $sats = $sky['satellites'] ?? [];
        $used = array_filter($sats, fn($s) => !empty($s['used']));
        $data['sky'] = [
            'total'      => count($sats),
            'used'       => count($used),
            'hdop'       => $sky['hdop'] ?? null,
            'vdop'       => $sky['vdop'] ?? null,
            'pdop'       => $sky['pdop'] ?? null,
            'satellites' => array_map(fn($s) => [
                'prn'   => $s['PRN']  ?? $s['prn']  ?? '?',
                'el'    => $s['el']   ?? null,
                'az'    => $s['az']   ?? null,
                'ss'    => $s['ss']   ?? null,
                'used'  => !empty($s['used']),
                'gnss'  => $s['gnssid'] ?? null,
            ], array_values($sats)),
        ];
    }

    // GNSS модуль — информация из gpsd DEVICES (уже распознано в общем цикле)
    if ($devices) {
        foreach ($devices['devices'] ?? [] as $dev) {
            if (strpos($dev['path'] ?? '', 'ttyAMA') === false) continue;
            $sub  = $dev['subtype']  ?? '';
            $sub1 = $dev['subtype1'] ?? '';
            $gnss = ['driver'=>$dev['driver']??'','bps'=>(int)($dev['bps']??0),'native'=>$dev['native']??0,'path'=>$dev['path']??''];
            if (preg_match('/SW\s+(.+?),HW\s+(.+)/', $sub, $m)) {
                $gnss['firmware'] = trim($m[1]);
                $gnss['hardware'] = trim($m[2]);
            }
            foreach (explode(',', $sub1) as $p) {
                if (str_starts_with($p,'FWVER='))   $gnss['fwver']   = substr($p,6);
                if (str_starts_with($p,'PROTVER=')) $gnss['protver'] = substr($p,8);
                if (str_contains($p,';') && !str_contains($p,'=')) {
                    $sys = explode(';', $p);
                    if (count($sys) > 2) $gnss['gnss_systems'] = $sys;
                    else                 $gnss['augmentation']  = $sys;
                }
            }
            $data['module'] = $gnss;
            break;
        }
    }

    // Baudrate порта — берём из gpsd DEVICES (уже разобрано выше), не из stty
    $baud = $data['module']['bps'] ?? null;
    // Fallback: попробовать stty только если gpsd не дал данных
    if (!$baud) {
        $stty = runCommand('stty -F /dev/ttyAMA0 2>/dev/null | head -1');
        if (preg_match('/speed (\d+) baud/', $stty['output'], $bm)) {
            $baud = (int)$bm[1];
        }
    }
    $data['uart_baud'] = $baud;

    // PPS статус
    $data['pps'] = file_exists('/dev/pps0');

    // Статус устройства
    $r2 = runCommand('systemctl is-active gpsd');
    $data['service_status'] = trim($r2['output']);

    return $data;
}

// ─── Система ──────────────────────────────────────────────────────────────

function getSystem() {
    $temp_raw = @file_get_contents('/sys/class/thermal/thermal_zone0/temp');
    $temp = $temp_raw !== false ? round((int)$temp_raw / 1000, 1) : null;

    $uptime_raw = @file_get_contents('/proc/uptime');
    $uptime_sec = $uptime_raw ? (int)explode(' ', $uptime_raw)[0] : 0;
    $uptime_str = sprintf('%dd %02dч %02dм',
        intdiv($uptime_sec, 86400),
        intdiv($uptime_sec % 86400, 3600),
        intdiv($uptime_sec % 3600, 60)
    );

    $load = sys_getloadavg();

    $mem_raw = @file_get_contents('/proc/meminfo');
    $mem = [];
    if ($mem_raw) {
        if (preg_match('/MemTotal:\s+(\d+)/',     $mem_raw, $m)) $mem['total']     = (int)$m[1];
        if (preg_match('/MemAvailable:\s+(\d+)/', $mem_raw, $m)) $mem['available'] = (int)$m[1];
        if (isset($mem['total'], $mem['available'])) {
            $mem['used']    = $mem['total'] - $mem['available'];
            $mem['percent'] = round(($mem['used'] / $mem['total']) * 100, 1);
        }
    }

    $hostname_r = runCommand('hostname');
    $hostname   = trim($hostname_r['output']);

    $chrony_r = runCommand('systemctl is-active chrony');
    $gpsd_r   = runCommand('systemctl is-active gpsd');

    return [
        'hostname'       => $hostname,
        'temp'           => $temp,
        'uptime'         => $uptime_str,
        'uptime_seconds' => $uptime_sec,
        'load'           => $load,
        'memory'         => $mem,
        'chrony_active'  => trim($chrony_r['output']) === 'active',
        'gpsd_active'    => trim($gpsd_r['output'])   === 'active',
        'pps_device'     => file_exists('/dev/pps0'),
        'uart_device'    => file_exists('/dev/ttyAMA0'),
    ];
}

// ─── История offset из лога chrony ────────────────────────────────────────

function getOffsetHistory() {
    $points = [];
    $logfile = '/var/log/chrony/measurements.log';
    $hasLog  = file_exists($logfile);

    if ($hasLog) {
        $lines = @file($logfile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines) {
            $lines = array_slice($lines, -500);
            foreach ($lines as $line) {
                if ($line[0] === '=' || $line[0] === '#') continue;
                $f = preg_split('/\s+/', trim($line));
                if (count($f) < 9) continue;
                $ts  = @strtotime($f[0] . ' ' . $f[1]);
                $off = isset($f[7]) ? (float)$f[7] : null;
                if ($ts > 0 && $off !== null) {
                    $points[] = ['t' => $ts * 1000, 'v' => round($off * 1e9, 2)]; // в нс
                }
            }
        }
    }

    return ['points' => $points, 'has_log' => $hasLog, 'logfile' => $logfile];
}

// ─── Сборка ответа ────────────────────────────────────────────────────────

$response = [
    'timestamp'      => date('c'),
    'timestamp_ms'   => round(microtime(true) * 1000),
    'tracking'       => getTracking(),
    'sources'        => getSources(),
    'sourcestats'    => getSourcestats(),
    'activity'       => getActivity(),
    'clients'        => getClients(),
    'serverstats'    => getServerstats(),
    'gpsd'           => getGpsd(),
    'system'         => getSystem(),
    'offset_history' => getOffsetHistory(),
];

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
