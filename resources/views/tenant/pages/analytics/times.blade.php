@extends('layouts.tenant')
@section('title', 'Visit Times')
@section('content')
    <div class="e360-page-header">
        <div>
            <h4><i class="bx bx-time-five" style="color:var(--analytics);"></i> Visit Times</h4>
            <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="{{ route('tenant.analytics.overview', $tenant->slug) }}">Analytics</a></li>
                <li class="breadcrumb-item active">Times</li>
            </ol></nav>
        </div>
    </div>
    <div class="d-flex justify-content-end mb-3">
        @include('tenant.pages.analytics._daterange')
    </div>
    <div class="e360-analytics-body">
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Traffic by Hour of Day</h5>
                    <div id="hourly-chart" style="height:300px;"></div>
                </div></div>
            </div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-xl-6">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Visits by Day of Week</h5>
                    <div id="day-chart" style="height:280px;"></div>
                </div></div>
            </div>
            <div class="col-xl-6">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Peak Hours Heatmap</h5>
                    <div class="table-responsive" id="heatmap-wrap" style="overflow-x:auto;"></div>
                </div></div>
            </div>
        </div>
    </div>
@endsection
@section('script')
<script src="{{ URL::asset('build/libs/apexcharts/apexcharts.min.js') }}"></script>
@endsection
@section('script-bottom')
<script>
(function(){
    const API = EcomAPI.baseUrl + '/analytics';
    const range = new URLSearchParams(location.search).get('date_range') || '30d';

    // Fetch both hourly (from geographic) and day-of-week (from dedicated endpoint) in parallel
    $.when(
        $.get(API + '/geographic', { date_range: range }),
        $.get(API + '/day-of-week', { date_range: range })
    ).then(function(geoRes, dowRes) {
        const g = geoRes[0]?.data || geoRes[0] || {};
        const dow = dowRes[0]?.data || dowRes[0] || {};
        const hourly = g.traffic_by_hour || {};
        // API returns parallel arrays: { hours: [...], views: [...] }
        const hourLabels = hourly.hours || [];
        const hourValues = hourly.views || [];

        if (hourLabels.length) {
            const hours = hourLabels.map(h => h + ':00');
            const values = hourValues;

            new ApexCharts(document.querySelector('#hourly-chart'), {
                chart:{type:'bar',height:300,fontFamily:'Inter',toolbar:{show:false}},
                series:[{name:'Visits',data:values}],
                xaxis:{categories:hours,labels:{style:{fontSize:'10px'}}},
                colors:['#1A56DB'],
                plotOptions:{bar:{borderRadius:4,columnWidth:'60%'}},
                dataLabels:{enabled:false},
                grid:{borderColor:'#E2E8F0',strokeDashArray:4},
            }).render();
        }

        // Day of week from real data
        const dayOfWeekData = dow.day_of_week || [];
        if (dayOfWeekData.length) {
            const days = dayOfWeekData.map(d => d.day);
            const dayValues = dayOfWeekData.map(d => d.count);
            new ApexCharts(document.querySelector('#day-chart'), {
                chart:{type:'bar',height:280,fontFamily:'Inter',toolbar:{show:false}},
                series:[{name:'Visits',data:dayValues}],
                xaxis:{categories:days},
                colors:['#10B981'],
                plotOptions:{bar:{borderRadius:6,columnWidth:'50%'}},
                dataLabels:{enabled:true,formatter:v=>EcomUtils.number(v),style:{fontSize:'11px'}},
            }).render();
        }

        // Heatmap from real data
        const heatmapRaw = dow.heatmap || [];
        const days = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
        const heatmapGrid = {};
        let maxVal = 1;
        heatmapRaw.forEach(h => {
            const key = h.day + '_' + h.hour;
            heatmapGrid[key] = h.count;
            if (h.count > maxVal) maxVal = h.count;
        });

        let hmHtml = '<table style="width:100%;font-size:10px;border-collapse:collapse;"><thead><tr><th style="padding:4px;">Hour</th>';
        days.forEach(d => hmHtml += `<th style="padding:4px;text-align:center;">${d}</th>`);
        hmHtml += '</tr></thead><tbody>';
        for(let h=0; h<24; h++) {
            hmHtml += `<tr><td class="mono" style="padding:4px;">${String(h).padStart(2,'0')}:00</td>`;
            days.forEach((_, di) => {
                const dowIndex = di + 1; // Mongo: 1=Sun, 2=Mon, ...
                const val = heatmapGrid[dowIndex + '_' + h] || 0;
                const intensity = val / maxVal;
                const bg = intensity > 0.7 ? 'rgba(26,86,219,0.5)' : intensity > 0.4 ? 'rgba(26,86,219,0.25)' : intensity > 0.1 ? 'rgba(26,86,219,0.1)' : 'transparent';
                hmHtml += `<td class="mono text-center" style="padding:4px;background:${bg};border-radius:3px;">${val}</td>`;
            });
            hmHtml += '</tr>';
        }
        hmHtml += '</tbody></table>';
        $('#heatmap-wrap').html(hmHtml);
    }).catch(function(){
        console.warn('Failed to load visit times data');
        $('#heatmap-wrap').html('<p class="text-center text-muted py-3">Unable to load data. Please try again.</p>');
    });
})();
</script>
@endsection
