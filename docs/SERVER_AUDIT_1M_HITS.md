# Production Server Audit & 1M Hits/Day Optimization Plan

**Server**: 13.204.186.178 (ddfapp-aws) — delhidutyfree.co.in  
**Date**: June 2025  
**Target**: 1,000,000 hits/day (~11.6 requests/second avg, ~40-50 rps peak)

---

## Current Infrastructure Summary

| Component | Version/Config | Status |
|---|---|---|
| **EC2 Instance** | 16 vCPU (Intel Xeon 8275CL 3.0GHz), 30GB RAM, NVMe SSD | ✅ Good |
| **OS** | Ubuntu 24.04.1 LTS, kernel 6.17.0 | ✅ Good |
| **PHP** | 8.3.6 + OPcache (1GB, 130K files) | ⚠️ Needs tuning |
| **PHP-FPM** | dynamic, max_children=25, max_requests=1000 | ⚠️ Needs tuning |
| **Apache** | 2.4.58 mpm_event, MaxRequestWorkers=150, HTTP/2 | ⚠️ Needs tuning |
| **Redis** | 7.0.15, maxmemory=4GB, used=135MB, 75% hit rate | ⚠️ Low hit rate |
| **Elasticsearch** | Single node, yellow status, 2GB heap | ⚠️ Under-resourced |
| **MySQL** | RDS (shared), ddfdev DB | ✅ Managed |
| **Magento** | 2.4.7-p3, production mode, all caches ON | ✅ Good base |
| **CDN** | **NONE** | 🔴 Critical gap |
| **Varnish** | **NOT installed** | 🔴 Critical gap |
| **Brotli** | **NOT installed** | 🟡 Missing |
| **Magento Cron** | **NOT configured** | 🔴 Critical gap |
| **CSF Firewall** | v15, CT_LIMIT=100 | ✅ OK |
| **SSL** | Let's Encrypt, expires Apr 12, 2026 | ✅ Good |
| **Disk** | 145GB total, 36% used, NVMe 4.36% utilization | ✅ Healthy |

---

## 🔴 CRITICAL ISSUES (Must Fix Immediately)

### 1. No CDN — Apache Serving Everything Directly

**Current**: Every request (HTML, CSS, JS, images, fonts) hits your Apache server directly.  
**Impact**: For 1M hits/day, you're looking at ~40-50 rps at peak. Without a CDN, ALL of that hits the origin server.

**Solution**: Deploy **Cloudflare** (free tier works, Pro at $20/mo is ideal)

```
User → Cloudflare Edge (cache static + HTML) → Apache (only cache misses)
```

**Expected improvement**:
- 60-80% of requests served from CDN edge (static assets, cached pages)
- Drop origin server load from ~50 rps to ~10-15 rps
- Global latency improvement (Cloudflare has edge nodes in Mumbai)
- Free DDoS protection & WAF
- Automatic SSL, HTTP/3 support

**Implementation**:
1. Sign up at cloudflare.com, add delhidutyfree.co.in
2. Change DNS nameservers to Cloudflare
3. Set SSL mode to "Full (strict)"
4. Configure Page Rules:
   - `*.css, *.js, *.png, *.jpg, *.webp, *.woff2` → Cache Everything, Edge TTL 30 days
   - `*/checkout/*` → Bypass Cache
   - `*/customer/*` → Bypass Cache
5. Enable "Always Online", "Auto Minify", "Brotli"

**Priority**: 🔴 DO THIS FIRST — single biggest performance gain

---

### 2. No Varnish Cache (Full Page Cache at HTTP Level)

**Current**: Redis FPC is serving cached pages, but PHP-FPM still processes every request (bootstrap, config load, cache lookup).  
**Impact**: Each request still consumes ~5-20MB RAM and ~50-200ms even with Redis FPC.

**Solution**: Install **Varnish 7.x** as reverse proxy in front of Apache

```
User → Cloudflare → Varnish (:80) → Apache (:8080)
```

**Expected improvement**:
- Cached pages served in <1ms from Varnish memory (vs 50-200ms via PHP+Redis)
- 90%+ cache hit rate for catalog pages
- PHP-FPM handles only uncacheable requests (cart, checkout, customer area)
- Reduce PHP-FPM load by 80-90%

