#!/usr/bin/env python3
import json, sys

data = json.load(sys.stdin)
groups = {}
for r in data:
    uri = r.get('uri','')
    method = r.get('method','')
    action = r.get('action','')
    name = r.get('name','')
    if 'api/v1/sync' in uri: grp = 'DataSync'
    elif 'api/v1/analytics' in uri: grp = 'Analytics'
    elif 'api/v1/marketing' in uri: grp = 'Marketing'
    elif 'api/v1/chatbot' in uri: grp = 'Chatbot'
    elif 'api/v1/search' in uri: grp = 'AISearch'
    elif 'api/v1/bi' in uri: grp = 'BI'
    elif 'api/v1/' in uri: grp = 'CoreAPI'
    elif uri.startswith('api/'): grp = 'OtherAPI'
    else: grp = 'Web'
    groups.setdefault(grp, []).append({'method': method, 'uri': uri, 'name': name, 'action': action})

for g in sorted(groups.keys()):
    print(f"\n=== {g} ({len(groups[g])} routes) ===")
    for r in groups[g]:
        print(f"  {r['method']:8s} /{r['uri']:60s} {r['name']}")

print(f"\nTOTAL: {len(data)} routes")
