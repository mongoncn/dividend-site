# 攒股收息 (dividend-site)

追踪股票持仓收益与股息收入的轻量级自托管系统。PHP + MySQL 存储持仓与分红记录，Python 数据服务实时拉取行情、分红与指数，前端原生 HTML/CSS/JS 无构建步骤。

## 功能

**持仓与收益**
- 仪表板：持仓市值、买入成本、浮动盈亏、分红记录（可增删改）
- 持仓分析：集中度、行业分布、加权股息率、风险提示、今日组合 vs 中证红利/沪深300
- 收益走势：每日市值快照自动积累成曲线
- 触发价看板：配置触发价（跌破加仓 / 涨破减仓），实时行情对照，分「已触发 / 接近 / 安全」三档

**分红**
- 分红日历：按历史除权间隔推算未来排期（标注「推算」，非公告值）
- 已收分红：实际到账记录汇总

**场外基金**
- 场外基金追踪：净值、市值、盈亏，多渠道同代码自动合并

## 技术栈

| 层 | 技术 |
|---|---|
| 前端 | 原生 HTML + CSS + JS，无构建步骤 |
| Web/存储 | PHP 8+ + MySQL |
| 数据服务 | Python 3 纯标准库（`server/`），无需 pip |
| 数据源 | 腾讯行情 / 东方财富分红与指数 / 天天基金净值 |

## 部署

### 1. 数据库

创建数据库后访问 `api/init.php` 自动建表（users / stocks / holdings / dividends / snapshots）。

### 2. Web（PHP）

将项目放到 PHP 站点根目录，编辑 `api/config.php`：

```php
$dbname   = 'YOUR_DB_NAME';
$username = 'YOUR_DB_USER';
$password = 'YOUR_DB_PASSWORD';
```

然后访问 `api/register.php` 注册第一个账号（首个用户为管理员）。

### 3. 数据服务（持仓分析 / 触发价 / 分红日历 / 场外基金 的数据来源）

```bash
cd server
cp portfolio.example.json portfolio.json   # 改成你的持仓
python3 server.py                          # 默认监听 127.0.0.1:8000，可用 PORT/HOST 环境变量覆盖
```

纯 Python 标准库，无需安装任何依赖。然后用 Nginx 反代：

```nginx
location ^~ /api/chicang.php { }   # 走 PHP 鉴权（见下）
location ^~ /data/ {
    proxy_pass http://127.0.0.1:8000/;
}
```

页面统一通过 `api/*.php` 鉴权后转发到数据服务，**不直接暴露 Python 端口**。各代理文件里的 `127.0.0.1:8001` 按你的端口修改。

### 4. 持仓配置

数据服务从 `portfolio.json` 读取持仓与触发价（格式见 `server/portfolio.example.json`）：

```json
{
  "accounts": [
    {"name": "账户名", "broker": "券商",
     "positions": [{"code": "sh600900", "name": "长江电力", "qty": 200, "cost": 25.5}]}
  ],
  "funds": [
    {"code": "023917", "name": "基金名", "channel": "渠道", "shares": 100, "cost_amount": 120}
  ],
  "triggers": [
    {"code": "sh600900", "name": "长江电力", "dir": "below", "price": 27.0, "action": "跌破加仓"}
  ]
}
```

查找顺序：环境变量 `PORTFOLIO` → 项目根 `portfolio.json` → `server/portfolio.json`。

### 5. 每日快照（收益走势数据，可选）

`server/sync_db.py` 每日 upsert 一条市值快照（默认适配作者的服务器环境，路径可用环境变量 `PORTFOLIO` 覆盖，MySQL 凭据走 `--defaults-file`）。也可以用任意方式向 `snapshots` 表按日插入 `market_value / cost / pnl / pnl_pct`，攒够 2 天页面自动开始画曲线。

## 安全说明

- 登录 token 为 **HMAC-SHA256 签名**，防伪造；建议部署时设置环境变量 `SX_TOKEN_KEY`（不设则用内置兜底值）
- 数据库异常不向前端泄露细节
- `api/config.php` 的 CORS 默认放行 `*`（便于部署），生产环境建议设置环境变量 `APP_ORIGIN` 收紧到你的域名
- `api/clear_all_data.php` 为危险接口（清空全部数据），不需要可直接删除

## 目录结构

```
├── index.html            落地页
├── login.html / register.html
├── dashboard.html        仪表板
├── chicang.html          持仓分析 + 今日 vs 指数 + 收益走势
├── chufa.html            触发价看板
├── rili.html             分红日历 + 已收分红
├── jijin.html            场外基金
├── profile.html          个人中心
├── api/                  PHP 接口（鉴权后代理数据服务）
├── server/               Python 数据服务（纯标准库）
└── css/
```

## License

MIT
