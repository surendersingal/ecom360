#!/usr/bin/env python3
"""
==============================================================================
  DELHI DUTY FREE (GMRAE) — Production-Ready E2E Test Suite
  Magento:  https://testing.gmraerodutyfree.in  (Arrival + Departure stores)
  Ecom360:  https://ecom.buildnetic.com
==============================================================================

DUTY-FREE BUSINESS SCENARIOS COVERED:

  USER JOURNEYS (5 segments)
  ├─ Pre-flight gift buyer      — quick browse → product search → cart → buy
  ├─ Business traveler          — known brand, add to cart, fast checkout
  ├─ Luxury shopper             — perfume/cosmetics, chatbot-assisted
  ├─ Budget-conscious traveler  — searches by price/offers, compares products
  └─ Returning loyalty customer — personalised recommendations, CLV tracking

  MODULE TESTS (7 sections, ~200 test cases)
  ├─ 1. Frontend Verification   — tracker injection both stores, config integrity
  ├─ 2. DataSync                — product/category/customer/order sync status
  ├─ 3. Analytics & Tracking    — event pipeline, CDP, batch, real-time
  ├─ 4. AI Search               — all product categories, multi-language, budget
  ├─ 5. Chatbot                 — all conversation scenarios, objection handling
  ├─ 6. Business Intelligence   — KPIs, reports, predictions, Customer 360
  └─ 7. Marketing               — contacts, templates, campaigns, automations

  CUSTOMER SIMULATION (100 customers, Arrival + Departure, paced)
  └─ Full funnel: page_view → search → product_view → add_to_cart → purchase
"""

import json, random, time, sys, re
from datetime import datetime
from urllib.parse import quote

try:
    import requests
except ImportError:
    print("pip3 install requests"); sys.exit(1)

requests.packages.urllib3.disable_warnings()

# ─────────────────────────── Config ──────────────────────────────────────
BASE_URL   = "https://ecom.buildnetic.com"
API_KEY    = "ek_Z3KoaSngu5edQ9exUKCDSYaMqAm7xomX"
SECRET_KEY = "sk_TBE3Jms2zQnDlT3RBO2cSd5sakoTgptL"
BEARER     = "31|b7BpVxuo3EbIjbppafdNsXfzttLku46ir8t0HMAme98dc255"

ARRIVAL_URL   = "https://testing.gmraerodutyfree.in/"
DEPARTURE_URL = "https://testing.gmraerodutyfree.in/departure/"

