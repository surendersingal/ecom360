#!/usr/bin/env python3
"""
==============================================================================
  ECOM360 — Module-by-Module API Test Suite
==============================================================================

Tests all 4 non-search/chatbot modules endpoint-by-endpoint:
  1. DataSync      — Product/category/order/customer sync, permissions, webhook
  2. Analytics     — Tracking, CDP, advanced analytics, real-time, NLQ
  3. BusinessIntel — Reports, dashboards, KPIs, alerts, exports, intel
  4. Marketing     — Contacts, templates, campaigns, flows, channels

Auth: Bearer token (Sanctum) for dashboard endpoints,
      API key + secret for DataSync endpoints,
      API key only for public tracking endpoints.
"""

import json, random, time, sys, os
from datetime import datetime
from urllib.parse import quote

try:
    import requests
except ImportError:
    print("pip3 install requests"); sys.exit(1)

# ─────────────────────────── Config ──────────────────────────────────────
BASE_URL   = "https://ecom.buildnetic.com"
API_KEY    = "ek_Z3KoaSngu5edQ9exUKCDSYaMqAm7xomX"
SECRET_KEY = "sk_TBE3Jms2zQnDlT3RBO2cSd5sakoTgptL"
BEARER     = "31|b7BpVxuo3EbIjbppafdNsXfzttLku46ir8t0HMAme98dc255"

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

DELAY = 1.5  # seconds between requests


# ─────────────────────────── Test Runner ─────────────────────────────────
class ModuleTestRunner:
    def __init__(self):
        self.modules = {}
        self.current_module = None
        self.total_pass = 0
        self.total_fail = 0
        self.total_skip = 0

    def set_module(self, name):
        self.current_module = name
        if name not in self.modules:
            self.modules[name] = {"pass": 0, "fail": 0, "skip": 0, "tests": []}

    def _call(self, method, path, data=None, headers=None, label="", expect_status=None):
        """Make API call and record result."""
        if headers is None:
            headers = HEADERS_AUTH
        if expect_status is None:
            expect_status = [200, 201, 207]

        time.sleep(DELAY)
        mod = self.modules[self.current_module]

        try:
            if method == "GET":
                r = requests.get(f"{BASE_URL}{path}", headers=headers, timeout=20)
            elif method == "POST":
                r = requests.post(f"{BASE_URL}{path}", json=data, headers=headers, timeout=20)
            elif method == "PUT":
                r = requests.put(f"{BASE_URL}{path}", json=data, headers=headers, timeout=20)
            elif method == "PATCH":
                r = requests.patch(f"{BASE_URL}{path}", json=data, headers=headers, timeout=20)
            elif method == "DELETE":
                r = requests.delete(f"{BASE_URL}{path}", headers=headers, timeout=20)
            else:
                r = requests.request(method, f"{BASE_URL}{path}", json=data, headers=headers, timeout=20)

            # Handle 429 with retry
            if r.status_code == 429:
                time.sleep(5)
                if method == "GET":
                    r = requests.get(f"{BASE_URL}{path}", headers=headers, timeout=20)
                elif method == "POST":
                    r = requests.post(f"{BASE_URL}{path}", json=data, headers=headers, timeout=20)
                elif method == "PUT":
                    r = requests.put(f"{BASE_URL}{path}", json=data, headers=headers, timeout=20)
                elif method == "DELETE":
                    r = requests.delete(f"{BASE_URL}{path}", headers=headers, timeout=20)

            passed = r.status_code in expect_status
            body = {}
            try:
                body = r.json()
            except:
                pass

            result = {
                "label": label,
                "method": method,
                "path": path,
                "status": r.status_code,
                "passed": passed,
                "response_keys": list(body.keys()) if isinstance(body, dict) else "array",
                "message": body.get("message", "")[:200] if isinstance(body, dict) else "",
            }

            if passed:
                mod["pass"] += 1
                self.total_pass += 1
                print(f"    ✅ {label} [{r.status_code}]")
            else:
                mod["fail"] += 1
                self.total_fail += 1
                msg = body.get("message", r.text[:100]) if isinstance(body, dict) else r.text[:100]
                result["error"] = msg
                print(f"    ❌ {label} [{r.status_code}] {msg}")

            mod["tests"].append(result)
            return body if passed else None

        except Exception as e:
            mod["fail"] += 1
            self.total_fail += 1
            result = {"label": label, "method": method, "path": path, "passed": False, "error": str(e)}
            mod["tests"].append(result)
            print(f"    ❌ {label} [ERR] {str(e)[:80]}")
            return None

    def skip(self, label, reason=""):
        mod = self.modules[self.current_module]
        mod["skip"] += 1
        self.total_skip += 1
        mod["tests"].append({"label": label, "passed": "skip", "reason": reason})
        print(f"    ⏭️  {label} (skipped: {reason})")


