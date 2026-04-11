#!/usr/bin/env python3
"""
Deep validation test - checks response body structure, edge cases,
and validates data was actually stored correctly.
"""

import socket
import json
import time
import sys

# CDN Bypass
_original_getaddrinfo = socket.getaddrinfo
def _patched_getaddrinfo(host, port, *args, **kwargs):
    if host == 'ecom.buildnetic.com':
        host = '13.204.186.178'
    return _original_getaddrinfo(host, port, *args, **kwargs)
socket.getaddrinfo = _patched_getaddrinfo

import requests
import urllib3
urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

BASE_URL     = "https://ecom.buildnetic.com/api/v1"
BEARER_TOKEN = "51|NOcBfo6Zzm1YFQQcwcrKlNSzXUY0znmWloe48WISf0b0ba50"
API_KEY      = "ek_Z3KoaSngu5edQ9exUKCDSYaMqAm7xomX"
SECRET_KEY   = "sk_TBE3Jms2zQnDlT3RBO2cSd5sakoTgptL"

MH = {"Authorization": f"Bearer {BEARER_TOKEN}", "Content-Type": "application/json", "Accept": "application/json"}
SH = {"X-Ecom360-Key": API_KEY, "X-Ecom360-Secret": SECRET_KEY, "Content-Type": "application/json", "Accept": "application/json"}

results = []

def req(method, url, headers, **kwargs):
    r = requests.request(method, f"{BASE_URL}{url}", headers=headers, timeout=30, verify=False, **kwargs)
    try:
        return r, r.json()
    except:
        return r, r.text

def check(name, condition, detail=""):
    ok = bool(condition)
    icon = "✓" if ok else "✗"
    print(f"  {icon} {name}" + (f": {detail}" if detail else ""))
    results.append((name, ok))
    return ok

print("=" * 65)
print("DEEP VALIDATION TESTS")
print("=" * 65)

# ── GET /marketing/campaigns ─────────────────────────────────────────────────
print("\n[Marketing] Campaigns list structure:")
r, body = req("GET", "/marketing/campaigns", MH)
check("HTTP 200", r.status_code == 200)
check("success=true", body.get('success') == True)
data = body.get('data', {})
check("has data.data (paginated items)", isinstance(data.get('data'), list))
check("has data.total", 'total' in data)
check("has data.current_page", 'current_page' in data)

# ── POST /marketing/contacts then GET ────────────────────────────────────────
print("\n[Marketing] Contact create + read-back:")
ts = int(time.time())
r, body = req("POST", "/marketing/contacts", MH, json={
    "email": f"deep_test_{ts}@example.com",
    "first_name": "Deep",
    "last_name": "Test",
    "tags": ["vip"],
})
check("POST 201", r.status_code == 201)
contact_data = body.get('data', {})
contact_id = contact_data.get('id')
check("has id", contact_id is not None)
check("email matches", contact_data.get('email') == f"deep_test_{ts}@example.com")
check("status=subscribed", contact_data.get('status') == 'subscribed')

# Read it back
if contact_id:
    r2, body2 = req("GET", f"/marketing/contacts/{contact_id}", MH)  # Won't work - no show route but we can list
    r2, body2 = req("GET", "/marketing/contacts", MH, params={"search": f"deep_test_{ts}@example.com"})
    check("Contact searchable", r2.status_code == 200 and len(body2.get('data', {}).get('data', [])) >= 1)

# ── POST /marketing/templates then preview ───────────────────────────────────
print("\n[Marketing] Template create + preview:")
r, body = req("POST", "/marketing/templates", MH, json={
    "name": f"Preview Test {ts}",
    "channel": "email",
    "subject": "Hello {{first_name}}!",
    "body_html": "<p>Hi {{first_name}} {{last_name}}</p><p>Order: {{order_id}}</p>",
    "body_text": "Hi {{first_name}}",
})
check("POST 201", r.status_code == 201)
tpl = body.get('data', {})
tpl_id = tpl.get('id')
check("has id", tpl_id is not None)
check("has variables extracted", isinstance(tpl.get('variables'), list))

if tpl_id:
    r2, body2 = req("GET", f"/marketing/templates/{tpl_id}/preview", MH)
    check("Preview 200", r2.status_code == 200)
    preview = body2.get('data', {})
    check("Preview has subject", 'subject' in preview)
    check("Preview has html (render key)", 'html' in preview)

# ── POST /marketing/campaigns with audience type=list ────────────────────────
print("\n[Marketing] Campaign with list audience:")
# Create a list first
r, body = req("POST", "/marketing/lists", MH, json={"name": f"Deep Test List {ts}"})
list_id = body.get('data', {}).get('id')

r, body = req("POST", "/marketing/campaigns", MH, json={
    "name": f"List Campaign {ts}",
    "channel": "sms",
    "type": "one_time",
    "audience": {"type": "list", "list_ids": [list_id] if list_id else []},
})
check("POST 201", r.status_code == 201)
camp = body.get('data', {})
camp_id = camp.get('id')
check("status=draft", camp.get('status') == 'draft')
check("channel=sms", camp.get('channel') == 'sms')

# ── Validation errors ─────────────────────────────────────────────────────────
print("\n[Marketing] Validation errors:")
r, body = req("POST", "/marketing/campaigns", MH, json={"name": "Missing fields"})
check("Missing required fields → 422", r.status_code == 422)

