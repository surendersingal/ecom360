#!/usr/bin/env python3
"""
══════════════════════════════════════════════════════════════════════════════
  DELHI DUTY FREE — AI Search & Chatbot Simulation (100 Customers)
══════════════════════════════════════════════════════════════════════════════

Tests the actual AI Search and Chatbot module APIs as real customers would
use them on the delhidutyfree.co.in Magento frontend.

Search API Tests:
  • /api/v1/search/search?q=...     — Product search (keyword queries)
  • /api/v1/search/suggest?q=...    — Autocomplete suggestions
  • /api/v1/search/trending         — Trending search queries
  • /api/v1/search/similar/{id}     — Similar product recommendations

Chatbot API Tests:
  • /api/v1/chatbot/send            — Chat messages (product questions, help)
  • /api/v1/chatbot/rage-click      — Rage click reports
  • /api/v1/chatbot/widget-config   — Widget configuration
  • /api/v1/chatbot/form-submit     — Lead capture forms
  • /api/v1/chatbot/advanced/*      — Advanced features (order tracking, etc.)

Each customer has a unique session, realistic queries, and varied interaction
patterns across arrival/departure stores.
"""

import json
import random
import time
import sys
import os
from datetime import datetime

try:
    import requests
    HAS_REQUESTS = True
except ImportError:
    HAS_REQUESTS = False
    print("WARNING: requests library not found. Install with: pip3 install requests")
    sys.exit(1)

# ─────────────────────────────── Config ───────────────────────────────────

BASE_URL = "https://ecom.buildnetic.com"
API_KEY  = "ek_Z3KoaSngu5edQ9exUKCDSYaMqAm7xomX"

HEADERS = {
    "X-Ecom360-Key": API_KEY,
    "Content-Type": "application/json",
    "Accept": "application/json",
}

# Load customer data from previous simulation
RESULTS_FILE = os.path.join(os.path.dirname(__file__), "ddf_100_customers_results.json")

# ─────────────────────────────── Search Queries ───────────────────────────

# Realistic DDF search queries per category — what real customers type
SEARCH_QUERIES = {
    "liquor_generic": [
        "whisky", "vodka", "rum", "gin", "wine", "champagne", "tequila",
        "cognac", "brandy", "beer", "spirits", "liquor", "alcohol",
    ],
    "liquor_brand": [
        "johnnie walker", "glenfiddich", "macallan", "jack daniels",
        "chivas regal", "absolut", "grey goose", "bacardi",
        "hennessy", "bombay sapphire", "hendricks", "patron",
        "moet chandon", "lagavulin", "amrut", "belvedere",
    ],
    "liquor_specific": [
        "johnnie walker blue label", "glenfiddich 18", "macallan 12",
        "hennessy xo", "chivas 18 year", "grey goose 1 litre",
        "jack daniels 1l", "lagavulin 16", "amrut fusion",
        "moet imperial", "patron silver",
    ],
    "perfume": [
        "perfume", "fragrance", "cologne", "dior sauvage",
        "chanel no 5", "tom ford", "versace eros", "hugo boss",
        "jo malone", "carolina herrera good girl", "davidoff cool water",
        "perfume for men", "perfume for women", "gift set perfume",
        "travel size perfume", "unisex perfume",
    ],
    "cosmetics": [
        "lipstick", "mac", "estee lauder", "skincare", "moisturizer",
        "clinique", "loreal", "serum", "night cream", "makeup",
        "foundation", "eye cream", "anti aging",
    ],
    "chocolate": [
        "chocolate", "toblerone", "godiva", "lindt", "ferrero rocher",
        "cadbury", "gift box chocolate", "premium chocolate",
        "chocolate gift", "swiss chocolate",
    ],
    "electronics": [
        "headphones", "airpods", "sony", "jbl speaker", "smartwatch",
        "samsung watch", "bluetooth speaker", "wireless earbuds",
        "power bank", "travel adapter",
    ],
    "watches_accessories": [
        "watch", "tissot", "sunglasses", "ray ban", "wallet",
        "michael kors", "luxury watch", "aviator sunglasses",
    ],
    "food": [
        "tea", "coffee", "saffron", "spices", "dry fruits",
        "twg tea", "kashmir saffron",
    ],
    "tobacco": [
        "cigarettes", "marlboro", "cigars", "davidoff cigars",
    ],
    "misspelled": [
        "wiskey", "parfume", "choclate", "headfones", "lipstik",
        "johny walker", "glenfidich", "macalen", "fererro",
    ],
    "intent_based": [
        "gift for wife", "gift for husband", "gift under 5000",
        "best whisky under 10000", "cheap perfume",
        "premium gift set", "what to buy duty free",
        "travel exclusive", "duty free deals",
        "best seller", "top rated", "new arrivals",
    ],
    "limit_related": [
        "products under 25000", "arrival limit products",
        "departure bestsellers", "2 litre whisky",
        "5 litre liquor combo",
    ],
}