# ==============================================================================
#  MODULE 1: DATASYNC
# ==============================================================================
def test_datasync(runner):
    runner.set_module("DataSync")
    print("\n" + "=" * 70)
    print("  MODULE 1: DataSync")
    print("=" * 70)

    # 1. Status
    runner._call("GET", "/api/v1/sync/status", headers=HEADERS_SYNC, label="Sync Status")

    # 2. Heartbeat
    runner._call("POST", "/api/v1/sync/heartbeat", {
        "platform": "magento2",
        "version": "1.0.0",
        "php_version": "8.2",
        "store_url": "https://www.delhidutyfree.co.in",
    }, headers=HEADERS_SYNC, label="Heartbeat")

    # 3. Permissions
    runner._call("POST", "/api/v1/sync/permissions", {
        "permissions": {
            "products": True,
            "categories": True,
            "inventory": True,
            "sales": True,
            "orders": True,
            "customers": True,
            "abandoned_carts": True,
            "popup_captures": True,
        },
    }, headers=HEADERS_SYNC, label="Update Permissions")

    # 4. Sync Products (small batch)
    runner._call("POST", "/api/v1/sync/products", {
        "products": [{
            "external_id": "TEST-PROD-001",
            "name": "Test Whisky Module Check",
            "sku": "TEST-SKU-001",
            "price": 4990,
            "special_price": 4490,
            "currency": "INR",
            "status": "active",
            "type": "simple",
            "categories": ["Liquor", "Whisky"],
            "images": ["https://example.com/test.jpg"],
            "url": "https://www.delhidutyfree.co.in/test-whisky",
            "stock_qty": 50,
            "in_stock": True,
            "brand": "Test Brand",
            "weight": 1.0,
            "description": "Test product for module validation",
        }],
    }, headers=HEADERS_SYNC, label="Sync Products (1 item)")

    # 5. Sync Categories
    runner._call("POST", "/api/v1/sync/categories", {
        "categories": [{
            "external_id": "CAT-TEST-001",
            "name": "Test Category",
            "parent_id": None,
            "level": 1,
            "position": 99,
            "is_active": True,
            "url_key": "test-category",
        }],
    }, headers=HEADERS_SYNC, label="Sync Categories (1 item)")

    # 6. Sync Inventory
    runner._call("POST", "/api/v1/sync/inventory", {
        "items": [{
            "sku": "TEST-SKU-001",
            "qty": 50,
            "is_in_stock": True,
        }],
    }, headers=HEADERS_SYNC, label="Sync Inventory (1 item)")

    # 7. Sync Orders
    runner._call("POST", "/api/v1/sync/orders", {
        "orders": [{
            "external_id": "TEST-ORD-001",
            "order_number": "DDF-TEST-001",
            "status": "processing",
            "total": 4990,
            "subtotal": 4490,
            "tax": 500,
            "shipping": 0,
            "discount": 0,
            "currency": "INR",
            "payment_method": "razorpay",
            "customer_email": "test@example.com",
            "customer_name": "Test Customer",
            "items": [{
                "sku": "TEST-SKU-001",
                "name": "Test Whisky",
                "qty": 1,
                "price": 4990,
            }],
            "created_at": "2026-04-05T10:00:00Z",
        }],
    }, headers=HEADERS_SYNC, label="Sync Orders (1 item)")

    # 8. Sync Customers
    runner._call("POST", "/api/v1/sync/customers", {
        "customers": [{
            "external_id": "CUST-TEST-001",
            "email": "moduletest@example.com",
            "first_name": "Module",
            "last_name": "Tester",
            "created_at": "2026-04-05T10:00:00Z",
            "orders_count": 1,
            "total_spent": 4990,
        }],
    }, headers=HEADERS_SYNC, label="Sync Customers (1 item)")

    # 9. Sync Abandoned Carts
    runner._call("POST", "/api/v1/sync/abandoned-carts", {
        "abandoned_carts": [{
            "external_id": "CART-TEST-001",
            "customer_email": "moduletest@example.com",
            "items": [{"sku": "TEST-SKU-001", "name": "Test Whisky", "qty": 1, "price": 4990}],
            "total": 4990,
            "currency": "INR",
            "created_at": "2026-04-05T10:00:00Z",
        }],
    }, headers=HEADERS_SYNC, label="Sync Abandoned Carts (1 item)")

    # 10. Sync Popup Captures
    runner._call("POST", "/api/v1/sync/popup-captures", {
        "captures": [{
            "email": "popup_test@example.com",
            "name": "Popup Tester",
            "source": "exit_intent",
            "page_url": "https://www.delhidutyfree.co.in/whisky",
            "captured_at": "2026-04-05T10:00:00Z",
        }],
    }, headers=HEADERS_SYNC, label="Sync Popup Captures (1 item)")

    # 11. Webhook
    runner._call("POST", "/api/v1/sync/webhook", {
        "event": "order.created",
        "data": {
            "order_id": "DDF-WEBHOOK-001",
            "total": 7500,
            "customer_email": "webhook@example.com",
        },
    }, headers=HEADERS_SYNC, label="Webhook (order.created)")


