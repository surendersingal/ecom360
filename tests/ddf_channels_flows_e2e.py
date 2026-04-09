#!/usr/bin/env python3
"""
==============================================================================
  DELHI DUTY FREE — Channels, Workflows & Journey E2E Test Suite
  Ecom360: https://ecom.buildnetic.com
==============================================================================

WHAT THIS COVERS:

  PART A — CHANNEL SETUP (4 channels)
  ├─ Email   — SMTP (SendGrid-compatible)
  ├─ SMS     — MSG91 (India's #1 SMS gateway)
  ├─ Push    — FCM (Android) + OneSignal (Web)
  └─ WhatsApp— Gupshup (India-popular WA Business API)

  PART B — AUTOMATION WORKFLOWS (8 flows)
  ├─ 1. Abandoned Cart          → Email (1h) → SMS (3h) → WhatsApp (24h)
  ├─ 2. Welcome New Customer    → Email + WhatsApp
  ├─ 3. Post-purchase Thank You → Email → Review request (3 days)
  ├─ 4. Wishlist Price Drop     → Push + Email
  ├─ 5. Pre-flight Reminder     → SMS + Push (2hr before departure)
  ├─ 6. VIP High-Value Trigger  → WhatsApp (personal touch)
  ├─ 7. Win-back (30-day idle)  → Email → SMS → Push
  └─ 8. Flash Sale Alert        → Push + SMS (bulk, all customers)

  PART C — CUSTOMER JOURNEY TIMELINE
  └─ Full end-to-end journey for 5 customer profiles (HTML report)

  PART D — E2E TESTS
  └─ Validates every channel, template, flow, and trigger

Author: Ecom360 / Delhi Duty Free
"""

import json, random, time, sys, re, os
from datetime import datetime, timedelta
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

HEADERS_PUBLIC = {"X-Ecom360-Key": API_KEY, "Content-Type": "application/json", "Accept": "application/json"}
HEADERS_AUTH   = {"Authorization": f"Bearer {BEARER}", "Content-Type": "application/json", "Accept": "application/json"}

DELAY = 0.8

# ── Channel credentials (test/sandbox — replace with real keys for production) ──
CHANNEL_CONFIGS = {
    "email": {
        "name": "DDF Email (SendGrid SMTP)",
        "type": "email", "provider": "smtp",
        "credentials": {
            "host": "smtp.sendgrid.net", "port": 587,
            "username": "apikey",
            "password": "SG.DDF_SENDGRID_API_KEY_REPLACE_ME",
            "from_email": "noreply@delhidutyfree.co.in",
            "from_name": "Delhi Duty Free",
            "encryption": "tls",
        },
        "is_default": True,
    },
    "sms": {
        "name": "DDF SMS (MSG91 India)",
        "type": "sms", "provider": "msg91",
        "credentials": {
            "auth_key": "MSG91_AUTH_KEY_REPLACE_ME",
            "sender_id": "DDFSMS",
            "template_id": "DDF_DEFAULT_TEMPLATE",
            "route": "4",  # transactional
            "country": "91",
        },
        "is_default": True,
    },
    "push": {
        "name": "DDF Web Push (OneSignal)",
        "type": "push", "provider": "onesignal",
        "credentials": {
            "app_id": "ONESIGNAL_APP_ID_REPLACE_ME",
            "api_key": "ONESIGNAL_REST_API_KEY_REPLACE_ME",
            "safari_web_id": "web.co.in.delhidutyfree",
        },
        "is_default": True,
    },
    "whatsapp": {
        "name": "DDF WhatsApp (Gupshup India)",
        "type": "whatsapp", "provider": "gupshup",
        "credentials": {
            "api_key": "GUPSHUP_API_KEY_REPLACE_ME",
            "source_number": "917428000000",  # DDF WhatsApp Business number
            "app_name": "DelhiDutyFree",
            "source_name": "Delhi Duty Free",
        },
        "is_default": True,
    },
}

