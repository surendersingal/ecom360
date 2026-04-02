#!/usr/bin/env python3
"""Dump the exact shape of every analytics API endpoint response."""
import requests, json

BEARER = "27|Q4blPK7ETXNPBLhn5BucMKtCaGciZLstQpsifLu1216a8ae2"
HEADERS = {"Authorization": f"Bearer {BEARER}", "Accept": "application/json"}
BASE = "https://ecom.buildnetic.com/api/v1/analytics"

endpoints = [
    ("overview", "?date_range=30d"),
    ("traffic", "?date_range=30d"),
    ("sessions", "?date_range=30d"),
    ("revenue", "?date_range=30d"),
    ("products", "?date_range=30d"),
    ("campaigns", "?date_range=30d"),
    ("geographic", "?date_range=30d"),
    ("funnel", "?date_range=30d"),
    ("cohorts", "?date_range=90d"),
    ("realtime", ""),
    ("all-pages", "?date_range=30d"),
    ("search-analytics", "?date_range=30d"),
    ("events-breakdown", "?date_range=30d"),
    ("visitor-frequency", "?date_range=30d"),
    ("day-of-week", "?date_range=30d"),
    ("recent-events", "?limit=5"),
    ("categories", "?date_range=30d"),
    ("advanced/recommendations", "?date_range=30d"),
    ("advanced/clv", "?date_range=30d"),
    ("advanced/benchmarks", "?date_range=30d"),
    ("advanced/alerts", ""),
]

def show_shape(obj, prefix="", depth=0):
    """Show keys and types, with sample values for non-collections."""
    if depth > 3:
        return
    if isinstance(obj, dict):
        for k, v in list(obj.items())[:20]:
            if isinstance(v, dict):
                print(f"  {prefix}{k}: {{dict with {len(v)} keys}}")
                show_shape(v, prefix + "  ", depth + 1)
            elif isinstance(v, list):
                print(f"  {prefix}{k}: [list of {len(v)}]")
                if v and isinstance(v[0], dict):
                    print(f"  {prefix}  [0] keys: {list(v[0].keys())}")
            else:
                val = str(v)[:60] if v is not None else "null"
                print(f"  {prefix}{k}: {type(v).__name__} = {val}")
    elif isinstance(obj, list):
        print(f"  {prefix}[list of {len(obj)}]")
        if obj and isinstance(obj[0], dict):
            print(f"  {prefix}[0] keys: {list(obj[0].keys())}")

for name, qs in endpoints:
    url = f"{BASE}/{name}{qs}"
    try:
        r = requests.get(url, headers=HEADERS, timeout=10)
        data = r.json().get("data", {})
        print(f"\n{'='*60}")
        print(f"ENDPOINT: /analytics/{name}")
        print(f"{'='*60}")
        show_shape(data)
    except Exception as e:
        print(f"\n/analytics/{name}: ERROR - {e}")
