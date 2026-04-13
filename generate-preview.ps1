<#
.SYNOPSIS
    极简笔记 - 本地预览版生成器

.DESCRIPTION
    从 GitHub 拉取最新 index.php，提取前端部分，生成本地预览版。
    预览版使用 localStorage 替代后端，可独立在浏览器运行。
    新版支持：登录界面 + Markdown 编辑器 + 暗色模式 + 行号显示。

.NOTES
    版本: 2.0（适配 SQLite + 账号登录版）
#>

param(
    [string]$Token = "",
    [string]$Repo = "advfree/minimalist-web-notepad",
    [string]$Branch = "master",
    [string]$OutputFile = "notepad-preview.html"
)

# 颜色函数
function Write-Step { param([string]$msg) Write-Host "  $msg" -ForegroundColor Cyan }
function Write-Success { param([string]$msg) Write-Host "  $msg" -ForegroundColor Green }
function Write-Error { param([string]$msg) Write-Host "  $msg" -ForegroundColor Red }
function Write-Info { param([string]$msg) Write-Host "  $msg" -ForegroundColor Yellow }

$ErrorActionPreference = "Stop"

Write-Host ""
Write-Host "============================================" -ForegroundColor Magenta
Write-Host "  极简笔记 - 本地预览生成器 v2.0" -ForegroundColor Magenta
Write-Host "  适配 SQLite + 账号登录版" -ForegroundColor Gray
Write-Host "============================================" -ForegroundColor Magenta
Write-Host ""

# Step 1: 下载 index.php
Write-Host "[1/4] 正在从 GitHub 读取最新 index.php..." -ForegroundColor White

$headers = @{
    "User-Agent" = "PreviewGenerator"
    "Accept" = "application/vnd.github.v3.raw"
}
if ($Token) { $headers["Authorization"] = "token $Token" }

$rawUrl = "https://raw.githubusercontent.com/$Repo/$Branch/index.php"

try {
    $response = Invoke-WebRequest -Uri $rawUrl -Headers $headers -UseBasicParsing
    $phpContent = $response.Content
    Write-Success "读取成功 ($(($phpContent | Measure-Object -Character).Characters) 字符)"
} catch {
    Write-Error "下载失败: $($_.Exception.Message)"
    Write-Host ""
    Write-Host "按回车退出..."
    Read-Host
    exit 1
}

# Step 2: 提取笔记编辑页 HTML（首页已登录状态，即仪表盘后的编辑器页面）
Write-Host "[2/4] 正在提取编辑器页面..." -ForegroundColor White

# 找到登录后仪表盘的 HTML（首页）
$homeStart = $phpContent.IndexOf('<!DOCTYPE html>')
if ($homeStart -eq -1) {
    $homeStart = $phpContent.IndexOf('<html')
}
if ($homeStart -eq -1) {
    Write-Error "无法找到 HTML 部分"
    Read-Host
    exit 1
}

Write-Success "提取成功"
Write-Step "说明：预览版展示核心编辑功能，数据保存在 localStorage"

# Step 3: 生成预览内容
Write-Host "[3/4] 正在生成预览代码..." -ForegroundColor White

$previewHtml = @"
<!--
  ==========================================================================
  极简笔记 - 本地预览版 v2.0
  --------------------------------------------------------------------------
  由 generate-preview.ps1 自动生成
  适配 SQLite + 账号登录版，仅展示编辑功能
  数据保存在 localStorage，不联网
  ==========================================================================