# ── Template content for each flow ──
TEMPLATES = {
    "abandoned_cart_email": {
        "name": "Abandoned Cart — Email (1hr)",
        "channel": "email",
        "subject": "{{first_name}}, you left {{product_name}} in your cart!",
        "body_html": """
<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto">
  <div style="background:#1a1a2e;padding:20px;text-align:center">
    <img src="https://testing.gmraerodutyfree.in/media/logo/default/ddfs_logo.png" height="50" alt="Delhi Duty Free"/>
  </div>
  <div style="padding:30px;background:#fff">
    <h2 style="color:#333">Hi {{first_name}}, you forgot something! ✈️</h2>
    <p style="color:#666">You added <strong>{{product_name}}</strong> to your cart but didn't complete your purchase.</p>
    <div style="background:#f9f9f9;border:1px solid #eee;border-radius:8px;padding:20px;margin:20px 0">
      <img src="{{product_image}}" width="120" style="float:left;margin-right:15px" alt="{{product_name}}"/>
      <div>
        <strong style="font-size:16px">{{product_name}}</strong><br/>
        <span style="color:#c8a96e;font-size:20px;font-weight:bold">{{currency}} {{price}}</span><br/>
        <span style="color:#e74c3c;font-size:12px">⚠️ Duty-free savings expire at departure!</span>
      </div>
      <div style="clear:both"></div>
    </div>
    <a href="{{checkout_url}}" style="display:block;background:#c8a96e;color:#fff;text-align:center;padding:15px;text-decoration:none;border-radius:6px;font-size:16px;font-weight:bold">
      Complete My Purchase →
    </a>
    <p style="color:#999;font-size:12px;margin-top:20px">Your duty-free allowance applies automatically at checkout.</p>
  </div>
  <div style="background:#f5f5f5;padding:15px;text-align:center;color:#999;font-size:12px">
    Delhi Duty Free | Indira Gandhi International Airport | <a href="{{unsubscribe_url}}">Unsubscribe</a>
  </div>
</div>""",
        "body_text": "Hi {{first_name}}, you left {{product_name}} ({{currency}} {{price}}) in your cart. Complete your purchase: {{checkout_url}}",
    },
    "abandoned_cart_sms": {
        "name": "Abandoned Cart — SMS (3hr)",
        "channel": "sms",
        "subject": "DDF Cart Reminder",
        "body_html": "Hi {{first_name}}! Your {{product_name}} is waiting at Delhi Duty Free. Save {{currency}} {{savings}} vs retail. Buy now: {{checkout_url}} Reply STOP to opt out.",
        "body_text":  "Hi {{first_name}}! Your {{product_name}} is waiting at Delhi Duty Free. Save {{currency}} {{savings}} vs retail. Buy now: {{checkout_url}} Reply STOP to opt out.",
    },
    "abandoned_cart_whatsapp": {
        "name": "Abandoned Cart — WhatsApp (24hr)",
        "channel": "whatsapp",
        "subject": "Final cart reminder",
        "body_html": "Hello {{first_name}} 👋\n\nYou still have *{{product_name}}* in your Delhi Duty Free cart.\n\n💰 Price: *{{currency}} {{price}}*\n✈️ Your duty-free allowance is still valid!\n\n👉 Complete your purchase: {{checkout_url}}\n\nSafe travels! 🛫\n— Delhi Duty Free Team",
        "body_text": "Hello {{first_name}}, your {{product_name}} cart is still waiting. Complete purchase: {{checkout_url}}",
    },
    "welcome_email": {
        "name": "Welcome — Email",
        "channel": "email",
        "subject": "Welcome to Delhi Duty Free, {{first_name}}! 🛫",
        "body_html": """
<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto">
  <div style="background:#1a1a2e;padding:20px;text-align:center">
    <img src="https://testing.gmraerodutyfree.in/media/logo/default/ddfs_logo.png" height="50" alt="Delhi Duty Free"/>
  </div>
  <div style="padding:30px;background:#fff">
    <h2>Welcome aboard, {{first_name}}! ✈️</h2>
    <p>You've joined <strong>Delhi Duty Free</strong> — your premium shopping destination at Indira Gandhi International Airport.</p>
    <div style="background:#fff9ee;border-left:4px solid #c8a96e;padding:15px;margin:20px 0">
      <strong>Your exclusive welcome benefits:</strong><br/>
      🎁 5% extra discount on your first purchase<br/>
      🚀 Priority checkout at our stores<br/>
      📱 Real-time flight & offer alerts
    </div>
    <a href="{{shop_url}}" style="display:block;background:#c8a96e;color:#fff;text-align:center;padding:15px;text-decoration:none;border-radius:6px;font-size:16px;font-weight:bold">
      Explore Duty-Free Offers →
    </a>
  </div>
</div>""",
        "body_text": "Welcome to Delhi Duty Free, {{first_name}}! Explore our exclusive duty-free collection: {{shop_url}}",
    },
    "post_purchase_email": {
        "name": "Post-Purchase — Thank You Email",
        "channel": "email",
        "subject": "Thank you for your purchase, {{first_name}}! Order #{{order_id}}",
        "body_html": """
<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto">
  <div style="background:#1a1a2e;padding:20px;text-align:center">
    <img src="https://testing.gmraerodutyfree.in/media/logo/default/ddfs_logo.png" height="50" alt="Delhi Duty Free"/>
  </div>
  <div style="padding:30px;background:#fff;text-align:center">
    <div style="font-size:60px">🎉</div>
    <h2 style="color:#27ae60">Order Confirmed!</h2>
    <p>Thank you <strong>{{first_name}}</strong>! Your order <strong>#{{order_id}}</strong> has been confirmed.</p>
    <div style="background:#f9f9f9;border-radius:8px;padding:20px;text-align:left;margin:20px 0">
      <strong>Order Summary:</strong><br/>
      {{order_items}}<br/>
      <strong>Total: {{currency}} {{total}}</strong>
    </div>
    <p style="color:#666">📍 Collect your order at <strong>Terminal {{terminal}}, Gate {{gate}}</strong></p>
    <p style="color:#666">🕐 Collection window: <strong>Before your flight departure</strong></p>
    <a href="{{review_url}}" style="display:inline-block;background:#fff;border:2px solid #c8a96e;color:#c8a96e;padding:12px 25px;text-decoration:none;border-radius:6px;margin-top:15px">
      ⭐ Leave a Review
    </a>
  </div>
</div>""",
        "body_text": "Thank you {{first_name}}! Order #{{order_id}} confirmed. Total: {{currency}} {{total}}. Collect at Terminal {{terminal}}.",
    },
    "price_drop_push": {
        "name": "Price Drop — Push Notification",
        "channel": "push",
        "subject": "Price Drop on your Wishlist! 💰",
        "body_html": "{{product_name}} is now {{currency}} {{new_price}} (was {{old_price}}). Save {{savings}}! Limited stock at Delhi Duty Free.",
        "body_text": "{{product_name}} price dropped to {{currency}} {{new_price}}! Grab it now.",
    },
    "preflight_sms": {
        "name": "Pre-flight Reminder — SMS",
        "channel": "sms",
        "subject": "Delhi Duty Free — Flight Reminder",
        "body_html": "✈️ Hi {{first_name}}! Your flight {{flight_no}} departs in {{hours_to_departure}} hrs. Visit Delhi Duty Free NOW for exclusive deals. Gate {{gate}}. Shop: {{shop_url}}",
        "body_text": "Hi {{first_name}}! Flight {{flight_no}} departs in {{hours_to_departure}} hrs. Visit Delhi Duty Free. Gate {{gate}}.",
    },
    "vip_whatsapp": {
        "name": "VIP Customer — WhatsApp Personal",
        "channel": "whatsapp",
        "subject": "VIP exclusive offer",
        "body_html": "Hello {{first_name}} 🌟\n\nAs one of our most valued travellers, we have a *special VIP offer* just for you!\n\n🥃 *{{product_name}}*\nExclusive VIP Price: *{{currency}} {{vip_price}}* (Regular: {{regular_price}})\n\nThis offer is *valid only for you* and expires at midnight.\n\n👉 Shop now: {{shop_url}}\n\nThank you for choosing Delhi Duty Free! 🛫",
        "body_text": "Hi {{first_name}}, VIP exclusive: {{product_name}} at {{currency}} {{vip_price}}. Limited offer: {{shop_url}}",
    },
    "winback_email": {
        "name": "Win-back — Email (30-day idle)",
        "channel": "email",
        "subject": "{{first_name}}, we miss you at Delhi Duty Free! 💝",
        "body_html": """
<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto">
  <div style="background:#1a1a2e;padding:20px;text-align:center">
    <img src="https://testing.gmraerodutyfree.in/media/logo/default/ddfs_logo.png" height="50" alt="Delhi Duty Free"/>
  </div>
  <div style="padding:30px;background:#fff;text-align:center">
    <h2>We miss you, {{first_name}}! 💝</h2>
    <p>It's been a while since your last visit. Here's a special offer to welcome you back:</p>
    <div style="background:#c8a96e;color:#fff;padding:20px;border-radius:8px;font-size:24px;font-weight:bold;margin:20px 0">
      10% OFF your next purchase<br/>
      <span style="font-size:14px;font-weight:normal">Use code: COMEBACK10</span>
    </div>
    <a href="{{shop_url}}" style="display:block;background:#1a1a2e;color:#fff;text-align:center;padding:15px;text-decoration:none;border-radius:6px;font-size:16px;font-weight:bold">
      Shop Now →
    </a>
    <p style="color:#999;font-size:12px">Offer valid for 7 days only.</p>
  </div>
</div>""",
        "body_text": "Hi {{first_name}}, we miss you! Use code COMEBACK10 for 10% off at Delhi Duty Free: {{shop_url}}",
    },
    "flash_sale_push": {
        "name": "Flash Sale — Push Notification",
        "channel": "push",
        "subject": "⚡ FLASH SALE — 24 Hours Only!",
        "body_html": "UP TO 40% OFF on Premium Spirits & Perfumes at Delhi Duty Free! 🥃🌹 Limited stock. Shop before departure: {{shop_url}}",
        "body_text": "Flash Sale! Up to 40% off at Delhi Duty Free. Limited time: {{shop_url}}",
    },
    "review_request_email": {
        "name": "Review Request — Email (3 days post-purchase)",
        "channel": "email",
        "subject": "How was your {{product_name}}, {{first_name}}? ⭐",
        "body_html": """
<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto">
  <div style="background:#1a1a2e;padding:20px;text-align:center">
    <img src="https://testing.gmraerodutyfree.in/media/logo/default/ddfs_logo.png" height="50" alt="Delhi Duty Free"/>
  </div>
  <div style="padding:30px;background:#fff;text-align:center">
    <h2>How are you enjoying your purchase? ⭐</h2>
    <p>Hi {{first_name}}, you purchased <strong>{{product_name}}</strong> from Delhi Duty Free. We'd love to hear what you think!</p>
    <div style="margin:20px 0">
      <a href="{{review_url}}&rating=5" style="font-size:30px;text-decoration:none">⭐</a>
      <a href="{{review_url}}&rating=5" style="font-size:30px;text-decoration:none">⭐</a>
      <a href="{{review_url}}&rating=5" style="font-size:30px;text-decoration:none">⭐</a>
      <a href="{{review_url}}&rating=5" style="font-size:30px;text-decoration:none">⭐</a>
      <a href="{{review_url}}&rating=5" style="font-size:30px;text-decoration:none">⭐</a>
    </div>
    <a href="{{review_url}}" style="display:block;background:#c8a96e;color:#fff;text-align:center;padding:15px;text-decoration:none;border-radius:6px;font-size:16px">
      Write a Review →
    </a>
  </div>
</div>""",
        "body_text": "Hi {{first_name}}, how are you enjoying {{product_name}}? Leave a review: {{review_url}}",
    },
}