**Implementation**:
```bash
# Install Varnish
apt install varnish

# Magento provides Varnish VCL config
bin/magento varnish:vcl:generate --backend-host=127.0.0.1 --backend-port=8080 > /etc/varnish/default.vcl

# Move Apache to port 8080
# Set Varnish on port 80
# Configure Magento: Stores > Config > System > Full Page Cache > Varnish
```

**Priority**: 🔴 Critical — will reduce server load dramatically

---

### 3. No Magento Cron Configured

**Current**: ZERO Magento cron jobs in any crontab (root, www-data, ubuntu). Only custom crons exist (Razorpay log chown, price/inventory API calls).  
**Impact**: 
- Indexers won't run automatically (currently all "idle" — they'll become stale)
- Scheduled emails won't send
- Cart abandonment won't clean up
- Catalog price rules won't apply on schedule
- Queue consumers won't process
- Session cleanup won't run

**Solution**: Add Magento cron to www-data crontab:

```bash
sudo crontab -u www-data -e

# Add these lines:
* * * * * /usr/bin/php /var/www/html/bin/magento cron:run >> /var/www/html/var/log/magento.cron.log 2>&1
* * * * * /usr/bin/php /var/www/html/update/cron.php >> /var/www/html/var/log/update.cron.log 2>&1
* * * * * /usr/bin/php /var/www/html/bin/magento setup:cron:run >> /var/www/html/var/log/setup.cron.log 2>&1
```

**Priority**: 🔴 Critical — indexes and scheduled tasks are NOT running

---

### 4. PHP Dangerous Defaults: No Memory/Time Limits

**Current**:
- `memory_limit = -1` (unlimited!)
- `max_execution_time = 0` (unlimited!)

**Impact**: A single runaway PHP process can consume ALL server memory. Under load, this WILL cause OOM kills and server crashes.

**Solution**: Set safe limits in `/etc/php/8.3/fpm/php.ini`:

```ini
memory_limit = 2G          ; Magento needs generous memory, but not unlimited
max_execution_time = 300    ; 5 minutes is more than enough
max_input_time = 300
```

Then restart: `systemctl restart php8.3-fpm`

**Priority**: 🔴 Critical for stability under load

---

## 🟡 HIGH PRIORITY (Fix Within 1-2 Weeks)

### 5. PHP-FPM Pool Needs Tuning for 1M Hits

**Current**: `pm = dynamic`, max_children=25, start_servers=8, min_spare=8, max_spare=24, max_requests=1000

**Analysis**: With 30GB RAM and Varnish handling most requests:
- Each PHP-FPM worker ≈ 100-200MB average for Magento
- 25 workers × 200MB = 5GB max — conservative for 30GB RAM
- But WITHOUT Varnish, 25 workers is a bottleneck at peak

**Recommended Config** (with Varnish):
```ini
[www]
pm = dynamic
pm.max_children = 40        ; Was 25, can handle more with Varnish reducing load
pm.start_servers = 12
pm.min_spare_servers = 8
pm.max_spare_servers = 30
pm.max_requests = 500        ; Reduce to recycle memory faster (was 1000)
pm.process_idle_timeout = 10s
```

**Recommended Config** (WITHOUT Varnish, temporary):
```ini
[www]
pm = dynamic
pm.max_children = 50         ; Need more workers when every request hits PHP
pm.start_servers = 15
pm.min_spare_servers = 10
pm.max_spare_servers = 40
pm.max_requests = 500
```

**Priority**: 🟡 High — tune after installing Varnish

---

### 6. Apache MPM Event Tuning

**Current**: MaxRequestWorkers=150, ThreadsPerChild=25, StartServers=2

**Problem**: With Varnish, Apache becomes a backend and 150 workers is fine. Without Varnish, 150 may bottleneck at peak.

**Recommended** (after adding Varnish):
```apache
<IfModule mpm_event_module>
    StartServers             3
    MinSpareThreads         75
    MaxSpareThreads        250
    ThreadLimit             64
    ThreadsPerChild         25
    MaxRequestWorkers      200
    MaxConnectionsPerChild   0
    ServerLimit              8
</IfModule>
```

Also add these performance headers to Apache config:
```apache
# Enable Keep-Alive
KeepAlive On
MaxKeepAliveRequests 100
KeepAliveTimeout 5

# File descriptor caching
EnableMMAP On
EnableSendfile On
```

**Priority**: 🟡 High

