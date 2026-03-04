/**
 * ecom360 Full Load Test – Simulates ~500K visitors/day traffic
 * ──────────────────────────────────────────────────────────────
 * Phases:
 *   1. Setup Marketing (contacts, lists, templates, channels, campaigns, flows)
 *   2. Setup BI (KPIs, reports, dashboards, alerts)
 *   3. Analytics Ingestion (page_view, add_to_cart, purchase, custom events, batches)
 *   4. Advanced Analytics (CLV, Why, Journey, Pulse, Benchmarks, Predictions, etc.)
 *   5. Verification (GET all created resources)
 *
 * Usage:  node tests/load/ecom360-load-test.js
 */

import http from 'node:http';
import crypto from 'node:crypto';

// ─── Configuration ────────────────────────────────────────────────
const BASE = 'http://127.0.0.1:8090';
const API_KEY = 'woo_live_sk_urbanstyle_2026_prod';
const BEARER = '1|61i3sqw6ZPHQ7lmxDndJkC4QJetYFc1gQPbmqHku0213bea6';
const CONCURRENCY = 3; // dev server is single-threaded, keep very low

// ─── Counters ─────────────────────────────────────────────────────
const stats = { total: 0, success: 0, fail: 0, errors: {} };
const created = {
    contacts: [],
    templates: [],
    campaigns: [],
    flows: [],
    channels: [],
    kpis: [],
    reports: [],
    dashboards: [],
    alerts: [],
    lists: []
};

// ─── Helpers ──────────────────────────────────────────────────────
const uid = () => crypto.randomBytes(4).toString('hex');
const pick = arr => arr[Math.floor(Math.random() * arr.length)];
const sleep = ms => new Promise(r => setTimeout(r, ms));

function req(method, path, body, headers = {}) {
    return new Promise((resolve) => {
        const url = new URL(path, BASE);
        const data = body ? JSON.stringify(body) : null;
        const opts = {
            method,
            hostname: url.hostname,
            port: url.port,
            path: url.pathname + url.search,
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                ...headers,
                ...(data ? { 'Content-Length': Buffer.byteLength(data) } : {}),
            },
            timeout: 30000,
        };

        stats.total++;
        const r = http.request(opts, (res) => {
            let chunks = [];
            res.on('data', c => chunks.push(c));
            res.on('end', () => {
                const raw = Buffer.concat(chunks).toString();
                const code = res.statusCode;
                let json;
                try { json = JSON.parse(raw); } catch { json = null; }

                if (code >= 200 && code < 300) {
                    stats.success++;
                    resolve({ ok: true, code, data: json ? .data ? ? json });
                } else {
                    stats.fail++;
                    const key = `${code} ${method} ${url.pathname}`;
                    stats.errors[key] = (stats.errors[key] || 0) + 1;
                    resolve({ ok: false, code, error: json ? .message || raw.substring(0, 200) });
                }
            });
        });

        r.on('error', (e) => {
            stats.fail++;
            const key = `ERR ${method} ${url.pathname}: ${e.code || e.message}`;
            stats.errors[key] = (stats.errors[key] || 0) + 1;
            resolve({ ok: false, code: 0, error: e.message });
        });

        r.on('timeout', () => { r.destroy(); });
        if (data) r.write(data);
        r.end();
    });
}

const auth = () => ({ 'Authorization': `Bearer ${BEARER}` });
const sdk = () => ({ 'X-Ecom360-Key': API_KEY });

async function pool(tasks, concurrency = CONCURRENCY) {
    const results = [];
    let idx = 0;
    const worker = async() => {
        while (idx < tasks.length) {
            const i = idx++;
            results[i] = await tasks[i]();
            await sleep(100); // delay for single-threaded dev server
        }
    };
    await Promise.all(Array.from({ length: Math.min(concurrency, tasks.length) }, () => worker()));
    return results;
}

