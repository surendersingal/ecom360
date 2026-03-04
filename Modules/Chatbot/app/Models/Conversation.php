<?php
declare(strict_types=1);

namespace Modules\Chatbot\Models;

use MongoDB\Laravel\Eloquent\Model;

class Conversation extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'chatbot_conversations';

    protected $fillable = [
        'tenant_id',
        'session_id',
        'visitor_id',
        'customer_email',
        'customer_name',
        'channel',          // widget | email | sms | whatsapp
        'status',           // active | resolved | escalated | abandoned
        'intent',           // order_tracking | product_inquiry | support | checkout | general
        'language',
        'started_at',
        'resolved_at',
        'escalated_at',
        'satisfaction_score',
        'metadata',
    ];

    protected $casts = [
        'tenant_id'   => 'integer',
        'metadata'    => 'array',
        'started_at'  => 'datetime',
        'resolved_at' => 'datetime',
        'escalated_at' => 'datetime',
        'satisfaction_score' => 'integer',
    ];

    public function messages()
    {
        return Message::where('conversation_id', (string) $this->_id)
            ->orderBy('created_at', 'asc')
            ->get();
    }
}
