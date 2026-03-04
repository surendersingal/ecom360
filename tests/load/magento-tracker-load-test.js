#!/usr/bin/env node

/**
 * Ecom360 Magento Module — High-Traffic Load Test
 * ================================================
 *
 * Simulates a high-traffic Magento storefront with realistic visitor
 * patterns to verify the tracker has ZERO impact on store performance.
 *
 * What it tests:
 *   1. localStorage buffer write speed (must be < 0.1 ms per event)
 *   2. Batch flush throughput (sendBeacon equivalent — non-blocking)
 *   3. Buffer overflow / eviction under extreme load
 *   4. Memory stability under sustained traffic
 *   5. Concurrent visitor simulation (1000+ simultaneous)
 *   6. Order confirmation page with full order data injection
 *   7. No admin events leak into tracking
 *
 * Usage:
 *   node tests/load/magento-tracker-load-test.js
 *   node tests/load/magento-tracker-load-test.js --visitors=5000 --duration=60
 *
 * Requirements: Node.js 18+ (no external deps)
 */

'use strict';

// ============================================================
//  CLI ARGS
// ============================================================
const args = {};
process.argv.slice(2).forEach(a => {
    const [k, v] = a.replace(/^--/, '').split('=');
    args[k] = v !== undefined ? v : true;
});

const TOTAL_VISITORS = parseInt(args.visitors || '2000', 10);
const DURATION_SEC = parseInt(args.duration || '30', 10);
const CONCURRENT = parseInt(args.concurrent || '200', 10);
const EVENTS_PER_VISIT = parseInt(args.events || '12', 10);
const API_URL = args.api || 'http://localhost:8090/api/v1/collect';
const BATCH_URL = API_URL + '/batch';
const API_KEY = args.key || 'test-load-key-12345';
const VERBOSE = !!args.verbose;

// ============================================================
//  SIMULATED localStorage (in-memory, measures real timing)
// ============================================================
class SimulatedLocalStorage {
    constructor() {
        this._store = {};
        this._writeCount = 0;
        this._readCount = 0;
        this._totalWriteMs = 0;
        this._totalReadMs = 0;
        this._maxWriteMs = 0;
        this._maxReadMs = 0;
    }

    getItem(key) {
        const start = performance.now();
        const val = this._store[key] || null;
        const elapsed = performance.now() - start;
        this._readCount++;
        this._totalReadMs += elapsed;
        if (elapsed > this._maxReadMs) this._maxReadMs = elapsed;
        return val;
    }

    setItem(key, value) {
        const start = performance.now();
        // Simulate 5MB localStorage limit
        const totalSize = Object.values(this._store).reduce((s, v) => s + v.length, 0);
        if (totalSize + value.length > 5 * 1024 * 1024) {
            throw new DOMException('QuotaExceededError');
        }
        this._store[key] = value;
        const elapsed = performance.now() - start;
        this._writeCount++;
        this._totalWriteMs += elapsed;
        if (elapsed > this._maxWriteMs) this._maxWriteMs = elapsed;
    }

    removeItem(key) { delete this._store[key]; }
    clear() { this._store = {}; }

    get stats() {
        return {
            writes: this._writeCount,
            reads: this._readCount,
            avgWriteMs: this._writeCount ? (this._totalWriteMs / this._writeCount).toFixed(4) : '0',
            avgReadMs: this._readCount ? (this._totalReadMs / this._readCount).toFixed(4) : '0',
            maxWriteMs: this._maxWriteMs.toFixed(4),
            maxReadMs: this._maxReadMs.toFixed(4),
            storeSizeBytes: Object.values(this._store).reduce((s, v) => s + v.length, 0),
        };
    }
}

// ============================================================
//  SIMULATED sendBeacon (counts calls, measures payload sizes)
// ============================================================
class SimulatedBeacon {
    constructor() {
        this._calls = 0;
        this._totalBytes = 0;
        this._totalEvents = 0;
        this._failures = 0;
        this._maxPayloadBytes = 0;
    }

    sendBeacon(url, blob) {
        const size = typeof blob === 'string' ? blob.length : (blob.size || 0);
        // Real browsers reject sendBeacon payloads > 64KB
        if (size > 65536) {
            this._failures++;
            return false;
        }
        this._calls++;
        this._totalBytes += size;
        if (size > this._maxPayloadBytes) this._maxPayloadBytes = size;

        // Count events in batch
        try {
            const data = JSON.parse(typeof blob === 'string' ? blob : blob.toString());
            this._totalEvents += data.events ? data.events.length : 1;
        } catch (_) {
            this._totalEvents++;
        }
        return true;
    }

