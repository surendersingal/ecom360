<?php
declare(strict_types=1);

namespace Modules\AiSearch\Models;

use MongoDB\Laravel\Eloquent\Model;

class SearchLog extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'search_logs';

    protected $fillable = [
        'tenant_id',
        'session_id',
        'visitor_id',
        'customer_email',
        'query',
        'query_type',         // text | visual | voice
        'results_count',
        'clicked_product_id',
        'clicked_position',
        'converted',
        'conversion_order_id',
        'language',
        'filters_applied',
        'response_time_ms',
        'metadata',
    ];

    protected $casts = [
        'tenant_id'       => 'integer',
        'results_count'   => 'integer',
        'clicked_position' => 'integer',
        'converted'       => 'boolean',
        'response_time_ms' => 'integer',
        'filters_applied' => 'array',
        'metadata'        => 'array',
    ];
}