# ─────────────────────────────── Chatbot Messages ─────────────────────────

CHATBOT_CONVERSATIONS = {
    "product_inquiry": [
        "What whisky do you recommend under 5000?",
        "Do you have Johnnie Walker Blue Label?",
        "What's the price of Dior Sauvage?",
        "Which perfume is best for gifting?",
        "Do you have any chocolate gift boxes?",
        "Is Macallan 12 available at arrival?",
        "What electronics do you sell?",
        "Do you have Sony headphones?",
        "Any new arrivals in perfumes?",
        "What's your best selling whisky?",
    ],
    "shopping_limits": [
        "What is the shopping limit for arrival?",
        "How much liquor can I buy?",
        "Can I add my wife's passport to increase limit?",
        "What's the departure shopping limit?",
        "I have 3 passports, what's my total limit?",
        "Can I buy 5 litres of whisky on arrival?",
        "Is the 25000 limit per person or per passport?",
        "What happens if I exceed the liquor limit?",
        "Do kids' passports count for shopping limit?",
    ],
    "collection_help": [
        "Where do I collect my order?",
        "What is the collection time for Terminal 3?",
        "Can I collect my order at a different terminal?",
        "How long after landing can I collect?",
        "I missed my collection slot, what do I do?",
        "Can someone else collect my order?",
        "Where is the DDF counter at T3 arrival?",
    ],
    "order_tracking": [
        "Where is my order DDF-T3-456789?",
        "I placed an order but haven't received confirmation",
        "Can I cancel my order?",
        "Can I modify my order after payment?",
        "My payment failed but money was deducted",
        "I want to add more items to my existing order",
    ],
    "general_help": [
        "What payment methods do you accept?",
        "Do you accept foreign currency?",
        "Is there a return policy?",
        "Do you ship internationally?",
        "How do I create an account?",
        "I forgot my password",
        "Do you have a loyalty program?",
        "Are prices in rupees?",
        "Do you offer gift wrapping?",
        "Is there a customer helpline?",
    ],
    "complaints": [
        "The website is very slow",
        "I can't add items to my cart",
        "The payment page keeps crashing",
        "I was charged twice for the same order",
        "The product I received was different from what I ordered",
        "Your checkout process is very confusing",
    ],
    "comparison": [
        "What's the difference between Johnnie Walker Black and Gold?",
        "Chivas 12 vs Chivas 18, which is better value?",
        "Grey Goose vs Belvedere, which should I buy?",
        "Is MAC lipstick cheaper here than in the city?",
        "Are duty free prices really cheaper?",
    ],
    "edge_cases": [
        "",  # empty message
        "a",  # single character
        "🎉🎊🎁",  # emoji only
        "Hello " * 50,  # very long message
        "Can I buy <script>alert('xss')</script>?",  # XSS attempt
        "SELECT * FROM products;",  # SQL injection attempt
    ],
}

