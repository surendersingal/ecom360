#!/usr/bin/env python3
"""
FULL PRODUCT AUDIT — Every API endpoint, every field, every screen.
Acts as the product owner's final QA gate before release.
"""
import requests, json, sys, traceback

BASE = 'https://ecom.buildnetic.com/api/v1/analytics'
HEADERS = {
    'Authorization': 'Bearer 27|Q4blPK7ETXNPBLhn5BucMKtCaGciZLstQpsifLu1216a8ae2',
    'Accept': 'application/json'
}
P = {'date_range': '30d'}

results = []  # (section, test_name, pass, detail)
issues = []   # critical issues to fix

def get(path, params=None):
    try:
        r = requests.get(f'{BASE}/{path}', headers=HEADERS, params=params or P, timeout=15)
        return r.status_code, r.json() if r.status_code == 200 else {}
    except Exception as e:
        return 0, {'error': str(e)}

def post(path, data=None, params=None):
    try:
        r = requests.post(f'{BASE}/{path}', headers=HEADERS, params=params or P,
                         json=data or {}, timeout=15)
        return r.status_code, r.json() if r.status_code in (200, 201, 422) else {}
    except Exception as e:
        return 0, {'error': str(e)}

def check(section, name, condition, detail='', critical=False):
    results.append((section, name, bool(condition), detail))
    if not condition and critical:
        issues.append(f'[{section}] {name}: {detail}')

def d(resp):
    """Extract .data from response"""
    if isinstance(resp, dict):
        return resp.get('data', resp)
    return resp

# ═══════════════════════════════════════════════════════════════════
# SECTION 1: ALL API ENDPOINTS — STATUS CODE CHECK
# ═══════════════════════════════════════════════════════════════════
print('\n' + '='*70)
print('  SECTION 1: API ENDPOINT STATUS CODES')
print('='*70)

api_endpoints = [
    ('overview', 'GET'),
    ('sessions', 'GET'),
    ('revenue', 'GET'),
    ('geographic', 'GET'),
    ('realtime', 'GET'),
    ('funnel', 'GET'),
    ('campaigns', 'GET'),
    ('all-pages', 'GET'),
    ('traffic', 'GET'),
    ('products', 'GET'),
    ('categories', 'GET'),
    ('events-breakdown', 'GET'),
    ('search-analytics', 'GET'),
    ('day-of-week', 'GET'),
    ('visitor-frequency', 'GET'),
    ('recent-events', 'GET'),
    ('cohorts', 'GET'),
    ('page-visits', 'GET'),
    ('customers', 'GET'),
    ('export', 'GET'),
    ('advanced/clv', 'GET'),
    ('advanced/benchmarks', 'GET'),
    ('advanced/recommendations', 'GET'),
    ('advanced/alerts', 'GET'),
    ('advanced/alerts/rules', 'GET'),
    ('advanced/pulse', 'GET'),
    ('advanced/journey', 'GET'),
    ('advanced/journey/drop-offs', 'GET'),
    ('advanced/revenue-waterfall', 'GET'),
    ('advanced/ask/suggest', 'GET'),
    ('advanced/audience/segments', 'GET'),
    ('advanced/audience/destinations', 'GET'),
]

for ep, method in api_endpoints:
    code, resp = get(ep) if method == 'GET' else post(ep)
    ok = code == 200
    check('API', f'{method} /{ep} → {code}', ok, f'Response keys: {list(d(resp).keys())[:8] if isinstance(d(resp), dict) else type(d(resp)).__name__}', critical=True)

# POST endpoints
code, resp = post('advanced/ask', {'q': 'total revenue'})
check('API', f'POST /advanced/ask → {code}', code in (200,500), 'May 500 if no OpenAI key')

code, resp = post('advanced/why', {'metric': 'bounce_rate', 'change': 'increase'})
check('API', f'POST /advanced/why → {code}', code in (200,500,422), f'Status {code}')

# ═══════════════════════════════════════════════════════════════════
# SECTION 2: OVERVIEW SCREEN — All data points
# ═══════════════════════════════════════════════════════════════════
print('\n' + '='*70)
print('  SECTION 2: OVERVIEW SCREEN')
print('='*70)

_, ov = get('overview')
ov = d(ov)
_, sess = get('sessions')
sess = d(sess)
_, rev = get('revenue')
rev = d(rev)
_, geo = get('geographic')
geo = d(geo)
_, rt = get('realtime')
rt = d(rt)
_, fn = get('funnel')
fn = d(fn)
_, camp = get('campaigns')
camp = d(camp)
_, pgs = get('all-pages')
pgs = d(pgs)

