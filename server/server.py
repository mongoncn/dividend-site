#!/usr/bin/env python3
"""持仓体检 + 分红日历
读 /root/ops/config/portfolio.json，拉行情/行业/分红，算集中度与分红排期
纯标准库，无第三方依赖
"""
import json
import os
import re
import subprocess
import time
import urllib.parse
import urllib.request
from datetime import datetime, timedelta
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36"
BASE = os.path.dirname(os.path.abspath(__file__))
PORTFOLIO = os.environ.get("PORTFOLIO", "/root/ops/config/portfolio.json")
# 开源部署：依次尝试 环境变量路径 → 项目目录下的 portfolio.json
PORTFOLIO_CANDIDATES = [PORTFOLIO, os.path.join(BASE, "portfolio.json")]


def load_portfolio():
    for p in PORTFOLIO_CANDIDATES:
        if p and os.path.exists(p):
            return json.load(open(p, encoding="utf-8"))
    raise RuntimeError(
        "未找到持仓配置 portfolio.json：请把 server/portfolio.example.json "
        "复制为 server/portfolio.json 并改成你的持仓，或用环境变量 PORTFOLIO 指定路径")
CACHE_FILE = os.path.join(BASE, "cache.json")
CACHE_TTL = 6 * 3600

# 用 curl 而不是 urllib：本地 Python 的 TLS 指纹会被东财直接断连（RemoteDisconnected），
# curl 在本地和服务器上都稳定。--noproxy 避免系统代理干扰。
def _get(url, referer=None, timeout=20, encoding="utf-8"):
    cmd = ["curl", "-s", "--noproxy", "*", "--max-time", str(timeout),
           "-H", "User-Agent: " + UA]
    if referer:
        cmd += ["-H", "Referer: " + referer]
    cmd.append(url)
    r = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    txt = r.stdout.decode(encoding, "ignore")
    if not txt.strip():
        raise RuntimeError("empty response")
    return txt


def _json(url, referer=None, timeout=20):
    return json.loads(_get(url, referer, timeout))


def to_secid(code):
    """sh600900 -> 1.600900 ; sz000001 -> 0.000001"""
    m = re.match(r"(sh|sz)(\d{6})", code.lower())
    if not m:
        return None
    return ("1." if m.group(1) == "sh" else "0.") + m.group(2)


# 对比基准（腾讯行情里指数与股票同格式）
BENCHMARKS = [("sh000922", "中证红利"), ("sh000300", "沪深300")]


def fetch_benchmarks():
    """指数当日行情，用于跟组合当日涨跌对比"""
    q = fetch_quotes([c for c, _ in BENCHMARKS])
    out = []
    for code, name in BENCHMARKS:
        x = q.get(code)
        if not x:
            continue
        out.append({"code": code, "name": name,
                    "price": round(x["price"], 2), "chg_pct": x["chg_pct"]})
    return out


_FUND_CACHE = {"ts": 0, "data": None}
_FUND_TTL = 1800  # 基金净值一天只更新一次，30 分钟缓存足够


def fetch_fund_nav(code):
    """天天基金 f10 历史净值最新一条"""
    url = ("http://api.fund.eastmoney.com/f10/lsjz"
           "?fundCode=%s&pageIndex=1&pageSize=1" % code)
    try:
        d = _json(url, "http://fundf10.eastmoney.com/jjjz_%s.html" % code, 15)
        lst = (d.get("Data") or {}).get("LSJZList") or []
        if not lst:
            return None
        it = lst[0]
        chg = it.get("JZZZL")
        try:
            chg = float(chg)
        except (TypeError, ValueError):
            chg = None
        return {"nav": float(it["DWJZ"]), "date": it["FSRQ"], "chg_pct": chg}
    except Exception:
        return None