# ─────────────────────────────── Customer Profiles ────────────────────────

# Generate 100 realistic customer profiles for search/chatbot
def generate_customers():
    """Generate 100 customer profiles with search/chat intent."""
    customers = []

    stores = [
        {"id": "arrival-t3", "type": "arrival", "terminal": "T3"},
        {"id": "arrival-t2", "type": "arrival", "terminal": "T2"},
        {"id": "departure-t3", "type": "departure", "terminal": "T3"},
        {"id": "departure-t2", "type": "departure", "terminal": "T2"},
        {"id": "departure-t1d", "type": "departure", "terminal": "T1D"},
    ]

    personas = [
        {"name": "whisky_hunter", "weight": 15,
         "search_cats": ["liquor_brand", "liquor_specific", "liquor_generic"],
         "chat_topics": ["product_inquiry", "shopping_limits", "comparison"],
         "searches": (3, 8), "chats": (1, 4)},

        {"name": "perfume_buyer", "weight": 12,
         "search_cats": ["perfume"],
         "chat_topics": ["product_inquiry", "general_help"],
         "searches": (2, 5), "chats": (1, 3)},

        {"name": "gift_shopper", "weight": 10,
         "search_cats": ["intent_based", "chocolate", "perfume", "watches_accessories"],
         "chat_topics": ["product_inquiry", "comparison", "general_help"],
         "searches": (4, 10), "chats": (2, 5)},

        {"name": "confused_first_timer", "weight": 10,
         "search_cats": ["liquor_generic", "misspelled", "intent_based", "limit_related"],
         "chat_topics": ["shopping_limits", "collection_help", "general_help"],
         "searches": (5, 12), "chats": (3, 7)},

        {"name": "tech_shopper", "weight": 8,
         "search_cats": ["electronics"],
         "chat_topics": ["product_inquiry", "comparison"],
         "searches": (3, 6), "chats": (1, 2)},

        {"name": "bulk_liquor_buyer", "weight": 8,
         "search_cats": ["liquor_brand", "liquor_specific", "limit_related"],
         "chat_topics": ["shopping_limits", "product_inquiry"],
         "searches": (2, 5), "chats": (2, 4)},

        {"name": "cosmetics_lover", "weight": 7,
         "search_cats": ["cosmetics", "perfume"],
         "chat_topics": ["product_inquiry"],
         "searches": (3, 7), "chats": (0, 2)},

        {"name": "quick_buyer", "weight": 8,
         "search_cats": ["liquor_brand", "chocolate"],
         "chat_topics": [],
         "searches": (1, 2), "chats": (0, 0)},

        {"name": "comparison_researcher", "weight": 7,
         "search_cats": ["liquor_brand", "liquor_specific", "perfume", "electronics"],
         "chat_topics": ["comparison", "product_inquiry"],
         "searches": (6, 15), "chats": (2, 4)},

        {"name": "problem_customer", "weight": 5,
         "search_cats": ["misspelled", "intent_based"],
         "chat_topics": ["complaints", "order_tracking", "edge_cases"],
         "searches": (2, 5), "chats": (3, 8)},

        {"name": "food_specialist", "weight": 5,
         "search_cats": ["food", "chocolate"],
         "chat_topics": ["product_inquiry", "general_help"],
         "searches": (2, 4), "chats": (1, 2)},

        {"name": "tobacco_buyer", "weight": 5,
         "search_cats": ["tobacco", "liquor_generic"],
         "chat_topics": ["shopping_limits"],
         "searches": (1, 3), "chats": (0, 1)},
    ]

    # Build weighted pool
    pool = []
    for p in personas:
        pool.extend([p] * p["weight"])

    for i in range(1, 101):
        persona = random.choice(pool)
        store = random.choice(stores)
        session_id = f"ddf_sc_{store['type'][:3]}_{i:04d}_{random.randint(1000,9999)}"

        n_searches = random.randint(*persona["searches"])
        n_chats = random.randint(*persona["chats"]) if persona["chat_topics"] else 0

        # Pick search queries
        queries = []
        for _ in range(n_searches):
            cat = random.choice(persona["search_cats"])
            q = random.choice(SEARCH_QUERIES[cat])
            queries.append(q)

        # Pick chat messages
        messages = []
        for _ in range(n_chats):
            topic = random.choice(persona["chat_topics"])
            msg = random.choice(CHATBOT_CONVERSATIONS[topic])
            messages.append({"message": msg, "topic": topic})

        customers.append({
            "id": i,
            "session_id": session_id,
            "persona": persona["name"],
            "store": store,
            "queries": queries,
            "messages": messages,
        })

    return customers


