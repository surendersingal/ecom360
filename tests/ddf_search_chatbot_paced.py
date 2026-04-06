#!/usr/bin/env python3
"""
══════════════════════════════════════════════════════════════════════════════
  DELHI DUTY FREE — AI Search & Chatbot PACED Simulation (100 Customers)
══════════════════════════════════════════════════════════════════════════════

Same 100-customer test as ddf_search_chatbot_simulation.py but with intelligent
pacing (1-2s delays between requests) to avoid rate limiting (429 errors).

Tests:
  • /api/v1/search/search      — Product search
  • /api/v1/search/suggest     — Autocomplete
  • /api/v1/search/trending    — Trending queries
  • /api/v1/search/similar/:id — Similar products
  • /api/v1/chatbot/send       — Chat messages
  • /api/v1/chatbot/rage-click — Rage click reports
  • /api/v1/chatbot/form-submit— Lead capture
  • /api/v1/chatbot/widget-config — Widget config
  • /api/v1/chatbot/advanced/* — Order tracking, objection handler
"""

import json, random, time, sys, os
from datetime import datetime
from urllib.parse import quote

try:
    import requests
except ImportError:
    print("pip3 install requests"); sys.exit(1)

# ─────────────────────────── Config ──────────────────────────────────────
BASE_URL = "https://ecom.buildnetic.com"
API_KEY  = "ek_Z3KoaSngu5edQ9exUKCDSYaMqAm7xomX"
HEADERS  = {"X-Ecom360-Key": API_KEY, "Content-Type": "application/json", "Accept": "application/json"}

DELAY_MIN = 1.0   # seconds between requests (mimics real user think-time)
DELAY_MAX = 2.5
RETRY_DELAY = 5   # seconds to wait after a 429

# ─────────────────────────── Search Queries ──────────────────────────────
SEARCH_QUERIES = {
    "liquor_generic": ["whisky", "vodka", "rum", "gin", "wine", "champagne", "tequila", "cognac", "brandy"],
    "liquor_brand": ["johnnie walker", "glenfiddich", "macallan", "jack daniels", "chivas regal",
                     "absolut", "grey goose", "bacardi", "hennessy", "bombay sapphire", "hendricks"],
    "liquor_specific": ["johnnie walker blue label", "glenfiddich 18", "macallan 12",
                        "hennessy xo", "chivas 18 year", "lagavulin 16", "amrut fusion"],
    "perfume": ["perfume", "dior sauvage", "chanel no 5", "tom ford", "versace eros",
                "jo malone", "perfume for men", "perfume for women", "travel size perfume"],
    "cosmetics": ["lipstick", "mac", "estee lauder", "skincare", "moisturizer",
                  "clinique", "foundation", "eye cream"],
    "chocolate": ["chocolate", "toblerone", "godiva", "lindt", "ferrero rocher",
                  "gift box chocolate", "premium chocolate"],
    "electronics": ["headphones", "airpods", "jbl speaker", "smartwatch", "wireless earbuds", "power bank"],
    "watches": ["watch", "tissot", "sunglasses", "ray ban", "michael kors"],
    "food": ["tea", "coffee", "saffron", "dry fruits", "twg tea"],
    "tobacco": ["cigarettes", "marlboro", "cigars"],
    "misspelled": ["wiskey", "parfume", "choclate", "headfones", "lipstik",
                   "johny walker", "glenfidich", "macalen"],
    "intent_based": ["gift for wife", "gift under 5000", "best whisky under 10000",
                     "premium gift set", "duty free deals", "best seller", "new arrivals"],
    "limit_related": ["products under 25000", "arrival limit products", "2 litre whisky"],
}

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
    ],
    "collection_help": [
        "Where do I collect my order?",
        "What is the collection time for Terminal 3?",
        "Can I collect my order at a different terminal?",
        "I missed my collection slot, what do I do?",
        "Where is the DDF counter at T3 arrival?",
    ],
    "order_tracking": [
        "Where is my order DDF-T3-456789?",
        "I placed an order but haven't received confirmation",
        "Can I cancel my order?",
        "My payment failed but money was deducted",
    ],
    "general_help": [
        "What payment methods do you accept?",
        "Do you accept foreign currency?",
        "Is there a return policy?",
        "Do you have a loyalty program?",
        "Do you offer gift wrapping?",
    ],
    "complaints": [
        "The website is very slow",
        "I can't add items to my cart",
        "The payment page keeps crashing",
        "I was charged twice for the same order",
    ],
    "comparison": [
        "What's the difference between Johnnie Walker Black and Gold?",
        "Chivas 12 vs Chivas 18, which is better value?",
        "Grey Goose vs Belvedere, which should I buy?",
        "Are duty free prices really cheaper?",
    ],
    "edge_cases": [
        "a",
        "🎉🎊🎁",
        "Can I buy <script>alert('xss')</script>?",
        "SELECT * FROM products;",
        "Hello " * 20,
    ],
}