r, body = req("POST", "/marketing/contacts", MH, json={"email": "not-an-email"})
check("Bad email → 422", r.status_code == 422)

r, body = req("POST", "/marketing/templates", MH, json={"name": "No channel"})
check("Missing channel → 422", r.status_code == 422)

r, body = req("POST", "/marketing/flows", MH, json={"name": "No trigger type"})
check("Missing trigger_type → 422", r.status_code == 422)

# ── Auth errors ───────────────────────────────────────────────────────────────
print("\n[Marketing] Auth errors:")
r, body = req("GET", "/marketing/campaigns", {"Accept": "application/json"})
check("No auth → 401/403", r.status_code in (401, 403))

# ── DataSync auth errors ──────────────────────────────────────────────────────
print("\n[DataSync] Auth errors:")
r, body = req("POST", "/sync/heartbeat", {"Content-Type": "application/json", "Accept": "application/json"},
              json={"platform": "magento2"})
check("Missing API key → 401", r.status_code == 401)

r, body = req("POST", "/sync/heartbeat",
              {"X-Ecom360-Key": API_KEY, "Content-Type": "application/json", "Accept": "application/json"},
              json={"platform": "magento2"})
check("Missing secret → 401", r.status_code == 401)

r, body = req("POST", "/sync/heartbeat",
              {"X-Ecom360-Key": "wrong_key", "X-Ecom360-Secret": "wrong_secret",
               "Content-Type": "application/json", "Accept": "application/json"},
              json={"platform": "magento2"})
check("Wrong keys → 403", r.status_code == 403)

# ── DataSync: status endpoint (read) ─────────────────────────────────────────
print("\n[DataSync] Status endpoint:")
r, body = req("GET", "/sync/status", SH)
check("GET /sync/status 200", r.status_code == 200)
data = body.get('data', [])
check("Returns list of connections", isinstance(data, list))
if data:
    conn = data[0]
    check("Connection has platform", 'platform' in conn)
    check("Connection has recent_syncs", 'recent_syncs' in conn)
    check("Connection has permissions", 'permissions' in conn)

# ── DataSync: Products response structure ─────────────────────────────────────
print("\n[DataSync] Products sync response structure:")
r, body = req("POST", "/sync/products", SH, json={
    "platform": "magento2",
    "store_id": 1,
    "products": [{"id": f"SKU-DEEP-{ts}", "sku": f"SKU-DEEP-{ts}", "name": "Deep Test Product", "price": 299.0}]
})
check("HTTP 200", r.status_code == 200)
data = body.get('data', {})
check("has received count", 'received' in data)
check("received=1", data.get('received') == 1)
check("has created/updated", 'created' in data or 'updated' in data)
created_or_updated = data.get('created', 0) + data.get('updated', 0)
check("created+updated=1", created_or_updated == 1)

# Re-sync same product → should update
r2, body2 = req("POST", "/sync/products", SH, json={
    "platform": "magento2",
    "store_id": 1,
    "products": [{"id": f"SKU-DEEP-{ts}", "sku": f"SKU-DEEP-{ts}", "name": "Deep Test Product Updated", "price": 399.0}]
})
data2 = body2.get('data', {})
check("Re-sync updates existing (updated=1)", data2.get('updated') == 1 and data2.get('created') == 0)

# ── DataSync: Abandoned carts permission check ────────────────────────────────
print("\n[DataSync] Abandoned carts permission check:")
r, body = req("POST", "/sync/abandoned-carts", SH, json={
    "platform": "magento2",
    "store_id": 1,
    "abandoned_carts": [
        {"quote_id": f"DEEP-CART-{ts}", "customer_email": "test@deep.com", "grand_total": 500.0, "items_count": 1}
    ]
})
# Should succeed (permissions were granted at register time) OR be a permission denied
check("Abandoned carts succeeds or permission denied gracefully",
      r.status_code == 200)
if r.status_code == 200:
    data = body.get('data', {})
    check("Cart sync has received", 'received' in data)

# ── Flow channels (providers list) ───────────────────────────────────────────
print("\n[Marketing] Channel providers endpoint:")
r, body = req("GET", "/marketing/channels/providers/email", MH)
check("GET /channels/providers/email 200", r.status_code == 200)
data = body.get('data', {})
check("has providers list", isinstance(data.get('providers'), list))
check("smtp in providers", 'smtp' in data.get('providers', []))

r, body = req("GET", "/marketing/channels/providers/whatsapp", MH)
check("GET /channels/providers/whatsapp 200", r.status_code == 200)
data = body.get('data', {})
check("meta in whatsapp providers", 'meta' in data.get('providers', []))

# ── Final Summary ─────────────────────────────────────────────────────────────
print("\n" + "=" * 65)
print("DEEP VALIDATION SUMMARY")
print("=" * 65)
passed = sum(1 for r in results if r[1])
failed = sum(1 for r in results if not r[1])
total = len(results)
print(f"Total checks: {total}  |  Passed: {passed}  |  Failed: {failed}")

if failed > 0:
    print("\nFailed checks:")
    for name, ok in results:
        if not ok:
            print(f"  ✗ {name}")

sys.exit(0 if failed == 0 else 1)