// ─── Phase 1: Marketing Setup ─────────────────────────────────────
async function phase1() {
    console.log('\n╔══════════════════════════════════════════╗');
    console.log('║  PHASE 1: Marketing Setup                ║');
    console.log('╚══════════════════════════════════════════╝');

    // 1a. Create 50 contacts
    console.log('  → Creating 50 contacts...');
    const contactTasks = Array.from({ length: 50 }, (_, i) => async() => {
        const res = await req('POST', '/api/v1/marketing/contacts', {
            email: `customer${i}@urbanstyle.co`,
            first_name: pick(['Alice', 'Bob', 'Carol', 'Dave', 'Eve', 'Frank', 'Grace', 'Hank']),
            last_name: pick(['Smith', 'Jones', 'Brown', 'Davis', 'Wilson', 'Moore', 'Taylor']),
            phone: `+1555${String(1000 + i).padStart(4, '0')}`,
            tags: pick([
                ['vip'],
                ['new'],
                ['returning'],
                ['wholesale']
            ]),
            status: 'subscribed',
        }, auth());
        if (res.ok) created.contacts.push(res.data.id);
        return res;
    });
    await pool(contactTasks);
    console.log(`    ✓ ${created.contacts.length} contacts created`);

    // 1b. Create 3 contact lists
    console.log('  → Creating contact lists...');
    for (const name of['VIP Customers', 'Newsletter', 'Abandoned Cart']) {
        const res = await req('POST', '/api/v1/marketing/lists', { name }, auth());
        if (res.ok) created.lists.push(res.data.id);
        await sleep(100);
    }
    console.log(`    ✓ ${created.lists.length} lists created`);

    // 1c. Create 3 channels
    console.log('  → Creating channels...');
    for (const ch of[{ name: 'Main Email', type: 'email', provider: 'smtp', config: { host: 'smtp.urbanstyle.co', from: 'hello@urbanstyle.co' } }, { name: 'SMS Gateway', type: 'sms', provider: 'twilio', config: { sid: 'AC_test', from: '+15551234567' } }, { name: 'Push Notifications', type: 'push', provider: 'firebase', config: { project_id: 'urban-push' } }, ]) {
        const res = await req('POST', '/api/v1/marketing/channels', ch, auth());
        if (res.ok) created.channels.push(res.data.id);
        await sleep(100);
    }
    console.log(`    ✓ ${created.channels.length} channels created`);

    // 1d. Create 5 templates
    console.log('  → Creating templates...');
    for (const tpl of[{ name: 'Welcome Email', channel: 'email', subject: 'Welcome to Urban Style!', body_html: '<h1>Welcome {{first_name}}!</h1><p>Shop our latest collection.</p>', body_text: 'Welcome {{first_name}}! Shop our latest collection.' }, { name: 'Cart Reminder', channel: 'email', subject: 'You left items in your cart!', body_html: '<h1>Come back {{first_name}}!</h1><p>Complete your purchase.</p>', body_text: 'Come back! Complete your purchase.' }, { name: 'Flash Sale', channel: 'email', subject: '⚡ Flash Sale - 50% Off!', body_html: '<h1>Flash Sale!</h1><p>50% off everything for 24 hours.</p>', body_text: 'Flash Sale! 50% off everything.' }, { name: 'Order Confirmation', channel: 'sms', subject: null, body_html: null, body_text: 'Hi {{first_name}}, your order #{{order_id}} is confirmed!' }, { name: 'Win-back Push', channel: 'push', subject: 'We miss you!', body_html: null, body_text: 'Come back and get 20% off your next order!' }, ]) {
        const res = await req('POST', '/api/v1/marketing/templates', tpl, auth());
        if (res.ok) created.templates.push(res.data.id);
        await sleep(100);
    }
    console.log(`    ✓ ${created.templates.length} templates created`);

    // 1e. Create 4 campaigns
    console.log('  → Creating campaigns...');
    for (const camp of[{ name: 'Summer Collection Launch', channel: 'email', type: 'one_time', template_id: created.templates[0] || 1, audience: { type: 'all' } }, { name: 'Abandoned Cart Recovery', channel: 'email', type: 'triggered', template_id: created.templates[1] || 1, audience: { type: 'segment', segment: 'cart_abandoners' } }, { name: 'Flash Sale Blast', channel: 'email', type: 'one_time', template_id: created.templates[2] || 1, audience: { type: 'list', list_id: created.lists[0] || 1 } }, { name: 'SMS Order Updates', channel: 'sms', type: 'triggered', template_id: created.templates[3] || 1, audience: { type: 'all' } }, ]) {
        const res = await req('POST', '/api/v1/marketing/campaigns', camp, auth());
        if (res.ok) created.campaigns.push(res.data.id);
        await sleep(100);
    }
    console.log(`    ✓ ${created.campaigns.length} campaigns created`);

    // 1f. Create 3 flows
    console.log('  → Creating automation flows...');
    for (const flow of[{ name: 'Welcome Series', trigger_type: 'event', trigger_config: { event: 'signup' }, steps: [{ action: 'send_email', template_id: created.templates[0] || 1 }] }, { name: 'Cart Abandonment', trigger_type: 'event', trigger_config: { event: 'cart_abandoned' }, steps: [{ action: 'wait', duration: '1h' }, { action: 'send_email', template_id: created.templates[1] || 1 }] }, { name: 'Post-Purchase', trigger_type: 'event', trigger_config: { event: 'purchase' }, steps: [{ action: 'wait', duration: '24h' }, { action: 'send_email', template_id: created.templates[0] || 1 }] }, ]) {
        const res = await req('POST', '/api/v1/marketing/flows', flow, auth());
        if (res.ok) created.flows.push(res.data.id);
        await sleep(100);
    }
    console.log(`    ✓ ${created.flows.length} flows created`);
}