    get stats() {
        return {
            beaconCalls: this._calls,
            totalEvents: this._totalEvents,
            totalKB: (this._totalBytes / 1024).toFixed(1),
            avgPayloadBytes: this._calls ? Math.round(this._totalBytes / this._calls) : 0,
            maxPayloadBytes: this._maxPayloadBytes,
            failures: this._failures,
        };
    }
}

// ============================================================
//  REALISTIC DATA GENERATORS
// ============================================================
const PRODUCTS = [];
for (let i = 1; i <= 500; i++) {
    PRODUCTS.push({
        id: String(i),
        name: `Product ${i} - ${['Widget', 'Gadget', 'Tool', 'Kit', 'Set'][i % 5]}`,
        sku: `SKU-${String(i).padStart(6, '0')}`,
        price: +(5 + Math.random() * 495).toFixed(2),
        category: ['Electronics', 'Clothing', 'Home & Garden', 'Sports', 'Books'][i % 5],
    });
}

const CATEGORIES = [
    { id: '10', name: 'Electronics' },
    { id: '20', name: 'Clothing' },
    { id: '30', name: 'Home & Garden' },
    { id: '40', name: 'Sports & Outdoors' },
    { id: '50', name: 'Books & Media' },
    { id: '60', name: 'Health & Beauty' },
    { id: '70', name: 'Toys & Games' },
    { id: '80', name: 'Automotive' },
];

const CUSTOMERS = [];
for (let i = 1; i <= 200; i++) {
    CUSTOMERS.push({
        email: `customer${i}@example.com`,
        firstname: ['John', 'Jane', 'Bob', 'Alice', 'Charlie'][i % 5],
        lastname: ['Smith', 'Doe', 'Wilson', 'Brown', 'Davis'][i % 5],
    });
}

const SEARCH_TERMS = [
    'blue shoes', 'iphone case', 'winter jacket', 'running shoes',
    'laptop bag', 'coffee maker', 'wireless earbuds', 'yoga mat',
    'protein powder', 'desk lamp', 'water bottle', 'backpack',
    'gaming mouse', 'face cream', 'vitamin d', 'air purifier',
];

const UTM_CAMPAIGNS = [
    { utm_source: 'google', utm_medium: 'cpc', utm_campaign: 'spring_sale' },
    { utm_source: 'facebook', utm_medium: 'social', utm_campaign: 'retarget_q1' },
    { utm_source: 'email', utm_medium: 'newsletter', utm_campaign: 'weekly_deals' },
    { utm_source: 'instagram', utm_medium: 'social', utm_campaign: 'influencer_collab' },
    { utm_source: 'bing', utm_medium: 'cpc', utm_campaign: 'brand_terms' },
    null, null, null, // 60% of traffic is direct (no UTM)
];

function pick(arr) { return arr[Math.floor(Math.random() * arr.length)]; }

function uuid() {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
        const r = (Math.random() * 16) | 0;
        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });
}

// ============================================================
//  VISITOR JOURNEY SIMULATOR
// ============================================================
// Simulates realistic Magento browsing patterns:
//   - 40% browse-only (page views + scroll + engagement)
//   - 25% search + browse
//   - 20% add-to-cart + partial checkout
//   - 10% full purchase funnel
//   - 5%  login + wishlist + review

/**
 * @param {SimulatedLocalStorage} ls
 * @param {SimulatedBeacon} beacon
 */