# ══════════════════════════════════════════════════════════════════════════
#  TEST RUNNER
# ══════════════════════════════════════════════════════════════════════════

class TestRunner:
    def __init__(self):
        self.results = {
            "search": {"total": 0, "pass": 0, "fail": 0, "errors": []},
            "suggest": {"total": 0, "pass": 0, "fail": 0, "errors": []},
            "trending": {"total": 0, "pass": 0, "fail": 0, "errors": []},
            "similar": {"total": 0, "pass": 0, "fail": 0, "errors": []},
            "chatbot_send": {"total": 0, "pass": 0, "fail": 0, "errors": []},
            "chatbot_rage": {"total": 0, "pass": 0, "fail": 0, "errors": []},
            "chatbot_form": {"total": 0, "pass": 0, "fail": 0, "errors": []},
            "chatbot_widget": {"total": 0, "pass": 0, "fail": 0, "errors": []},
            "chatbot_advanced": {"total": 0, "pass": 0, "fail": 0, "errors": []},
        }
        self.search_results_data = []  # Store search results for analysis
        self.chat_responses = []       # Store chat responses for analysis

    def _call(self, method, path, data=None, key="search"):
        """Make API call and track result."""
        self.results[key]["total"] += 1
        try:
            if method == "GET":
                r = requests.get(f"{BASE_URL}{path}", headers=HEADERS, timeout=15)
            else:
                r = requests.post(f"{BASE_URL}{path}", json=data, headers=HEADERS, timeout=15)

            if r.status_code in (200, 201, 207):
                self.results[key]["pass"] += 1
                return r.json()
            else:
                self.results[key]["fail"] += 1
                body = r.json() if r.headers.get("content-type", "").startswith("application/json") else {}
                msg = body.get("message", r.text[:100])
                self.results[key]["errors"].append({
                    "path": path,
                    "status": r.status_code,
                    "message": msg,
                    "data": data,
                })
                return None
        except Exception as e:
            self.results[key]["fail"] += 1
            self.results[key]["errors"].append({
                "path": path,
                "error": str(e),
                "data": data,
            })
            return None

    # ──────────── Search Tests ────────────

    def test_search(self, query):
        """Test search endpoint with a query."""
        result = self._call("GET", f"/api/v1/search/search?q={requests.utils.quote(query)}", key="search")
        if result:
            data = result.get("data", result.get("results", []))
            self.search_results_data.append({
                "query": query,
                "results_count": len(data) if isinstance(data, list) else 0,
                "has_results": bool(data),
            })
        return result

    def test_suggest(self, partial_query):
        """Test suggest endpoint with partial query."""
        return self._call("GET", f"/api/v1/search/suggest?q={requests.utils.quote(partial_query)}", key="suggest")

    def test_trending(self):
        """Test trending endpoint."""
        return self._call("GET", "/api/v1/search/trending", key="trending")

    def test_similar(self, product_id):
        """Test similar products endpoint."""
        return self._call("GET", f"/api/v1/search/similar/{product_id}", key="similar")

    # ──────────── Chatbot Tests ────────────

    def test_chatbot_send(self, session_id, message, product_id=None):
        """Test chatbot send message."""
        payload = {
            "session_id": session_id,
            "message": message,
        }
        if product_id:
            payload["product_id"] = str(product_id)

        result = self._call("POST", "/api/v1/chatbot/send", payload, key="chatbot_send")
        if result:
            self.chat_responses.append({
                "message": message[:80],
                "has_response": bool(result.get("data", {}).get("response", result.get("data", {}).get("message", ""))),
                "conversation_id": result.get("data", {}).get("conversation_id", ""),
            })
        return result

    def test_rage_click(self, session_id, url, element):
        """Test rage click reporting."""
        return self._call("POST", "/api/v1/chatbot/rage-click", {
            "session_id": session_id,
            "url": url,
            "element": element,
            "click_count": random.randint(5, 15),
        }, key="chatbot_rage")

    def test_form_submit(self, session_id, email, name):
        """Test form submission (lead capture)."""
        return self._call("POST", "/api/v1/chatbot/form-submit", {
            "session_id": session_id,
            "form_data": {
                "email": email,
                "name": name,
                "phone": f"+91{random.randint(7000000000, 9999999999)}",
                "interest": random.choice(["whisky", "perfume", "electronics", "gifting"]),
            },
            "form_type": random.choice(["lead_capture", "newsletter", "feedback"]),
        }, key="chatbot_form")

    def test_widget_config(self):
        """Test widget config endpoint."""
        return self._call("GET", "/api/v1/chatbot/widget-config", key="chatbot_widget")

    def test_order_tracking(self, session_id, order_id):
        """Test advanced order tracking."""
        return self._call("POST", "/api/v1/chatbot/advanced/order-tracking", {
            "session_id": session_id,
            "order_id": order_id,
        }, key="chatbot_advanced")

    def test_objection_handler(self, session_id, objection):
        """Test objection handler."""
        return self._call("POST", "/api/v1/chatbot/advanced/objection-handler", {
            "session_id": session_id,
            "objection": objection,
            "product_id": random.choice(["WH001", "PF001", "EL001"]),
            "context": "product_page",
        }, key="chatbot_advanced")


