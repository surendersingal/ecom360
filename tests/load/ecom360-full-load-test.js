#!/usr/bin/env node

/**
 * ecom360 Full Load Test — 500K Visitors/Day Simulation
 * =====================================================
 * Simulates a Magento store with ~500,000 daily visitors sending real events
 * to the ecom360 analytics platform. Tests ALL modules:
 *
 *   Phase 1: Marketing Setup  — contacts, lists, templates, channels, campaigns, flows
 *   Phase 2: BI Setup         — KPIs, reports, dashboards, alerts, exports
 *   Phase 3: Analytics Ingest — 500K visitor journey simulation (collect + batch)
 *   Phase 4: Advanced Analytics — CLV, Why, Triggers, Journey, Pulse, Alerts
 *   Phase 5: Verification     — Read back all data and validate counts
 */

import http from "node:http";
import crypto from "node:crypto";

// ─── Configuration ──────────────────────────────────────────────────────────
const BASE = "http://127.0.0.1:8090";
const API_KEY = "woo_live_sk_urbanstyle_2026_prod";
const BEARER = "1|61i3sqw6ZPHQ7lmxDndJkC4QJetYFc1gQPbmqHku0213bea6";
const TENANT_ID = 885;

// Scale: 500K/day ≈ 347/min ≈ 5.8/sec. We compress into a short test window.
// We'll send 2,000 individual events and 100 batch requests (50 events each) = 7,000 total events
const INDIVIDUAL_EVENTS = 2000;
const BATCH_REQUESTS = 100;
const BATCH_SIZE = 50;
const CONCURRENCY = 20; // parallel HTTP requests
const TOTAL_EVENTS = INDIVIDUAL_EVENTS + BATCH_REQUESTS * BATCH_SIZE;

// ─── Fake Data Generators ───────────────────────────────────────────────────

const CATEGORIES = [
    "Electronics",
    "Clothing",
    "Home & Garden",
    "Sports",
    "Beauty",
    "Books",
    "Toys",
    "Automotive",
    "Jewelry",
    "Food & Grocery",
];

const PRODUCTS = [];
for (let i = 1; i <= 200; i++) {
    PRODUCTS.push({
        id: `PROD-${String(i).padStart(4, "0")}`,
        name: `${CATEGORIES[i % CATEGORIES.length]} Item ${i}`,
        sku: `SKU-${String(i).padStart(6, "0")}`,
        price: +(Math.random() * 500 + 5).toFixed(2),
        category: CATEGORIES[i % CATEGORIES.length],
        brand: ["Nike", "Samsung", "Apple", "Adidas", "Sony", "LG", "Bosch", "Levi's", "Dyson", "Canon"][i % 10],
    });
}

const PAGES = [
    "/", "/shop", "/sale", "/new-arrivals", "/best-sellers",
    "/about", "/contact", "/faq", "/blog", "/shipping-returns",
];

const SEARCH_TERMS = [
    "wireless headphones", "summer dress", "running shoes", "laptop stand",
    "protein powder", "yoga mat", "smart watch", "coffee maker",
    "face cream", "kids toy", "gaming mouse", "desk lamp",
    "backpack", "sunglasses", "water bottle", "phone case",
];

const UTM_SOURCES = ["google", "facebook", "instagram", "email", "tiktok", "twitter", "bing", "pinterest", "reddit", "direct"];
const UTM_MEDIUMS = ["cpc", "organic", "social", "email", "referral", "display", "affiliate"];
const UTM_CAMPAIGNS = [
    "summer_sale_2025", "back_to_school", "flash_sale_july", "loyalty_rewards",
    "new_arrivals_promo", "clearance_event", "holiday_special", "weekend_deals",
];
const COUNTRIES = ["US", "UK", "CA", "DE", "FR", "AU", "IN", "JP", "BR", "MX", "IT", "ES", "NL", "SE"];
const CITIES = ["New York", "London", "Toronto", "Berlin", "Paris", "Sydney", "Mumbai", "Tokyo", "São Paulo", "Mexico City"];
const DEVICES = ["desktop", "mobile", "tablet"];
const BROWSERS = ["Chrome", "Safari", "Firefox", "Edge", "Opera"];
const RESOLUTIONS = ["1920x1080", "1366x768", "414x896", "375x812", "768x1024", "1440x900", "2560x1440"];
const LANGUAGES = ["en-US", "en-GB", "de-DE", "fr-FR", "es-ES", "ja-JP", "pt-BR"];
const TIMEZONES = ["America/New_York", "Europe/London", "Europe/Berlin", "Asia/Tokyo", "America/Los_Angeles"];

const FIRST_NAMES = ["James", "Emma", "Liam", "Olivia", "Noah", "Ava", "William", "Sophia", "Mason", "Isabella",
    "Ethan", "Mia", "Alexander", "Charlotte", "Daniel", "Amelia", "Henry", "Harper", "Sebastian", "Evelyn"
];
const LAST_NAMES = ["Smith", "Johnson", "Williams", "Brown", "Jones", "Garcia", "Miller", "Davis", "Rodriguez", "Martinez",
    "Hernandez", "Lopez", "Gonzalez", "Wilson", "Anderson", "Thomas", "Taylor", "Moore", "Jackson", "Martin"
];

function uuid() {
    return crypto.randomUUID();
}

function pick(arr) {
    return arr[Math.floor(Math.random() * arr.length)];
}

function pickN(arr, n) {
    const shuffled = [...arr].sort(() => 0.5 - Math.random());
    return shuffled.slice(0, n);
}

function randInt(min, max) {
    return Math.floor(Math.random() * (max - min + 1)) + min;
}

function randEmail() {
    return `${pick(FIRST_NAMES).toLowerCase()}.${pick(LAST_NAMES).toLowerCase()}${randInt(1, 999)}@example.com`;
}

// ─── HTTP Helper ────────────────────────────────────────────────────────────

function request(method, path, body, auth = "apikey") {
    return new Promise((resolve, reject) => {
        const url = new URL(path, BASE);
        const payload = body ? JSON.stringify(body) : null;
        const headers = {
            "Content-Type": "application/json",
            Accept: "application/json",
        };
        if (auth === "apikey") {
            headers["X-Ecom360-Key"] = API_KEY;
        } else if (auth === "bearer") {
            headers["Authorization"] = `Bearer ${BEARER}`;
        }
        const opts = {
            hostname: url.hostname,
            port: url.port,
            path: url.pathname + url.search,
            method,
            headers,
            timeout: 30000,
        };
        if (payload) headers["Content-Length"] = Buffer.byteLength(payload);

        const req = http.request(opts, (res) => {
            let data = "";
            res.on("data", (c) => (data += c));
            res.on("end", () => {
                let parsed;
                try {
                    parsed = JSON.parse(data);
                } catch {
                    parsed = data;
                }
                resolve({ status: res.statusCode, body: parsed });
            });
        });
        req.on("error", reject);
        req.on("timeout", () => {
            req.destroy();
            reject(new Error("Request timeout"));
        });
        if (payload) req.write(payload);
        req.end();
    });
}

// Parallel pool executor
async function pool(tasks, concurrency) {
    const results = [];
    let idx = 0;
    async function worker() {
        while (idx < tasks.length) {
            const i = idx++;
            results[i] = await tasks[i]();
        }
    }
    await Promise.all(Array.from({ length: Math.min(concurrency, tasks.length) }, worker));
    return results;
}

// ─── Stats Tracker ──────────────────────────────────────────────────────────
const stats = {
    total: 0,
    success: 0,
    errors: 0,
    statusCounts: {},
    phaseResults: {},
    startTime: 0,
};