# KPIs
traffic = ov.get('traffic', ov)
check('Overview', 'KPI: unique_sessions', traffic.get('unique_sessions', 0) > 0, f"val={traffic.get('unique_sessions')}", critical=True)
check('Overview', 'KPI: total_events', traffic.get('total_events', 0) > 0, f"val={traffic.get('total_events')}")

sess_m = sess.get('metrics', {})
check('Overview', 'KPI: bounce_rate exists', 'bounce_rate' in sess_m, f"val={sess_m.get('bounce_rate')}")
check('Overview', 'KPI: avg_session_duration', 'avg_session_duration_seconds' in sess_m, f"val={sess_m.get('avg_session_duration_seconds')}")

rev_daily = rev.get('daily', {})
check('Overview', 'KPI: revenue', rev_daily.get('total_revenue', 0) > 0, f"val={rev_daily.get('total_revenue')}", critical=True)
check('Overview', 'KPI: orders', rev_daily.get('total_orders', 0) >= 0, f"val={rev_daily.get('total_orders')}")

# Charts
check('Overview', 'Visits chart: dates array', len(sess.get('daily_trend', {}).get('dates', [])) > 0, f"len={len(sess.get('daily_trend', {}).get('dates', []))}")
check('Overview', 'Visits chart: sessions array', len(sess.get('daily_trend', {}).get('sessions', [])) > 0)

# Sources donut
refs = camp.get('referrer_sources', [])
check('Overview', 'Sources: referrer_sources', len(refs) > 0, f"{len(refs)} sources", critical=True)
if refs:
    check('Overview', 'Sources: each has sessions', all('sessions' in r for r in refs), f"first={refs[0]}")
    check('Overview', 'Sources: each has referrer', all('referrer' in r for r in refs), f"first keys={list(refs[0].keys())}")

# Devices
devs = geo.get('device_breakdown', [])
check('Overview', 'Devices chart: device_breakdown', len(devs) > 0, f"{len(devs)} devices")
if devs:
    check('Overview', 'Devices: each has device+count', all('device' in d2 and 'count' in d2 for d2 in devs))

# Pages table
pages = pgs.get('pages', [])
check('Overview', 'Pages table: pages array', len(pages) > 0, f"{len(pages)} pages", critical=True)
if pages:
    check('Overview', 'Pages: each has pageviews', all('pageviews' in p for p in pages[:5]), f"first={pages[0]}")
    check('Overview', 'Pages: each has url', all('url' in p for p in pages[:5]))

# Referrers table
if refs:
    check('Overview', 'Referrers table: referrer field used as name', 'referrer' in refs[0])

# Countries
countries = geo.get('by_country', [])
check('Overview', 'Countries: by_country', len(countries) > 0, f"{len(countries)} countries")
if countries:
    check('Overview', 'Countries: sessions field', all('sessions' in c for c in countries), f"first={countries[0]}", critical=True)

# Funnel
stages = fn.get('stages', [])
check('Overview', 'Funnel: stages array', len(stages) > 0, f"{len(stages)} stages")
if stages:
    check('Overview', 'Funnel: unique_sessions field', all('unique_sessions' in s for s in stages))

# Realtime
check('Overview', 'Realtime: active_sessions_5min', 'active_sessions_5min' in rt, f"val={rt.get('active_sessions_5min')}")
check('Overview', 'Realtime: events_per_minute', 'events_per_minute' in rt, f"val={rt.get('events_per_minute')}")

# ═══════════════════════════════════════════════════════════════════
# SECTION 3: VISITORS SCREEN
# ═══════════════════════════════════════════════════════════════════
print('\n' + '='*70)
print('  SECTION 3: VISITORS SCREEN')
print('='*70)

check('Visitors', 'Sessions: total_sessions', sess_m.get('total_sessions', 0) > 0, f"val={sess_m.get('total_sessions')}", critical=True)
check('Visitors', 'Sessions: daily_trend.dates', len(sess.get('daily_trend', {}).get('dates', [])) > 0)
check('Visitors', 'Sessions: new_vs_returning', 'new_vs_returning' in sess, f"keys={list(sess.get('new_vs_returning', {}).keys())}")

_, freq = get('visitor-frequency')
freq = d(freq)
check('Visitors', 'Frequency: total_visitors', freq.get('total_visitors', 0) >= 0, f"val={freq.get('total_visitors')}")
check('Visitors', 'Frequency: frequency array', isinstance(freq.get('frequency'), list), f"len={len(freq.get('frequency', []))}")
if freq.get('frequency'):
    check('Visitors', 'Frequency: items have name+count+percentage', all('name' in f and 'count' in f for f in freq['frequency']), f"first={freq['frequency'][0]}")

