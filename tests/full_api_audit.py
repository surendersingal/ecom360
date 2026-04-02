#!/usr/bin/env python3
"""
Full API Audit - Test every analytics endpoint and dump exact response shapes.
"""
import requests, json, sys

BASE = "https://ecom.buildnetic.com/api/v1/analytics"
HEADERS = {
    "Authorization": "Bearer 27|Q4blPK7ETXNPBLhn5BucMKtCaGciZLstQpsifLu1216a8ae2",
    "Accept": "application/json",
    "X-Api-Key": "ek_Z3KoaSngu5edQ9exUKCDSYaMqAm7xomX",
}
PARAMS = {"date_range": "30d"}

ENDPOINTS = [
    # Core
    ("overview", {}),
    ("sessions", {}),
    ("revenue", {}),
    ("geographic", {}),
    ("realtime", {}),
    ("funnel", {}),
    ("campaigns", {}),
    ("all-pages", {}),
    ("traffic", {}),
    ("products", {}),
    ("categories", {}),
    # Advanced
    ("advanced/recommendations", {}),
    ("advanced/predictions", {}),
    ("advanced/benchmarks", {}),
    ("advanced/ask", {"question": "What are top pages?"}),
    # More from routes
    ("cart-abandonment", {}),
    ("events", {}),
    ("site-search", {}),
    ("entry-pages", {}),
    ("exit-pages", {}),
    ("visitor-log", {}),
    ("times", {}),
    ("devices", {}),
    ("locations", {}),
    ("channels", {}),
    # Realtime
    ("advanced/pulse", {}),
    ("advanced/alerts", {}),
]

results = {}
for ep, extra_params in ENDPOINTS:
    url = f"{BASE}/{ep}"
    params = {**PARAMS, **extra_params}
    try:
        r = requests.get(url, headers=HEADERS, params=params, timeout=15)
        status = r.status_code
        try:
            body = r.json()
        except:
            body = {"_raw": r.text[:500]}
        
        # Extract data
        data = body.get("data", body)
        
        # Summarize shape
        def shape(obj, depth=0):
            if depth > 3:
                return "..."
            if isinstance(obj, dict):
                return {k: shape(v, depth+1) for k, v in list(obj.items())[:20]}
            elif isinstance(obj, list):
                if len(obj) == 0:
                    return "[]"
                return [shape(obj[0], depth+1), f"...({len(obj)} items)"]
            elif isinstance(obj, (int, float)):
                return obj
            elif isinstance(obj, str):
                return obj[:80] if len(obj) > 80 else obj
            elif obj is None:
                return None
            else:
                return str(type(obj))
        
        results[ep] = {
            "status": status,
            "success": body.get("success", "N/A"),
            "shape": shape(data),
        }
        
        status_icon = "✅" if status == 200 else "❌"
        print(f"{status_icon} {ep}: HTTP {status}")
        
    except Exception as e:
        results[ep] = {"status": "ERROR", "error": str(e)}
        print(f"❌ {ep}: {e}")

print("\n" + "="*80)
print("DETAILED SHAPES:")
print("="*80)

for ep, info in results.items():
    print(f"\n{'─'*60}")
    print(f"  {ep} (HTTP {info.get('status', '?')})")
    print(f"{'─'*60}")
    if "shape" in info:
        print(json.dumps(info["shape"], indent=2, default=str))
    elif "error" in info:
        print(f"  ERROR: {info['error']}")
