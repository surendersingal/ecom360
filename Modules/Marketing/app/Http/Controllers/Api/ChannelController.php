<?php

declare(strict_types=1);

namespace Modules\Marketing\Http\Controllers\Api;

use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Modules\Marketing\Models\Channel;
use Modules\Marketing\Channels\ChannelManager;

final class ChannelController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $channels = Channel::where('tenant_id', $this->tenantId())
            ->orderBy('type')
            ->get()
            ->makeHidden('credentials'); // never leak credentials

        return $this->successResponse($channels);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:email,whatsapp,rcs,push,sms',
            'provider' => 'required|string|max:100',
            'credentials' => 'required|array',
        ]);

        $channel = Channel::create([
            'tenant_id' => $this->tenantId(),
            'name' => $request->input('name'),
            'type' => $request->input('type'),
            'provider' => $request->input('provider'),
            'credentials' => $request->input('credentials'),
            'settings' => $request->input('settings', []),
            'is_active' => true,
        ]);

        return $this->successResponse($channel->makeHidden('credentials'), 201);
    }

    public function show(int $id): JsonResponse
    {
        $channel = Channel::where('tenant_id', $this->tenantId())->find($id);
        if (!$channel) return $this->errorResponse('Channel not found', 404);

        return $this->successResponse($channel->makeHidden('credentials'));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $channel = Channel::where('tenant_id', $this->tenantId())->findOrFail($id);
        $channel->update($request->only(['name', 'provider', 'credentials', 'settings', 'is_active']));

        return $this->successResponse($channel->fresh()->makeHidden('credentials'));
    }

    public function destroy(int $id): JsonResponse
    {
        Channel::where('tenant_id', $this->tenantId())->findOrFail($id)->delete();

        return $this->successResponse(['deleted' => true]);
    }

    /**
     * Validate channel credentials by sending a test message.
     */
    public function test(Request $request, int $id): JsonResponse
    {
        $channel = Channel::where('tenant_id', $this->tenantId())->findOrFail($id);

        $manager = app(ChannelManager::class);
        try {
            $provider = $manager->resolve($channel->type);
            $valid = $provider->validateCredentials($channel);
            return $this->successResponse([
                'valid' => $valid,
                'message' => $valid ? 'Credentials are valid.' : 'Credential validation failed.',
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse('Validation failed: ' . $e->getMessage(), 422);
        }
    }

    /**
     * Get available providers for a channel type.
     */
    public function providers(string $type): JsonResponse
    {
        $providers = match ($type) {
            'email' => ['smtp', 'sendgrid', 'mailgun', 'ses', 'postmark'],
            'whatsapp' => ['meta', 'twilio', 'gupshup'],
            'rcs' => ['google', 'sinch', 'infobip'],
            'push' => ['fcm', 'onesignal', 'expo'],
            'sms' => ['twilio', 'vonage', 'msg91'],
            default => [],
        };

        return $this->successResponse(['type' => $type, 'providers' => $providers]);
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