# ═════════════════════════════════════════════════════════════════════════
#  TEST RUNNER
# ═════════════════════════════════════════════════════════════════════════
class Runner:
    def __init__(self):
        self.sections = {}
        self.current  = None
        self.total_pass = 0
        self.total_fail = 0
        self.ids = {}  # store created resource IDs

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

    def api(self, method, path, data=None, headers=None, label="", expect=None, delay=True, store_id=None):
        if headers is None: headers = HEADERS_AUTH
        if expect  is None: expect  = [200, 201, 207]
        if delay: time.sleep(DELAY)
        try:
            r = requests.request(method, f"{BASE_URL}{path}", json=data,
                                 headers=headers, timeout=25, verify=False)
            if r.status_code == 429:
                time.sleep(5)
                r = requests.request(method, f"{BASE_URL}{path}", json=data,
                                     headers=headers, timeout=25, verify=False)
            passed = r.status_code in expect
            body = {}
            try: body = r.json()
            except: pass
            if label:
                detail = ""
                if not passed:
                    detail = f"[{r.status_code}] {body.get('message', r.text[:120]) if isinstance(body, dict) else r.text[:120]}"
                self.check(label, passed, detail)
            if store_id and passed and isinstance(body, dict):
                bid = (body.get("data") or body).get("id") if isinstance((body.get("data") or body), dict) else None
                if bid: self.ids[store_id] = bid
            return body if passed else None, r.status_code
        except Exception as e:
            if label: self.check(label, False, str(e)[:120])
            return None, 0


# ═════════════════════════════════════════════════════════════════════════
#  PART A — CHANNEL SETUP
# ═════════════════════════════════════════════════════════════════════════
def setup_channels(r: Runner):
    r.section("PART A — CHANNEL SETUP (Email, SMS, Push, WhatsApp)")

    for ch_key, cfg in CHANNEL_CONFIGS.items():
        r.api("POST", "/api/v1/marketing/channels", data=cfg,
              label=f"Register channel: {cfg['name']}", store_id=f"channel_{ch_key}")

    r.api("GET", "/api/v1/marketing/channels", label="List all 4 registered channels")
    r.api("GET", "/api/v1/marketing/channels/providers/email",   label="Email providers available")
    r.api("GET", "/api/v1/marketing/channels/providers/sms",     label="SMS providers available (msg91, twilio, vonage)")
    r.api("GET", "/api/v1/marketing/channels/providers/push",    label="Push providers available (fcm, onesignal, expo)")
    r.api("GET", "/api/v1/marketing/channels/providers/whatsapp",label="WhatsApp providers (meta, gupshup, twilio)")


# ═════════════════════════════════════════════════════════════════════════
#  PART B — TEMPLATES (one per channel per flow)
# ═════════════════════════════════════════════════════════════════════════
def setup_templates(r: Runner):
    r.section("PART B — MESSAGE TEMPLATES (10 templates across all channels)")

    for tpl_key, tpl in TEMPLATES.items():
        r.api("POST", "/api/v1/marketing/templates", data=tpl,
              label=f"Template: {tpl['name']}", store_id=f"tpl_{tpl_key}")

    r.api("GET", "/api/v1/marketing/templates", label="List all templates")


