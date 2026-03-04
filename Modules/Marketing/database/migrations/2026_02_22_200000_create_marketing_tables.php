<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketing module — campaigns, templates, automations, contacts, channels.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Contact Lists ──────────────────────────────────────────────
        Schema::create('marketing_contact_lists', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('source', ['manual', 'segment', 'import', 'api'])->default('manual');
            $table->unsignedBigInteger('segment_id')->nullable();
            $table->unsignedInteger('contact_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['tenant_id', 'is_active']);
        });

        // ── Contacts ───────────────────────────────────────────────────
        Schema::create('marketing_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->json('custom_fields')->nullable();
            $table->json('tags')->nullable();
            $table->enum('status', ['subscribed', 'unsubscribed', 'bounced', 'complained'])->default('subscribed');
            $table->string('whatsapp_opt_in')->nullable();
            $table->string('push_token', 512)->nullable();
            $table->string('device_id')->nullable();
            $table->timestamp('subscribed_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'email']);
            $table->index(['tenant_id', 'phone']);
            $table->index(['tenant_id', 'status']);
        });

        // ── Contact ↔ List pivot ───────────────────────────────────────
        Schema::create('marketing_contact_list_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->constrained('marketing_contacts')->cascadeOnDelete();
            $table->foreignId('list_id')->constrained('marketing_contact_lists')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['contact_id', 'list_id']);
        });

        // ── Channel Configurations ─────────────────────────────────────
        Schema::create('marketing_channels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->enum('type', ['email', 'whatsapp', 'rcs', 'push', 'sms'])->default('email');
            $table->string('name');
            $table->string('provider');  // sendgrid, mailgun, twilio, gupshup, firebase, etc.
            $table->json('credentials')->nullable();
            $table->json('settings')->nullable();  // from_email, from_name, etc.
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->index(['tenant_id', 'type', 'is_active']);
        });

        // ── Templates ──────────────────────────────────────────────────
        Schema::create('marketing_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->enum('channel', ['email', 'whatsapp', 'rcs', 'push', 'sms']);
            $table->string('name');
            $table->string('subject')->nullable();
            $table->longText('body_html')->nullable();
            $table->text('body_text')->nullable();
            $table->json('variables')->nullable();     // [{name, default, type}]
            $table->json('attachments')->nullable();
            $table->string('thumbnail')->nullable();
            $table->string('category')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['tenant_id', 'channel', 'is_active']);
        });

        // ── Campaigns ──────────────────────────────────────────────────
        Schema::create('marketing_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('type', ['one_time', 'recurring', 'triggered', 'ab_test'])->default('one_time');
            $table->enum('channel', ['email', 'whatsapp', 'rcs', 'push', 'sms', 'multi']);
            $table->enum('status', ['draft', 'scheduled', 'running', 'paused', 'completed', 'cancelled'])->default('draft');
            $table->foreignId('template_id')->nullable()->constrained('marketing_templates')->nullOnDelete();
            $table->json('audience')->nullable();       // {list_ids:[], segment_ids:[], filters:{}}
            $table->json('schedule')->nullable();       // {send_at, timezone, recurrence}
            $table->json('ab_variants')->nullable();    // [{template_id, weight, name}]
            $table->unsignedInteger('total_sent')->default(0);
            $table->unsignedInteger('total_delivered')->default(0);
            $table->unsignedInteger('total_opened')->default(0);
            $table->unsignedInteger('total_clicked')->default(0);
            $table->unsignedInteger('total_converted')->default(0);
            $table->unsignedInteger('total_bounced')->default(0);
            $table->unsignedInteger('total_unsubscribed')->default(0);
            $table->decimal('total_revenue', 12, 2)->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'channel']);
        });

        // ── Campaign Messages (individual sends) ──────────────────────
        Schema::create('marketing_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained('marketing_campaigns')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('marketing_contacts')->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('marketing_templates')->nullOnDelete();
            $table->enum('channel', ['email', 'whatsapp', 'rcs', 'push', 'sms']);
            $table->enum('status', ['queued', 'sending', 'sent', 'delivered', 'opened', 'clicked', 'bounced', 'failed', 'unsubscribed'])->default('queued');
            $table->string('external_id')->nullable();  // provider message ID
            $table->json('variables_resolved')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->timestamps();
            $table->index(['campaign_id', 'status']);
            $table->index(['contact_id', 'channel']);
        });

        // ── Automation Flows ───────────────────────────────────────────
        Schema::create('marketing_flows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('trigger_type', [
                'event',           // analytics event: purchase, cart_abandon, signup
                'segment_enter',   // customer enters a segment
                'segment_exit',    // customer exits a segment
                'date_field',      // birthday, anniversary
                'manual',          // triggered via API
                'schedule',        // cron-based
                'webhook',         // external trigger
            ]);
            $table->json('trigger_config')->nullable();  // {event_type, conditions, schedule}
            $table->json('canvas')->nullable();           // Flow builder canvas (nodes + edges)
            $table->enum('status', ['draft', 'active', 'paused', 'archived'])->default('draft');
            $table->unsignedInteger('enrolled_count')->default(0);
            $table->unsignedInteger('completed_count')->default(0);
            $table->unsignedInteger('converted_count')->default(0);
            $table->decimal('revenue_attributed', 12, 2)->default(0);
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'trigger_type']);
        });

        // ── Flow Nodes (steps in the flow) ─────────────────────────────
        Schema::create('marketing_flow_nodes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('flow_id')->constrained('marketing_flows')->cascadeOnDelete();
            $table->string('node_id', 64);              // UUID for frontend drag-drop
            $table->enum('type', [
                'trigger',         // entry point
                'send_email',      // send email
                'send_whatsapp',   // send whatsapp
                'send_rcs',        // send rcs
                'send_push',       // send push
                'send_sms',        // send sms
                'delay',           // wait X minutes/hours/days
                'condition',       // if/else branch
                'split',           // random A/B split
                'webhook',         // call external URL
                'update_contact',  // update contact fields/tags
                'add_to_list',     // add to contact list
                'remove_from_list',// remove from list
                'goal',            // conversion goal check
                'exit',            // end flow
            ]);
            $table->json('config')->nullable();          // type-specific config
            $table->json('position')->nullable();        // {x, y} for canvas
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['flow_id', 'node_id']);
        });

        // ── Flow Edges (connections between nodes) ─────────────────────
        Schema::create('marketing_flow_edges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('flow_id')->constrained('marketing_flows')->cascadeOnDelete();
            $table->string('source_node_id', 64);
            $table->string('target_node_id', 64);
            $table->string('label')->nullable();         // "Yes", "No", "Variant A"
            $table->json('condition')->nullable();        // edge condition data
            $table->timestamps();
            $table->index(['flow_id']);
        });

        // ── Flow Enrollments (users in flow) ──────────────────────────
        Schema::create('marketing_flow_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('flow_id')->constrained('marketing_flows')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('marketing_contacts')->cascadeOnDelete();
            $table->string('current_node_id', 64)->nullable();
            $table->enum('status', ['active', 'waiting', 'completed', 'exited', 'failed'])->default('active');
            $table->json('context')->nullable();          // variables resolved at enrollment
            $table->timestamp('entered_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('next_action_at')->nullable();
            $table->timestamps();
            $table->index(['flow_id', 'status']);
            $table->index(['next_action_at', 'status']);
            $table->unique(['flow_id', 'contact_id']);
        });

        // ── Flow Execution Log ─────────────────────────────────────────
        Schema::create('marketing_flow_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('enrollment_id')->constrained('marketing_flow_enrollments')->cascadeOnDelete();
            $table->string('node_id', 64);
            $table->string('action');
            $table->enum('result', ['success', 'failure', 'skipped'])->default('success');
            $table->json('data')->nullable();
            $table->timestamps();
            $table->index(['enrollment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_flow_logs');
        Schema::dropIfExists('marketing_flow_enrollments');
        Schema::dropIfExists('marketing_flow_edges');
        Schema::dropIfExists('marketing_flow_nodes');
        Schema::dropIfExists('marketing_flows');
        Schema::dropIfExists('marketing_messages');
        Schema::dropIfExists('marketing_campaigns');
        Schema::dropIfExists('marketing_templates');
        Schema::dropIfExists('marketing_channels');
        Schema::dropIfExists('marketing_contact_list_members');
        Schema::dropIfExists('marketing_contacts');
        Schema::dropIfExists('marketing_contact_lists');
    }
};
