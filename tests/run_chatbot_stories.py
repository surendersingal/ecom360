#!/usr/bin/env python3
"""
ECOM360 AI CHATBOT — User Story Test Runner
Tests 80 chatbot user stories across 15 groups against the live staging API.
Verifies intent detection, response quality, escalation triggers, and edge cases.
"""
import json, time, sys, os
from datetime import datetime
from urllib.request import Request, urlopen
from urllib.error import HTTPError, URLError

# ── Configuration ──
API_BASE = "https://ecom.buildnetic.com/api/v1"
API_KEY = "ek_Z3KoaSngu5edQ9exUKCDSYaMqAm7xomX"
HEADERS = {
    "Content-Type": "application/json",
    "Accept": "application/json",
    "X-Ecom360-Key": API_KEY,
}

# ── Results storage ──
results = []
group_stats = {}

# ── Helpers ──
def api_post(endpoint, data, extra_headers=None):
    url = f"{API_BASE}/{endpoint}"
    hdrs = dict(HEADERS)
    if extra_headers:
        hdrs.update(extra_headers)
    req = Request(url, data=json.dumps(data).encode(), headers=hdrs, method="POST")
    try:
        with urlopen(req, timeout=30) as resp:
            body = json.loads(resp.read().decode())
            return resp.status, body, None
    except HTTPError as e:
        try:
            body = json.loads(e.read().decode())
        except Exception:
            body = {}
        return e.code, body, str(e)
    except Exception as e:
        return 0, {}, str(e)

def chat(message, session_id, retries=3):
    """Send a message to chatbot and return (status, intent, response_text, data, ms)."""
    for attempt in range(retries + 1):
        t0 = time.time()
        code, body, err = api_post("chatbot/send", {"message": message, "session_id": session_id})
        ms = int((time.time() - t0) * 1000)
        if code == 429 and attempt < retries:
            wait = 15 * (attempt + 1)
            print(f"      ⏳ Rate limited, waiting {wait}s (retry {attempt+1}/{retries})...")
            time.sleep(wait)
            continue
        data = body.get("data", {}) if body else {}
        intent = data.get("intent", "")
        msg = data.get("message", "")
        return code, intent, msg, data, ms
    return 0, "", "", {}, 0

def rage_click(session_id, element="button.add-to-cart", page_url="https://stagingddf.gmraerodutyfree.in/product/test", click_count=7, retries=3):
    """Trigger rage-click detection."""
    for attempt in range(retries + 1):
        t0 = time.time()
        code, body, err = api_post("chatbot/rage-click", {
            "session_id": session_id,
            "element": element,
            "page_url": page_url,
            "click_count": click_count,
        })
        ms = int((time.time() - t0) * 1000)
        if code == 429 and attempt < retries:
            wait = 15 * (attempt + 1)
            print(f"      ⏳ Rate limited, waiting {wait}s (retry {attempt+1}/{retries})...")
            time.sleep(wait)
            continue
        data = body.get("data", {}) if body else {}
        return code, data, ms
    return 0, {}, 0

def record(story_id, group, message_preview, status, intent, response_preview, checks, ms):
    """Record a test result."""
    icon = {"PASS": "✅", "WARN": "⚠️", "FAIL": "❌", "SKIP": "⏭️"}.get(status, "❓")
    checks_str = " | ".join(checks) if checks else ""
    print(f"  {icon} Story {story_id}: {message_preview[:60]}")
    print(f"      Intent: {intent:<25} Status: {status:<6} ({ms}ms)")
    if response_preview:
        # Truncate long responses
        rp = response_preview.replace('\n', ' ')[:120]
        print(f"      Response: {rp}")
    if checks_str:
        print(f"      Checks: {checks_str}")
    print()

    results.append({
        "story_id": story_id,
        "group": group,
        "message": message_preview,
        "status": status,
        "intent": intent,
        "response": response_preview[:300] if response_preview else "",
        "checks": checks,
        "response_time_ms": ms,
    })

    # Track group stats
    if group not in group_stats:
        group_stats[group] = {"pass": 0, "warn": 0, "fail": 0, "skip": 0, "total": 0}
    group_stats[group]["total"] += 1
    group_stats[group][status.lower()] += 1