def build_funds():
    """场外基金：跨渠道同代码合并，算市值与盈亏"""
    if _FUND_CACHE["data"] and time.time() - _FUND_CACHE["ts"] < _FUND_TTL:
        return _FUND_CACHE["data"]

    cfg = load_portfolio()
    agg = {}
    for f in cfg.get("funds", []):
        code = str(f.get("code", "")).strip()
        if not code:
            continue
        a = agg.setdefault(code, {"code": code, "name": f.get("name", ""),
                                  "shares": 0.0, "cost": 0.0, "channels": []})
        a["shares"] += float(f.get("shares") or 0)
        a["cost"] += float(f.get("cost_amount") or 0)
        ch = f.get("channel", "")
        if ch and ch not in a["channels"]:
            a["channels"].append(ch)

    items = []
    for code, a in agg.items():
        nav = fetch_fund_nav(code)
        mv = round(a["shares"] * nav["nav"], 2) if nav else None
        pnl = round(mv - a["cost"], 2) if mv is not None else None
        items.append({
            "code": code,
            "name": a["name"],
            "channels": a["channels"],
            "shares": round(a["shares"], 4),
            "cost": round(a["cost"], 2),
            "nav": round(nav["nav"], 4) if nav else None,
            "nav_date": nav["date"] if nav else None,
            "chg_pct": nav["chg_pct"] if nav else None,
            "market_value": mv,
            "pnl": pnl,
            "pnl_pct": round(pnl / a["cost"] * 100, 2) if nav and a["cost"] else None,
        })
        time.sleep(0.12)

    total_mv = sum(x["market_value"] or 0 for x in items)
    total_cost = sum(x["cost"] for x in items)
    items.sort(key=lambda x: -(x["market_value"] or 0))

    out = {
        "as_of": datetime.now().strftime("%Y-%m-%d %H:%M"),
        "items": items,
        "summary": {
            "count": len(items),
            "market_value": round(total_mv, 2),
            "cost": round(total_cost, 2),
            "pnl": round(total_mv - total_cost, 2),
            "pnl_pct": round((total_mv - total_cost) / total_cost * 100, 2) if total_cost else None,
        },
    }
    _FUND_CACHE["data"] = out
    _FUND_CACHE["ts"] = time.time()
    return out


def fetch_quotes(codes):
    """腾讯批量行情: 名称/现价/昨收/涨跌幅"""
    if not codes:
        return {}
    url = "https://qt.gtimg.cn/q=" + ",".join(codes)
    txt = _get(url, encoding="gbk")
    out = {}
    for line in txt.split(";"):
        line = line.strip()
        if not line.startswith("v_"):
            continue
        m = re.match(r'v_([a-z]{2}\d{6})="(.*)"', line)
        if not m:
            continue
        f = m.group(2).split("~")
        if len(f) < 6:
            continue
        try:
            price = float(f[3])
            prev = float(f[4])
        except ValueError:
            continue
        out[m.group(1)] = {
            "name": f[1],
            "price": price,
            "prev": prev,
            "chg_pct": round((price - prev) / prev * 100, 2) if prev else 0.0,
        }
    return out


# 持仓里已知的行业，直接查表（东财单股接口连续请求会被限流，只作兜底）
KNOWN_INDUSTRY = {
    "sh601988": "银行", "sh601818": "银行", "sh600036": "银行", "sh601916": "银行",
    "sh601398": "银行", "sh601288": "银行", "sh601939": "银行", "sh601166": "银行",
    "sh600900": "电力", "sh601006": "铁路公路", "sh600029": "航空机场",
    "sh600901": "多元金融", "sh601728": "通信服务", "sh601088": "煤炭",
    "sh518850": "黄金ETF", "sh515080": "红利ETF", "sh520660": "红利ETF",
}


def fetch_industries(codes):
    """先查已知表，未知的才请求东财（每个之间隔 0.6 秒，失败重试一次）"""
    out = {}
    for c in codes:
        if c in KNOWN_INDUSTRY:
            out[c] = KNOWN_INDUSTRY[c]
            continue
        sid = to_secid(c)
        ind = None
        for attempt in range(2):
            try:
                d = _json("https://push2.eastmoney.com/api/qt/stock/get?secid=%s&fields=f127" % sid)
                v = (d.get("data") or {}).get("f127")
                if isinstance(v, str) and v.strip():
                    ind = v.strip()
                    break
            except Exception:
                pass
            time.sleep(1.0)
        out[c] = ind or "未分类"
        time.sleep(0.6)
    return out