HEADERS_SYNC = {
    "X-Ecom360-Key": API_KEY,
    "X-Ecom360-Secret": SECRET_KEY,
    "Content-Type": "application/json",
    "Accept": "application/json",
}
HEADERS_PUBLIC = {
    "X-Ecom360-Key": API_KEY,
    "Content-Type": "application/json",
    "Accept": "application/json",
}
HEADERS_AUTH = {
    "Authorization": f"Bearer {BEARER}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

DELAY = 1.0  # base delay between API requests

# ── Duty-free product catalog (mirrors DDF categories) ──
DDF_PRODUCTS = {
    "spirits": [
        {"id": "101", "sku": "JW-BL-700ML",   "name": "Johnnie Walker Black Label",    "price": 35.99,  "brand": "Johnnie Walker"},
        {"id": "102", "sku": "GF-12-750ML",    "name": "Glenfiddich 12 Year Old",       "price": 45.00,  "brand": "Glenfiddich"},
        {"id": "103", "sku": "JD-OLD7-700ML",  "name": "Jack Daniel's Old No. 7",       "price": 28.50,  "brand": "Jack Daniel's"},
        {"id": "104", "sku": "GL-18-700ML",    "name": "Glenlivet 18 Year Old",         "price": 89.00,  "brand": "The Glenlivet"},
        {"id": "105", "sku": "ABV-16-700ML",   "name": "Aberfeldy 16 Year Old",         "price": 72.00,  "brand": "Aberfeldy"},
        {"id": "106", "sku": "GREY-750ML",     "name": "Grey Goose Vodka",              "price": 39.00,  "brand": "Grey Goose"},
        {"id": "107", "sku": "BAC-WHITE-700",  "name": "Bacardi White Rum",             "price": 22.00,  "brand": "Bacardi"},
        {"id": "108", "sku": "HENN-VS-700ML",  "name": "Hennessy VS Cognac",            "price": 55.00,  "brand": "Hennessy"},
        {"id": "109", "sku": "MOET-750ML",     "name": "Moët & Chandon Impérial",       "price": 48.00,  "brand": "Moët & Chandon"},
        {"id": "110", "sku": "TANQ-700ML",     "name": "Tanqueray London Dry Gin",      "price": 32.00,  "brand": "Tanqueray"},
    ],
    "perfumes": [
        {"id": "201", "sku": "CH-N5-50ML",     "name": "Chanel No. 5 EDP 50ml",         "price": 89.00,  "brand": "Chanel"},
        {"id": "202", "sku": "DIOR-MISS-30ML", "name": "Miss Dior EDT 30ml",             "price": 65.00,  "brand": "Dior"},
        {"id": "203", "sku": "YSL-BOR-90ML",   "name": "YSL Black Opium EDP 90ml",       "price": 95.00,  "brand": "YSL"},
        {"id": "204", "sku": "ARMANI-SI-100ML","name": "Giorgio Armani Sì EDP 100ml",    "price": 88.00,  "brand": "Armani"},
        {"id": "205", "sku": "BV-MEN-100ML",   "name": "Burberry Hero EDT 100ml",        "price": 72.00,  "brand": "Burberry"},
    ],
    "tobacco": [
        {"id": "301", "sku": "MARL-RED-200",   "name": "Marlboro Red 200s",             "price": 18.00,  "brand": "Marlboro"},
        {"id": "302", "sku": "CAMEL-200",       "name": "Camel 200s",                    "price": 16.50,  "brand": "Camel"},
        {"id": "303", "sku": "COHIBA-25",       "name": "Cohiba Siglo I Cigars (25)",    "price": 210.00, "brand": "Cohiba"},
    ],
    "confectionery": [
        {"id": "401", "sku": "FERR-T16-200G",  "name": "Ferrero Rocher T16 200g",       "price": 12.00,  "brand": "Ferrero"},
        {"id": "402", "sku": "LINDT-DG-200G",  "name": "Lindt Dark Gold 200g",           "price": 9.50,   "brand": "Lindt"},
        {"id": "403", "sku": "TOBL-LARGE-400G","name": "Toblerone Large 400g",           "price": 11.00,  "brand": "Toblerone"},
    ],
    "cosmetics": [
        {"id": "501", "sku": "ESTEE-FOND-SPF", "name": "Estée Lauder Foundation SPF",   "price": 45.00,  "brand": "Estée Lauder"},
        {"id": "502", "sku": "LANCOME-ADV",    "name": "Lancôme Advanced Génifique",     "price": 79.00,  "brand": "Lancôme"},
    ],
}

ALL_PRODUCTS = [p for cat in DDF_PRODUCTS.values() for p in cat]

SEARCH_TERMS = {
    "brand":    ["chanel", "glenfiddich", "hennessy", "dior", "marlboro", "bacardi", "tanqueray"],
    "category": ["single malt", "eau de parfum", "dark chocolate", "luxury gift set", "cognac"],
    "budget":   ["whisky under 50", "perfume under 70", "gift under 30"],
    "intent":   ["birthday gift for wife", "best scotch whisky", "duty free allowance"],
    "multilang":["whisky", "parfum", "alkohol", "chocolate"],
}

# ── Duty-free customer segments ──
CUSTOMER_SEGMENTS = ["gift_buyer", "business_traveler", "luxury_shopper", "budget_shopper", "returning_customer"]


# ─────────────────────────── Test Runner ─────────────────────────────────
class E2ERunner:
    def __init__(self):
        self.sections  = {}
        self.current   = None
        self.total_pass = 0
        self.total_fail = 0
        self.session   = requests.Session()

    def section(self, name):
        self.current = name
        if name not in self.sections:
            self.sections[name] = {"pass": 0, "fail": 0, "tests": []}
        print(f"\n{'─'*66}")
        print(f"  {name}")
        print(f"{'─'*66}")

    def check(self, label, passed, detail=""):
        sec = self.sections[self.current]
        if passed:
            sec["pass"] += 1; self.total_pass += 1
            print(f"  ✅ {label}")
        else:
            sec["fail"] += 1; self.total_fail += 1
            print(f"  ❌ {label}{': ' + str(detail)[:120] if detail else ''}")
        sec["tests"].append({"label": label, "passed": passed, "detail": str(detail)[:200]})
        return passed

    def api(self, method, path, data=None, headers=None, label="", expect=None, delay=True):
        if headers is None: headers = HEADERS_AUTH
        if expect is None:  expect  = [200, 201, 207]
        if delay: time.sleep(DELAY)
        url = f"{BASE_URL}{path}"
        try:
            r = self.session.request(method, url, json=data, headers=headers, timeout=25, verify=False)
            if r.status_code == 429:
                time.sleep(5)
                r = self.session.request(method, url, json=data, headers=headers, timeout=25, verify=False)
            passed = r.status_code in expect
            body   = {}
            try: body = r.json()
            except: pass
            if label:
                detail = ""
                if not passed:
                    detail = (body.get("message", r.text[:120]) if isinstance(body, dict) else r.text[:120])
                    detail = f"[{r.status_code}] {detail}"
                self.check(label, passed, detail)
            return body if passed else None, r.status_code
        except Exception as e:
            if label: self.check(label, False, str(e)[:120])
            return None, 0


# ═════════════════════════════════════════════════════════════════════════
#  SECTION 1 — FRONTEND VERIFICATION
# ═════════════════════════════════════════════════════════════════════════
def test_frontend(r: E2ERunner):
    r.section("1. FRONTEND VERIFICATION — Tracker Injection (Arrival + Departure)")

    for store_name, url, expected_store in [
        ("Arrival store",   ARRIVAL_URL,   "default"),
        ("Departure store", DEPARTURE_URL, "departure"),
    ]:
        try:
            resp = requests.get(url, timeout=20, verify=True)
            r.check(f"{store_name} — HTTP 200", resp.status_code == 200)
            html = resp.text

            has_tracker = 'id="ecom360-config"' in html
            r.check(f"{store_name} — Ecom360 tracker block injected", has_tracker)

            if has_tracker:
                m = re.search(r'<script id="ecom360-config" type="application/json">\s*(.*?)\s*</script>', html, re.DOTALL)
                if m:
                    cfg = json.loads(m.group(1))
                    r.check(f"{store_name} — api_key present", bool(cfg.get("api_key")))
                    r.check(f"{store_name} — server_url = {BASE_URL}", cfg.get("server_url") == BASE_URL)
                    tracking = cfg.get("tracking", {})
                    r.check(f"{store_name} — page_views tracking on",  tracking.get("page_views") is True)
                    r.check(f"{store_name} — cart tracking on",        tracking.get("cart") is True)
                    r.check(f"{store_name} — purchase tracking on",    tracking.get("purchases") is True)
                    r.check(f"{store_name} — search tracking on",      tracking.get("search") is True)
                    r.check(f"{store_name} — page.type = homepage",    cfg.get("page", {}).get("type") == "homepage")
                    r.check(f"{store_name} — session_timeout set",     cfg.get("session_timeout", 0) > 0)
        except Exception as e:
            r.check(f"{store_name} — reachable", False, str(e))

    # Verify chatbot + AI search blocks present
    try:
        html = requests.get(ARRIVAL_URL, timeout=20, verify=True).text
        r.check("Arrival — chatbot widget block present", "ecom360.chatbot" in html or "chatbot" in html.lower())
        r.check("Arrival — AI search widget block present", "ecom360-search" in html or "ecom360.aisearch" in html or "ai-search" in html.lower())
    except Exception as e:
        r.check("Arrival — widget blocks check", False, str(e))


# ═════════════════════════════════════════════════════════════════════════
#  SECTION 2 — DATASYNC
# ═════════════════════════════════════════════════════════════════════════
def test_datasync(r: E2ERunner):
    r.section("2. DATASYNC — Catalog, Orders & Customer Data Integrity")

    # Sync status (verify connection to ecom360 platform is live)
    body, _ = r.api("GET", "/api/v1/sync/status", headers=HEADERS_SYNC, label="Sync status endpoint")
    if body and isinstance(body, dict):
        info = body.get("data") or body
        if isinstance(info, dict):
            products_count  = info.get("products_count")  or info.get("products")  or 0
            categories_count= info.get("categories_count")or info.get("categories")or 0
            customers_count = info.get("customers_count") or info.get("customers") or 0
            r.check(f"Products in platform (expect ≥ 3000): {products_count}",   int(products_count  or 0) >= 3000)
            r.check(f"Categories in platform (expect ≥ 100): {categories_count}", int(categories_count or 0) >= 100)
            r.check(f"Customers in platform (expect ≥ 10000): {customers_count}", int(customers_count  or 0) >= 10000)

    # Product/customer sync (POST = push data TO platform)
    r.api("POST", "/api/v1/sync/products", headers=HEADERS_SYNC, label="Sync products (push batch)", data={
        "products": [{"id": "101", "sku": "JW-BL-700ML", "name": "Johnnie Walker Black Label",
                      "price": 35.99, "status": "enabled", "visibility": 4, "type": "simple",
                      "categories": ["Spirits", "Whisky"], "category_ids": ["10", "11"],
                      "brand": "Johnnie Walker", "updated_at": datetime.now().isoformat()}],
        "store_id": 1, "platform": "magento2",
        "sync_config": {"brand_attribute": "manufacturer"},
    })

    # Inventory update (duty-free stock management)
    r.api("POST", "/api/v1/sync/inventory", headers=HEADERS_SYNC, label="Inventory update (terminal stock)", data={
        "items": [
            {"product_id": "101", "sku": "JW-BL-700ML", "qty": 450, "is_in_stock": True},
            {"product_id": "106", "sku": "GREY-750ML",  "qty": 200, "is_in_stock": True},
            {"product_id": "201", "sku": "CH-N5-50ML",  "qty": 80,  "is_in_stock": True},
            {"product_id": "301", "sku": "MARL-RED-200","qty": 600, "is_in_stock": True},
        ]
    })

    # Permissions check
    r.api("POST", "/api/v1/sync/permissions", headers=HEADERS_SYNC, label="Sync permissions", data={
        "permissions": {"products": True, "categories": True, "customers": True, "orders": True}
    })

    # Webhook registration (for real-time sync triggers from Magento)
    r.api("POST", "/api/v1/sync/webhook", headers=HEADERS_SYNC, label="Register Magento webhook", data={
        "url": "https://testing.gmraerodutyfree.in/ecom360/webhook/receive",
        "events": ["product.updated", "order.placed", "customer.registered"],
        "secret": "wh_test_secret_ddf",
    })


# ═════════════════════════════════════════════════════════════════════════
#  SECTION 3 — ANALYTICS & TRACKING
# ═════════════════════════════════════════════════════════════════════════
def test_analytics(r: E2ERunner):
    r.section("3. ANALYTICS — Event Pipeline, CDP, Real-Time")

    ts       = int(time.time())
    session1 = f"arr_session_{ts}"
    session2 = f"dep_session_{ts}"
    visitor1 = f"visitor_arr_{ts}"
    visitor2 = f"visitor_dep_{ts}"
    email1   = f"arrival_customer_{ts}@ddf.test"
    email2   = f"departure_customer_{ts}@ddf.test"
    product  = random.choice(DDF_PRODUCTS["spirits"])

    print("\n  — Arrival store funnel —")

    # 1. Page view — arrival store
    r.api("POST", "/api/v1/collect", headers=HEADERS_PUBLIC,
          label="[Arrival] page_view — homepage", data={
              "event_type": "page_view", "session_id": session1, "visitor_id": visitor1,
              "url": ARRIVAL_URL, "title": "Delhi Duty Free — Arrival",
              "store_id": "default", "platform": "magento2",
          })

    # 2. Search — brand
    r.api("POST", "/api/v1/collect", headers=HEADERS_PUBLIC,
          label="[Arrival] search — 'johnnie walker'", data={
              "event_type": "search", "session_id": session1, "visitor_id": visitor1,
              "url": ARRIVAL_URL, "query": "johnnie walker",
              "results_count": 4, "store_id": "default", "platform": "magento2",
          })

    # 3. Product view
    r.api("POST", "/api/v1/collect", headers=HEADERS_PUBLIC,
          label=f"[Arrival] product_view — {product['name']}", data={
              "event_type": "product_view", "session_id": session1, "visitor_id": visitor1,
              "url": f"{ARRIVAL_URL}{product['name'].lower().replace(' ', '-')}.html",
              "product_id": product["id"], "product_name": product["name"],
              "price": product["price"], "brand": product["brand"],
              "category": "spirits", "store_id": "default", "platform": "magento2",
          })

    # 4. Add to cart
    r.api("POST", "/api/v1/collect", headers=HEADERS_PUBLIC,
          label=f"[Arrival] add_to_cart — {product['name']} × 2", data={
              "event_type": "add_to_cart", "session_id": session1, "visitor_id": visitor1,
              "url": f"{ARRIVAL_URL}{product['name'].lower().replace(' ', '-')}.html",
              "product_id": product["id"], "product_name": product["name"],
              "price": product["price"], "quantity": 2,
              "store_id": "default", "platform": "magento2",
          })

    # 5. Purchase — arrival store
    r.api("POST", "/api/v1/collect", headers=HEADERS_PUBLIC,
          label="[Arrival] purchase — order placed", data={
              "event_type": "purchase", "session_id": session1, "visitor_id": visitor1,
              "url": f"{ARRIVAL_URL}checkout/onepage/success/",
              "order_id": f"DDF-ARR-E2E-{ts}", "total": product["price"] * 2,
              "currency": "USD", "platform": "magento2",
              "customer_identifier": {"type": "email", "value": email1},
              "items": [{"product_id": product["id"], "sku": product["sku"],
                         "name": product["name"], "price": product["price"], "quantity": 2}],
              "store_id": "default",
          })

    print("\n  — Departure store funnel —")
    perf = random.choice(DDF_PRODUCTS["perfumes"])

    r.api("POST", "/api/v1/collect", headers=HEADERS_PUBLIC,
          label="[Departure] page_view — homepage", data={
              "event_type": "page_view", "session_id": session2, "visitor_id": visitor2,
              "url": DEPARTURE_URL, "title": "Delhi Duty Free — Departure",
              "store_id": "departure", "platform": "magento2",
          })

    r.api("POST", "/api/v1/collect", headers=HEADERS_PUBLIC,
          label="[Departure] search — 'chanel perfume'", data={
              "event_type": "search", "session_id": session2, "visitor_id": visitor2,
              "url": DEPARTURE_URL, "query": "chanel perfume",
              "results_count": 3, "store_id": "departure", "platform": "magento2",
          })

    r.api("POST", "/api/v1/collect", headers=HEADERS_PUBLIC,
          label=f"[Departure] product_view — {perf['name']}", data={
              "event_type": "product_view", "session_id": session2, "visitor_id": visitor2,
              "url": f"{DEPARTURE_URL}{perf['name'].lower().replace(' ', '-')}.html",
              "product_id": perf["id"], "product_name": perf["name"],
              "price": perf["price"], "brand": perf["brand"],
              "category": "perfumes", "store_id": "departure", "platform": "magento2",
          })

    # Abandoned cart scenario (view + add to cart, NO purchase)
    r.api("POST", "/api/v1/collect", headers=HEADERS_PUBLIC,
          label="[Departure] add_to_cart — ABANDONED CART scenario", data={
              "event_type": "add_to_cart", "session_id": session2, "visitor_id": visitor2,
              "url": f"{DEPARTURE_URL}{perf['name'].lower().replace(' ', '-')}.html",
              "product_id": perf["id"], "product_name": perf["name"],
              "price": perf["price"], "quantity": 1,
              "store_id": "departure", "platform": "magento2",
          })
    # No purchase event — triggers abandoned cart automation

    print("\n  — Batch events (peak traffic simulation) —")
    confec = random.choice(DDF_PRODUCTS["confectionery"])
    batch_events = []
    for i in range(1, 8):
        v = f"batch_vis_{ts}_{i}"
        s = f"batch_sess_{ts}_{i}"
        p = random.choice(ALL_PRODUCTS)
        store = "departure" if i % 2 == 0 else "default"
        store_url = DEPARTURE_URL if store == "departure" else ARRIVAL_URL
        batch_events.extend([
            {"event_type": "page_view", "session_id": s, "visitor_id": v,
             "url": store_url, "store_id": store},
            {"event_type": "product_view", "session_id": s, "visitor_id": v,
             "url": f"{store_url}{p['name'].lower().replace(' ','-')}.html",
             "product_id": p["id"], "product_name": p["name"],
             "price": p["price"], "store_id": store},
        ])
    r.api("POST", "/api/v1/collect/batch", headers=HEADERS_PUBLIC,
          label="Batch: 14 events (7 customers, both stores)", data={"events": batch_events})

    print("\n  — CDP & advanced analytics —")
    r.api("GET", f"/api/v1/cdp/profiles?email={quote(email1)}&limit=1",
          label="CDP profile — arrival customer (post-purchase)")

    r.api("GET", "/api/v1/analytics/realtime", label="Real-time dashboard — live visitors")
    r.api("GET", "/api/v1/analytics/overview", label="Analytics overview (today + trends)")
    r.api("GET", "/api/v1/analytics/traffic", label="Traffic stats")

    # Custom event — register definition first, then fire it
    r.api("POST", "/api/v1/analytics/events/custom/definitions", headers=HEADERS_AUTH,
          label="Register custom event definition (duty_free_allowance_check)", data={
              "event_key": "duty_free_allowance_check",
              "display_name": "Duty Free Allowance Check",
              "description": "Fired when a traveler checks their duty-free allowance",
              "properties": [
                  {"name": "nationality", "type": "string"},
                  {"name": "destination", "type": "string"},
                  {"name": "category", "type": "string"},
              ],
          })
    r.api("POST", "/api/v1/analytics/events/custom", headers=HEADERS_AUTH,
          label="Custom event — duty_free_allowance_check (fire)", data={
              "event_key": "duty_free_allowance_check",
              "session_id": session1, "visitor_id": visitor1,
              "url": ARRIVAL_URL,
              "properties": {"nationality": "IN", "destination": "LON", "category": "spirits"},
          })

    # Customer login event
    r.api("POST", "/api/v1/collect", headers=HEADERS_PUBLIC,
          label="Collect customer_login event", data={
              "event_type": "customer_login",
              "session_id": f"login_{ts}", "visitor_id": f"lv_{ts}",
              "url": ARRIVAL_URL,
              "customer_identifier": {"type": "email", "value": email1},
              "store_id": "default", "platform": "magento2",
          })

    # Wishlist event
    r.api("POST", "/api/v1/collect", headers=HEADERS_PUBLIC,
          label="Collect wishlist_add event", data={
              "event_type": "wishlist_add",
              "session_id": f"wl_{ts}", "visitor_id": f"wlv_{ts}",
              "url": DEPARTURE_URL,
              "product_id": "201", "product_name": "Chanel No. 5 EDP 50ml", "price": 89.00,
              "customer_identifier": {"type": "email", "value": email2},
              "store_id": "departure", "platform": "magento2",
          })

    # NLQ — natural language query for analytics
    r.api("POST", "/api/v1/analytics/advanced/ask", label="NLQ — top selling spirits this week", data={
        "q": "What are the top 5 selling spirits this week by revenue?"
    })


# ═════════════════════════════════════════════════════════════════════════
#  SECTION 4 — AI SEARCH
# ═════════════════════════════════════════════════════════════════════════
def test_search(r: E2ERunner):
    r.section("4. AI SEARCH — All Query Types (Duty-Free Catalog)")

    r.api("GET", "/api/v1/search/trending", headers=HEADERS_PUBLIC,
          label="Trending items (most searched at DDF)")
    r.api("GET", "/api/v1/search/widget-config", headers=HEADERS_PUBLIC,
          label="Search widget config (API key valid)")

    print("\n  — Brand searches —")
    for brand in ["chanel", "glenfiddich", "hennessy", "marlboro", "bacardi"]:
        r.api("GET", f"/api/v1/search?q={quote(brand)}&limit=5",
              headers=HEADERS_PUBLIC, label=f"Brand search: '{brand}'")

    print("\n  — Category / type searches —")
    for term in ["single malt whisky", "eau de parfum", "duty free chocolate", "cigars", "cognac"]:
        r.api("GET", f"/api/v1/search?q={quote(term)}&limit=5",
              headers=HEADERS_PUBLIC, label=f"Category search: '{term}'")

    print("\n  — Budget / price-driven searches —")
    for term in ["whisky under 40", "perfume under 100", "gift under 25"]:
        r.api("GET", f"/api/v1/search?q={quote(term)}&limit=5",
              headers=HEADERS_PUBLIC, label=f"Budget search: '{term}'")

    print("\n  — Intent / gift searches —")
    for term in ["birthday gift for wife", "best scotch whisky gift", "luxury perfume gift set"]:
        r.api("GET", f"/api/v1/search?q={quote(term)}&limit=5",
              headers=HEADERS_PUBLIC, label=f"Intent search: '{term}'")

    print("\n  — Multi-language queries —")
    for term in ["parfum", "whisky", "alkohol", "chocolat"]:
        r.api("GET", f"/api/v1/search?q={quote(term)}&limit=3",
              headers=HEADERS_PUBLIC, label=f"Multi-lang search: '{term}'")

    print("\n  — Autocomplete (keystroke simulation) —")
    for prefix in ["whi", "per", "cho", "cog", "glenf"]:
        r.api("GET", f"/api/v1/search/suggest?q={quote(prefix)}",
              headers=HEADERS_PUBLIC, label=f"Autocomplete: '{prefix}'")

    print("\n  — Similar products (cross-sell) —")
    for pid, name in [("101", "Johnnie Walker → similar spirits"),
                      ("201", "Chanel No.5 → similar perfumes"),
                      ("401", "Ferrero → similar confectionery")]:
        r.api("GET", f"/api/v1/search/similar/{pid}?limit=3",
              headers=HEADERS_PUBLIC, label=f"Similar products: {name}")

    # Store-scoped search (Departure vs Arrival catalog may differ)
    r.api("GET", f"/api/v1/search?q=whisky&store_id=departure&limit=3",
          headers=HEADERS_PUBLIC, label="Search scoped to Departure store")
    r.api("GET", f"/api/v1/search?q=whisky&store_id=default&limit=3",
          headers=HEADERS_PUBLIC, label="Search scoped to Arrival store")


# ═════════════════════════════════════════════════════════════════════════
#  SECTION 5 — CHATBOT
# ═════════════════════════════════════════════════════════════════════════
def test_chatbot(r: E2ERunner):
    r.section("5. CHATBOT — All Duty-Free Conversation Scenarios")

    ts = int(time.time())

    def new_session(suffix=""):
        return f"e2e_chat_{ts}_{suffix}"

    r.api("GET", "/api/v1/chatbot/widget-config", headers=HEADERS_PUBLIC,
          label="Chatbot widget config (API key valid)")

    print("\n  — Scenario 1: Gift buyer (Arrival store) —")
    s1 = new_session("gift")
    r.api("POST", "/api/v1/chatbot/send", headers=HEADERS_PUBLIC,
          label="[Gift buyer] initial message", data={
              "message": "I want to buy a gift for my husband who likes whisky",
              "session_id": s1, "platform": "magento2", "store_id": "default",
          })
    r.api("POST", "/api/v1/chatbot/send", headers=HEADERS_PUBLIC,
          label="[Gift buyer] budget qualifier", data={
              "message": "My budget is around $50",
              "session_id": s1, "platform": "magento2",
          })
    r.api("POST", "/api/v1/chatbot/send", headers=HEADERS_PUBLIC,
          label="[Gift buyer] recommendation request", data={
              "message": "What whisky would you recommend for a gift under $50?",
              "session_id": s1, "platform": "magento2",
          })

    print("\n  — Scenario 2: Business traveler (Departure store) —")
    s2 = new_session("biz")
    r.api("POST", "/api/v1/chatbot/send", headers=HEADERS_PUBLIC,
          label="[Business traveler] quick product inquiry", data={
              "message": "Do you have Glenfiddich 12 year?",
              "session_id": s2, "platform": "magento2", "store_id": "departure",
          })
    r.api("POST", "/api/v1/chatbot/send", headers=HEADERS_PUBLIC,
          label="[Business traveler] quantity check", data={
              "message": "I want to buy two bottles of single malt for colleagues, what's available?",
              "session_id": s2, "platform": "magento2", "store_id": "departure",
          })
    r.api("POST", "/api/v1/chatbot/advanced/order-tracking", headers=HEADERS_PUBLIC,
          label="[Business traveler] order tracking query", data={
              "session_id": s2, "order_id": "DDF-TEST-001",
          })

    print("\n  — Scenario 3: Luxury shopper (Departure store) —")
    s3 = new_session("luxury")
    r.api("POST", "/api/v1/chatbot/send", headers=HEADERS_PUBLIC,
          label="[Luxury shopper] perfume consultation", data={
              "message": "I'm looking for a Chanel perfume for my wife, she likes floral scents",
              "session_id": s3, "platform": "magento2", "store_id": "departure",
          })
    r.api("POST", "/api/v1/chatbot/send", headers=HEADERS_PUBLIC,
          label="[Luxury shopper] luxury recommendation", data={
              "message": "What's the most popular luxury perfume under $120?",
              "session_id": s3, "platform": "magento2", "store_id": "departure",
          })

    print("\n  — Scenario 4: Duty-free allowance inquiry —")
    s4 = new_session("allowance")
    r.api("POST", "/api/v1/chatbot/send", headers=HEADERS_PUBLIC,
          label="[Allowance] 'What is my duty free allowance for India?'", data={
              "message": "What is my duty free allowance for spirits when entering India?",
              "session_id": s4, "platform": "magento2",
          })

    print("\n  — Scenario 5: Price objection handling —")
    s5 = new_session("objection")
    r.api("POST", "/api/v1/chatbot/advanced/objection-handler", headers=HEADERS_PUBLIC,
          label="Objection: price too high", data={
              "session_id": s5, "objection_type": "price", "product_id": "201",
          })
    r.api("POST", "/api/v1/chatbot/advanced/objection-handler", headers=HEADERS_PUBLIC,
          label="Objection: shipping concern", data={
              "session_id": s5, "objection_type": "shipping",
          })
    r.api("POST", "/api/v1/chatbot/advanced/objection-handler", headers=HEADERS_PUBLIC,
          label="Objection: quality concern", data={
              "session_id": s5, "objection_type": "quality",
          })
    r.api("POST", "/api/v1/chatbot/advanced/objection-handler", headers=HEADERS_PUBLIC,
          label="Objection: trust concern (returns policy)", data={
              "session_id": s5, "objection_type": "returns",
          })

    print("\n  — Scenario 6: Form submissions —")
    s6 = new_session("form")
    r.api("POST", "/api/v1/chatbot/form-submit", headers=HEADERS_PUBLIC,
          label="Form submit: newsletter signup", data={
              "session_id": s6,
              "form_id": f"form_newsletter_{ts}",
              "form_data": {"email": f"newsletter_{ts}@ddf.test", "name": "Test Traveler"},
          })


# ═════════════════════════════════════════════════════════════════════════
#  SECTION 6 — BUSINESS INTELLIGENCE
# ═════════════════════════════════════════════════════════════════════════
def test_bi(r: E2ERunner):
    r.section("6. BUSINESS INTELLIGENCE — Reports, KPIs, Predictions")

    ts = int(time.time())

    print("\n  — KPIs & dashboards —")
    r.api("GET", "/api/v1/bi/kpis?period=last_7_days", label="KPIs — last 7 days")
    r.api("GET", "/api/v1/bi/kpis?period=last_30_days", label="KPIs — last 30 days")
    r.api("GET", "/api/v1/bi/kpis?period=last_90_days", label="KPIs — last 90 days (quarterly)")

    print("\n  — Reports —")
    # Revenue by category (duty-free key metric)
    body1, _ = r.api("POST", "/api/v1/bi/reports", label="Create: revenue by category report", data={
        "name": f"DDF Revenue by Category {ts}",
        "type": "revenue",
        "description": "Revenue breakdown by product category",
        "config": {"period": "last_30_days", "group_by": "category"},
    })
    r_id = (body1 or {}).get("data", {}).get("id") or (body1 or {}).get("id")

    # Conversion funnel
    body2, _ = r.api("POST", "/api/v1/bi/reports", label="Create: conversion funnel report", data={
        "name": f"DDF Conversion Funnel {ts}",
        "type": "revenue",
        "description": "Conversion funnel analysis",
        "config": {"period": "last_7_days", "group_by": "day"},
    })
    funnel_id = (body2 or {}).get("data", {}).get("id") or (body2 or {}).get("id")

    # Arrival vs Departure store comparison
    body3, _ = r.api("POST", "/api/v1/bi/reports", label="Create: store comparison (Arrival vs Departure)", data={
        "name": f"DDF Store Comparison {ts}",
        "type": "revenue",
        "description": "Compare Arrival and Departure store revenue",
        "config": {"period": "last_30_days", "group_by": "day"},
    })
    comp_id = (body3 or {}).get("data", {}).get("id") or (body3 or {}).get("id")

    if r_id:
        r.api("GET", f"/api/v1/bi/reports/{r_id}", label="Fetch revenue report")
        r.api("POST", f"/api/v1/bi/reports/{r_id}/execute", label="Execute revenue report")

    r.api("GET", "/api/v1/bi/reports", label="List all reports")

    print("\n  — Dashboards —")
    body4, _ = r.api("POST", "/api/v1/bi/dashboards", label="Create executive dashboard", data={
        "name": f"DDF Executive Dashboard {ts}",
        "description": "Executive overview dashboard",
        "layout": [{"id": "w1", "x": 0, "y": 0, "w": 6, "h": 4},
                   {"id": "w2", "x": 6, "y": 0, "w": 6, "h": 4}],
        "widgets": [],
    })
    dash_id = (body4 or {}).get("data", {}).get("id") or (body4 or {}).get("id")
    r.api("GET", "/api/v1/bi/dashboards", label="List dashboards")
    if dash_id:
        r.api("GET", f"/api/v1/bi/dashboards/{dash_id}", label="Get dashboard by ID")

    print("\n  — Alerts (duty-free operations) —")
    r.api("POST", "/api/v1/bi/alerts", label="Alert: revenue drop > 20%", data={
        "name": f"Revenue Drop Alert {ts}",
        "metric": "revenue",
        "condition": "change_percent",
        "threshold": -20,
        "period": "daily",
        "notification_channels": ["email"],
    })
    r.api("POST", "/api/v1/bi/alerts", label="Alert: conversion rate below 2%", data={
        "name": f"Low Conversion Alert {ts}",
        "metric": "conversion_rate",
        "condition": "below",
        "threshold": 2.0,
        "period": "daily",
        "notification_channels": ["email"],
    })

    print("\n  — AI Predictions —")
    r.api("GET", "/api/v1/bi/insights/predictions", label="List AI predictions")
    r.api("POST", "/api/v1/bi/insights/predictions/generate", label="Generate revenue forecast", data={
        "model_type": "revenue_forecast",
    })

    # CLV what-if (price sensitivity for duty-free shoppers)
    r.api("POST", "/api/v1/analytics/advanced/clv/what-if", label="CLV what-if (10% price increase)", data={
        "visitor_id": "e2e_visitor_clv",
        "scenario": {"price_change_pct": 10, "category": "spirits"},
    })

    print("\n  — Ad-hoc analytics queries —")
    r.api("POST", "/api/v1/bi/insights/query", label="Ad-hoc: top products by order status", data={
        "data_source": "orders",
        "filters": {},
        "group_by": "status",
        "aggregations": [{"field": "total", "function": "sum"}],
    })
    r.api("GET", "/api/v1/bi/intel/revenue/command-center", label="Revenue command center")
    r.api("GET", "/api/v1/bi/intel/revenue/by-day", label="Revenue by day")
    r.api("GET", "/api/v1/bi/intel/products/leaderboard", label="Product leaderboard (top sellers)")
    r.api("GET", "/api/v1/bi/intel/customers/overview", label="Customer overview")

    print("\n  — Customer 360 (loyalty traveler) —")
    r.api("GET", f"/api/v1/bi/intel/cross/customer-360?email={quote('testing_e2e_001@example.com')}",
          label="Customer 360 — loyalty customer profile")

    print("\n  — Natural language queries —")
    r.api("POST", "/api/v1/analytics/advanced/ask", label="NLQ: 'Which spirits brand sold most last month?'", data={
        "q": "Which spirits brand generated the most revenue last month?"
    })
    r.api("POST", "/api/v1/analytics/advanced/ask", label="NLQ: 'Compare Arrival vs Departure store revenue'", data={
        "q": "Compare revenue between Arrival and Departure stores this month"
    })

    print("\n  — Why/Explain (AI insight narratives) —")
    today   = datetime.now().strftime("%Y-%m-%d")
    last_wk = (datetime.now() - timedelta(days=7)).strftime("%Y-%m-%d")
    r.api("POST", "/api/v1/analytics/advanced/why", label="Why-explain: revenue change last 7 days", data={
        "metric": "revenue", "start_date": last_wk, "end_date": today,
    })


# ═════════════════════════════════════════════════════════════════════════
#  SECTION 7 — MARKETING
# ═════════════════════════════════════════════════════════════════════════
def test_marketing(r: E2ERunner):
    r.section("7. MARKETING — Contacts, Campaigns & Automation")

    ts = int(time.time())

    print("\n  — Contacts (duty-free travelers CRM) —")
    bodies = []
    for i, (name, segment) in enumerate([
        ("Rahul Sharma",    "frequent_traveler"),
        ("Priya Mehta",     "luxury_shopper"),
        ("James Wilson",    "business_traveler"),
        ("Anna Chen",       "first_time"),
    ]):
        b, _ = r.api("POST", "/api/v1/marketing/contacts", label=f"Create contact: {name} ({segment})", data={
            "email": f"ddf_{segment}_{ts}_{i}@test.in",
            "first_name": name.split()[0], "last_name": name.split()[-1],
            "tags": [segment, "duty_free", "delhi_airport"],
            "attributes": {"segment": segment, "preferred_category": "spirits" if "travel" in segment else "perfumes"},
        })
        bodies.append(b)

    contact_id = (bodies[0] or {}).get("id") or (bodies[0] or {}).get("data", {}).get("id") if bodies else None
    r.api("GET", "/api/v1/marketing/contacts", label="List contacts")

    if contact_id:
        r.api("GET", f"/api/v1/marketing/contacts/{contact_id}", label="Get contact by ID")

    print("\n  — Email Templates —")
    tpl_ids = []
    for tpl_name, subject, body_html in [
        ("Pre-departure Offer",
         "Your flight is soon — exclusive duty-free offers!",
         "<h1>Delhi Duty Free</h1><p>Your flight departs soon. Shop duty-free now and save up to 40%.</p>"),
        ("Abandoned Cart Recovery",
         "You left something behind at Delhi Duty Free",
         "<h1>Don't forget your items!</h1><p>Your cart is waiting. Complete your purchase before your flight.</p>"),
        ("Post-purchase Thank You",
         "Thank you for shopping at Delhi Duty Free!",
         "<h1>Thank You!</h1><p>Your order has been confirmed. Enjoy your purchase!</p>"),
        ("Loyalty VIP Offer",
         "Exclusive VIP offer for your next trip",
         "<h1>VIP Member Exclusive</h1><p>As a valued frequent traveler, enjoy 15% extra off this weekend.</p>"),
    ]:
        b, _ = r.api("POST", "/api/v1/marketing/templates", label=f"Template: {tpl_name}", data={
            "name": f"{tpl_name} {ts}",
            "channel": "email",
            "subject": subject,
            "body_html": body_html,
            "body_text": re.sub(r'<[^>]+>', '', body_html),
        })
        tpl_ids.append((b or {}).get("id") or (b or {}).get("data", {}).get("id"))

    r.api("GET", "/api/v1/marketing/templates", label="List templates")

    print("\n  — Marketing Channels —")
    r.api("POST", "/api/v1/marketing/channels", label="Register email channel", data={
        "name": "DDF SMTP Channel",
        "type": "email",
        "provider": "smtp",
        "credentials": {
            "host": "smtp.ddf.test",
            "port": 587,
            "username": "noreply@ddf.test",
            "password": "test_password",
            "from_email": "noreply@delhidutyfree.co.in",
            "from_name": "Delhi Duty Free",
        },
        "is_default": False,
    })

    print("\n  — Campaigns —")
    camp_ids = []
    for c_name, audience_type in [
        ("Pre-departure Flash Sale",  "all"),
        ("Abandoned Cart Recovery",   "list"),
        ("VIP Loyalty Offer",         "list"),
    ]:
        audience = {"type": audience_type}
        if audience_type == "list":
            audience["list_id"] = contact_id or 1
        b, _ = r.api("POST", "/api/v1/marketing/campaigns", label=f"Campaign: {c_name}", data={
            "name": f"{c_name} {ts}",
            "channel": "email",
            "type": "one_time",
            "template_id": tpl_ids[0] if tpl_ids else 1,
            "audience": audience,
        })
        camp_ids.append((b or {}).get("data", {}).get("id") or (b or {}).get("id"))

    r.api("GET", "/api/v1/marketing/campaigns", label="List campaigns")
    if camp_ids and camp_ids[0]:
        r.api("GET", f"/api/v1/marketing/campaigns/{camp_ids[0]}", label="Get campaign by ID")

    print("\n  — Automation Flows —")
    r.api("GET", "/api/v1/marketing/flows", label="List automation flows")

    # Create abandoned cart flow (trigger_type = "event", status = "draft")
    b, _ = r.api("POST", "/api/v1/marketing/flows", label="Create: Abandoned Cart Recovery flow", data={
        "name": f"DDF Abandoned Cart Flow {ts}",
        "trigger_type": "event",
        "trigger_config": {"event": "cart_abandoned"},
        "status": "draft",
    })
    flow_id = (b or {}).get("data", {}).get("id") or (b or {}).get("id")

    if flow_id:
        # Build the flow canvas with a trigger → delay → send email sequence
        r.api("PUT", f"/api/v1/marketing/flows/{flow_id}/canvas",
              label="Flow canvas: trigger → delay → send email", data={
                  "nodes": [
                      {"node_id": "start", "type": "trigger",
                       "config": {"event": "cart_abandoned"}},
                      {"node_id": "wait",  "type": "delay",
                       "config": {"duration": 3600}},
                      {"node_id": "send",  "type": "send_email",
                       "config": {"template_id": str(tpl_ids[1] if len(tpl_ids) > 1 else 1)}},
                  ],
                  "edges": [
                      {"source_node_id": "start", "target_node_id": "wait"},
                      {"source_node_id": "wait",  "target_node_id": "send"},
                  ],
              })

    # Pre-departure trigger flow
    b2, _ = r.api("POST", "/api/v1/marketing/flows", label="Create: Pre-departure Offer flow", data={
        "name": f"DDF Pre-departure Offer Flow {ts}",
        "trigger_type": "event",
        "trigger_config": {"event": "session_start"},
        "status": "draft",
    })


# ═════════════════════════════════════════════════════════════════════════
#  SECTION 8 — CUSTOMER SIMULATION (100 customers, full funnel)
# ═════════════════════════════════════════════════════════════════════════
def test_simulation(r: E2ERunner, n_customers=100):
    r.section(f"8. CUSTOMER SIMULATION — {n_customers} customers, Full Duty-Free Funnel")

    print(f"\n  Stores: Arrival (50%) + Departure (50%)")
    print(f"  Categories: Spirits, Perfumes, Tobacco, Confectionery, Cosmetics")
    print(f"  Segments: Gift buyers, business travelers, luxury shoppers, budget shoppers")
    print()

    passed = 0
    failed = 0
    rate_limited = 0

    for i in range(1, n_customers + 1):
        segment  = CUSTOMER_SEGMENTS[i % len(CUSTOMER_SEGMENTS)]
        store    = "departure" if i % 2 == 0 else "default"
        store_url = DEPARTURE_URL if store == "departure" else ARRIVAL_URL
        category  = random.choice(list(DDF_PRODUCTS.keys()))
        product   = random.choice(DDF_PRODUCTS[category])
        search_q  = random.choice(
            SEARCH_TERMS["brand"] + SEARCH_TERMS["category"] + SEARCH_TERMS["budget"]
        )
        email   = f"sim_{segment}_{int(time.time())}_{i:04d}@ddf.test"
        session = f"sim_{store[:3]}_{i:04d}_{int(time.time())}"
        visitor = f"visitor_{store[:3]}_{i:04d}"
        qty     = 2 if segment == "business_traveler" else 1

        events = [
            {"event_type": "page_view", "session_id": session, "visitor_id": visitor,
             "url": store_url, "title": "Delhi Duty Free", "store_id": store, "platform": "magento2"},
            {"event_type": "search", "session_id": session, "visitor_id": visitor,
             "url": store_url, "query": search_q, "results_count": random.randint(3, 20),
             "store_id": store, "platform": "magento2"},
            {"event_type": "product_view", "session_id": session, "visitor_id": visitor,
             "url": f"{store_url}{product['name'].lower().replace(' ', '-').replace('&', 'and')}.html",
             "product_id": product["id"], "product_name": product["name"],
             "price": product["price"], "brand": product["brand"],
             "category": category, "store_id": store, "platform": "magento2"},
            {"event_type": "add_to_cart", "session_id": session, "visitor_id": visitor,
             "url": f"{store_url}{product['name'].lower().replace(' ', '-').replace('&', 'and')}.html",
             "product_id": product["id"], "quantity": qty, "price": product["price"],
             "store_id": store, "platform": "magento2"},
            {"event_type": "purchase", "session_id": session, "visitor_id": visitor,
             "url": f"{store_url}checkout/onepage/success/",
             "order_id": f"SIM-{store[:3].upper()}-{i:04d}-{int(time.time())}",
             "total": round(product["price"] * qty, 2), "currency": "USD",
             "customer_identifier": {"type": "email", "value": email},
             "items": [{"product_id": product["id"], "sku": product["sku"],
                        "name": product["name"], "price": product["price"], "quantity": qty}],
             "store_id": store, "platform": "magento2"},
        ]

        try:
            time.sleep(random.uniform(0.8, 1.6))
            resp = requests.post(
                f"{BASE_URL}/api/v1/collect/batch",
                json={"events": events},
                headers=HEADERS_PUBLIC,
                timeout=25, verify=False,
            )
            if resp.status_code == 429:
                rate_limited += 1
                time.sleep(5)
                resp = requests.post(
                    f"{BASE_URL}/api/v1/collect/batch",
                    json={"events": events},
                    headers=HEADERS_PUBLIC,
                    timeout=25, verify=False,
                )
            if resp.status_code in (200, 201, 207):
                passed += 1
            else:
                failed += 1
        except Exception:
            failed += 1

        if i % 10 == 0:
            total_so_far = passed + failed
            pct = round(passed / total_so_far * 100, 1) if total_so_far else 0
            print(f"    ... {i}/{n_customers} | ✅ {passed} passed | ❌ {failed} failed | 429: {rate_limited} | {pct}%")

    total = passed + failed
    pct   = round(passed / total * 100, 1) if total else 0
    r.check(
        f"Simulation: {passed}/{total} customers ({pct}% pass, {rate_limited} rate-limits)",
        failed == 0,
        f"{failed} failed requests" if failed else "",
    )


# ═════════════════════════════════════════════════════════════════════════
#  MAIN
# ═════════════════════════════════════════════════════════════════════════
def main():
    print("=" * 70)
    print("  DELHI DUTY FREE (GMRAE) — Production-Ready E2E Test Suite")
    print("=" * 70)
    print(f"  Magento:   {ARRIVAL_URL}")
    print(f"  Ecom360:   {BASE_URL}")
    print(f"  API Key:   {API_KEY[:20]}...")
    print(f"  Time:      {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print("=" * 70)

    r = E2ERunner()

    test_frontend(r)
    test_datasync(r)
    test_analytics(r)
    test_search(r)
    test_chatbot(r)
    test_bi(r)
    test_marketing(r)
    test_simulation(r, n_customers=100)

    # ── Results ──
    print(f"\n{'=' * 70}")
    print(f"  FINAL RESULTS")
    print(f"{'=' * 70}")
    all_pass = True
    for name, sec in r.sections.items():
        total = sec["pass"] + sec["fail"]
        pct   = round(sec["pass"] / total * 100, 1) if total else 0
        ok    = "✅" if sec["fail"] == 0 else "⚠️ "
        if sec["fail"] > 0:
            all_pass = False
        print(f"  {ok} {name}: {sec['pass']}/{total} ({pct}%)")

    grand_total = r.total_pass + r.total_fail
    grand_pct   = round(r.total_pass / grand_total * 100, 1) if grand_total else 0
    print(f"\n  GRAND TOTAL: {r.total_pass}/{grand_total} passed ({grand_pct}%)")
    verdict = "✅ PRODUCTION READY" if r.total_fail == 0 else f"⚠️  {r.total_fail} test(s) failed — review before go-live"
    print(f"  VERDICT:     {verdict}")
    print("=" * 70)

    results = {
        "timestamp":    datetime.now().isoformat(),
        "store":        "testing.gmraerodutyfree.in",
        "api_url":      BASE_URL,
        "total_pass":   r.total_pass,
        "total_fail":   r.total_fail,
        "pass_rate":    grand_pct,
        "verdict":      verdict,
        "sections":     r.sections,
    }
    out_file = "tests/testing_store_e2e_results.json"
    with open(out_file, "w") as f:
        json.dump(results, f, indent=2)
    print(f"\n  Results saved → {out_file}")

    return 0 if r.total_fail == 0 else 1


from datetime import timedelta

if __name__ == "__main__":
    sys.exit(main())