# ─────────────────────────── Customer Generator ──────────────────────────
def generate_customers():
    stores = [
        {"id": "arrival-t3", "type": "arrival", "terminal": "T3"},
        {"id": "arrival-t2", "type": "arrival", "terminal": "T2"},
        {"id": "departure-t3", "type": "departure", "terminal": "T3"},
        {"id": "departure-t2", "type": "departure", "terminal": "T2"},
        {"id": "departure-t1d", "type": "departure", "terminal": "T1D"},
    ]
    personas = [
        {"name": "whisky_hunter", "weight": 15, "search_cats": ["liquor_brand", "liquor_specific", "liquor_generic"],
         "chat_topics": ["product_inquiry", "shopping_limits", "comparison"], "searches": (3, 8), "chats": (1, 4)},
        {"name": "perfume_buyer", "weight": 12, "search_cats": ["perfume"],
         "chat_topics": ["product_inquiry", "general_help"], "searches": (2, 5), "chats": (1, 3)},
        {"name": "gift_shopper", "weight": 10, "search_cats": ["intent_based", "chocolate", "perfume", "watches"],
         "chat_topics": ["product_inquiry", "comparison", "general_help"], "searches": (4, 10), "chats": (2, 5)},
        {"name": "confused_first_timer", "weight": 10, "search_cats": ["liquor_generic", "misspelled", "intent_based", "limit_related"],
         "chat_topics": ["shopping_limits", "collection_help", "general_help"], "searches": (5, 12), "chats": (3, 7)},
        {"name": "tech_shopper", "weight": 8, "search_cats": ["electronics"],
         "chat_topics": ["product_inquiry", "comparison"], "searches": (3, 6), "chats": (1, 2)},
        {"name": "bulk_liquor_buyer", "weight": 8, "search_cats": ["liquor_brand", "liquor_specific", "limit_related"],
         "chat_topics": ["shopping_limits", "product_inquiry"], "searches": (2, 5), "chats": (2, 4)},
        {"name": "cosmetics_lover", "weight": 7, "search_cats": ["cosmetics", "perfume"],
         "chat_topics": ["product_inquiry"], "searches": (3, 7), "chats": (0, 2)},
        {"name": "quick_buyer", "weight": 8, "search_cats": ["liquor_brand", "chocolate"],
         "chat_topics": [], "searches": (1, 2), "chats": (0, 0)},
        {"name": "comparison_researcher", "weight": 7, "search_cats": ["liquor_brand", "liquor_specific", "perfume", "electronics"],
         "chat_topics": ["comparison", "product_inquiry"], "searches": (6, 15), "chats": (2, 4)},
        {"name": "problem_customer", "weight": 5, "search_cats": ["misspelled", "intent_based"],
         "chat_topics": ["complaints", "order_tracking", "edge_cases"], "searches": (2, 5), "chats": (3, 8)},
        {"name": "food_specialist", "weight": 5, "search_cats": ["food", "chocolate"],
         "chat_topics": ["product_inquiry", "general_help"], "searches": (2, 4), "chats": (1, 2)},
        {"name": "tobacco_buyer", "weight": 5, "search_cats": ["tobacco", "liquor_generic"],
         "chat_topics": ["shopping_limits"], "searches": (1, 3), "chats": (0, 1)},
    ]
    pool = []
    for p in personas:
        pool.extend([p] * p["weight"])

    customers = []
    for i in range(1, 101):
        persona = random.choice(pool)
        store = random.choice(stores)
        session_id = f"ddf_sc_{store['type'][:3]}_{i:04d}_{random.randint(1000,9999)}"
        n_searches = random.randint(*persona["searches"])
        n_chats = random.randint(*persona["chats"]) if persona["chat_topics"] else 0
        queries = [random.choice(SEARCH_QUERIES[random.choice(persona["search_cats"])]) for _ in range(n_searches)]
        messages = []
        for _ in range(n_chats):
            topic = random.choice(persona["chat_topics"])
            messages.append({"message": random.choice(CHATBOT_CONVERSATIONS[topic]), "topic": topic})
        customers.append({"id": i, "session_id": session_id, "persona": persona["name"],
                          "store": store, "queries": queries, "messages": messages})
    return customers