def fetch_dividends(code):
    """东财实施分配记录，按除权日升序"""
    m = re.match(r"(sh|sz)(\d{6})", code.lower())
    if not m:
        return []
    num = m.group(2)
    q = urllib.parse.quote('(SECURITY_CODE="%s")' % num)
    url = ("https://datacenter-web.eastmoney.com/api/data/v1/get"
           "?reportName=RPT_SHAREBONUS_DET&columns=SECURITY_CODE,REPORT_DATE,"
           "EX_DIVIDEND_DATE,PRETAX_BONUS_RMB,BONUS_IT_RATIO,IT_RATIO,"
           "ASSIGN_PROGRESS,IMPL_PLAN_PROFILE&filter=" + q +
           "&pageNumber=1&pageSize=60&sortColumns=EX_DIVIDEND_DATE&sortTypes=1")
    d = _json(url, "https://data.eastmoney.com/", 25)
    rows = (d.get("result") or {}).get("data") or []
    out = []
    for r in rows:
        if (r.get("ASSIGN_PROGRESS") or "") != "实施分配":
            continue
        ex = (r.get("EX_DIVIDEND_DATE") or "")[:10]
        if not ex:
            continue
        cash = float(r.get("PRETAX_BONUS_RMB") or 0) / 10.0
        if cash <= 0:
            continue
        out.append({"ex_date": ex,
                    "report": (r.get("REPORT_DATE") or "")[:10],
                    "cash": cash})
    out.sort(key=lambda x: x["ex_date"])
    return out


