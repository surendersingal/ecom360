<?php

declare(strict_types=1);

namespace Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Message extends Model
{
    protected $table = 'marketing_messages';

    protected $fillable = [
        'campaign_id', 'contact_id', 'template_id', 'channel', 'status',
        'external_id', 'variables_resolved', 'error_message',
        'sent_at', 'delivered_at', 'opened_at', 'clicked_at',
    ];

    protected function casts(): array
    {
        return [
            'variables_resolved' => 'array',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'opened_at' => 'datetime',
            'clicked_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo { return $this->belongsTo(Campaign::class); }
    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function template(): BelongsTo { return $this->belongsTo(Template::class); }
}