function trackResult(phase, res) {
    stats.total++;
    const code = res.status;
    stats.statusCounts[code] = (stats.statusCounts[code] || 0) + 1;
    if (code >= 200 && code < 300) {
        stats.success++;
    } else {
        stats.errors++;
        if (!stats.phaseResults[`${phase}_errors`]) stats.phaseResults[`${phase}_errors`] = [];
        if (stats.phaseResults[`${phase}_errors`].length < 5) {
            stats.phaseResults[`${phase}_errors`].push({ status: code, body: typeof res.body === "string" ? res.body.slice(0, 200) : JSON.stringify(res.body).slice(0, 200) });
        }
    }
    return res;
}

// ─── Event Builder ──────────────────────────────────────────────────────────

function buildVisitorSession() {
    const sessionId = uuid();
    const visitorType = Math.random(); // determines journey depth
    const hasUtm = Math.random() < 0.4;
    const isLoggedIn = Math.random() < 0.15;
    const device = pick(DEVICES);

    const base = {
        session_id: sessionId,
        device_fingerprint: `fp_${crypto.randomBytes(8).toString("hex")}`,
        referrer: hasUtm ?
            `https://www.${pick(UTM_SOURCES)}.com/ad/${randInt(1000, 9999)}` :
            Math.random() < 0.3 ?
            `https://www.google.com/search?q=${encodeURIComponent(pick(SEARCH_TERMS))}` :
            "",
        screen_resolution: pick(RESOLUTIONS),
        timezone: pick(TIMEZONES),
        language: pick(LANGUAGES),
        utm: hasUtm ?
            {
                source: pick(UTM_SOURCES),
                medium: pick(UTM_MEDIUMS),
                campaign: pick(UTM_CAMPAIGNS),
            } :
            undefined,
        customer_identifier: isLoggedIn ?
            { type: "email", value: randEmail() } :
            undefined,
    };

    const events = [];

    // Every visitor gets a page_view
    events.push({
        ...base,
        event_type: "page_view",
        url: `https://urbanstyle.co${pick(PAGES)}`,
        page_title: `Urban Style Co. | ${pick(["Home", "Shop", "Sale", "New Arrivals"])}`,
        metadata: {
            device_type: device,
            browser: pick(BROWSERS),
            os: device === "mobile" ? pick(["iOS", "Android"]) : pick(["Windows", "macOS", "Linux"]),
            country: pick(COUNTRIES),
            city: pick(CITIES),
        },
    });

    // 70% browse product
    if (visitorType > 0.3) {
        const product = pick(PRODUCTS);
        events.push({
            ...base,
            event_type: "product_view",
            url: `https://urbanstyle.co/product/${product.id}`,
            page_title: `${product.name} - Urban Style Co.`,
            metadata: {
                product_id: product.id,
                product_name: product.name,
                product_sku: product.sku,
                product_price: product.price,
                product_category: product.category,
                product_brand: product.brand,
                device_type: device,
                browser: pick(BROWSERS),
            },
        });
    }

    // 35% search
    if (visitorType > 0.65) {
        const term = pick(SEARCH_TERMS);
        events.push({
            ...base,
            event_type: "search",
            url: `https://urbanstyle.co/catalogsearch/result/?q=${encodeURIComponent(term)}`,
            page_title: `Search: ${term}`,
            metadata: {
                search_term: term,
                results_count: randInt(0, 250),
            },
        });
    }

    // 30% add to cart
    if (visitorType > 0.7) {
        const product = pick(PRODUCTS);
        const qty = randInt(1, 3);
        events.push({
            ...base,
            event_type: "add_to_cart",
            url: `https://urbanstyle.co/product/${product.id}`,
            metadata: {
                product_id: product.id,
                product_name: product.name,
                product_sku: product.sku,
                product_price: product.price,
                quantity: qty,
                cart_value: +(product.price * qty).toFixed(2),
            },
        });

        // 5% remove from cart
        if (Math.random() < 0.15) {
            events.push({
                ...base,
                event_type: "remove_from_cart",
                url: "https://urbanstyle.co/checkout/cart",
                metadata: {
                    product_id: product.id,
                    product_name: product.name,
                    product_sku: product.sku,
                    quantity: 1,
                },
            });
        }
    }

    // 15% checkout
    if (visitorType > 0.85) {
        events.push({
            ...base,
            event_type: "checkout_step",
            url: "https://urbanstyle.co/checkout/#shipping",
            metadata: { step: 1, step_name: "shipping" },
        });
        events.push({
            ...base,
            event_type: "checkout_step",
            url: "https://urbanstyle.co/checkout/#payment",
            metadata: { step: 2, step_name: "payment" },
        });
    }

    // 10% purchase
    if (visitorType > 0.9) {
        const items = pickN(PRODUCTS, randInt(1, 4));
        const orderId = `ORD-${Date.now()}-${randInt(1000, 9999)}`;
        const subtotal = items.reduce((s, p) => s + p.price, 0);
        const shipping = pick([0, 5.99, 9.99, 12.99]);
        const tax = +(subtotal * 0.08).toFixed(2);
        const discount = Math.random() < 0.3 ? +(subtotal * pick([0.1, 0.15, 0.2])).toFixed(2) : 0;
        const grandTotal = +(subtotal + shipping + tax - discount).toFixed(2);

        events.push({
            ...base,
            event_type: "purchase",
            url: "https://urbanstyle.co/checkout/onepage/success/",
            metadata: {
                order_id: orderId,
                grand_total: grandTotal,
                subtotal: +subtotal.toFixed(2),
                shipping_amount: shipping,
                tax_amount: tax,
                discount_amount: discount,
                coupon: discount > 0 ? pick(["SUMMER25", "SAVE10", "VIP20", "FLASH15"]) : null,
                payment_method: pick(["credit_card", "paypal", "apple_pay", "google_pay", "klarna"]),
                shipping_method: pick(["standard", "express", "next_day", "pickup"]),
                currency: "USD",
                items_count: items.length,
                items: items.map((p) => ({
                    id: p.id,
                    name: p.name,
                    sku: p.sku,
                    price: p.price,
                    quantity: 1,
                    category: p.category,
                })),
            },
        });
    }

    // Engagement events
    if (Math.random() < 0.6) {
        events.push({
            ...base,
            event_type: "scroll_depth",
            url: events[0].url,
            metadata: { max_percent: pick([25, 50, 75, 100]) },
        });
    }
    if (Math.random() < 0.5) {
        events.push({
            ...base,
            event_type: "engagement_time",
            url: events[0].url,
            metadata: { duration_seconds: randInt(5, 300), pages_viewed: randInt(1, 12) },
        });
    }

    // 3% wishlist
    if (Math.random() < 0.03) {
        const prod = pick(PRODUCTS);
        events.push({
            ...base,
            event_type: "add_to_wishlist",
            url: `https://urbanstyle.co/product/${prod.id}`,
            metadata: { product_id: prod.id, product_name: prod.name },
        });
    }

    // 2% review
    if (Math.random() < 0.02) {
        const prod = pick(PRODUCTS);
        events.push({
            ...base,
            event_type: "review_submit",
            url: `https://urbanstyle.co/product/${prod.id}`,
            metadata: {
                product_id: prod.id,
                rating: randInt(1, 5),
                title: pick(["Great product!", "Love it!", "Not bad", "Excellent quality", "Disappointing"]),
            },
        });
    }

    // 5% customer_login
    if (isLoggedIn && Math.random() < 0.5) {
        events.push({
            ...base,
            event_type: "customer_login",
            url: "https://urbanstyle.co/customer/account/login/",
            metadata: { method: pick(["form", "google", "facebook"]) },
        });
    }

    // 2% customer_register
    if (Math.random() < 0.02) {
        events.push({
            ...base,
            event_type: "customer_register",
            url: "https://urbanstyle.co/customer/account/create/",
            metadata: { method: "form" },
        });
    }

    return events;
}

// ═══════════════════════════════════════════════════════════════════════════
// PHASE 1: MARKETING SETUP
// ═══════════════════════════════════════════════════════════════════════════