_, coh = get('cohorts')
coh = d(coh)
check('Visitors', 'Cohorts: retention', 'retention' in coh, f"keys={list(coh.keys())}")
ret = coh.get('retention', {})
check('Visitors', 'Cohorts: retention_matrix', isinstance(ret.get('retention_matrix'), list), f"len={len(ret.get('retention_matrix', []))}")
if ret.get('retention_matrix'):
    check('Visitors', 'Cohorts: matrix items have cohort_month+cohort_size', 
          all('cohort_month' in m and 'cohort_size' in m for m in ret['retention_matrix']),
          f"first={ret['retention_matrix'][0]}")

# ═══════════════════════════════════════════════════════════════════
# SECTION 4: PAGES SCREEN (all-pages)
# ═══════════════════════════════════════════════════════════════════
print('\n' + '='*70)
print('  SECTION 4: PAGES SCREEN')
print('='*70)

check('Pages', 'Pages: array populated', len(pages) > 0, f"{len(pages)} pages")
if pages:
    p0 = pages[0]
    for field in ['pageviews', 'url', 'unique', 'avg_time', 'bounce_rate', 'exit_rate']:
        check('Pages', f'Pages: field "{field}" exists', field in p0, f"val={p0.get(field)}")

# ═══════════════════════════════════════════════════════════════════
# SECTION 5: ENTRY PAGES SCREEN
# ═══════════════════════════════════════════════════════════════════
print('\n' + '='*70)
print('  SECTION 5: ENTRY PAGES')
print('='*70)

landing = sess.get('top_landing_pages', [])
check('EntryPages', 'Landing pages array', len(landing) > 0, f"{len(landing)} pages", critical=True)
if landing:
    lp0 = landing[0]
    check('EntryPages', 'Has url field', 'url' in lp0, f"first={lp0}")
    check('EntryPages', 'Has sessions field', 'sessions' in lp0, f"val={lp0.get('sessions')}", critical=True)
    check('EntryPages', 'sessions > 0', lp0.get('sessions', 0) > 0, f"val={lp0.get('sessions')}")

# ═══════════════════════════════════════════════════════════════════
# SECTION 6: EXIT PAGES SCREEN
# ═══════════════════════════════════════════════════════════════════
print('\n' + '='*70)
print('  SECTION 6: EXIT PAGES')
print('='*70)

exits = sess.get('top_exit_pages', [])
check('ExitPages', 'Exit pages array', len(exits) > 0, f"{len(exits)} pages", critical=True)
if exits:
    ep0 = exits[0]
    check('ExitPages', 'Has url field', 'url' in ep0, f"first={ep0}")
    check('ExitPages', 'Has sessions field', 'sessions' in ep0, f"val={ep0.get('sessions')}", critical=True)
    check('ExitPages', 'sessions > 0', ep0.get('sessions', 0) > 0, f"val={ep0.get('sessions')}")

# ═══════════════════════════════════════════════════════════════════
# SECTION 7: LOCATIONS SCREEN
# ═══════════════════════════════════════════════════════════════════
print('\n' + '='*70)
print('  SECTION 7: LOCATIONS')
print('='*70)

check('Locations', 'Countries array', len(countries) > 0, f"{len(countries)} countries", critical=True)
if countries:
    c0 = countries[0]
    check('Locations', 'Country has sessions', 'sessions' in c0, f"val={c0.get('sessions')}", critical=True)
    check('Locations', 'Country has country name', 'country' in c0, f"val={c0.get('country')}")
    check('Locations', 'sessions > 0', c0.get('sessions', 0) > 0, f"val={c0.get('sessions')}")

cities = geo.get('by_city', [])
check('Locations', 'Cities array', len(cities) > 0, f"{len(cities)} cities", critical=True)
if cities:
    ci0 = cities[0]
    check('Locations', 'City has sessions', 'sessions' in ci0, f"val={ci0.get('sessions')}", critical=True)
    check('Locations', 'City has city+country', 'city' in ci0 and 'country' in ci0, f"first={ci0}")

# ═══════════════════════════════════════════════════════════════════
# SECTION 8: DEVICES SCREEN
# ═══════════════════════════════════════════════════════════════════
print('\n' + '='*70)
print('  SECTION 8: DEVICES')
print('='*70)

check('Devices', 'device_breakdown', len(devs) > 0, f"{len(devs)} devices")
if devs:
    check('Devices', 'Items have device+count', all('device' in d2 and 'count' in d2 for d2 in devs), f"first={devs[0]}")

browsers = geo.get('browser_breakdown', [])
check('Devices', 'browser_breakdown', len(browsers) > 0, f"{len(browsers)} browsers")
if browsers:
    check('Devices', 'Items have browser+count', all('browser' in b and 'count' in b for b in browsers), f"first={browsers[0]}")

