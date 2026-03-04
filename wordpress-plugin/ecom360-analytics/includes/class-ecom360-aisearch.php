<?php
/**
 * AI Search Widget — renders AI-powered search overlay.
 *
 * Features: Ctrl+K shortcut, debounced search, suggestions,
 * faceted results, visual/image search, trending queries.
 *
 * @package Ecom360_Analytics
 */

defined('ABSPATH') || exit;

final class Ecom360_AiSearch {

    /** @var array<string, mixed> */
    private $settings;

    public function __construct( array $settings ) {
        $this->settings = $settings;
    }

    public function is_enabled(): bool {
        return ! empty( $this->settings['ai_search_enabled'] )
            && ! empty( $this->settings['endpoint'] )
            && ! empty( $this->settings['api_key'] );
    }

    public function enqueue(): void {
        if ( ! $this->is_enabled() ) return;
        add_action( 'wp_footer', [ $this, 'render' ], 100 );
    }

    public function render(): void {
        $s = $this->settings;
        $endpoint = rtrim( $s['endpoint'], '/' );
        $config = wp_json_encode( [
            'endpoint'      => $endpoint,
            'apiKey'        => $s['api_key'],
            'visualEnabled' => ! empty( $s['ai_search_visual_enabled'] ),
        ], JSON_UNESCAPED_SLASHES );
        ?>
        <!-- Ecom360 AI Search Widget -->
        <style>
        .ecom360-search-overlay{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:200000;display:none;align-items:flex-start;justify-content:center;padding-top:10vh}
        .ecom360-search-overlay.open{display:flex}
        .ecom360-search-container{background:#fff;border-radius:16px;width:640px;max-width:90vw;max-height:75vh;display:flex;flex-direction:column;box-shadow:0 16px 48px rgba(0,0,0,.3);overflow:hidden}
        .ecom360-search-header{display:flex;align-items:center;padding:16px;border-bottom:1px solid #e5e7eb;gap:12px}
        .ecom360-search-header svg{width:20px;height:20px;color:#9ca3af;flex-shrink:0}
        .ecom360-search-input{flex:1;border:none;outline:none;font-size:16px;background:transparent}
        .ecom360-search-kbd{background:#f3f4f6;padding:2px 8px;border-radius:4px;font-size:12px;color:#6b7280;font-family:monospace;white-space:nowrap}
        .ecom360-search-close{background:none;border:none;font-size:20px;cursor:pointer;color:#9ca3af;padding:4px}
        .ecom360-search-body{flex:1;overflow-y:auto;padding:16px}
        .ecom360-search-section{margin-bottom:16px}
        .ecom360-search-section h5{font-size:12px;text-transform:uppercase;color:#6b7280;margin:0 0 8px;font-weight:600;letter-spacing:.5px}
        .ecom360-search-results{display:flex;flex-direction:column;gap:8px}
        .ecom360-search-item{display:flex;align-items:center;gap:12px;padding:10px;border-radius:8px;cursor:pointer;text-decoration:none;color:inherit;transition:background .15s}
        .ecom360-search-item:hover{background:#f3f4f6}
        .ecom360-search-item img{width:48px;height:48px;object-fit:cover;border-radius:6px;flex-shrink:0}
        .ecom360-search-item-info{flex:1}
        .ecom360-search-item-info .name{font-weight:600;font-size:14px;color:#111}
        .ecom360-search-item-info .meta{font-size:13px;color:#6b7280}
        .ecom360-search-item-info .price{font-weight:600;color:#4f46e5;font-size:14px}
        .ecom360-search-facets{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px}
        .ecom360-search-facet{background:#eef2ff;color:#4f46e5;border:1px solid #c7d2fe;border-radius:20px;padding:4px 12px;font-size:12px;cursor:pointer}
        .ecom360-search-facet.active{background:#4f46e5;color:#fff}
        .ecom360-search-trending{display:flex;flex-wrap:wrap;gap:8px}
        .ecom360-search-trending a{color:#4f46e5;text-decoration:none;font-size:14px;padding:6px 12px;background:#eef2ff;border-radius:8px}
        .ecom360-search-trending a:hover{background:#c7d2fe}
        .ecom360-search-visual{margin-top:8px}
        .ecom360-search-visual label{display:inline-flex;align-items:center;gap:6px;color:#4f46e5;font-size:13px;cursor:pointer}
        .ecom360-search-visual input[type=file]{display:none}
        .ecom360-search-empty{text-align:center;color:#6b7280;padding:32px 0}
        .ecom360-search-suggest{display:flex;flex-direction:column;gap:4px}
        .ecom360-search-suggest a{padding:8px 12px;border-radius:6px;color:#333;text-decoration:none;font-size:14px}
        .ecom360-search-suggest a:hover{background:#f3f4f6}
        .ecom360-search-suggest a strong{color:#4f46e5}
        </style>

        <div id="ecom360-search-overlay" class="ecom360-search-overlay">
            <div class="ecom360-search-container">
                <div class="ecom360-search-header">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                    <input id="ecom360-search-input" class="ecom360-search-input" type="text" placeholder="Search products..." autocomplete="off" />
                    <span class="ecom360-search-kbd">ESC</span>
                    <button class="ecom360-search-close" aria-label="Close">&times;</button>
                </div>
                <div id="ecom360-search-body" class="ecom360-search-body">
                    <div id="ecom360-search-trending-section" class="ecom360-search-section" style="display:none">
                        <h5>Trending Searches</h5>
                        <div id="ecom360-search-trending" class="ecom360-search-trending"></div>
                    </div>
                    <div id="ecom360-search-suggest-section" class="ecom360-search-section" style="display:none">
                        <h5>Suggestions</h5>
                        <div id="ecom360-search-suggestions" class="ecom360-search-suggest"></div>
                    </div>
                    <div id="ecom360-search-facets-container" class="ecom360-search-facets" style="display:none"></div>
                    <div id="ecom360-search-results" class="ecom360-search-results"></div>
                    <?php if ( ! empty( $s['ai_search_visual_enabled'] ) ): ?>
                    <div class="ecom360-search-visual">
                        <label>📷 <span>Search by image</span>
                            <input type="file" id="ecom360-visual-upload" accept="image/*" />
                        </label>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <script>
        (function(){
            'use strict';
            var CFG = <?php echo $config; ?>;
            var overlay = document.getElementById('ecom360-search-overlay');
            var input = document.getElementById('ecom360-search-input');
            var body = document.getElementById('ecom360-search-body');
            var resultsEl = document.getElementById('ecom360-search-results');
            var suggestEl = document.getElementById('ecom360-search-suggestions');
            var suggestSection = document.getElementById('ecom360-search-suggest-section');
            var trendingSection = document.getElementById('ecom360-search-trending-section');
            var trendingEl = document.getElementById('ecom360-search-trending');
            var facetsEl = document.getElementById('ecom360-search-facets-container');
            var closeBtn = overlay.querySelector('.ecom360-search-close');
            var debounceTimer = null;
            var activeFacets = {};

            function open() {
                overlay.classList.add('open');
                input.value = '';
                input.focus();
                resultsEl.innerHTML = '';
                suggestSection.style.display = 'none';
                facetsEl.style.display = 'none';
                loadTrending();
            }

            function close() {
                overlay.classList.remove('open');
                input.value = '';
            }

            // Ctrl+K / Cmd+K
            document.addEventListener('keydown', function(e) {
                if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                    e.preventDefault();
                    overlay.classList.contains('open') ? close() : open();
                }
                if (e.key === 'Escape' && overlay.classList.contains('open')) close();
            });

            closeBtn.addEventListener('click', close);
            overlay.addEventListener('click', function(e) { if (e.target === overlay) close(); });

            // Hijack WooCommerce search focus
            document.addEventListener('focusin', function(e) {
                if (e.target.matches && e.target.matches('.woocommerce-product-search input[type="search"], .search-field, .dgwt-wcas-search-input')) {
                    e.preventDefault();
                    e.target.blur();
                    open();
                }
            });

            // Debounced search
            input.addEventListener('input', function() {
                clearTimeout(debounceTimer);
                var q = input.value.trim();
                if (q.length < 2) {
                    resultsEl.innerHTML = '';
                    suggestSection.style.display = 'none';
                    facetsEl.style.display = 'none';
                    trendingSection.style.display = trendingEl.children.length ? '' : 'none';
                    return;
                }
                debounceTimer = setTimeout(function() { doSearch(q); doSuggest(q); }, 250);
            });

            function apiFetch(path, opts) {
                opts = opts || {};
                opts.headers = Object.assign({'X-Ecom360-Key': CFG.apiKey}, opts.headers || {});
                return fetch(CFG.endpoint + path, opts).then(function(r) { return r.json(); });
            }

            function doSearch(q) {
                var url = '/api/v1/search?q=' + encodeURIComponent(q);
                var facetKeys = Object.keys(activeFacets);
                if (facetKeys.length) {
                    facetKeys.forEach(function(k) { url += '&facets['+k+']='+encodeURIComponent(activeFacets[k]); });
                }
                apiFetch(url).then(function(res) {
                    trendingSection.style.display = 'none';
                    renderResults(res.data || res.results || []);
                    renderFacets(res.facets || []);
                    if (window.ecom360) window.ecom360.track('ai_search', {query: q, results_count: (res.data||[]).length});
                }).catch(function() { resultsEl.innerHTML = '<p class="ecom360-search-empty">Search unavailable</p>'; });
            }

            function doSuggest(q) {
                apiFetch('/api/v1/search/suggest?q=' + encodeURIComponent(q)).then(function(res) {
                    var items = res.data || res.suggestions || [];
                    if (items.length) {
                        suggestSection.style.display = '';
                        suggestEl.innerHTML = items.map(function(s) {
                            var label = (typeof s === 'string') ? s : s.text || s.query;
                            return '<a href="#" data-q="'+label+'"><strong>'+label+'</strong></a>';
                        }).join('');
                        suggestEl.querySelectorAll('a').forEach(function(a) {
                            a.addEventListener('click', function(e) {
                                e.preventDefault();
                                input.value = a.dataset.q;
                                doSearch(a.dataset.q);
                            });
                        });
                    } else {
                        suggestSection.style.display = 'none';
                    }
                });
            }

            function loadTrending() {
                apiFetch('/api/v1/search/trending').then(function(res) {
                    var items = res.data || res.trending || [];
                    if (items.length) {
                        trendingSection.style.display = '';
                        trendingEl.innerHTML = items.map(function(t) {
                            var label = (typeof t === 'string') ? t : t.query || t.text;
                            return '<a href="#" data-q="'+label+'">'+label+'</a>';
                        }).join('');
                        trendingEl.querySelectorAll('a').forEach(function(a) {
                            a.addEventListener('click', function(e) {
                                e.preventDefault();
                                input.value = a.dataset.q;
                                doSearch(a.dataset.q);
                            });
                        });
                    }
                }).catch(function(){});
            }

            function renderResults(items) {
                if (!items.length) {
                    resultsEl.innerHTML = '<p class="ecom360-search-empty">No results found</p>';
                    return;
                }
                resultsEl.innerHTML = items.map(function(p) {
                    var img = p.image || p.thumbnail || p.image_url || '';
                    var url = p.url || p.link || '#';
                    var price = p.price ? ('$' + parseFloat(p.price).toFixed(2)) : '';
                    var name = p.name || p.title || 'Product';
                    var cat = p.category || '';
                    return '<a class="ecom360-search-item" href="'+url+'">' +
                        (img ? '<img src="'+img+'" alt="'+name+'">' : '') +
                        '<div class="ecom360-search-item-info">' +
                        '<div class="name">'+name+'</div>' +
                        (cat ? '<div class="meta">'+cat+'</div>' : '') +
                        (price ? '<div class="price">'+price+'</div>' : '') +
                        '</div></a>';
                }).join('');
            }

            function renderFacets(facets) {
                if (!facets || !facets.length) { facetsEl.style.display = 'none'; return; }
                facetsEl.style.display = 'flex';
                facetsEl.innerHTML = facets.map(function(f) {
                    var label = f.label || f.key;
                    var isActive = activeFacets[f.key] === f.value;
                    return '<span class="ecom360-search-facet'+(isActive?' active':'')+'" data-key="'+f.key+'" data-value="'+f.value+'">'+label+'</span>';
                }).join('');
                facetsEl.querySelectorAll('.ecom360-search-facet').forEach(function(el) {
                    el.addEventListener('click', function() {
                        if (activeFacets[el.dataset.key] === el.dataset.value) {
                            delete activeFacets[el.dataset.key];
                        } else {
                            activeFacets[el.dataset.key] = el.dataset.value;
                        }
                        doSearch(input.value.trim());
                    });
                });
            }

            // Visual search
            var visualInput = document.getElementById('ecom360-visual-upload');
            if (visualInput) {
                visualInput.addEventListener('change', function() {
                    var file = visualInput.files[0];
                    if (!file) return;
                    var fd = new FormData();
                    fd.append('image', file);
                    fetch(CFG.endpoint + '/api/v1/search/visual', {
                        method: 'POST',
                        headers: {'X-Ecom360-Key': CFG.apiKey},
                        body: fd,
                    }).then(function(r) { return r.json(); }).then(function(res) {
                        renderResults(res.data || res.results || []);
                        if (window.ecom360) window.ecom360.track('ai_visual_search', {});
                    }).catch(function() {
                        resultsEl.innerHTML = '<p class="ecom360-search-empty">Visual search failed</p>';
                    });
                });
            }
        })();
        </script>
        <?php
    }
}
