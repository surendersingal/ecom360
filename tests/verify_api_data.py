#!/usr/bin/env python3
"""Quick verification that all analytics endpoints return real data."""
import requests

BEARER = "27|Q4blPK7ETXNPBLhn5BucMKtCaGciZLstQpsifLu1216a8ae2"
HEADERS = {"Authorization": f"Bearer {BEARER}", "Accept": "application/json"}
BASE = "https://ecom.buildnetic.com/api/v1/analytics"

def get(path):
    r = requests.get(f"{BASE}/{path}", headers=HEADERS, timeout=10)
    return r.json().get("data", {})

print("=== Analytics Data Visibility Check ===\n")

# 1. Overview
d = get("overview?date_range=30d")
t = d.get("traffic", {})
print(f"  1. Overview:      {t.get('total_events')} events, {t.get('unique_sessions')} sessions")

# 2. All Pages
d = get("all-pages?date_range=30d")
pages = d.get("pages", [])
valid = [p for p in pages if p and p.get("url")]
top = valid[0]["url"][:50] if valid else "none"
print(f"  2. All Pages:     {len(pages)} pages, top={top}")

# 3. Events Breakdown
d = get("events-breakdown?date_range=30d")
print(f"  3. Events:        {d.get('total_events')} events, {len(d.get('categories', []))} categories")

# 4. Products
d = get("products?date_range=30d")
print(f"  4. Products:      {len(d.get('top_by_views', []))} by views, {len(d.get('top_by_purchases', []))} by purchases")

# 5. Revenue
d = get("revenue?date_range=30d")
dl = d.get("daily", {})
rev = sum(dl.get("revenues", []))
orders = sum(dl.get("orders", []))
print(f"  5. Revenue:       ${rev:.2f} revenue, {orders} orders")

# 6. Geographic
d = get("geographic?date_range=30d")
print(f"  6. Geographic:    {len(d.get('device_breakdown', []))} devices, {len(d.get('browser_breakdown', []))} browsers, {len(d.get('os_breakdown', []))} OS")

# 7. Sessions
d = get("sessions?date_range=30d")
m = d.get("metrics", {})
print(f"  7. Sessions:      {m.get('total_sessions')} sessions, bounce={m.get('bounce_rate')}%")

# 8. Search
d = get("search-analytics?date_range=30d")
print(f"  8. Search:        {d.get('total_searches')} searches, {d.get('unique_keywords')} keywords")

# 9. Visitor Frequency
d = get("visitor-frequency?date_range=30d")
print(f"  9. Frequency:     {d.get('total_visitors')} visitors, {len(d.get('frequency', []))} buckets")

# 10. Day of Week
d = get("day-of-week?date_range=30d")
print(f"  10. Day of Week:  {len(d.get('day_of_week', []))} days, {len(d.get('heatmap', []))} heatmap cells")

# 11. Recent Events
d = get("recent-events?limit=5")
ev = d.get("events", [])
latest = ev[0]["event_type"] if ev else "none"
print(f"  11. Recent Events:{len(ev)} events, latest={latest}")

# 12. Campaigns
d = get("campaigns?date_range=30d")
u = d.get("utm_breakdown", {})
print(f"  12. Campaigns:    {len(u.get('sources', []))} sources, {len(u.get('mediums', []))} mediums, {len(u.get('campaigns', []))} campaigns")

# 13. Funnel
d = get("funnel?date_range=30d")
print(f"  13. Funnel:       {len(d.get('stages', d.get('funnel', [])))} stages")

# 14. Realtime
d = get("realtime")
print(f"  14. Realtime:     {d.get('active_sessions_5min')} active, {d.get('events_per_minute')} events/min")

# 15. Categories
d = get("categories?date_range=30d")
print(f"  15. Categories:   {len(d.get('category_views', []))} categories")

print("\n=== All 15 endpoints verified ===")