def guess_next(divs, today):
    """按历史除权间隔推算下一次；间隔取最近几次的中位数"""
    if not divs:
        return None
    dates = [datetime.strptime(d["ex_date"], "%Y-%m-%d") for d in divs]
    last = dates[-1]
    if last > today:
        # 已公告未来除权
        d = divs[-1]
        return {"date": d["ex_date"], "cash": d["cash"],
                "basis": "已公告", "days": (last - today).days}
    gaps = [(dates[i] - dates[i - 1]).days for i in range(1, len(dates))][-4:]
    if not gaps:
        return None
    gaps.sort()
    gap = gaps[len(gaps) // 2]
    nxt = last + timedelta(days=gap)
    while nxt < today:
        nxt += timedelta(days=gap)
    # 派息额用最近一次
    return {"date": nxt.strftime("%Y-%m-%d"), "cash": divs[-1]["cash"],
            "basis": "按历史间隔推算", "days": (nxt - today).days}


def ttm_dividend(divs, today):
    """最近 12 个月已实施的每股分红合计"""
    start = (today - timedelta(days=365)).strftime("%Y-%m-%d")
    return sum(d["cash"] for d in divs if start <= d["ex_date"] <= today.strftime("%Y-%m-%d"))


def build_report(positions=None):
    """positions 为 None 时读 portfolio.json；传入列表时用调用方持仓（PHP 桥接 MySQL）"""
    cfg = {}
    if positions:
        positions = [dict(p, account=p.get("account", "证券账户")) for p in positions]
    else:
        cfg = load_portfolio()
        positions = []
        for acc in cfg.get("accounts", []):
            for p in acc.get("positions", []):
                positions.append({**p, "account": acc.get("name", "")})
    if not positions:
        raise RuntimeError("没有场内持仓：仪表盘未添加，且 portfolio.json 未配置")

    codes = sorted({p["code"] for p in positions})
    quotes = fetch_quotes(codes)

    # 分红与行业走缓存，避免每次刷新都打东财
    cache = {}
    if os.path.exists(CACHE_FILE):
        try:
            cache = json.load(open(CACHE_FILE, encoding="utf-8"))
        except Exception:
            cache = {}
    fresh = time.time() - cache.get("_ts", 0) < CACHE_TTL
    div_cache = cache.get("dividends", {}) if fresh else {}
    ind_cache = cache.get("industries", {}) if fresh else {}

    if not ind_cache:
        ind_cache = fetch_industries(codes)
    if not div_cache or any(c not in div_cache for c in codes):
        for c in codes:
            if c in div_cache:
                continue
            try:
                div_cache[c] = fetch_dividends(c)
            except Exception:
                div_cache[c] = []
            time.sleep(0.45)
    json.dump({"_ts": time.time(), "dividends": div_cache, "industries": ind_cache},
              open(CACHE_FILE, "w", encoding="utf-8"), ensure_ascii=False)

    today = datetime.now().replace(hour=0, minute=0, second=0, microsecond=0)

    # 合并同一代码（光大在两个账户）
    merged = {}
    for p in positions:
        c = p["code"]
        if c in merged:
            old = merged[c]
            total = old["qty"] + p["qty"]
            old["cost"] = round((old["cost"] * old["qty"] + p["cost"] * p["qty"]) / total, 4)
            old["qty"] = total
            old["account"] = old["account"] + " + " + p["account"]
        else:
            merged[c] = dict(p)

    rows, calendar = [], []
    total_mv = 0.0
    for c, p in merged.items():
        q = quotes.get(c) or {}
        price = q.get("price") or p.get("cost", 0)
        mv = price * p["qty"]
        total_mv += mv
        divs = div_cache.get(c, [])
        ttm = ttm_dividend(divs, today)
        dy = (ttm / price * 100) if price > 0 else 0.0
        nxt = guess_next(divs, today)
        rows.append({
            "code": c, "name": p.get("name", q.get("name", c)),
            "account": p.get("account", ""),
            "qty": p["qty"], "cost": p["cost"],
            "price": round(price, 3), "chg_pct": q.get("chg_pct", 0),
            "mv": round(mv, 1),
            "pnl": round((price - p["cost"]) * p["qty"], 1),
            "pnl_pct": round((price / p["cost"] - 1) * 100, 2) if p["cost"] else 0,
            "industry": ind_cache.get(c, "未分类"),
            "dy": round(dy, 2),
            "ttm_div": round(ttm, 4),
            "div_count": len(divs),
            "last_div": divs[-1]["ex_date"] if divs else None,
        })
        if nxt:
            calendar.append({
                "code": c, "name": p.get("name", c),
                "date": nxt["date"], "days": nxt["days"],
                "cash_per_share": round(nxt["cash"], 4),
                "est_amount": round(nxt["cash"] * p["qty"], 2),
                "basis": nxt["basis"],
            })

    for r in rows:
        r["weight"] = round(r["mv"] / total_mv * 100, 2) if total_mv else 0

    # 行业分布
    ind = {}
    for r in rows:
        ind[r["industry"]] = ind.get(r["industry"], 0) + r["mv"]
    industries = sorted(
        [{"name": k, "mv": round(v, 1), "weight": round(v / total_mv * 100, 2)}
         for k, v in ind.items()], key=lambda x: -x["mv"])

    # 加权股息率
    wdy = sum(r["mv"] * r["dy"] for r in rows) / total_mv if total_mv else 0

    # 风险提示
    alerts = []
    for r in sorted(rows, key=lambda x: -x["weight"]):
        if r["weight"] >= 25:
            alerts.append({"level": "high", "text": "%s 占 %.1f%%，单票过于集中" % (r["name"], r["weight"])})
        elif r["weight"] >= 15:
            alerts.append({"level": "mid", "text": "%s 占 %.1f%%，接近单票上限" % (r["name"], r["weight"])})
    for i in industries:
        if i["weight"] >= 50:
            alerts.append({"level": "high", "text": "%s 占 %.1f%%，行业过度集中" % (i["name"], i["weight"])})
        elif i["weight"] >= 35:
            alerts.append({"level": "mid", "text": "%s 占 %.1f%%，行业偏高" % (i["name"], i["weight"])})
    for r in rows:
        if r["pnl_pct"] <= -15:
            alerts.append({"level": "high", "text": "%s 浮亏 %.1f%%，需复核持有逻辑" % (r["name"], r["pnl_pct"])})
        elif r["pnl_pct"] <= -8:
            alerts.append({"level": "mid", "text": "%s 浮亏 %.1f%%" % (r["name"], r["pnl_pct"])})
    for r in rows:
        if r["dy"] < 2 and r["div_count"] > 0:
            alerts.append({"level": "low", "text": "%s 股息率仅 %.2f%%，偏离收息定位" % (r["name"], r["dy"])})

    calendar.sort(key=lambda x: x["days"])

    funds = [{"name": f.get("name", ""), "code": f.get("code", ""),
              "channel": f.get("channel", ""), "shares": f.get("shares"),
              "cost": f.get("cost_amount")} for f in cfg.get("funds", [])]

    # 组合当日涨跌（跟指数同口径，可直接对比）
    day_delta = 0.0
    for r in rows:
        x = quotes.get(r["code"])
        if x:
            day_delta += r["mv"] * x["chg_pct"] / 100

    return {
        "as_of": datetime.now().strftime("%Y-%m-%d %H:%M"),
        "positions": sorted(rows, key=lambda x: -x["mv"]),
        "industries": industries,
        "calendar": calendar,
        "alerts": alerts,
        "funds": funds,
        "benchmarks": fetch_benchmarks(),
        "snapshots": fetch_snapshots(),
        "summary": {
            "count": len(rows),
            "market_value": round(total_mv, 1),
            "cost": round(sum(r["cost"] * r["qty"] for r in rows), 1),
            "pnl": round(sum(r["pnl"] for r in rows), 1),
            "weighted_dy": round(wdy, 2),
            "cash_12m": round(sum(r["mv"] * r["dy"] / 100 for r in rows), 1),
            "day_delta": round(day_delta, 1),
            "day_pct": round(day_delta / total_mv * 100, 2) if total_mv else 0.0,
        },
    }


MYSQL_CNF = "/root/ops/config/mysql-root.cnf"


def fetch_snapshots(limit=180):
    """读取每日市值快照（由 sync_db.py 写入）"""
    try:
        r = subprocess.run(
            ["mysql", "--defaults-file=" + MYSQL_CNF, "sxdb", "-N", "-B", "-e",
             "SELECT snap_date, market_value, cost, pnl, pnl_pct FROM snapshots "
             "ORDER BY snap_date DESC LIMIT %d;" % int(limit)],
            stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=12)
        out = []
        for line in r.stdout.decode("utf-8", "ignore").strip().split("\n"):
            if not line.strip():
                continue
            f = line.split("\t")
            if len(f) < 5:
                continue
            out.append({"date": f[0], "mv": float(f[1]), "cost": float(f[2]),
                        "pnl": float(f[3]), "pct": float(f[4])})
        out.reverse()
        return out
    except Exception:
        return []


def build_triggers():
    """触发价看板：portfolio.json 的 triggers 对照实时行情"""
    cfg = load_portfolio()
    rows = cfg.get("triggers") or []

    # 持仓代码集合，用于标注「已持仓 / 观察中」
    held = set()
    for a in cfg.get("accounts", []):
        for p in a.get("positions", []):
            held.add(str(p.get("code", "")).lower())

    codes = [str(t.get("code", "")).lower() for t in rows if t.get("code")]
    q = fetch_quotes(codes) if codes else {}

    items = []
    for t in rows:
        code = str(t.get("code", "")).lower()
        x = q.get(code)
        price = x["price"] if x else None
        target = float(t.get("price") or 0)
        direction = str(t.get("dir") or "below").lower()

        it = {
            "code": code,
            "name": t.get("name") or (x["name"] if x else code),
            "price": round(price, 3) if price else None,
            "chg_pct": x["chg_pct"] if x else None,
            "target": target,
            "dir": direction,
            "action": t.get("action", ""),
            "held": code in held,
        }

        if not price or target <= 0:
            it["status"] = "nodata"
            it["gap_pct"] = None
        else:
            if direction == "below":
                # 跌到 target 才买：gap 正数表示还要跌多少，负数表示已跌破
                gap = (price - target) / target * 100
                triggered = price <= target
            else:
                # 涨到 target 才卖：gap 正数表示还要涨多少，负数表示已涨过
                gap = (target - price) / target * 100
                triggered = price >= target
            it["gap_pct"] = round(gap, 2)
            if triggered:
                it["status"] = "triggered"
            elif gap <= 3:
                it["status"] = "near"
            else:
                it["status"] = "safe"
        items.append(it)

    order = {"triggered": 0, "near": 1, "safe": 2, "nodata": 3}
    items.sort(key=lambda x: (order.get(x["status"], 9),
                              abs(x["gap_pct"]) if x.get("gap_pct") is not None else 999))

    cnt = {"triggered": 0, "near": 0, "safe": 0, "nodata": 0}
    for x in items:
        cnt[x["status"]] = cnt.get(x["status"], 0) + 1

    return {
        "as_of": datetime.now().strftime("%Y-%m-%d %H:%M"),
        "items": items,
        "summary": cnt,
    }


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def _send(self, code, body, ctype="application/json; charset=utf-8"):
        b = body if isinstance(body, bytes) else body.encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(b)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(b)

    def do_POST(self):
        if self.path.startswith("/api/report"):
            try:
                n = int(self.headers.get("Content-Length") or 0)
                body = json.loads(self.rfile.read(n) or b"{}") if n else {}
                ps = []
                for p in (body.get("positions") or []):
                    try:
                        q = int(p.get("qty") or 0)
                        c = float(p.get("cost") or 0)
                        code = str(p.get("code") or "").strip().lower()
                        if code and q > 0 and c > 0:
                            ps.append({"code": code, "name": p.get("name") or code,
                                       "qty": q, "cost": c,
                                       "account": p.get("account") or "证券账户"})
                    except (TypeError, ValueError):
                        continue
                self._send(200, json.dumps(build_report(ps or None), ensure_ascii=False))
            except Exception as e:
                self._send(500, json.dumps({"error": str(e)[:160]}, ensure_ascii=False))
        else:
            self._send(404, "not found", "text/plain; charset=utf-8")

    def do_GET(self):
        if self.path.startswith("/api/report"):
            try:
                self._send(200, json.dumps(build_report(), ensure_ascii=False))
            except Exception as e:
                self._send(500, json.dumps({"error": str(e)[:160]}, ensure_ascii=False))
        elif self.path.startswith("/api/triggers"):
            try:
                self._send(200, json.dumps(build_triggers(), ensure_ascii=False))
            except Exception as e:
                self._send(500, json.dumps({"error": str(e)[:160]}, ensure_ascii=False))
        elif self.path.startswith("/api/funds"):
            try:
                self._send(200, json.dumps(build_funds(), ensure_ascii=False))
            except Exception as e:
                self._send(500, json.dumps({"error": str(e)[:160]}, ensure_ascii=False))
        elif self.path.startswith("/api/snapshots"):
            self._send(200, json.dumps({"items": fetch_snapshots()}, ensure_ascii=False))
        else:
            try:
                with open(os.path.join(BASE, "index.html"), "rb") as f:
                    self._send(200, f.read(), "text/html; charset=utf-8")
            except FileNotFoundError:
                self._send(404, "index.html not found", "text/plain; charset=utf-8")


if __name__ == "__main__":
    port = int(os.environ.get("PORT", 8001))
    host = os.environ.get("HOST", "0.0.0.0")
    print("listening on %s:%s" % (host, port), flush=True)
    ThreadingHTTPServer((host, port), Handler).serve_forever()