---

### 7. Redis Cache Hit Rate Too Low (75%)

**Current**: 389K hits / 130K misses = ~75% hit rate. Should be 90%+ for production.

**Diagnosis**: Likely causes:
- Magento full page cache TTL too short
- Missing Redis pipeline optimization
- Cache invalidation too aggressive
- Possible key evictions (check with `redis-cli info stats | grep evicted`)

**Solution**:
```bash
# Check for evictions
redis-cli info stats | grep evicted

# Check memory fragmentation
redis-cli info memory | grep ratio

# Increase maxmemory if evictions happening
# In /etc/redis/redis.conf:
maxmemory 6gb              # Increase from 4GB → 6GB (you have RAM to spare)
maxmemory-policy allkeys-lru  # Already correct

# Optimize Magento cache config in env.php
# Add L2 cache (shared across FPM workers):
```

In `app/etc/env.php`, add L2 cache:
```php
'cache' => [
    'frontend' => [
        'default' => [
            'backend' => 'Magento\\Framework\\Cache\\Backend\\Redis',
            'backend_options' => [
                'server' => '127.0.0.1',
                'port' => '6379',
                'database' => '0',
                'compress_data' => '1',     // Enable compression
                'compression_lib' => 'lz4', // Fast compression
            ],
            'id_prefix' => 'ddf_'           // Namespace to avoid conflicts
        ],
        'page_cache' => [
            'backend' => 'Magento\\Framework\\Cache\\Backend\\Redis',
            'backend_options' => [
                'server' => '127.0.0.1',
                'port' => '6379',
                'database' => '1',
                'compress_data' => '0',     // FPC should not compress (speed)
            ],
            'id_prefix' => 'ddf_'
        ]
    ]
]
```

**Priority**: 🟡 High — directly affects page load times

---

### 8. search_query Table Bloat — Recurring Problem

**Current**: Already truncated multiple times. Root cause is Magento logging every search query.

**Solution**: Add a cron to clean old search queries weekly:

```bash
# Add to www-data crontab:
0 3 * * 0 /usr/bin/mysql -u ddfdev -p'EItA]-TRp!9*Ff_nleBGsZCW>7W*' -h ddfliveadhicine.cj48eecsop04.ap-south-1.rds.amazonaws.com ddfdev -e "DELETE FROM search_query WHERE updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY);" >> /var/log/magento-cleanup.log 2>&1
```

OR disable search query logging in Magento admin:
- Stores → Configuration → Catalog → Catalog Search → "Search Term" → Disable "Popular Search Terms"

**Priority**: 🟡 High — caused server crash before

---

### 9. TIME_WAIT Socket Accumulation

**Current**: 4,165 TCP connections in TIME_WAIT state! Only 216 established.

**Impact**: Each TIME_WAIT socket holds OS resources. At 1M hits/day, this will grow to 10,000+ and cause "cannot assign requested address" errors.

**Solution**: Tune kernel TCP settings:

```bash
# Add to /etc/sysctl.conf:
net.ipv4.tcp_tw_reuse = 1              # Reuse TIME_WAIT sockets
net.ipv4.tcp_fin_timeout = 15          # Reduce FIN timeout (default 60)
net.ipv4.tcp_max_tw_buckets = 20000    # Max TIME_WAIT sockets
net.core.somaxconn = 65535             # Max socket backlog
net.core.netdev_max_backlog = 65535    # Max network device backlog
net.ipv4.tcp_max_syn_backlog = 65535   # Max SYN backlog
net.ipv4.ip_local_port_range = 1024 65535  # More ephemeral ports

# Apply immediately
sysctl -p
```

**Priority**: 🟡 High — will cause failures at high traffic

---

### 10. Install Brotli Compression

**Current**: Only gzip enabled. Brotli provides 15-25% better compression ratios.

**Solution**:
```bash
apt install brotli libbrotli-dev
# Install mod_brotli for Apache (if available) or use Cloudflare Brotli (free)
```

If using Cloudflare, Brotli is automatic — no server config needed.

**Priority**: 🟡 Medium — Cloudflare handles this automatically

---

## 🟢 RECOMMENDED OPTIMIZATIONS

### 11. OPcache Fine-Tuning

**Current**: Good base config (1GB memory, 130K files, revalidate_freq=2)