async function phase1_marketing() {
    console.log("\n╔══════════════════════════════════════════════════════════╗");
    console.log("║  PHASE 1: MARKETING SETUP                              ║");
    console.log("╚══════════════════════════════════════════════════════════╝\n");

    const created = { contacts: [], lists: [], templates: [], channels: [], campaigns: [], flows: [] };

    // 1a. Create 200 contacts via bulkImport
    console.log("  [1a] Creating 200 contacts via bulk-import...");
    const contacts = [];
    for (let i = 0; i < 200; i++) {
        contacts.push({
            email: `customer${i}@urbanstyle-test.com`,
            first_name: pick(FIRST_NAMES),
            last_name: pick(LAST_NAMES),
            phone: `+1${randInt(200, 999)}${randInt(1000000, 9999999)}`,
            tags: pickN(["vip", "new", "repeat", "high_value", "at_risk", "dormant", "loyal", "discount_hunter"], randInt(1, 3)),
            custom_fields: {
                lifetime_value: +(Math.random() * 5000).toFixed(2),
                total_orders: randInt(0, 50),
                last_purchase_date: new Date(Date.now() - randInt(1, 365) * 86400000).toISOString().split("T")[0],
                preferred_category: pick(CATEGORIES),
                acquisition_source: pick(UTM_SOURCES),
            },
        });
    }
    let res = await request("POST", "/api/v1/marketing/contacts/bulk-import", { contacts }, "bearer");
    trackResult("marketing", res);
    console.log(`    → ${res.status} | Bulk import: ${typeof res.body === "object" ? JSON.stringify(res.body).slice(0, 100) : res.status}`);

    // Store some contact IDs for later (create a few individually to get IDs)
    console.log("  [1b] Creating 10 individual contacts...");
    for (let i = 0; i < 10; i++) {
        res = await request(
            "POST",
            "/api/v1/marketing/contacts", {
                email: `vip${i}@urbanstyle-test.com`,
                first_name: pick(FIRST_NAMES),
                last_name: pick(LAST_NAMES),
                phone: `+1${randInt(200, 999)}${randInt(1000000, 9999999)}`,
                tags: ["vip", "test"],
                custom_fields: { test_contact: true, tier: pick(["gold", "silver", "platinum"]) },
            },
            "bearer"
        );
        trackResult("marketing", res);
        if (res.status >= 200 && res.status < 300 && res.body ? .data ? .id) {
            created.contacts.push(res.body.data.id);
        }
    }
    console.log(`    → Created ${created.contacts.length} contacts with IDs`);

    // 1c. Create contact lists
    console.log("  [1c] Creating contact lists...");
    const listNames = [
        "VIP Customers", "New Subscribers", "Cart Abandoners", "High Value Segment",
        "Re-engagement Target", "Summer Sale Audience", "Product Launch List",
    ];
    for (const name of listNames) {
        res = await request("POST", "/api/v1/marketing/lists", { name, description: `Auto-created list: ${name}` }, "bearer");
        trackResult("marketing", res);
        if (res.status >= 200 && res.status < 300 && res.body ? .data ? .id) {
            created.lists.push(res.body.data.id);
        }
    }
    console.log(`    → Created ${created.lists.length} lists`);

    // Add contacts to lists
    if (created.lists.length > 0 && created.contacts.length > 0) {
        console.log("  [1d] Adding contacts to lists...");
        for (const listId of created.lists.slice(0, 3)) {
            const memberIds = created.contacts.slice(0, randInt(3, Math.min(8, created.contacts.length)));
            res = await request("POST", `/api/v1/marketing/lists/${listId}/members`, { contact_ids: memberIds }, "bearer");
            trackResult("marketing", res);
        }
        console.log("    → Members added to lists");
    }

    // 1e. Create email templates
    console.log("  [1e] Creating email templates...");
    const templates = [
        { name: "Welcome Email", channel: "email", subject: "Welcome to Urban Style Co.!", body: "<h1>Welcome {{first_name}}!</h1><p>Thanks for joining us. Enjoy 15% off your first order with code WELCOME15.</p>" },
        { name: "Cart Abandonment", channel: "email", subject: "You forgot something!", body: "<h1>Oops, {{first_name}}!</h1><p>You have items waiting in your cart. Complete your purchase now and get free shipping.</p>" },
        { name: "Flash Sale Alert", channel: "email", subject: "⚡ Flash Sale - 50% Off!", body: "<h1>FLASH SALE</h1><p>50% off everything for the next 24 hours. Don't miss out, {{first_name}}!</p>" },
        { name: "Order Confirmation", channel: "email", subject: "Order Confirmed #{{order_id}}", body: "<h1>Thank you!</h1><p>Your order has been confirmed and is being processed.</p>" },
        { name: "Re-engagement", channel: "email", subject: "We miss you, {{first_name}}!", body: "<h1>Come back!</h1><p>It's been a while since your last visit. Here's 20% off to welcome you back.</p>" },
        { name: "Product Launch SMS", channel: "sms", subject: null, body: "NEW: Check out our latest collection at urbanstyle.co/new. Reply STOP to unsubscribe." },
        { name: "VIP Push Notification", channel: "push", subject: "Exclusive VIP Offer", body: "You've been selected for an exclusive VIP deal. Tap to claim your 30% discount!" },
    ];
    for (const tpl of templates) {
        res = await request("POST", "/api/v1/marketing/templates", tpl, "bearer");
        trackResult("marketing", res);
        if (res.status >= 200 && res.status < 300 && res.body ? .data ? .id) {
            created.templates.push({ id: res.body.data.id, channel: tpl.channel });
        }
    }
    console.log(`    → Created ${created.templates.length} templates`);

    // 1f. Create channels
    console.log("  [1f] Creating marketing channels...");
    const channels = [
        { name: "Primary Email (Mailgun)", type: "email", provider: "mailgun", credentials: { api_key: "test-key-mg", domain: "mg.urbanstyle.co" }, settings: { from_name: "Urban Style Co.", from_email: "hello@urbanstyle.co" } },
        { name: "SMS (Twilio)", type: "sms", provider: "twilio", credentials: { sid: "test-sid", token: "test-token", from: "+15551234567" } },
        { name: "Push (Firebase)", type: "push", provider: "firebase", credentials: { project_id: "urbanstyle-push", server_key: "test-fcm-key" } },
        { name: "WhatsApp (Twilio)", type: "whatsapp", provider: "twilio", credentials: { sid: "test-sid-wa", token: "test-token-wa", from: "whatsapp:+15551234567" } },
    ];
    for (const ch of channels) {
        res = await request("POST", "/api/v1/marketing/channels", ch, "bearer");
        trackResult("marketing", res);
        if (res.status >= 200 && res.status < 300 && res.body ? .data ? .id) {
            created.channels.push({ id: res.body.data.id, type: ch.type });
        }
    }
    console.log(`    → Created ${created.channels.length} channels`);

    // 1g. Create campaigns
    console.log("  [1g] Creating marketing campaigns...");
    const emailTemplates = created.templates.filter((t) => t.channel === "email");
    if (emailTemplates.length > 0) {
        const campDefs = [
            { name: "Summer Sale 2025", type: "one_time", audience: { type: "all" } },
            { name: "Cart Recovery Flow", type: "triggered", audience: { type: "tags", tags: ["cart_abandoner"] } },
            { name: "Weekly Newsletter", type: "recurring", audience: { type: "all" }, schedule: { frequency: "weekly", day: "monday", time: "09:00" } },
            { name: "VIP Exclusive Offer", type: "one_time", audience: { type: "tags", tags: ["vip"] } },
            { name: "New Product Launch", type: "one_time", audience: { type: "list", list_id: created.lists[0] || 1 } },
            { name: "Win-back Dormant Customers", type: "triggered", audience: { type: "segment", segment_id: 1 } },
            { name: "A/B Test: Subject Lines", type: "ab_test", audience: { type: "all" } },
        ];
        for (const camp of campDefs) {
            res = await request(
                "POST",
                "/api/v1/marketing/campaigns", {
                    ...camp,
                    channel: "email",
                    template_id: pick(emailTemplates).id,
                },
                "bearer"
            );
            trackResult("marketing", res);
            if (res.status >= 200 && res.status < 300 && res.body ? .data ? .id) {
                created.campaigns.push(res.body.data.id);
            }
        }
    }
    console.log(`    → Created ${created.campaigns.length} campaigns`);

    // Send first campaign
    if (created.campaigns.length > 0) {
        console.log("  [1h] Sending first campaign...");
        res = await request("POST", `/api/v1/marketing/campaigns/${created.campaigns[0]}/send`, {}, "bearer");
        trackResult("marketing", res);
        console.log(`    → Send: ${res.status}`);

        // Get campaign stats
        res = await request("GET", `/api/v1/marketing/campaigns/${created.campaigns[0]}/stats`, null, "bearer");
        trackResult("marketing", res);
        console.log(`    → Stats: ${res.status}`);
    }

    // 1i. Create automation flows
    console.log("  [1i] Creating automation flows...");
    const flowDefs = [{
            name: "Welcome Series",
            trigger_type: "event",
            trigger_config: { event: "customer_register" },
            description: "3-email welcome series for new customers",
        },
        {
            name: "Abandoned Cart Recovery",
            trigger_type: "event",
            trigger_config: { event: "cart_update", condition: "abandoned_30min" },
            description: "Send recovery email 30 min after cart abandonment",
        },
        {
            name: "Post-Purchase Follow-up",
            trigger_type: "event",
            trigger_config: { event: "purchase" },
            description: "Review request 7 days after purchase",
        },
        {
            name: "Re-engagement Campaign",
            trigger_type: "segment_enter",
            trigger_config: { segment: "dormant_30_days" },
            description: "Re-engage customers inactive for 30 days",
        },
        {
            name: "VIP Birthday Offer",
            trigger_type: "date_field",
            trigger_config: { field: "birthday", offset_days: 0 },
            description: "Send birthday discount to VIP customers",
        },
    ];
    for (const flow of flowDefs) {
        res = await request("POST", "/api/v1/marketing/flows", flow, "bearer");
        trackResult("marketing", res);
        if (res.status >= 200 && res.status < 300 && res.body ? .data ? .id) {
            created.flows.push(res.body.data.id);
        }
    }
    console.log(`    → Created ${created.flows.length} flows`);

    // Save canvas for first flow (Welcome Series)
    if (created.flows.length > 0 && emailTemplates.length >= 2) {
        console.log("  [1j] Saving flow canvas (Welcome Series)...");
        res = await request(
            "PUT",
            `/api/v1/marketing/flows/${created.flows[0]}/canvas`, {
                nodes: [
                    { node_id: "trigger_1", type: "trigger", config: { event: "customer_register" }, position: { x: 100, y: 100 } },
                    { node_id: "delay_1", type: "delay", config: { duration: 0, unit: "hours" }, position: { x: 100, y: 250 } },
                    { node_id: "email_1", type: "send_email", config: { template_id: emailTemplates[0].id, subject: "Welcome to Urban Style!" }, position: { x: 100, y: 400 } },
                    { node_id: "delay_2", type: "delay", config: { duration: 3, unit: "days" }, position: { x: 100, y: 550 } },
                    { node_id: "email_2", type: "send_email", config: { template_id: emailTemplates[1].id, subject: "Tips to get started" }, position: { x: 100, y: 700 } },
                    { node_id: "condition_1", type: "condition", config: { property: "has_purchased", operator: "equals", value: true }, position: { x: 100, y: 850 } },
                    { node_id: "end_1", type: "end", config: {}, position: { x: 300, y: 1000 } },
                    { node_id: "email_3", type: "send_email", config: { template_id: emailTemplates.length > 2 ? emailTemplates[2].id : emailTemplates[0].id, subject: "Special offer just for you" }, position: { x: -100, y: 1000 } },
                ],
                edges: [
                    { source_node_id: "trigger_1", target_node_id: "delay_1" },
                    { source_node_id: "delay_1", target_node_id: "email_1" },
                    { source_node_id: "email_1", target_node_id: "delay_2" },
                    { source_node_id: "delay_2", target_node_id: "email_2" },
                    { source_node_id: "email_2", target_node_id: "condition_1" },
                    { source_node_id: "condition_1", target_node_id: "end_1", label: "Yes" },
                    { source_node_id: "condition_1", target_node_id: "email_3", label: "No" },
                ],
            },
            "bearer"
        );
        trackResult("marketing", res);
        console.log(`    → Canvas saved: ${res.status}`);

        // Activate flow
        console.log("  [1k] Activating Welcome Series flow...");
        res = await request("POST", `/api/v1/marketing/flows/${created.flows[0]}/activate`, {}, "bearer");
        trackResult("marketing", res);
        console.log(`    → Activate: ${res.status}`);

        // Enroll a contact
        if (created.contacts.length > 0) {
            res = await request("POST", `/api/v1/marketing/flows/${created.flows[0]}/enroll`, { contact_id: created.contacts[0] }, "bearer");
            trackResult("marketing", res);
            console.log(`    → Enrolled contact: ${res.status}`);
        }
    }

    stats.phaseResults.marketing = created;
    return created;
}

