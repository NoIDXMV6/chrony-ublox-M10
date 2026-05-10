<?php
/**
 * NTP Monitor — Action API
 * Выполняет команды управления: перезапуск сервисов, makestep, чтение порта
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Access-Control-Allow-Origin: *');

function runCommand($cmd) {
    $output = [];
    $code   = 0;
    exec($cmd . ' 2>&1', $output, $code);
    return ['output' => implode("\n", $output), 'code' => $code];
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

    case 'makestep':
        $r = runCommand('sudo chronyc makestep');
        echo json_encode([
            'success' => $r['code'] === 0,
            'output'  => $r['output'],
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'restart_gpsd':
        $r = runCommand('sudo systemctl restart gpsd');
        sleep(2);
        $s = runCommand('systemctl is-active gpsd');
        echo json_encode([
            'success' => trim($s['output']) === 'active',
            'output'  => 'gpsd ' . trim($s['output']),
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'restart_chrony':
        $r = runCommand('sudo systemctl restart chrony');
        sleep(2);
        $s = runCommand('systemctl is-active chrony');
        echo json_encode([
            'success' => trim($s['output']) === 'active',
            'output'  => 'chrony ' . trim($s['output']),
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'raw_port':
        // Читаем 20 строк с UART за 3 секунды
        $baud = 9600; // можно передать параметром
        $r = runCommand("stty -F /dev/ttyAMA0 {$baud} raw 2>&1");
        $r2 = runCommand("timeout 3 cat /dev/ttyAMA0 2>&1 | strings | head -20");
        echo json_encode([
            'success' => true,
            'output'  => $r2['output'] ?: '(нет данных)',
            'baud'    => $baud,
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'port_info':
        // Текущие настройки порта
        $r = runCommand('stty -F /dev/ttyAMA0 2>&1');
        $r2 = runCommand('lsof /dev/ttyAMA0 2>/dev/null | tail -n +2');
        echo json_encode([
            'success'  => true,
            'stty'     => $r['output'],
            'lsof'     => $r2['output'],
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'offset_history':
        // История offset из лога chrony
        $logfile = '/var/log/chrony/measurements.log';
        $points  = [];
        if (file_exists($logfile)) {
            $lines = file($logfile);
            $lines = array_slice($lines, -200); // последние 200 записей
            foreach ($lines as $line) {
                if ($line[0] === '=') continue;
                $f = preg_split('/\s+/', trim($line));
                // Формат: Date Time IP Stratum Poll Reach LastRx Offset RMS
                if (count($f) < 9) continue;
                $ts  = strtotime($f[0] . ' ' . $f[1]);
                $off = (float)$f[7];
                if ($ts && is_numeric($off)) {
                    $points[] = ['t' => $ts * 1000, 'v' => $off];
                }
            }
        } else {
            // Если лога нет — включаем логирование и возвращаем пустой массив
            // Пользователь должен добавить "log measurements" в chrony.conf
        }
        echo json_encode(['points' => $points, 'logfile' => $logfile, 'exists' => file_exists($logfile)], JSON_UNESCAPED_UNICODE);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action: ' . htmlspecialchars($action)]);
}