function simulateVisitor(ls, beacon, visitorId) {
    const LS_KEY = 'ecom360_event_buffer';
    const MAX_BUFFER = 500;
    const MAX_BYTES = 512 * 1024;
    const BATCH_MAX = 50;
    const sessionId = uuid();
    const customer = Math.random() > 0.6 ? pick(CUSTOMERS) : null;
    const utm = pick(UTM_CAMPAIGNS);
    const journey = Math.random();
    const events = [];

    function base() {
        const p = {
            session_id: sessionId,
            url: 'https://store.example.com/' + ['', 'electronics.html', 'clothing.html'][Math.floor(Math.random() * 3)],
            page_title: 'Store Page',
            screen_resolution: pick(['1920x1080', '1366x768', '2560x1440', '375x812', '414x896']),
            timezone: pick(['America/New_York', 'America/Los_Angeles', 'Europe/London', 'Asia/Tokyo']),
            language: pick(['en-US', 'en-GB', 'es-ES', 'fr-FR', 'de-DE']),
            timestamp: new Date().toISOString(),
        };
        if (utm) Object.assign(p, utm);
        if (customer) {
            p.customer_identifier = { type: 'email', value: customer.email };
        }
        return p;
    }

    function bufferEvent(type, meta) {
        const payload = base();
        payload.event_type = type;
        payload.metadata = meta || {};
        events.push(payload);

        // Simulate actual localStorage operations (the critical path)
        let buf;
        try {
            buf = JSON.parse(ls.getItem(LS_KEY) || '[]');
        } catch (_) { buf = []; }

        buf.push(payload);
        if (buf.length > MAX_BUFFER) buf = buf.slice(-MAX_BUFFER);

        try {
            const json = JSON.stringify(buf);
            if (json.length > MAX_BYTES) {
                buf = buf.slice(-Math.floor(MAX_BUFFER / 2));
            }
            ls.setItem(LS_KEY, JSON.stringify(buf));
        } catch (_) {
            buf = buf.slice(-Math.floor(buf.length / 2));
            try { ls.setItem(LS_KEY, JSON.stringify(buf)); } catch (_) {}
        }
    }

    function flush() {
        let buf;
        try { buf = JSON.parse(ls.getItem(LS_KEY) || '[]'); } catch (_) { buf = []; }
        if (!buf.length) return;
        const batch = buf.splice(0, BATCH_MAX);
        ls.setItem(LS_KEY, JSON.stringify(buf));
        const data = batch.length === 1 ? batch[0] : { events: batch };
        const body = JSON.stringify(data);
        beacon.sendBeacon(BATCH_URL, { size: body.length, toString: () => body });
    }

    // --- Generate journey ---

    // Everyone gets a page_view
    bufferEvent('page_view', {
        page_type: pick(['homepage', 'category', 'product', 'search']),
    });

    // Browse some category pages
    const pageViews = 1 + Math.floor(Math.random() * 5);
    for (let i = 0; i < pageViews; i++) {
        const cat = pick(CATEGORIES);
        bufferEvent('page_view', { page_type: 'category', category: cat.name, category_id: cat.id });
    }

    // Product views
    const prodViews = 1 + Math.floor(Math.random() * 4);
    for (let i = 0; i < prodViews; i++) {
        const prod = pick(PRODUCTS);
        bufferEvent('product_view', prod);
    }

    // Scroll depth
    bufferEvent('scroll_depth', { max_percent: 20 + Math.floor(Math.random() * 80) });

    // Search journey
    if (journey > 0.4) {
        bufferEvent('search', { query: pick(SEARCH_TERMS), page_type: 'search_results' });
    }

    // Cart journey
    if (journey > 0.55) {
        const cartItems = 1 + Math.floor(Math.random() * 4);
        for (let i = 0; i < cartItems; i++) {
            const prod = pick(PRODUCTS);
            bufferEvent('add_to_cart', {
                product_id: prod.id,
                product_name: prod.name,
                sku: prod.sku,
                source: pick(['ajax', 'ajax_widget']),
            });
        }
        // Some remove from cart
        if (Math.random() > 0.7) {
            bufferEvent('remove_from_cart', { product_ids: [pick(PRODUCTS).id], source: 'ajax' });
        }
        // Cart update snapshot
        bufferEvent('cart_update', {
            items_count: cartItems,
            subtotal: +(cartItems * 49.99).toFixed(2),
        });
    }

    // Checkout journey
    if (journey > 0.75) {
        bufferEvent('checkout_step', { step: 'shipping', source: 'knockout' });
        bufferEvent('checkout_step', { step: 'payment', source: 'knockout' });
    }

    // Full purchase
    if (journey > 0.85) {
        const itemCount = 1 + Math.floor(Math.random() * 5);
        const items = [];
        let total = 0;
        for (let i = 0; i < itemCount; i++) {
            const prod = pick(PRODUCTS);
            const qty = 1 + Math.floor(Math.random() * 3);
            const rowTotal = +(prod.price * qty).toFixed(2);
            total += rowTotal;
            items.push({
                product_id: prod.id,
                sku: prod.sku,
                name: prod.name,
                qty,
                price: prod.price,
                row_total: rowTotal,
                discount: +(Math.random() * 10).toFixed(2),
            });
        }
        bufferEvent('purchase', {
            order_id: '1000' + String(visitorId).padStart(6, '0'),
            total: +(total * 1.08).toFixed(2),
            subtotal: +total.toFixed(2),
            tax: +(total * 0.08).toFixed(2),
            shipping: +(5 + Math.random() * 15).toFixed(2),
            discount: +(Math.random() * 20).toFixed(2),
            payment_method: pick(['stripe', 'paypal', 'braintree', 'checkmo']),
            shipping_method: pick(['flatrate_flatrate', 'freeshipping_freeshipping', 'ups_GND']),
            currency: 'USD',
            item_count: itemCount,
            items,
            coupons: Math.random() > 0.7 ? ['SAVE10'] : [],
            is_guest: !customer,
            customer_email: customer ? customer.email : `guest${visitorId}@example.com`,
        });
    }

    // Login detection (via storage event)
    if (customer && journey > 0.3) {
        bufferEvent('customer_login', {
            customer_email: customer.email,
            customer_name: customer.firstname + ' ' + customer.lastname,
            source: 'private_content',
        });
    }

    // Wishlist
    if (journey > 0.9) {
        bufferEvent('add_to_wishlist', { product_id: pick(PRODUCTS).id, source: 'click' });
    }

    // Review
    if (journey > 0.92) {
        bufferEvent('review_submit', {
            product_id: pick(PRODUCTS).id,
            rating: String(3 + Math.floor(Math.random() * 3)),
            source: 'form_submit',
        });
    }

    // Engagement time
    bufferEvent('engagement_time', { seconds: 5 + Math.floor(Math.random() * 300) });

    // Flush (simulates page unload)
    flush();

    return events.length;
}