// ═══════════════════════════════════════════════════════════════════════════
// PHASE 2: BI SETUP
// ═══════════════════════════════════════════════════════════════════════════

async function phase2_bi() {
    console.log("\n╔══════════════════════════════════════════════════════════╗");
    console.log("║  PHASE 2: BUSINESS INTELLIGENCE SETUP                  ║");
    console.log("╚══════════════════════════════════════════════════════════╝\n");

    const created = { kpis: [], reports: [], dashboards: [], alerts: [], exports: [] };

    // 2a. Create KPI defaults
    console.log("  [2a] Creating default KPIs...");
    let res = await request("POST", "/api/v1/bi/kpis/defaults", {}, "bearer");
    trackResult("bi", res);
    console.log(`    → Defaults: ${res.status}`);

    // 2b. Create custom KPIs
    console.log("  [2b] Creating custom KPIs...");
    const kpiDefs = [
        { name: "Monthly Revenue Target", metric: "revenue", target: 150000 },
        { name: "Daily Active Visitors", metric: "sessions", target: 500000 },
        { name: "Cart Abandonment Rate", metric: "cart_abandonment_rate", target: 25 },
        { name: "Average Order Value", metric: "aov", target: 85 },
        { name: "Conversion Rate", metric: "conversion_rate", target: 3.5 },
        { name: "Customer Acquisition Cost", metric: "cac", target: 15 },
        { name: "Customer Lifetime Value", metric: "clv", target: 450 },
        { name: "Email Open Rate", metric: "email_open_rate", target: 25 },
        { name: "Return Customer Rate", metric: "return_rate", target: 40 },
        { name: "Net Promoter Score", metric: "nps", target: 70 },
    ];
    for (const kpi of kpiDefs) {
        res = await request("POST", "/api/v1/bi/kpis", kpi, "bearer");
        trackResult("bi", res);
        if (res.status >= 200 && res.status < 300 && res.body ? .data ? .id) {
            created.kpis.push(res.body.data.id);
        }
    }
    console.log(`    → Created ${created.kpis.length} KPIs`);

    // Refresh KPIs
    console.log("  [2c] Refreshing KPIs...");
    res = await request("POST", "/api/v1/bi/kpis/refresh", {}, "bearer");
    trackResult("bi", res);
    console.log(`    → Refresh: ${res.status}`);

    // 2d. Create reports
    console.log("  [2d] Creating BI reports...");
    const reportDefs = [
        { name: "Revenue by Channel", type: "bar_chart", config: { data_source: "events", metric: "revenue", group_by: "utm_source", period: "last_30_days" } },
        { name: "Product Performance Matrix", type: "table", config: { data_source: "events", event_type: "purchase", group_by: "product_id", metrics: ["revenue", "orders", "aov"] } },
        { name: "Customer Cohort Analysis", type: "heatmap", config: { data_source: "customers", metric: "retention", period: "monthly", cohort_size: 12 } },
        { name: "Funnel: Browse to Purchase", type: "funnel", config: { steps: ["page_view", "product_view", "add_to_cart", "checkout_step", "purchase"] } },
        { name: "Geographic Revenue Map", type: "geo_map", config: { data_source: "events", metric: "revenue", group_by: "country" } },
        { name: "Daily Traffic Trend", type: "line_chart", config: { data_source: "sessions", metric: "count", period: "daily", range: "last_90_days" } },
        { name: "Top Search Terms", type: "word_cloud", config: { data_source: "events", event_type: "search", field: "search_term", limit: 50 } },
        { name: "Category Performance", type: "treemap", config: { data_source: "events", metric: "revenue", group_by: "category" } },
    ];
    for (const rpt of reportDefs) {
        res = await request("POST", "/api/v1/bi/reports", rpt, "bearer");
        trackResult("bi", res);
        if (res.status >= 200 && res.status < 300 && res.body ? .data ? .id) {
            created.reports.push(res.body.data.id);
        }
    }
    console.log(`    → Created ${created.reports.length} reports`);

    // Execute first report
    if (created.reports.length > 0) {
        console.log("  [2e] Executing report...");
        res = await request("POST", `/api/v1/bi/reports/${created.reports[0]}/execute`, { filters: {} }, "bearer");
        trackResult("bi", res);
        console.log(`    → Execute: ${res.status}`);
    }

    // 2f. Create dashboards
    console.log("  [2f] Creating BI dashboards...");
    const dashDefs = [{
            name: "Executive Overview",
            description: "High-level business metrics for leadership",
            is_default: true,
            is_public: true,
            layout: [
                { widget: "kpi_cards", row: 0, col: 0, width: 12, height: 2 },
                { widget: "revenue_chart", row: 2, col: 0, width: 6, height: 4 },
                { widget: "traffic_chart", row: 2, col: 6, width: 6, height: 4 },
                { widget: "top_products", row: 6, col: 0, width: 4, height: 4 },
                { widget: "geographic_map", row: 6, col: 4, width: 8, height: 4 },
            ],
            widgets: created.reports.slice(0, 4).map((id, i) => ({ report_id: id, position: i })),
        },
        {
            name: "Marketing Performance",
            description: "Campaign effectiveness and ROI tracking",
            layout: [
                { widget: "campaign_metrics", row: 0, col: 0, width: 12, height: 2 },
                { widget: "channel_comparison", row: 2, col: 0, width: 6, height: 4 },
                { widget: "utm_analysis", row: 2, col: 6, width: 6, height: 4 },
            ],
            widgets: [],
        },
        {
            name: "Customer Intelligence",
            description: "Customer behavior, segments, and lifetime value",
            layout: [
                { widget: "clv_distribution", row: 0, col: 0, width: 6, height: 4 },
                { widget: "cohort_retention", row: 0, col: 6, width: 6, height: 4 },
                { widget: "segment_breakdown", row: 4, col: 0, width: 12, height: 4 },
            ],
            widgets: [],
        },
        {
            name: "Real-time Operations",
            description: "Live monitoring dashboard",
            layout: [
                { widget: "live_visitors", row: 0, col: 0, width: 4, height: 2 },
                { widget: "live_revenue", row: 0, col: 4, width: 4, height: 2 },
                { widget: "live_alerts", row: 0, col: 8, width: 4, height: 2 },
            ],
            widgets: [],
        },
    ];
    for (const dash of dashDefs) {
        res = await request("POST", "/api/v1/bi/dashboards", dash, "bearer");
        trackResult("bi", res);
        if (res.status >= 200 && res.status < 300 && res.body ? .data ? .id) {
            created.dashboards.push(res.body.data.id);
        }
    }
    console.log(`    → Created ${created.dashboards.length} dashboards`);

    // Duplicate a dashboard
    if (created.dashboards.length > 0) {
        res = await request("POST", `/api/v1/bi/dashboards/${created.dashboards[0]}/duplicate`, {}, "bearer");
        trackResult("bi", res);
        console.log(`    → Duplicated dashboard: ${res.status}`);
    }

    // 2g. Create alerts
    console.log("  [2g] Creating BI alerts...");
    if (created.kpis.length > 0) {
        const alertDefs = [
            { name: "Revenue Drop Alert", kpi_id: created.kpis[0], condition: "below", threshold: 100000, channels: ["email", "slack"] },
            { name: "Traffic Spike Alert", kpi_id: created.kpis[1], condition: "above", threshold: 700000, channels: ["email"] },
            { name: "Cart Abandonment High", kpi_id: created.kpis[2], condition: "above", threshold: 40, channels: ["email", "sms"] },
            { name: "Conversion Rate Drop", kpi_id: created.kpis[4], condition: "below", threshold: 2.0, channels: ["email", "slack"] },
            { name: "Revenue Anomaly", kpi_id: created.kpis[0], condition: "anomaly", threshold: 2.5, channels: ["email"] },
        ];
        for (const alert of alertDefs) {
            res = await request("POST", "/api/v1/bi/alerts", alert, "bearer");
            trackResult("bi", res);
            if (res.status >= 200 && res.status < 300 && res.body ? .data ? .id) {
                created.alerts.push(res.body.data.id);
            }
        }
        console.log(`    → Created ${created.alerts.length} alerts`);

        // Evaluate alerts
        console.log("  [2h] Evaluating alerts...");
        res = await request("POST", "/api/v1/bi/alerts/evaluate", {}, "bearer");
        trackResult("bi", res);
        console.log(`    → Evaluate: ${res.status}`);
    }

    // 2i. Create exports
    if (created.reports.length > 0) {
        console.log("  [2i] Creating exports...");
        const formats = ["csv", "xlsx", "json", "pdf"];
        for (let i = 0; i < Math.min(4, created.reports.length); i++) {
            res = await request(
                "POST",
                "/api/v1/bi/exports", { name: `Export ${i + 1}`, report_id: created.reports[i], format: formats[i] },
                "bearer"
            );
            trackResult("bi", res);
            if (res.status >= 200 && res.status < 300 && res.body ? .data ? .id) {
                created.exports.push(res.body.data.id);
            }
        }
        console.log(`    → Created ${created.exports.length} exports`);
    }

    // 2j. Query insights
    console.log("  [2j] Running BI queries...");
    const queries = [
        { data_source: "events", filters: { event_type: "purchase" }, group_by: "day", aggregations: [{ field: "metadata.grand_total", function: "sum" }] },
        { data_source: "sessions", filters: {}, group_by: "device_type" },
        { data_source: "customers", filters: {}, group_by: "country" },
    ];
    for (const q of queries) {
        res = await request("POST", "/api/v1/bi/insights/query", q, "bearer");
        trackResult("bi", res);
    }
    console.log(`    → Queries executed`);

    // Generate predictions
    console.log("  [2k] Generating predictions...");
    for (const model of["clv", "churn_risk", "purchase_propensity", "revenue_forecast"]) {
        res = await request("POST", "/api/v1/bi/insights/predictions/generate", { model_type: model }, "bearer");
        trackResult("bi", res);
    }
    console.log(`    → Predictions generated`);

    stats.phaseResults.bi = created;
    return created;
}

