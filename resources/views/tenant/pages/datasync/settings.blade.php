@extends('layouts.tenant')

@section('title', 'Data Sync — Settings & Configuration')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Data Sync Settings</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">Data Sync</li>
                        <li class="breadcrumb-item active">Settings</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    {{-- Flash messages --}}
    <div id="settings-alert" class="alert d-none" role="alert"></div>

    <form id="datasync-settings-form" method="POST">
        @csrf

        {{-- ──────────── Attribute Mapping ──────────── --}}
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-1"><i class="bx bx-transfer-alt me-1"></i> Attribute Mapping</h4>
                        <p class="card-title-desc">Map your store's product attributes to Ecom360 fields. These mappings control how data appears in search, filters and analytics.</p>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="brand_attribute" class="form-label">Brand Attribute</label>
                                    <input type="text" class="form-control" id="brand_attribute" name="brand_attribute"
                                           value="{{ $settings['brand_attribute'] ?? 'manufacturer' }}"
                                           placeholder="e.g. manufacturer, brand, vendor">
                                    <div class="form-text">
                                        The product attribute code used as "Brand" in search facets and product cards.
                                        @if(!empty($remoteSettings['brand_attribute']))
                                            <br><span class="text-info"><i class="bx bx-info-circle"></i>
                                            Magento reports: <strong>{{ $remoteSettings['brand_attribute'] }}</strong></span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="name_attribute" class="form-label">Product Name Attribute</label>
                                    <input type="text" class="form-control" id="name_attribute" name="name_attribute"
                                           value="{{ $settings['name_attribute'] ?? 'name' }}" placeholder="name">
                                    <div class="form-text">Attribute code for product name (usually "name").</div>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="color_attribute" class="form-label">Color Attribute</label>
                                    <input type="text" class="form-control" id="color_attribute" name="color_attribute"
                                           value="{{ $settings['color_attribute'] ?? 'color' }}" placeholder="e.g. color, colour">
                                    <div class="form-text">Attribute code used for color facets in search.</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="size_attribute" class="form-label">Size Attribute</label>
                                    <input type="text" class="form-control" id="size_attribute" name="size_attribute"
                                           value="{{ $settings['size_attribute'] ?? 'size' }}" placeholder="e.g. size, volume">
                                    <div class="form-text">Attribute code used for size facets in search.</div>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="custom_attributes" class="form-label">Additional Synced Attributes</label>
                                    <input type="text" class="form-control" id="custom_attributes" name="custom_attributes"
                                           value="{{ $settings['custom_attributes'] ?? '' }}"
                                           placeholder="e.g. country_of_origin, material, abv">
                                    <div class="form-text">Comma-separated attribute codes to include in sync (will be available in search facets).</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="image_attribute" class="form-label">Image Attribute</label>
                                    <input type="text" class="form-control" id="image_attribute" name="image_attribute"
                                           value="{{ $settings['image_attribute'] ?? 'image' }}" placeholder="image">
                                    <div class="form-text">Attribute for the main product image (usually "image").</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ──────────── Currency & Locale ──────────── --}}
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-1"><i class="bx bx-money me-1"></i> Currency & Locale</h4>
                        <p class="card-title-desc">Configure how prices and locale are displayed across Ecom360 dashboards, search widget and chatbot.</p>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="currency_code" class="form-label">Currency Code</label>
                                    <select class="form-select" id="currency_code" name="currency_code">
                                        @php
                                            $cc = $settings['currency_code'] ?? ($connection->currency ?? 'INR');
                                            $currencies = ['INR'=>'₹ Indian Rupee (INR)','USD'=>'$ US Dollar (USD)','EUR'=>'€ Euro (EUR)','GBP'=>'£ British Pound (GBP)','AED'=>'د.إ UAE Dirham (AED)','SAR'=>'﷼ Saudi Riyal (SAR)','JPY'=>'¥ Japanese Yen (JPY)','AUD'=>'A$ Australian Dollar (AUD)','CAD'=>'C$ Canadian Dollar (CAD)','SGD'=>'S$ Singapore Dollar (SGD)','MYR'=>'RM Malaysian Ringgit (MYR)','THB'=>'฿ Thai Baht (THB)'];
                                        @endphp
                                        @foreach($currencies as $code => $label)
                                            <option value="{{ $code }}" {{ $cc === $code ? 'selected' : '' }}>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <div class="form-text">
                                        Currency used for displaying prices.
                                        @if(!empty($connection->currency))
                                            <br><span class="text-info"><i class="bx bx-info-circle"></i> Store reports: <strong>{{ $connection->currency }}</strong></span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="currency_symbol" class="form-label">Currency Symbol</label>
                                    <input type="text" class="form-control" id="currency_symbol" name="currency_symbol"
                                           value="{{ $settings['currency_symbol'] ?? '₹' }}" placeholder="₹" maxlength="5">
                                    <div class="form-text">Symbol shown in front of prices (e.g. ₹, $, €).</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="locale" class="form-label">Locale</label>
                                    <select class="form-select" id="locale" name="locale">
                                        @php
                                            $loc = $settings['locale'] ?? ($connection->locale ?? 'en_US');
                                            $locales = ['en_US'=>'English (US)','en_GB'=>'English (UK)','en_IN'=>'English (India)','hi_IN'=>'Hindi (India)','ar_SA'=>'Arabic (Saudi Arabia)','fr_FR'=>'French','de_DE'=>'German','ja_JP'=>'Japanese','zh_CN'=>'Chinese (Simplified)','es_ES'=>'Spanish','pt_BR'=>'Portuguese (Brazil)'];
                                        @endphp
                                        @foreach($locales as $code => $label)
                                            <option value="{{ $code }}" {{ $loc === $code ? 'selected' : '' }}>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <div class="form-text">Locale for number/date formatting.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ──────────── Feature Toggles ──────────── --}}
        <div class="row">
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-1"><i class="bx bx-toggle-right me-1"></i> Sync Features</h4>
                        <p class="card-title-desc">Control which data types are synced and displayed.</p>

                        @php
                            $syncFeatures = [
                                'sync_products'   => ['label' => 'Sync Products',   'default' => true],
                                'sync_categories' => ['label' => 'Sync Categories', 'default' => true],
                                'sync_orders'     => ['label' => 'Sync Orders',     'default' => true],
                                'sync_customers'  => ['label' => 'Sync Customers',  'default' => false],
                                'sync_inventory'  => ['label' => 'Sync Inventory',  'default' => true],
                                'sync_brands'     => ['label' => 'Sync Brands (from attribute)', 'default' => true],
                            ];
                        @endphp

                        @foreach($syncFeatures as $key => $feat)
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" id="{{ $key }}" name="{{ $key }}"
                                       {{ ($settings[$key] ?? $feat['default']) ? 'checked' : '' }}>
                                <label class="form-check-label" for="{{ $key }}">{{ $feat['label'] }}</label>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-1"><i class="bx bx-search-alt me-1"></i> Search & Discovery</h4>
                        <p class="card-title-desc">Control search widget behavior.</p>

                        @php
                            $searchFeatures = [
                                'search_brand_facet'    => ['label' => 'Show Brand Facet in Search',      'default' => true],
                                'search_category_facet' => ['label' => 'Show Category Facet in Search',   'default' => true],
                                'search_price_facet'    => ['label' => 'Show Price Range Facet',           'default' => true],
                                'chatbot_enabled'       => ['label' => 'Enable AI Chatbot',               'default' => true],
                                'chatbot_product_cards' => ['label' => 'Show Product Cards in Chatbot',   'default' => true],
                                'search_autocomplete'   => ['label' => 'Enable Search Autocomplete',      'default' => true],
                            ];
                        @endphp

                        @foreach($searchFeatures as $key => $feat)
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" id="{{ $key }}" name="{{ $key }}"
                                       {{ ($settings[$key] ?? $feat['default']) ? 'checked' : '' }}>
                                <label class="form-check-label" for="{{ $key }}">{{ $feat['label'] }}</label>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        {{-- ──────────── Search Widget Display ──────────── --}}
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-1"><i class="bx bx-paint me-1"></i> Search Widget Display</h4>
                        <p class="card-title-desc">Customize how the search results page and product cards look.</p>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="products_per_page" class="form-label">Products Per Page</label>
                                    <input type="number" class="form-control" id="products_per_page" name="products_per_page"
                                           value="{{ $settings['products_per_page'] ?? 20 }}" min="4" max="100">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="chatbot_max_products" class="form-label">Chatbot Max Products</label>
                                    <input type="number" class="form-control" id="chatbot_max_products" name="chatbot_max_products"
                                           value="{{ $settings['chatbot_max_products'] ?? 5 }}" min="1" max="20">
                                    <div class="form-text">Maximum product cards shown in chatbot responses.</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="price_display" class="form-label">Price Display Format</label>
                                    <select class="form-select" id="price_display" name="price_display">
                                        @php $pd = $settings['price_display'] ?? 'symbol_left'; @endphp
                                        <option value="symbol_left" {{ $pd === 'symbol_left' ? 'selected' : '' }}>₹1,299 (Symbol Left)</option>
                                        <option value="symbol_right" {{ $pd === 'symbol_right' ? 'selected' : '' }}>1,299₹ (Symbol Right)</option>
                                        <option value="code_left" {{ $pd === 'code_left' ? 'selected' : '' }}>INR 1,299 (Code Left)</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="store_base_url" class="form-label">Store Product URL Base</label>
                                    <input type="url" class="form-control" id="store_base_url" name="store_base_url"
                                           value="{{ $settings['store_base_url'] ?? ($connection->store_url ?? '') }}"
                                           placeholder="https://your-store.com">
                                    <div class="form-text">Base URL used to build product links in search results.</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="product_url_suffix" class="form-label">Product URL Suffix</label>
                                    <input type="text" class="form-control" id="product_url_suffix" name="product_url_suffix"
                                           value="{{ $settings['product_url_suffix'] ?? '.html' }}" placeholder=".html">
                                    <div class="form-text">Suffix appended to product URLs (e.g. ".html" for Magento).</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="no_image_placeholder" class="form-label">No-Image Placeholder URL</label>
                                    <input type="text" class="form-control" id="no_image_placeholder" name="no_image_placeholder"
                                           value="{{ $settings['no_image_placeholder'] ?? '' }}"
                                           placeholder="https://your-store.com/media/placeholder.jpg">
                                    <div class="form-text">Fallback image when a product has no image.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ──────────── Remote Store Config (Read-Only) ──────────── --}}
        @if(!empty($remoteSettings) && is_array($remoteSettings))
        <div class="row">
            <div class="col-12">
                <div class="card border-info">
                    <div class="card-body">
                        <h4 class="card-title mb-1"><i class="bx bx-cloud-download me-1 text-info"></i> Magento Store Configuration <span class="badge bg-info">Read-Only</span></h4>
                        <p class="card-title-desc">Settings reported by the connected Magento module during the last sync/heartbeat.</p>

                        <div class="table-responsive">
                            <table class="table table-sm table-nowrap mb-0">
                                <thead class="table-light">
                                    <tr><th>Setting</th><th>Value</th></tr>
                                </thead>
                                <tbody>
                                    @foreach($remoteSettings as $rKey => $rVal)
                                        <tr>
                                            <td><code>{{ $rKey }}</code></td>
                                            <td>{{ is_array($rVal) ? json_encode($rVal) : $rVal }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        @endif

        {{-- ──────────── Save Button ──────────── --}}
        <div class="row mb-4">
            <div class="col-12">
                <button type="submit" class="btn btn-primary btn-lg" id="btn-save-settings">
                    <i class="bx bx-save me-1"></i> Save Settings
                </button>
                <span class="text-muted ms-2" id="save-status"></span>
            </div>
        </div>
    </form>
@endsection

@section('scripts')
<script>
document.getElementById('datasync-settings-form').addEventListener('submit', function(e) {
    e.preventDefault();

    const btn = document.getElementById('btn-save-settings');
    const status = document.getElementById('save-status');
    const alert = document.getElementById('settings-alert');
    btn.disabled = true;
    btn.innerHTML = '<i class="bx bx-loader-alt bx-spin me-1"></i> Saving…';

    const formData = new FormData(this);

    // Convert checkboxes to boolean
    const checkboxes = this.querySelectorAll('input[type="checkbox"]');
    checkboxes.forEach(cb => {
        formData.set(cb.name, cb.checked ? '1' : '0');
    });

    fetch('{{ route("tenant.datasync.settings.save", $tenant->slug) }}', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'Accept': 'application/json',
        },
        body: formData,
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bx bx-save me-1"></i> Save Settings';
        if (data.success) {
            alert.className = 'alert alert-success';
            alert.textContent = '✓ Settings saved successfully.';
            alert.classList.remove('d-none');
            setTimeout(() => alert.classList.add('d-none'), 4000);
        } else {
            alert.className = 'alert alert-danger';
            alert.textContent = data.message || 'Failed to save settings.';
            alert.classList.remove('d-none');
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bx bx-save me-1"></i> Save Settings';
        alert.className = 'alert alert-danger';
        alert.textContent = 'Network error — please try again.';
        alert.classList.remove('d-none');
    });
});
</script>
@endsection
