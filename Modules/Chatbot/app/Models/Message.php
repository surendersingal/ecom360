<?php
declare(strict_types=1);

namespace Modules\Chatbot\Models;

use MongoDB\Laravel\Eloquent\Model;

class Message extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'chatbot_messages';

    protected $fillable = [
        'conversation_id',
        'tenant_id',
        'role',              // user | assistant | system
        'content',
        'content_type',      // text | html | card | carousel | quick_reply | action
        'intent',
        'confidence',
        'attachments',
        'quick_replies',     // Array of button options
        'action',            // add_to_cart | apply_coupon | redirect | escalate | null
        'action_payload',
        'metadata',
    ];

    protected $casts = [
        'tenant_id'      => 'integer',
        'confidence'     => 'float',
        'attachments'    => 'array',
        'quick_replies'  => 'array',
        'action_payload' => 'array',
        'metadata'       => 'array',
    ];
}