# ══════════════════════════════════════════════════════════════════════════
#  MAIN
# ══════════════════════════════════════════════════════════════════════════

def main():
    print("=" * 72)
    print("  DELHI DUTY FREE — AI Search & Chatbot Test (100 Customers)")
    print("=" * 72)
    print(f"  Target: {BASE_URL}")
    print(f"  Time:   {datetime.now().isoformat()}")
    print("=" * 72)
    print()

    customers = generate_customers()
    runner = TestRunner()

    # ── Phase 0: Global endpoints ──
    print("Phase 0: Testing global endpoints...")
    runner.test_trending()
    runner.test_widget_config()
    # Test similar for a few product IDs
    for pid in ["WH001", "PF001", "CH001", "EL001", "WT001"]:
        runner.test_similar(pid)
    print(f"  Trending: {runner.results['trending']['pass']}/{runner.results['trending']['total']}")
    print(f"  Widget config: {runner.results['chatbot_widget']['pass']}/{runner.results['chatbot_widget']['total']}")
    print(f"  Similar products: {runner.results['similar']['pass']}/{runner.results['similar']['total']}")
    print()

    # ── Phase 1: Search queries from all 100 customers ──
    print("Phase 1: Running search queries for 100 customers...")
    total_queries = 0
    for c in customers:
        for q in c["queries"]:
            runner.test_search(q)
            total_queries += 1

            # Also test suggest with first 2-3 chars of each query
            if len(q) >= 2:
                runner.test_suggest(q[:random.randint(2, min(5, len(q)))])

        # Progress indicator
        if c["id"] % 20 == 0:
            print(f"  ... {c['id']}/100 customers ({total_queries} search queries)")

    print(f"  Total search queries: {runner.results['search']['total']}")
    print(f"  Total suggest queries: {runner.results['suggest']['total']}")
    print()

    # ── Phase 2: Chatbot conversations from all 100 customers ──
    print("Phase 2: Running chatbot conversations for 100 customers...")
    total_chats = 0
    emails = [f"ddf_cust_{i}@example.com" for i in range(1, 101)]
    names = ["Rahul", "Priya", "Amit", "Neha", "Vikram", "Anjali", "Rohit",
             "Kavita", "Arjun", "Sneha", "James", "Sarah", "Mohammed", "Li"]

    for c in customers:
        for msg in c["messages"]:
            # Send chat message
            product_id = random.choice([None, "WH001", "PF002", "CH001", "EL001"])
            runner.test_chatbot_send(c["session_id"], msg["message"], product_id)
            total_chats += 1

        # Some customers submit forms (10%)
        if random.random() < 0.1:
            runner.test_form_submit(
                c["session_id"],
                emails[c["id"] - 1],
                random.choice(names),
            )

        # Some customers rage click (5%)
        if random.random() < 0.05 or c["persona"] == "problem_customer":
            store_url = f"https://www.delhidutyfree.co.in/{c['store']['type']}/{c['store']['terminal'].lower()}"
            runner.test_rage_click(
                c["session_id"],
                f"{store_url}/checkout/cart",
                random.choice(["button.checkout", "#add-to-cart", ".qty-input", ".coupon-apply"]),
            )

        # Problem customers test advanced features
        if c["persona"] == "problem_customer":
            order_id = f"DDF-{c['store']['terminal']}-{random.randint(100000, 999999)}"
            runner.test_order_tracking(c["session_id"], order_id)
            runner.test_objection_handler(
                c["session_id"],
                random.choice([
                    "too expensive",
                    "I can get cheaper in the city",
                    "not sure about the brand",
                    "delivery concerns",
                ]),
            )

        if c["id"] % 20 == 0:
            print(f"  ... {c['id']}/100 customers ({total_chats} chat messages)")

    print(f"  Total chat messages: {runner.results['chatbot_send']['total']}")
    print(f"  Total form submissions: {runner.results['chatbot_form']['total']}")
    print(f"  Total rage clicks: {runner.results['chatbot_rage']['total']}")
    print(f"  Total advanced features: {runner.results['chatbot_advanced']['total']}")
    print()

    # ══════════════════════════════════════════════════════════════════════
    #  RESULTS SUMMARY
    # ══════════════════════════════════════════════════════════════════════

    print("=" * 72)
    print("  RESULTS SUMMARY")
    print("=" * 72)
    print()

    total_pass = 0
    total_fail = 0
    total_tests = 0

    print(f"{'Endpoint':<25} {'Total':>7} {'Pass':>7} {'Fail':>7} {'Rate':>8}")
    print("-" * 60)

    for key in ["search", "suggest", "trending", "similar",
                "chatbot_send", "chatbot_rage", "chatbot_form",
                "chatbot_widget", "chatbot_advanced"]:
        r = runner.results[key]
        rate = f"{r['pass']/r['total']*100:.1f}%" if r["total"] > 0 else "—"
        status = "✅" if r["fail"] == 0 else "❌"
        print(f"  {key:<23} {r['total']:>7} {r['pass']:>7} {r['fail']:>7} {rate:>7} {status}")
        total_pass += r["pass"]
        total_fail += r["fail"]
        total_tests += r["total"]

    print("-" * 60)
    overall_rate = f"{total_pass/total_tests*100:.1f}%" if total_tests > 0 else "—"
    overall_status = "✅" if total_fail == 0 else "❌"
    print(f"  {'TOTAL':<23} {total_tests:>7} {total_pass:>7} {total_fail:>7} {overall_rate:>7} {overall_status}")
    print()

    # ── Search Analysis ──
    print("── SEARCH ANALYSIS ──")
    if runner.search_results_data:
        with_results = sum(1 for s in runner.search_results_data if s["has_results"])
        no_results = sum(1 for s in runner.search_results_data if not s["has_results"])
        avg_results = sum(s["results_count"] for s in runner.search_results_data) / len(runner.search_results_data) if runner.search_results_data else 0
        print(f"  Queries with results:   {with_results}")
        print(f"  Queries with 0 results: {no_results}")
        print(f"  Avg results per query:  {avg_results:.1f}")

        # Show some queries that returned 0 results
        zero_queries = list(set(s["query"] for s in runner.search_results_data if not s["has_results"]))
        if zero_queries:
            print(f"  Zero-result queries:    {', '.join(zero_queries[:10])}")
    print()

    # ── Chat Analysis ──
    print("── CHATBOT ANALYSIS ──")
    if runner.chat_responses:
        with_response = sum(1 for c in runner.chat_responses if c["has_response"])
        unique_convos = len(set(c["conversation_id"] for c in runner.chat_responses if c["conversation_id"]))
        print(f"  Messages with response:  {with_response}/{len(runner.chat_responses)}")
        print(f"  Unique conversations:    {unique_convos}")
    print()

    # ── Errors Detail ──
    all_errors = []
    for key, r in runner.results.items():
        for err in r["errors"]:
            all_errors.append({"endpoint": key, **err})

    if all_errors:
        print("── ERRORS DETAIL ──")
        # Group by error type
        error_types = {}
        for err in all_errors:
            msg = err.get("message", err.get("error", "unknown"))[:60]
            error_types[msg] = error_types.get(msg, 0) + 1

        for msg, count in sorted(error_types.items(), key=lambda x: -x[1]):
            print(f"  [{count}x] {msg}")
        print()

    # ── Per-Persona Breakdown ──
    print("── PER-PERSONA BREAKDOWN ──")
    persona_stats = {}
    for c in customers:
        p = c["persona"]
        if p not in persona_stats:
            persona_stats[p] = {"count": 0, "searches": 0, "chats": 0}
        persona_stats[p]["count"] += 1
        persona_stats[p]["searches"] += len(c["queries"])
        persona_stats[p]["chats"] += len(c["messages"])

    print(f"  {'Persona':<28} {'Count':>6} {'Searches':>9} {'Chats':>7}")
    print(f"  {'-'*55}")
    for p, s in sorted(persona_stats.items(), key=lambda x: -x[1]["count"]):
        print(f"  {p:<28} {s['count']:>6} {s['searches']:>9} {s['chats']:>7}")
    print()

    # ── Save Results ──
    report = {
        "simulation_time": datetime.now().isoformat(),
        "target": BASE_URL,
        "total_tests": total_tests,
        "total_pass": total_pass,
        "total_fail": total_fail,
        "pass_rate": f"{total_pass/total_tests*100:.1f}%" if total_tests else "0%",
        "results": {k: {**v, "errors": v["errors"][:5]} for k, v in runner.results.items()},
        "search_analysis": {
            "total_queries": len(runner.search_results_data),
            "with_results": sum(1 for s in runner.search_results_data if s["has_results"]),
            "zero_results": sum(1 for s in runner.search_results_data if not s["has_results"]),
            "zero_result_queries": list(set(s["query"] for s in runner.search_results_data if not s["has_results"]))[:20],
        },
        "chat_analysis": {
            "total_messages": len(runner.chat_responses),
            "with_response": sum(1 for c in runner.chat_responses if c["has_response"]),
            "unique_conversations": len(set(c["conversation_id"] for c in runner.chat_responses if c["conversation_id"])),
        },
        "persona_stats": persona_stats,
        "errors": all_errors[:20],
    }

    report_path = os.path.join(os.path.dirname(__file__), "ddf_search_chatbot_results.json")
    with open(report_path, "w") as f:
        json.dump(report, f, indent=2, default=str)

    print(f"  Report saved: {report_path}")
    print("=" * 72)

    return 0 if total_fail == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