# ==============================================================================
#  MODULE 2: ANALYTICS
# ==============================================================================
def test_analytics(runner):
    runner.set_module("Analytics")
    print("\n" + "=" * 70)
    print("  MODULE 2: Analytics")
    print("=" * 70)

    # ── Public Tracking Endpoints ──
    print("\n  --- Public Tracking ---")

    # 1. Single event
    runner._call("POST", "/api/v1/collect", {
        "event_type": "page_view",
        "session_id": "module_test_sess_001",
        "url": "https://www.delhidutyfree.co.in/whisky",
        "page_title": "Whisky Collection",
        "device_fingerprint": "module_test_fp_001",
        "customer_identifier": {"type": "email", "value": "moduletest@example.com"},
    }, headers=HEADERS_PUBLIC, label="Collect single event")

    # 2. Batch events
    runner._call("POST", "/api/v1/collect/batch", {
        "events": [
            {"event_type": "page_view", "session_id": "module_test_sess_002", "url": "https://www.delhidutyfree.co.in/perfume", "device_fingerprint": "fp_002"},
            {"event_type": "product_view", "session_id": "module_test_sess_002", "url": "https://www.delhidutyfree.co.in/perfume/dior", "device_fingerprint": "fp_002",
             "metadata": {"product_id": "PF001", "product_name": "Dior Sauvage", "product_price": 8500, "product_category": "Perfume"}},
            {"event_type": "add_to_cart", "session_id": "module_test_sess_002", "url": "https://www.delhidutyfree.co.in/perfume/dior", "device_fingerprint": "fp_002",
             "metadata": {"product_id": "PF001", "product_name": "Dior Sauvage", "product_price": 8500, "quantity": 1}},
        ],
    }, headers=HEADERS_PUBLIC, label="Collect batch (3 events)")

    # 3. Intervention poll
    runner._call("GET", "/api/v1/interventions/poll?session_id=module_test_sess_002&fingerprint=fp_002",
                 headers=HEADERS_PUBLIC, label="Intervention poll")

    # ── Dashboard Endpoints (Sanctum Auth) ──
    print("\n  --- Dashboard Analytics ---")

    runner._call("GET", "/api/v1/analytics/overview", headers=HEADERS_AUTH, label="Overview KPIs")
    runner._call("GET", "/api/v1/analytics/traffic", headers=HEADERS_AUTH, label="Traffic stats")
    runner._call("GET", "/api/v1/analytics/realtime", headers=HEADERS_AUTH, label="Realtime metrics")
    runner._call("GET", "/api/v1/analytics/revenue", headers=HEADERS_AUTH, label="Revenue analytics")
    runner._call("GET", "/api/v1/analytics/products", headers=HEADERS_AUTH, label="Product analytics")
    runner._call("GET", "/api/v1/analytics/categories", headers=HEADERS_AUTH, label="Category analytics")
    runner._call("GET", "/api/v1/analytics/sessions", headers=HEADERS_AUTH, label="Session analytics")
    runner._call("GET", "/api/v1/analytics/page-visits", headers=HEADERS_AUTH, label="Page visits")
    runner._call("GET", "/api/v1/analytics/funnel", headers=HEADERS_AUTH, label="Conversion funnel")
    runner._call("GET", "/api/v1/analytics/customers", headers=HEADERS_AUTH, label="Customer analytics")
    runner._call("GET", "/api/v1/analytics/cohorts", headers=HEADERS_AUTH, label="Cohort retention")
    runner._call("GET", "/api/v1/analytics/geographic", headers=HEADERS_AUTH, label="Geographic analytics")
    runner._call("GET", "/api/v1/analytics/campaigns", headers=HEADERS_AUTH, label="Campaign analytics")

    # Matomo-parity
    print("\n  --- Matomo-Parity Endpoints ---")
    runner._call("GET", "/api/v1/analytics/all-pages", headers=HEADERS_AUTH, label="All pages")
    runner._call("GET", "/api/v1/analytics/search-analytics", headers=HEADERS_AUTH, label="Search analytics")
    runner._call("GET", "/api/v1/analytics/events-breakdown", headers=HEADERS_AUTH, label="Events breakdown")
    runner._call("GET", "/api/v1/analytics/visitor-frequency", headers=HEADERS_AUTH, label="Visitor frequency")
    runner._call("GET", "/api/v1/analytics/day-of-week", headers=HEADERS_AUTH, label="Day of week")
    runner._call("GET", "/api/v1/analytics/recent-events", headers=HEADERS_AUTH, label="Recent events")

    # Export
    runner._call("GET", "/api/v1/analytics/export?format=json&limit=5", headers=HEADERS_AUTH, label="Export (JSON, 5 rows)")

    # Custom events
    print("\n  --- Custom Events ---")
    runner._call("GET", "/api/v1/analytics/events/custom/definitions", headers=HEADERS_AUTH, label="Custom event definitions")
    # Create a custom event definition first
    ced = runner._call("POST", "/api/v1/analytics/events/custom/definitions", {
        "event_key": "module_test_custom",
        "display_name": "Module Test Custom Event",
        "description": "Test event for module validation",
        "properties": {"test_key": "string", "score": "number"},
    }, headers=HEADERS_AUTH, label="Create custom event definition")
    # Now track with the definition
    runner._call("POST", "/api/v1/analytics/events/custom", {
        "event_key": "module_test_custom",
        "session_id": "module_test_sess_001",
        "url": "https://www.delhidutyfree.co.in/test",
        "custom_data": {"test_key": "test_value", "score": 95},
    }, headers=HEADERS_AUTH, label="Track custom event")

    # ── Advanced Analytics ──
    print("\n  --- Advanced Analytics ---")
    runner._call("GET", "/api/v1/analytics/advanced/clv", headers=HEADERS_AUTH, label="CLV prediction")
    runner._call("POST", "/api/v1/analytics/advanced/clv/what-if", {
        "visitor_id": "module_test_sess_001",
        "scenario": {"retention_increase": 10, "aov_increase": 15},
    }, headers=HEADERS_AUTH, label="CLV what-if")
    runner._call("GET", "/api/v1/analytics/advanced/revenue-waterfall", headers=HEADERS_AUTH, label="Revenue waterfall")
    runner._call("POST", "/api/v1/analytics/advanced/why", {
        "metric": "revenue",
        "start_date": "2026-03-01",
        "end_date": "2026-04-05",
    }, headers=HEADERS_AUTH, label="Why explain")
    runner._call("POST", "/api/v1/analytics/advanced/triggers/evaluate", {
        "session_id": "module_test_sess_002",
        "triggers": ["cart_abandonment", "high_intent"],
    }, headers=HEADERS_AUTH, label="Behavioral triggers")
    runner._call("GET", "/api/v1/analytics/advanced/journey", headers=HEADERS_AUTH, label="Customer journey")
    runner._call("GET", "/api/v1/analytics/advanced/journey/drop-offs", headers=HEADERS_AUTH, label="Journey drop-offs")
    runner._call("GET", "/api/v1/analytics/advanced/recommendations", headers=HEADERS_AUTH, label="Smart recommendations")

    # Audience segments
    print("\n  --- Audience Segments ---")
    runner._call("GET", "/api/v1/analytics/advanced/audience/segments", headers=HEADERS_AUTH, label="Audience segments")
    runner._call("GET", "/api/v1/analytics/advanced/audience/destinations", headers=HEADERS_AUTH, label="Audience destinations")

    # Real-time pulse
    runner._call("GET", "/api/v1/analytics/advanced/pulse", headers=HEADERS_AUTH, label="Realtime pulse")
    runner._call("GET", "/api/v1/analytics/advanced/alerts", headers=HEADERS_AUTH, label="Realtime alerts")
    runner._call("GET", "/api/v1/analytics/advanced/alerts/rules", headers=HEADERS_AUTH, label="Alert rules list")

    # NLQ (Natural Language Query)
    print("\n  --- Natural Language Query ---")
    runner._call("GET", "/api/v1/analytics/advanced/ask/suggest", headers=HEADERS_AUTH, label="NLQ suggestions")
    runner._call("POST", "/api/v1/analytics/advanced/ask", {
        "q": "What was the total revenue last week?",
    }, headers=HEADERS_AUTH, label="NLQ query")

    # Competitive benchmarks
    runner._call("GET", "/api/v1/analytics/advanced/benchmarks", headers=HEADERS_AUTH, label="Competitive benchmarks")

    # ── CDP ──
    print("\n  --- CDP (Customer Data Platform) ---")
    runner._call("GET", "/api/v1/cdp/dashboard", headers=HEADERS_AUTH, label="CDP dashboard")
    runner._call("GET", "/api/v1/cdp/profiles?limit=5", headers=HEADERS_AUTH, label="CDP profiles (5)")
    runner._call("POST", "/api/v1/cdp/profiles/build", {}, headers=HEADERS_AUTH, label="Build profiles")
    runner._call("GET", "/api/v1/cdp/segments", headers=HEADERS_AUTH, label="CDP segments")
    runner._call("GET", "/api/v1/cdp/rfm", headers=HEADERS_AUTH, label="RFM analysis")
    runner._call("GET", "/api/v1/cdp/predictions", headers=HEADERS_AUTH, label="CDP predictions")
    runner._call("GET", "/api/v1/cdp/data-health", headers=HEADERS_AUTH, label="Data health")
    runner._call("GET", "/api/v1/cdp/dimensions", headers=HEADERS_AUTH, label="Dimensions")