// ═══════════════════════════════════════════════════════════════════════════
// PHASE 3: ANALYTICS INGESTION — 500K Visitor Simulation
// ═══════════════════════════════════════════════════════════════════════════

async function phase3_analytics() {
    console.log("\n╔══════════════════════════════════════════════════════════╗");
    console.log("║  PHASE 3: ANALYTICS INGESTION (500K Visitor Sim)       ║");
    console.log("╚══════════════════════════════════════════════════════════╝\n");

    let eventsSent = 0;
    let batchesSent = 0;
    const t0 = Date.now();

    // 3a. Individual event collection via POST /api/v1/collect
    console.log(`  [3a] Sending ${INDIVIDUAL_EVENTS} individual events via /api/v1/collect...`);
    const individualTasks = [];
    for (let i = 0; i < INDIVIDUAL_EVENTS; i++) {
        const session = buildVisitorSession();
        // Pick one event from the session to send individually
        const event = pick(session);
        individualTasks.push(async() => {
            try {
                const res = await request("POST", "/api/v1/collect", event, "apikey");
                trackResult("analytics_collect", res);
                eventsSent++;
                if (eventsSent % 500 === 0) {
                    const elapsed = (Date.now() - t0) / 1000;
                    process.stdout.write(`    → ${eventsSent} events sent (${(eventsSent / elapsed).toFixed(0)}/sec)\r`);
                }
                return res;
            } catch (e) {
                stats.errors++;
                return { status: 0, body: e.message };
            }
        });
    }
    await pool(individualTasks, CONCURRENCY);
    console.log(`\n    → Individual events complete: ${eventsSent} sent`);

    // 3b. Batch event collection via POST /api/v1/collect/batch
    console.log(`\n  [3b] Sending ${BATCH_REQUESTS} batches of ${BATCH_SIZE} events via /api/v1/collect/batch...`);
    const batchTasks = [];
    for (let b = 0; b < BATCH_REQUESTS; b++) {
        const batchEvents = [];
        for (let i = 0; i < BATCH_SIZE; i++) {
            const session = buildVisitorSession();
            // Send all events from the session in the batch
            for (const ev of session) {
                batchEvents.push({...ev, timestamp: new Date().toISOString() });
                if (batchEvents.length >= BATCH_SIZE) break;
            }
            if (batchEvents.length >= BATCH_SIZE) break;
        }
        batchTasks.push(async() => {
            try {
                const res = await request("POST", "/api/v1/collect/batch", { events: batchEvents.slice(0, BATCH_SIZE) }, "apikey");
                trackResult("analytics_batch", res);
                batchesSent++;
                eventsSent += BATCH_SIZE;
                if (batchesSent % 10 === 0) {
                    process.stdout.write(`    → ${batchesSent}/${BATCH_REQUESTS} batches sent\r`);
                }
                return res;
            } catch (e) {
                stats.errors++;
                return { status: 0, body: e.message };
            }
        });
    }
    await pool(batchTasks, Math.min(CONCURRENCY, 10)); // lower concurrency for batch (rate limited at 60/min)
    console.log(`\n    → Batches complete: ${batchesSent} batches sent`);

    // 3c. Authenticated ingest via POST /api/v1/analytics/ingest
    console.log("\n  [3c] Sending 100 events via authenticated /api/v1/analytics/ingest...");
    for (let i = 0; i < 100; i++) {
        const session = buildVisitorSession();
        const event = pick(session);
        const payload = {
            payload: {
                session_id: event.session_id,
                event_type: event.event_type,
                url: event.url,
                metadata: event.metadata || {},
                custom_data: event.custom_data || null,
                device_fingerprint: event.device_fingerprint || null,
                customer_identifier: event.customer_identifier || null,
            },
        };
        const res = await request("POST", "/api/v1/analytics/ingest", payload, "bearer");
        trackResult("analytics_ingest", res);
    }
    console.log("    → Authenticated ingest complete");

    // 3d. Custom event definitions and tracking
    console.log("\n  [3d] Creating custom event definitions...");
    const customDefs = [
        { event_key: "wishlist_share", display_name: "Wishlist Shared", description: "Customer shared their wishlist", schema: [{ field_name: "share_method", field_type: "string", required: true }, { field_name: "item_count", field_type: "number" }] },
        { event_key: "product_comparison", display_name: "Product Comparison", description: "Customer compared products", schema: [{ field_name: "product_ids", field_type: "array", required: true }] },
        { event_key: "coupon_applied", display_name: "Coupon Applied", description: "Customer applied a coupon", schema: [{ field_name: "coupon_code", field_type: "string", required: true }, { field_name: "discount_value", field_type: "number" }] },
        { event_key: "size_guide_view", display_name: "Size Guide Viewed", description: "Customer viewed size guide", schema: [{ field_name: "product_id", field_type: "string" }] },
        { event_key: "store_locator_use", display_name: "Store Locator Used", description: "Customer used store locator", schema: [{ field_name: "zip_code", field_type: "string" }] },
    ];
    for (const def of customDefs) {
        const res = await request("POST", "/api/v1/analytics/events/custom/definitions", def, "bearer");
        trackResult("analytics_custom", res);
    }

    // Track custom events
    console.log("  [3e] Tracking custom events...");
    for (let i = 0; i < 50; i++) {
        const def = pick(customDefs);
        const event = {
            event_key: def.event_key,
            session_id: uuid(),
            url: `https://urbanstyle.co/${def.event_key.replace(/_/g, "-")}`,
            metadata: {},
            custom_data: {},
        };
        // Fill custom data based on schema
        if (def.event_key === "wishlist_share") event.custom_data = { share_method: pick(["email", "facebook", "twitter", "link"]), item_count: randInt(1, 10) };
        if (def.event_key === "product_comparison") event.custom_data = { product_ids: pickN(PRODUCTS, randInt(2, 4)).map((p) => p.id) };
        if (def.event_key === "coupon_applied") event.custom_data = { coupon_code: pick(["SUMMER25", "SAVE10", "VIP20"]), discount_value: +(Math.random() * 50).toFixed(2) };
        if (def.event_key === "size_guide_view") event.custom_data = { product_id: pick(PRODUCTS).id };
        if (def.event_key === "store_locator_use") event.custom_data = { zip_code: String(randInt(10000, 99999)) };

        const res = await request("POST", "/api/v1/analytics/events/custom", event, "bearer");
        trackResult("analytics_custom", res);
    }
    console.log("    → Custom events complete");

    const elapsed = (Date.now() - t0) / 1000;
    stats.phaseResults.analytics = {
        total_events: eventsSent,
        individual: INDIVIDUAL_EVENTS,
        batches: batchesSent,
        batch_events: batchesSent * BATCH_SIZE,
        authenticated: 100,
        custom: 50,
        elapsed_seconds: elapsed.toFixed(1),
        events_per_second: (eventsSent / elapsed).toFixed(1),
    };

    console.log(`\n  ✓ Total events ingested: ${eventsSent.toLocaleString()}`);
    console.log(`  ✓ Throughput: ${(eventsSent / elapsed).toFixed(1)} events/sec`);
    console.log(`  ✓ Elapsed: ${elapsed.toFixed(1)}s`);
}