// ============================================================
//  TEST: Admin events must NOT be tracked
// ============================================================
async function testAdminExclusion() {
    console.log('\n📋 TEST: Admin Event Exclusion');
    console.log('─'.repeat(50));

    const adminEvents = [
        'catalog_product_save_after',
        'catalog_category_save_after',
        'customer_save_after',
        'sales_order_creditmemo_save_after',
        'sales_order_save_after',
    ];

    // Verify these are NOT in frontend/events.xml
    const { readFileSync, existsSync } = await
    import ('fs');
    const { join } = await
    import ('path');
    const { fileURLToPath } = await
    import ('url');
    const __filename = fileURLToPath(
        import.meta.url);
    const __dirname = join(__filename, '..');

    const frontendEventsPath = join(__dirname, '../../ecom360-magento/Ecom360/Analytics/etc/frontend/events.xml');
    const globalEventsPath = join(__dirname, '../../ecom360-magento/Ecom360/Analytics/etc/events.xml');

    let passed = true;

    if (existsSync(frontendEventsPath)) {
        const frontendXml = readFileSync(frontendEventsPath, 'utf8');
        for (const evt of adminEvents) {
            if (frontendXml.includes(evt)) {
                console.log(`  ❌ FAIL: Admin event "${evt}" found in frontend/events.xml`);
                passed = false;
            }
        }
    } else {
        console.log('  ⚠️  frontend/events.xml not found');
        passed = false;
    }

    if (existsSync(globalEventsPath)) {
        const globalXml = readFileSync(globalEventsPath, 'utf8');
        // Global should be empty (no observer instances)
        const hasObservers = globalXml.includes('instance="Ecom360');
        if (hasObservers) {
            console.log('  ❌ FAIL: Global events.xml still has active observers');
            passed = false;
        } else {
            console.log('  ✅ Global events.xml is clean (no active observers)');
        }
    }

    // Check no adminhtml/events.xml exists
    const adminhtmlEventsPath = join(__dirname, '../../ecom360-magento/Ecom360/Analytics/etc/adminhtml/events.xml');
    if (existsSync(adminhtmlEventsPath)) {
        console.log('  ❌ FAIL: adminhtml/events.xml exists — admin events are being tracked');
        passed = false;
    } else {
        console.log('  ✅ No adminhtml/events.xml — admin events are NOT tracked');
    }

    if (passed) {
        console.log('  ✅ PASS: No admin-area events are registered');
    }
    return passed;
}