// ─── Phase 2: BI Setup ────────────────────────────────────────────
async function phase2() {
    console.log('\n╔══════════════════════════════════════════╗');
    console.log('║  PHASE 2: BI Setup                       ║');
    console.log('╚══════════════════════════════════════════╝');

    // 2a. Create default KPIs
    console.log('  → Creating KPI defaults...');
    await req('POST', '/api/v1/bi/kpis/defaults', {}, auth());
    await sleep(200);

    // 2b. Create additional KPIs
    console.log('  → Creating custom KPIs...');
    for (const kpi of[{ name: 'Monthly Revenue Target', metric: `monthly_rev_${uid()}`, target_value: 500000, unit: 'currency', direction: 'up', category: 'revenue' }, { name: 'Customer Satisfaction', metric: `csat_${uid()}`, target_value: 4.5, unit: 'number', direction: 'up', category: 'customers' }, { name: 'Avg Response Time', metric: `resp_time_${uid()}`, target_value: 2.0, unit: 'number', direction: 'down', category: 'operations' }, ]) {
        const res = await req('POST', '/api/v1/bi/kpis', kpi, auth());
        if (res.ok) created.kpis.push(res.data.id);
        await sleep(100);
    }
    console.log(`    ✓ ${created.kpis.length} custom KPIs created`);

    // 2c. Create reports
    console.log('  → Creating reports...');
    for (const rpt of[{ name: 'Daily Revenue Report', type: 'standard', config: { metrics: ['revenue', 'orders', 'aov'], period: '7d' } }, { name: 'Customer Acquisition', type: 'standard', config: { metrics: ['customers', 'sessions', 'conversion_rate'], period: '30d' } }, { name: 'Product Performance', type: 'custom', config: { query: 'SELECT product_name, SUM(revenue) FROM purchases GROUP BY product_name', period: '30d' } }, ]) {
        const res = await req('POST', '/api/v1/bi/reports', rpt, auth());
        if (res.ok) created.reports.push(res.data.id);
        await sleep(100);
    }
    console.log(`    ✓ ${created.reports.length} reports created`);

    // 2d. Create dashboards
    console.log('  → Creating dashboards...');
    for (const dash of[{ name: 'Executive Overview', layout: { columns: 3, rows: 4 }, widgets: [{ type: 'kpi', position: [0, 0] }, { type: 'chart', chart_type: 'line', position: [1, 0] }], is_default: true }, { name: 'Marketing Performance', layout: { columns: 2, rows: 3 }, widgets: [{ type: 'campaign_stats' }, { type: 'conversion_funnel' }] }, ]) {
        const res = await req('POST', '/api/v1/bi/dashboards', dash, auth());
        if (res.ok) created.dashboards.push(res.data.id);
        await sleep(100);
    }
    console.log(`    ✓ ${created.dashboards.length} dashboards created`);

    // 2e. Create alerts
    console.log('  → Creating alerts...');
    const kpis = await req('GET', '/api/v1/bi/kpis', null, auth());
    const kpiList = kpis.ok ? (kpis.data ? .data || kpis.data || []) : [];
    const kpiId = kpiList[0] ? .id || created.kpis[0] || 1;

    for (const alert of[{ name: 'Revenue Drop Alert', kpi_id: kpiId, condition: 'below', threshold: 1000, channels: ['email'] }, { name: 'High Cart Abandonment', kpi_id: kpiId, condition: 'above', threshold: 70, channels: ['email', 'slack'] }, { name: 'Anomaly Detection', kpi_id: kpiId, condition: 'anomaly', threshold: 2.5, channels: ['email'] }, ]) {
        const res = await req('POST', '/api/v1/bi/alerts', alert, auth());
        if (res.ok) created.alerts.push(res.data.id);
        await sleep(100);
    }
    console.log(`    ✓ ${created.alerts.length} alerts created`);
}

