#!/usr/bin/env python3
"""
Test all Ecom360 tracker event types against the live API.
Verifies the full ingestion pipeline from collect → MongoDB.
"""
import requests
import json
import time
import uuid

BASE_URL = "https://ecom.buildnetic.com/api/v1"
API_KEY = "ek_Z3KoaSngu5edQ9exUKCDSYaMqAm7xomX"
SECRET_KEY = "sk_TBE3Jms2zQnDlT3RBO2cSd5sakoTgptL"
BEARER = "27|Q4blPK7ETXNPBLhn5BucMKtCaGciZLstQpsifLu1216a8ae2"
STORE_URL = "https://stagingddf.gmraerodutyfree.in"

H_TRACK = {"Content-Type": "application/json", "X-Ecom360-Key": API_KEY}
H_AUTH = {"Content-Type": "application/json", "Accept": "application/json", "Authorization": f"Bearer {BEARER}", "X-Ecom360-Key": API_KEY}
H_SYNC = {"Content-Type": "application/json", "X-Ecom360-Key": API_KEY, "X-Ecom360-Secret": SECRET_KEY}

results = []

def test(name, method, url, headers, data=None, expect_status=None):
    try:
        if method == "POST":
            r = requests.post(url, headers=headers, json=data, timeout=30)
        else:
            r = requests.get(url, headers=headers, timeout=30)
        
        ok = r.status_code in (200, 201, 202)
        if expect_status:
            ok = r.status_code == expect_status
        
        try:
            body = r.json()
        except:
            body = r.text[:200]
        
        status = "PASS" if ok else "FAIL"
        results.append({"test": name, "status": status, "code": r.status_code, "response": body})
        print(f"  {'✅' if ok else '❌'} {name}: HTTP {r.status_code}")
        if not ok:
            print(f"     Response: {json.dumps(body, indent=2)[:200]}")
        return ok, body
    except Exception as e:
        results.append({"test": name, "status": "ERROR", "error": str(e)})
        print(f"  ❌ {name}: ERROR - {e}")
        return False, None