# ==============================================================================
#  MODULE 3: BUSINESS INTELLIGENCE
# ==============================================================================
def test_bi(runner):
    runner.set_module("BusinessIntelligence")
    print("\n" + "=" * 70)
    print("  MODULE 3: Business Intelligence")
    print("=" * 70)

    # ── Reports ──
    print("\n  --- Reports ---")
    runner._call("GET", "/api/v1/bi/reports", headers=HEADERS_AUTH, label="List reports")
    runner._call("GET", "/api/v1/bi/reports/meta/templates", headers=HEADERS_AUTH, label="Report templates")

    # Create a report
    report = runner._call("POST", "/api/v1/bi/reports", {
        "name": "Module Test Report",
        "description": "Automated module test report",
        "type": "revenue",
        "config": {"period": "last_30_days", "group_by": "day"},
    }, headers=HEADERS_AUTH, label="Create report")
    report_id = report.get("data", {}).get("id") if report else None

    if report_id:
        runner._call("GET", f"/api/v1/bi/reports/{report_id}", headers=HEADERS_AUTH, label=f"Get report {report_id}")
        runner._call("POST", f"/api/v1/bi/reports/{report_id}/execute", headers=HEADERS_AUTH, label=f"Execute report {report_id}")
        runner._call("DELETE", f"/api/v1/bi/reports/{report_id}", headers=HEADERS_AUTH, label=f"Delete report {report_id}")
    else:
        runner.skip("Get/Execute/Delete report", "report creation failed")

    # ── Dashboards ──
    print("\n  --- Dashboards ---")
    runner._call("GET", "/api/v1/bi/dashboards", headers=HEADERS_AUTH, label="List dashboards")

    dash = runner._call("POST", "/api/v1/bi/dashboards", {
        "name": "Module Test Dashboard",
        "description": "Test dashboard",
        "layout": [{"id": "w1", "x": 0, "y": 0, "w": 6, "h": 4}],
        "widgets": [],
    }, headers=HEADERS_AUTH, label="Create dashboard")
    dash_id = dash.get("data", {}).get("id") if dash else None

    if dash_id:
        runner._call("GET", f"/api/v1/bi/dashboards/{dash_id}", headers=HEADERS_AUTH, label=f"Get dashboard {dash_id}")
        runner._call("POST", f"/api/v1/bi/dashboards/{dash_id}/duplicate", headers=HEADERS_AUTH, label=f"Duplicate dashboard")
        runner._call("DELETE", f"/api/v1/bi/dashboards/{dash_id}", headers=HEADERS_AUTH, label=f"Delete dashboard {dash_id}")
    else:
        runner.skip("Get/Dup/Delete dashboard", "creation failed")

    # ── KPIs ──
    print("\n  --- KPIs ---")
    runner._call("GET", "/api/v1/bi/kpis", headers=HEADERS_AUTH, label="List KPIs")
    runner._call("POST", "/api/v1/bi/kpis/defaults", {}, headers=HEADERS_AUTH, label="Default KPIs")
    runner._call("POST", "/api/v1/bi/kpis/refresh", {}, headers=HEADERS_AUTH, label="Refresh KPIs")

    kpi = runner._call("POST", "/api/v1/bi/kpis", {
        "name": "Module Test KPI",
        "metric": "total_revenue",
        "target": 100000,
        "period": "monthly",
    }, headers=HEADERS_AUTH, label="Create KPI")
    kpi_id = kpi.get("data", {}).get("id") if kpi else None

    if kpi_id:
        runner._call("GET", f"/api/v1/bi/kpis/{kpi_id}", headers=HEADERS_AUTH, label=f"Get KPI {kpi_id}")
        runner._call("DELETE", f"/api/v1/bi/kpis/{kpi_id}", headers=HEADERS_AUTH, label=f"Delete KPI {kpi_id}")
    else:
        runner.skip("Get/Delete KPI", "creation failed")

    # ── Alerts ──
    print("\n  --- Alerts ---")
    runner._call("GET", "/api/v1/bi/alerts", headers=HEADERS_AUTH, label="List alerts")

    alert = runner._call("POST", "/api/v1/bi/alerts", {
        "name": "Module Test Alert",
        "metric": "revenue",
        "condition": "below",
        "threshold": 1000,
        "period": "daily",
        "channels": ["email"],
    }, headers=HEADERS_AUTH, label="Create alert")
    alert_id = alert.get("data", {}).get("id") if alert else None

    if alert_id:
        runner._call("GET", f"/api/v1/bi/alerts/{alert_id}", headers=HEADERS_AUTH, label=f"Get alert {alert_id}")
        runner._call("GET", f"/api/v1/bi/alerts/{alert_id}/history", headers=HEADERS_AUTH, label=f"Alert history")
        runner._call("DELETE", f"/api/v1/bi/alerts/{alert_id}", headers=HEADERS_AUTH, label=f"Delete alert {alert_id}")
    else:
        runner.skip("Get/History/Delete alert", "creation failed")

    runner._call("POST", "/api/v1/bi/alerts/evaluate", {}, headers=HEADERS_AUTH, label="Evaluate alerts")

    # ── Exports ──
    print("\n  --- Exports ---")
    runner._call("GET", "/api/v1/bi/exports", headers=HEADERS_AUTH, label="List exports")

    # Create a report first to get report_id for export
    tmp_report = runner._call("POST", "/api/v1/bi/reports", {
        "name": "Export Test Report",
        "description": "Temp report for export test",
        "type": "revenue",
        "config": {"period": "last_30_days"},
    }, headers=HEADERS_AUTH, label="Create temp report for export")
    tmp_report_id = tmp_report.get("data", {}).get("id") if tmp_report else 1

    export = runner._call("POST", "/api/v1/bi/exports", {
        "report_id": tmp_report_id,
        "format": "csv",
    }, headers=HEADERS_AUTH, label="Create export")
    export_id = export.get("data", {}).get("id") if export else None

    if export_id:
        runner._call("GET", f"/api/v1/bi/exports/{export_id}", headers=HEADERS_AUTH, label=f"Get export {export_id}")
    else:
        runner.skip("Get export", "creation failed")

    # ── Insights ──
    print("\n  --- Insights ---")
    runner._call("GET", "/api/v1/bi/insights/predictions", headers=HEADERS_AUTH, label="Predictions")
    runner._call("POST", "/api/v1/bi/insights/predictions/generate", {
        "model_type": "revenue_forecast",
    }, headers=HEADERS_AUTH, label="Generate predictions")
    runner._call("GET", "/api/v1/bi/insights/benchmarks", headers=HEADERS_AUTH, label="Benchmarks")
    runner._call("POST", "/api/v1/bi/insights/query", {
        "data_source": "orders",
        "filters": {},
        "group_by": "status",
        "aggregations": [{"field": "total", "function": "sum"}],
    }, headers=HEADERS_AUTH, label="Ad-hoc query")
    runner._call("GET", "/api/v1/bi/insights/fields/orders", headers=HEADERS_AUTH, label="Available fields (orders)")

    # ── Intelligence Endpoints ──
    print("\n  --- Intelligence (Revenue) ---")
    runner._call("GET", "/api/v1/bi/intel/revenue/command-center", headers=HEADERS_AUTH, label="Revenue command center")
    runner._call("GET", "/api/v1/bi/intel/revenue/by-hour", headers=HEADERS_AUTH, label="Revenue by hour")
    runner._call("GET", "/api/v1/bi/intel/revenue/by-day", headers=HEADERS_AUTH, label="Revenue by day")
    runner._call("GET", "/api/v1/bi/intel/revenue/trend", headers=HEADERS_AUTH, label="Revenue trend")
    runner._call("GET", "/api/v1/bi/intel/revenue/breakdown", headers=HEADERS_AUTH, label="Revenue breakdown")
    runner._call("GET", "/api/v1/bi/intel/revenue/margin", headers=HEADERS_AUTH, label="Revenue margin")
    runner._call("GET", "/api/v1/bi/intel/revenue/top-performers", headers=HEADERS_AUTH, label="Top performers")

    print("\n  --- Intelligence (Products) ---")
    runner._call("GET", "/api/v1/bi/intel/products/leaderboard", headers=HEADERS_AUTH, label="Product leaderboard")
    runner._call("GET", "/api/v1/bi/intel/products/stars", headers=HEADERS_AUTH, label="Product stars")
    runner._call("GET", "/api/v1/bi/intel/products/category-matrix", headers=HEADERS_AUTH, label="Category matrix")
    runner._call("GET", "/api/v1/bi/intel/products/pareto", headers=HEADERS_AUTH, label="Pareto analysis")

    print("\n  --- Intelligence (Customers) ---")
    runner._call("GET", "/api/v1/bi/intel/customers/overview", headers=HEADERS_AUTH, label="Customer overview")
    runner._call("GET", "/api/v1/bi/intel/customers/acquisition", headers=HEADERS_AUTH, label="Customer acquisition")
    runner._call("GET", "/api/v1/bi/intel/customers/geo", headers=HEADERS_AUTH, label="Customer geo")
    runner._call("GET", "/api/v1/bi/intel/customers/cohort", headers=HEADERS_AUTH, label="Cohort retention")
    runner._call("GET", "/api/v1/bi/intel/customers/value-dist", headers=HEADERS_AUTH, label="Value distribution")
    runner._call("GET", "/api/v1/bi/intel/customers/new-vs-returning", headers=HEADERS_AUTH, label="New vs returning")

    print("\n  --- Intelligence (Operations) ---")
    runner._call("GET", "/api/v1/bi/intel/operations/pipeline", headers=HEADERS_AUTH, label="Order pipeline")
    runner._call("GET", "/api/v1/bi/intel/operations/daily-volume", headers=HEADERS_AUTH, label="Daily order volume")
    runner._call("GET", "/api/v1/bi/intel/operations/heatmap", headers=HEADERS_AUTH, label="Order heatmap")
    runner._call("GET", "/api/v1/bi/intel/operations/coupons", headers=HEADERS_AUTH, label="Coupon intelligence")
    runner._call("GET", "/api/v1/bi/intel/operations/payments", headers=HEADERS_AUTH, label="Payment analysis")

    print("\n  --- Intelligence (Cross-Module) ---")
    runner._call("GET", "/api/v1/bi/intel/cross/marketing-attribution", headers=HEADERS_AUTH, label="Marketing attribution")
    runner._call("GET", "/api/v1/bi/intel/cross/search-revenue", headers=HEADERS_AUTH, label="Search revenue impact")
    runner._call("GET", "/api/v1/bi/intel/cross/chatbot-impact", headers=HEADERS_AUTH, label="Chatbot impact")
    runner._call("GET", "/api/v1/bi/intel/cross/customer-360?email=moduletest@example.com", headers=HEADERS_AUTH, label="Customer 360")