// ─── Phase 3: Analytics Ingestion (simulating 500K visitors/day) ──
async function phase3() {
    console.log('\n╔══════════════════════════════════════════╗');
    console.log('║  PHASE 3: Analytics Ingestion            ║');
    console.log('╚══════════════════════════════════════════╝');

    const pages = [
        '/products', '/products/summer-dress', '/products/leather-jacket',
        '/products/sneakers-pro', '/products/silk-scarf', '/products/denim-jeans',
        '/cart', '/checkout', '/account', '/blog/summer-trends',
        '/collections/new-arrivals', '/collections/sale', '/',
    ];
    const products = [
        { id: 'PROD-001', name: 'Summer Dress', price: 79.99, category: 'Dresses' },
        { id: 'PROD-002', name: 'Leather Jacket', price: 249.99, category: 'Outerwear' },
        { id: 'PROD-003', name: 'Sneakers Pro', price: 129.99, category: 'Footwear' },
        { id: 'PROD-004', name: 'Silk Scarf', price: 49.99, category: 'Accessories' },
        { id: 'PROD-005', name: 'Denim Jeans', price: 89.99, category: 'Bottoms' },
        { id: 'PROD-006', name: 'Cotton T-Shirt', price: 29.99, category: 'Tops' },
        { id: 'PROD-007', name: 'Wool Blazer', price: 199.99, category: 'Outerwear' },
        { id: 'PROD-008', name: 'Running Shorts', price: 39.99, category: 'Activewear' },
    ];

    // 3a. Individual page_view events (500 events = ~5 sec of traffic at 500K/day)
    console.log('  → Sending 500 page_view events...');
    const pvTasks = Array.from({ length: 500 }, () => async() => {
        const sid = `sess-${uid()}`;
        const vid = `vis-${uid()}`;
        return req('POST', '/api/v1/collect', {
            event_type: 'page_view',
            url: `https://urbanstyle.co${pick(pages)}`,
            session_id: sid,
            visitor_id: vid,
            metadata: {
                title: 'Urban Style Co',
                referrer: pick(['google', 'facebook', 'instagram', 'direct', 'email']),
                device: pick(['mobile', 'desktop', 'tablet']),
                browser: pick(['Chrome', 'Safari', 'Firefox', 'Edge']),
            },
        }, sdk());
    });
    await pool(pvTasks, CONCURRENCY);
    console.log(`    ✓ Page views sent`);

    // 3b. Add-to-cart events (150 events - ~30% view-to-cart)
    console.log('  → Sending 150 add_to_cart events...');
    const atcTasks = Array.from({ length: 150 }, () => async() => {
        const prod = pick(products);
        return req('POST', '/api/v1/collect', {
            event_type: 'add_to_cart',
            url: `https://urbanstyle.co/products/${prod.id}`,
            session_id: `sess-${uid()}`,
            visitor_id: `vis-${uid()}`,
            metadata: {
                product_id: prod.id,
                product_name: prod.name,
                price: prod.price,
                quantity: Math.floor(Math.random() * 3) + 1,
                category: prod.category,
            },
        }, sdk());
    });
    await pool(atcTasks, CONCURRENCY);
    console.log(`    ✓ Add-to-cart events sent`);

    // 3c. Purchase events (50 events - ~33% cart-to-purchase)
    console.log('  → Sending 50 purchase events...');
    const purchTasks = Array.from({ length: 50 }, (_, i) => async() => {
        const prod = pick(products);
        const qty = Math.floor(Math.random() * 3) + 1;
        return req('POST', '/api/v1/collect', {
            event_type: 'purchase',
            url: 'https://urbanstyle.co/checkout/success',
            session_id: `sess-${uid()}`,
            visitor_id: `vis-${uid()}`,
            metadata: {
                order_id: `ORD-${10000 + i}`,
                revenue: +(prod.price * qty).toFixed(2),
                products: [{ id: prod.id, name: prod.name, price: prod.price, quantity: qty }],
                currency: 'USD',
                payment_method: pick(['credit_card', 'paypal', 'apple_pay', 'google_pay']),
                shipping_method: pick(['standard', 'express', 'overnight']),
            },
        }, sdk());
    });
    await pool(purchTasks, CONCURRENCY);
    console.log(`    ✓ Purchase events sent`);

    // 3d. Custom event definitions + custom events (30 events)
    console.log('  → Creating custom event definitions...');
    const customEventKeys = ['wishlist_add', 'product_review', 'share_product', 'newsletter_signup', 'search_query'];
    for (const key of customEventKeys) {
        await req('POST', '/api/v1/analytics/events/custom/definitions', {
            event_key: key,
            display_name: key.replace(/_/g, ' '),
            description: `Custom event: ${key}`,
        }, auth());
        await sleep(100);
    }
    console.log('  → Sending 30 custom events...');
    const customTasks = Array.from({ length: 30 }, () => async() => {
        return req('POST', '/api/v1/analytics/events/custom', {
            event_key: pick(customEventKeys),
            session_id: `sess-${uid()}`,
            url: `https://urbanstyle.co${pick(pages)}`,
            metadata: {
                product_id: pick(products).id,
                source: pick(['homepage', 'search', 'category', 'recommendation']),
                value: +(Math.random() * 100).toFixed(2),
            },
        }, auth());
    });
    await pool(customTasks, CONCURRENCY);
    console.log(`    ✓ Custom events sent`);

    // 3e. Batch events (10 batches × 50 events = 500 events)
    console.log('  → Sending 10 batch requests (50 events each)...');
    for (let b = 0; b < 10; b++) {
        const events = Array.from({ length: 50 }, () => ({
            event_type: pick(['page_view', 'add_to_cart', 'product_view']),
            url: `https://urbanstyle.co${pick(pages)}`,
            session_id: `sess-${uid()}`,
            visitor_id: `vis-${uid()}`,
            metadata: {
                product_id: pick(products).id,
                device: pick(['mobile', 'desktop']),
            },
        }));
        await req('POST', '/api/v1/collect/batch', { events }, sdk());
        await sleep(1200); // 60/min rate limit = 1 per second
    }
    console.log(`    ✓ Batch events sent`);

    // 3f. Ingest endpoint events (20 events)
    console.log('  → Sending 20 ingest events...');
    const ingestTasks = Array.from({ length: 20 }, () => async() => {
        const prod = pick(products);
        return req('POST', '/api/v1/analytics/ingest', {
            payload: {
                event_type: pick(['page_view', 'purchase', 'add_to_cart']),
                session_id: `sess-${uid()}`,
                url: `https://urbanstyle.co${pick(pages)}`,
                metadata: {
                    product_id: prod.id,
                    revenue: prod.price,
                },
            },
        }, auth());
    });
    await pool(ingestTasks, CONCURRENCY);
    console.log(`    ✓ Ingest events sent`);
}

