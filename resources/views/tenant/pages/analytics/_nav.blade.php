{{-- Analytics sub-navigation (Matomo-style section tabs) --}}
@php
    $currentRoute = request()->route()?->getName() ?? '';
    $slug = $tenant->slug;

    $sections = [
        'dashboard' => [
            'label' => 'Dashboard',
            'icon' => 'bx-grid-alt',
            'route' => route('tenant.analytics.overview', $slug),
            'active' => $currentRoute === 'tenant.analytics.overview',
        ],
        'visitors' => [
            'label' => 'Visitors',
            'icon' => 'bx-user',
            'children' => [
                ['label' => 'Overview', 'route' => route('tenant.analytics.visitors', $slug), 'name' => 'tenant.analytics.visitors'],
                ['label' => 'Visitor Log', 'route' => route('tenant.analytics.visitor-log', $slug), 'name' => 'tenant.analytics.visitor-log'],
                ['label' => 'Real-Time', 'route' => route('tenant.analytics.realtime', $slug), 'name' => 'tenant.analytics.realtime'],
                ['label' => 'Devices', 'route' => route('tenant.analytics.devices', $slug), 'name' => 'tenant.analytics.devices'],
                ['label' => 'Locations', 'route' => route('tenant.analytics.locations', $slug), 'name' => 'tenant.analytics.locations'],
                ['label' => 'Times', 'route' => route('tenant.analytics.times', $slug), 'name' => 'tenant.analytics.times'],
            ],
        ],
        'behaviour' => [
            'label' => 'Behaviour',
            'icon' => 'bx-pointer',
            'children' => [
                ['label' => 'Pages', 'route' => route('tenant.analytics.pages', $slug), 'name' => 'tenant.analytics.pages'],
                ['label' => 'Entry Pages', 'route' => route('tenant.analytics.entry-pages', $slug), 'name' => 'tenant.analytics.entry-pages'],
                ['label' => 'Exit Pages', 'route' => route('tenant.analytics.exit-pages', $slug), 'name' => 'tenant.analytics.exit-pages'],
                ['label' => 'Events', 'route' => route('tenant.analytics.events', $slug), 'name' => 'tenant.analytics.events'],
                ['label' => 'Site Search', 'route' => route('tenant.analytics.site-search', $slug), 'name' => 'tenant.analytics.site-search'],
            ],
        ],
        'acquisition' => [
            'label' => 'Acquisition',
            'icon' => 'bx-rocket',
            'children' => [
                ['label' => 'Channels', 'route' => route('tenant.analytics.channels', $slug), 'name' => 'tenant.analytics.channels'],
                ['label' => 'Campaigns', 'route' => route('tenant.analytics.campaigns', $slug), 'name' => 'tenant.analytics.campaigns'],
                ['label' => 'Referrers', 'route' => route('tenant.analytics.referrers', $slug), 'name' => 'tenant.analytics.referrers'],
            ],
        ],
        'ecommerce' => [
            'label' => 'Ecommerce',
            'icon' => 'bx-cart',
            'children' => [
                ['label' => 'Overview', 'route' => route('tenant.analytics.ecommerce', $slug), 'name' => 'tenant.analytics.ecommerce'],
                ['label' => 'Products', 'route' => route('tenant.analytics.products', $slug), 'name' => 'tenant.analytics.products'],
                ['label' => 'Categories', 'route' => route('tenant.analytics.categories', $slug), 'name' => 'tenant.analytics.categories'],
                ['label' => 'Funnel', 'route' => route('tenant.analytics.funnel', $slug), 'name' => 'tenant.analytics.funnel'],
                ['label' => 'Abandoned Carts', 'route' => route('tenant.analytics.abandoned-carts', $slug), 'name' => 'tenant.analytics.abandoned-carts'],
            ],
        ],
        'ai' => [
            'label' => 'AI Insights',
            'icon' => 'bx-brain',
            'children' => [
                ['label' => 'AI Analytics', 'route' => route('tenant.analytics.ai-insights', $slug), 'name' => 'tenant.analytics.ai-insights'],
                ['label' => 'Ask a Question', 'route' => route('tenant.analytics.ask', $slug), 'name' => 'tenant.analytics.ask'],
                ['label' => 'Predictions', 'route' => route('tenant.analytics.predictions', $slug), 'name' => 'tenant.analytics.predictions'],
                ['label' => 'Benchmarks', 'route' => route('tenant.analytics.benchmarks', $slug), 'name' => 'tenant.analytics.benchmarks'],
                ['label' => 'Alerts', 'route' => route('tenant.analytics.alerts', $slug), 'name' => 'tenant.analytics.alerts'],
            ],
        ],
    ];
@endphp

<div class="e360-analytics-nav">
    @foreach($sections as $key => $sec)
        @if(isset($sec['route']))
            <a href="{{ $sec['route'] }}" class="e360-anav-item {{ $sec['active'] ? 'active' : '' }}">
                <i class="bx {{ $sec['icon'] }}"></i> {{ $sec['label'] }}
            </a>
        @else
            @php
                $isOpen = false;
                foreach($sec['children'] as $child) {
                    if($currentRoute === $child['name']) { $isOpen = true; break; }
                }
            @endphp
            <div class="e360-anav-group {{ $isOpen ? 'open' : '' }}">
                <button class="e360-anav-item {{ $isOpen ? 'active' : '' }}" onclick="this.parentElement.classList.toggle('open')">
                    <i class="bx {{ $sec['icon'] }}"></i> {{ $sec['label'] }}
                    <i class="bx bx-chevron-down e360-anav-chev"></i>
                </button>
                <div class="e360-anav-sub">
                    @foreach($sec['children'] as $child)
                        <a href="{{ $child['route'] }}" class="{{ $currentRoute === $child['name'] ? 'active' : '' }}">
                            {{ $child['label'] }}
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    @endforeach
</div>

{{-- Date range picker --}}
<div class="e360-analytics-daterange">
    <div class="e360-period-toggle">
        <button class="period-btn {{ request('date_range', '30d') === '7d' ? 'active' : '' }}" data-range="7d">7D</button>
        <button class="period-btn {{ request('date_range', '30d') === '30d' ? 'active' : '' }}" data-range="30d">30D</button>
        <button class="period-btn {{ request('date_range', '30d') === '90d' ? 'active' : '' }}" data-range="90d">90D</button>
        <button class="period-btn {{ request('date_range', '30d') === '365d' ? 'active' : '' }}" data-range="365d">1Y</button>
    </div>
</div>