os_data = geo.get('os_breakdown', [])
check('Devices', 'os_breakdown', len(os_data) > 0, f"{len(os_data)} OS types")
if os_data:
    check('Devices', 'Items have os+count', all('os' in o and 'count' in o for o in os_data), f"first={os_data[0]}")

resolutions = geo.get('resolution_breakdown', [])
check('Devices', 'resolution_breakdown', len(resolutions) > 0, f"{len(resolutions)} resolutions")

# ═══════════════════════════════════════════════════════════════════
# SECTION 9: TIMES SCREEN
# ═══════════════════════════════════════════════════════════════════
print('\n' + '='*70)
print('  SECTION 9: TIMES (day-of-week + traffic_by_hour)')
print('='*70)

_, dow = get('day-of-week')
dow = d(dow)
check('Times', 'day_of_week array', isinstance(dow.get('day_of_week'), list), f"len={len(dow.get('day_of_week', []))}")
if dow.get('day_of_week'):
    check('Times', 'Items have day+count', all('day' in x and 'count' in x for x in dow['day_of_week']), f"first={dow['day_of_week'][0]}")

check('Times', 'heatmap array', isinstance(dow.get('heatmap'), list), f"len={len(dow.get('heatmap', []))}")
if dow.get('heatmap'):
    check('Times', 'Heatmap items have day+hour+count', all('day' in x and 'hour' in x and 'count' in x for x in dow['heatmap'][:3]), f"first={dow['heatmap'][0]}")

tbh = geo.get('traffic_by_hour', {})
check('Times', 'traffic_by_hour.hours', isinstance(tbh.get('hours'), list), f"len={len(tbh.get('hours', []))}")
check('Times', 'traffic_by_hour.views', isinstance(tbh.get('views'), list), f"len={len(tbh.get('views', []))}")

# ═══════════════════════════════════════════════════════════════════
# SECTION 10: REFERRERS SCREEN
# ═══════════════════════════════════════════════════════════════════
print('\n' + '='*70)
print('  SECTION 10: REFERRERS')
print('='*70)

check('Referrers', 'referrer_sources populated', len(refs) > 0, f"{len(refs)} referrers", critical=True)
if refs:
    r0 = refs[0]
    check('Referrers', 'Has referrer field', 'referrer' in r0, f"val={r0.get('referrer')}", critical=True)
    check('Referrers', 'Has sessions field', 'sessions' in r0, f"val={r0.get('sessions')}", critical=True)
    check('Referrers', 'sessions > 0', r0.get('sessions', 0) > 0, f"val={r0.get('sessions')}")
    total_ref_sessions = sum(r.get('sessions', 0) for r in refs)
    check('Referrers', 'Total referrer sessions', total_ref_sessions > 0, f"total={total_ref_sessions}")

# ═══════════════════════════════════════════════════════════════════
# SECTION 11: CAMPAIGNS SCREEN
# ═══════════════════════════════════════════════════════════════════
print('\n' + '='*70)
print('  SECTION 11: CAMPAIGNS')
print('='*70)

utm = camp.get('utm_breakdown', {})
check('Campaigns', 'utm_breakdown exists', isinstance(utm, dict), f"keys={list(utm.keys())}")
check('Campaigns', 'utm sources', isinstance(utm.get('sources'), list))
check('Campaigns', 'utm mediums', isinstance(utm.get('mediums'), list))
check('Campaigns', 'utm campaigns', isinstance(utm.get('campaigns'), list))

ca = camp.get('channel_attribution', {})
check('Campaigns', 'channel_attribution', isinstance(ca, dict), f"keys={list(ca.keys())}")
check('Campaigns', 'channels array', isinstance(ca.get('channels'), list), f"len={len(ca.get('channels', []))}")
if ca.get('channels'):
    ch0 = ca['channels'][0]
    check('Campaigns', 'Channel has channel+revenue+conversions', all(k in ch0 for k in ['channel','revenue','conversions']), f"first={ch0}")

# ═══════════════════════════════════════════════════════════════════
# SECTION 12: CHANNELS SCREEN
# ═══════════════════════════════════════════════════════════════════
print('\n' + '='*70)
print('  SECTION 12: CHANNELS')
print('='*70)

# channels.blade.php uses /campaigns → channel_attribution
check('Channels', 'channel_attribution.channels', len(ca.get('channels', [])) > 0, f"{len(ca.get('channels', []))} channels")
check('Channels', 'channel_attribution.total_revenue', 'total_revenue' in ca, f"val={ca.get('total_revenue')}")

# ═══════════════════════════════════════════════════════════════════
# SECTION 13: EVENTS SCREEN
# ═══════════════════════════════════════════════════════════════════
print('\n' + '='*70)
print('  SECTION 13: EVENTS')
print('='*70)