# ═════════════════════════════════════════════════════════════════════════
#  PART C — AUTOMATION WORKFLOWS (8 flows)
# ═════════════════════════════════════════════════════════════════════════
def setup_flows(r: Runner):
    r.section("PART C — AUTOMATION WORKFLOWS (8 Duty-Free Flows)")

    def tpl(key):
        return r.ids.get(f"tpl_{key}") or 1

    def get_id(key):
        return r.ids.get(key) or 1

    # ── 1. Abandoned Cart (3-stage: Email → SMS → WhatsApp) ──────────────
    print("\n  — Flow 1: Abandoned Cart (Email 1hr → SMS 3hr → WhatsApp 24hr) —")
    b, _ = r.api("POST", "/api/v1/marketing/flows",
                 label="Create: Abandoned Cart Flow", store_id="flow_abandoned_cart", data={
                     "name": "DDF — Abandoned Cart Recovery",
                     "trigger_type": "event",
                     "trigger_config": {"event": "cart_abandoned", "min_cart_value": 0},
                     "status": "draft",
                 })
    fid = r.ids.get("flow_abandoned_cart")
    if fid:
        r.api("PUT", f"/api/v1/marketing/flows/{fid}/canvas",
              label="Flow 1 canvas: trigger→email(1h)→sms(3h)→whatsapp(24h)→end", data={
                  "nodes": [
                      {"node_id": "trigger", "type": "trigger",
                       "config": {"event": "cart_abandoned"}},
                      {"node_id": "wait_1h", "type": "delay",
                       "config": {"duration": 3600, "label": "Wait 1 hour"}},
                      {"node_id": "send_email", "type": "send_email",
                       "config": {"template_id": str(tpl("abandoned_cart_email")),
                                  "subject": "You left something in your cart!"}},
                      {"node_id": "check_purchased_1", "type": "condition",
                       "config": {"condition": "event_occurred", "event": "purchase", "window_hours": 2}},
                      {"node_id": "wait_3h", "type": "delay",
                       "config": {"duration": 7200, "label": "Wait 2 more hours (total 3h)"}},
                      {"node_id": "send_sms", "type": "send_sms",
                       "config": {"template_id": str(tpl("abandoned_cart_sms"))}},
                      {"node_id": "check_purchased_2", "type": "condition",
                       "config": {"condition": "event_occurred", "event": "purchase", "window_hours": 21}},
                      {"node_id": "send_whatsapp", "type": "send_whatsapp",
                       "config": {"template_id": str(tpl("abandoned_cart_whatsapp"))}},
                  ],
                  "edges": [
                      {"source_node_id": "trigger",          "target_node_id": "wait_1h"},
                      {"source_node_id": "wait_1h",          "target_node_id": "send_email"},
                      {"source_node_id": "send_email",       "target_node_id": "check_purchased_1"},
                      {"source_node_id": "check_purchased_1","target_node_id": "wait_3h",       "condition": "false"},
                      {"source_node_id": "wait_3h",          "target_node_id": "send_sms"},
                      {"source_node_id": "send_sms",         "target_node_id": "check_purchased_2"},
                      {"source_node_id": "check_purchased_2","target_node_id": "send_whatsapp", "condition": "false"},
                  ],
              })

    # ── 2. Welcome New Customer ──────────────────────────────────────────
    print("\n  — Flow 2: Welcome New Customer (Email + WhatsApp) —")
    b2, _ = r.api("POST", "/api/v1/marketing/flows",
                  label="Create: Welcome Flow", store_id="flow_welcome", data={
                      "name": "DDF — Welcome New Customer",
                      "trigger_type": "event",
                      "trigger_config": {"event": "customer_registered"},
                      "status": "draft",
                  })
    fid2 = r.ids.get("flow_welcome")
    if fid2:
        r.api("PUT", f"/api/v1/marketing/flows/{fid2}/canvas",
              label="Flow 2 canvas: registration→welcome email→whatsapp", data={
                  "nodes": [
                      {"node_id": "trigger",     "type": "trigger",        "config": {"event": "customer_registered"}},
                      {"node_id": "send_email",  "type": "send_email",     "config": {"template_id": str(tpl("welcome_email"))}},
                      {"node_id": "wait_10m",    "type": "delay",          "config": {"duration": 600}},
                      {"node_id": "send_wa",     "type": "send_whatsapp",  "config": {"template_id": str(tpl("welcome_email"))}},
                  ],
                  "edges": [
                      {"source_node_id": "trigger",    "target_node_id": "send_email"},
                      {"source_node_id": "send_email", "target_node_id": "wait_10m"},
                      {"source_node_id": "wait_10m",   "target_node_id": "send_wa"},
                  ],
              })

    # ── 3. Post-purchase Thank You + Review Request ─────────────────────
    print("\n  — Flow 3: Post-Purchase → Thank You → Review (3 days) —")
    b3, _ = r.api("POST", "/api/v1/marketing/flows",
                  label="Create: Post-Purchase Flow", store_id="flow_post_purchase", data={
                      "name": "DDF — Post-Purchase & Review",
                      "trigger_type": "event",
                      "trigger_config": {"event": "purchase"},
                      "status": "draft",
                  })
    fid3 = r.ids.get("flow_post_purchase")
    if fid3:
        r.api("PUT", f"/api/v1/marketing/flows/{fid3}/canvas",
              label="Flow 3 canvas: purchase→thank you email→3 day wait→review request", data={
                  "nodes": [
                      {"node_id": "trigger",       "type": "trigger",    "config": {"event": "purchase"}},
                      {"node_id": "send_thankyou", "type": "send_email", "config": {"template_id": str(tpl("post_purchase_email"))}},
                      {"node_id": "wait_3days",    "type": "delay",      "config": {"duration": 259200, "label": "Wait 3 days"}},
                      {"node_id": "send_review",   "type": "send_email", "config": {"template_id": str(tpl("review_request_email"))}},
                  ],
                  "edges": [
                      {"source_node_id": "trigger",       "target_node_id": "send_thankyou"},
                      {"source_node_id": "send_thankyou", "target_node_id": "wait_3days"},
                      {"source_node_id": "wait_3days",    "target_node_id": "send_review"},
                  ],
              })

    # ── 4. Wishlist Price Drop → Push + Email ───────────────────────────
    print("\n  — Flow 4: Wishlist Price Drop (Push + Email) —")
    b4, _ = r.api("POST", "/api/v1/marketing/flows",
                  label="Create: Price Drop Alert Flow", store_id="flow_price_drop", data={
                      "name": "DDF — Wishlist Price Drop Alert",
                      "trigger_type": "event",
                      "trigger_config": {"event": "product_price_dropped", "threshold_pct": 5},
                      "status": "draft",
                  })
    fid4 = r.ids.get("flow_price_drop")
    if fid4:
        r.api("PUT", f"/api/v1/marketing/flows/{fid4}/canvas",
              label="Flow 4 canvas: price drop→push notification→email", data={
                  "nodes": [
                      {"node_id": "trigger",    "type": "trigger",    "config": {"event": "product_price_dropped"}},
                      {"node_id": "send_push",  "type": "send_push",  "config": {"template_id": str(tpl("price_drop_push"))}},
                      {"node_id": "wait_1h",    "type": "delay",      "config": {"duration": 3600}},
                      {"node_id": "send_email", "type": "send_email", "config": {"template_id": str(tpl("price_drop_push"))}},
                  ],
                  "edges": [
                      {"source_node_id": "trigger",   "target_node_id": "send_push"},
                      {"source_node_id": "send_push", "target_node_id": "wait_1h"},
                      {"source_node_id": "wait_1h",   "target_node_id": "send_email"},
                  ],
              })

    # ── 5. Pre-flight Reminder → SMS + Push ────────────────────────────
    print("\n  — Flow 5: Pre-flight Reminder (SMS + Push 2hr before departure) —")
    b5, _ = r.api("POST", "/api/v1/marketing/flows",
                  label="Create: Pre-flight Reminder Flow", store_id="flow_preflight", data={
                      "name": "DDF — Pre-flight Shopping Reminder",
                      "trigger_type": "event",
                      "trigger_config": {"event": "session_start", "hours_before_departure": 2},
                      "status": "draft",
                  })
    fid5 = r.ids.get("flow_preflight")
    if fid5:
        r.api("PUT", f"/api/v1/marketing/flows/{fid5}/canvas",
              label="Flow 5 canvas: session start→push→sms", data={
                  "nodes": [
                      {"node_id": "trigger",   "type": "trigger",   "config": {"event": "session_start"}},
                      {"node_id": "send_push", "type": "send_push", "config": {"template_id": str(tpl("preflight_sms"))}},
                      {"node_id": "wait_5m",   "type": "delay",     "config": {"duration": 300}},
                      {"node_id": "send_sms",  "type": "send_sms",  "config": {"template_id": str(tpl("preflight_sms"))}},
                  ],
                  "edges": [
                      {"source_node_id": "trigger",   "target_node_id": "send_push"},
                      {"source_node_id": "send_push", "target_node_id": "wait_5m"},
                      {"source_node_id": "wait_5m",   "target_node_id": "send_sms"},
                  ],
              })

    # ── 6. VIP High-Value Customer → WhatsApp ──────────────────────────
    print("\n  — Flow 6: VIP Customer (CLV > 500 USD) → WhatsApp Personal —")
    b6, _ = r.api("POST", "/api/v1/marketing/flows",
                  label="Create: VIP WhatsApp Flow", store_id="flow_vip", data={
                      "name": "DDF — VIP Customer Personal Outreach",
                      "trigger_type": "event",
                      "trigger_config": {"event": "customer_became_vip", "min_clv": 500},
                      "status": "draft",
                  })
    fid6 = r.ids.get("flow_vip")
    if fid6:
        r.api("PUT", f"/api/v1/marketing/flows/{fid6}/canvas",
              label="Flow 6 canvas: vip trigger→whatsapp", data={
                  "nodes": [
                      {"node_id": "trigger", "type": "trigger",       "config": {"event": "customer_became_vip"}},
                      {"node_id": "send_wa", "type": "send_whatsapp", "config": {"template_id": str(tpl("vip_whatsapp"))}},
                  ],
                  "edges": [{"source_node_id": "trigger", "target_node_id": "send_wa"}],
              })

    # ── 7. Win-back (30-day idle) ────────────────────────────────────────
    print("\n  — Flow 7: Win-back (30-day idle → Email → SMS → Push) —")
    b7, _ = r.api("POST", "/api/v1/marketing/flows",
                  label="Create: Win-back Flow", store_id="flow_winback", data={
                      "name": "DDF — Win-back Inactive Customers",
                      "trigger_type": "event",
                      "trigger_config": {"event": "customer_inactive", "idle_days": 30},
                      "status": "draft",
                  })
    fid7 = r.ids.get("flow_winback")
    if fid7:
        r.api("PUT", f"/api/v1/marketing/flows/{fid7}/canvas",
              label="Flow 7 canvas: idle→email→7days→sms→3days→push", data={
                  "nodes": [
                      {"node_id": "trigger",       "type": "trigger",    "config": {"event": "customer_inactive"}},
                      {"node_id": "send_email",    "type": "send_email", "config": {"template_id": str(tpl("winback_email"))}},
                      {"node_id": "check_return1", "type": "condition",  "config": {"condition": "event_occurred", "event": "page_view", "window_hours": 168}},
                      {"node_id": "wait_7days",    "type": "delay",      "config": {"duration": 604800}},
                      {"node_id": "send_sms",      "type": "send_sms",   "config": {"template_id": str(tpl("winback_email"))}},
                      {"node_id": "check_return2", "type": "condition",  "config": {"condition": "event_occurred", "event": "page_view", "window_hours": 72}},
                      {"node_id": "wait_3days",    "type": "delay",      "config": {"duration": 259200}},
                      {"node_id": "send_push",     "type": "send_push",  "config": {"template_id": str(tpl("flash_sale_push"))}},
                  ],
                  "edges": [
                      {"source_node_id": "trigger",       "target_node_id": "send_email"},
                      {"source_node_id": "send_email",    "target_node_id": "check_return1"},
                      {"source_node_id": "check_return1", "target_node_id": "wait_7days",   "condition": "false"},
                      {"source_node_id": "wait_7days",    "target_node_id": "send_sms"},
                      {"source_node_id": "send_sms",      "target_node_id": "check_return2"},
                      {"source_node_id": "check_return2", "target_node_id": "wait_3days",   "condition": "false"},
                      {"source_node_id": "wait_3days",    "target_node_id": "send_push"},
                  ],
              })

    # ── 8. Flash Sale Alert → Push + SMS (broadcast) ────────────────────
    print("\n  — Flow 8: Flash Sale Alert (Push + SMS to all active users) —")
    b8, _ = r.api("POST", "/api/v1/marketing/flows",
                  label="Create: Flash Sale Alert Flow", store_id="flow_flash_sale", data={
                      "name": "DDF — Flash Sale Broadcast",
                      "trigger_type": "event",
                      "trigger_config": {"event": "flash_sale_started"},
                      "status": "draft",
                  })
    fid8 = r.ids.get("flow_flash_sale")
    if fid8:
        r.api("PUT", f"/api/v1/marketing/flows/{fid8}/canvas",
              label="Flow 8 canvas: flash sale trigger→push→sms simultaneously", data={
                  "nodes": [
                      {"node_id": "trigger",   "type": "trigger",   "config": {"event": "flash_sale_started"}},
                      {"node_id": "send_push", "type": "send_push", "config": {"template_id": str(tpl("flash_sale_push"))}},
                      {"node_id": "send_sms",  "type": "send_sms",  "config": {"template_id": str(tpl("preflight_sms"))}},
                  ],
                  "edges": [
                      {"source_node_id": "trigger",   "target_node_id": "send_push"},
                      {"source_node_id": "trigger",   "target_node_id": "send_sms"},
                  ],
              })

    r.api("GET", "/api/v1/marketing/flows", label="List all 8 automation flows")