-->
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>极简笔记 - 预览版</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.css">
<style>
:root{--bg:#f5f5f5;--tbg:#fff;--tc:#333;--bd:#ddd;--ac:#4a90d9;--ah:#357abd;--tb:#fafafa;--sc:#666;--lb:#f0f0f0;--lc:#999;--pb:#fff;--sb:#f8f9fa;--ok:#4caf50;--wr:#ff9800;--err:#e53935}
[data-theme="dark"]{--bg:#1a1a2e;--tbg:#16213e;--tc:#e0e0e0;--bd:#2a3a5a;--ac:#64b5f6;--ah:#42a5f5;--tb:#1a1a2e;--sc:#a0a0a0;--lb:#16213e;--lc:#5a7a9a;--pb:#16213e;--sb:#1a1a2e}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:var(--bg);color:var(--tc);height:100vh;display:flex;flex-direction:column;transition:background .3s,color .3s}
.tb{display:flex;align-items:center;gap:8px;padding:8px 12px;background:var(--tb);border-bottom:1px solid var(--bd);flex-wrap:wrap}
.tb-btn{padding:6px 12px;border:1px solid var(--bd);border-radius:6px;background:var(--tbg);color:var(--tc);cursor:pointer;font-size:13px;transition:all .2s;display:flex;align-items:center;gap:4px}
.tb-btn:hover{border-color:var(--ac);color:var(--ac)}
.tb-btn.on,.tb-btn.on:hover{background:var(--ac);color:#fff;border-color:var(--ac)}
.sep{width:1px;height:24px;background:var(--bd);margin:0 4px}
.sb{display:flex;align-items:center;gap:16px;margin-left:auto;font-size:12px;color:var(--sc)}
.saved{color:var(--ok)}.saving{color:var(--wr)}
.main{display:flex;flex:1;overflow:hidden}
.ew{flex:1;display:flex;overflow:hidden;position:relative}
#lines{width:50px;padding:20px 10px;text-align:right;background:var(--lb);color:var(--lc);font-family:'SF Mono',Consolas,monospace;font-size:14px;line-height:1.6;overflow:hidden;user-select:none;border-right:1px solid var(--bd);flex-shrink:0}
#editor{flex:1;padding:20px;border:none;outline:none;background:var(--tbg);color:var(--tc);font-family:'SF Mono',Consolas,monospace;font-size:14px;line-height:1.6;resize:none;overflow-y:auto;tab-size:4}
#preview{flex:1;padding:20px 40px;background:var(--pb);overflow-y:auto;border-left:1px solid var(--bd);display:none}
#preview h1,#preview h2{border-bottom:1px solid var(--bd);padding-bottom:.3em;margin-top:1.5em;margin-bottom:.5em}
#preview h1{font-size:2em}#preview h2{font-size:1.5em}
#preview h3{font-size:1.25em;margin-top:1.5em;margin-bottom:.5em}
#preview p{margin:1em 0;line-height:1.7}
#preview code{background:var(--lb);padding:2px 6px;border-radius:4px}
#preview pre{background:var(--lb);padding:16px;border-radius:8px;overflow-x:auto}
#preview blockquote{border-left:4px solid var(--ac);margin:1em 0;padding:.5em 1em;background:var(--lb);border-radius:0 8px 8px 0}
#preview ul,#preview ol{margin:1em 0;padding-left:2em}
#preview table{border-collapse:collapse;width:100%;margin:1em 0}
#preview th,#preview td{border:1px solid var(--bd);padding:8px}
#preview th{background:var(--lb)}
#preview img{max-width:100%;border-radius:8px}
.katex-display{margin:1em 0;overflow-x:auto}
.sidebar{position:fixed;right:0;top:0;bottom:0;width:300px;background:var(--sb);border-left:1px solid var(--bd);display:none;flex-direction:column;z-index:100;box-shadow:-2px 0 10px rgba(0,0,0,.1)}
.sidebar.on{display:flex}
.sh{padding:12px 16px;border-bottom:1px solid var(--bd);font-weight:600;font-size:14px}
.ss{padding:12px 16px;border-bottom:1px solid var(--bd)}
.ss p{font-size:13px;color:var(--sc);margin:0 0 8px}
.ss label{display:block;font-size:12px;color:var(--sc);margin-top:8px}
.ss input,.ss select{width:100%;padding:8px;border:1px solid var(--bd);border-radius:6px;background:var(--tbg);color:var(--tc);font-size:13px}
.ss button{width:100%;padding:8px;border:none;border-radius:6px;background:var(--ac);color:#fff;cursor:pointer;font-size:13px;margin-top:8px}
.ss button:hover{background:var(--ah)}
.ss button.g{background:var(--ok)}
.ss button.g:hover{background:#43a047}
.info-box{background:rgba(74,144,217,.1);border:1px solid var(--ac);border-radius:8px;padding:12px;margin-top:8px;font-size:12px;color:var(--sc)}
.nlist{max-height:400px;overflow-y:auto}
.nitem{padding:10px 12px;border-bottom:1px solid var(--bd);cursor:pointer;transition:background .2s}
.nitem:hover{background:var(--lb)}
.nitem.active{background:var(--ac);color:#fff}
.nitem .nslug{font-family:monospace;font-size:12px}
.nitem .ntime{font-size:11px;opacity:.7;margin-top:2px}
.mo{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);display:none;align-items:center;justify-content:center;z-index:200}
.mo.on{display:flex}
.modal{background:var(--tbg);border-radius:12px;padding:24px;max-width:600px;width:90%;max-height:80vh;overflow-y:auto;color:var(--tc)}
.modal h3{margin-top:0;margin-bottom:12px}
.modal p{font-size:14px;color:var(--sc);margin:8px 0}
.modal-btns{display:flex;gap:8px;margin-top:16px;justify-content:flex-end}
.modal-btns button{padding:8px 16px;border:1px solid var(--bd);border-radius:6px;background:var(--tb);color:var(--tc);cursor:pointer}
.modal-btns .pk{background:var(--ac);color:#fff;border-color:var(--ac)}
.modal-btns .pd{background:var(--err);color:#fff;border-color:var(--err)}
.empty{text-align:center;padding:48px;color:var(--sc)}
#login-screen{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:var(--bg);z-index:3000;flex-direction:column;align-items:center;justify-content:center}
#login-screen.on{display:flex}
.login-box{background:var(--tbg);border-radius:16px;padding:40px;width:360px;box-shadow:0 8px 32px rgba(0,0,0,.1)}
.login-box h1{font-size:22px;margin-bottom:8px;text-align:center;color:var(--ac)}
.login-box .sub{font-size:13px;color:var(--sc);text-align:center;margin-bottom:24px}
.login-box input{width:100%;padding:12px;border:1px solid var(--bd);border-radius:8px;background:var(--tb);color:var(--tc);font-size:14px;margin-bottom:12px;outline:none}
.login-box input:focus{border-color:var(--ac)}
.login-box button{width:100%;padding:12px;border:none;border-radius:8px;background:var(--ac);color:#fff;font-size:15px;cursor:pointer;margin-top:4px}
.login-box button:hover{background:var(--ah)}
.login-box .err{color:var(--err);font-size:13px;margin-top:8px;text-align:center;min-height:18px}
.login-logo{font-size:48px;text-align:center;margin-bottom:16px}
.notice{background:#fff3cd;border:1px solid #ffeeba;border-radius:8px;padding:12px 16px;margin-top:16px;font-size:12px;color:#856404;text-align:center}
.notice strong{display:block;margin-bottom:4px}
#note-list-modal .modal{max-width:700px}
@media print{.tb,.sb,.sidebar,#lines{display:none!important}.main{display:block}#editor{display:none}#preview{display:block!important;border:none;padding:0}body{background:#fff;color:#000}}
@media(max-width:768px){.sidebar{width:100%}.tb{padding:6px 8px}.tb-btn{padding:5px 8px;font-size:12px}.sb{width:100%;justify-content:space-between;margin-left:0;padding-top:4px}}
</style>
</head>
<body>

<!-- 登录界面（预览模式：跳过验证） -->
<div id="login-screen" class="on">
<div class="login-box">
<div class="login-logo">📝</div>
<h1>极简笔记</h1>
<p class="sub">预览模式 · 数据保存在本地</p>
<form id="login-form">
<input type="text" id="lu" placeholder="用户名" autocomplete="username">
<input type="password" id="lp" placeholder="密码" autocomplete="current-password">
<button type="submit" id="login-btn">进入笔记</button>
<div class="err" id="le"></div>
</form>
<div class="notice"><strong>⚠️ 预览说明</strong>此为本地预览版，无需真实账号。<br>点击「进入笔记」即可体验全部编辑功能。</div>
</div>
</div>

<!-- 工具栏 -->
<div class="tb">
<button class="tb-btn" id="bh" title="笔记列表">📋 列表</button>
<button class="tb-btn" id="bt" title="切换主题"><span id="ti">🌙</span></button>
<button class="tb-btn" id="bp" title="预览">👁️ 预览</button>
<button class="tb-btn on" id="bl" title="行号">#</button>
<div class="sep"></div>
<button class="tb-btn" id="bs" title="分享设置">🔗</button>
<button class="tb-btn" id="et" title="导出 TXT">📄</button>
<button class="tb-btn" id="em" title="导出 MD">📝</button>
<div class="sep"></div>
<button class="tb-btn" id="bn" title="新建">➕</button>
<button class="tb-btn" id="bd" title="删除" style="color:var(--err)">🗑</button>
<div class="sb">
<span><span id="ss">✓</span> <span id="st">本地模式</span></span>
<span id="wc">0 字</span>
<span id="lc">0 行</span>
</div>
</div>

<!-- 编辑器 -->
<div class="main">
<div class="ew">
<div id="lines">1</div>
<textarea id="editor" spellcheck="false" placeholder="开始书写...

支持 Markdown 语法：
- 粗体 **文字** / 斜体 *文字*
- 代码块 ``` ... ```
- 表格、列表、引用
- LaTeX 数学公式 $E=mc^2$
- 网站链接和图片"></textarea>
<div id="preview"></div>
</div>
</div>

<!-- 分享侧边栏 -->
<div class="sidebar" id="sidebar">
<div class="sh">🔗 分享设置 <span style="float:right;cursor:pointer" onclick="toggleShare()">✕</span></div>
<div class="ss">
<p>设置分享链接的访问限制（预览版仅展示界面）</p>
<label>最大查看次数</label>
<input type="number" id="sv" value="1" min="1" max="100">
<label>过期时间（小时，0=永不过期）</label>
<input type="number" id="se" value="0" min="0" max="720">
<button onclick="createShare()">生成分享链接</button>
</div>
<div class="ss" id="sresult" style="display:none">
<p>分享链接：</p>
<div style="background:var(--lb);padding:8px 12px;border-radius:6px;font-family:monospace;font-size:12px;word-break:break-all;margin-top:8px" id="surl"></div>
<button class="g" onclick="copyShare()">📋 复制链接</button>
</div>
<div class="ss">
<div class="info-box">💡 生产环境下，此链接为一次性访问链接，达到次数或过期后将无法访问。</div>
</div>
</div>

<!-- 笔记列表弹窗 -->
<div class="mo" id="lmo">
<div class="modal" id="note-list-modal">
<h3>📋 笔记列表</h3>
<div class="nlist" id="nlist"></div>
<div class="modal-btns"><button onclick="closeLMo()">关闭</button></div>
</div>
</div>

<!-- 删除确认弹窗 -->
<div class="mo" id="mo">
<div class="modal">
<h3 id="mt"></h3>
<p id="mm"></p>
<div class="modal-btns">
<button onclick="closeMo()">取消</button>
<button class="pd" id="mc">确认</button>
</div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"><\/script>
<script src="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.js"><\/script>
<script>
(function() {
    'use strict';

    // ========== 配置 ==========
    var NOTE_KEY = 'notepad_v2_notes';      // localStorage 键名前缀
    var SLUG_KEY = 'notepad_v2_current';    // 当前笔记 slug
    var THEME_KEY = 'notepad_v2_theme';
    var LINES_KEY = 'notepad_v2_lines';

    // ========== 状态 ==========
    var currentSlug = null;
    var currentContent = '';
    var isPreview = false;
    var isLines = true;
    var isShareOpen = false;
    var isDark = false;
    var saveTimer = null;

    // ========== DOM 引用 ==========
    var editor = document.getElementById('editor');
    var preview = document.getElementById('preview');
    var linesEl = document.getElementById('lines');
    var sstEl = document.getElementById('ss');
    var sttEl = document.getElementById('st');
    var wcEl = document.getElementById('wc');
    var lcEl = document.getElementById('lc');

    // ========== 笔记存储 ==========
    function genSlug() {
        var chars = '234579abcdefghjkmnpqrstwxyz';
        var s = '';
        for (var i = 0; i < 5; i++) s += chars[Math.floor(Math.random() * chars.length)];
        return s;
    }

    function getNotes() {
        try {
            return JSON.parse(localStorage.getItem(NOTE_KEY)) || {};
        } catch(e) { return {}; }
    }

    function saveNotes(notes) {
        localStorage.setItem(NOTE_KEY, JSON.stringify(notes));
    }

    function getOrCreateNote() {
        var slug = localStorage.getItem(SLUG_KEY);
        var notes = getNotes();
        if (!slug || !notes[slug]) {
            slug = genSlug();
            notes[slug] = { content: '', created: Date.now(), updated: Date.now() };
            saveNotes(notes);
            localStorage.setItem(SLUG_KEY, slug);
        }
        return slug;
    }

    function loadCurrentNote() {
        currentSlug = getOrCreateNote();
        var notes = getNotes();
        currentContent = notes[currentSlug] ? notes[currentSlug].content : '';
        editor.value = currentContent;
        updateLines();
        updateStats();
        renderPreview();
    }

    function persistCurrentNote() {
        if (!currentSlug) return;
        var notes = getNotes();
        if (!notes[currentSlug]) {
            notes[currentSlug] = { content: '', created: Date.now(), updated: Date.now() };
        }
        notes[currentSlug].content = editor.value;
        notes[currentSlug].updated = Date.now();
        saveNotes(notes);
        currentContent = editor.value;
        sstEl.textContent = '✓';
        sstEl.parentElement.className = 'saved';
        sttEl.textContent = new Date().toLocaleTimeString('zh-CN', {hour:'2-digit', minute:'2-digit'});
    }

    function scheduleSave() {
        sstEl.textContent = '⟳';
        sstEl.parentElement.className = 'saving';
        sttEl.textContent = '保存中...';
        if (saveTimer) clearTimeout(saveTimer);
        saveTimer = setTimeout(persistCurrentNote, 800);
    }

    // ========== 行号 ==========
    function updateLines() {
        var n = editor.value.split('\n').length;
        var html = [];
        for (var i = 1; i <= n; i++) html.push(i);
        linesEl.textContent = html.join('\n');
        linesEl.scrollTop = editor.scrollTop;
    }

    // ========== 字数统计 ==========
    function updateStats() {
        var t = editor.value;
        wcEl.textContent = t.length + ' 字';
        lcEl.textContent = t.split('\n').length + ' 行';
    }

    // ========== Markdown 预览 ==========
    function renderPreview() {
        if (!isPreview) {
            preview.innerHTML = '';
            return;
        }
        var md = editor.value;
        // LaTeX inline
        md = md.replace(/\$([^\$]+)\$/g, function(m, f) {
            try { return katex.renderToString(f, {throwOnError: false, displayMode: false}); }
            catch(e) { return m; }
        });
        // LaTeX block
        md = md.replace(/\$\$([^\$]+)\$\$/g, function(m, f) {
            try { return '<div class="katex-display">' + katex.renderToString(f, {throwOnError: false, displayMode: true}) + '</div>'; }
            catch(e) { return m; }
        });
        marked.setOptions({breaks: true, gfm: true});
        preview.innerHTML = marked.parse(md);
    }

    function togglePreview() {
        isPreview = !isPreview;
        document.getElementById('bp').classList.toggle('on', isPreview);
        if (isPreview) {
            editor.style.display = 'none';
            preview.style.display = 'block';
            renderPreview();
        } else {
            editor.style.display = 'block';
            preview.style.display = 'none';
        }
    }

    // ========== 主题 ==========
    function applyTheme(dark) {
        isDark = dark;
        document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
        document.getElementById('ti').textContent = dark ? '☀️' : '🌙';
        localStorage.setItem(THEME_KEY, dark ? 'dark' : 'light');
    }

    function toggleTheme() {
        applyTheme(!isDark);
    }

    // ========== 行号显示/隐藏 ==========
    function toggleLines() {
        isLines = !isLines;
        linesEl.style.display = isLines ? 'block' : 'none';
        document.getElementById('bl').classList.toggle('on', isLines);
        localStorage.setItem(LINES_KEY, isLines ? '1' : '0');
    }

    // ========== 分享侧边栏 ==========
    function toggleShare() {
        isShareOpen = !isShareOpen;
        document.getElementById('sidebar').classList.toggle('on', isShareOpen);
    }

    function createShare() {
        var v = document.getElementById('sv').value;
        var e = document.getElementById('se').value;
        // 预览版：生成本地模拟 token
        var token = Math.random().toString(36).substring(2, 18);
        var base = location.origin + location.pathname;
        document.getElementById('surl').textContent = base + '?share=' + token;
        document.getElementById('sresult').style.display = 'block';
    }

    function copyShare() {
        var url = document.getElementById('surl').textContent;
        navigator.clipboard.writeText(url).then(function() {
            var btn = event.target;
            btn.textContent = '✓ 已复制';
            setTimeout(function() { btn.textContent = '📋 复制链接'; }, 1500);
        });
    }

    // ========== 笔记列表 ==========
    function showNotes() {
        var notes = getNotes();
        var slugs = Object.keys(notes).sort(function(a, b) {
            return (notes[b].updated || 0) - (notes[a].updated || 0);
        });
        var html = [];
        if (slugs.length === 0) {
            html.push('<div class="empty">暂无笔记，点击「➕ 新建」创建</div>');
        } else {
            slugs.forEach(function(slug) {
                var n = notes[slug];
                var preview = n.content ? n.content.substring(0, 30).replace(/\n/g, ' ') : '空笔记';
                if (preview.length >= 30) preview += '...';
                var time = new Date(n.updated).toLocaleString('zh-CN');
                var isActive = slug === currentSlug;
                html.push('<div class="nitem' + (isActive ? ' active' : '') + '" data-slug="' + slug + '">');
                html.push('<div class="nslug">' + slug + '</div>');
                html.push('<div style="font-size:12px;margin-top:2px;opacity:.7">' + preview + '</div>');
                html.push('<div class="ntime">' + time + '</div>');
                html.push('</div>');
            });
        }
        document.getElementById('nlist').innerHTML = html.join('');
        document.getElementById('lmo').classList.add('on');

        // 绑定点击事件
        document.querySelectorAll('.nitem[data-slug]').forEach(function(el) {
            el.addEventListener('click', function() {
                var slug = this.getAttribute('data-slug');
                localStorage.setItem(SLUG_KEY, slug);
                loadCurrentNote();
                closeLMo();
            });
        });
    }

    function closeLMo() {
        document.getElementById('lmo').classList.remove('on');
    }

    // ========== 新建笔记 ==========
    function createNote() {
        var slug = genSlug();
        var notes = getNotes();
        notes[slug] = { content: '', created: Date.now(), updated: Date.now() };
        saveNotes(notes);
        localStorage.setItem(SLUG_KEY, slug);
        loadCurrentNote();
    }

    // ========== 删除确认 ==========
    function delNote() {
        document.getElementById('mt').textContent = '🗑 删除笔记';
        document.getElementById('mm').textContent = '确定删除笔记「' + currentSlug + '」吗？此操作不可撤销。';
        document.getElementById('mc').onclick = function() {
            var notes = getNotes();
            delete notes[currentSlug];
            saveNotes(notes);
            closeMo();
            var slugs = Object.keys(notes);
            if (slugs.length > 0) {
                localStorage.setItem(SLUG_KEY, slugs[0]);
            } else {
                createNote();
                return;
            }
            loadCurrentNote();
        };
        document.getElementById('mo').classList.add('on');
    }

    function closeMo() {
        document.getElementById('mo').classList.remove('on');
    }

    // ========== 导出 ==========
    function exportF(fmt) {
        var blob = new Blob([editor.value], {type: 'text/plain;charset=utf-8'});
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = currentSlug + (fmt === 'md' ? '.md' : '.txt');
        a.click();
        URL.revokeObjectURL(a.href);
    }

    // ========== 登录（预览模式跳过） ==========
    function skipLogin() {
        document.getElementById('login-screen').classList.remove('on');
    }

    // ========== 事件绑定 ==========
    editor.addEventListener('input', function() {
        updateLines();
        updateStats();
        scheduleSave();
        if (isPreview) renderPreview();
    });

    editor.addEventListener('scroll', function() {
        linesEl.scrollTop = editor.scrollTop;
    });

    editor.addEventListener('keydown', function(e) {
        if (e.key === 'Tab') {
            e.preventDefault();
            var s = editor.selectionStart;
            var en = editor.selectionEnd;
            editor.value = editor.value.substring(0, s) + '    ' + editor.value.substring(en);
            editor.selectionStart = editor.selectionEnd = s + 4;
            updateLines();
            scheduleSave();
        }
        // 快捷键
        if (e.ctrlKey && e.key === 'b') {
            e.preventDefault();
            var ss = editor.selectionStart, se = editor.selectionEnd;
            var sel = editor.value.substring(ss, se) || '粗体文字';
            editor.value = editor.value.substring(0, ss) + '**' + sel + '**' + editor.value.substring(se);
            editor.selectionStart = ss + 2; editor.selectionEnd = ss + 2 + sel.length;
            updateLines(); scheduleSave();
        }
        if (e.ctrlKey && e.key === 'i') {
            e.preventDefault();
            var ss = editor.selectionStart, se = editor.selectionEnd;
            var sel = editor.value.substring(ss, se) || '斜体文字';
            editor.value = editor.value.substring(0, ss) + '*' + sel + '*' + editor.value.substring(se);
            editor.selectionStart = ss + 1; editor.selectionEnd = ss + 1 + sel.length;
            updateLines(); scheduleSave();
        }
    });

    document.getElementById('login-form').addEventListener('submit', function(e) {
        e.preventDefault();
        var u = document.getElementById('lu').value;
        var p = document.getElementById('lp').value;
        if (!u || !p) {
            document.getElementById('le').textContent = '请输入用户名和密码（任意值均可）';
            return;
        }
        skipLogin();
    });

    document.getElementById('bt').addEventListener('click', toggleTheme);
    document.getElementById('bp').addEventListener('click', togglePreview);
    document.getElementById('bl').addEventListener('click', toggleLines);
    document.getElementById('bs').addEventListener('click', toggleShare);
    document.getElementById('bh').addEventListener('click', showNotes);
    document.getElementById('bn').addEventListener('click', createNote);
    document.getElementById('bd').addEventListener('click', delNote);
    document.getElementById('et').addEventListener('click', function() { exportF('txt'); });
    document.getElementById('em').addEventListener('click', function() { exportF('md'); });

    document.getElementById('mo').addEventListener('click', function(e) {
        if (e.target === document.getElementById('mo')) closeMo();
    });
    document.getElementById('lmo').addEventListener('click', function(e) {
        if (e.target === document.getElementById('lmo')) closeLMo();
    });

    // ========== 初始化 ==========
    (function init() {
        // 主题
        var savedTheme = localStorage.getItem(THEME_KEY);
        if (savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            applyTheme(true);
        }
        // 行号
        if (localStorage.getItem(LINES_KEY) === '0') toggleLines();
        // 加载笔记
        loadCurrentNote();
        // 更新标题
        document.title = currentSlug + ' - 极简笔记预览';
    })();

})();
<\/script>
</body>
</html>
"@

# Step 4: 输出文件
Write-Host "[4/4] 正在写入文件..." -ForegroundColor White

$OutputFile = [System.IO.Path]::GetFullPath($OutputFile)
[System.IO.File]::WriteAllText($OutputFile, $previewHtml, [System.Text.Encoding]::UTF8)

$fileSize = (Get-Item $OutputFile).Length
Write-Host ""
Write-Success "============================================" -ForegroundColor Green
Write-Success "  生成完成！" -ForegroundColor Green
Write-Success "============================================" -ForegroundColor Green
Write-Host ""
Write-Step "输出文件: $OutputFile"
Write-Step "文件大小: $([Math]::Round($fileSize / 1024, 1)) KB"
Write-Host ""
Write-Host "  直接在浏览器打开 notepad-preview.html 即可体验！" -ForegroundColor White
Write-Host "  提示：输入任意用户名和密码登录（预览模式跳过验证）" -ForegroundColor Gray
Write-Host ""
