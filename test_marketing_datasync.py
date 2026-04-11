#!/usr/bin/env python3
"""
Comprehensive test suite for Marketing and DataSync modules.
Bypasses Nginx proxy CDN by monkey-patching socket.getaddrinfo.
"""

import socket
import json
import time
import sys
from datetime import datetime

# ─── CDN Bypass ──────────────────────────────────────────────────────────────
_original_getaddrinfo = socket.getaddrinfo

def _patched_getaddrinfo(host, port, *args, **kwargs):
    if host == 'ecom.buildnetic.com':
        host = '13.204.186.178'
    return _original_getaddrinfo(host, port, *args, **kwargs)

socket.getaddrinfo = _patched_getaddrinfo

import requests

# ─── Config ──────────────────────────────────────────────────────────────────
BASE_URL       = "https://ecom.buildnetic.com/api/v1"
BEARER_TOKEN   = "51|NOcBfo6Zzm1YFQQcwcrKlNSzXUY0znmWloe48WISf0b0ba50"
API_KEY        = "ek_Z3KoaSngu5edQ9exUKCDSYaMqAm7xomX"
SECRET_KEY     = "sk_TBE3Jms2zQnDlT3RBO2cSd5sakoTgptL"

MARKETING_HEADERS = {
    "Authorization": f"Bearer {BEARER_TOKEN}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

SYNC_HEADERS = {
    "X-Ecom360-Key": API_KEY,
    "X-Ecom360-Secret": SECRET_KEY,
    "Content-Type": "application/json",
    "Accept": "application/json",
}

# ─── Test State ───────────────────────────────────────────────────────────────
results = []
created_ids = {}


# ─── Helpers ─────────────────────────────────────────────────────────────────
def test(name, method, url, headers, **kwargs):
    """Run one HTTP test and record pass/fail."""
    full_url = f"{BASE_URL}{url}"
    try:
        resp = requests.request(method, full_url, headers=headers,
                                timeout=30, verify=False, **kwargs)
        ok = resp.status_code in (200, 201, 204)
        try:
            body = resp.json()
        except Exception:
            body = resp.text[:300]

        status = "PASS" if ok else "FAIL"
        print(f"[{status}] {name} → HTTP {resp.status_code}")
        if not ok:
            print(f"       Body: {json.dumps(body)[:400]}")
        results.append((name, ok, resp.status_code, body))
        return resp, body
    except Exception as e:
        print(f"[FAIL] {name} → Exception: {e}")
        results.append((name, False, 0, str(e)))
        return None, None


def get_data(body):
    """Extract data from standard API response."""
    if isinstance(body, dict):
        return body.get('data', body)
    return body


# Suppress SSL warnings
import urllib3
urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

print("=" * 65)
print("MARKETING MODULE TESTS")
print("=" * 65)

# ─────────────────────────────────────────────────────────────────────────────
# MARKETING: Channels
# ─────────────────────────────────────────────────────────────────────────────
print("\n--- Channels ---")
resp, body = test("GET /marketing/channels", "GET", "/marketing/channels", MARKETING_HEADERS)

resp, body = test("POST /marketing/channels", "POST", "/marketing/channels", MARKETING_HEADERS,
    json={
        "name": "Test Email Channel",
        "type": "email",
        "provider": "smtp",
        "credentials": {"host": "smtp.example.com", "port": 587, "user": "test@test.com", "pass": "secret"},
        "settings": {"from_name": "Test", "from_email": "noreply@test.com"},
    }
)
if resp and resp.status_code in (200, 201):
    data = get_data(body)
    if isinstance(data, dict):
        created_ids['channel_id'] = data.get('id')
        print(f"       Created channel id={created_ids['channel_id']}")

# ─────────────────────────────────────────────────────────────────────────────
# MARKETING: Templates
# ─────────────────────────────────────────────────────────────────────────────
print("\n--- Templates ---")
resp, body = test("GET /marketing/templates", "GET", "/marketing/templates", MARKETING_HEADERS)

resp, body = test("POST /marketing/templates", "POST", "/marketing/templates", MARKETING_HEADERS,
    json={
        "name": "Welcome Email",
        "channel": "email",
        "subject": "Welcome {{first_name}}!",
        "body_html": "<p>Hello {{first_name}} {{last_name}}, welcome aboard!</p>",
        "body_text": "Hello {{first_name}}, welcome!",
    }
)
if resp and resp.status_code in (200, 201):
    data = get_data(body)
    if isinstance(data, dict):
        created_ids['template_id'] = data.get('id')
        print(f"       Created template id={created_ids['template_id']}")

# ─────────────────────────────────────────────────────────────────────────────
# MARKETING: Contacts
# ─────────────────────────────────────────────────────────────────────────────
print("\n--- Contacts ---")
resp, body = test("GET /marketing/contacts", "GET", "/marketing/contacts", MARKETING_HEADERS)

resp, body = test("POST /marketing/contacts", "POST", "/marketing/contacts", MARKETING_HEADERS,
    json={
        "email": f"testcontact_{int(time.time())}@example.com",
        "first_name": "Test",
        "last_name": "User",
        "phone": "+919999999999",
        "tags": ["test", "api"],
        "custom_fields": {"source": "api_test"},
    }
)
if resp and resp.status_code in (200, 201):
    data = get_data(body)
    if isinstance(data, dict):
        created_ids['contact_id'] = data.get('id')
        print(f"       Created contact id={created_ids['contact_id']}")

# ─────────────────────────────────────────────────────────────────────────────
# MARKETING: Lists
# ─────────────────────────────────────────────────────────────────────────────
print("\n--- Lists ---")
resp, body = test("GET /marketing/lists", "GET", "/marketing/lists", MARKETING_HEADERS)

resp, body = test("POST /marketing/lists", "POST", "/marketing/lists", MARKETING_HEADERS,
    json={
        "name": f"Test List {int(time.time())}",
        "description": "Created by API test",
        "source": "manual",
        "is_active": True,
    }
)
if resp and resp.status_code in (200, 201):
    data = get_data(body)
    if isinstance(data, dict):
        created_ids['list_id'] = data.get('id')
        print(f"       Created list id={created_ids['list_id']}")

# Add contact to list if both were created
if created_ids.get('list_id') and created_ids.get('contact_id'):
    test(f"POST /marketing/lists/{created_ids['list_id']}/members",
         "POST", f"/marketing/lists/{created_ids['list_id']}/members", MARKETING_HEADERS,
         json={"contact_ids": [created_ids['contact_id']]})

# ─────────────────────────────────────────────────────────────────────────────
# MARKETING: Campaigns
# ─────────────────────────────────────────────────────────────────────────────
print("\n--- Campaigns ---")
resp, body = test("GET /marketing/campaigns", "GET", "/marketing/campaigns", MARKETING_HEADERS)

campaign_payload = {
    "name": f"Test Campaign {int(time.time())}",
    "channel": "email",
    "type": "one_time",
    "audience": {"type": "all"},
    "schedule": None,
}
if created_ids.get('template_id'):
    campaign_payload['template_id'] = created_ids['template_id']

resp, body = test("POST /marketing/campaigns", "POST", "/marketing/campaigns", MARKETING_HEADERS,
    json=campaign_payload
)
if resp and resp.status_code in (200, 201):
    data = get_data(body)
    if isinstance(data, dict):
        created_ids['campaign_id'] = data.get('id')
        print(f"       Created campaign id={created_ids['campaign_id']}")

# ─────────────────────────────────────────────────────────────────────────────
# MARKETING: Flows
# ─────────────────────────────────────────────────────────────────────────────
print("\n--- Flows ---")
resp, body = test("GET /marketing/flows", "GET", "/marketing/flows", MARKETING_HEADERS)

resp, body = test("POST /marketing/flows", "POST", "/marketing/flows", MARKETING_HEADERS,
    json={
        "name": f"Test Flow {int(time.time())}",
        "trigger_type": "event",
        "trigger_config": {"event": "purchase"},
        "description": "API test flow",
    }
)
if resp and resp.status_code in (200, 201):
    data = get_data(body)
    if isinstance(data, dict):
        created_ids['flow_id'] = data.get('id')
        print(f"       Created flow id={created_ids['flow_id']}")

print("\n" + "=" * 65)
print("DATASYNC MODULE TESTS")
print("=" * 65)

# ─────────────────────────────────────────────────────────────────────────────
# DATASYNC: Register a connection first (needed for heartbeat etc.)
# ─────────────────────────────────────────────────────────────────────────────
print("\n--- Register Connection ---")
resp, body = test("POST /sync/register", "POST", "/sync/register", SYNC_HEADERS,
    json={
        "platform": "magento2",
        "store_url": "https://teststore.example.com",
        "store_name": "Test Store",
        "store_id": 1,
        "platform_version": "2.4.6",
        "module_version": "1.0.0",
        "php_version": "8.2",
        "locale": "en_US",
        "currency": "INR",
        "timezone": "Asia/Kolkata",
        "permissions": {
            "products": True,
            "categories": True,
            "orders": True,
            "customers": True,
            "inventory": True,
            "abandoned_carts": True,
        }
    }
)
if resp and resp.status_code in (200, 201):
    data = get_data(body)
    if isinstance(data, dict):
        created_ids['connection_id'] = data.get('connection_id')
        print(f"       Registered connection id={created_ids['connection_id']}")

# ─────────────────────────────────────────────────────────────────────────────
# DATASYNC: Heartbeat
# ─────────────────────────────────────────────────────────────────────────────
print("\n--- Heartbeat ---")
test("POST /sync/heartbeat", "POST", "/sync/heartbeat", SYNC_HEADERS,
    json={
        "platform": "magento2",
        "store_id": 1,
    }
)

# ─────────────────────────────────────────────────────────────────────────────
# DATASYNC: Products
# ─────────────────────────────────────────────────────────────────────────────
print("\n--- Products ---")
test("POST /sync/products", "POST", "/sync/products", SYNC_HEADERS,
    json={
        "platform": "magento2",
        "store_id": 1,
        "products": [
            {
                "id": "SKU-TEST-001",
                "sku": "SKU-TEST-001",
                "name": "Test Product Alpha",
                "price": 999.00,
                "status": "enabled",
                "categories": ["Electronics", "Gadgets"],
                "stock_qty": 50,
                "description": "A test product",
            },
            {
                "id": "SKU-TEST-002",
                "sku": "SKU-TEST-002",
                "name": "Test Product Beta",
                "price": 1499.00,
                "status": "enabled",
                "categories": ["Clothing"],
                "stock_qty": 10,
            }
        ]
    }
)

# ─────────────────────────────────────────────────────────────────────────────
# DATASYNC: Categories
# ─────────────────────────────────────────────────────────────────────────────
print("\n--- Categories ---")
test("POST /sync/categories", "POST", "/sync/categories", SYNC_HEADERS,
    json={
        "platform": "magento2",
        "store_id": 1,
        "categories": [
            {"id": "CAT-001", "name": "Electronics", "parent_id": None, "level": 1},
            {"id": "CAT-002", "name": "Gadgets", "parent_id": "CAT-001", "level": 2},
        ]
    }
)

# ─────────────────────────────────────────────────────────────────────────────
# DATASYNC: Inventory
# ─────────────────────────────────────────────────────────────────────────────
print("\n--- Inventory ---")
test("POST /sync/inventory", "POST", "/sync/inventory", SYNC_HEADERS,
    json={
        "platform": "magento2",
        "store_id": 1,
        "items": [
            {"sku": "SKU-TEST-001", "qty": 45, "is_in_stock": True},
            {"sku": "SKU-TEST-002", "qty": 8, "is_in_stock": True},
        ]
    }
)

# ─────────────────────────────────────────────────────────────────────────────
# DATASYNC: Orders
# ─────────────────────────────────────────────────────────────────────────────
print("\n--- Orders ---")
test("POST /sync/orders", "POST", "/sync/orders", SYNC_HEADERS,
    json={
        "platform": "magento2",
        "store_id": 1,
        "orders": [
            {
                "id": "ORD-TEST-001",
                "increment_id": "100000001",
                "customer_email": "customer@test.com",
                "customer_firstname": "Alice",
                "customer_lastname": "Smith",
                "grand_total": 1998.00,
                "status": "complete",
                "created_at": "2026-04-10T10:00:00Z",
                "items": [
                    {"sku": "SKU-TEST-001", "qty_ordered": 2, "price": 999.00}
                ]
            }
        ]
    }
)

# ─────────────────────────────────────────────────────────────────────────────
# DATASYNC: Customers
# ─────────────────────────────────────────────────────────────────────────────
print("\n--- Customers ---")
test("POST /sync/customers", "POST", "/sync/customers", SYNC_HEADERS,
    json={
        "platform": "magento2",
        "store_id": 1,
        "customers": [
            {
                "id": "CUST-TEST-001",
                "email": "customer@test.com",
                "firstname": "Alice",
                "lastname": "Smith",
                "created_at": "2026-01-01T00:00:00Z",
                "group_id": 1,
            }
        ]
    }
)

# ─────────────────────────────────────────────────────────────────────────────
# DATASYNC: Abandoned Carts
# ─────────────────────────────────────────────────────────────────────────────
print("\n--- Abandoned Carts ---")
test("POST /sync/abandoned-carts", "POST", "/sync/abandoned-carts", SYNC_HEADERS,
    json={
        "platform": "magento2",
        "store_id": 1,
        "abandoned_carts": [
            {
                "quote_id": "CART-TEST-001",
                "customer_email": "customer@test.com",
                "customer_name": "Alice Smith",
                "customer_id": "CUST-TEST-001",
                "grand_total": 999.00,
                "items_count": 1,
                "items": [{"sku": "SKU-TEST-001", "qty": 1, "price": 999.00}],
                "status": "abandoned",
                "abandoned_at": "2026-04-10T08:00:00Z",
            }
        ]
    }
)

# ─────────────────────────────────────────────────────────────────────────────
# SUMMARY
# ─────────────────────────────────────────────────────────────────────────────
print("\n" + "=" * 65)
print("TEST SUMMARY")
print("=" * 65)
passed = sum(1 for r in results if r[1])
failed = sum(1 for r in results if not r[1])
total  = len(results)

for name, ok, status, _ in results:
    icon = "✓" if ok else "✗"
    print(f"  {icon} [{status}] {name}")

print(f"\nTotal: {total}  |  Passed: {passed}  |  Failed: {failed}")

if failed > 0:
    print("\nFAILED TESTS DETAIL:")
    for name, ok, status, body in results:
        if not ok:
            print(f"\n  FAIL: {name} (HTTP {status})")
            print(f"  Body: {json.dumps(body)[:600] if not isinstance(body, str) else body[:600]}")

sys.exit(0 if failed == 0 else 1)
