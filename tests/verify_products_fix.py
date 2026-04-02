#!/usr/bin/env python3
"""Quick verify that products API now returns revenue data."""
import requests, json

H = {"Authorization": "Bearer 27|Q4blPK7ETXNPBLhn5BucMKtCaGciZLstQpsifLu1216a8ae2", "Accept": "application/json"}
r = requests.get("https://ecom.buildnetic.com/api/v1/analytics/products", params={"date_range": "30d"}, headers=H, timeout=15)
d = r.json()["data"]

perf = d.get("performance", [])
total_rev = sum(p.get("revenue", 0) for p in perf)
total_purchases = sum(p.get("purchases", 0) for p in perf)
total_views = sum(p.get("views", 0) for p in perf)
rev_prods = [p for p in perf if p.get("revenue", 0) > 0]

print("=== DASHBOARD KPIs (30d) ===")
print(f"  Products Viewed:     {total_views}")
print(f"  Products Purchased:  {total_purchases}")
print(f"  Total Revenue:       INR {total_rev:,.0f}")
print(f"  View-to-Purchase:    {(total_purchases/total_views*100) if total_views else 0:.1f}%")
print(f"  Avg Product Revenue: INR {(total_rev/len(perf)) if perf else 0:,.0f}")
print(f"  Products w/ revenue: {len(rev_prods)}/{len(perf)}")

purchases = d.get("top_by_purchases", [])
print(f"\n=== TOP BY PURCHASES: {len(purchases)} products ===")
for p in purchases[:10]:
    print(f"  {p['product_name'][:35]:35s} | qty={p['count']:3d} | rev=INR {p['revenue']:>10,.0f}")
total_purch_rev = sum(p["revenue"] for p in purchases)
print(f"  TOTAL purchase revenue: INR {total_purch_rev:,.0f}")

print(f"\n=== PERFORMANCE TABLE (top 15) ===")
for i, p in enumerate(perf[:15]):
    print(f"  {i+1:2d}. {p.get('product_name','?')[:35]:35s} | views={p.get('views',0):3d} | carts={p.get('cart_adds',0):3d} | purchases={p.get('purchases',0):3d} | rev=INR {p.get('revenue',0):>10,.0f}")

fbt = d.get("frequently_bought_together", [])
print(f"\n=== FREQUENTLY BOUGHT TOGETHER: {len(fbt)} ===")
for p in fbt[:5]:
    print(f"  {p['product_name']}: co_purchase_count={p['co_purchase_count']}")

ca = d.get("cart_abandonment", [])
print(f"\n=== CART ABANDONMENT: {len(ca)} products ===")
for p in ca[:5]:
    print(f"  {p['product_name'][:35]:35s} | carts={p['cart_adds']} | purchased={p['purchases']} | abandoned={p['abandonments']} | rate={p['abandonment_rate']}%")

# Final verdict
print("\n" + "="*60)
if total_rev > 0 and len(purchases) > 0 and total_purchases > 0:
    print("PASS: Revenue data is now populated correctly!")
else:
    print("FAIL: Revenue still showing 0")
print("="*60)