def evaluate(story_id, group, message, session_id, expected_intents, required_keywords=None, 
             must_not_contain=None, expect_escalation=False, expect_products=False, expect_error=False):
    """Send message, evaluate response against criteria, record result."""
    time.sleep(1)  # Pace requests to avoid rate limiting
    code, intent, resp, data, ms = chat(message, session_id)
    
    checks = []
    passed = True
    
    if code != 200:
        if expect_error:
            checks.append(f"✓ Expected error, got HTTP {code}")
        else:
            record(story_id, group, message, "FAIL", f"HTTP {code}", resp, [f"✗ HTTP {code}"], ms)
            return
    
    # Check intent
    if expected_intents:
        if intent in expected_intents:
            checks.append(f"✓ Intent: {intent}")
        else:
            checks.append(f"~ Intent: {intent} (expected: {'/'.join(expected_intents)})")
            passed = False
    
    # Check response not empty
    if resp:
        checks.append(f"✓ Response: {len(resp)} chars")
    else:
        checks.append("✗ Empty response")
        passed = False
    
    # Check for required keywords in response
    if required_keywords:
        resp_lower = resp.lower()
        for kw in required_keywords:
            if kw.lower() in resp_lower:
                checks.append(f"✓ Contains: '{kw}'")
            else:
                checks.append(f"~ Missing: '{kw}'")
    
    # Check must-not-contain
    if must_not_contain:
        resp_lower = resp.lower()
        for bad in must_not_contain:
            if bad.lower() in resp_lower:
                checks.append(f"✗ Should not contain: '{bad}'")
                passed = False
    
    # Check escalation
    if expect_escalation:
        esc = data.get("escalation") or data.get("escalated") or data.get("needs_human") or data.get("handoff")
        if esc:
            checks.append("✓ Escalation triggered")
        elif intent in ("escalation", "human_agent", "agent_handoff"):
            checks.append("✓ Escalation intent detected")
        elif any(w in resp.lower() for w in ["connect you", "human agent", "transfer", "team member", "escalat", "real person", "customer service", "support team"]):
            checks.append("✓ Escalation mentioned in response")
        else:
            checks.append("~ No escalation signal found")
    
    # Check products in response
    if expect_products:
        products = data.get("products", [])
        if products and len(products) > 0:
            checks.append(f"✓ Products returned: {len(products)}")
        elif any(w in resp.lower() for w in ["product", "recommend", "found", "here are", "option", "suggest"]):
            checks.append("✓ Product-related response")
        else:
            checks.append("~ No products in response")
    
    status = "PASS" if passed else "WARN"
    record(story_id, group, message, status, intent, resp, checks, ms)


def section(title):
    print(f"\n{'='*70}")
    print(f"  {title}")
    print(f"{'='*70}\n")


# ═══════════════════════════════════════════════════════════════════════
# MAIN TEST EXECUTION
# ═══════════════════════════════════════════════════════════════════════