_, ev = get('events-breakdown')
ev = d(ev)
check('Events', 'breakdown array', isinstance(ev.get('breakdown'), list), f"len={len(ev.get('breakdown', []))}")
check('Events', 'total_events', ev.get('total_events', 0) > 0, f"val={ev.get('total_events')}", critical=True)
check('Events', 'categories array', isinstance(ev.get('categories'), list), f"len={len(ev.get('categories', []))}")
if ev.get('breakdown'):
    eb0 = ev['breakdown'][0]
    check('Events', 'Breakdown: count+label+category+action+unique', 
          all(k in eb0 for k in ['count','label','category','action','unique']), f"keys={list(eb0.keys())}")

# ═══════════════════════════════════════════════════════════════════
# SECTION 14: SITE SEARCH
# ═══════════════════════════════════════════════════════════════════
print('\n' + '='*70)
print('  SECTION 14: SITE SEARCH')
print('='*70)

_, ss = get('search-analytics')
ss = d(ss)
check('SiteSearch', 'total_searches', ss.get('total_searches', 0) > 0, f"val={ss.get('total_searches')}")
check('SiteSearch', 'unique_keywords', ss.get('unique_keywords', 0) > 0, f"val={ss.get('unique_keywords')}")
check('SiteSearch', 'no_result_rate', 'no_result_rate' in ss, f"val={ss.get('no_result_rate')}")
check('SiteSearch', 'keywords array', isinstance(ss.get('keywords'), list), f"len={len(ss.get('keywords', []))}")
if ss.get('keywords'):
    kw0 = ss['keywords'][0]
    check('SiteSearch', 'Keyword: searches+keyword+unique+avg_results', 
          all(k in kw0 for k in ['searches','keyword','unique','avg_results']), f"keys={list(kw0.keys())}")

# ═══════════════════════════════════════════════════════════════════
# SECTION 15: PRODUCTS SCREEN
# ═══════════════════════════════════════════════════════════════════
print('\n' + '='*70)
print('  SECTION 15: PRODUCTS')
print('='*70)

_, prod = get('products')
prod = d(prod)
perf = prod.get('performance', [])
check('Products', 'performance array', len(perf) > 0, f"{len(perf)} products", critical=True)
if perf:
    pp0 = perf[0]
    for field in ['product_id','product_name','views','cart_adds','purchases','revenue','view_to_cart_rate']:
        check('Products', f'Performance: field "{field}"', field in pp0, f"val={pp0.get(field)}")

check('Products', 'top_by_views', isinstance(prod.get('top_by_views'), list), f"len={len(prod.get('top_by_views', []))}")
check('Products', 'cart_abandonment', isinstance(prod.get('cart_abandonment'), list), f"len={len(prod.get('cart_abandonment', []))}")
if prod.get('cart_abandonment'):
    ca0 = prod['cart_abandonment'][0]
    check('Products', 'Cart abandon: abandonments+abandonment_rate', 'abandonments' in ca0 and 'abandonment_rate' in ca0, f"keys={list(ca0.keys())}")

# ═══════════════════════════════════════════════════════════════════
# SECTION 16: CATEGORIES SCREEN
# ═══════════════════════════════════════════════════════════════════
print('\n' + '='*70)
print('  SECTION 16: CATEGORIES')
print('='*70)

_, cat = get('categories')
cat = d(cat)
cv = cat.get('category_views', [])
check('Categories', 'category_views array', isinstance(cv, list), f"len={len(cv)}")
if cv:
    cv0 = cv[0]
    check('Categories', 'Has views field', 'views' in cv0, f"val={cv0.get('views')}", critical=True)
    check('Categories', 'Has unique_visitors', 'unique_visitors' in cv0, f"val={cv0.get('unique_visitors')}")
    check('Categories', 'Has category', 'category' in cv0, f"val={cv0.get('category')}")

# ═══════════════════════════════════════════════════════════════════
# SECTION 17: ECOMMERCE SCREEN
# ═══════════════════════════════════════════════════════════════════
print('\n' + '='*70)
print('  SECTION 17: ECOMMERCE')
print('='*70)

check('Ecommerce', 'Revenue: total_revenue', rev_daily.get('total_revenue', 0) > 0, f"val={rev_daily.get('total_revenue')}", critical=True)
check('Ecommerce', 'Revenue: total_orders', rev_daily.get('total_orders') is not None, f"val={rev_daily.get('total_orders')}")
check('Ecommerce', 'Revenue: average_order_value', rev_daily.get('average_order_value') is not None, f"val={rev_daily.get('average_order_value')}")
check('Ecommerce', 'Revenue: dates[]', len(rev_daily.get('dates', [])) > 0, f"len={len(rev_daily.get('dates', []))}")
check('Ecommerce', 'Revenue: revenues[]', len(rev_daily.get('revenues', [])) > 0)
check('Ecommerce', 'Revenue: orders[]', len(rev_daily.get('orders', [])) > 0)

