#!/usr/bin/env python3
"""Verify all template field mappings against live API data."""
import requests, json

BASE = 'https://ecom.buildnetic.com/api/v1/analytics'
HEADERS = {
    'Authorization': 'Bearer 27|Q4blPK7ETXNPBLhn5BucMKtCaGciZLstQpsifLu1216a8ae2',
    'Accept': 'application/json'
}
PARAMS = {'date_range': '30d'}
tests = []

# 1. Locations — by_country should have sessions > 0
r = requests.get(f'{BASE}/geographic', headers=HEADERS, params=PARAMS).json()['data']
countries = r.get('by_country', [])
cities = r.get('by_city', [])
ct = sum(c.get('sessions', 0) for c in countries)
tests.append(('Locations: countries have sessions', ct > 0, f'{ct} total sessions, {len(countries)} countries'))
tests.append(('Locations: cities have sessions', sum(c.get('sessions', 0) for c in cities) > 0, f'{len(cities)} cities'))

# 2. Entry pages
r = requests.get(f'{BASE}/sessions', headers=HEADERS, params=PARAMS).json()['data']
landing = r.get('top_landing_pages', [])
et = sum(p.get('sessions', 0) for p in landing)
tests.append(('Entry pages: sessions field', et > 0, f'{et} total entries across {len(landing)} pages'))

# 3. Exit pages
exits = r.get('top_exit_pages', [])
xt = sum(p.get('sessions', 0) for p in exits)
tests.append(('Exit pages: sessions field', xt > 0, f'{xt} total exits across {len(exits)} pages'))

# 4. Referrers
r = requests.get(f'{BASE}/campaigns', headers=HEADERS, params=PARAMS).json()['data']
refs = r.get('referrer_sources', [])
rt = sum(ref.get('sessions', 0) for ref in refs)
top_ref = refs[0]['referrer'] if refs else 'none'
tests.append(('Referrers: sessions+referrer fields', rt > 0, f'{rt} visits from {len(refs)} referrers, top: {top_ref}'))

# 5. Products
r = requests.get(f'{BASE}/products', headers=HEADERS, params=PARAMS).json()['data']
perf = r.get('performance', [])
if perf:
    detail = f'{len(perf)} products, first: {perf[0].get("product_name","?")} views={perf[0].get("views",0)} cart_adds={perf[0].get("cart_adds",0)}'
else:
    detail = 'empty'
tests.append(('Products: performance array', len(perf) > 0, detail))

# 6. Realtime
r = requests.get(f'{BASE}/realtime', headers=HEADERS, params=PARAMS).json()['data']
tests.append(('Realtime: active_sessions_5min', 'active_sessions_5min' in r, f'active_5min={r.get("active_sessions_5min","?")} epm={r.get("events_per_minute","?")}'))

# 7. Benchmarks
r = requests.get(f'{BASE}/advanced/benchmarks', headers=HEADERS, params=PARAMS).json()['data']
tests.append(('Benchmarks: message field', 'message' in r, f'msg="{r.get("message","")}" comparison={len(r.get("comparison",[]))} items'))

# 8. Search analytics
r = requests.get(f'{BASE}/search-analytics', headers=HEADERS, params=PARAMS).json()['data']
tests.append(('Search: no_result_rate', 'no_result_rate' in r, f'no_result_rate={r.get("no_result_rate")}, total={r.get("total_searches")}'))

# 9. Recent events (visitor-log)
r = requests.get(f'{BASE}/recent-events', headers=HEADERS, params=PARAMS).json()['data']
events = r.get('events', [])
has_session = events[0].get('session_id') if events else None
has_country = events[0].get('metadata', {}).get('country') if events else None
tests.append(('Visitor log: events with session_id', has_session is not None, f'{len(events)} events, first session={has_session}, country={has_country}'))

# 10. CLV predictions
r = requests.get(f'{BASE}/advanced/clv', headers=HEADERS, params=PARAMS).json()['data']
tests.append(('Predictions: avg_predicted_clv', 'avg_predicted_clv' in r, f'avg_clv={r.get("avg_predicted_clv")}, segments={r.get("segments")}'))

# 11. All pages
r = requests.get(f'{BASE}/all-pages', headers=HEADERS, params=PARAMS).json()['data']
pages = r.get('pages', [])
has_pv = pages[0].get('pageviews', 0) if pages else 0
tests.append(('All pages: pageviews field', has_pv > 0, f'{len(pages)} pages, first pageviews={has_pv}'))

# 12. Revenue
r = requests.get(f'{BASE}/revenue', headers=HEADERS, params=PARAMS).json()['data']
daily = r.get('daily', {})
tests.append(('Revenue: daily parallel arrays', 'dates' in daily and 'revenues' in daily, f'dates={len(daily.get("dates",[]))} total_rev={daily.get("total_revenue")}'))

# 13. Funnel
r = requests.get(f'{BASE}/funnel', headers=HEADERS, params=PARAMS).json()['data']
stages = r.get('stages', [])
tests.append(('Funnel: stages with unique_sessions', len(stages) > 0, f'{len(stages)} stages'))

print()
pass_count = 0
for name, ok, detail in tests:
    status = 'PASS' if ok else 'FAIL'
    if ok:
        pass_count += 1
    icon = '✅' if ok else '❌'
    print(f'  {icon} {status}: {name}')
    print(f'         {detail}')
print(f'\n  {pass_count}/{len(tests)} passed')