// ─── Phase 4: Advanced Analytics & BI Queries ─────────────────────
async function phase4() {
    console.log('\n╔══════════════════════════════════════════╗');
    console.log('║  PHASE 4: Advanced Analytics & BI        ║');
    console.log('╚══════════════════════════════════════════╝');

    const endpoints = [
        // Advanced Analytics GET endpoints
        ['GET', '/api/v1/analytics/advanced/clv', null, auth()],
        ['POST', '/api/v1/analytics/advanced/clv/what-if', { visitor_id: 'vis-test-001', scenario: { aov_increase_percent: 15 } }, auth()],
        ['POST', '/api/v1/analytics/advanced/why', { metric: 'revenue', start_date: '2026-01-01', end_date: '2026-02-23' }, auth()],
        ['GET', '/api/v1/analytics/advanced/journey', null, auth()],
        ['GET', '/api/v1/analytics/advanced/journey/drop-offs', null, auth()],
        ['GET', '/api/v1/analytics/advanced/pulse', null, auth()],
        ['GET', '/api/v1/analytics/advanced/recommendations', null, auth()],
        ['GET', '/api/v1/analytics/advanced/revenue-waterfall', null, auth()],
        ['GET', '/api/v1/analytics/advanced/benchmarks', null, auth()],
        ['GET', '/api/v1/analytics/advanced/alerts', null, auth()],
        ['POST', '/api/v1/analytics/advanced/triggers/evaluate', { triggers: ['cart_abandon', 'churn_risk'] }, auth()],
        ['GET', '/api/v1/analytics/advanced/audience/segments', null, auth()],
        ['GET', '/api/v1/analytics/advanced/ask?q=what+was+revenue+last+week', null, auth()],
        ['GET', '/api/v1/analytics/advanced/ask/suggest', null, auth()],

        // Standard Analytics GET endpoints
        ['GET', '/api/v1/analytics/overview', null, auth()],
        ['GET', '/api/v1/analytics/revenue', null, auth()],
        ['GET', '/api/v1/analytics/sessions', null, auth()],
        ['GET', '/api/v1/analytics/traffic', null, auth()],
        ['GET', '/api/v1/analytics/products', null, auth()],
        ['GET', '/api/v1/analytics/customers', null, auth()],
        ['GET', '/api/v1/analytics/funnel', null, auth()],
        ['GET', '/api/v1/analytics/cohorts', null, auth()],
        ['GET', '/api/v1/analytics/geographic', null, auth()],
        ['GET', '/api/v1/analytics/realtime', null, auth()],
        ['GET', '/api/v1/analytics/campaigns', null, auth()],

        // BI Insights
        ['POST', '/api/v1/bi/insights/query', { data_source: 'events', filters: { period: '7d' }, group_by: 'event_type', aggregations: ['count'] }, auth()],
        ['GET', '/api/v1/bi/insights/predictions', null, auth()],
        ['POST', '/api/v1/bi/insights/predictions/generate', { model_type: 'clv' }, auth()],
        ['GET', '/api/v1/bi/insights/benchmarks', null, auth()],

        // BI KPI Operations
        ['POST', '/api/v1/bi/kpis/refresh', {}, auth()],
        ['POST', '/api/v1/bi/alerts/evaluate', {}, auth()],
    ];

    let passed = 0;
    for (const [method, path, body, hdrs] of endpoints) {
        const label = `${method} ${path.split('?')[0]}`;
        process.stdout.write(`  → ${label}... `);
        const res = await req(method, path, body, hdrs);
        if (res.ok) {
            passed++;
            console.log(`✓ ${res.code}`);
        } else {
            console.log(`✗ ${res.code} ${(res.error || '').substring(0, 80)}`);
        }
        await sleep(150);
    }
    console.log(`\n    ✓ ${passed}/${endpoints.length} advanced endpoints succeeded`);
}