# ==============================================================================
#  MODULE 4: MARKETING
# ==============================================================================
def test_marketing(runner):
    runner.set_module("Marketing")
    print("\n" + "=" * 70)
    print("  MODULE 4: Marketing")
    print("=" * 70)

    # ── Channels ──
    print("\n  --- Channels ---")
    runner._call("GET", "/api/v1/marketing/channels", headers=HEADERS_AUTH, label="List channels")
    runner._call("GET", "/api/v1/marketing/channels/providers/email", headers=HEADERS_AUTH, label="Email providers")
    runner._call("GET", "/api/v1/marketing/channels/providers/sms", headers=HEADERS_AUTH, label="SMS providers")

    channel = runner._call("POST", "/api/v1/marketing/channels", {
        "name": "Module Test Email",
        "type": "email",
        "provider": "smtp",
        "credentials": {
            "host": "smtp.test.com",
            "port": 587,
            "username": "test@test.com",
            "password": "test",
            "from_email": "noreply@test.com",
            "from_name": "DDF Test",
        },
        "is_default": False,
    }, headers=HEADERS_AUTH, label="Create email channel")
    channel_id = channel.get("data", {}).get("id") if channel else None

    if channel_id:
        runner._call("GET", f"/api/v1/marketing/channels/{channel_id}", headers=HEADERS_AUTH, label=f"Get channel {channel_id}")
        runner._call("DELETE", f"/api/v1/marketing/channels/{channel_id}", headers=HEADERS_AUTH, label=f"Delete channel")
    else:
        runner.skip("Get/Delete channel", "creation failed")

    # ── Contacts ──
    print("\n  --- Contacts ---")
    runner._call("GET", "/api/v1/marketing/contacts", headers=HEADERS_AUTH, label="List contacts")

    contact = runner._call("POST", "/api/v1/marketing/contacts", {
        "email": "module_test_contact@example.com",
        "first_name": "Module",
        "last_name": "Test",
        "phone": "+919876543210",
        "tags": ["test", "module_validation"],
    }, headers=HEADERS_AUTH, label="Create contact")
    contact_id = contact.get("data", {}).get("id") if contact else None

    if contact_id:
        runner._call("GET", f"/api/v1/marketing/contacts/{contact_id}", headers=HEADERS_AUTH, label=f"Get contact {contact_id}")
        runner._call("PUT", f"/api/v1/marketing/contacts/{contact_id}", {
            "first_name": "Updated",
            "tags": ["test", "updated"],
        }, headers=HEADERS_AUTH, label="Update contact")
        runner._call("POST", f"/api/v1/marketing/contacts/{contact_id}/unsubscribe", {
            "channel": "email",
        }, headers=HEADERS_AUTH, label="Unsubscribe contact")
    else:
        runner.skip("Get/Update/Unsub contact", "creation failed")

    # Bulk import
    runner._call("POST", "/api/v1/marketing/contacts/bulk-import", {
        "contacts": [
            {"email": f"bulk_test_{i}@example.com", "first_name": f"Bulk{i}", "last_name": "Test"}
            for i in range(1, 4)
        ],
    }, headers=HEADERS_AUTH, label="Bulk import (3 contacts)")

    # ── Lists ──
    print("\n  --- Contact Lists ---")
    runner._call("GET", "/api/v1/marketing/lists", headers=HEADERS_AUTH, label="List all lists")

    clist = runner._call("POST", "/api/v1/marketing/lists", {
        "name": "Module Test List",
        "description": "Test list for module validation",
    }, headers=HEADERS_AUTH, label="Create list")
    list_id = clist.get("data", {}).get("id") if clist else None

    if list_id and contact_id:
        runner._call("POST", f"/api/v1/marketing/lists/{list_id}/members", {
            "contact_ids": [contact_id],
        }, headers=HEADERS_AUTH, label="Add contact to list")
    else:
        runner.skip("Add to list", "list or contact creation failed")

    # ── Templates ──
    print("\n  --- Templates ---")
    runner._call("GET", "/api/v1/marketing/templates", headers=HEADERS_AUTH, label="List templates")

    template = runner._call("POST", "/api/v1/marketing/templates", {
        "name": "Module Test Template",
        "channel": "email",
        "subject": "Test Subject {{first_name}}",
        "body_html": "<h1>Hello {{first_name}}</h1><p>This is a test email.</p>",
        "body_text": "Hello {{first_name}}, this is a test email.",
    }, headers=HEADERS_AUTH, label="Create email template")
    template_id = template.get("data", {}).get("id") if template else None

    if template_id:
        runner._call("GET", f"/api/v1/marketing/templates/{template_id}", headers=HEADERS_AUTH, label=f"Get template {template_id}")
        runner._call("GET", f"/api/v1/marketing/templates/{template_id}/preview", headers=HEADERS_AUTH, label="Preview template")
        runner._call("POST", f"/api/v1/marketing/templates/{template_id}/duplicate", headers=HEADERS_AUTH, label="Duplicate template")
    else:
        runner.skip("Get/Preview/Dup template", "creation failed")

    # ── Campaigns ──
    print("\n  --- Campaigns ---")
    runner._call("GET", "/api/v1/marketing/campaigns", headers=HEADERS_AUTH, label="List campaigns")

    campaign = runner._call("POST", "/api/v1/marketing/campaigns", {
        "name": "Module Test Campaign",
        "channel": "email",
        "type": "one_time",
        "template_id": template_id,
        "audience": {"type": "list", "list_id": list_id},
    }, headers=HEADERS_AUTH, label="Create campaign")
    campaign_id = campaign.get("data", {}).get("id") if campaign else None

    if campaign_id:
        runner._call("GET", f"/api/v1/marketing/campaigns/{campaign_id}", headers=HEADERS_AUTH, label=f"Get campaign {campaign_id}")
        runner._call("GET", f"/api/v1/marketing/campaigns/{campaign_id}/stats", headers=HEADERS_AUTH, label="Campaign stats")
        runner._call("POST", f"/api/v1/marketing/campaigns/{campaign_id}/duplicate", headers=HEADERS_AUTH, label="Duplicate campaign")
    else:
        runner.skip("Get/Stats/Dup campaign", "creation failed")

    # ── Flows ──
    print("\n  --- Flows (Automation) ---")
    runner._call("GET", "/api/v1/marketing/flows", headers=HEADERS_AUTH, label="List flows")

    flow = runner._call("POST", "/api/v1/marketing/flows", {
        "name": "Module Test Flow",
        "trigger_type": "event",
        "trigger_config": {"event": "order_completed"},
        "status": "draft",
    }, headers=HEADERS_AUTH, label="Create flow")
    flow_id = flow.get("data", {}).get("id") if flow else None

    if flow_id:
        runner._call("GET", f"/api/v1/marketing/flows/{flow_id}", headers=HEADERS_AUTH, label=f"Get flow {flow_id}")
        runner._call("PUT", f"/api/v1/marketing/flows/{flow_id}/canvas", {
            "nodes": [
                {"node_id": "start", "type": "trigger", "config": {"event": "order_completed"}},
                {"node_id": "wait", "type": "delay", "config": {"duration": 3600}},
                {"node_id": "send", "type": "send_email", "config": {"template_id": str(template_id or "1")}},
            ],
            "edges": [
                {"source_node_id": "start", "target_node_id": "wait"},
                {"source_node_id": "wait", "target_node_id": "send"},
            ],
        }, headers=HEADERS_AUTH, label="Save flow canvas")
        runner._call("GET", f"/api/v1/marketing/flows/{flow_id}/stats", headers=HEADERS_AUTH, label="Flow stats")
    else:
        runner.skip("Get/Canvas/Stats flow", "creation failed")

    # Clean up contact if created
    if contact_id:
        runner._call("DELETE", f"/api/v1/marketing/contacts/{contact_id}", headers=HEADERS_AUTH, label="Cleanup: delete contact")


