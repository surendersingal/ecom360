@extends('layouts.tenant')
@section('title', 'AI Search Settings')

@section('content')
<div class="page-content">
    <div class="container-fluid">

        <div class="row">
            <div class="col-12">
                <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                    <h4 class="mb-sm-0 font-size-18">AI Search & Discovery Settings</h4>
                    <div class="page-title-right">
                        <ol class="breadcrumb m-0">
                            <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                            <li class="breadcrumb-item active">Search Settings</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div id="saveAlert" class="alert d-none" role="alert"></div>

        {{-- ── Section 1: Search Engine Configuration ── --}}
        <div class="row">
            <div class="col-xl-6">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4"><i class="bx bx-cog text-primary me-2"></i>Search Engine</h4>

                        <div class="mb-3">
                            <label class="form-label">Results Per Page (Default)</label>
                            <input type="number" class="form-control" id="search_results_per_page" value="{{ $settings['search_results_per_page'] ?? 20 }}" min="5" max="100">
                            <small class="text-muted">Default number of results per search page (5-100)</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Max Raw Results</label>
                            <input type="number" class="form-control" id="search_max_raw_results" value="{{ $settings['search_max_raw_results'] ?? 500 }}" min="50" max="5000">
                            <small class="text-muted">Maximum products to score before pagination (higher = more accurate, slower)</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Minimum Query Length</label>
                            <input type="number" class="form-control" id="search_min_query_length" value="{{ $settings['search_min_query_length'] ?? 2 }}" min="1" max="5">
                            <small class="text-muted">Minimum characters before search triggers</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Debounce Delay (ms)</label>
                            <input type="number" class="form-control" id="search_debounce_ms" value="{{ $settings['search_debounce_ms'] ?? 250 }}" min="100" max="1000" step="50">
                            <small class="text-muted">Delay before triggering search as user types</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">API Throttle Rate (req/min)</label>
                            <input type="number" class="form-control" id="search_throttle_rate" value="{{ $settings['search_throttle_rate'] ?? 120 }}" min="30" max="600">
                            <small class="text-muted">Maximum search API requests per minute per key</small>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ── Section 2: Relevance Scoring Weights ── --}}
            <div class="col-xl-6">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4"><i class="bx bx-sort-alt-2 text-success me-2"></i>Relevance Scoring Weights</h4>
                        <p class="text-muted mb-3">Control how search results are ranked. Total must equal 100%.</p>

                        <div class="mb-3">
                            <label class="form-label">Text Relevance Weight (%)</label>
                            <input type="number" class="form-control weight-input" id="weight_text_relevance" value="{{ $settings['weight_text_relevance'] ?? 60 }}" min="0" max="100">
                            <small class="text-muted">How closely results match the search query</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Margin Boost Weight (%)</label>
                            <input type="number" class="form-control weight-input" id="weight_margin_boost" value="{{ $settings['weight_margin_boost'] ?? 15 }}" min="0" max="100">
                            <small class="text-muted">Higher-margin products rank higher</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Popularity Weight (%)</label>
                            <input type="number" class="form-control weight-input" id="weight_popularity" value="{{ $settings['weight_popularity'] ?? 10 }}" min="0" max="100">
                            <small class="text-muted">Based on ratings, reviews, and sales</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Freshness Weight (%)</label>
                            <input type="number" class="form-control weight-input" id="weight_freshness" value="{{ $settings['weight_freshness'] ?? 10 }}" min="0" max="100">
                            <small class="text-muted">Newer products get a boost</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Stock Availability Weight (%)</label>
                            <input type="number" class="form-control weight-input" id="weight_stock" value="{{ $settings['weight_stock'] ?? 5 }}" min="0" max="100">
                            <small class="text-muted">Out-of-stock products penalized</small>
                        </div>

                        <div id="weightTotal" class="alert alert-info py-2 px-3 mb-0">
                            Total: <strong><span id="weightSum">100</span>%</strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ── Section 3: Facets & Filters ── --}}
        <div class="row">
            <div class="col-xl-6">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4"><i class="bx bx-filter-alt text-info me-2"></i>Facets & Filters</h4>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Brand Facet</label>
                                <small class="d-block text-muted">Show brand filter in search results</small>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="facet_brands_enabled" {{ ($settings['facet_brands_enabled'] ?? '1') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Category Facet</label>
                                <small class="d-block text-muted">Show category filter in search results</small>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="facet_categories_enabled" {{ ($settings['facet_categories_enabled'] ?? '1') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Price Range Facet</label>
                                <small class="d-block text-muted">Show price range filter in search results</small>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="facet_price_enabled" {{ ($settings['facet_price_enabled'] ?? '1') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Color Facet</label>
                                <small class="d-block text-muted">Show color filter in search results</small>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="facet_color_enabled" {{ ($settings['facet_color_enabled'] ?? '0') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Size Facet</label>
                                <small class="d-block text-muted">Show size filter in search results</small>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="facet_size_enabled" {{ ($settings['facet_size_enabled'] ?? '0') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Rating Facet</label>
                                <small class="d-block text-muted">Show star rating filter</small>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="facet_rating_enabled" {{ ($settings['facet_rating_enabled'] ?? '0') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Max Brands in Facet</label>
                            <input type="number" class="form-control" id="facet_brands_limit" value="{{ $settings['facet_brands_limit'] ?? 15 }}" min="5" max="50">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Max Categories in Facet</label>
                            <input type="number" class="form-control" id="facet_categories_limit" value="{{ $settings['facet_categories_limit'] ?? 10 }}" min="3" max="30">
                        </div>
                    </div>
                </div>
            </div>

            {{-- ── Section 4: Autocomplete & Suggestions ── --}}
            <div class="col-xl-6">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4"><i class="bx bx-bulb text-warning me-2"></i>Autocomplete & Suggestions</h4>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Enable Autocomplete</label>
                                <small class="d-block text-muted">Show suggestions as user types</small>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="autocomplete_enabled" {{ ($settings['autocomplete_enabled'] ?? '1') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Max Suggestions</label>
                            <input type="number" class="form-control" id="autocomplete_max_suggestions" value="{{ $settings['autocomplete_max_suggestions'] ?? 8 }}" min="3" max="20">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Suggestion Cache TTL (seconds)</label>
                            <input type="number" class="form-control" id="suggest_cache_ttl" value="{{ $settings['suggest_cache_ttl'] ?? 300 }}" min="60" max="3600">
                        </div>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Show Trending Searches</label>
                                <small class="d-block text-muted">Display popular searches on empty input</small>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="trending_enabled" {{ ($settings['trending_enabled'] ?? '1') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Trending Window (days)</label>
                            <input type="number" class="form-control" id="trending_window_days" value="{{ $settings['trending_window_days'] ?? 7 }}" min="1" max="90">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Max Trending Results</label>
                            <input type="number" class="form-control" id="trending_max_results" value="{{ $settings['trending_max_results'] ?? 10 }}" min="3" max="25">
                        </div>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Show "Did You Mean" Corrections</label>
                                <small class="d-block text-muted">Suggest typo corrections on zero results</small>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="typo_correction_enabled" {{ ($settings['typo_correction_enabled'] ?? '1') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Smart Price Fallback</label>
                                <small class="d-block text-muted">If "whisky under 500" yields 0, show cheapest with hint</small>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="smart_price_fallback" {{ ($settings['smart_price_fallback'] ?? '1') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ── Section 5: Synonym Management ── --}}
        <div class="row">
            <div class="col-xl-12">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4"><i class="bx bx-transfer text-danger me-2"></i>Synonym Management</h4>
                        <p class="text-muted mb-3">Define synonym pairs so searches for one term also match the other. One pair per line: <code>word → synonym1, synonym2</code></p>

                        <div class="mb-3">
                            <textarea class="form-control" id="custom_synonyms" rows="10" placeholder="whisky → whiskey&#10;scotch → whisky, whiskey&#10;perfume → fragrance, cologne, eau de&#10;chocolate → confectionery">{{ $settings['custom_synonyms'] ?? "whisky → whiskey\nwhiskey → whisky\nscotch → whisky, whiskey\nvodka → spirit\nrum → spirit\ngin → spirit\nbrandy → cognac\ncognac → brandy\nperfume → fragrance, cologne, eau de\nfragrance → perfume, cologne\ncologne → perfume, fragrance\nchocolate → confectionery\nwine → champagne, sparkling" }}</textarea>
                            <small class="text-muted">Format: <code>source_word → target1, target2</code> — one mapping per line</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ── Section 6: Category Aliases ── --}}
        <div class="row">
            <div class="col-xl-12">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4"><i class="bx bx-category text-primary me-2"></i>Category Aliases</h4>
                        <p class="text-muted mb-3">Map common search terms to actual category names in your catalog. One mapping per line: <code>alias → Category Name</code></p>

                        <div class="mb-3">
                            <textarea class="form-control" id="category_aliases" rows="10" placeholder="liquor → Liquor&#10;alcohol → Liquor&#10;perfume → Perfumes&#10;beauty → Beauty">{{ $settings['category_aliases'] ?? "liquor → Liquor\nliquors → Liquor\nalcohol → Liquor\ndrinks → Liquor\nspirits → Liquor\nperfume → Perfumes\nperfumes → Perfumes\nfragrance → Perfumes\nfragrances → Perfumes\ncologne → Colognes\nbeauty → Beauty\ncosmetics → Beauty\nmakeup → Beauty\nchocolate → Confectionery\nchocolates → Confectionery\nconfectionery → Confectionery\ncandy → Confectionery\nsweets → Confectionery\nlipstick → Lips\nlips → Lips\nskincare → Skincare\nwine → Wine\nwines → Wine\nchampagne → Champagne\nvodka → Vodka\ngin → Gin\nrum → Rum\nbrandy → Brandy\ncognac → Cognac\ntequila → Tequila\nbourbon → Bourbon\nscotch → Blended Scotch" }}</textarea>
                            <small class="text-muted">Format: <code>search_term → Actual Category Name</code></small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ── Section 7: Currency & Locale ── --}}
        <div class="row">
            <div class="col-xl-6">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4"><i class="bx bx-money text-success me-2"></i>Currency & Display</h4>

                        <div class="mb-3">
                            <label class="form-label">Currency Code</label>
                            <select class="form-select" id="search_currency_code">
                                @foreach(['INR','USD','EUR','GBP','AED','SAR','SGD','MYR','AUD','CAD','JPY','THB'] as $cur)
                                <option value="{{ $cur }}" {{ ($settings['search_currency_code'] ?? 'INR') == $cur ? 'selected' : '' }}>{{ $cur }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Currency Symbol</label>
                            <input type="text" class="form-control" id="search_currency_symbol" value="{{ $settings['search_currency_symbol'] ?? '₹' }}" maxlength="5">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Price Display Format</label>
                            <select class="form-select" id="search_price_format">
                                <option value="symbol_left" {{ ($settings['search_price_format'] ?? 'symbol_left') == 'symbol_left' ? 'selected' : '' }}>₹1,000 (Symbol Left)</option>
                                <option value="symbol_right" {{ ($settings['search_price_format'] ?? '') == 'symbol_right' ? 'selected' : '' }}>1,000₹ (Symbol Right)</option>
                                <option value="code_left" {{ ($settings['search_price_format'] ?? '') == 'code_left' ? 'selected' : '' }}>INR 1,000 (Code Left)</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Product URL Base</label>
                            <input type="url" class="form-control" id="search_store_base_url" value="{{ $settings['search_store_base_url'] ?? '' }}" placeholder="https://your-store.com">
                            <small class="text-muted">Base URL for product links in search results</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Product URL Pattern</label>
                            <input type="text" class="form-control" id="search_product_url_pattern" value="{{ $settings['search_product_url_pattern'] ?? '/default/{url_key}.html' }}" placeholder="/default/{url_key}.html">
                            <small class="text-muted">Use <code>{url_key}</code> as placeholder</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">No Image Placeholder URL</label>
                            <input type="text" class="form-control" id="search_no_image_url" value="{{ $settings['search_no_image_url'] ?? '' }}" placeholder="https://your-store.com/media/placeholder.jpg">
                        </div>
                    </div>
                </div>
            </div>

            {{-- ── Section 8: Advanced Search Features ── --}}
            <div class="col-xl-6">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4"><i class="bx bx-rocket text-danger me-2"></i>Advanced Features</h4>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Natural Language Queries (NLQ)</label>
                                <small class="d-block text-muted">Parse "whisky under 5000" into structured filters</small>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="nlq_enabled" {{ ($settings['nlq_enabled'] ?? '1') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Fuzzy Matching (Typo Tolerance)</label>
                                <small class="d-block text-muted">Match "glenlevit" → "Glenlivet"</small>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="fuzzy_matching_enabled" {{ ($settings['fuzzy_matching_enabled'] ?? '1') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Phonetic Matching</label>
                                <small class="d-block text-muted">Match by pronunciation (Metaphone/Soundex)</small>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="phonetic_matching_enabled" {{ ($settings['phonetic_matching_enabled'] ?? '1') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Synonym Expansion</label>
                                <small class="d-block text-muted">Expand searches with synonym matches</small>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="synonym_expansion_enabled" {{ ($settings['synonym_expansion_enabled'] ?? '1') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Gift Concierge</label>
                                <small class="d-block text-muted">"Gift for dad who likes whisky" → curated results</small>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="gift_concierge_enabled" {{ ($settings['gift_concierge_enabled'] ?? '1') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Visual Search</label>
                                <small class="d-block text-muted">Search by image upload</small>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="visual_search_enabled" {{ ($settings['visual_search_enabled'] ?? '0') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Voice Search</label>
                                <small class="d-block text-muted">Voice-to-text search input</small>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="voice_search_enabled" {{ ($settings['voice_search_enabled'] ?? '0') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Out-of-Stock Rerouting</label>
                                <small class="d-block text-muted">Auto-suggest alternatives for OOS products</small>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="oos_reroute_enabled" {{ ($settings['oos_reroute_enabled'] ?? '1') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Feature Comparison</label>
                                <small class="d-block text-muted">"X vs Y" comparison tables</small>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="comparison_enabled" {{ ($settings['comparison_enabled'] ?? '1') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Personalized Size Search</label>
                                <small class="d-block text-muted">Auto-apply customer size preferences</small>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="personalized_size_enabled" {{ ($settings['personalized_size_enabled'] ?? '0') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ── Section 9: Search Widget Appearance ── --}}
        <div class="row">
            <div class="col-xl-6">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4"><i class="bx bx-palette text-purple me-2"></i>Search Widget Appearance</h4>

                        <div class="mb-3">
                            <label class="form-label">Primary Color</label>
                            <input type="color" class="form-control form-control-color w-100" id="search_widget_color" value="{{ $settings['search_widget_color'] ?? '#4F46E5' }}">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Placeholder Text</label>
                            <input type="text" class="form-control" id="search_placeholder_text" value="{{ $settings['search_placeholder_text'] ?? 'Search products...' }}">
                        </div>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Show Product Images in Suggestions</label>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="suggest_show_images" {{ ($settings['suggest_show_images'] ?? '1') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Show Prices in Suggestions</label>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="suggest_show_prices" {{ ($settings['suggest_show_prices'] ?? '1') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Show Brand in Results</label>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="show_brand_in_results" {{ ($settings['show_brand_in_results'] ?? '1') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Keyboard Shortcut (Ctrl/Cmd+K)</label>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="search_keyboard_shortcut" {{ ($settings['search_keyboard_shortcut'] ?? '1') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ── Section 10: Search Results Page ── --}}
            <div class="col-xl-6">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4"><i class="bx bx-layout text-info me-2"></i>Search Results Page</h4>

                        <div class="mb-3">
                            <label class="form-label">Default View</label>
                            <select class="form-select" id="srp_default_view">
                                <option value="grid" {{ ($settings['srp_default_view'] ?? 'grid') == 'grid' ? 'selected' : '' }}>Grid View</option>
                                <option value="list" {{ ($settings['srp_default_view'] ?? '') == 'list' ? 'selected' : '' }}>List View</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Default Sort</label>
                            <select class="form-select" id="srp_default_sort">
                                <option value="relevance" {{ ($settings['srp_default_sort'] ?? 'relevance') == 'relevance' ? 'selected' : '' }}>Relevance</option>
                                <option value="price_asc" {{ ($settings['srp_default_sort'] ?? '') == 'price_asc' ? 'selected' : '' }}>Price: Low to High</option>
                                <option value="price_desc" {{ ($settings['srp_default_sort'] ?? '') == 'price_desc' ? 'selected' : '' }}>Price: High to Low</option>
                                <option value="newest" {{ ($settings['srp_default_sort'] ?? '') == 'newest' ? 'selected' : '' }}>Newest</option>
                                <option value="rating" {{ ($settings['srp_default_sort'] ?? '') == 'rating' ? 'selected' : '' }}>Rating</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Products Per Page Options</label>
                            <input type="text" class="form-control" id="srp_per_page_options" value="{{ $settings['srp_per_page_options'] ?? '12,24,36' }}">
                            <small class="text-muted">Comma-separated values</small>
                        </div>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Show Discount Badge</label>
                                <small class="d-block text-muted">Show % off badge for special price items</small>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="srp_show_discount_badge" {{ ($settings['srp_show_discount_badge'] ?? '1') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Show Stock Status</label>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="srp_show_stock_status" {{ ($settings['srp_show_stock_status'] ?? '1') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Show Rating Stars</label>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="srp_show_rating" {{ ($settings['srp_show_rating'] ?? '1') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ── Save Button ── --}}
        <div class="row">
            <div class="col-12 text-end mb-4">
                <button class="btn btn-primary btn-lg px-5" id="saveBtn" onclick="saveSearchSettings()">
                    <i class="bx bx-save me-1"></i> Save Search Settings
                </button>
            </div>
        </div>

    </div>
</div>
@endsection

@section('scripts')
<script>
    // Weight total calculator
    function updateWeightTotal() {
        const inputs = document.querySelectorAll('.weight-input');
        let sum = 0;
        inputs.forEach(i => sum += parseInt(i.value) || 0);
        document.getElementById('weightSum').textContent = sum;
        const el = document.getElementById('weightTotal');
        el.className = sum === 100 ? 'alert alert-success py-2 px-3 mb-0' : 'alert alert-danger py-2 px-3 mb-0';
    }
    document.querySelectorAll('.weight-input').forEach(i => i.addEventListener('input', updateWeightTotal));
    updateWeightTotal();

    function saveSearchSettings() {
        const btn = document.getElementById('saveBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="bx bx-loader-alt bx-spin me-1"></i> Saving...';

        const getVal = id => document.getElementById(id)?.value ?? '';
        const getChecked = id => document.getElementById(id)?.checked ? '1' : '0';

        const data = {
            _token: '{{ csrf_token() }}',
            // Engine
            search_results_per_page: getVal('search_results_per_page'),
            search_max_raw_results: getVal('search_max_raw_results'),
            search_min_query_length: getVal('search_min_query_length'),
            search_debounce_ms: getVal('search_debounce_ms'),
            search_throttle_rate: getVal('search_throttle_rate'),
            // Weights
            weight_text_relevance: getVal('weight_text_relevance'),
            weight_margin_boost: getVal('weight_margin_boost'),
            weight_popularity: getVal('weight_popularity'),
            weight_freshness: getVal('weight_freshness'),
            weight_stock: getVal('weight_stock'),
            // Facets
            facet_brands_enabled: getChecked('facet_brands_enabled'),
            facet_categories_enabled: getChecked('facet_categories_enabled'),
            facet_price_enabled: getChecked('facet_price_enabled'),
            facet_color_enabled: getChecked('facet_color_enabled'),
            facet_size_enabled: getChecked('facet_size_enabled'),
            facet_rating_enabled: getChecked('facet_rating_enabled'),
            facet_brands_limit: getVal('facet_brands_limit'),
            facet_categories_limit: getVal('facet_categories_limit'),
            // Autocomplete
            autocomplete_enabled: getChecked('autocomplete_enabled'),
            autocomplete_max_suggestions: getVal('autocomplete_max_suggestions'),
            suggest_cache_ttl: getVal('suggest_cache_ttl'),
            trending_enabled: getChecked('trending_enabled'),
            trending_window_days: getVal('trending_window_days'),
            trending_max_results: getVal('trending_max_results'),
            typo_correction_enabled: getChecked('typo_correction_enabled'),
            smart_price_fallback: getChecked('smart_price_fallback'),
            // Synonyms & Aliases
            custom_synonyms: getVal('custom_synonyms'),
            category_aliases: getVal('category_aliases'),
            // Currency
            search_currency_code: getVal('search_currency_code'),
            search_currency_symbol: getVal('search_currency_symbol'),
            search_price_format: getVal('search_price_format'),
            search_store_base_url: getVal('search_store_base_url'),
            search_product_url_pattern: getVal('search_product_url_pattern'),
            search_no_image_url: getVal('search_no_image_url'),
            // Advanced Features
            nlq_enabled: getChecked('nlq_enabled'),
            fuzzy_matching_enabled: getChecked('fuzzy_matching_enabled'),
            phonetic_matching_enabled: getChecked('phonetic_matching_enabled'),
            synonym_expansion_enabled: getChecked('synonym_expansion_enabled'),
            gift_concierge_enabled: getChecked('gift_concierge_enabled'),
            visual_search_enabled: getChecked('visual_search_enabled'),
            voice_search_enabled: getChecked('voice_search_enabled'),
            oos_reroute_enabled: getChecked('oos_reroute_enabled'),
            comparison_enabled: getChecked('comparison_enabled'),
            personalized_size_enabled: getChecked('personalized_size_enabled'),
            // Widget
            search_widget_color: getVal('search_widget_color'),
            search_placeholder_text: getVal('search_placeholder_text'),
            suggest_show_images: getChecked('suggest_show_images'),
            suggest_show_prices: getChecked('suggest_show_prices'),
            show_brand_in_results: getChecked('show_brand_in_results'),
            search_keyboard_shortcut: getChecked('search_keyboard_shortcut'),
            // SRP
            srp_default_view: getVal('srp_default_view'),
            srp_default_sort: getVal('srp_default_sort'),
            srp_per_page_options: getVal('srp_per_page_options'),
            srp_show_discount_badge: getChecked('srp_show_discount_badge'),
            srp_show_stock_status: getChecked('srp_show_stock_status'),
            srp_show_rating: getChecked('srp_show_rating'),
        };

        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
            body: JSON.stringify(data),
        })
        .then(r => r.json())
        .then(d => {
            const alert = document.getElementById('saveAlert');
            alert.className = d.success ? 'alert alert-success' : 'alert alert-danger';
            alert.textContent = d.message || (d.success ? 'Settings saved.' : 'Error saving.');
            alert.classList.remove('d-none');
            setTimeout(() => alert.classList.add('d-none'), 4000);
        })
        .catch(e => {
            const alert = document.getElementById('saveAlert');
            alert.className = 'alert alert-danger';
            alert.textContent = 'Network error: ' + e.message;
            alert.classList.remove('d-none');
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bx bx-save me-1"></i> Save Search Settings';
        });
    }
</script>
@endsection