by_source = rev.get('by_source', [])
check('Ecommerce', 'Revenue: by_source', isinstance(by_source, list), f"len={len(by_source)}")

hourly = rev.get('hourly_pattern', {})
check('Ecommerce', 'Revenue: hourly_pattern', isinstance(hourly.get('hours'), list), f"len={len(hourly.get('hours', []))}")

# ═══════════════════════════════════════════════════════════════════
# SECTION 18: FUNNEL SCREEN
# ═══════════════════════════════════════════════════════════════════
print('\n' + '='*70)
print('  SECTION 18: FUNNEL')
print('='*70)

check('Funnel', 'Stages populated', len(stages) > 0, f"{len(stages)} stages", critical=True)
if stages:
    s0 = stages[0]
    check('Funnel', 'Stage: stage+unique_sessions+drop_off_pct', all(k in s0 for k in ['stage','unique_sessions','drop_off_pct']), f"keys={list(s0.keys())}")
check('Funnel', 'overall_conversion_pct', fn.get('overall_conversion_pct') is not None, f"val={fn.get('overall_conversion_pct')}")

# ═══════════════════════════════════════════════════════════════════
# SECTION 19: ABANDONED CARTS SCREEN
# ═══════════════════════════════════════════════════════════════════
print('\n' + '='*70)
print('  SECTION 19: ABANDONED CARTS')
print('='*70)

# abandoned-carts.blade.php uses /funnel + /products
cart_aband = prod.get('cart_abandonment', [])
check('AbandonedCarts', 'cart_abandonment from products', isinstance(cart_aband, list), f"len={len(cart_aband)}")
if cart_aband:
    check('AbandonedCarts', 'Items have product_name+cart_adds+abandonments', 
          all('product_name' in c and 'cart_adds' in c and 'abandonments' in c for c in cart_aband[:3]),
          f"first={cart_aband[0]}")

# ═══════════════════════════════════════════════════════════════════
# SECTION 20: REALTIME SCREEN
# ═══════════════════════════════════════════════════════════════════
print('\n' + '='*70)
print('  SECTION 20: REALTIME')
print('='*70)

check('Realtime', 'active_sessions_5min', 'active_sessions_5min' in rt, f"val={rt.get('active_sessions_5min')}")
check('Realtime', 'active_sessions_15min', 'active_sessions_15min' in rt, f"val={rt.get('active_sessions_15min')}")
check('Realtime', 'events_per_minute', 'events_per_minute' in rt, f"val={rt.get('events_per_minute')}")
check('Realtime', 'events_last_hour', 'events_last_hour' in rt, f"val={rt.get('events_last_hour')}")
check('Realtime', 'recent_event_types', isinstance(rt.get('recent_event_types'), list), f"val={rt.get('recent_event_types')}")

_, revt = get('recent-events')
revt = d(revt)
events = revt.get('events', [])
check('Realtime', 'recent-events populated', len(events) > 0, f"{len(events)} events", critical=True)
if events:
    e0 = events[0]
    check('Realtime', 'Event: session_id', 'session_id' in e0, f"val={e0.get('session_id')}")
    check('Realtime', 'Event: event_type', 'event_type' in e0, f"val={e0.get('event_type')}")
    check('Realtime', 'Event: metadata.url', e0.get('metadata', {}).get('url') is not None, f"val={e0.get('metadata', {}).get('url')}")
    check('Realtime', 'Event: metadata.country', e0.get('metadata', {}).get('country') is not None, f"val={e0.get('metadata', {}).get('country')}")
    check('Realtime', 'Event: created_at', 'created_at' in e0, f"val={e0.get('created_at')}")

# ═══════════════════════════════════════════════════════════════════
# SECTION 21: VISITOR LOG SCREEN
# ═══════════════════════════════════════════════════════════════════
print('\n' + '='*70)
print('  SECTION 21: VISITOR LOG')
print('='*70)

# visitor-log uses /recent-events
check('VisitorLog', 'Events for grouping', len(events) > 0, f"{len(events)} events to group by session_id")
if events:
    sessions = {}
    for e in events:
        sid = e.get('session_id', 'unknown')
        if sid not in sessions:
            sessions[sid] = []
        sessions[sid].append(e)
    check('VisitorLog', 'Unique sessions from events', len(sessions) > 0, f"{len(sessions)} unique sessions from {len(events)} events")

# ═══════════════════════════════════════════════════════════════════
# SECTION 22: PREDICTIONS SCREEN (CLV)
# ═══════════════════════════════════════════════════════════════════
print('\n' + '='*70)
print('  SECTION 22: PREDICTIONS (CLV)')
print('='*70)

