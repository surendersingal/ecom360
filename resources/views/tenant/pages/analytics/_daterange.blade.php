{{-- Inline date range picker for analytics sub-pages --}}
<div class="e360-period-toggle">
    <button class="period-btn {{ request('date_range', '30d') === '7d' ? 'active' : '' }}" onclick="window.location.href='?date_range=7d'">7D</button>
    <button class="period-btn {{ request('date_range', '30d') === '30d' ? 'active' : '' }}" onclick="window.location.href='?date_range=30d'">30D</button>
    <button class="period-btn {{ request('date_range', '30d') === '90d' ? 'active' : '' }}" onclick="window.location.href='?date_range=90d'">90D</button>
    <button class="period-btn {{ request('date_range', '30d') === '365d' ? 'active' : '' }}" onclick="window.location.href='?date_range=365d'">1Y</button>
</div>