def run_tests():
    sid = f"copilot-test-{uuid.uuid4().hex[:8]}"
    
    print("\n" + "=" * 60)
    print("ECOM360 TRACKER EVENT VERIFICATION")
    print("=" * 60)
    
    # ── 1. Single Event Collection ──
    print("\n── 1. Single Event Collection (POST /collect) ──")
    
    test("page_view", "POST", f"{BASE_URL}/collect", H_TRACK, {
        "session_id": sid,
        "event_type": "page_view",
        "url": f"{STORE_URL}/default/",
        "metadata": {"title": "Delhi Duty Free - Home", "page_type": "homepage"}
    })
    
    test("product_view", "POST", f"{BASE_URL}/collect", H_TRACK, {
        "session_id": sid,
        "event_type": "product_view",
        "url": f"{STORE_URL}/default/johnnie-walker-blue-label.html",
        "metadata": {"product_id": "123", "sku": "JW-BLUE", "name": "Johnnie Walker Blue Label", "price": 15999, "category": "Liquor/Whisky"}
    })
    
    test("add_to_cart", "POST", f"{BASE_URL}/collect", H_TRACK, {
        "session_id": sid,
        "event_type": "add_to_cart",
        "url": f"{STORE_URL}/default/johnnie-walker-blue-label.html",
        "metadata": {"product_id": "123", "sku": "JW-BLUE", "name": "Johnnie Walker Blue Label", "price": 15999, "qty": 1}
    })
    
    test("remove_from_cart", "POST", f"{BASE_URL}/collect", H_TRACK, {
        "session_id": sid,
        "event_type": "remove_from_cart",
        "url": f"{STORE_URL}/default/checkout/cart/",
        "metadata": {"product_id": "123", "sku": "JW-BLUE", "name": "Johnnie Walker Blue Label"}
    })
    
    test("search", "POST", f"{BASE_URL}/collect", H_TRACK, {
        "session_id": sid,
        "event_type": "search",
        "url": f"{STORE_URL}/default/catalogsearch/result/?q=whisky",
        "metadata": {"query": "whisky", "results_count": 42}
    })
    
    test("checkout_step", "POST", f"{BASE_URL}/collect", H_TRACK, {
        "session_id": sid,
        "event_type": "checkout_step",
        "url": f"{STORE_URL}/default/checkout/",
        "metadata": {"step": 1, "step_name": "shipping"}
    })
    
    test("purchase", "POST", f"{BASE_URL}/collect", H_TRACK, {
        "session_id": sid,
        "event_type": "purchase",
        "url": f"{STORE_URL}/default/checkout/onepage/success/",
        "metadata": {"order_id": f"TEST-{uuid.uuid4().hex[:6]}", "total": 15999, "currency": "INR", "items": [{"sku": "JW-BLUE", "name": "Johnnie Walker Blue Label", "qty": 1, "price": 15999}]}
    })
    
    test("customer_login", "POST", f"{BASE_URL}/collect", H_TRACK, {
        "session_id": sid,
        "event_type": "customer_login",
        "url": f"{STORE_URL}/default/customer/account/",
        "metadata": {"method": "email"}
    })
    
    test("customer_logout", "POST", f"{BASE_URL}/collect", H_TRACK, {
        "session_id": sid,
        "event_type": "customer_logout",
        "url": f"{STORE_URL}/default/customer/account/logout/",
        "metadata": {}
    })
    
    test("add_to_wishlist", "POST", f"{BASE_URL}/collect", H_TRACK, {
        "session_id": sid,
        "event_type": "add_to_wishlist",
        "url": f"{STORE_URL}/default/johnnie-walker-blue-label.html",
        "metadata": {"product_id": "123", "sku": "JW-BLUE", "name": "Johnnie Walker Blue Label"}
    })
    
    test("review_submit", "POST", f"{BASE_URL}/collect", H_TRACK, {
        "session_id": sid,
        "event_type": "review_submit",
        "url": f"{STORE_URL}/default/johnnie-walker-blue-label.html",
        "metadata": {"product_id": "123", "rating": 5, "title": "Excellent whisky"}
    })
    
    test("scroll_depth", "POST", f"{BASE_URL}/collect", H_TRACK, {
        "session_id": sid,
        "event_type": "scroll_depth",
        "url": f"{STORE_URL}/default/",
        "metadata": {"depth": 75, "max_depth": 100}
    })
    
    test("engagement_time", "POST", f"{BASE_URL}/collect", H_TRACK, {
        "session_id": sid,
        "event_type": "engagement_time",
        "url": f"{STORE_URL}/default/",
        "metadata": {"seconds": 45, "total_time": 120}
    })
    
    test("exit_intent", "POST", f"{BASE_URL}/collect", H_TRACK, {
        "session_id": sid,
        "event_type": "exit_intent",
        "url": f"{STORE_URL}/default/",
        "metadata": {"time_on_page": 35}
    })
    
    test("rage_click", "POST", f"{BASE_URL}/collect", H_TRACK, {
        "session_id": sid,
        "event_type": "rage_click",
        "url": f"{STORE_URL}/default/",
        "metadata": {"element": "button.add-to-cart", "click_count": 7}
    })
    
    test("cart_update", "POST", f"{BASE_URL}/collect", H_TRACK, {
        "session_id": sid,
        "event_type": "cart_update",
        "url": f"{STORE_URL}/default/checkout/cart/",
        "metadata": {"items_count": 2, "subtotal": 31998}
    })
    
    test("free_shipping_qualified", "POST", f"{BASE_URL}/collect", H_TRACK, {
        "session_id": sid,
        "event_type": "free_shipping_qualified",
        "url": f"{STORE_URL}/default/checkout/cart/",
        "metadata": {"cart_total": 5000, "threshold": 3000}
    })
    
    # ── 2. Batch Event Collection ──
    print("\n── 2. Batch Event Collection (POST /collect/batch) ──")
    
    sid2 = f"copilot-batch-{uuid.uuid4().hex[:8]}"
    test("batch_10_events", "POST", f"{BASE_URL}/collect/batch", H_TRACK, {
        "events": [
            {"session_id": sid2, "event_type": "page_view", "url": f"{STORE_URL}/default/liquor.html", "metadata": {"page_type": "category"}},
            {"session_id": sid2, "event_type": "product_view", "url": f"{STORE_URL}/default/chivas-18.html", "metadata": {"product_id": "456", "sku": "CH-18", "name": "Chivas Regal 18", "price": 4999}},
            {"session_id": sid2, "event_type": "add_to_cart", "url": f"{STORE_URL}/default/chivas-18.html", "metadata": {"product_id": "456", "qty": 2}},
            {"session_id": sid2, "event_type": "search", "url": f"{STORE_URL}/default/catalogsearch/result/?q=perfume", "metadata": {"query": "perfume", "results_count": 18}},
            {"session_id": sid2, "event_type": "scroll_depth", "url": f"{STORE_URL}/default/liquor.html", "metadata": {"depth": 50}},
            {"session_id": sid2, "event_type": "engagement_time", "url": f"{STORE_URL}/default/liquor.html", "metadata": {"seconds": 30}},
            {"session_id": sid2, "event_type": "page_view", "url": f"{STORE_URL}/default/beauty.html", "metadata": {"page_type": "category"}},
            {"session_id": sid2, "event_type": "page_view", "url": f"{STORE_URL}/default/perfume.html", "metadata": {"page_type": "category"}},
            {"session_id": sid2, "event_type": "checkout_step", "url": f"{STORE_URL}/default/checkout/", "metadata": {"step": 2, "step_name": "payment"}},
            {"session_id": sid2, "event_type": "purchase", "url": f"{STORE_URL}/default/checkout/onepage/success/", "metadata": {"order_id": f"TEST-BATCH-{uuid.uuid4().hex[:4]}", "total": 9998}}
        ]
    })
    
    # ── 3. Server-to-Server Sync ──
    print("\n── 3. Server-to-Server Sync ──")
    
    test("sync_products", "POST", f"{BASE_URL}/sync/products", H_SYNC, {
        "products": [
            {"external_id": "SYNC-TEST-1", "sku": "TEST-SKU-1", "name": "Test Product Sync", "price": 999, "category": "Test", "status": "active"}
        ]
    })
    
    test("sync_categories", "POST", f"{BASE_URL}/sync/categories", H_SYNC, {
        "categories": [
            {"external_id": "CAT-TEST-1", "name": "Test Category Sync", "level": 1}
        ]
    })
    
    test("sync_customers", "POST", f"{BASE_URL}/sync/customers", H_SYNC, {
        "customers": [
            {"external_id": "CUST-TEST-1", "email": "test-sync@example.com", "first_name": "Test", "last_name": "Customer"}
        ]
    })
    
    # ── 4. Interventions Polling ──
    print("\n── 4. Interventions Polling ──")
    
    test("interventions_poll", "GET", f"{BASE_URL}/interventions/poll?session_id={sid}", H_TRACK)
    
    # ── 5. Analytics API Endpoints (Authenticated) ──
    print("\n── 5. Analytics Dashboard API ──")

    test("analytics_overview", "GET", f"{BASE_URL}/analytics/overview", H_AUTH)
    test("analytics_traffic", "GET", f"{BASE_URL}/analytics/traffic?period=30d", H_AUTH)
    test("analytics_realtime", "GET", f"{BASE_URL}/analytics/realtime", H_AUTH)
    test("analytics_revenue", "GET", f"{BASE_URL}/analytics/revenue?period=30d", H_AUTH)
    test("analytics_products", "GET", f"{BASE_URL}/analytics/products?period=30d", H_AUTH)
    test("analytics_categories", "GET", f"{BASE_URL}/analytics/categories?period=30d", H_AUTH)
    test("analytics_sessions", "GET", f"{BASE_URL}/analytics/sessions?period=30d", H_AUTH)
    test("analytics_page_visits", "GET", f"{BASE_URL}/analytics/page-visits?period=30d", H_AUTH)
    test("analytics_funnel", "GET", f"{BASE_URL}/analytics/funnel?period=30d", H_AUTH)
    test("analytics_customers", "GET", f"{BASE_URL}/analytics/customers?period=30d", H_AUTH)
    test("analytics_cohorts", "GET", f"{BASE_URL}/analytics/cohorts?period=30d", H_AUTH)
    test("analytics_campaigns", "GET", f"{BASE_URL}/analytics/campaigns?period=30d", H_AUTH)
    test("analytics_geographic", "GET", f"{BASE_URL}/analytics/geographic?period=30d", H_AUTH)
    test("analytics_export", "GET", f"{BASE_URL}/analytics/export?period=30d&format=json", H_AUTH)
    
    # ── 6. Invalid Event (should fail validation) ──
    print("\n── 6. Negative Tests ──")
    
    test("missing_session_id (expect 422)", "POST", f"{BASE_URL}/collect", H_TRACK, {
        "event_type": "page_view",
        "url": f"{STORE_URL}/default/"
    }, expect_status=422)
    
    test("invalid_api_key (expect 401/403)", "POST", f"{BASE_URL}/collect", 
        {"Content-Type": "application/json", "X-Ecom360-Key": "ek_invalid_key_12345"},
        {"session_id": "x", "event_type": "page_view", "url": "https://example.com"},
        expect_status=401)
    
    # ── Summary ──
    print("\n" + "=" * 60)
    total = len(results)
    passed = sum(1 for r in results if r["status"] == "PASS")
    failed = sum(1 for r in results if r["status"] == "FAIL")
    errors = sum(1 for r in results if r["status"] == "ERROR")
    
    print(f"RESULTS: {passed}/{total} passed, {failed} failed, {errors} errors")
    print("=" * 60)
    
    if failed > 0 or errors > 0:
        print("\nFailed/Error tests:")
        for r in results:
            if r["status"] != "PASS":
                print(f"  - {r['test']}: {r['status']} (HTTP {r.get('code', 'N/A')})")
    
    # Save results
    with open("tests/tracker_test_results.json", "w") as f:
        json.dump({"total": total, "passed": passed, "failed": failed, "errors": errors, "results": results}, f, indent=2, default=str)
    print(f"\nResults saved to tests/tracker_test_results.json")


if __name__ == "__main__":
    run_tests()