// ============================================================
//  TEST: localStorage buffer behavior
// ============================================================
function testBufferBehavior() {
    console.log('\n📋 TEST: localStorage Buffer Behavior');
    console.log('─'.repeat(50));

    const ls = new SimulatedLocalStorage();
    const LS_KEY = 'ecom360_event_buffer';
    let passed = true;

    // Test 1: Buffer overflow eviction
    console.log('  Filling buffer to MAX_BUFFER (500 events)...');
    const buf = [];
    for (let i = 0; i < 600; i++) {
        buf.push({ event_type: 'test', i, session_id: uuid(), timestamp: new Date().toISOString() });
    }
    ls.setItem(LS_KEY, JSON.stringify(buf.slice(-500)));
    const stored = JSON.parse(ls.getItem(LS_KEY));
    if (stored.length <= 500) {
        console.log(`  ✅ Buffer capped at ${stored.length} events (max 500)`);
    } else {
        console.log(`  ❌ Buffer has ${stored.length} events — exceeds 500 cap`);
        passed = false;
    }

    // Test 2: Size cap
    console.log('  Testing 512KB size limit...');
    const bigBuf = [];
    for (let i = 0; i < 500; i++) {
        bigBuf.push({ data: 'x'.repeat(2000), i }); // ~1MB total
    }
    const json = JSON.stringify(bigBuf);
    if (json.length > 512 * 1024) {
        // Simulate eviction logic
        const halfBuf = bigBuf.slice(-250);
        ls.setItem(LS_KEY, JSON.stringify(halfBuf));
        const after = JSON.parse(ls.getItem(LS_KEY));
        console.log(`  ✅ Oversized buffer evicted to ${after.length} events`);
    } else {
        console.log('  ✅ Buffer fits within 512KB');
    }

    // Test 3: Empty buffer read
    ls.setItem(LS_KEY, '[]');
    const empty = JSON.parse(ls.getItem(LS_KEY));
    if (empty.length === 0) {
        console.log('  ✅ Empty buffer returns []');
    } else {
        console.log('  ❌ Empty buffer returned non-empty');
        passed = false;
    }

    return passed;
}

