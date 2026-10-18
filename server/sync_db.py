#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
把 portfolio.json 的持仓 + 东财分红公告，同步进 sxdb，供仪表盘（dashboard.html）使用。

数据流：
    /root/ops/config/portfolio.json  ──持仓──▶  stocks / holdings
    东方财富分红实施记录            ──分红──▶  dividends

设计原则：
    · 幂等 —— 重复跑不会重复插记录
    · 只动 user_id=1（站点主人），不碰其他用户
    · 已有分红记录不覆盖（手工录入的数量是当时的真实持股数，比推算准）
    · 场外基金（cfg["funds"]）不处理，仪表盘没有基金结构

用法：
    python3 sync_db.py --dry-run     只看会改什么
    python3 sync_db.py               真跑

cron（每日 08:40，简报 08:30 之后）：
    40 8 * * * /usr/bin/python3 /opt/holdings-app/sync_db.py >> /root/ops/logs/sync_db.log 2>&1
"""
import json
import os
import subprocess
import sys
from datetime import date, datetime, timedelta

BASE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, BASE)
from server import fetch_dividends, build_report, PORTFOLIO  # noqa: E402

CNF = "/root/ops/config/mysql-root.cnf"
DB = "sxdb"
USER_ID = 1
DIV_WINDOW_DAYS = 400          # 只同步近 400 天的分红（覆盖一年一次/两次的节奏）
DRY = "--dry-run" in sys.argv


# ---------- MySQL ----------

def q(stmt, db=DB):
    """执行 SQL，返回 stdout（-N -B 制表符分隔）"""
    r = subprocess.run(
        ["mysql", "--defaults-file=" + CNF, db, "-N", "-B", "-e", stmt],
        stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if r.returncode != 0:
        raise RuntimeError(r.stderr.decode("utf-8", "ignore").strip()[:300])
    return r.stdout.decode("utf-8", "ignore").strip()


def esc(s):
    return str(s).replace("\\", "\\\\").replace("'", "\\'")


def write(stmt, label):
    if DRY:
        print("    [dry] %s" % label)
        return
    q(stmt)
    print("    ✓ %s" % label)


# ---------- portfolio.json ----------

def load_positions():
    """合并跨账户的同代码持仓，成本按股数加权"""
    cfg = json.load(open(PORTFOLIO, encoding="utf-8"))
    pos = {}
    for acct in cfg.get("accounts", []):
        for p in acct.get("positions", []):
            code = str(p["code"]).lower()
            num = code[-6:]
            qty = int(p["qty"])
            cost = float(p["cost"])
            if num in pos:
                o = pos[num]
                tot = o["qty"] + qty
                o["cost"] = round((o["cost"] * o["qty"] + cost * qty) / tot, 4)
                o["qty"] = tot
            else:
                pos[num] = {"num": num, "code": code,
                            "name": p.get("name", ""), "qty": qty, "cost": cost}
    return pos


# ---------- 同步 ----------

def sync_holdings(pos):
    print("\n【1】持仓对齐（portfolio.json → stocks / holdings）")
    stats = {"new_stock": 0, "upd_stock": 0, "new_hold": 0, "upd_hold": 0, "skip": 0}

    for num, p in sorted(pos.items()):
        # --- stocks ---
        row = q("SELECT id, name FROM stocks WHERE symbol='%s' LIMIT 1;" % esc(num))
        if row:
            sid, old_name = row.split("\t")[0], row.split("\t")[1]
            if old_name != p["name"]:
                write("UPDATE stocks SET name='%s', market='A股' WHERE id=%s;"
                      % (esc(p["name"]), sid),
                      "%s 改名 %s → %s" % (num, old_name, p["name"]))
                stats["upd_stock"] += 1
        else:
            write("INSERT INTO stocks (symbol, name, market, current_price, dividend_yield, last_updated) "
                  "VALUES ('%s','%s','A股',0,0,NOW());" % (esc(num), esc(p["name"])),
                  "%s %s 新建股票" % (num, p["name"]))
            stats["new_stock"] += 1
            sid = q("SELECT id FROM stocks WHERE symbol='%s' LIMIT 1;" % esc(num))

        if not sid:
            # dry-run 下股票还没真插入，无法继续查 holdings，按「将新建」计数
            print("    [dry] %s %s 新建持仓 %d股@%.3f" % (num, p["name"], p["qty"], p["cost"]))
            stats["new_hold"] += 1
            continue

        # --- holdings ---
        row = q("SELECT id, quantity, buy_price FROM holdings "
                "WHERE user_id=%d AND stock_id=%s LIMIT 1;" % (USER_ID, sid))
        if row:
            hid, old_qty, old_cost = row.split("\t")
            # buy_price 是 decimal(10,2)，入库会被截断，容差用 0.005 避免每天重复 UPDATE
            if int(float(old_qty)) != p["qty"] or abs(float(old_cost) - p["cost"]) > 0.005:
                write("UPDATE holdings SET quantity=%d, buy_price=%.4f WHERE id=%s;"
                      % (p["qty"], p["cost"], hid),
                      "%s %s  %s股@%s → %d股@%.3f"
                      % (num, p["name"], old_qty, old_cost, p["qty"], p["cost"]))
                stats["upd_hold"] += 1
            else:
                stats["skip"] += 1
        else:
            write("INSERT INTO holdings (user_id, stock_id, buy_price, quantity, buy_date, notes) "
                  "VALUES (%d,%s,%.4f,%d,NULL,'由 portfolio.json 同步');"
                  % (USER_ID, sid, p["cost"], p["qty"]),
                  "%s %s 新建持仓 %d股@%.3f（买入日未知）" % (num, p["name"], p["qty"], p["cost"]))
            stats["new_hold"] += 1

    print("  小计：新建股票 %d / 改名 %d / 新建持仓 %d / 更新持仓 %d / 无变化 %d"
          % (stats["new_stock"], stats["upd_stock"], stats["new_hold"],
             stats["upd_hold"], stats["skip"]))
    return stats


def write_snapshot():
    """每日市值快照：upsert 当天一条，供收益曲线积累"""
    try:
        rep = build_report()
        sm = rep["summary"]
        mv, cost, pnl = sm["market_value"], sm["cost"], sm["pnl"]
        pct = round(pnl / cost * 100, 3) if cost else 0.0
        write("INSERT INTO snapshots (snap_date, market_value, cost, pnl, pnl_pct) "
              "VALUES (CURDATE(),%.2f,%.2f,%.2f,%.3f) "
              "ON DUPLICATE KEY UPDATE market_value=VALUES(market_value), "
              "cost=VALUES(cost), pnl=VALUES(pnl), pnl_pct=VALUES(pnl_pct);"
              % (mv, cost, pnl, pct),
              "今日快照  市值 %.0f  盈亏 %+.0f (%.2f%%)" % (mv, pnl, pct))
        return True
    except Exception as e:
        print("    ! 快照写入失败：%s" % str(e)[:80])
        return False


def sync_dividends(pos):
    print("\n【2】分红记录同步（东财公告 → dividends）")
    today = date.today()
    cutoff = today - timedelta(days=DIV_WINDOW_DAYS)
    added = skipped = nodata = 0

    for num, p in sorted(pos.items()):
        # 找到该持仓在本站的 holding id 与买入日
        row = q("SELECT h.id, IFNULL(h.buy_date,'') FROM holdings h JOIN stocks s ON h.stock_id=s.id "
                "WHERE h.user_id=%d AND s.symbol='%s' LIMIT 1;" % (USER_ID, esc(num)))
        if not row:
            print("    - %s %s 无持仓记录，跳过" % (num, p["name"]))
            continue
        parts = row.split("\t")
        hid = parts[0]
        buy_date = parts[1].strip() if len(parts) > 1 else ""

        try:
            divs = fetch_dividends(p["code"])
        except Exception as e:
            print("    ! %s %s 抓取失败：%s" % (num, p["name"], str(e)[:60]))
            nodata += 1
            continue

        mine = []
        for d in divs:
            if d["ex_date"] < cutoff.isoformat():
                continue
            # 买入前的分红不属于自己，绝不录入
            if buy_date:
                if d["ex_date"] < buy_date:
                    continue
            else:
                # 买入日未知：只收今天及以后的，不猜历史
                if d["ex_date"] < today.isoformat():
                    continue
            mine.append(d)

        if not mine:
            continue

        for d in mine:
            # 判重容忍 ±3 天：手工记录用的是「股权登记日」，东财给的是「除权除息日」，
            # 通常是次一交易日，精确比对会产生重复记录
            exist = q("SELECT id FROM dividends WHERE holding_id=%s "
                      "AND ABS(DATEDIFF(dividend_date,'%s'))<=3 LIMIT 1;"
                      % (hid, d["ex_date"]))
            if exist:
                skipped += 1
                continue
            amount = round(d["cash"] * p["qty"], 2)
            write("INSERT INTO dividends (holding_id, dividend_date, amount_per_share, quantity, "
                  "total_amount, notes) VALUES (%s,'%s',%.4f,%d,%.2f,'自动同步·东财公告');"
                  % (hid, d["ex_date"], d["cash"], p["qty"], amount),
                  "%s %s  %s 每股%.4f × %d = %.2f 元"
                  % (num, p["name"], d["ex_date"], d["cash"], p["qty"], amount))
            added += 1

    print("  小计：新增 %d 条 / 已存在跳过 %d 条 / 抓取失败 %d 只" % (added, skipped, nodata))
    return added, skipped


def save_snapshot():
    """每日市值快照，供收益曲线使用"""
    print("\n【3】每日快照")
    try:
        from server import build_report
        r = build_report()
        s = r["summary"]
        pct = round(s["pnl"] / s["cost"] * 100, 3) if s.get("cost") else 0.0
        stmt = ("INSERT INTO snapshots (snap_date, market_value, cost, pnl, pnl_pct) "
                "VALUES (CURDATE(), %.2f, %.2f, %.2f, %.3f) "
                "ON DUPLICATE KEY UPDATE market_value=VALUES(market_value), "
                "cost=VALUES(cost), pnl=VALUES(pnl), pnl_pct=VALUES(pnl_pct);"
                % (s["market_value"], s["cost"], s["pnl"], pct))
        if DRY:
            print("    [dry] 今日快照 市值 %.2f 浮盈亏 %.2f (%.2f%%)"
                  % (s["market_value"], s["pnl"], pct))
        else:
            q(stmt)
            print("    ✓ 今日快照 市值 %.2f 浮盈亏 %.2f (%.2f%%)"
                  % (s["market_value"], s["pnl"], pct))
    except Exception as e:
        print("    ! 快照失败：%s" % str(e)[:100])


def ensure_schema():
    """首次运行自动建快照表"""
    q("CREATE TABLE IF NOT EXISTS snapshots ("
      "id INT AUTO_INCREMENT PRIMARY KEY,"
      "snap_date DATE NOT NULL UNIQUE,"
      "market_value DECIMAL(14,2) NOT NULL,"
      "cost DECIMAL(14,2) NOT NULL,"
      "pnl DECIMAL(14,2) NOT NULL,"
      "pnl_pct DECIMAL(8,3) NOT NULL,"
      "created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
      ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;")


def main():
    if not os.path.exists(PORTFOLIO):
        print("找不到持仓配置：%s" % PORTFOLIO)
        return 1

    print("=" * 56)
    print("同步 %s → MySQL %s%s" % (PORTFOLIO, DB, "（dry-run，不写库）" if DRY else ""))
    print("时间 %s" % datetime.now().strftime("%Y-%m-%d %H:%M:%S"))
    print("=" * 56)

    print("\n【0】每日快照")
    write_snapshot()

    pos = load_positions()
    print("\nportfolio.json 共 %d 只场内持仓" % len(pos))

    if not DRY:
        ensure_schema()

    sync_holdings(pos)
    sync_dividends(pos)
    save_snapshot()

    print("\n【4】结果的数据库侧核对")
    print("  holdings(user_id=%d)：%s 条" % (
        USER_ID, q("SELECT COUNT(*) FROM holdings WHERE user_id=%d;" % USER_ID)))
    print("  dividends：%s 条" % q("SELECT COUNT(*) FROM dividends;"))
    print("\n完成。")
    return 0


if __name__ == "__main__":
    sys.exit(main())