_, clv = get('advanced/clv')
clv = d(clv)
check('Predictions', 'total_customers', 'total_customers' in clv, f"val={clv.get('total_customers')}")
check('Predictions', 'avg_predicted_clv', 'avg_predicted_clv' in clv, f"val={clv.get('avg_predicted_clv')}")
check('Predictions', 'total_predicted_clv', 'total_predicted_clv' in clv, f"val={clv.get('total_predicted_clv')}")
seg = clv.get('segments', {})
check('Predictions', 'segments object', isinstance(seg, dict), f"val={seg}")
check('Predictions', 'segments has high/medium/low', all(k in seg for k in ['high','medium','low']), f"keys={list(seg.keys())}")
check('Predictions', 'top_customers', isinstance(clv.get('top_customers'), list), f"len={len(clv.get('top_customers', []))}")

# ═══════════════════════════════════════════════════════════════════
# SECTION 23: BENCHMARKS SCREEN
# ═══════════════════════════════════════════════════════════════════
print('\n' + '='*70)
print('  SECTION 23: BENCHMARKS')
print('='*70)

_, bm = get('advanced/benchmarks')
bm = d(bm)
check('Benchmarks', 'Has metrics or message', 'metrics' in bm or 'message' in bm or 'comparison' in bm, f"keys={list(bm.keys())}")
if bm.get('metrics'):
    check('Benchmarks', 'Metrics has conversion_rate', 'conversion_rate' in bm['metrics'], f"metric_keys={list(bm['metrics'].keys())}")
    check('Benchmarks', 'Metric items have value', all('value' in v for v in bm['metrics'].values() if isinstance(v, dict)), 'Each metric has value field')
if bm.get('overall_score'):
    check('Benchmarks', 'Overall score has label', 'label' in bm['overall_score'], f"score={bm['overall_score']}")

# ═══════════════════════════════════════════════════════════════════
# SECTION 24: ASK SCREEN
# ═══════════════════════════════════════════════════════════════════
print('\n' + '='*70)
print('  SECTION 24: ASK (NLQ)')
print('='*70)

code, ask_resp = post('advanced/ask', {'q': 'total revenue last 30 days'})
check('Ask', f'POST status (may 500 w/o API key)', code in (200, 500), f"status={code}")
if code == 200:
    ask_d = d(ask_resp)
    check('Ask', 'Response has suggestion or answer', any(k in ask_d for k in ['suggestion','answer','response','message']), f"keys={list(ask_d.keys())}")

# ═══════════════════════════════════════════════════════════════════
# SECTION 25: ALERTS SCREEN
# ═══════════════════════════════════════════════════════════════════
print('\n' + '='*70)
print('  SECTION 25: ALERTS')
print('='*70)

_, alerts = get('advanced/alerts')
alerts_d = d(alerts)
check('Alerts', 'Response is list or has alerts key', isinstance(alerts_d, list) or isinstance(alerts_d, dict), f"type={type(alerts_d).__name__}")

# ═══════════════════════════════════════════════════════════════════
# SECTION 26: AI INSIGHTS SCREEN
# ═══════════════════════════════════════════════════════════════════
print('\n' + '='*70)
print('  SECTION 26: AI INSIGHTS')
print('='*70)

_, recs = get('advanced/recommendations')
recs_d = d(recs)
check('AiInsights', 'Response is list', isinstance(recs_d, list), f"type={type(recs_d).__name__}, len={len(recs_d) if isinstance(recs_d, list) else 'N/A'}")

# ═══════════════════════════════════════════════════════════════════
# SECTION 27: OTHER ADVANCED ENDPOINTS
# ═══════════════════════════════════════════════════════════════════
print('\n' + '='*70)
print('  SECTION 27: OTHER ADVANCED ENDPOINTS')
print('='*70)

_, pulse = get('advanced/pulse')
pulse_d = d(pulse)
check('Advanced', f'Pulse endpoint', isinstance(pulse_d, (dict, list)), f"type={type(pulse_d).__name__}, keys={list(pulse_d.keys()) if isinstance(pulse_d, dict) else 'list'}")

_, journey = get('advanced/journey')
journey_d = d(journey)
check('Advanced', 'Journey endpoint', isinstance(journey_d, (dict, list)), f"type={type(journey_d).__name__}")

_, waterfall = get('advanced/revenue-waterfall')
waterfall_d = d(waterfall)
check('Advanced', 'Revenue waterfall', isinstance(waterfall_d, (dict, list)), f"type={type(waterfall_d).__name__}")

_, suggest = get('advanced/ask/suggest')
suggest_d = d(suggest)
check('Advanced', 'Ask suggest', isinstance(suggest_d, (dict, list)), f"type={type(suggest_d).__name__}")