def main():
    print("╔══════════════════════════════════════════════════════════════╗")
    print("║   ECOM360 AI CHATBOT — USER STORY TEST RUNNER             ║")
    print(f"║   Target: ecom.buildnetic.com                             ║")
    print(f"║   Date: {datetime.utcnow().strftime('%Y-%m-%d %H:%M')} UTC                          ║")
    print(f"║   Stories: 80 across 15 groups                            ║")
    print("╚══════════════════════════════════════════════════════════════╝")

    # ──────────────────────────────────────────────────────────────────
    # GROUP 1: ORDER TRACKING & STATUS
    # ──────────────────────────────────────────────────────────────────
    grp = "G01: Order Tracking"
    section(f"GROUP 1: ORDER TRACKING & STATUS")

    evaluate(1, grp,
        "I placed an order yesterday. My order number is #10045. Can you tell me where my order is right now?",
        "qa_story_01",
        expected_intents=["order_tracking", "order_status", "order_inquiry"],
        required_keywords=["order"])

    evaluate(2, grp,
        "I ordered 3 items last week but only received 2. Order #10087. What happened to the third item?",
        "qa_story_02",
        expected_intents=["order_tracking", "order_status", "order_inquiry", "missing_item"],
        required_keywords=["order"])

    evaluate(3, grp,
        "My order says delivered but I never received it. Order #10023. Can you help?",
        "qa_story_03",
        expected_intents=["order_tracking", "order_status", "delivery_issue"],
        required_keywords=["order"])

    evaluate(4, grp,
        "I placed two orders this week — #10091 and #10094. Can you give me the status of both?",
        "qa_story_04",
        expected_intents=["order_tracking", "order_status", "order_inquiry"],
        required_keywords=["order"])

    evaluate(5, grp,
        "How long will my order take to arrive? I ordered standard shipping 4 days ago. Order #10056.",
        "qa_story_05",
        expected_intents=["order_tracking", "shipping_inquiry", "order_status", "delivery_estimate"],
        required_keywords=["order"])

    time.sleep(1)  # Pace requests

    # ──────────────────────────────────────────────────────────────────
    # GROUP 2: RETURNS & REFUNDS
    # ──────────────────────────────────────────────────────────────────
    grp = "G02: Returns & Refunds"
    section(f"GROUP 2: RETURNS & REFUNDS")

    evaluate(6, grp,
        "I received the wrong product. I ordered a blue shirt size L but got a red one size M. I want to return it. Order #10033.",
        "qa_story_06",
        expected_intents=["return_request", "returns", "wrong_item"],
        required_keywords=["return"])

    evaluate(7, grp,
        "I want to return my headphones. I bought them 5 days ago and they stopped working. What's the return process?",
        "qa_story_07",
        expected_intents=["return_request", "returns", "return_policy", "product_issue"],
        required_keywords=["return"])

    evaluate(8, grp,
        "I already shipped back my return 3 days ago. When will I get my refund? Return ID RT-4421.",
        "qa_story_08",
        expected_intents=["return_request", "refund_status", "returns", "refund"],
        required_keywords=["refund"])

    evaluate(9, grp,
        "The product I received is damaged. The box was crushed. Can I get a replacement or refund? Order #10078.",
        "qa_story_09",
        expected_intents=["return_request", "returns", "damaged_product", "replacement"],
        required_keywords=["return", "refund"])

    evaluate(10, grp,
        "I changed my mind about my purchase. Can I return it even though I opened the packaging? Order #10065.",
        "qa_story_10",
        expected_intents=["return_request", "returns", "return_policy"],
        required_keywords=["return"])

    time.sleep(1)

    # ──────────────────────────────────────────────────────────────────
    # GROUP 3: PRODUCT DISCOVERY & RECOMMENDATIONS
    # ──────────────────────────────────────────────────────────────────
    grp = "G03: Product Discovery"
    section(f"GROUP 3: PRODUCT DISCOVERY & RECOMMENDATIONS")

    evaluate(11, grp,
        "I'm looking for a gift for my wife who loves skincare. Budget is around $80. What do you recommend?",
        "qa_story_11",
        expected_intents=["product_inquiry", "product_search", "recommendation", "gift"],
        expect_products=True)

    evaluate(12, grp,
        "I bought wireless earbuds from you 3 months ago. What accessories would work with them?",
        "qa_story_12",
        expected_intents=["product_inquiry", "product_search", "accessories", "recommendation"])

    evaluate(13, grp,
        "I need running shoes for a half marathon. I pronate slightly. What do you have under $120?",
        "qa_story_13",
        expected_intents=["product_inquiry", "product_search", "recommendation"],
        expect_products=True)

    evaluate(14, grp,
        "Show me your best selling products in the electronics category right now.",
        "qa_story_14",
        expected_intents=["product_inquiry", "product_search", "best_sellers"],
        expect_products=True)

    evaluate(15, grp,
        "I'm looking for something similar to the Sony WH-1000XM5 headphones but cheaper. What do you have?",
        "qa_story_15",
        expected_intents=["product_inquiry", "product_search", "comparison", "alternative"],
        expect_products=True)

    time.sleep(1)

    # ──────────────────────────────────────────────────────────────────
    # GROUP 4: CART & CHECKOUT ASSISTANCE
    # ──────────────────────────────────────────────────────────────────
    grp = "G04: Cart & Checkout"
    section(f"GROUP 4: CART & CHECKOUT ASSISTANCE")

    evaluate(16, grp,
        "I added 5 items to my cart but when I go to checkout the cart shows only 3 items. What happened?",
        "qa_story_16",
        expected_intents=["cart_issue", "add_to_cart", "cart_help", "general", "product_inquiry"])

    evaluate(17, grp,
        "I'm trying to apply a discount code SAVE20 but it's not working. Can you help?",
        "qa_story_17",
        expected_intents=["coupon_inquiry", "coupon", "discount", "promo_code"],
        required_keywords=["coupon", "code", "discount"])

    evaluate(18, grp,
        "I started checkout but got confused at the payment step. Can you walk me through how to complete my order?",
        "qa_story_18",
        expected_intents=["checkout_help", "payment_help", "general", "cart_help"],
        required_keywords=["checkout", "payment"])

    evaluate(19, grp,
        "I want to change the quantity of one item in my cart from 1 to 3 before I checkout. Can you help me do that?",
        "qa_story_19",
        expected_intents=["cart_help", "cart_update", "add_to_cart", "general"])

    evaluate(20, grp,
        "I accidentally added the wrong size to my cart. How do I change it to size XL before placing the order?",
        "qa_story_20",
        expected_intents=["cart_help", "cart_update", "add_to_cart", "general"])

    time.sleep(1)

    # ──────────────────────────────────────────────────────────────────
    # GROUP 5: ACCOUNT & LOGIN ISSUES
    # ──────────────────────────────────────────────────────────────────
    grp = "G05: Account & Login"
    section(f"GROUP 5: ACCOUNT & LOGIN ISSUES")

    evaluate(21, grp,
        "I forgot my password and can't log in. My email is test@gmail.com. Can you help me reset it?",
        "qa_story_21",
        expected_intents=["account_help", "password_reset", "login_issue", "general"],
        required_keywords=["password"])

    evaluate(22, grp,
        "I want to create a new account. What information do I need and how do I do it?",
        "qa_story_22",
        expected_intents=["account_help", "account_creation", "general"],
        required_keywords=["account"])

    evaluate(23, grp,
        "I have two accounts with the same email. Can you merge them into one?",
        "qa_story_23",
        expected_intents=["account_help", "account_merge", "general", "escalation"],
        required_keywords=["account"])

    evaluate(24, grp,
        "I updated my shipping address in my account but my current order still shows the old address. Can you update it?",
        "qa_story_24",
        expected_intents=["order_tracking", "address_update", "shipping_inquiry", "account_help"],
        required_keywords=["address"])

    evaluate(25, grp,
        "I want to delete my account and all my data. How do I do that?",
        "qa_story_25",
        expected_intents=["account_help", "account_deletion", "general"],
        required_keywords=["account"])

    time.sleep(2)  # Pace between groups

    # ──────────────────────────────────────────────────────────────────
    # GROUP 6: SHIPPING & DELIVERY
    # ──────────────────────────────────────────────────────────────────
    grp = "G06: Shipping & Delivery"
    section(f"GROUP 6: SHIPPING & DELIVERY")

    evaluate(26, grp,
        "What shipping options do you offer and how much does each one cost?",
        "qa_story_26",
        expected_intents=["shipping_inquiry", "shipping", "general"],
        required_keywords=["shipping"])

    evaluate(27, grp,
        "Do you ship internationally? I'm in Dubai and want to order.",
        "qa_story_27",
        expected_intents=["shipping_inquiry", "shipping", "international_shipping"],
        required_keywords=["shipping"])

    evaluate(28, grp,
        "I need my order by Friday for a birthday. Today is Tuesday. Which shipping option should I choose?",
        "qa_story_28",
        expected_intents=["shipping_inquiry", "shipping", "delivery_estimate"],
        required_keywords=["shipping"])

    evaluate(29, grp,
        "My tracking number says my package is stuck in transit for 5 days. Can you investigate? Tracking: TRK998821.",
        "qa_story_29",
        expected_intents=["order_tracking", "shipping_inquiry", "delivery_issue"],
        required_keywords=["tracking"])

    evaluate(30, grp,
        "Can I change my delivery address after placing the order? Order #10102 was placed 2 hours ago.",
        "qa_story_30",
        expected_intents=["order_tracking", "address_update", "shipping_inquiry"],
        required_keywords=["address", "order"])

    time.sleep(1)

    # ──────────────────────────────────────────────────────────────────
    # GROUP 7: PAYMENT & BILLING
    # ──────────────────────────────────────────────────────────────────
    grp = "G07: Payment & Billing"
    section(f"GROUP 7: PAYMENT & BILLING")

    evaluate(31, grp,
        "My payment failed but money was deducted from my account. Order #10055. What happened?",
        "qa_story_31",
        expected_intents=["payment_issue", "order_tracking", "billing", "general"],
        required_keywords=["payment"])

    evaluate(32, grp,
        "What payment methods do you accept? Do you have buy now pay later?",
        "qa_story_32",
        expected_intents=["payment_info", "payment_methods", "general"],
        required_keywords=["payment"])

    evaluate(33, grp,
        "I was charged twice for the same order. My order number is #10039. Can you fix this?",
        "qa_story_33",
        expected_intents=["payment_issue", "billing", "order_tracking", "general"],
        required_keywords=["charge"])

    evaluate(34, grp,
        "I want to pay using a different card than the one saved on my account. How do I do that at checkout?",
        "qa_story_34",
        expected_intents=["payment_info", "payment_help", "checkout_help", "general"],
        required_keywords=["payment", "card"])

    evaluate(35, grp,
        "Do you offer EMI or installment payment options for orders above $500?",
        "qa_story_35",
        expected_intents=["payment_info", "payment_methods", "general"],
        required_keywords=["payment"])

    time.sleep(2)  # Pace between groups

    # ──────────────────────────────────────────────────────────────────
    # GROUP 8: PRODUCT INFORMATION
    # ──────────────────────────────────────────────────────────────────
    grp = "G08: Product Info"
    section(f"GROUP 8: PRODUCT INFORMATION")

    evaluate(36, grp,
        "What is the warranty period on your electronics products? Does it cover accidental damage?",
        "qa_story_36",
        expected_intents=["product_inquiry", "warranty", "general"],
        required_keywords=["warranty"])

    evaluate(37, grp,
        "I want to buy the running shoes but I'm between size 9 and 9.5. How does your sizing run — should I size up or down?",
        "qa_story_37",
        expected_intents=["product_inquiry", "sizing", "general"],
        required_keywords=["size"])

    evaluate(38, grp,
        "Is this product compatible with iPhone 15? I want to make sure before I buy.",
        "qa_story_38",
        expected_intents=["product_inquiry", "compatibility", "general"])

    evaluate(39, grp,
        "What's the difference between the Pro version and the Standard version of your headphones? Is it worth the extra $50?",
        "qa_story_39",
        expected_intents=["product_inquiry", "comparison", "product_search"],
        expect_products=True)

    evaluate(40, grp,
        "I'm allergic to latex. Does this product contain any latex or rubber materials?",
        "qa_story_40",
        expected_intents=["product_inquiry", "general"],
        required_keywords=["product"])

    time.sleep(1)

    # ──────────────────────────────────────────────────────────────────
    # GROUP 9: STOCK & AVAILABILITY
    # ──────────────────────────────────────────────────────────────────
    grp = "G09: Stock & Availability"
    section(f"GROUP 9: STOCK & AVAILABILITY")

    evaluate(41, grp,
        "The blue color of this jacket is showing out of stock. When will it be back in stock?",
        "qa_story_41",
        expected_intents=["product_inquiry", "stock_check", "availability"],
        required_keywords=["stock"])

    evaluate(42, grp,
        "Can you notify me when size M of the running shirt is available again?",
        "qa_story_42",
        expected_intents=["product_inquiry", "stock_notification", "availability", "general"],
        required_keywords=["stock", "notify", "available"])

    evaluate(43, grp,
        "I need 10 units of the same product for a corporate order. Do you have that quantity available?",
        "qa_story_43",
        expected_intents=["product_inquiry", "bulk_order", "availability", "general"])

    evaluate(44, grp,
        "I saw a product on your site yesterday but now I can't find it. Was it removed? It was called ProFit Yoga Mat.",
        "qa_story_44",
        expected_intents=["product_inquiry", "product_search", "general"])

    evaluate(45, grp,
        "Do you have any products similar to what I bought before that are currently in stock and on sale?",
        "qa_story_45",
        expected_intents=["product_inquiry", "product_search", "recommendation"],
        expect_products=True)

    time.sleep(2)  # Pace between groups

    # ──────────────────────────────────────────────────────────────────
    # GROUP 10: COMPLAINTS & ESCALATION
    # ──────────────────────────────────────────────────────────────────
    grp = "G10: Complaints & Escalation"
    section(f"GROUP 10: COMPLAINTS & ESCALATION")

    evaluate(46, grp,
        "This is absolutely ridiculous. I've been waiting 2 weeks for my order and nobody is helping me. I want to speak to a real person NOW. Order #10011.",
        "qa_story_46",
        expected_intents=["escalation", "human_agent", "order_tracking", "complaint"],
        expect_escalation=True)

    evaluate(47, grp,
        "I have complained 3 times already about the same issue and nothing has been resolved. This is my last attempt before I post a review. Order #10044.",
        "qa_story_47",
        expected_intents=["escalation", "complaint", "order_tracking", "general"],
        expect_escalation=True)

    evaluate(48, grp,
        "Your website crashed during checkout and I was charged but never received an order confirmation. I have a screenshot. What do I do?",
        "qa_story_48",
        expected_intents=["payment_issue", "checkout_help", "order_tracking", "general"],
        required_keywords=["order"])

    evaluate(49, grp,
        "I received a product that looks like it was used and repackaged. This is not acceptable. I need an immediate resolution. Order #10088.",
        "qa_story_49",
        expected_intents=["return_request", "complaint", "escalation", "general"],
        expect_escalation=True)

    evaluate(50, grp,
        "I'm very frustrated. The chatbot keeps giving me wrong answers and I can't get a human to respond. Who do I escalate to?",
        "qa_story_50",
        expected_intents=["escalation", "human_agent", "complaint"],
        expect_escalation=True)

    time.sleep(1)

    # ──────────────────────────────────────────────────────────────────
    # GROUP 11: PROMOTIONS & LOYALTY
    # ──────────────────────────────────────────────────────────────────
    grp = "G11: Promotions & Loyalty"
    section(f"GROUP 11: PROMOTIONS & LOYALTY")

    evaluate(51, grp,
        "I heard you have a loyalty program. How many points do I have and what can I use them for?",
        "qa_story_51",
        expected_intents=["loyalty", "points", "general", "product_inquiry"],
        required_keywords=["loyalty", "points"])

    evaluate(52, grp,
        "Is there a discount for first-time buyers? I've never ordered from you before.",
        "qa_story_52",
        expected_intents=["coupon_inquiry", "discount", "promotion", "general"],
        required_keywords=["discount"])

    evaluate(53, grp,
        "I referred my friend and he placed an order. When will my referral bonus be credited?",
        "qa_story_53",
        expected_intents=["referral", "loyalty", "general"],
        required_keywords=["referral"])

    evaluate(54, grp,
        "Are there any active promo codes right now? I'm about to place a $200 order.",
        "qa_story_54",
        expected_intents=["coupon_inquiry", "promotion", "discount", "general"],
        required_keywords=["promo", "code", "discount"])

    evaluate(55, grp,
        "I'm a VIP member. Am I eligible for early access to your upcoming sale?",
        "qa_story_55",
        expected_intents=["loyalty", "vip", "promotion", "general"])

    time.sleep(2)  # Pace between groups

    # ──────────────────────────────────────────────────────────────────
    # GROUP 12: COMPLEX & MULTI-INTENT QUERIES
    # ──────────────────────────────────────────────────────────────────
    grp = "G12: Complex Multi-Intent"
    section(f"GROUP 12: COMPLEX & MULTI-INTENT QUERIES")

    evaluate(56, grp,
        "I want to return my order #10031 AND place a new order for the same product in a different color AND also check if I have any loyalty points I can use for the new order.",
        "qa_story_56",
        expected_intents=["return_request", "order_tracking", "product_inquiry", "loyalty", "general"],
        required_keywords=["return"])

    evaluate(57, grp,
        "My last three orders have all had issues — wrong item, late delivery, and damaged product. I want a compensation and a guarantee this won't happen again.",
        "qa_story_57",
        expected_intents=["complaint", "escalation", "return_request", "general"],
        expect_escalation=True)

    evaluate(58, grp,
        "I'm looking for a birthday gift under $100 for a 10-year-old boy who loves technology. It needs to be delivered by this Saturday and I want to use my store credit.",
        "qa_story_58",
        expected_intents=["product_inquiry", "product_search", "recommendation", "gift"],
        expect_products=True)

    evaluate(59, grp,
        "Can you compare the top 3 noise-cancelling headphones you sell, tell me which one has the best reviews, and check if any of them are currently on sale?",
        "qa_story_59",
        expected_intents=["product_inquiry", "comparison", "product_search"],
        expect_products=True)

    evaluate(60, grp,
        "I placed an order 30 minutes ago. I want to cancel it, get a refund, and then reorder the same items but with a different shipping address and faster shipping.",
        "qa_story_60",
        expected_intents=["order_tracking", "order_cancel", "return_request", "general"],
        required_keywords=["order", "cancel"])

    time.sleep(5)  # Extended pause before edge cases

    # ──────────────────────────────────────────────────────────────────
    # GROUP 13: EDGE CASES & STRESS TESTS
    # ──────────────────────────────────────────────────────────────────
    grp = "G13: Edge Cases"
    section(f"GROUP 13: EDGE CASES & STRESS TESTS")

    # Story 61: Gibberish
    evaluate(61, grp,
        "asdfghjkl qwerty random gibberish 12345",
        "qa_story_61",
        expected_intents=["general", "unknown", "fallback", "greeting"],
        must_not_contain=["error", "exception", "traceback"])

    # Story 62: Single character
    evaluate(62, grp,
        ".",
        "qa_story_62",
        expected_intents=["general", "unknown", "fallback", "greeting"],
        must_not_contain=["error", "exception", "traceback"])

    # Story 63: Unreasonable request
    evaluate(63, grp,
        "I want to buy everything in your store",
        "qa_story_63",
        expected_intents=["product_inquiry", "product_search", "general", "add_to_cart"],
        must_not_contain=["error", "exception"])

    # Story 64: Off-topic
    evaluate(64, grp,
        "What is the meaning of life?",
        "qa_story_64",
        expected_intents=["general", "off_topic", "unknown", "greeting"],
        must_not_contain=["error", "exception"])

    # Story 65: Multi-language
    evaluate(65, grp,
        "Can you speak to me in Hindi / Arabic / French?",
        "qa_story_65",
        expected_intents=["general", "language", "greeting"],
        must_not_contain=["error", "exception"])

    # Story 66: Absurd quantity
    evaluate(66, grp,
        "I want to order 10,000 units of your most expensive product for immediate delivery today",
        "qa_story_66",
        expected_intents=["product_inquiry", "bulk_order", "general", "add_to_cart"],
        must_not_contain=["error", "exception"])

    # Story 67: Invalid order number
    evaluate(67, grp,
        "My order number is AAAA-BBBB-CCCC-DDDD",
        "qa_story_67",
        expected_intents=["order_tracking", "general"],
        must_not_contain=["error", "exception", "traceback"])

    # Story 68: Vague message
    evaluate(68, grp,
        "I need help",
        "qa_story_68",
        expected_intents=["general", "help", "greeting"],
        must_not_contain=["error", "exception"])

    # Story 69: Repeated messages (send same message 5 times)
    print("  📋 Story 69: Sending same message 5 times...")
    session_69 = "qa_story_69"
    story69_ok = True
    for i in range(5):
        code, intent, resp, data, ms = chat("Where is my order #10045", session_69)
        if code != 200:
            story69_ok = False
            break
        time.sleep(1.5)
    checks_69 = ["✓ All 5 messages handled"] if story69_ok else ["✗ Failed during repeated messages"]
    record(69, grp, "Where is my order #10045 (x5 repeated)", 
           "PASS" if story69_ok else "FAIL", "order_tracking", 
           "Repeated message handling tested", checks_69, ms)

    # Story 70: Outside return window
    evaluate(70, grp,
        "I want a refund for an order I placed 2 years ago",
        "qa_story_70",
        expected_intents=["return_request", "refund", "general", "order_tracking"],
        required_keywords=["return", "refund", "policy"])

    time.sleep(10)  # Long cooldown after edge cases + repeated messages

    # ──────────────────────────────────────────────────────────────────
    # GROUP 14: HANDOFF & ESCALATION TESTING
    # ──────────────────────────────────────────────────────────────────
    grp = "G14: Handoff & Escalation"
    section(f"GROUP 14: HANDOFF & ESCALATION TESTING")

    evaluate(71, grp,
        "I don't want to talk to a bot. Connect me to a human agent right now.",
        "qa_story_71",
        expected_intents=["escalation", "human_agent", "agent_handoff"],
        expect_escalation=True)

    evaluate(72, grp,
        "The chatbot already answered my question wrong twice. I want a supervisor.",
        "qa_story_72",
        expected_intents=["escalation", "human_agent", "complaint", "general"],
        expect_escalation=True)

    evaluate(73, grp,
        "This is a legal matter. I need to speak with your customer service manager.",
        "qa_story_73",
        expected_intents=["escalation", "human_agent", "legal", "general"],
        expect_escalation=True)

    evaluate(74, grp,
        "I'm a business customer with a bulk order issue. I need to speak to your B2B team.",
        "qa_story_74",
        expected_intents=["escalation", "human_agent", "bulk_order", "general"],
        expect_escalation=True)

    evaluate(75, grp,
        "Can I schedule a callback from your support team instead of chatting?",
        "qa_story_75",
        expected_intents=["escalation", "human_agent", "callback", "general"],
        expect_escalation=True)

    time.sleep(10)  # Long cooldown before proactive tests

    # ──────────────────────────────────────────────────────────────────
    # GROUP 15: PROACTIVE CHATBOT SCENARIOS
    # ──────────────────────────────────────────────────────────────────
    grp = "G15: Proactive Scenarios"
    section(f"GROUP 15: PROACTIVE CHATBOT SCENARIOS")

    # Story 76: Browse without action → proactive offer (simulated via chat)
    evaluate(76, grp,
        "I've been looking at this product for a while but I'm not sure if I should buy it.",
        "qa_story_76",
        expected_intents=["product_inquiry", "general", "recommendation"],
        required_keywords=["help", "product"])

    # Story 77: Checkout hesitation (simulated)
    evaluate(77, grp,
        "I have items in my cart but I'm hesitating to check out. Not sure about the total.",
        "qa_story_77",
        expected_intents=["cart_help", "checkout_help", "general", "add_to_cart", "product_inquiry"],
        required_keywords=["checkout", "cart", "help"])

    # Story 78: Rage-click detection (actual API test)
    print("  📋 Story 78: Testing rage-click detection...")
    code78, data78, ms78 = rage_click("qa_story_78", click_count=8)
    msg78 = data78.get("message", "")
    checks78 = []
    if code78 == 200:
        checks78.append("✓ Rage-click endpoint responded")
        if msg78:
            checks78.append(f"✓ Intervention message: {msg78[:60]}")
        else:
            checks78.append("~ No intervention message")
    else:
        checks78.append(f"✗ HTTP {code78}")
    record(78, grp, "Rage-click detection (8 rapid clicks)", 
           "PASS" if code78 == 200 else "FAIL", "rage_click", msg78, checks78, ms78)

    # Story 79: Cart abandonment return (simulated via chat)
    evaluate(79, grp,
        "Hi, I was shopping yesterday but didn't complete my order. I left some items in my cart.",
        "qa_story_79",
        expected_intents=["greeting", "cart_help", "general", "add_to_cart"],
        required_keywords=["cart"])

    # Story 80: Zero-result search follow-up (simulated)
    evaluate(80, grp,
        "I searched for 'quantum flux capacitor' but nothing came up. Do you have anything similar?",
        "qa_story_80",
        expected_intents=["product_inquiry", "product_search", "general"],
        expect_products=True)

    # ══════════════════════════════════════════════════════════════════
    # FINAL REPORT
    # ══════════════════════════════════════════════════════════════════
    print(f"\n{'='*70}")
    print(f"  CHATBOT USER STORY TEST RESULTS")
    print(f"{'='*70}\n")

    total = len(results)
    passed = sum(1 for r in results if r["status"] == "PASS")
    warned = sum(1 for r in results if r["status"] == "WARN")
    failed = sum(1 for r in results if r["status"] == "FAIL")
    skipped = sum(1 for r in results if r["status"] == "SKIP")

    print(f"📊 SUMMARY")
    print(f"   Total Stories: {total}")
    print(f"   ✅ Passed:   {passed} ({100*passed//total}%)")
    print(f"   ⚠️  Warnings: {warned} ({100*warned//total}%)")
    print(f"   ❌ Failed:   {failed} ({100*failed//total}%)")
    print(f"   ⏭️  Skipped:  {skipped}")

    # Avg response time
    times = [r["response_time_ms"] for r in results if r["response_time_ms"] > 0]
    avg_ms = sum(times) // len(times) if times else 0
    slowest = max(times) if times else 0
    fastest = min(times) if times else 0
    print(f"\n⏱️  PERFORMANCE")
    print(f"   Average: {avg_ms}ms | Fastest: {fastest}ms | Slowest: {slowest}ms")

    print(f"\n📋 GROUP BREAKDOWN:")
    for grp_name in sorted(group_stats.keys()):
        gs = group_stats[grp_name]
        print(f"   {grp_name}: {gs['pass']}/{gs['total']} passed, {gs['warn']} warnings, {gs['fail']} failed")

    # Intent distribution
    intent_counts = {}
    for r in results:
        i = r["intent"] or "none"
        intent_counts[i] = intent_counts.get(i, 0) + 1
    print(f"\n🧠 INTENT DISTRIBUTION:")
    for intent_name, count in sorted(intent_counts.items(), key=lambda x: -x[1]):
        print(f"   {intent_name}: {count} ({100*count//total}%)")

    # Failures
    if failed > 0:
        print(f"\n🔴 FAILURES ({failed}):")
        for r in results:
            if r["status"] == "FAIL":
                print(f"   Story {r['story_id']}: {r['message'][:60]}")
                for c in r["checks"]:
                    print(f"     {c}")

    # Warnings
    if warned > 0:
        print(f"\n🟡 WARNINGS ({warned}):")
        for r in results:
            if r["status"] == "WARN":
                print(f"   Story {r['story_id']}: {r['message'][:60]}")
                for c in r["checks"]:
                    if c.startswith("~") or c.startswith("✗"):
                        print(f"     {c}")

    # Save results
    out_path = os.path.join(os.path.dirname(__file__), "chatbot_story_results.json")
    with open(out_path, "w") as f:
        json.dump({
            "run_date": datetime.utcnow().isoformat(),
            "total": total,
            "passed": passed,
            "warnings": warned,
            "failed": failed,
            "group_stats": group_stats,
            "intent_distribution": intent_counts,
            "results": results,
        }, f, indent=2)
    print(f"\n💾 Full results saved to: {out_path}")


if __name__ == "__main__":
    main()