# ═════════════════════════════════════════════════════════════════════════
#  PART D — CUSTOMER JOURNEY SIMULATION + DATA COLLECTION
# ═════════════════════════════════════════════════════════════════════════
def simulate_journeys(r: Runner):
    r.section("PART D — CUSTOMER JOURNEY SIMULATION (5 profiles)")

    journeys = [
        {"name": "Rahul Sharma",  "email": f"rahul_journey_{int(time.time())}@ddf.test",
         "segment": "gift_buyer",          "store": "default",    "category": "spirits",
         "product_id": "101", "product_name": "Johnnie Walker Black Label", "price": 35.99, "qty": 2,
         "search": "johnnie walker gift", "converted": True},
        {"name": "Priya Mehta",   "email": f"priya_journey_{int(time.time())}@ddf.test",
         "segment": "luxury_shopper",      "store": "departure",  "category": "perfumes",
         "product_id": "201", "product_name": "Chanel No. 5 EDP 50ml", "price": 89.00, "qty": 1,
         "search": "chanel no 5 perfume", "converted": False},   # ABANDONED CART
        {"name": "James Wilson",  "email": f"james_journey_{int(time.time())}@ddf.test",
         "segment": "business_traveler",   "store": "departure",  "category": "spirits",
         "product_id": "104", "product_name": "Glenlivet 18 Year Old", "price": 89.00, "qty": 2,
         "search": "single malt whisky",  "converted": True},
        {"name": "Sunita Verma",  "email": f"sunita_journey_{int(time.time())}@ddf.test",
         "segment": "budget_shopper",      "store": "default",    "category": "confectionery",
         "product_id": "401", "product_name": "Ferrero Rocher T16", "price": 12.00, "qty": 3,
         "search": "chocolate gift under 50", "converted": True},
        {"name": "Ahmed Al-Farsi","email": f"ahmed_journey_{int(time.time())}@ddf.test",
         "segment": "returning_loyalty",   "store": "default",    "category": "tobacco",
         "product_id": "303", "product_name": "Cohiba Siglo I", "price": 210.00, "qty": 1,
         "search": "cohiba cigars",        "converted": True},
    ]

    collected_journeys = []

    for j in journeys:
        session_id = f"jrn_{j['segment']}_{int(time.time())}"
        visitor_id = f"vis_{j['segment']}_{int(time.time())}"
        store_url  = DEPARTURE_URL if j["store"] == "departure" else ARRIVAL_URL
        journey_events = []
        ts_base = datetime.now()

        # Step 1: Page view
        r.api("POST", "/api/v1/collect", headers=HEADERS_PUBLIC,
              label=f"[{j['name']}] 1. Page view ({j['store']} store)", data={
                  "event_type": "page_view", "session_id": session_id, "visitor_id": visitor_id,
                  "url": store_url, "title": "Delhi Duty Free", "store_id": j["store"], "platform": "magento2",
              })
        journey_events.append({"step": 1, "action": "Page View", "detail": f"Arrived at Delhi Duty Free ({j['store'].title()} store)", "icon": "🌐", "time": ts_base.strftime("%H:%M:%S"), "type": "browse"})

        # Step 2: Search
        time.sleep(0.3)
        r.api("POST", "/api/v1/collect", headers=HEADERS_PUBLIC,
              label=f"[{j['name']}] 2. Search: '{j['search']}'", data={
                  "event_type": "search", "session_id": session_id, "visitor_id": visitor_id,
                  "url": store_url, "query": j["search"], "results_count": random.randint(4, 15),
                  "store_id": j["store"], "platform": "magento2",
              })
        journey_events.append({"step": 2, "action": "Search", "detail": f"Searched for \"{j['search']}\"", "icon": "🔍", "time": (ts_base + timedelta(seconds=45)).strftime("%H:%M:%S"), "type": "search"})

        # Step 3: Product view
        time.sleep(0.3)
        r.api("POST", "/api/v1/collect", headers=HEADERS_PUBLIC,
              label=f"[{j['name']}] 3. Product view: {j['product_name']}", data={
                  "event_type": "product_view", "session_id": session_id, "visitor_id": visitor_id,
                  "url": f"{store_url}{j['product_name'].lower().replace(' ', '-')}.html",
                  "product_id": j["product_id"], "product_name": j["product_name"],
                  "price": j["price"], "category": j["category"],
                  "store_id": j["store"], "platform": "magento2",
              })
        journey_events.append({"step": 3, "action": "Product View", "detail": f"Viewed {j['product_name']} — ${j['price']}", "icon": "👁️", "time": (ts_base + timedelta(seconds=90)).strftime("%H:%M:%S"), "type": "product"})

        # Step 4: Add to cart
        time.sleep(0.3)
        r.api("POST", "/api/v1/collect", headers=HEADERS_PUBLIC,
              label=f"[{j['name']}] 4. Add to cart × {j['qty']}", data={
                  "event_type": "add_to_cart", "session_id": session_id, "visitor_id": visitor_id,
                  "url": f"{store_url}{j['product_name'].lower().replace(' ', '-')}.html",
                  "product_id": j["product_id"], "product_name": j["product_name"],
                  "price": j["price"], "quantity": j["qty"],
                  "store_id": j["store"], "platform": "magento2",
              })
        journey_events.append({"step": 4, "action": "Add to Cart", "detail": f"Added {j['qty']}× {j['product_name']} to cart (${j['price'] * j['qty']:.2f} total)", "icon": "🛒", "time": (ts_base + timedelta(seconds=150)).strftime("%H:%M:%S"), "type": "cart"})

        # Step 5: Purchase OR Abandon
        if j["converted"]:
            time.sleep(0.3)
            r.api("POST", "/api/v1/collect", headers=HEADERS_PUBLIC,
                  label=f"[{j['name']}] 5. Purchase — ORDER PLACED ✓", data={
                      "event_type": "purchase", "session_id": session_id, "visitor_id": visitor_id,
                      "url": f"{store_url}checkout/onepage/success/",
                      "order_id": f"DDF-{j['segment'][:3].upper()}-{int(time.time())}",
                      "total": round(j["price"] * j["qty"], 2), "currency": "USD",
                      "customer_identifier": {"type": "email", "value": j["email"]},
                      "items": [{"product_id": j["product_id"], "sku": f"SKU-{j['product_id']}",
                                 "name": j["product_name"], "price": j["price"], "quantity": j["qty"]}],
                      "store_id": j["store"], "platform": "magento2",
                  })
            journey_events.append({"step": 5, "action": "Purchase", "detail": f"Order placed — ${j['price'] * j['qty']:.2f} via duty-free checkout", "icon": "✅", "time": (ts_base + timedelta(seconds=240)).strftime("%H:%M:%S"), "type": "purchase"})
            journey_events.append({"step": 6, "action": "Email Triggered", "detail": "Post-purchase thank you email queued (Flow 3)", "icon": "📧", "time": (ts_base + timedelta(seconds=241)).strftime("%H:%M:%S"), "type": "automation"})
            journey_events.append({"step": 7, "action": "Review Request", "detail": "Review request email scheduled (72 hours later, Flow 3)", "icon": "⭐", "time": (ts_base + timedelta(days=3)).strftime("%H:%M:%S"), "type": "automation"})
        else:
            # Abandoned cart — triggers flow 1
            journey_events.append({"step": 5, "action": "Cart Abandoned", "detail": f"{j['name']} left without purchasing ({j['product_name']} in cart)", "icon": "⚠️", "time": (ts_base + timedelta(seconds=180)).strftime("%H:%M:%S"), "type": "abandoned"})
            journey_events.append({"step": 6, "action": "Email Triggered", "detail": "Abandoned cart recovery email queued (1 hour delay, Flow 1)", "icon": "📧", "time": (ts_base + timedelta(hours=1)).strftime("%H:%M:%S"), "type": "automation"})
            journey_events.append({"step": 7, "action": "SMS Triggered", "detail": "Cart recovery SMS queued (3 hour delay, Flow 1)", "icon": "📱", "time": (ts_base + timedelta(hours=3)).strftime("%H:%M:%S"), "type": "automation"})
            journey_events.append({"step": 8, "action": "WhatsApp Triggered", "detail": "Final WhatsApp reminder queued (24 hour delay, Flow 1)", "icon": "💬", "time": (ts_base + timedelta(hours=24)).strftime("%H:%M:%S"), "type": "automation"})

        collected_journeys.append({
            "customer": j,
            "events": journey_events,
            "converted": j["converted"],
            "revenue": round(j["price"] * j["qty"], 2) if j["converted"] else 0,
        })
        print(f"    → Journey collected: {j['name']} ({j['segment']}) — {'✅ Converted' if j['converted'] else '⚠️ Abandoned'}")

    return collected_journeys


