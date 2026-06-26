<div align="center">

# 🎮 主播模拟器论坛

### 主播模拟器（Streamer Simulator）玩家交流社区

[![GitHub stars](https://img.shields.io/github/stars/mawqer123/streamer-forum?style=flat-square)](https://github.com/mawqer123/streamer-forum/stargazers)
[![GitHub license](https://img.shields.io/badge/License-GPLv3-blue?style=flat-square)](https://www.gnu.org/licenses/gpl-3.0)
[![PHP](https://img.shields.io/badge/PHP-8.5-777BB4?style=flat-square&logo=php)](https://www.php.net/)
[![Rust](https://img.shields.io/badge/Rust-Axum-orange?style=flat-square&logo=rust)](https://www.rust-lang.org/)
[![Redis](https://img.shields.io/badge/Redis-6.2-DC382D?style=flat-square&logo=redis)](https://redis.io/)

**🌐 在线体验：[zbgame.hyperspark.cn](https://zbgame.hyperspark.cn)**

</div>

---

## 📌 这是什么？

**主播模拟器论坛** 是专为手机文字游戏《主播模拟器》（Streamer Simulator）打造的玩家交流社区。在这里可以：

- 🔥 **发布和分享 MOD** — 上传你的自定义 MOD，让其他玩家下载体验
- 💬 **玩家交流** — 讨论攻略、游戏技巧、版本更新
- 📸 **截图分享** — 晒出你的游戏成就和高光时刻
- 🤝 **找队友** — 群聊系统，实时交流组队
- ⭐ **收藏好贴** — 收藏喜欢的 MOD 和教程

## ✨ 核心功能

| 功能 | 说明 |
|------|------|
| 📱 **移动优先** | 完美适配手机浏览器，随时随地刷论坛 |
| 🎨 **多主题** | 亮色/暗色主题自由切换 |
| 🔑 **OAuth 登录** | 支持 GitHub、Gitee 一键登录 |
| 🖼️ **图片上传** | 拖拽上传，自动裁剪头像背景 |
| 🔔 **实时通知** | 评论回复、点赞、关注即时推送 |
| 💬 **群聊系统** | 内置群聊，实时交流 |
| ⚡ **高性能** | Rust API + Redis 缓存，秒级响应 |
| ✍️ **富文本发帖** | 支持 Markdown、图文混排 |
| 👑 **等级系统** | 发帖回帖赚积分升级 |
| 🎯 **SEO 友好** | 伪静态 URL，搜索引擎收录 |

## 🚀 快速体验

访问 **[zbgame.hyperspark.cn](https://zbgame.hyperspark.cn)** 即可直接使用！

## 🛠️ 技术架构

```
┌─────────────────────────────────────────┐
│               Nginx 反向代理              │
├────────────────┬────────────────────────┤
│  PHP 前端      │  Rust API (Axum 0.7)   │
│  (用户界面)     │  (高性能接口)            │
├────────────────┴────────────────────────┤
│              Redis 缓存                   │
├─────────────────────────────────────────┤
│              MySQL 数据库                 │
└─────────────────────────────────────────┘
```

### 技术栈

- **前端渲染**: PHP + JavaScript (ES6) + CSS
- **后端 API**: Rust (Axum 0.7, 端口 3001)
- **缓存层**: Redis 6.2 (Session 存储 + API 缓存加速)
- **数据库**: MySQL
- **Web 服务器**: Nginx

## 📂 目录结构

```
├── rust-api/          # Rust 高性能 API 服务
│   ├── src/
│   │   ├── main.rs    # 主入口 + 路由
│   │   ├── db.rs      # 数据库连接
│   │   ├── cache.rs   # Redis 缓存
│   │   ├── post.rs    # 帖子接口
│   │   ├── notify.rs  # 通知接口
│   │   ├── upload.rs  # 文件上传
│   │   ├── config.rs  # 配置管理
│   │   └── qq.rs      # QQ 登录
│   └── Cargo.toml
├── js/                # JavaScript / TypeScript
├── css/               # 样式文件
├── ts/                # TypeScript 源文件
├── uploads/           # 用户上传文件
└── st/                # 聊天室
```

## 💻 本地部署

### 环境要求

- PHP 8.0+
- MySQL 5.7+
- Redis 6.0+
- Nginx
- Rust (编译 API 用)

### 安装步骤

```bash
# 1. 克隆仓库
git clone https://github.com/mawqer123/streamer-forum.git
cd streamer-forum

# 2. 导入数据库
mysql -u root -p < database.sql

# 3. 配置
cp config.example.php config.php
# 编辑 config.php 填写数据库信息

# 4. 启动 Rust API
cd rust-api
cargo run --release

# 5. 启动 PHP + Redis
systemctl start nginx php-fpm redis-server

# 6. 访问 http://localhost
```

## ⭐ 支持项目

如果你觉得这个论坛对你有帮助，**请点一个 Star ⭐** 支持一下！

[![GitHub stars](https://img.shields.io/github/stars/mawqer123/streamer-forum?style=social)](https://github.com/mawqer123/streamer-forum)

## 📄 许可证

[GNU General Public License v3.0](LICENSE)

---

<div align="center">
Made with ❤️ for the Streamer Simulator community
</div>