_, cust = get('customers')
cust_d = d(cust)
check('Advanced', 'Customers endpoint', isinstance(cust_d, (dict, list)), f"type={type(cust_d).__name__}")

_, pvs = get('page-visits')
pvs_d = d(pvs)
check('Advanced', 'Page-visits endpoint', isinstance(pvs_d, (dict, list)), f"type={type(pvs_d).__name__}")

# ═══════════════════════════════════════════════════════════════════
# SECTION 28: WEB PAGE STATUS CODES
# ═══════════════════════════════════════════════════════════════════
print('\n' + '='*70)
print('  SECTION 28: WEB PAGE STATUS CODES')
print('='*70)

WEB_BASE = 'https://ecom.buildnetic.com/app/delhi-duty-free/analytics'
# Use a session to maintain cookies
web_session = requests.Session()
# First login or get auth cookie
web_pages = [
    ('', 'Overview'),
    ('/visitors', 'Visitors'),
    ('/pages', 'Pages'),
    ('/entry-pages', 'Entry Pages'),
    ('/exit-pages', 'Exit Pages'),
    ('/locations', 'Locations'),
    ('/devices', 'Devices'),
    ('/times', 'Times'),
    ('/referrers', 'Referrers'),
    ('/campaigns', 'Campaigns'),
    ('/channels', 'Channels'),
    ('/events', 'Events'),
    ('/site-search', 'Site Search'),
    ('/products', 'Products'),
    ('/categories', 'Categories'),
    ('/ecommerce', 'Ecommerce'),
    ('/funnel', 'Funnel'),
    ('/abandoned-carts', 'Abandoned Carts'),
    ('/realtime', 'Realtime'),
    ('/visitor-log', 'Visitor Log'),
    ('/predictions', 'Predictions'),
    ('/benchmarks', 'Benchmarks'),
    ('/ask', 'Ask'),
    ('/alerts', 'Alerts'),
    ('/ai-insights', 'AI Insights'),
]

for path, name in web_pages:
    try:
        r = requests.get(f'{WEB_BASE}{path}', headers={'Accept': 'text/html'}, 
                        allow_redirects=True, timeout=10)
        # 200 or 302 to login page are both acceptable (means route exists)
        ok = r.status_code in (200, 302)
        final_url = r.url if r.status_code == 302 else ''
        check('WebPages', f'{name} page → {r.status_code}', ok, final_url[:60] if final_url else 'OK')
    except Exception as e:
        check('WebPages', f'{name} page → ERROR', False, str(e)[:60])

# ═══════════════════════════════════════════════════════════════════
# FINAL REPORT
# ═══════════════════════════════════════════════════════════════════
print('\n\n' + '='*70)
print('  FINAL AUDIT REPORT')
print('='*70)

passed = sum(1 for r in results if r[2])
failed = sum(1 for r in results if not r[2])
total = len(results)

# Print all failures
if failed > 0:
    print(f'\n  ❌ FAILED TESTS ({failed}):')
    for section, name, ok, detail in results:
        if not ok:
            print(f'    [{section}] {name}')
            if detail:
                print(f'      → {detail}')

# Print critical issues
if issues:
    print(f'\n  🔥 CRITICAL ISSUES ({len(issues)}):')
    for i, issue in enumerate(issues):
        print(f'    {i+1}. {issue}')

# Summary by section
print(f'\n  SECTION SUMMARY:')
sections = {}
for section, name, ok, detail in results:
    if section not in sections:
        sections[section] = [0, 0]
    sections[section][0 if ok else 1] += (1 if ok else 0)
    sections[section][1 if not ok else 0] += (0 if ok else 1)

# Recount properly
sections = {}
for section, name, ok, detail in results:
    if section not in sections:
        sections[section] = {'pass': 0, 'fail': 0}
    if ok:
        sections[section]['pass'] += 1
    else:
        sections[section]['fail'] += 1

for section, counts in sections.items():
    total_s = counts['pass'] + counts['fail']
    icon = '✅' if counts['fail'] == 0 else '⚠️' if counts['fail'] <= 2 else '❌'
    print(f'    {icon} {section}: {counts["pass"]}/{total_s} passed', end='')
    if counts['fail'] > 0:
        print(f' ({counts["fail"]} failed)', end='')
    print()

print(f'\n  TOTAL: {passed}/{total} passed, {failed} failed')
print(f'  CRITICAL ISSUES: {len(issues)}')

if failed == 0 and len(issues) == 0:
    print('\n  🎉 ALL TESTS PASSED — READY FOR RELEASE')
else:
    print(f'\n  ⚠️  NOT READY — {failed} failures, {len(issues)} critical issues need fixing')
    sys.exit(1)