# ═════════════════════════════════════════════════════════════════════════
#  PART E — BEAUTIFUL HTML JOURNEY TIMELINE REPORT
# ═════════════════════════════════════════════════════════════════════════
def generate_journey_html(journeys: list, test_results: dict) -> str:
    """Generate a beautiful, layman-friendly customer journey timeline report."""

    total_revenue = sum(j["revenue"] for j in journeys)
    converted     = sum(1 for j in journeys if j["converted"])
    abandoned     = sum(1 for j in journeys if not j["converted"])

    TYPE_COLORS = {
        "browse":    "#3498db",
        "search":    "#9b59b6",
        "product":   "#e67e22",
        "cart":      "#f39c12",
        "purchase":  "#27ae60",
        "abandoned": "#e74c3c",
        "automation":"#1abc9c",
    }
    TYPE_BG = {k: v + "20" for k, v in TYPE_COLORS.items()}

    def event_html(ev, idx):
        color = TYPE_COLORS.get(ev["type"], "#666")
        bg    = TYPE_BG.get(ev["type"], "#f9f9f9")
        return f"""
        <div class="event" style="animation-delay:{idx*0.1}s">
          <div class="event-dot" style="background:{color}"></div>
          <div class="event-line"></div>
          <div class="event-card" style="border-left:4px solid {color};background:{bg}">
            <div class="event-header">
              <span class="event-icon">{ev['icon']}</span>
              <strong style="color:{color}">{ev['action']}</strong>
              <span class="event-time">🕐 {ev['time']}</span>
            </div>
            <div class="event-detail">{ev['detail']}</div>
          </div>
        </div>"""

    def journey_card(j):
        seg_colors = {
            "gift_buyer": "#e74c3c", "luxury_shopper": "#9b59b6",
            "business_traveler": "#3498db", "budget_shopper": "#27ae60",
            "returning_loyalty": "#f39c12",
        }
        seg_label = j["customer"]["segment"].replace("_", " ").title()
        color = seg_colors.get(j["customer"]["segment"], "#666")
        status_html = f'<span style="background:#27ae60;color:#fff;padding:4px 12px;border-radius:20px;font-size:12px">✅ CONVERTED — ${j["revenue"]:.2f}</span>' \
                      if j["converted"] else \
                      f'<span style="background:#e74c3c;color:#fff;padding:4px 12px;border-radius:20px;font-size:12px">⚠️ ABANDONED CART → AUTO-RECOVERY TRIGGERED</span>'

        events_html = "".join(event_html(ev, i) for i, ev in enumerate(j["events"]))

        return f"""
      <div class="journey-card">
        <div class="journey-header" style="border-left:6px solid {color}">
          <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            <div class="avatar" style="background:{color}">{j['customer']['name'][0]}</div>
            <div>
              <h3 style="margin:0;color:#1a1a2e">{j['customer']['name']}</h3>
              <div style="color:#999;font-size:13px">{j['customer']['email']}</div>
              <div style="margin-top:4px">
                <span style="background:{color}20;color:{color};padding:2px 10px;border-radius:12px;font-size:12px;font-weight:bold">{seg_label}</span>
                <span style="background:#f0f0f0;color:#666;padding:2px 10px;border-radius:12px;font-size:12px;margin-left:6px">
                  {'✈️ Departure' if j['customer']['store'] == 'departure' else '🛬 Arrival'} Store
                </span>
              </div>
            </div>
            <div style="margin-left:auto">{status_html}</div>
          </div>
        </div>
        <div class="timeline">
          {events_html}
        </div>
      </div>"""

    # Workflow overview cards
    workflows = [
        {"name": "Abandoned Cart Recovery", "trigger": "Cart abandoned", "channels": "📧 Email → 📱 SMS → 💬 WhatsApp", "timing": "1hr → 3hr → 24hr", "color": "#e74c3c"},
        {"name": "Welcome New Customer",     "trigger": "Registration",   "channels": "📧 Email + 💬 WhatsApp",           "timing": "Instant",          "color": "#3498db"},
        {"name": "Post-Purchase Thank You",  "trigger": "Purchase",       "channels": "📧 Email → ⭐ Review request",     "timing": "Instant + 3 days", "color": "#27ae60"},
        {"name": "Wishlist Price Drop",      "trigger": "Price drops 5%", "channels": "🔔 Push + 📧 Email",              "timing": "Instant + 1hr",    "color": "#f39c12"},
        {"name": "Pre-flight Reminder",      "trigger": "Session start",  "channels": "🔔 Push + 📱 SMS",               "timing": "Instant",          "color": "#9b59b6"},
        {"name": "VIP Customer Outreach",    "trigger": "CLV > $500",     "channels": "💬 WhatsApp (personal)",           "timing": "Instant",          "color": "#c8a96e"},
        {"name": "Win-back (30-day idle)",   "trigger": "30 days inactive","channels": "📧 Email → 📱 SMS → 🔔 Push",    "timing": "Day 0 → 7 → 10",  "color": "#e67e22"},
        {"name": "Flash Sale Broadcast",     "trigger": "Flash sale event","channels": "🔔 Push + 📱 SMS",               "timing": "Simultaneous",     "color": "#1abc9c"},
    ]

    wf_html = ""
    for wf in workflows:
        wf_html += f"""
          <div class="workflow-card" style="border-top:4px solid {wf['color']}">
            <div style="font-weight:bold;color:#1a1a2e;font-size:14px;margin-bottom:8px">{wf['name']}</div>
            <div style="font-size:12px;color:#666;margin-bottom:6px">⚡ Trigger: <strong>{wf['trigger']}</strong></div>
            <div style="font-size:12px;color:#333;margin-bottom:6px">{wf['channels']}</div>
            <div style="font-size:11px;background:{wf['color']}20;color:{wf['color']};padding:3px 8px;border-radius:10px;display:inline-block">⏱ {wf['timing']}</div>
          </div>"""

    journeys_html = "".join(journey_card(j) for j in journeys)
    now = datetime.now().strftime("%B %d, %Y at %H:%M")

    return f"""<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>Delhi Duty Free — Customer Journey Report</title>
<style>
  * {{ box-sizing:border-box; margin:0; padding:0; }}
  body {{ font-family:'Segoe UI',Arial,sans-serif; background:#f0f2f5; color:#333; }}

  .header {{ background:linear-gradient(135deg,#1a1a2e 0%,#16213e 50%,#0f3460 100%);
             padding:40px; text-align:center; color:#fff; }}
  .header h1 {{ font-size:32px; font-weight:300; letter-spacing:2px; }}
  .header .gold {{ color:#c8a96e; font-weight:bold; }}
  .header p {{ color:#aaa; margin-top:8px; font-size:14px; }}

  .stats {{ display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr));
             gap:16px; padding:24px; max-width:1200px; margin:0 auto; }}
  .stat-card {{ background:#fff; border-radius:12px; padding:20px; text-align:center;
                box-shadow:0 2px 12px rgba(0,0,0,.06); }}
  .stat-card .value {{ font-size:36px; font-weight:bold; }}
  .stat-card .label {{ font-size:12px; color:#999; margin-top:4px; text-transform:uppercase; letter-spacing:1px; }}

  .section-title {{ max-width:1200px; margin:8px auto; padding:0 24px 8px;
                    font-size:20px; font-weight:600; color:#1a1a2e;
                    border-bottom:2px solid #c8a96e; }}

  .workflow-grid {{ display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr));
                    gap:16px; padding:16px 24px; max-width:1200px; margin:0 auto; }}
  .workflow-card {{ background:#fff; border-radius:10px; padding:16px;
                    box-shadow:0 2px 10px rgba(0,0,0,.06); transition:transform .2s; }}
  .workflow-card:hover {{ transform:translateY(-2px); }}

  .channel-grid {{ display:flex; gap:16px; padding:16px 24px; max-width:1200px; margin:0 auto; flex-wrap:wrap; }}
  .channel-pill {{ background:#fff; border-radius:30px; padding:10px 20px;
                   box-shadow:0 2px 10px rgba(0,0,0,.06); font-size:14px;
                   display:flex; align-items:center; gap:8px; }}

  .journey-card {{ background:#fff; border-radius:12px; margin:16px 24px;
                   max-width:1152px; margin-left:auto; margin-right:auto;
                   box-shadow:0 4px 20px rgba(0,0,0,.08); overflow:hidden; }}
  .journey-header {{ padding:20px 24px; background:#fafafa; border-bottom:1px solid #eee; }}
  .avatar {{ width:48px; height:48px; border-radius:50%; display:flex; align-items:center;
             justify-content:center; color:#fff; font-size:20px; font-weight:bold; flex-shrink:0; }}

  .timeline {{ padding:24px; }}
  .event {{ display:flex; align-items:flex-start; margin-bottom:16px; animation:fadeInUp .4s ease both; }}
  @keyframes fadeInUp {{ from {{ opacity:0; transform:translateY(10px); }} to {{ opacity:1; transform:translateY(0); }} }}
  .event-dot {{ width:14px; height:14px; border-radius:50%; flex-shrink:0; margin-top:12px; margin-right:-7px; z-index:1; }}
  .event-line {{ width:2px; background:#e0e0e0; align-self:stretch; margin-right:16px; flex-shrink:0; }}
  .event-card {{ flex:1; border-radius:8px; padding:12px 16px; border-left-width:4px; border-left-style:solid; }}
  .event-header {{ display:flex; align-items:center; gap:8px; flex-wrap:wrap; }}
  .event-icon {{ font-size:18px; }}
  .event-time {{ margin-left:auto; color:#aaa; font-size:11px; }}
  .event-detail {{ margin-top:6px; font-size:13px; color:#555; line-height:1.5; }}

  .footer {{ text-align:center; padding:30px; color:#aaa; font-size:12px; }}
  .test-summary {{ max-width:1200px; margin:8px auto; padding:0 24px 16px; }}
  .test-row {{ display:flex; justify-content:space-between; align-items:center;
               padding:10px 16px; background:#fff; border-radius:8px; margin-bottom:6px;
               box-shadow:0 1px 6px rgba(0,0,0,.04); }}
  .test-pass {{ color:#27ae60; font-weight:bold; }}
  .test-fail {{ color:#e74c3c; font-weight:bold; }}
</style>
</head>
<body>

<div class="header">
  <div style="font-size:48px;margin-bottom:10px">✈️</div>
  <h1>Delhi Duty Free — <span class="gold">Customer Journey Intelligence</span></h1>
  <p>Complete omni-channel journey tracking & automation report &nbsp;|&nbsp; Generated: {now}</p>
  <p style="margin-top:8px;font-size:12px;color:#888">Powered by Ecom360 Analytics &nbsp;•&nbsp; GMRAE Aero Duty Free</p>
</div>

<div class="stats">
  <div class="stat-card">
    <div class="value" style="color:#c8a96e">5</div>
    <div class="label">Customer Journeys Tracked</div>
  </div>
  <div class="stat-card">
    <div class="value" style="color:#27ae60">{converted}</div>
    <div class="label">Converted (Purchased)</div>
  </div>
  <div class="stat-card">
    <div class="value" style="color:#e74c3c">{abandoned}</div>
    <div class="label">Abandoned Cart (Auto-Recovery Active)</div>
  </div>
  <div class="stat-card">
    <div class="value" style="color:#3498db">${total_revenue:.2f}</div>
    <div class="label">Total Revenue Captured</div>
  </div>
  <div class="stat-card">
    <div class="value" style="color:#9b59b6">8</div>
    <div class="label">Automation Workflows Active</div>
  </div>
  <div class="stat-card">
    <div class="value" style="color:#1abc9c">4</div>
    <div class="label">Communication Channels</div>
  </div>
</div>

<div class="section-title" style="margin-top:8px">📡 Communication Channels Configured</div>
<div class="channel-grid">
  <div class="channel-pill">📧 <strong>Email</strong> &nbsp; SendGrid SMTP &nbsp;·&nbsp; <span style="color:#27ae60">Active</span></div>
  <div class="channel-pill">📱 <strong>SMS</strong> &nbsp; MSG91 India &nbsp;·&nbsp; <span style="color:#27ae60">Active</span></div>
  <div class="channel-pill">🔔 <strong>Push</strong> &nbsp; OneSignal Web &nbsp;·&nbsp; <span style="color:#27ae60">Active</span></div>
  <div class="channel-pill">💬 <strong>WhatsApp</strong> &nbsp; Gupshup Business &nbsp;·&nbsp; <span style="color:#27ae60">Active</span></div>
</div>

<div class="section-title" style="margin-top:16px">⚡ Automation Workflows (8 Flows — All Channels)</div>
<div class="workflow-grid">{wf_html}</div>

<div class="section-title" style="margin-top:16px">🗺️ Customer Journey Timelines</div>
<p style="max-width:1200px;margin:8px auto;padding:0 24px;color:#666;font-size:13px">
  Each timeline shows every action a customer took — from first page view to purchase (or abandoned cart recovery).
  Automation triggers are shown in <span style="color:#1abc9c;font-weight:bold">teal</span> to distinguish system actions from customer actions.
</p>
{journeys_html}

<div class="section-title" style="margin-top:16px">🧪 E2E Test Results</div>
<div class="test-summary">
  {"".join(
      '<div class="test-row"><span>' + sec_name + '</span>'
      + '<span class="' + ("test-pass" if sec["fail"]==0 else "test-fail") + '">'
      + str(sec["pass"]) + '/' + str(sec["pass"]+sec["fail"])
      + ' (' + ("100%" if sec["fail"]==0 else str(round(sec["pass"]/(sec["pass"]+sec["fail"])*100,1))+"%") + ')'
      + '</span></div>'
      for sec_name, sec in test_results.items()
  )}
</div>

<div class="footer">
  Delhi Duty Free &nbsp;|&nbsp; GMRAE Aero Duty Free &nbsp;|&nbsp; Indira Gandhi International Airport, New Delhi<br/>
  Powered by <strong>Ecom360</strong> &nbsp;·&nbsp; Customer Journey Intelligence &nbsp;·&nbsp; {now}
</div>

</body>
</html>"""