**Optimize** for production:
```ini
; In /etc/php/8.3/fpm/php.ini
opcache.revalidate_freq = 60          ; Check timestamps every 60s (was 2)
opcache.validate_timestamps = 0       ; Disable in production (manual reset on deploy)
opcache.save_comments = 1             ; Required by Magento DI
opcache.enable_file_override = 1      ; Optimize file_exists/is_file calls
opcache.huge_code_pages = 1           ; Use huge pages for code
```

⚠️ With `validate_timestamps=0`, you MUST run `php-fpm reload` after any code deploy.

**Priority**: 🟢 Nice-to-have — small but free performance gain

---

### 12. Elasticsearch Optimization

**Current**: Single node, yellow status, 2GB heap, ~2.6GB RSS, only ~7MB index data.

**Issues**: Yellow status means replica shards unassigned (normal for single node but not ideal).

**Solution**:
```bash
# Suppress yellow status (single node can't have replicas)
curl -X PUT "localhost:9200/_settings" -H 'Content-Type: application/json' -d '{
  "index.number_of_replicas": 0
}'

# Optimize JVM heap (2GB is OK for current data size)
# In /etc/elasticsearch/jvm.options:
-Xms2g
-Xmx2g
```

For 1M hits/day, consider **OpenSearch** (Magento compatible, better memory efficiency) or **ElasticSuite** for better search relevancy.

**Priority**: 🟢 Low — current setup handles the data fine

---

### 13. MySQL Query Optimization

**Current**: Using RDS (managed). Can't tune server config, but can optimize queries.

**Recommendations**:
```sql
-- Check slow queries
SHOW VARIABLES LIKE 'slow_query_log%';

-- Enable if not already
SET GLOBAL slow_query_log = 'ON';
SET GLOBAL long_query_time = 2;

-- Check table sizes
SELECT table_name, round(data_length/1024/1024,2) AS data_mb, 
       round(index_length/1024/1024,2) AS index_mb 
FROM information_schema.tables 
WHERE table_schema = 'ddfdev' 
ORDER BY data_length DESC LIMIT 20;
```

Also:
- Use `OPTIMIZE TABLE` on large tables periodically
- Ensure all Magento indexes are up to date (requires cron!)
- Consider read replica for analytics queries

**Priority**: 🟢 Medium

---

### 14. Image Optimization

**Recommendations**:
- Enable Magento's built-in WebP conversion (Stores > Config > General > Web > Optimize Images)
- Lazy load below-fold images
- Use `<picture>` elements with WebP + JPEG fallback
- Serve responsive images (srcset)
- If using Cloudflare Pro ($20/mo): Enable **Polish** for automatic image optimization

**Priority**: 🟢 Medium — reduces bandwidth significantly

---

### 15. Security Hardening for High Traffic

**Current**: CSF with basic config.

**Recommendations**:
```bash
# In /etc/csf/csf.conf:
CT_LIMIT = 200               # Increase for high traffic (was 100)
CT_INTERVAL = 30              # Keep at 30
SYNFLOOD_RATE = "200/s"       # Increase for high traffic (was 100/s)
SYNFLOOD_BURST = "300"        # Increase (was 150)

# Rate limit API endpoints in Apache
<Location "/rest/V1/">
    <IfModule mod_ratelimit.c>
        SetOutputFilter RATE_LIMIT
        SetEnv rate-limit 400
    </IfModule>
</Location>
```

Consider adding **fail2ban** for Magento admin brute force protection.

**Priority**: 🟢 Nice-to-have

---

## Implementation Priority Roadmap

### Phase 1: Immediate (This Week) 🔴
| # | Task | Time | Impact |
|---|---|---|---|
| 1 | Fix PHP memory_limit & max_execution_time | 5 min | Prevents OOM crashes |
| 2 | Add Magento cron jobs | 10 min | Enables indexing, cleanup |
| 3 | Sign up Cloudflare + configure CDN | 1 hour | 60-80% load reduction |
| 4 | Tune kernel TCP (TIME_WAIT) | 10 min | Prevents socket exhaustion |

### Phase 2: Short Term (1-2 Weeks) 🟡
| # | Task | Time | Impact |
|---|---|---|---|
| 5 | Install & configure Varnish | 2-3 hours | 80-90% PHP load reduction |
| 6 | Tune PHP-FPM pool | 15 min | Better resource utilization |
| 7 | Tune Apache MPM | 15 min | Handle more concurrent connections |
| 8 | Add search_query cleanup cron | 10 min | Prevent recurring bloat |
| 9 | Increase Redis maxmemory to 6GB | 5 min | Better cache hit rate |

