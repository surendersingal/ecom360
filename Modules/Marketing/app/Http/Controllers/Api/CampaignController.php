<?php

declare(strict_types=1);

namespace Modules\Marketing\Http\Controllers\Api;

use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Marketing\Services\CampaignService;
use Modules\Marketing\Jobs\SendCampaignJob;

final class CampaignController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $service = app(CampaignService::class);
        $campaigns = $service->list($this->tenantId(), $request->query());

        return $this->successResponse($campaigns);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'channel' => 'required|in:email,whatsapp,rcs,push,sms',
            'type' => 'required|in:one_time,recurring,triggered,ab_test',
            'template_id' => 'nullable|integer',
            'audience' => 'required|array',
            'audience.type' => 'required|in:list,segment,tags,all,contact_ids',
            'schedule' => 'nullable|array',
        ]);

        $data = $request->all();
        // Null out template_id if the referenced template doesn't exist (avoids FK constraint failure)
        if (!empty($data['template_id'])) {
            $exists = \Illuminate\Support\Facades\DB::table('marketing_templates')
                ->where('tenant_id', $this->tenantId())
                ->where('id', (int) $data['template_id'])
                ->exists();
            if (!$exists) {
                $data['template_id'] = null;
            }
        }

        $service = app(CampaignService::class);
        $campaign = $service->create($this->tenantId(), $data);

        return $this->successResponse($campaign, 201);
    }

    public function show(int $id): JsonResponse
    {
        $service = app(CampaignService::class);
        $campaign = $service->find($this->tenantId(), $id);

        if (!$campaign) return $this->errorResponse('Campaign not found', 404);

        return $this->successResponse($campaign);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $service = app(CampaignService::class);
            $campaign = $service->update($this->tenantId(), $id, $request->all());
            return $this->successResponse($campaign);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->errorResponse('Campaign not found', 404);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $service = app(CampaignService::class);
            $service->delete($this->tenantId(), $id);
            return $this->successResponse(['deleted' => true]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->errorResponse('Campaign not found', 404);
        }
    }

    public function send(int $id): JsonResponse
    {
        SendCampaignJob::dispatch($this->tenantId(), $id);

        return $this->successResponse(['status' => 'queued', 'message' => 'Campaign send has been queued.']);
    }

    public function stats(int $id): JsonResponse
    {
        $service = app(CampaignService::class);
        $service->refreshStats($this->tenantId(), $id);
        $campaign = $service->find($this->tenantId(), $id);

        if (!$campaign) return $this->errorResponse('Campaign not found', 404);

        return $this->successResponse([
            'campaign_id' => $id,
            'status' => $campaign->status ?? null,
            'sent_count' => $campaign->total_sent ?? 0,
            'delivered_count' => $campaign->total_delivered ?? 0,
            'opened_count' => $campaign->total_opened ?? 0,
            'clicked_count' => $campaign->total_clicked ?? 0,
            'bounced_count' => $campaign->total_bounced ?? 0,
            'unsubscribed_count' => $campaign->total_unsubscribed ?? 0,
            'open_rate' => $campaign->open_rate ?? 0,
            'click_rate' => $campaign->click_rate ?? 0,
            'conversion_rate' => $campaign->conversion_rate ?? 0,
        ]);
    }

    public function duplicate(int $id): JsonResponse
    {
        $service = app(CampaignService::class);
        $campaign = $service->duplicate($this->tenantId(), $id);

        return $this->successResponse($campaign, 201);
    }

    private function tenantId(): int
    {
        $user = Auth::user();
        if ($user === null || !isset($user->tenant_id)) {
            abort(403, 'Tenant context required.');
        }
        return (int) $user->tenant_id;
    }
}
