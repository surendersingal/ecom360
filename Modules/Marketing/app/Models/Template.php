<?php

declare(strict_types=1);

namespace Modules\Marketing\Models;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Template extends Model
{
    protected $table = 'marketing_templates';

    protected $fillable = [
        'tenant_id', 'channel', 'name', 'subject', 'body_html',
        'body_text', 'variables', 'attachments', 'thumbnail',
        'category', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'variables' => 'array',
            'attachments' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function campaigns(): HasMany { return $this->hasMany(Campaign::class, 'template_id'); }

    /**
     * Extract variable placeholders from body content.
     */
    public function extractVariables(): array
    {
        $content = ($this->subject ?? '') . ' ' . ($this->body_html ?? '') . ' ' . ($this->body_text ?? '');
        preg_match_all('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', $content, $matches);
        return array_unique($matches[1] ?? []);
    }

    /**
     * Render template with resolved variables.
     */
    public function render(array $variables): array
    {
        $replace = function (string $text) use ($variables): string {
            return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', function ($m) use ($variables) {
                return $variables[$m[1]] ?? $m[0];
            }, $text);
        };

        return [
            'subject' => $replace($this->subject ?? ''),
            'html' => $replace($this->body_html ?? ''),
            'text' => $replace($this->body_text ?? ''),
        ];
    }
}