// ─── Phase 5: Verification (GET all created resources) ────────────
async function phase5() {
    console.log('\n╔══════════════════════════════════════════╗');
    console.log('║  PHASE 5: Verification                   ║');
    console.log('╚══════════════════════════════════════════╝');

    const checks = [
        // Marketing GETs
        ['Marketing Contacts', '/api/v1/marketing/contacts'],
        ['Marketing Templates', '/api/v1/marketing/templates'],
        ['Marketing Campaigns', '/api/v1/marketing/campaigns'],
        ['Marketing Flows', '/api/v1/marketing/flows'],
        ['Marketing Channels', '/api/v1/marketing/channels'],
        ['Marketing Lists', '/api/v1/marketing/lists'],
        // BI GETs
        ['BI KPIs', '/api/v1/bi/kpis'],
        ['BI Reports', '/api/v1/bi/reports'],
        ['BI Dashboards', '/api/v1/bi/dashboards'],
        ['BI Alerts', '/api/v1/bi/alerts'],
        ['BI Exports', '/api/v1/bi/exports'],
        // Analytics
        ['Analytics Overview', '/api/v1/analytics/overview'],
        ['Analytics Revenue', '/api/v1/analytics/revenue'],
        ['Analytics Products', '/api/v1/analytics/products'],
        ['Analytics Realtime', '/api/v1/analytics/realtime'],
    ];

    let passed = 0;
    for (const [label, path] of checks) {
        const res = await req('GET', path, null, auth());
        const icon = res.ok ? '✓' : '✗';
        const count = res.ok ? (Array.isArray(res.data ? .data) ? res.data.data.length : (Array.isArray(res.data) ? res.data.length : '')) : res.code;
        console.log(`  ${icon} ${label}: ${res.ok ? `${res.code} (${count} items)` : `${res.code} ${(res.error || '').substring(0, 60)}`}`);
    if (res.ok) passed++;
    await sleep(100);
  }
  console.log(`\n    ✓ ${passed}/${checks.length} verification checks passed`);
}

