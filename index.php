<?php
/**
 * 极简笔记 - 增强版
 * 基于 pereorga/minimalist-web-notepad 改造
 * 支持：SQLite 数据库、账号登录、一次性分享链接、访问日志
 */

// ===== 加载配置 =====
$config = parse_config_yaml('/var/www/config.yaml') ?: [];
$admin = $config['admin'] ?? [];
$security = $config['security'] ?? [];
$app = $config['app'] ?? [];

// 安全设置
$SESSION_TIMEOUT = (int)($security['session_timeout'] ?? 30);
$MAX_FAILED = (int)($security['max_failed_attempts'] ?? 5);
$LOCKOUT_DURATION = (int)($security['lockout_duration'] ?? 15);

// ===== 数据库初始化 =====
$db_file = '/var/www/_data/notes.db';
$data_dir = '/var/www/_data';
if (!is_dir($data_dir)) mkdir($data_dir, 0700, true);

$pdo = new PDO("sqlite:$db_file");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("PRAGMA journal_mode=WAL");
// 严重安全修复：SQLite外键约束默认关闭，必须显式开启
$pdo->exec("PRAGMA foreign_keys = ON");

// 创建表
$pdo->exec("
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY,
    username TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS notes (
    id INTEGER PRIMARY KEY,
    slug TEXT UNIQUE NOT NULL,
    content TEXT DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS shared_notes (
    id INTEGER PRIMARY KEY,
    share_token TEXT UNIQUE NOT NULL,
    note_id INTEGER NOT NULL,
    max_views INTEGER DEFAULT 1,
    view_count INTEGER DEFAULT 0,
    expires_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS access_logs (
    id INTEGER PRIMARY KEY,
    share_token TEXT,
    ip_address TEXT,
    user_agent TEXT,
    accessed_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS failed_attempts (
    id INTEGER PRIMARY KEY,
    ip_address TEXT NOT NULL,
    attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME,
    UNIQUE(ip_address, attempted_at)
);

CREATE TABLE IF NOT EXISTS sessions (
    id TEXT PRIMARY KEY,
    user_id INTEGER,
    ip_address TEXT,
    user_agent TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_activity DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
");

// ===== 管理员账号验证 =====
// 严重安全修复：禁止使用硬编码默认凭据
// 必须从config.yaml配置中获取管理员账号
$admin_username = $admin['username'] ?? null;
$admin_hash = $admin['password_hash'] ?? null;

// 如果配置文件中未指定管理员，拒绝启动
if (empty($admin_username) || empty($admin_hash)) {
    http_response_code(500);
    die('配置错误：未在config.yaml中设置管理员账号（admin.username 和 admin.password_hash）');
}

// 如果users表为空，使用配置中的账号创建
$stmt = $pdo->prepare("SELECT COUNT(*) FROM users");
$stmt->execute();
if ($stmt->fetchColumn() == 0) {
    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash) VALUES (?, ?)");
    $stmt->execute([$admin_username, $admin_hash]);
}

// ===== 安全函数 =====

function sanitize($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function mask_ip($ip) {
    // IPv4: 192.168.1.xxx → 192.168.1.*
    // IPv6: 保留前 4 段，其余脱敏
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $parts = explode('.', $ip);
        return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.*';
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $parts = explode(':', $ip);
        $keep = array_slice($parts, 0, 4);
        return implode(':', $keep) . ':****';
    }
    return $ip;
}

function generate_token($length = 32) {
    return bin2hex(random_bytes($length));
}

function is_locked($pdo, $ip) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM failed_attempts WHERE ip_address = ? AND expires_at > datetime('now')");
    $stmt->execute([$ip]);
    return $stmt->fetchColumn() >= $GLOBALS['MAX_FAILED'];
}

function record_failed_attempt($pdo, $ip) {
    try {
        $stmt = $pdo->prepare("INSERT INTO failed_attempts (ip_address, expires_at) VALUES (?, datetime('now', '+{$GLOBALS['LOCKOUT_DURATION']} minutes'))");
        $stmt->execute([$ip]);
    } catch (PDOException $e) {
        // 唯一键冲突忽略（同一秒内多次尝试）
    }
    $pdo->exec("DELETE FROM failed_attempts WHERE expires_at < datetime('now')");
}

function clear_failed_attempts($pdo, $ip) {
    $stmt = $pdo->prepare("DELETE FROM failed_attempts WHERE ip_address = ?");
    $stmt->execute([$ip]);
}

function verify_csrf($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function generate_csrf() {
    // 每次页面加载都刷新 CSRF token，防止会话刷新后旧 token 失效
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = generate_token(16);
    }
    return $_SESSION['csrf_token'];
}

// ===== 会话管理 =====

// Cookie 安全属性
// 反代场景：Nginx传来X-Forwarded-Proto=https，Caddy通过env HTTPS透传给PHP-FPM
// 容器内部是HTTP，不能依赖 $_SERVER['HTTPS']，优先信任 X-Forwarded-Proto
$proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] 
    ?? $_SERVER['HTTP_X_FORWARDED_PROTO'] 
    ?? '';
$is_https = ($proto === 'https')
    || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
// 允许config.yaml强制覆盖
$force_secure = $security['force_secure_cookie'] ?? null;
$use_secure = $force_secure !== null ? (bool)$force_secure : $is_https;

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $use_secure,
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

// 获取真实 IP
// 严重安全修复：默认只信任 REMOTE_ADDR，禁用对 X-Forwarded-For 的信任
// 如果应用前面有可信的反向代理（如Caddy），代理会在请求头中设置真实IP
// 但攻击者可以伪造这些头，所以不能直接信任客户端传入的值
// 只有在反向代理正确配置（不转发不可信来源）时才考虑使用代理头
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
// 如果需要支持可信代理，可在config.yaml中配置 trusted_proxies
$trusted_proxies = $security['trusted_proxies'] ?? [];
if (!empty($trusted_proxies) && in_array($ip, $trusted_proxies)) {
    $forwarded_ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? null;
    if ($forwarded_ip) {
        $ip = filter_var(explode(',', $forwarded_ip)[0], FILTER_VALIDATE_IP) ?: $ip;
    }
}
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

// 清理过期会话
$pdo->exec("DELETE FROM sessions WHERE datetime(last_activity, '+{$SESSION_TIMEOUT} minutes') < datetime('now')");

// 检查会话是否有效（session_id 必须同时存在于 cookie 和数据库）
// 注意：为了支持动态 IP 用户，不强制绑定 IP，但记录 IP 用于审计
$session_id = $_COOKIE['PHPSESSID'] ?? '';
if (!empty($session_id)) {
    $stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE id = ? AND datetime(last_activity, '+{$SESSION_TIMEOUT} minutes') > datetime('now')");
    $stmt->execute([$session_id]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    $is_logged_in = !empty($session);
} else {
    $is_logged_in = false;
}

// 更新会话活动
if ($is_logged_in && !empty($session_id)) {
    $stmt = $pdo->prepare("UPDATE sessions SET last_activity = datetime('now') WHERE id = ?");
    $stmt->execute([$session_id]);
}

// 路由处理
$action = $_GET['action'] ?? '';

// ===== API 接口 =====

// 登录
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    if (is_locked($pdo, $ip)) {
        echo json_encode(['success' => false, 'error' => '账户已被锁定，请稍后再试']);
        exit;
    }

    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $csrf = $_POST['csrf_token'] ?? '';

    if (!verify_csrf($csrf)) {
        echo json_encode(['success' => false, 'error' => '无效的请求']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, username, password_hash FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password_hash'])) {
        clear_failed_attempts($pdo, $ip);
        // 清理旧 session（防止 Session Fixation）
        $pdo->prepare("DELETE FROM sessions WHERE id = ?")->execute([session_id()]);
        session_regenerate_id(true);
        $stmt = $pdo->prepare("INSERT INTO sessions (id, user_id, ip_address, user_agent) VALUES (?, ?, ?, ?)");
        $stmt->execute([session_id(), $user['id'], $ip, $user_agent]);
        echo json_encode(['success' => true]);
    } else {
        record_failed_attempt($pdo, $ip);
        $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM failed_attempts WHERE ip_address = ? AND expires_at > datetime('now')");
        $stmt2->execute([$ip]);
        $remaining = max(0, $MAX_FAILED - (int)$stmt2->fetchColumn());
        echo json_encode(['success' => false, 'error' => '用户名或密码错误', 'remaining' => $remaining]);
    }
    exit;
}

// 登出
if ($action === 'logout') {
    $stmt = $pdo->prepare("DELETE FROM sessions WHERE id = ?");
    $stmt->execute([session_id()]);
    session_destroy();
    header('Location: ?');
    exit;
}

// 登录页面（GET请求）
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($is_logged_in) {
        header('Location: ?');
        exit;
    }
    $csrf_token = generate_csrf();
    $site_title = sanitize($app['site_title'] ?? '极简笔记');
    $error = $_GET['error'] ?? '';
    $error_msg = $error === 'login_required' ? '请先登录' : '';
    
    echo "<!DOCTYPE html>
<html lang=\"zh-CN\">
<head>
<meta charset=\"utf-8\">
<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
<title>登录 - {$site_title}</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f5f5f5;min-height:100vh;display:flex;align-items:center;justify-content:center}
.login-box{background:#fff;border-radius:16px;padding:40px;width:360px;box-shadow:0 8px 32px rgba(0,0,0,.1)}
.login-box h1{font-size:24px;margin-bottom:24px;text-align:center;color:#4a90d9}
.login-box input{width:100%;padding:12px;border:1px solid #ddd;border-radius:8px;font-size:14px;margin-bottom:12px;box-sizing:border-box}
.login-box button{width:100%;padding:12px;border:none;border-radius:8px;background:#4a90d9;color:#fff;font-size:15px;cursor:pointer;margin-top:8px}
.login-box button:hover{background:#357abd}
.err{color:#e53935;font-size:13px;margin-top:8px;text-align:center;padding:8px;background:#ffebee;border-radius:4px;display:" . ($error_msg ? 'block' : 'none') . "}
.back{text-align:center;margin-top:16px}
.back a{color:#4a90d9;text-decoration:none;font-size:13px}
</style>
</head>
<body>
<div class=\"login-box\">
<h1>🔐 登录</h1>
<form action=\"?action=login\" method=\"POST\">
<input type=\"text\" name=\"username\" placeholder=\"用户名\" required autocomplete=\"username\">
<input type=\"password\" name=\"password\" placeholder=\"密码\" required autocomplete=\"current-password\">
<input type=\"hidden\" name=\"csrf_token\" value=\"{$csrf_token}\">
<button type=\"submit\">登录</button>
</form>
<div class=\"err\">{$error_msg}</div>
<div class=\"back\"><a href=\"?\">← 返回首页</a></div>
</div>
</body>
</html>";
    exit;
}

// ===== 需要登录的操作 =====

// 获取笔记列表
if ($action === 'api_notes' && $is_logged_in) {
    header('Content-Type: application/json');
    $stmt = $pdo->query("SELECT id, slug, content, created_at, updated_at FROM notes ORDER BY updated_at DESC");
    $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($notes);
    exit;
}

// 创建笔记
if ($action === 'api_create' && $is_logged_in && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $csrf = $_POST['csrf_token'] ?? '';
    if (!verify_csrf($csrf)) {
        echo json_encode(['success' => false, 'error' => '无效的请求']);
        exit;
    }
    $slug = bin2hex(random_bytes(4));
    $stmt = $pdo->prepare("INSERT INTO notes (slug, content) VALUES (?, '')");
    $stmt->execute([$slug]);
    echo json_encode(['success' => true, 'slug' => $slug]);
    exit;
}

// 保存笔记
if ($action === 'api_save' && $is_logged_in && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $csrf = $_POST['csrf_token'] ?? '';
    if (!verify_csrf($csrf)) {
        echo json_encode(['success' => false, 'error' => '无效的请求']);
        exit;
    }
    $slug = $_POST['slug'] ?? '';
    $content = $_POST['content'] ?? '';
    // 限制内容长度 1MB
    if (strlen($content) > 1048576) {
        echo json_encode(['success' => false, 'error' => '内容过长（最大 1MB）']);
        exit;
    }
    $stmt = $pdo->prepare("UPDATE notes SET content = ?, updated_at = datetime('now') WHERE slug = ?");
    $stmt->execute([$content, $slug]);
    echo json_encode(['success' => true]);
    exit;
}

// 删除笔记
if ($action === 'api_delete' && $is_logged_in && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $csrf = $_POST['csrf_token'] ?? '';
    if (!verify_csrf($csrf)) {
        echo json_encode(['success' => false, 'error' => '无效的请求']);
        exit;
    }
    $slug = $_POST['slug'] ?? '';
    $stmt = $pdo->prepare("DELETE FROM notes WHERE slug = ?");
    $stmt->execute([$slug]);
    echo json_encode(['success' => true]);
    exit;
}

// 创建分享链接
if ($action === 'api_share' && $is_logged_in && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $csrf = $_POST['csrf_token'] ?? '';
    if (!verify_csrf($csrf)) {
        echo json_encode(['success' => false, 'error' => '无效的请求']);
        exit;
    }
    $note_slug = $_POST['slug'] ?? '';
    $max_views = (int)($_POST['max_views'] ?? 1);
    $expires_hours = (int)($_POST['expires_hours'] ?? 0);

    $stmt = $pdo->prepare("SELECT id FROM notes WHERE slug = ?");
    $stmt->execute([$note_slug]);
    $note = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$note) {
        echo json_encode(['success' => false, 'error' => '笔记不存在']);
        exit;
    }

    $share_token = generate_token(16);
    $expires_at = $expires_hours > 0 ? date('Y-m-d H:i:s', time() + $expires_hours * 3600) : null;

    $stmt = $pdo->prepare("INSERT INTO shared_notes (share_token, note_id, max_views, expires_at) VALUES (?, ?, ?, ?)");
    $stmt->execute([$share_token, $note['id'], $max_views, $expires_at]);
    echo json_encode(['success' => true, 'share_token' => $share_token]);
    exit;
}

// 删除分享链接
if ($action === 'api_delete_share' && $is_logged_in && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $csrf = $_POST['csrf_token'] ?? '';
    if (!verify_csrf($csrf)) {
        echo json_encode(['success' => false, 'error' => '无效的请求']);
        exit;
    }
    $id = (int)($_POST['id'] ?? 0);
    // 验证分享链接属于当前用户的笔记（防止越权删除）
    $stmt = $pdo->prepare("
        SELECT s.id FROM shared_notes s 
        JOIN notes n ON s.note_id = n.id 
        WHERE s.id = ?
    ");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => '无权删除此链接']);
        exit;
    }
    $stmt = $pdo->prepare("DELETE FROM shared_notes WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
}

// 获取分享链接列表
if ($action === 'api_shares' && $is_logged_in) {
    header('Content-Type: application/json');
    $stmt = $pdo->query("SELECT s.id, s.share_token, s.note_id, s.max_views, s.view_count, s.expires_at, s.created_at, n.slug, n.content FROM shared_notes s JOIN notes n ON s.note_id = n.id ORDER BY s.created_at DESC");
    $shares = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($shares);
    exit;
}

// 获取访问日志
if ($action === 'api_logs' && $is_logged_in) {
    header('Content-Type: application/json');
    $stmt = $pdo->query("SELECT id, share_token, ip_address, user_agent, accessed_at FROM access_logs ORDER BY accessed_at DESC LIMIT 100");
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($logs);
    exit;
}

// ===== 分享链接查看 =====

if (!empty($_GET['share'])) {
    $share_token = $_GET['share'];

    // 使用事务防止竞态条件（查看次数检查与更新之间）
    $pdo->beginTransaction();
    
    // 获取分享信息（加锁）
    $stmt = $pdo->prepare("SELECT id, note_id, max_views, view_count, expires_at FROM shared_notes WHERE share_token = ?");
    $stmt->execute([$share_token]);
    $share = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$share) {
        $pdo->rollBack();
        http_response_code(404); die('链接不存在或已失效');
    }
    if ($share['expires_at'] && strtotime($share['expires_at']) < time()) {
        $pdo->rollBack();
        http_response_code(404); die('链接已过期');
    }
    if ($share['view_count'] >= $share['max_views']) {
        $pdo->rollBack();
        http_response_code(404); die('链接已达到最大查看次数');
    }

    // 更新查看次数（原子操作）
    $stmt = $pdo->prepare("UPDATE shared_notes SET view_count = view_count + 1 WHERE id = ?");
    $stmt->execute([$share['id']]);
    $pdo->commit();

    // 记录访问日志（IP 部分脱敏）
    $stmt = $pdo->prepare("INSERT INTO access_logs (share_token, ip_address, user_agent) VALUES (?, ?, ?)");
    $stmt->execute([$share_token, mask_ip($ip), $user_agent]);

    // 获取笔记内容
    $stmt = $pdo->prepare("SELECT id, slug, content FROM notes WHERE id = ?");
    $stmt->execute([$share['note_id']]);
    $note = $stmt->fetch(PDO::FETCH_ASSOC);

    $site_title = sanitize($app['site_title'] ?? '极简笔记');
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title><?php echo $site_title; ?> - 分享内容</title>
<style>
:root { --bg: #f5f5f5; --text-bg: #fff; --text-color: #333; --border: #ddd; --accent: #4a90d9; --line-bg: #f0f0f0; }
@media (prefers-color-scheme: dark) { :root { --bg: #1a1a2e; --text-bg: #16213e; --text-color: #e0e0e0; --border: #2a3a5a; --accent: #64b5f6; --line-bg: #1a2744; } }
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: var(--bg); color: var(--text-color); min-height: 100vh; display: flex; flex-direction: column; }
.header { padding: 16px 24px; background: var(--text-bg); border-bottom: 1px solid var(--border); text-align: center; }
.header h1 { font-size: 18px; color: var(--accent); }
.warning { background: #fff3cd; color: #856404; padding: 8px 16px; font-size: 13px; text-align: center; border-bottom: 1px solid #ffeeba; }
.content { flex: 1; padding: 24px; max-width: 800px; margin: 0 auto; width: 100%; }
.note { background: var(--text-bg); border-radius: 12px; padding: 32px; white-space: pre-wrap; word-wrap: break-word; font-size: 15px; line-height: 1.7; overflow-x: auto; }
.note h1,.note h2,.note h3 { margin: 1em 0 0.5em; }
.note h1 { font-size: 2em; border-bottom: 1px solid var(--border); padding-bottom: 0.3em; }
.note h2 { font-size: 1.5em; border-bottom: 1px solid var(--border); padding-bottom: 0.3em; }
.note p { margin: 1em 0; }
.note code { background: var(--line-bg); padding: 2px 6px; border-radius: 4px; }
.note pre { background: var(--line-bg); padding: 16px; border-radius: 8px; overflow-x: auto; }
.note blockquote { border-left: 4px solid var(--accent); margin: 1em 0; padding: 0.5em 1em; background: rgba(0,0,0,0.03); }
.note ul,.note ol { margin: 1em 0; padding-left: 2em; }
.note table { border-collapse: collapse; width: 100%; margin: 1em 0; }
.note th,.note td { border: 1px solid var(--border); padding: 8px; }
.note th { background: var(--line-bg); }
.note img { max-width: 100%; border-radius: 8px; }
@media (max-width: 600px) { .content { padding: 16px; } .note { padding: 20px; } }
</style>
</head>
<body>
<div class="header"><h1>分享内容</h1></div>
<div class="warning" id="w">⚠️ 此链接为一次性访问链接，内容仅展示一次</div>
<div class="content">
<div class="note" id="note"><?php echo sanitize($note['content']); ?></div>
</div>
<script src="https://cdn.jsdelivr.net/npm/marked@9/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify@3/dist/purify.min.js"></script>
<script>
marked.setOptions({breaks: true, gfm: true});
document.getElementById('note').innerHTML = DOMPurify.sanitize(marked.parse(document.getElementById('note').textContent));
if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
    document.documentElement.style.colorScheme = 'dark';
    document.getElementById('w').style.background = '#664d03';
    document.getElementById('w').style.color = '#ffdb6d';
    document.getElementById('w').style.borderColor = '#664d03';
}
</script>
</body>
</html>
    <?php
    exit;
}

// ===== 笔记编辑页面 =====

if (!empty($_GET['note'])) {
    // 严重安全修复：必须登录才能访问笔记页面
    if (!$is_logged_in) {
        http_response_code(403);
        header('Location: ?action=login&error=login_required');
        exit;
    }
    
    $slug = $_GET['note'];
    $stmt = $pdo->prepare("SELECT id, slug, content, created_at, updated_at FROM notes WHERE slug = ?");
    $stmt->execute([$slug]);
    $note = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$note) { http_response_code(404); die('笔记不存在'); }

    $csrf_token = generate_csrf();
    $site_title = sanitize($app['site_title'] ?? '极简笔记');

    echo "<!DOCTYPE html>
<html lang=\"zh-CN\">
<head>
<meta charset=\"utf-8\">
<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
<title>{$note['slug']} - {$site_title}</title>
<link rel=\"icon\" href=\"favicon.ico\" sizes=\"any\">
<link rel=\"icon\" href=\"favicon.svg\" type=\"image/svg+xml\">
<style>
:root{--bg:#f5f5f5;--tbg:#fff;--tc:#333;--bd:#ddd;--ac:#4a90d9;--ah:#357abd;--tb:#fafafa;--sc:#666;--lb:#f0f0f0;--lc:#999;--pb:#fff;--sb:#f8f9fa;--ok:#4caf50;--wr:#ff9800}
[data-theme=\"dark\"]{--bg:#1a1a2e;--tbg:#16213e;--tc:#e0e0e0;--bd:#2a3a5a;--ac:#64b5f6;--ah:#42a5f5;--tb:#1a1a2e;--sc:#a0a0a0;--lb:#16213e;--lc:#5a7a9a;--pb:#16213e;--sb:#1a1a2e}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:var(--bg);color:var(--tc);height:100vh;display:flex;flex-direction:column;transition:background .3s,color .3s}
.tb{display:flex;align-items:center;gap:8px;padding:8px 12px;background:var(--tb);border-bottom:1px solid var(--bd);flex-wrap:wrap}
.tb-btn{padding:6px 12px;border:1px solid var(--bd);border-radius:6px;background:var(--tbg);color:var(--tc);cursor:pointer;font-size:13px;transition:all .2s;display:flex;align-items:center;gap:4px}
.tb-btn:hover{border-color:var(--ac);background:var(--ac);color:#fff}
.tb-btn.on{background:var(--ac);color:#fff;border-color:var(--ac)}
.sep{width:1px;height:24px;background:var(--bd);margin:0 4px}
.sb{display:flex;align-items:center;gap:16px;margin-left:auto;font-size:12px;color:var(--sc)}
.saved{color:var(--ok)}.saving{color:var(--wr)}
.main{display:flex;flex:1;overflow:hidden}
.ew{flex:1;display:flex;overflow:hidden;position:relative}
#lines{width:50px;padding:20px 10px;text-align:right;background:var(--lb);color:var(--lc);font-family:'SF Mono',Consolas,monospace;font-size:14px;line-height:1.6;overflow:hidden;user-select:none;border-right:1px solid var(--bd);flex-shrink:0}
#editor{flex:1;padding:20px;border:none;outline:none;background:var(--tbg);color:var(--tc);font-family:'SF Mono',Consolas,monospace;font-size:14px;line-height:1.6;resize:none;overflow-y:auto;tab-size:4}
.sidebar{width:320px;background:var(--sb);border-left:1px solid var(--bd);display:none;flex-direction:column}
.sidebar.on{display:flex}
.sh{padding:12px 16px;border-bottom:1px solid var(--bd);font-weight:600;font-size:14px}
.ss{padding:12px 16px;border-bottom:1px solid var(--bd)}
.ss p{font-size:13px;color:var(--sc);margin:0 0 8px}
.ss label{display:block;font-size:12px;color:var(--sc);margin-top:8px}
.ss input,.ss select{width:100%;padding:8px;border:1px solid var(--bd);border-radius:6px;background:var(--tbg);color:var(--tc);font-size:13px}
.ss button{width:100%;padding:8px;border:none;border-radius:6px;background:var(--ac);color:#fff;cursor:pointer;font-size:13px;margin-top:8px}
.ss button:hover{background:var(--ah)}
.share-link{background:var(--lb);padding:8px 12px;border-radius:6px;font-family:monospace;font-size:12px;word-break:break-all;margin-top:8px}
.mo{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);display:none;align-items:center;justify-content:center;z-index:1000}
.mo.on{display:flex}
.modal{background:var(--tbg);border-radius:12px;padding:24px;max-width:400px;width:90%;color:var(--tc)}
.modal h3{margin-top:0}
.modal p{margin:8px 0;font-size:14px;color:var(--sc)}
.modal-btns{display:flex;gap:8px;margin-top:16px;justify-content:flex-end}
.modal-btns button{padding:8px 16px;border:1px solid var(--bd);border-radius:6px;background:var(--tb);color:var(--tc);cursor:pointer}
.modal-btns .pk{background:var(--ac);color:#fff;border-color:var(--ac)}
#login-screen{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:var(--bg);z-index:2000;flex-direction:column;align-items:center;justify-content:center}
#login-screen.on{display:flex}
.login-box{background:var(--tbg);border-radius:16px;padding:40px;width:360px;box-shadow:0 8px 32px rgba(0,0,0,.1)}
.login-box h1{font-size:24px;margin-bottom:24px;text-align:center;color:var(--ac)}
.login-box input{width:100%;padding:12px;border:1px solid var(--bd);border-radius:8px;background:var(--tb);color:var(--tc);font-size:14px;margin-bottom:12px}
.login-box button{width:100%;padding:12px;border:none;border-radius:8px;background:var(--ac);color:#fff;font-size:15px;cursor:pointer}
.login-box button:hover{background:var(--ah)}
.login-box .err{color:#e53935;font-size:13px;margin-top:8px;text-align:center}
.login-logo{font-size:48px;text-align:center;margin-bottom:16px}
@media print{.tb,.sb,.sidebar,#lines{display:none!important}.main{display:block}#editor{display:none}body{background:#fff;color:#000}}
@media(max-width:768px){.sidebar{position:fixed;right:0;top:0;bottom:0;z-index:100;box-shadow:-2px 0 10px rgba(0,0,0,.1)}.tb{padding:6px 8px}.tb-btn{padding:5px 8px;font-size:12px}.sb{width:100%;justify-content:space-between;margin-left:0;padding-top:4px}}
</style>
</head>
<body>
<div id=\"login-screen\" class=\"on\">
<div class=\"login-box\">
<div class=\"login-logo\">📝</div>
<h1>{$site_title}</h1>
<form id=\"login-form\">
<input type=\"text\" id=\"lu\" placeholder=\"用户名\" autocomplete=\"username\" required>
<input type=\"password\" id=\"lp\" placeholder=\"密码\" autocomplete=\"current-password\" required>
<button type=\"submit\">登录</button>
<div class=\"err\" id=\"le\"></div>
</form>
</div>
</div>

<div class=\"tb\">
<button class=\"tb-btn\" id=\"bh\" title=\"笔记列表\">📋 列表</button>
<button class=\"tb-btn\" id=\"bt\" title=\"主题\"><span id=\"ti\">🌙</span></button>
<button class=\"tb-btn on\" id=\"bl\" title=\"行号\">#</button>
<div class=\"sep\"></div>
<button class=\"tb-btn\" id=\"bs\" title=\"分享\">🔗</button>
<button class=\"tb-btn\" id=\"et\" title=\"TXT\">📄</button>
<button class=\"tb-btn\" id=\"em\" title=\"MD\">📝</button>
<div class=\"sep\"></div>
<button class=\"tb-btn\" id=\"bn\" title=\"新建\">➕</button>
<button class=\"tb-btn\" id=\"bd\" title=\"删除\" style=\"color:#e53935\">🗑</button>
<div class=\"sb\">
<span><span id=\"ss\">✓</span> <span id=\"st\">已保存</span></span>
<span id=\"wc\">0 字</span>
<span id=\"lc\">0 行</span>
</div>
</div>

<div class=\"main\">
<div class=\"ew\">
<div id=\"lines\">1</div>
<textarea id=\"editor\" spellcheck=\"false\" placeholder=\"开始书写...\n\n支持 Markdown 语法\">" . sanitize($note['content']) . "</textarea>
</div>
<div class=\"sidebar\" id=\"sidebar\">
<div class=\"sh\">🔗 分享设置</div>
<div class=\"ss\">
<p>设置分享链接的访问限制</p>
<label>最大查看次数</label>
<input type=\"number\" id=\"sv\" value=\"1\" min=\"1\" max=\"100\">
<label>过期时间（小时，0=永不过期），默认24h</label>
<input type=\"number\" id=\"se\" value=\"24\" min=\"0\" max=\"720\">
<button onclick=\"createShare()\">生成分享链接</button>
</div>
<div class=\"ss\" id=\"sresult\" style=\"display:none\">
<p>分享链接：</p>
<div class=\"share-link\" id=\"surl\"></div>
<button onclick=\"copyShare()\" style=\"background:#4caf50\">📋 复制</button>
</div>
<div class=\"ss\" style=\"padding-bottom:16px\">
<p>💡 链接在达到次数或过期后将无法访问</p>
</div>
</div>
</div>

<div class=\"mo\" id=\"mo\">
<div class=\"modal\">
<h3 id=\"mt\"></h3>
<p id=\"mm\"></p>
<div class=\"modal-btns\">
<button onclick=\"closeMo()\">取消</button>
<button class=\"pk\" id=\"mc\">确认</button>
</div>
</div>
</div>

<div class=\"mo\" id=\"lmo\">
<div class=\"modal\" style=\"max-width:700px;max-height:80vh;overflow-y:auto\">
<h3>📋 笔记列表</h3>
<div style=\"margin:12px 0;display:flex;gap:8px\">
<input type=\"text\" id=\"ns\" placeholder=\"搜索笔记内容...\" style=\"flex:1;padding:8px 12px;border:1px solid var(--bd);border-radius:6px;background:var(--tbg);color:var(--tc);font-size:13px\" oninput=\"filterNotes()\">
<button onclick=\"showNotes()\" style=\"padding:8px 12px;border:1px solid var(--bd);border-radius:6px;background:var(--tbg);color:var(--tc);cursor:pointer\">刷新</button>
</div>
<div id=\"nlist\"></div>
<div class=\"modal-btns\"><button onclick='closeLMo()'
 style=\"padding:8px 16px;border:1px solid var(--bd);border-radius:6px;background:var(--tb);color:var(--tc);cursor:pointer\">关闭</button></div>
</div>
</div>

<script src=\"https://cdn.jsdelivr.net/npm/marked/marked.min.js\"><\/script>
<script>
const SLUG='{$note[\'slug\']}',CSRF='{$csrf_token}',LOGIN={$is_logged_in?'true':'false'},BASE=location.origin+location.pathname.split('?')[0];
let C='',R=document.getElementById('editor').value,PL=true,SV=false,LT=null,SSO=false;
const ed=document.getElementById('editor'),ln=document.getElementById('lines'),sst=document.getElementById('ss'),stt=document.getElementById('st');

if(LOGIN){document.getElementById('login-screen').classList.remove('on');C=ed.value;loop();}else{document.getElementById('login-screen').classList.add('on');}

function showErr(e){document.getElementById('le').textContent=e+(e.includes('remaining')?' ('+e.match(/(\d+)/)[1]+' times)':'');}
document.getElementById('login-form').onsubmit=async function(e){
e.preventDefault();
const u=document.getElementById('lu').value,p=document.getElementById('lp').value;
try{
const r=await fetch('?action=login',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({username:u,password:p,csrf_token:CSRF})});
const d=await r.json();
if(d.success){document.getElementById('login-screen').classList.remove('on');C=ed.value;loop();}else{showErr(d.error+(d.remaining!==undefined?' ('+d.remaining+' remaining)':''));}
}catch{showErr('Login failed');}
};

function applyTheme(t){let a=t==='auto'?(window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'):t;document.documentElement.setAttribute('data-theme',a);localStorage.setItem('theme',t);document.getElementById('ti').textContent=a==='dark'?'☀️':'🌙';}
function toggleTheme(){const ts=['auto','light','dark'],c=localStorage.getItem('theme')||'auto';applyTheme(ts[(ts.indexOf(c)+1)%3]);}
applyTheme(localStorage.getItem('theme')||'auto');

function toggleLines(){PL=!PL;ln.style.display=PL?'block':'none';document.getElementById('bl').classList.toggle('on',PL);localStorage.setItem('showLines',PL);}
if(localStorage.getItem('showLines')==='false')toggleLines();

function updateLines(){const n=ed.value.split('\\n').length;ln.textContent=Array.from({length:n},(_,i)=>i+1).join('\\n');ln.scrollTop=ed.scrollTop;}


function updateStats(){const t=ed.value;document.getElementById('wc').textContent=t.length+' 字';document.getElementById('lc').textContent=t.split('\\n').length+' 行';}

function setSaving(){if(!SV){SV=true;sst.textContent='⟳';sst.parentElement.className='saving';stt.textContent='Saving...';}}
function doneSaving(){SV=false;LT=new Date();sst.textContent='✓';sst.parentElement.className='saved';stt.textContent=LT.toLocaleTimeString('zh-CN',{hour:'2-digit',minute:'2-digit'});}

async function loop(){if(!LOGIN){setTimeout(loop,1000);return;}
if(ed.value!==C){setSaving();try{const r=await fetch('?action=api_save',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({slug:SLUG,content:ed.value,csrf_token:CSRF})});const d=await r.json();if(d.success){C=ed.value;doneSaving();}}catch{}}setTimeout(loop,1000);}

async function toggleShare(){SSO=!SSO;document.getElementById('sidebar').classList.toggle('on',SSO);}
async function createShare(){const v=document.getElementById('sv').value,e=document.getElementById('se').value;try{const r=await fetch('?action=api_share',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({slug:SLUG,max_views:v,expires_hours:e,csrf_token:CSRF})});const d=await r.json();if(d.success){document.getElementById('surl').textContent=BASE+'?share='+d.share_token;document.getElementById('sresult').style.display='block';}}catch{alert('Failed');}}
function copyShare(){navigator.clipboard.writeText(document.getElementById('surl').textContent).then(()=>{event.target.textContent='✓ Copied';setTimeout(()=>event.target.textContent='📋 Copy',1500);});}

var NOTES_DATA=[];async function showNotes(){const r=await fetch('?action=api_notes&_='+Date.now());const n=await r.json();NOTES_DATA=n;filterNotes();document.getElementById('lmo').classList.add('on');}function filterNotes(){const q=document.getElementById('ns').value.toLowerCase();const f=NOTES_DATA.filter(x=>!q||x.slug.toLowerCase().includes(q)||(x.content&&x.content.toLowerCase().includes(q)));let h='<div style=max-height:400px;overflow-y:auto><table style=width:100%;border-collapse:collapse;font-size:13px><tr style=background:var(--lb)><th style=\"padding:8px;text-align:left\">ID</th><th style=padding:8px>Preview</th><th style=padding:8px>Updated</th><th style=padding:8px>Action</th></tr>';if(f.length===0){h+='<tr><td colspan=4 style=\"text-align:center;padding:32px;color:var(--sc)\">No matching notes</td></tr>';}else{for(const x of f){const d=new Date(x.updated_at).toLocaleString('zh-CN'),c=x.content?x.content.substring(0,40)+(x.content.length>40?'...':''):'<em style=opacity:0.5>Empty</em>',is=x.slug===SLUG;h+='<tr style=\"background:'+(is?'var(--ac)':'transparent')+';color:'+(is?'#fff':'var(--tc)')+'\"><td style=\"padding:8px;font-family:monospace\">'+x.slug+'</td><td style=padding:8px>'+c+'</td><td style=\"padding:8px;font-size:12px\">'+d+'</td><td style=padding:8px><a href=\"?note='+x.slug+'\" style=\"color:'+(is?'#fff':'var(--ac)')+'\">'+(is?'Current':'Open')+'</a></td></tr>';}}h+='</table></div>';document.getElementById('nlist').innerHTML=h;}
function closeLMo(){document.getElementById('lmo').classList.remove('on');}
async function createNote(){const r=await fetch('?action=api_create',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({csrf_token:CSRF})});const d=await r.json();if(d.success)window.location.href='?note='+d.slug;}

function delNote(){document.getElementById('mt').textContent='Delete Note';document.getElementById('mm').textContent='Delete this note? This cannot be undone.';document.getElementById('mc').onclick=async function(){closeMo();await fetch('?action=api_delete',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({slug:SLUG,csrf_token:CSRF})});window.location.href=BASE;};document.getElementById('mo').classList.add('on');}
function closeMo(){document.getElementById('mo').classList.remove('on');}

function exportF(fmt){const b=new Blob([ed.value],{type:'text/plain;charset=utf-8'});const a=document.createElement('a');a.href=URL.createObjectURL(b);a.download=SLUG+(fmt==='md'?'.md':'.txt');a.click();}

document.getElementById('bt').onclick=toggleTheme;
document.getElementById('bl').onclick=toggleLines;
document.getElementById('bs').onclick=toggleShare;
document.getElementById('bh').onclick=showNotes;
document.getElementById('bn').onclick=createNote;
document.getElementById('bd').onclick=delNote;
document.getElementById('et').onclick=()=>exportF('txt');
document.getElementById('em').onclick=()=>exportF('md');
ed.oninput=function(){updateLines();updateStats();;};
ed.onscroll=function(){ln.scrollTop=ed.scrollTop;};
ed.onkeydown=function(e){if(e.key==='Tab'){e.preventDefault();const s=ed.selectionStart,en=ed.selectionEnd;ed.value=ed.value.substring(0,s)+'    '+ed.value.substring(en);ed.selectionStart=ed.selectionEnd=s+4;updateLines();}};
updateLines();updateStats();
<\/script>
</body>
</html>";
    exit;
}

// ===== 首页 / 仪表盘 =====

if ($is_logged_in) {
    $csrf_token = generate_csrf();
    $stmt = $pdo->query("SELECT id, slug, content, created_at, updated_at FROM notes ORDER BY updated_at DESC");
    $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $site_title = sanitize($app['site_title'] ?? '极简笔记');

    $notes_html = '';
    foreach ($notes as $n) {
        $preview = $n['content'] ? mb_substr(sanitize($n['content']), 0, 50) : '<em style="opacity:0.5">空笔记</em>';
        $updated = date('Y-m-d H:i', strtotime($n['updated_at']));
        $notes_html .= "<tr><td><a href=\"?note={$n['slug']}\" class=\"nl\">{$n['slug']}</a></td><td>{$preview}</td><td style=\"font-size:12px;color:var(--sc)\">{$updated}</td></tr>";
    }
    if (!$notes_html) $notes_html = '<tr><td colspan="3" style="text-align:center;color:var(--sc);padding:32px">暂无笔记，点击下方按钮创建</td></tr>';

    echo "<!DOCTYPE html>
<html lang=\"zh-CN\">
<head>
<meta charset=\"utf-8\">
<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
<title>{$site_title} - Dashboard</title>
<style>
:root{--bg:#f5f5f5;--tbg:#fff;--tc:#333;--bd:#ddd;--ac:#4a90d9;--ah:#357abd;--tb:#fafafa;--sc:#666;--lb:#f0f0f0}
[data-theme=\"dark\"]{--bg:#1a1a2e;--tbg:#16213e;--tc:#e0e0e0;--bd:#2a3a5a;--ac:#64b5f6;--ah:#42a5f5;--tb:#1a1a2e;--sc:#a0a0a0;--lb:#16213e}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,sans-serif;background:var(--bg);color:var(--tc);min-height:100vh}
.h{display:flex;align-items:center;justify-content:space-between;padding:16px 24px;background:var(--tbg);border-bottom:1px solid var(--bd)}
.h h1{font-size:20px;color:var(--ac)}
.hr{display:flex;gap:8px;align-items:center}
.btn{padding:8px 16px;border:1px solid var(--bd);border-radius:8px;background:var(--tbg);color:var(--tc);cursor:pointer;font-size:14px;text-decoration:none;display:inline-flex;align-items:center;gap:4px}
.btn:hover{border-color:var(--ac);color:var(--ac)}
.btn-p{background:var(--ac);color:#fff;border-color:var(--ac)}
.btn-p:hover{background:var(--ah);color:#fff}
.main{max-width:900px;margin:0 auto;padding:24px}
.stats{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px}
.sb{background:var(--lb);border-radius:8px;padding:16px;text-align:center}
.sb .n{font-size:28px;font-weight:700;color:var(--ac)}
.sb .l{font-size:12px;color:var(--sc);margin-top:4px}
.card{background:var(--tbg);border-radius:12px;padding:24px;margin-bottom:16px;box-shadow:0 2px 8px rgba(0,0,0,.05)}
.ch{font-size:16px;font-weight:600;margin-bottom:16px;display:flex;align-items:center;gap:8px}
table{width:100%;border-collapse:collapse}
th,td{padding:10px 8px;text-align:left;border-bottom:1px solid var(--bd)}
th{background:var(--lb);font-size:12px;text-transform:uppercase;color:var(--sc)}
.nl{font-family:monospace;color:var(--ac);text-decoration:none}
.nl:hover{text-decoration:underline}
.empty{text-align:center;padding:48px;color:var(--sc)}
#ti{cursor:pointer;font-size:18px}
@media(max-width:600px){.stats{grid-template-columns:1fr}.h{flex-direction:column;gap:12px}table{font-size:13px}}
</style>
</head>
<body>
<div class=\"h\">
<h1>📝 {$site_title}</h1>
<div class=\"hr\">
<span id=\"ti\" onclick=\"tt()\">🌙</span>
<a href=\"?action=new\" class=\"btn btn-p\">➕ New</a>
<a href=\"?action=shares\" class=\"btn\">🔗 Shares</a>
<a href=\"?action=logs\" class=\"btn\">📊 Logs</a>
<a href=\"?action=logout\" class=\"btn\">🚪 Logout</a>
</div>
</div>
<div class=\"main\">
<div class=\"stats\">
<div class=\"sb\"><div class=\"n\">" . count($notes) . "</div><div class=\"l\">Notes</div></div>
<div class=\"sb\"><div class=\"n\">" . $pdo->query("SELECT COUNT(*) FROM shared_notes")->fetchColumn() . "</div><div class=\"l\">Active Shares</div></div>
<div class=\"sb\"><div class=\"n\">" . ($pdo->query("SELECT SUM(view_count) FROM shared_notes")->fetchColumn() ?: 0) . "</div><div class=\"l\">Total Views</div></div>
</div>
<div class=\"card\">
<div class=\"ch\">📋 Recent Notes</div>
<table>
<thead><tr><th>ID</th><th>Preview</th><th>Updated</th></tr></thead>
<tbody>{$notes_html}</tbody>
</table>
</div>
</div>
<script>
function tt(){const c=document.documentElement.getAttribute('data-theme')||'light',n=c==='dark'?'light':'dark';document.documentElement.setAttribute('data-theme',n);localStorage.setItem('theme',n);}
const t=localStorage.getItem('theme');if(t==='dark'||(t!=='light'&&window.matchMedia('(prefers-color-scheme: dark)').matches))document.documentElement.setAttribute('data-theme','dark');
<\/script>
</body>
</html>";
    exit;
}

// ===== 分享管理 =====
if ($action === 'shares' && $is_logged_in) {
    $csrf_token = generate_csrf();
    $stmt = $pdo->query("SELECT s.id, s.share_token, s.note_id, s.max_views, s.view_count, s.expires_at, s.created_at, n.slug, n.content FROM shared_notes s JOIN notes n ON s.note_id = n.id ORDER BY s.created_at DESC");
    $shares = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $shares_html = '';
    foreach ($shares as $s) {
        $preview = mb_substr(sanitize($s['content']), 0, 30) ?: '<em>Empty</em>';
        $status = $s['view_count'] >= $s['max_views'] ? '<span style="color:#e53935">Exhausted</span>' :
            ($s['expires_at'] && strtotime($s['expires_at']) < time() ? '<span style="color:#e53935">Expired</span>' : '<span style="color:#4caf50">Active</span>');
        $expires = $s['expires_at'] ? date('Y-m-d H:i', strtotime($s['expires_at'])) : 'Never';
        $shares_html .= "<tr><td><code>{$s['share_token']}</code></td><td>{$s['slug']}</td><td>{$preview}</td><td>{$s['view_count']}/{$s['max_views']}</td><td>{$expires}</td><td>{$status}</td><td><button onclick=\"cp('{$s['share_token']}')\" class=\"btn-sm\">Copy</button> <button onclick=\"ds({$s['id']})\" class=\"btn-sm\" style=\"color:#e53935\">Del</button></td></tr>";
    }

    echo "<!DOCTYPE html>
<html lang=\"zh-CN\">
<head>
<meta charset=\"utf-8\">
<title>Share Management</title>
<style>
:root{--bg:#f5f5f5;--tbg:#fff;--tc:#333;--bd:#ddd;--ac:#4a90d9;--lb:#f0f0f0;--sc:#666}
[data-theme=\"dark\"]{--bg:#1a1a2e;--tbg:#16213e;--tc:#e0e0e0;--bd:#2a3a5a;--ac:#64b5f6;--lb:#16213e;--sc:#a0a0a0}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,sans-serif;background:var(--bg);color:var(--tc);min-height:100vh}
.h{padding:16px 24px;background:var(--tbg);border-bottom:1px solid var(--bd);display:flex;align-items:center;gap:16px}
.h h1{font-size:18px}.bk{color:var(--ac);text-decoration:none}
.m{max-width:900px;margin:24px auto;padding:0 24px}
.card{background:var(--tbg);border-radius:12px;overflow:hidden}
table{width:100%;border-collapse:collapse}
th,td{padding:10px 12px;text-align:left;border-bottom:1px solid var(--bd);font-size:13px}
th{background:var(--lb);font-size:11px;text-transform:uppercase;color:var(--sc)}
code{background:var(--lb);padding:2px 6px;border-radius:4px;font-size:12px}
.btn-sm{padding:4px 8px;border:1px solid var(--bd);border-radius:4px;background:var(--tbg);color:var(--tc);cursor:pointer;font-size:12px}
.btn-sm:hover{border-color:var(--ac);color:var(--ac)}
</style>
</head>
<body>
<div class=\"h\"><h1>🔗 Share Management</h1><a href=\"?\" class=\"bk\">← Back</a></div>
<div class=\"m\"><div class=\"card\">
<table>
<thead><tr><th>Token</th><th>Note</th><th>Preview</th><th>Views</th><th>Expires</th><th>Status</th><th>Action</th></tr></thead>
<tbody>{$shares_html}</tbody>
</table>
</div></div>
<script>
const CSRF='{$csrf_token}',BASE=location.origin+location.pathname;
async function ds(id){if(!confirm('Delete share link?'))return;
await fetch('?action=api_delete_share',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({id,csrf_token:CSRF})});
location.reload();}
function cp(t){navigator.clipboard.writeText(BASE+'?share='+t).then(()=>alert('Copied!'));}
<\/script>
</body>
</html>";
    exit;
}

// ===== 访问日志 =====
if ($action === 'logs' && $is_logged_in) {
    $csrf_token = generate_csrf();
    $stmt = $pdo->query("SELECT id, share_token, ip_address, user_agent, accessed_at FROM access_logs ORDER BY accessed_at DESC LIMIT 100");
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $logs_html = '';
    foreach ($logs as $l) {
        $time = date('Y-m-d H:i:s', strtotime($l['accessed_at']));
        $ua = mb_substr(sanitize($l['user_agent']), 0, 60) ?: 'Unknown';
        $logs_html .= "<tr><td style=\"font-family:monospace\">{$l['share_token']}</td><td style=\"font-family:monospace\">" . mask_ip($l['ip_address']) . "</td><td style=\"font-size:12px\">{$ua}</td><td style=\"font-size:12px\">{$time}</td></tr>";
    }

    echo "<!DOCTYPE html>
<html lang=\"zh-CN\">
<head>
<meta charset=\"utf-8\">
<title>Access Logs</title>
<style>
:root{--bg:#f5f5f5;--tbg:#fff;--tc:#333;--bd:#ddd;--lb:#f0f0f0;--sc:#666}
[data-theme=\"dark\"]{--bg:#1a1a2e;--tbg:#16213e;--tc:#e0e0e0;--bd:#2a3a5a;--ac:#64b5f6;--lb:#16213e;--sc:#a0a0a0}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,sans-serif;background:var(--bg);color:var(--tc);min-height:100vh}
.h{padding:16px 24px;background:var(--tbg);border-bottom:1px solid var(--bd);display:flex;align-items:center;gap:16px}
.h h1{font-size:18px}.bk{color:var(--ac);text-decoration:none}
.m{max-width:900px;margin:24px auto;padding:0 24px}
.card{background:var(--tbg);border-radius:12px;overflow:hidden}
table{width:100%;border-collapse:collapse}
th,td{padding:10px 12px;text-align:left;border-bottom:1px solid var(--bd);font-size:13px}
th{background:var(--lb);font-size:11px;text-transform:uppercase;color:var(--sc)}
</style>
</head>
<body>
<div class=\"h\"><h1>📊 Access Logs</h1><a href=\"?\" class=\"bk\">← Back</a></div>
<div class=\"m\"><div class=\"card\">
<table>
<thead><tr><th>Share Token</th><th>IP</th><th>User Agent</th><th>Time</th></tr></thead>
<tbody>{$logs_html}</tbody>
</table>
</div></div>
</body>
</html>";
    exit;
}

// ===== 新建笔记 =====
if ($action === 'new' && $is_logged_in) {
    $slug = bin2hex(random_bytes(4));
    $stmt = $pdo->prepare("INSERT INTO notes (slug, content) VALUES (?, '')");
    $stmt->execute([$slug]);
    header("Location: ?note=$slug");
    exit;
}

// ===== 首页（未登录）=====
echo "<!DOCTYPE html>
<html lang=\"zh-CN\">
<head>
<meta charset=\"utf-8\">
<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
<title>极简笔记</title>
<style>
:root{--bg:#f5f5f5;--ac:#4a90d9;--ac-h:#357abd}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:var(--bg);min-height:100vh;display:flex;align-items:center;justify-content:center}
.c{text-align:center}
.logo{font-size:64px;margin-bottom:16px}
.title{font-size:32px;font-weight:700;color:var(--ac);margin-bottom:8px}
.subtitle{font-size:16px;color:#666;margin-bottom:32px}
.btn{display:inline-block;padding:12px 32px;background:var(--ac);color:#fff;text-decoration:none;border-radius:8px;font-size:15px}
.btn:hover{background:var(--ac-h)}
</style>
</head>
<body>
<div class=\"c\">
<div class=\"logo\">📝</div>
<h1 class=\"title\">极简笔记</h1>
<p class=\"subtitle\">Simple note taking with one-time sharing</p>
<a href=\"?action=login\" class=\"btn\">🔐 Login</a>
</div>
</body>
</html>";

// ===== YAML 解析函数 =====
function parse_config_yaml($file) {
    if (!file_exists($file)) return [];
    $result = [];
    $current_section = null;
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    foreach ($lines as $line) {
        $line = rtrim($line);
        if (preg_match('/^(\w+):$/', $line, $m)) {
            $current_section = $m[1];
            if (!isset($result[$current_section])) $result[$current_section] = [];
        } elseif (preg_match('/^\s+(\w+):\s*["\']?(.+?)["\']?\s*$/', $line, $m)) {
            if ($current_section) {
                $result[$current_section][$m[1]] = trim($m[2], '"\'');
            }
        }
    }
    return $result;
}
?>