### Phase 3: Medium Term (1 Month) 🟢
| # | Task | Time | Impact |
|---|---|---|---|
| 10 | OPcache production tuning | 10 min | Small speed gain |
| 11 | Elasticsearch optimization | 30 min | Better search performance |
| 12 | Image optimization (WebP) | 1-2 hours | Reduce bandwidth 30-50% |
| 13 | MySQL query optimization | 2-3 hours | Reduce DB load |
| 14 | Security hardening | 30 min | Better DDoS protection |

---

## Capacity Estimates After Optimization

| Scenario | Estimated Max RPS | Daily Capacity |
|---|---|---|
| **Current (no CDN, no Varnish)** | ~50-80 rps | ~500K-700K hits/day |
| **+ Cloudflare CDN** | ~150 rps effective | ~2M hits/day |
| **+ Cloudflare + Varnish** | ~500 rps effective | ~5M+ hits/day |
| **+ All optimizations** | ~1000+ rps effective | ~10M+ hits/day |

With Cloudflare + Varnish + tuning, your current hardware (16 vCPU, 30GB RAM) can comfortably handle **5-10 million hits/day**. The 1M target is very achievable.

---

## Architecture Diagram (Target State)

```
                          ┌─────────────────┐
                          │   Cloudflare     │
                          │   CDN + WAF      │
                          │   (Edge Cache)   │
                          │   + Brotli       │
                          │   + DDoS Shield  │
                          └────────┬─────────┘
                                   │ (cache misses only)
                                   ▼
                          ┌─────────────────┐
                          │   Varnish :80    │
                          │   (FPC HTTP)     │
                          │   ~90% hit rate  │
                          └────────┬─────────┘
                                   │ (cache misses only)
                                   ▼
                          ┌─────────────────┐
                          │   Apache :8080   │
                          │   mpm_event      │
                          │   + HTTP/2       │
                          └────────┬─────────┘
                                   │
                                   ▼
                          ┌─────────────────┐
                          │   PHP-FPM 8.3    │
                          │   (40 workers)   │
                          │   + OPcache      │
                          └──┬────┬────┬─────┘
                             │    │    │
                    ┌────────┘    │    └────────┐
                    ▼             ▼              ▼
              ┌──────────┐ ┌──────────┐  ┌──────────────┐
              │  Redis    │ │  MySQL   │  │ Elasticsearch│
              │  6GB      │ │  RDS     │  │  2GB heap    │
              │  Cache+   │ │ (managed)│  │  (search)    │
              │  FPC+Sess │ │          │  │              │
              └──────────┘ └──────────┘  └──────────────┘
```

---

## Quick Wins You Can Do RIGHT NOW

```bash
# 1. Fix PHP limits (5 min)
ssh ddfapp-aws
sudo sed -i 's/memory_limit = -1/memory_limit = 2G/' /etc/php/8.3/fpm/php.ini
sudo sed -i 's/max_execution_time = 0/max_execution_time = 300/' /etc/php/8.3/fpm/php.ini
sudo systemctl restart php8.3-fpm

# 2. Add Magento cron (2 min)
sudo crontab -u www-data -e
# Add: * * * * * /usr/bin/php /var/www/html/bin/magento cron:run >> /var/www/html/var/log/magento.cron.log 2>&1

# 3. Fix TIME_WAIT (2 min)
echo "net.ipv4.tcp_tw_reuse = 1" | sudo tee -a /etc/sysctl.conf
echo "net.ipv4.tcp_fin_timeout = 15" | sudo tee -a /etc/sysctl.conf
echo "net.core.somaxconn = 65535" | sudo tee -a /etc/sysctl.conf
sudo sysctl -p

# 4. Add search_query cleanup (1 min)
echo "0 3 * * 0 /usr/bin/php /var/www/html/bin/magento indexer:reindex catalog_fulltext_search" | sudo crontab -u www-data -
```

---

*Document generated from live server audit on June 2025.*
*Server: 13.204.186.178 | 16 vCPU | 30GB RAM | Ubuntu 24.04 | Magento 2.4.7-p3*