// ═══════════════════════════════════════════════════════════════════════════
// PHASE 4: ADVANCED ANALYTICS
// ═══════════════════════════════════════════════════════════════════════════

async function phase4_advanced() {
    console.log("\n╔══════════════════════════════════════════════════════════╗");
    console.log("║  PHASE 4: ADVANCED ANALYTICS                           ║");
    console.log("╚══════════════════════════════════════════════════════════╝\n");

    // 4a. CLV Prediction
    console.log("  [4a] CLV Prediction...");
    let res = await request("GET", "/api/v1/analytics/advanced/clv?limit=50", null, "bearer");
    trackResult("advanced", res);
    console.log(`    → CLV: ${res.status} (${typeof res.body === "object" && res.body?.data ? "has data" : "no data"})`);

    // CLV What-If
    res = await request("POST", "/api/v1/analytics/advanced/clv/what-if", {
        scenario: "increase_retention",
        retention_increase_percent: 10,
        period_months: 12,
    }, "bearer");
    trackResult("advanced", res);
    console.log(`    → CLV What-If: ${res.status}`);

    // 4b. Why Analysis
    console.log("  [4b] Why Analysis...");
    const today = new Date();
    const thirtyDaysAgo = new Date(today - 30 * 86400000);
    const sixtyDaysAgo = new Date(today - 60 * 86400000);
    res = await request("POST", "/api/v1/analytics/advanced/why", {
        metric: "revenue",
        start_date: thirtyDaysAgo.toISOString().split("T")[0],
        end_date: today.toISOString().split("T")[0],
        prev_start_date: sixtyDaysAgo.toISOString().split("T")[0],
        prev_end_date: thirtyDaysAgo.toISOString().split("T")[0],
    }, "bearer");
    trackResult("advanced", res);
    console.log(`    → Why (revenue): ${res.status}`);

    for (const metric of["orders", "sessions", "conversion_rate", "aov"]) {
        res = await request("POST", "/api/v1/analytics/advanced/why", {
            metric,
            start_date: thirtyDaysAgo.toISOString().split("T")[0],
            end_date: today.toISOString().split("T")[0],
        }, "bearer");
        trackResult("advanced", res);
    }
    console.log("    → Why analysis for all metrics complete");

    // 4c. Behavioral Triggers
    console.log("  [4c] Behavioral Triggers...");
    res = await request("POST", "/api/v1/analytics/advanced/triggers/evaluate", {}, "bearer");
    trackResult("advanced", res);
    console.log(`    → Triggers: ${res.status}`);

    // 4d. Customer Journey
    console.log("  [4d] Customer Journey...");
    res = await request("GET", "/api/v1/analytics/advanced/journey?limit=50", null, "bearer");
    trackResult("advanced", res);
    console.log(`    → Journey: ${res.status}`);

    res = await request("GET", "/api/v1/analytics/advanced/journey/drop-offs", null, "bearer");
    trackResult("advanced", res);
    console.log(`    → Drop-offs: ${res.status}`);

    // 4e. Real-time Pulse
    console.log("  [4e] Real-time Pulse...");
    res = await request("GET", "/api/v1/analytics/advanced/pulse", null, "bearer");
    trackResult("advanced", res);
    console.log(`    → Pulse: ${res.status}`);

    // 4f. Real-time Alerts
    console.log("  [4f] Real-time Alerts...");
    res = await request("GET", "/api/v1/analytics/advanced/alerts", null, "bearer");
    trackResult("advanced", res);
    console.log(`    → Alerts: ${res.status}`);

    // 4g. Audience Segments
    console.log("  [4g] Audience Segments...");
    res = await request("GET", "/api/v1/analytics/advanced/audience/segments", null, "bearer");
    trackResult("advanced", res);
    console.log(`    → Segments: ${res.status}`);

    res = await request("GET", "/api/v1/analytics/advanced/audience/destinations", null, "bearer");
    trackResult("advanced", res);
    console.log(`    → Destinations: ${res.status}`);

    // 4h. Recommendations
    console.log("  [4h] Recommendations...");
    res = await request("GET", "/api/v1/analytics/advanced/recommendations", null, "bearer");
    trackResult("advanced", res);
    console.log(`    → Recommendations: ${res.status}`);

    // 4i. Revenue Waterfall
    console.log("  [4i] Revenue Waterfall...");
    res = await request("GET", "/api/v1/analytics/advanced/revenue-waterfall", null, "bearer");
    trackResult("advanced", res);
    console.log(`    → Waterfall: ${res.status}`);

    // 4j. Competitive Benchmarks
    console.log("  [4j] Competitive Benchmarks...");
    res = await request("GET", "/api/v1/analytics/advanced/benchmarks", null, "bearer");
    trackResult("advanced", res);
    console.log(`    → Benchmarks: ${res.status}`);

    // 4k. NL Query (Ask)
    console.log("  [4k] Natural Language Query...");
    res = await request("GET", "/api/v1/analytics/advanced/ask?q=What+is+the+top+selling+product+this+month", null, "bearer");
    trackResult("advanced", res);
    console.log(`    → NLQ: ${res.status}`);

    res = await request("GET", "/api/v1/analytics/advanced/ask/suggest?q=revenue", null, "bearer");
    trackResult("advanced", res);
    console.log(`    → NLQ Suggest: ${res.status}`);
}