# ─────────────────────────── Test Runner ─────────────────────────────────
class TestRunner:
    def __init__(self):
        self.results = {}
        for key in ["search", "suggest", "trending", "similar",
                     "chatbot_send", "chatbot_rage", "chatbot_form",
                     "chatbot_widget", "chatbot_advanced"]:
            self.results[key] = {"total": 0, "pass": 0, "fail": 0, "errors": [], "samples": []}
        self.request_count = 0
        self.rate_limit_hits = 0

    def _pace(self):
        """Add realistic delay between requests."""
        delay = random.uniform(DELAY_MIN, DELAY_MAX)
        time.sleep(delay)

    def _call(self, method, path, data=None, key="search"):
        self.results[key]["total"] += 1
        self.request_count += 1
        self._pace()

        for attempt in range(3):  # retry on 429
            try:
                if method == "GET":
                    r = requests.get(f"{BASE_URL}{path}", headers=HEADERS, timeout=15)
                else:
                    r = requests.post(f"{BASE_URL}{path}", json=data, headers=HEADERS, timeout=15)

                if r.status_code == 429:
                    self.rate_limit_hits += 1
                    if attempt < 2:
                        time.sleep(RETRY_DELAY * (attempt + 1))
                        continue
                    # Final attempt still 429
                    self.results[key]["fail"] += 1
                    self.results[key]["errors"].append({"path": path, "status": 429, "message": "Too Many Attempts (after retries)"})
                    return None

                if r.status_code in (200, 201, 207):
                    self.results[key]["pass"] += 1
                    resp = r.json()
                    # Store sample (first 3 per endpoint)
                    if len(self.results[key]["samples"]) < 3:
                        self.results[key]["samples"].append({"path": path, "data": data, "response_keys": list(resp.keys()) if isinstance(resp, dict) else "array"})
                    return resp
                else:
                    self.results[key]["fail"] += 1
                    body = r.json() if "json" in r.headers.get("content-type", "") else {}
                    msg = body.get("message", r.text[:200])
                    self.results[key]["errors"].append({"path": path, "status": r.status_code, "message": msg, "data": data})
                    return None
            except Exception as e:
                if attempt < 2:
                    time.sleep(2)
                    continue
                self.results[key]["fail"] += 1
                self.results[key]["errors"].append({"path": path, "error": str(e)})
                return None
        return None


