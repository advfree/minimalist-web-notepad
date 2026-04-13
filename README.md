# Minimalist Web Notepad - 增强版

基于 [pereorga/minimalist-web-notepad](https://github.com/pereorga/minimalist-web-notepad) 改造，增加了账号登录、SQLite 数据库、一次性分享链接、访问日志等功能，支持 Docker 一键部署。

<p align="center">
<img src="https://img.shields.io/badge/PHP-8.2+-777BB4?style=flat-square&logo=php" alt="PHP">
<img src="https://img.shields.io/badge/Docker-Ready-2496ED?style=flat-square&logo=docker" alt="Docker">
<img src="https://img.shields.io/badge/SQLite-Lightweight-003188?style=flat-square&logo=sqlite" alt="SQLite">
<img src="https://img.shields.io/badge/Caddy-HTTP%20Proxy-8B5CF6?style=flat-square" alt="Caddy">
<img src="https://img.shields.io/badge/License-Apache%202.0-green?style=flat-square" alt="License">
</p>

---

## 功能特性

### 笔记管理
- **Markdown 实时预览** - 渲染标题、列表、代码块、表格、LaTeX 数学公式
- **暗色模式** - 自动 / 亮色 / 暗色三种模式
- **行号显示** - 可开关的编辑器行号
- **字数统计** - 实时显示字符数、行数
- **自动保存** - 每次输入后自动保存到服务器
- **导出文件** - 一键导出为 TXT 或 Markdown

### 账号安全
- **账号密码登录** - 通过 `config.yaml` 配置，无需数据库安装
- **会话超时** - 30 分钟无操作自动登出
- **账户锁定** - 连续 5 次密码错误，锁定 15 分钟
- **CSRF 防护** - 所有操作携带 CSRF Token
- **bcrypt 密码** - 业界标准密码哈希

### 一次性分享
- **按次限制** - 设置最大查看次数（默认为 1 次）
- **时效控制** - 设置过期时间（小时为单位，0=永不过期，默认为24小时）
- **访问日志** - 记录谁、何时、哪个链接被访问
- **权限关闭** - 次数或时间到后，链接返回 404（不暴露"已关闭"）

---

## 快速开始

### 1. 修改配置文件

编辑 `config.yaml`，**必须修改默认密码**：

```yaml
admin:
  username: admin
  # 生成你自己的 bcrypt 密码：https://bcrypt-generator.com/
  # 默认密码：admin123（请务必修改！）
  password_hash: "$2y$10$..."
```

### 2. Docker 启动（推荐）

```bash
git clone https://github.com/advfree/minimalist-web-notepad.git
cd minimalist-web-notepad

# 编辑 config.yaml 修改密码
# 然后启动
docker-compose up -d

# 访问 http://localhost:8080
# 使用 config.yaml 中配置的账号密码登录
```

### 3. VPS 手动部署

```bash
git clone https://github.com/advfree/minimalist-web-notepad.git
cd minimalist-web-notepad

# 赋予写权限
chmod 777 _data

# 使用 Caddy 反向代理（参考 Caddyfile 配置）
caddy run --config Caddyfile --adapter caddyfile
```

---

## 本地预览（无需安装 Docker/PHP）

如果只想先看看效果，直接打开 `notepad-preview.html` 即可在浏览器中体验全部编辑功能（数据保存在 localStorage，不联网）。

每次推送新代码后，重新生成预览：

```powershell
.\generate-preview.ps1
```

---

## 目录结构

```
minimalist-web-notepad/
├── index.php               # 主程序（单文件，PHP 8.2+）
├── config.yaml             # 用户配置文件（账号密码、安全设置）
├── Caddyfile               # Caddy 反向代理配置（HTTP 端口 8080）
├── notepad-preview.html    # 本地预览版（纯 HTML + JS，调试用）
├── generate-preview.ps1    # 预览版生成脚本
├── _data/                  # 数据目录（SQLite 数据库，自动创建）
│   └── notes.db            # SQLite 数据库文件（自动创建）
│   └── .gitkeep
├── Dockerfile              # Docker 镜像配置（Caddy + PHP-FPM）
├── docker-compose.yml      # Docker Compose 配置
├── README.md
└── LICENSE
```

---

## 配置说明

### config.yaml 完整配置

```yaml
# ===== 管理员账号密码 =====
admin:
  username: admin
  # bcrypt 加密后的密码
  password_hash: "$2y$10$..."

# ===== 安全设置 =====
security:
  # 会话超时时间（分钟）
  session_timeout: 30
  # 密码失败最大次数
  max_failed_attempts: 5
  # 锁定时间（分钟）
  lockout_duration: 15

# ===== 应用设置 =====
app:
  site_title: "极简笔记"
```

### 生成 bcrypt 密码

1. 访问 https://bcrypt-generator.com/
2. 输入您想要的密码
3. 复制生成的哈希值到 `config.yaml`

### Caddyfile 端口配置

Caddyfile 默认使用 HTTP 端口 8080，由外部反向代理（如 frp、Caddy、Nginx）提供 HTTPS。

```bash
# 端口配置在 Caddyfile 中，默认为 :8080
# 修改后重启容器即可
```

### 环境变量

| 变量 | 说明 |
|:---|:---|
| `TZ` | 时区，默认 `Asia/Shanghai` |

---

## API 接口

| 接口 | 方法 | 说明 |
|:---|:---|:---|
| `/?note=xxx` | GET | 访问笔记（需登录） |
| `/?note=xxx` | POST | 保存笔记内容（需登录） |
| `/?share=xxx` | GET | 查看一次性分享内容 |
| `/?action=login` | POST | 登录 |
| `/?action=api_notes` | GET | 获取笔记列表（需登录） |
| `/?action=api_create` | POST | 创建笔记（需登录） |
| `/?action=api_save` | POST | 保存笔记（需登录） |
| `/?action=api_share` | POST | 生成分享链接（需登录） |
| `/?action=logs` | GET | 访问日志（需登录） |
| `/?action=shares` | GET | 分享管理（需登录） |

---

## 安全说明

- 数据库文件存储在 `_data/` 目录，确保 Web 服务器配置禁止直接访问
- 分享 Token 使用 `random_bytes()` 生成，无法预测
- 所有用户输入经过 HTML 转义，防止 XSS
- 使用 PDO 预处理语句，防止 SQL 注入
- 建议在生产环境由外部反向代理提供 HTTPS

---

## License

基于 [Apache License 2.0](LICENSE)，感谢原项目 [pereorga/minimalist-web-notepad](https://github.com/pereorga/minimalist-web-notepad)。