# ==============================================================================
#  MAIN
# ==============================================================================
def main():
    start = time.time()
    print("=" * 70)
    print("  ECOM360 — Module-by-Module API Test Suite")
    print("=" * 70)
    print(f"  Target:  {BASE_URL}")
    print(f"  Time:    {datetime.now().isoformat()}")
    print(f"  Pacing:  {DELAY}s between requests")
    print(f"  Modules: DataSync, Analytics, BusinessIntelligence, Marketing")
    print("=" * 70)

    runner = ModuleTestRunner()

    # Run all 4 modules
    test_datasync(runner)
    test_analytics(runner)
    test_bi(runner)
    test_marketing(runner)

    # ══════════════════════════════════════════════════════════════════════
    #  SUMMARY
    # ══════════════════════════════════════════════════════════════════════
    elapsed = time.time() - start
    total = runner.total_pass + runner.total_fail

    print("\n" + "=" * 70)
    print("  FINAL RESULTS")
    print("=" * 70)
    print(f"  Duration: {elapsed:.0f}s | Total: {total} tests | Pass: {runner.total_pass} | Fail: {runner.total_fail} | Skip: {runner.total_skip}")
    print(f"  Overall: {runner.total_pass/total*100:.1f}% pass rate" if total else "  No tests ran")
    print()

    print(f"  {'Module':<25} {'Pass':>6} {'Fail':>6} {'Skip':>6} {'Rate':>8}")
    print("  " + "-" * 55)
    for name, mod in runner.modules.items():
        t = mod["pass"] + mod["fail"]
        rate = f"{mod['pass']/t*100:.1f}%" if t else "—"
        status = "✅" if mod["fail"] == 0 else ("⚠️" if mod["pass"] > mod["fail"] else "❌")
        print(f"  {name:<25} {mod['pass']:>6} {mod['fail']:>6} {mod['skip']:>6} {rate:>7} {status}")
    print("  " + "-" * 55)
    print()

    # Show failures
    for name, mod in runner.modules.items():
        fails = [t for t in mod["tests"] if t.get("passed") == False]
        if fails:
            print(f"  ── {name} FAILURES ──")
            for f in fails:
                err = f.get("error", f.get("message", "unknown"))[:100]
                print(f"    • {f['label']}: [{f.get('status', 'ERR')}] {err}")
            print()

    # Save results
    output = {
        "simulation_time": datetime.now().isoformat(),
        "target": BASE_URL,
        "duration_seconds": round(elapsed),
        "total_pass": runner.total_pass,
        "total_fail": runner.total_fail,
        "total_skip": runner.total_skip,
        "pass_rate": f"{runner.total_pass/total*100:.1f}%" if total else "0%",
        "modules": {
            name: {
                "pass": mod["pass"],
                "fail": mod["fail"],
                "skip": mod["skip"],
                "tests": mod["tests"],
            }
            for name, mod in runner.modules.items()
        },
    }

    results_file = os.path.join(os.path.dirname(__file__), "ddf_module_test_results.json")
    with open(results_file, "w") as f:
        json.dump(output, f, indent=2, default=str)
    print(f"  Results saved: {results_file}")
    print("=" * 70)


if __name__ == "__main__":
    main()
