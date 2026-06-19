# 攒股收息 — 股息投资收益追踪器

智能追踪您的股票投资收益，轻松管理股息收入。

## 功能特点

- 📊 持仓一目了然 — 饼图展示持仓分布，每只股票的市值、盈亏、股息率清晰可见
- 💰 股息收益追踪 — 自动计算基于成本的股息收益率，让复利增长看得见
- 🔍 智能股票搜索 — 支持 A股、港股、美股，输入代码或名称即可快速添加
- 🔒 数据安全可靠 — 您的数据加密存储在服务器上，随时随地安全访问

## 默认账号

部署后默认账号：
- 用户名：test
- 密码：123456

## 部署指南

### 环境要求

- PHP 7.4+
- MySQL 5.7+ / MariaDB 10.3+
- Nginx / Apache

### 安装步骤

1. 克隆代码到服务器
```bash
git clone https://github.com/mongoncn/dividend-site.git
```

2. 创建数据库
```sql
CREATE DATABASE dividend_site CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'dividend_user'@'localhost' IDENTIFIED BY 'your_password';
GRANT ALL PRIVILEGES ON dividend_site.* TO 'dividend_user'@'localhost';
FLUSH PRIVILEGES;
```

3. 修改数据库配置
编辑 `api/config.php`，填入你的数据库信息：
```php
$host = 'localhost';
$dbname = 'dividend_site';
$username = 'dividend_user';
$password = 'your_password';
```

4. 初始化数据库
访问 `http://your-domain/api/init.php`

5. 配置 Nginx
```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /path/to/dividend-site;
    index index.html index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

## 技术栈

- 前端：HTML5, CSS3, JavaScript, Chart.js
- 后端：PHP
- 数据库：MySQL / MariaDB

## 许可证

MIT License