# ══════════════════════════════════════════════════════════════════════════
#  MAIN
# ══════════════════════════════════════════════════════════════════════════
def main():
    start_time = time.time()
    print("=" * 72)
    print("  DELHI DUTY FREE — PACED Search & Chatbot Test (100 Customers)")
    print("=" * 72)
    print(f"  Target:    {BASE_URL}")
    print(f"  Pacing:    {DELAY_MIN}-{DELAY_MAX}s between requests (real user behavior)")
    print(f"  Retries:   Up to 3 attempts on 429, with {RETRY_DELAY}s backoff")
    print(f"  Time:      {datetime.now().isoformat()}")
    print("=" * 72)
    print()

    customers = generate_customers()
    runner = TestRunner()

    # ── Phase 0: Global endpoints (trending, widget config, similar) ──
    print("Phase 0: Global endpoints...")

    # Trending
    runner._pace()
    result = runner._call("GET", "/api/v1/search/trending", key="trending")
    trending_data = result.get("data", []) if result else []
    print(f"  ✓ Trending: {runner.results['trending']['pass']}/{runner.results['trending']['total']}", end="")
    if trending_data:
        print(f"  ({len(trending_data)} trending items)")
    else:
        print()

    # Widget config
    result = runner._call("GET", "/api/v1/chatbot/widget-config", key="chatbot_widget")
    print(f"  ✓ Widget config: {runner.results['chatbot_widget']['pass']}/{runner.results['chatbot_widget']['total']}")

    # Similar products
    for pid in ["1", "2", "3", "5", "10"]:
        runner._call("GET", f"/api/v1/search/similar/{pid}", key="similar")
    print(f"  ✓ Similar: {runner.results['similar']['pass']}/{runner.results['similar']['total']}")
    print()

    # ── Phase 1: Search queries ──
    print("Phase 1: Search queries for 100 customers...")
    search_details = []
    total_q = 0
    for c in customers:
        for q in c["queries"]:
            result = runner._call("GET", f"/api/v1/search/search?q={quote(q)}", key="search")
            total_q += 1
            if result:
                data = result.get("data", result.get("results", []))
                count = len(data) if isinstance(data, list) else 0
                search_details.append({"query": q, "results_count": count, "has_results": count > 0})

            # Suggest (autocomplete) with first few chars
            if len(q) >= 2:
                partial = q[:random.randint(2, min(5, len(q)))]
                runner._call("GET", f"/api/v1/search/suggest?q={quote(partial)}", key="suggest")

        if c["id"] % 10 == 0:
            elapsed = time.time() - start_time
            print(f"  ... {c['id']}/100 customers | {total_q} queries | {runner.rate_limit_hits} rate-limits | {elapsed:.0f}s elapsed")

    print(f"  Total search: {runner.results['search']['total']} | suggest: {runner.results['suggest']['total']}")
    print()

    # ── Phase 2: Chatbot conversations ──
    print("Phase 2: Chatbot conversations for 100 customers...")
    chat_details = []
    total_chats = 0
    names = ["Rahul", "Priya", "Amit", "Neha", "Vikram", "Anjali", "Rohit", "Kavita", "Arjun", "Sneha", "James", "Sarah", "Mohammed"]

    for c in customers:
        for msg in c["messages"]:
            product_id = random.choice([None, "1", "2", "5", "10"])
            payload = {"session_id": c["session_id"], "message": msg["message"]}
            if product_id:
                payload["product_id"] = product_id
            result = runner._call("POST", "/api/v1/chatbot/send", payload, key="chatbot_send")
            total_chats += 1
            if result:
                resp_data = result.get("data", {})
                chat_details.append({
                    "message": msg["message"][:80],
                    "topic": msg["topic"],
                    "has_response": bool(resp_data.get("response", resp_data.get("message", ""))),
                    "conversation_id": resp_data.get("conversation_id", ""),
                })

        # 10% submit forms
        if random.random() < 0.1:
            runner._call("POST", "/api/v1/chatbot/form-submit", {
                "session_id": c["session_id"],
                "form_id": f"form_{random.choice(['lead_capture', 'newsletter', 'feedback'])}_{c['id']}",
                "form_data": {
                    "email": f"ddf_cust_{c['id']}@example.com",
                    "name": random.choice(names),
                    "phone": f"+91{random.randint(7000000000, 9999999999)}",
                    "interest": random.choice(["whisky", "perfume", "electronics", "gifting"]),
                },
            }, key="chatbot_form")

        # Problem customers rage-click + advanced features
        if c["persona"] == "problem_customer" or random.random() < 0.05:
            store_url = f"https://www.delhidutyfree.co.in/{c['store']['type']}/{c['store']['terminal'].lower()}"
            runner._call("POST", "/api/v1/chatbot/rage-click", {
                "session_id": c["session_id"],
                "url": f"{store_url}/checkout/cart",
                "element": random.choice(["button.checkout", "#add-to-cart", ".qty-input"]),
                "click_count": random.randint(5, 15),
            }, key="chatbot_rage")

        if c["persona"] == "problem_customer":
            runner._call("POST", "/api/v1/chatbot/advanced/order-tracking", {
                "session_id": c["session_id"],
                "order_id": f"DDF-{c['store']['terminal']}-{random.randint(100000, 999999)}",
            }, key="chatbot_advanced")
            runner._call("POST", "/api/v1/chatbot/advanced/objection-handler", {
                "session_id": c["session_id"],
                "objection_type": random.choice(["price", "trust", "shipping", "returns", "quality"]),
                "product_id": "1",
                "context": "product_page",
            }, key="chatbot_advanced")

        if c["id"] % 10 == 0:
            elapsed = time.time() - start_time
            print(f"  ... {c['id']}/100 customers | {total_chats} chats | {runner.rate_limit_hits} rate-limits | {elapsed:.0f}s elapsed")

    print(f"  Chatbot: {runner.results['chatbot_send']['total']} msgs | "
          f"{runner.results['chatbot_form']['total']} forms | "
          f"{runner.results['chatbot_rage']['total']} rage-clicks | "
          f"{runner.results['chatbot_advanced']['total']} advanced")
    print()

    # ══════════════════════════════════════════════════════════════════════
    #  RESULTS
    # ══════════════════════════════════════════════════════════════════════
    elapsed = time.time() - start_time

    print("=" * 72)
    print("  RESULTS SUMMARY")
    print("=" * 72)
    print(f"  Duration: {elapsed:.0f}s | Requests: {runner.request_count} | Rate-limit retries: {runner.rate_limit_hits}")
    print()

    total_pass = total_fail = total_tests = 0
    print(f"  {'Endpoint':<25} {'Total':>6} {'Pass':>6} {'Fail':>6} {'Rate':>8}")
    print("  " + "-" * 55)

    for key in ["search", "suggest", "trending", "similar",
                "chatbot_send", "chatbot_rage", "chatbot_form",
                "chatbot_widget", "chatbot_advanced"]:
        r = runner.results[key]
        rate = f"{r['pass']/r['total']*100:.1f}%" if r["total"] > 0 else "—"
        status = "✅" if r["fail"] == 0 else ("⚠️" if r["pass"] > r["fail"] else "❌")
        print(f"  {key:<25} {r['total']:>6} {r['pass']:>6} {r['fail']:>6} {rate:>7} {status}")
        total_pass += r["pass"]
        total_fail += r["fail"]
        total_tests += r["total"]

    print("  " + "-" * 55)
    overall_rate = f"{total_pass/total_tests*100:.1f}%" if total_tests else "—"
    print(f"  {'TOTAL':<25} {total_tests:>6} {total_pass:>6} {total_fail:>6} {overall_rate:>7}")
    print()

    # Search analysis
    if search_details:
        with_results = sum(1 for s in search_details if s["has_results"])
        no_results = sum(1 for s in search_details if not s["has_results"])
        avg = sum(s["results_count"] for s in search_details) / len(search_details) if search_details else 0
        print("── SEARCH ANALYSIS ──")
        print(f"  Queries returning results:  {with_results}/{len(search_details)}")
        print(f"  Queries with 0 results:     {no_results}")
        print(f"  Avg results per query:      {avg:.1f}")
        zero_q = list(set(s["query"] for s in search_details if not s["has_results"]))
        if zero_q:
            print(f"  Zero-result queries:        {', '.join(zero_q[:10])}")
        print()

    # Chat analysis
    if chat_details:
        with_resp = sum(1 for c in chat_details if c["has_response"])
        convos = len(set(c["conversation_id"] for c in chat_details if c["conversation_id"]))
        topics = {}
        for c in chat_details:
            topics[c["topic"]] = topics.get(c["topic"], 0) + 1
        print("── CHATBOT ANALYSIS ──")
        print(f"  Messages with response: {with_resp}/{len(chat_details)}")
        print(f"  Unique conversations:   {convos}")
        print(f"  Topics tested:          {', '.join(f'{k}({v})' for k, v in sorted(topics.items(), key=lambda x: -x[1]))}")
        print()

    # Error summary
    all_errors = []
    for key, r in runner.results.items():
        for err in r["errors"]:
            all_errors.append({"endpoint": key, **err})
    if all_errors:
        print("── ERRORS ──")
        error_types = {}
        for err in all_errors:
            msg = err.get("message", err.get("error", "unknown"))[:80]
            error_types[msg] = error_types.get(msg, 0) + 1
        for msg, cnt in sorted(error_types.items(), key=lambda x: -x[1]):
            print(f"  [{cnt}x] {msg}")
        print()

    # Per-persona breakdown
    print("── PER-PERSONA BREAKDOWN ──")
    persona_stats = {}
    for c in customers:
        p = c["persona"]
        if p not in persona_stats:
            persona_stats[p] = {"count": 0, "searches": 0, "chats": 0}
        persona_stats[p]["count"] += 1
        persona_stats[p]["searches"] += len(c["queries"])
        persona_stats[p]["chats"] += len(c["messages"])
    print(f"  {'Persona':<28} {'Count':>5} {'Searches':>9} {'Chats':>7}")
    print("  " + "-" * 52)
    for p, s in sorted(persona_stats.items(), key=lambda x: -x[1]["count"]):
        print(f"  {p:<28} {s['count']:>5} {s['searches']:>9} {s['chats']:>7}")
    print()

    # Save results
    output = {
        "simulation_time": datetime.now().isoformat(),
        "target": BASE_URL,
        "duration_seconds": round(elapsed),
        "total_requests": runner.request_count,
        "rate_limit_retries": runner.rate_limit_hits,
        "total_tests": total_tests,
        "total_pass": total_pass,
        "total_fail": total_fail,
        "pass_rate": f"{total_pass/total_tests*100:.1f}%" if total_tests else "0%",
        "results": {k: {"total": v["total"], "pass": v["pass"], "fail": v["fail"],
                         "errors": v["errors"][:5], "samples": v["samples"]}
                    for k, v in runner.results.items()},
        "search_analysis": {
            "queries_with_results": sum(1 for s in search_details if s["has_results"]),
            "queries_no_results": sum(1 for s in search_details if not s["has_results"]),
            "avg_results": round(sum(s["results_count"] for s in search_details) / len(search_details), 1) if search_details else 0,
            "zero_result_queries": list(set(s["query"] for s in search_details if not s["has_results"]))[:20],
        } if search_details else {},
        "chatbot_analysis": {
            "messages_with_response": sum(1 for c in chat_details if c["has_response"]),
            "unique_conversations": len(set(c["conversation_id"] for c in chat_details if c["conversation_id"])),
            "topics": {t: sum(1 for c in chat_details if c["topic"] == t) for t in set(c["topic"] for c in chat_details)},
        } if chat_details else {},
        "persona_stats": persona_stats,
    }

    results_file = os.path.join(os.path.dirname(__file__), "ddf_search_chatbot_paced_results.json")
    with open(results_file, "w") as f:
        json.dump(output, f, indent=2, default=str)
    print(f"  Results saved: {results_file}")
    print("=" * 72)


if __name__ == "__main__":
    main()
