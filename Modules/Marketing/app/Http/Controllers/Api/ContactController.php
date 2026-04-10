<?php

declare(strict_types=1);

namespace Modules\Marketing\Http\Controllers\Api;

use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Modules\Marketing\Services\ContactService;

final class ContactController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $service = app(ContactService::class);
        $contacts = $service->list($this->tenantId(), $request->query());

        return $this->successResponse($contacts);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'phone' => 'nullable|string',
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'tags' => 'nullable|array',
            'custom_fields' => 'nullable|array',
        ]);

        $service = app(ContactService::class);
        $contact = $service->upsert($this->tenantId(), $request->all());

        return $this->successResponse($contact, 201);
    }

    public function show(int $id): JsonResponse
    {
        $service = app(ContactService::class);
        $contact = $service->find($this->tenantId(), $id);

        if (!$contact) return $this->errorResponse('Contact not found', 404);

        return $this->successResponse($contact);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $service = app(ContactService::class);
            $contact = $service->update($this->tenantId(), $id, $request->all());
            return $this->successResponse($contact);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->errorResponse('Contact not found', 404);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $service = app(ContactService::class);
            $service->delete($this->tenantId(), $id);
            return $this->successResponse(['deleted' => true]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->errorResponse('Contact not found', 404);
        }
    }

    public function bulkImport(Request $request): JsonResponse
    {
        $request->validate([
            'contacts' => 'required|array|min:1',
            'contacts.*.email' => 'required|email',
            'list_id' => 'nullable|integer',
        ]);

        $service = app(ContactService::class);
        $result = $service->bulkImport($this->tenantId(), $request->input('contacts'), $request->input('list_id'));

        return $this->successResponse($result);
    }

    public function unsubscribe(Request $request, int $id): JsonResponse
    {
        $service = app(ContactService::class);
        $service->unsubscribe($this->tenantId(), $id, $request->input('channel', 'email'));

        return $this->successResponse(['unsubscribed' => true]);
    }

    public function lists(Request $request): JsonResponse
    {
        $service = app(ContactService::class);
        $lists = $service->getLists($this->tenantId());

        return $this->successResponse($lists);
    }

    public function createList(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $service = app(ContactService::class);
        $list = $service->createList($this->tenantId(), $request->all());

        return $this->successResponse($list, 201);
    }

    public function addToList(Request $request, int $listId): JsonResponse
    {
        $request->validate(['contact_ids' => 'required|array']);

        $service = app(ContactService::class);
        $result = $service->addToList($this->tenantId(), $listId, $request->input('contact_ids'));

        return $this->successResponse($result);
    }

    public function removeFromList(Request $request, int $listId): JsonResponse
    {
        $request->validate(['contact_ids' => 'required|array']);

        $service = app(ContactService::class);
        $result = $service->removeFromList($this->tenantId(), $listId, $request->input('contact_ids'));

        return $this->successResponse($result);
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