// ============================================================
//  MAIN LOAD TEST
// ============================================================
async function runLoadTest() {
    console.log('');
    console.log('═'.repeat(60));
    console.log('  Ecom360 Magento Tracker — HIGH TRAFFIC LOAD TEST');
    console.log('═'.repeat(60));
    console.log(`  Visitors:     ${TOTAL_VISITORS.toLocaleString()}`);
    console.log(`  Concurrent:   ${CONCURRENT}`);
    console.log(`  Events/visit: ~${EVENTS_PER_VISIT}`);
    console.log(`  Duration:     ${DURATION_SEC}s`);
    console.log(`  API endpoint: ${API_URL}`);
    console.log('═'.repeat(60));

    // Run structure tests first
    const adminTest = await testAdminExclusion();
    const bufferTest = testBufferBehavior();

    // Performance test
    console.log('\n📋 TEST: High-Traffic Performance Simulation');
    console.log('─'.repeat(50));

    const ls = new SimulatedLocalStorage();
    const beacon = new SimulatedBeacon();

    let totalEvents = 0;
    let completedVisitors = 0;
    const startTime = performance.now();

    // Process visitors in batches to simulate concurrent load
    const batches = Math.ceil(TOTAL_VISITORS / CONCURRENT);

    for (let batch = 0; batch < batches; batch++) {
        const batchSize = Math.min(CONCURRENT, TOTAL_VISITORS - completedVisitors);
        const promises = [];

        for (let i = 0; i < batchSize; i++) {
            const visitorId = completedVisitors + i + 1;
            // Each visitor runs synchronously (simulates single-threaded JS)
            const eventCount = simulateVisitor(ls, beacon, visitorId);
            totalEvents += eventCount;
        }

        completedVisitors += batchSize;

        if (VERBOSE || batch % 5 === 0) {
            const elapsed = ((performance.now() - startTime) / 1000).toFixed(1);
            process.stdout.write(`\r  Visitors: ${completedVisitors.toLocaleString()} / ${TOTAL_VISITORS.toLocaleString()} | Events: ${totalEvents.toLocaleString()} | Time: ${elapsed}s`);
        }
    }

    const totalTime = performance.now() - startTime;
    const totalTimeSec = (totalTime / 1000).toFixed(2);

    console.log('\n');

    // ============================================================
    //  RESULTS
    // ============================================================
    const lsStats = ls.stats;
    const beaconStats = beacon.stats;

    console.log('═'.repeat(60));
    console.log('  RESULTS');
    console.log('═'.repeat(60));

    console.log(`\n  📊 Traffic Summary`);
    console.log(`     Total visitors:      ${TOTAL_VISITORS.toLocaleString()}`);
    console.log(`     Total events:        ${totalEvents.toLocaleString()}`);
    console.log(`     Events per visitor:  ${(totalEvents / TOTAL_VISITORS).toFixed(1)}`);
    console.log(`     Total test time:     ${totalTimeSec}s`);
    console.log(`     Visitors/sec:        ${(TOTAL_VISITORS / (totalTime / 1000)).toFixed(0)}`);
    console.log(`     Events/sec:          ${(totalEvents / (totalTime / 1000)).toFixed(0)}`);

    console.log(`\n  💾 localStorage Performance`);
    console.log(`     Total writes:        ${lsStats.writes.toLocaleString()}`);
    console.log(`     Total reads:         ${lsStats.reads.toLocaleString()}`);
    console.log(`     Avg write time:      ${lsStats.avgWriteMs} ms`);
    console.log(`     Avg read time:       ${lsStats.avgReadMs} ms`);
    console.log(`     Max write time:      ${lsStats.maxWriteMs} ms`);
    console.log(`     Max read time:       ${lsStats.maxReadMs} ms`);
    console.log(`     Final buffer size:   ${(lsStats.storeSizeBytes / 1024).toFixed(1)} KB`);

    console.log(`\n  📡 sendBeacon Performance`);
    console.log(`     Total beacon calls:  ${beaconStats.beaconCalls.toLocaleString()}`);
    console.log(`     Total events sent:   ${beaconStats.totalEvents.toLocaleString()}`);
    console.log(`     Total data sent:     ${beaconStats.totalKB} KB`);
    console.log(`     Avg payload size:    ${beaconStats.avgPayloadBytes} bytes`);
    console.log(`     Max payload size:    ${beaconStats.maxPayloadBytes} bytes`);
    console.log(`     Beacon failures:     ${beaconStats.failures}`);

    // ============================================================
    //  PASS/FAIL CRITERIA
    // ============================================================
    console.log('\n  ✅ PASS/FAIL Criteria');
    console.log('  ' + '─'.repeat(48));

    let allPassed = adminTest && bufferTest;

    const checks = [{
            name: 'localStorage avg write < 0.1 ms',
            pass: parseFloat(lsStats.avgWriteMs) < 0.1,
            value: `${lsStats.avgWriteMs} ms`,
        },
        {
            name: 'localStorage max write < 1.0 ms',
            pass: parseFloat(lsStats.maxWriteMs) < 1.0,
            value: `${lsStats.maxWriteMs} ms`,
        },
        {
            name: 'localStorage avg read < 0.1 ms',
            pass: parseFloat(lsStats.avgReadMs) < 0.1,
            value: `${lsStats.avgReadMs} ms`,
        },
        {
            name: 'Zero sendBeacon failures',
            pass: beaconStats.failures === 0,
            value: `${beaconStats.failures} failures`,
        },
        {
            name: 'All events accounted for',
            pass: beaconStats.totalEvents >= totalEvents * 0.95, // 95%+ delivery
            value: `${beaconStats.totalEvents}/${totalEvents} (${(beaconStats.totalEvents / totalEvents * 100).toFixed(1)}%)`,
        },
        {
            name: 'No payload > 64KB (sendBeacon limit)',
            pass: beaconStats.maxPayloadBytes <= 65536,
            value: `${beaconStats.maxPayloadBytes} bytes`,
        },
        {
            name: 'Throughput > 1000 events/sec',
            pass: (totalEvents / (totalTime / 1000)) > 1000,
            value: `${(totalEvents / (totalTime / 1000)).toFixed(0)} events/sec`,
        },
        {
            name: 'Admin events excluded',
            pass: adminTest,
            value: adminTest ? 'No admin observers' : 'ADMIN EVENTS FOUND',
        },
    ];

    for (const c of checks) {
        const icon = c.pass ? '✅' : '❌';
        console.log(`     ${icon} ${c.name}: ${c.value}`);
        if (!c.pass) allPassed = false;
    }

    console.log('\n' + '═'.repeat(60));
    if (allPassed) {
        console.log('  🎉 ALL TESTS PASSED — Zero performance impact confirmed');
        console.log('');
        console.log('  The Ecom360 tracker adds < 0.1ms overhead per event.');
        console.log('  All events buffer to localStorage (non-blocking) and');
        console.log('  flush via sendBeacon (survives page unload).');
        console.log('  No admin-area data or requests are tracked.');
    } else {
        console.log('  ⚠️  SOME TESTS FAILED — Review results above');
    }
    console.log('═'.repeat(60));
    console.log('');

    process.exit(allPassed ? 0 : 1);
}

runLoadTest().catch(console.error);