// ─── Main ─────────────────────────────────────────────────────────
async function main() {
  console.log('═══════════════════════════════════════════════════════');
  console.log('  ecom360 Full Platform Load Test');
  console.log('  Simulating ~500K visitors/day for Urban Style Co.');
  console.log('  Tenant #885 | API Key: ' + API_KEY.substring(0, 20) + '...');
  console.log('═══════════════════════════════════════════════════════');

  const t0 = Date.now();

  await phase1(); // Marketing setup
  await phase2(); // BI setup
  await phase3(); // Analytics ingestion (1,280 events)
  await phase4(); // Advanced Analytics + BI queries
  await phase5(); // Verification

  const elapsed = ((Date.now() - t0) / 1000).toFixed(1);

  console.log('\n═══════════════════════════════════════════════════════');
  console.log('  RESULTS SUMMARY');
  console.log('═══════════════════════════════════════════════════════');
  console.log(`  Total requests:  ${stats.total}`);
  console.log(`  Successful:      ${stats.success} (${(stats.success / stats.total * 100).toFixed(1)}%)`);
  console.log(`  Failed:          ${stats.fail} (${(stats.fail / stats.total * 100).toFixed(1)}%)`);
  console.log(`  Duration:        ${elapsed}s`);
  console.log(`  Throughput:      ${(stats.total / (elapsed)).toFixed(1)} req/s`);
  console.log('');

  // Created resources summary
  console.log('  Created Resources:');
  console.log(`    Contacts:   ${created.contacts.length}`);
  console.log(`    Lists:      ${created.lists.length}`);
  console.log(`    Templates:  ${created.templates.length}`);
  console.log(`    Campaigns:  ${created.campaigns.length}`);
  console.log(`    Flows:      ${created.flows.length}`);
  console.log(`    Channels:   ${created.channels.length}`);
  console.log(`    KPIs:       ${created.kpis.length}`);
  console.log(`    Reports:    ${created.reports.length}`);
  console.log(`    Dashboards: ${created.dashboards.length}`);
  console.log(`    Alerts:     ${created.alerts.length}`);
  console.log('');

  if (Object.keys(stats.errors).length > 0) {
    console.log('  Error Breakdown:');
    const sorted = Object.entries(stats.errors).sort((a, b) => b[1] - a[1]);
    for (const [key, count] of sorted.slice(0, 15)) {
      console.log(`    ${count}× ${key}`);
    }
  }

  console.log('\n═══════════════════════════════════════════════════════');
  const successRate = (stats.success / stats.total * 100).toFixed(1);
  if (successRate >= 90) {
    console.log(`  ✅ PASS — ${successRate}% success rate`);
  } else if (successRate >= 70) {
    console.log(`  ⚠️  PARTIAL — ${successRate}% success rate`);
  } else {
    console.log(`  ❌ FAIL — ${successRate}% success rate`);
  }
  console.log('═══════════════════════════════════════════════════════\n');
}

main().catch(console.error);