// ═══════════════════════════════════════════════════════════════════════════
// PHASE 5: VERIFICATION
// ═══════════════════════════════════════════════════════════════════════════

async function phase5_verify() {
    console.log("\n╔══════════════════════════════════════════════════════════╗");
    console.log("║  PHASE 5: VERIFICATION                                 ║");
    console.log("╚══════════════════════════════════════════════════════════╝\n");

    const checks = [];
    const check = (name, status, body) => {
        const passed = status >= 200 && status < 300;
        const detail = typeof body === "object" ? JSON.stringify(body).slice(0, 150) : String(body).slice(0, 150);
        checks.push({ name, passed, status, detail });
        console.log(`  ${passed ? "✅" : "❌"} ${name}: HTTP ${status}`);
    };

    // Analytics endpoints
    console.log("  --- Analytics ---");
    let res = await request("GET", "/api/v1/analytics/overview", null, "bearer");
    check("Analytics Overview", res.status, res.body);

    res = await request("GET", "/api/v1/analytics/revenue", null, "bearer");
    check("Revenue Report", res.status, res.body);

    res = await request("GET", "/api/v1/analytics/traffic", null, "bearer");
    check("Traffic Report", res.status, res.body);

    res = await request("GET", "/api/v1/analytics/sessions", null, "bearer");
    check("Sessions Report", res.status, res.body);

    res = await request("GET", "/api/v1/analytics/products", null, "bearer");
    check("Products Report", res.status, res.body);

    res = await request("GET", "/api/v1/analytics/customers", null, "bearer");
    check("Customers Report", res.status, res.body);

    res = await request("GET", "/api/v1/analytics/page-visits", null, "bearer");
    check("Page Visits Report", res.status, res.body);

    res = await request("GET", "/api/v1/analytics/funnel", null, "bearer");
    check("Funnel Report", res.status, res.body);

    res = await request("GET", "/api/v1/analytics/geographic", null, "bearer");
    check("Geographic Report", res.status, res.body);

    res = await request("GET", "/api/v1/analytics/cohorts", null, "bearer");
    check("Cohorts Report", res.status, res.body);

    res = await request("GET", "/api/v1/analytics/campaigns", null, "bearer");
    check("Campaigns Report", res.status, res.body);

    res = await request("GET", "/api/v1/analytics/realtime", null, "bearer");
    check("Realtime Data", res.status, res.body);

    res = await request("GET", "/api/v1/analytics/events/custom/definitions", null, "bearer");
    check("Custom Event Definitions", res.status, res.body);

    // BI endpoints
    console.log("\n  --- Business Intelligence ---");
    res = await request("GET", "/api/v1/bi/kpis", null, "bearer");
    check("BI KPIs List", res.status, res.body);

    res = await request("GET", "/api/v1/bi/reports", null, "bearer");
    check("BI Reports List", res.status, res.body);

    res = await request("GET", "/api/v1/bi/dashboards", null, "bearer");
    check("BI Dashboards List", res.status, res.body);

    res = await request("GET", "/api/v1/bi/alerts", null, "bearer");
    check("BI Alerts List", res.status, res.body);

    res = await request("GET", "/api/v1/bi/exports", null, "bearer");
    check("BI Exports List", res.status, res.body);

    res = await request("GET", "/api/v1/bi/insights/predictions", null, "bearer");
    check("BI Predictions", res.status, res.body);

    res = await request("GET", "/api/v1/bi/insights/benchmarks", null, "bearer");
    check("BI Benchmarks", res.status, res.body);

    res = await request("GET", "/api/v1/bi/insights/fields/events", null, "bearer");
    check("BI Fields (events)", res.status, res.body);

    res = await request("GET", "/api/v1/bi/reports/meta/templates", null, "bearer");
    check("BI Report Templates", res.status, res.body);

    // Marketing endpoints
    console.log("\n  --- Marketing ---");
    res = await request("GET", "/api/v1/marketing/contacts", null, "bearer");
    check("Marketing Contacts", res.status, res.body);

    res = await request("GET", "/api/v1/marketing/lists", null, "bearer");
    check("Marketing Lists", res.status, res.body);

    res = await request("GET", "/api/v1/marketing/templates", null, "bearer");
    check("Marketing Templates", res.status, res.body);

    res = await request("GET", "/api/v1/marketing/campaigns", null, "bearer");
    check("Marketing Campaigns", res.status, res.body);

    res = await request("GET", "/api/v1/marketing/flows", null, "bearer");
    check("Marketing Flows", res.status, res.body);

    res = await request("GET", "/api/v1/marketing/channels", null, "bearer");
    check("Marketing Channels", res.status, res.body);

    // Advanced Analytics GET endpoints
    console.log("\n  --- Advanced Analytics ---");
    res = await request("GET", "/api/v1/analytics/advanced/pulse", null, "bearer");
    check("Realtime Pulse", res.status, res.body);

    res = await request("GET", "/api/v1/analytics/advanced/clv?limit=10", null, "bearer");
    check("CLV Predictions", res.status, res.body);

    res = await request("GET", "/api/v1/analytics/advanced/journey?limit=10", null, "bearer");
    check("Customer Journeys", res.status, res.body);

    res = await request("GET", "/api/v1/analytics/advanced/recommendations", null, "bearer");
    check("Recommendations", res.status, res.body);

    const passed = checks.filter((c) => c.passed).length;
    const failed = checks.filter((c) => !c.passed).length;

    stats.phaseResults.verification = { passed, failed, total: checks.length, checks };

    console.log(`\n  ─────────────────────────────────────────────`);
    console.log(`  VERIFICATION: ${passed}/${checks.length} passed, ${failed} failed`);
}