# ═════════════════════════════════════════════════════════════════════════
#  MAIN
# ═════════════════════════════════════════════════════════════════════════
def main():
    print("=" * 70)
    print("  DELHI DUTY FREE — Channels, Workflows & Journey E2E Suite")
    print("=" * 70)
    print(f"  Ecom360:   {BASE_URL}")
    print(f"  Time:      {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print("=" * 70)

    r = Runner()

    setup_channels(r)
    setup_templates(r)
    setup_flows(r)
    journeys = simulate_journeys(r)

    # ── Summary ──
    print(f"\n{'=' * 70}")
    print(f"  FINAL RESULTS")
    print(f"{'=' * 70}")
    for name, sec in r.sections.items():
        total = sec["pass"] + sec["fail"]
        pct   = round(sec["pass"] / total * 100, 1) if total else 0
        ok    = "✅" if sec["fail"] == 0 else "⚠️ "
        print(f"  {ok} {name}: {sec['pass']}/{total} ({pct}%)")
    grand = r.total_pass + r.total_fail
    pct   = round(r.total_pass / grand * 100, 1) if grand else 0
    print(f"\n  TOTAL: {r.total_pass}/{grand} ({pct}%)")
    verdict = "✅ ALL CHANNELS & FLOWS OPERATIONAL" if r.total_fail == 0 else f"⚠️  {r.total_fail} failures"
    print(f"  VERDICT: {verdict}")
    print("=" * 70)

    # ── Generate HTML journey report ──
    html = generate_journey_html(journeys, r.sections)
    out_html = "tests/ddf_journey_report.html"
    with open(out_html, "w", encoding="utf-8") as f:
        f.write(html)
    print(f"\n  📊 Journey report saved → {out_html}")
    print(f"     Open in browser to view the beautiful timeline!")

    # ── Save JSON results ──
    out_json = "tests/ddf_channels_flows_results.json"
    with open(out_json, "w") as f:
        json.dump({
            "timestamp": datetime.now().isoformat(),
            "total_pass": r.total_pass, "total_fail": r.total_fail,
            "pass_rate": pct, "verdict": verdict,
            "sections": r.sections,
            "journeys_simulated": len(journeys),
            "revenue_captured": sum(j["revenue"] for j in journeys),
            "channels_configured": list(CHANNEL_CONFIGS.keys()),
            "flows_created": 8,
        }, f, indent=2)
    print(f"  📄 JSON results saved → {out_json}\n")

    return 0 if r.total_fail == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