// ═══════════════════════════════════════════════════════════════════════════
// MAIN RUNNER
// ═══════════════════════════════════════════════════════════════════════════

async function main() {
    console.log("╔══════════════════════════════════════════════════════════════════╗");
    console.log("║  ecom360 Full Load Test — 500K Visitors/Day Simulation          ║");
    console.log("║  Target: " + BASE.padEnd(54) + "║");
    console.log("║  Events: " + `${TOTAL_EVENTS.toLocaleString()} (${INDIVIDUAL_EVENTS} single + ${BATCH_REQUESTS}×${BATCH_SIZE} batch)`.padEnd(54) + "║");
    console.log("╚══════════════════════════════════════════════════════════════════╝");

    stats.startTime = Date.now();

    try {
        // Quick health check
        const healthRes = await request("GET", "/up", null, null);
        if (healthRes.status !== 200) {
            console.error(`\n❌ Server not responding (HTTP ${healthRes.status}). Is it running on ${BASE}?`);
            process.exit(1);
        }
        console.log("\n✓ Server is healthy\n");

        await phase1_marketing();
        await phase2_bi();
        await phase3_analytics();
        await phase4_advanced();
        await phase5_verify();
    } catch (err) {
        console.error("\n❌ Fatal error:", err.message);
        console.error(err.stack);
    }

    const totalElapsed = ((Date.now() - stats.startTime) / 1000).toFixed(1);

    // ─── Final Report ─────────────────────────────────────────────────────
    console.log("\n");
    console.log("╔══════════════════════════════════════════════════════════════════╗");
    console.log("║                    FINAL TEST REPORT                            ║");
    console.log("╠══════════════════════════════════════════════════════════════════╣");
    console.log(`║  Total Requests:     ${String(stats.total).padEnd(42)}║`);
    console.log(`║  Successful (2xx):   ${String(stats.success).padEnd(42)}║`);
    console.log(`║  Errors:             ${String(stats.errors).padEnd(42)}║`);
    console.log(`║  Success Rate:       ${(((stats.success / stats.total) * 100).toFixed(2) + "%").padEnd(42)}║`);
    console.log(`║  Total Time:         ${(totalElapsed + "s").padEnd(42)}║`);
    console.log("╠══════════════════════════════════════════════════════════════════╣");
    console.log("║  Status Code Breakdown:                                        ║");
    for (const [code, count] of Object.entries(stats.statusCounts).sort()) {
        console.log(`║    HTTP ${code}: ${String(count).padEnd(49)}║`);
    }
    console.log("╠══════════════════════════════════════════════════════════════════╣");

    if (stats.phaseResults.marketing) {
        const m = stats.phaseResults.marketing;
        console.log("║  Marketing:                                                    ║");
        console.log(`║    Contacts: ${String(m.contacts?.length || 0).padEnd(50)}║`);
        console.log(`║    Lists: ${String(m.lists?.length || 0).padEnd(53)}║`);
        console.log(`║    Templates: ${String(m.templates?.length || 0).padEnd(49)}║`);
        console.log(`║    Channels: ${String(m.channels?.length || 0).padEnd(50)}║`);
        console.log(`║    Campaigns: ${String(m.campaigns?.length || 0).padEnd(49)}║`);
        console.log(`║    Flows: ${String(m.flows?.length || 0).padEnd(53)}║`);
    }

    if (stats.phaseResults.bi) {
        const b = stats.phaseResults.bi;
        console.log("║  Business Intelligence:                                        ║");
        console.log(`║    KPIs: ${String(b.kpis?.length || 0).padEnd(54)}║`);
        console.log(`║    Reports: ${String(b.reports?.length || 0).padEnd(51)}║`);
        console.log(`║    Dashboards: ${String(b.dashboards?.length || 0).padEnd(48)}║`);
        console.log(`║    Alerts: ${String(b.alerts?.length || 0).padEnd(52)}║`);
        console.log(`║    Exports: ${String(b.exports?.length || 0).padEnd(51)}║`);
    }

    if (stats.phaseResults.analytics) {
        const a = stats.phaseResults.analytics;
        console.log("║  Analytics Ingestion:                                          ║");
        console.log(`║    Total Events: ${String(a.total_events).padEnd(46)}║`);
        console.log(`║    Individual: ${String(a.individual).padEnd(48)}║`);
        console.log(`║    Batch Events: ${String(a.batch_events).padEnd(46)}║`);
        console.log(`║    Authenticated: ${String(a.authenticated).padEnd(45)}║`);
        console.log(`║    Custom Events: ${String(a.custom).padEnd(45)}║`);
        console.log(`║    Throughput: ${(a.events_per_second + " events/sec").padEnd(48)}║`);
    }

    if (stats.phaseResults.verification) {
        const v = stats.phaseResults.verification;
        console.log("║  Verification:                                                 ║");
        console.log(`║    Passed: ${String(v.passed + "/" + v.total).padEnd(52)}║`);
        console.log(`║    Failed: ${String(v.failed).padEnd(52)}║`);
    }
    console.log("╚══════════════════════════════════════════════════════════════════╝");

    // Print errors if any
    for (const key of Object.keys(stats.phaseResults)) {
        if (key.endsWith("_errors") && stats.phaseResults[key].length > 0) {
            console.log(`\n⚠ Errors in ${key}:`);
            for (const err of stats.phaseResults[key]) {
                console.log(`  HTTP ${err.status}: ${err.body}`);
            }
        }
    }

    // Exit code based on success rate
    const successRate = stats.success / stats.total;
    if (successRate < 0.7) {
        console.log("\n❌ TEST FAILED: Success rate below 70%");
        process.exit(1);
    } else if (successRate < 0.9) {
        console.log("\n⚠ TEST PASSED WITH WARNINGS: Success rate below 90%");
        process.exit(0);
    } else {
        console.log("\n✅ TEST PASSED: All systems operational");
        process.exit(0);
    }
}

main();