<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\MaterialMemo;
use App\Models\MaterialMemoItem;
use App\Models\User;
use App\Services\MaterialMemoRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * Alur persetujuan memo barang di mobile (2026-11-17): minta tambahan/ajukan ulang, setujui, tolak, catat penyerahan oleh gudang, selesai
 * diserahkan, isi meter gulungan, dan kotak masuk. Aturan siapa boleh apa ada di MaterialMemoRequestService; di sini hanya bentuk respons.
 * Penyetuju (store manager) dan gudang belum tentu punya menu Memo, jadi endpoint ini tidak mensyaratkannya.
 */
class MaterialMemoRequestController extends Controller
{
    public function __construct(private readonly MaterialMemoRequestService $service)
    {
    }

    private function shape(MaterialMemo $memo, User $user): array
    {
        $memo->load(['creator:id,name', 'store:id,name', 'items', 'booking:id,booking_number,customer_name']);

        return $memo->toArray() + [
            'request_status_label' => MaterialMemo::REQUEST_STATUS_LABELS[$memo->request_status] ?? null,
            'is_locked' => $memo->isLocked(),
            'can_approve' => $memo->request_status === MaterialMemo::REQ_PENDING && $this->service->canApprove($user, $memo),
            'can_hand_over' => $memo->request_status === MaterialMemo::REQ_APPROVED && $this->service->canHandOver($user, $memo),
            'can_request_more' => in_array($memo->request_status, [MaterialMemo::REQ_REJECTED, MaterialMemo::REQ_FULFILLED], true)
                && ((int) $memo->created_by === (int) $user->id || $user->isFullAccess()),
        ];
    }

    private function fail(InvalidArgumentException $e): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
    }

    private function find(int $id): MaterialMemo|JsonResponse
    {
        $memo = MaterialMemo::withoutGlobalScopes()->find($id);

        return $memo && $memo->isRequestFlow() ? $memo : response()->json(['success' => false, 'message' => 'Memo pengajuan tidak ditemukan.'], 404);
    }

    private function ok(string $message, MaterialMemo $memo, Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $this->shape($memo->fresh(), $request->user('api'))]);
    }

    /** Memo yang menunggu keputusan akun ini: yang bisa disetujui, dan yang sedang/menunggu diserahkan. */
    public function inbox(Request $request)
    {
        $user = $request->user('api');

        $pending = MaterialMemo::withoutGlobalScopes()->with(['creator:id,name', 'store:id,name'])
            ->where('request_status', MaterialMemo::REQ_PENDING)->orderBy('submitted_at')->get()
            ->filter(fn (MaterialMemo $m) => $this->service->canApprove($user, $m))->values();

        $handover = MaterialMemo::withoutGlobalScopes()->with(['creator:id,name', 'store:id,name'])
            ->where('request_status', MaterialMemo::REQ_APPROVED)->orderBy('approved_at')->get()
            ->filter(fn (MaterialMemo $m) => $this->service->canHandOver($user, $m))->values();

        return response()->json([
            'success' => true,
            'data' => [
                'to_approve' => $pending->map(fn (MaterialMemo $m) => $this->shape($m, $user))->values(),
                'to_hand_over' => $handover->map(fn (MaterialMemo $m) => $this->shape($m, $user))->values(),
            ],
        ]);
    }

    public function showRequest(Request $request, int $id)
    {
        $memo = $this->find($id);
        $user = $request->user('api');

        if ($memo instanceof JsonResponse) {
            return $memo;
        }

        $allowed = (int) $memo->created_by === (int) $user->id || $user->isFullAccess()
            || $this->service->canApprove($user, $memo) || $this->service->canHandOver($user, $memo);

        if (! $allowed) {
            return response()->json(['success' => false, 'message' => 'Anda tidak terkait dengan memo ini.'], 403);
        }

        return response()->json(['success' => true, 'data' => $this->shape($memo, $user)]);
    }

    /** Ajukan ulang setelah ditolak, atau minta tambahan setelah selesai diserahkan. Body: {"note": "..."} (wajib untuk tambahan). */
    public function resubmit(Request $request, int $id)
    {
        $memo = $this->find($id);

        if ($memo instanceof JsonResponse) {
            return $memo;
        }

        $validator = Validator::make($request->all(), ['note' => 'nullable|string|max:500']);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Data yang dikirim tidak valid.', 'errors' => $validator->errors()], 422);
        }

        try {
            $this->service->resubmit($memo, $request->user('api'), $request->input('note'));
        } catch (InvalidArgumentException $e) {
            return $this->fail($e);
        }

        return $this->ok('Pengajuan dikirim. Menunggu persetujuan store manager.', $memo, $request);
    }

    public function approve(Request $request, int $id)
    {
        $memo = $this->find($id);

        if ($memo instanceof JsonResponse) {
            return $memo;
        }

        try {
            $this->service->approve($memo, $request->user('api'));
        } catch (InvalidArgumentException $e) {
            return $this->fail($e);
        }

        return $this->ok('Memo disetujui. Gudang akan mencatat barang yang diambil.', $memo, $request);
    }

    public function reject(Request $request, int $id)
    {
        $memo = $this->find($id);

        if ($memo instanceof JsonResponse) {
            return $memo;
        }

        $validator = Validator::make($request->all(), ['reason' => 'required|string|max:500']);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Isi alasan penolakan.', 'errors' => $validator->errors()], 422);
        }

        try {
            $this->service->reject($memo, $request->user('api'), (string) $request->reason);
        } catch (InvalidArgumentException $e) {
            return $this->fail($e);
        }

        return $this->ok('Memo ditolak.', $memo, $request);
    }

    /** Gudang mencatat barang yang diserahkan. Bahan: qty wajib. Gulungan PPF/WF (inventory_item): tanpa meter. */
    public function addHandoverItem(Request $request, int $id)
    {
        $memo = $this->find($id);

        if ($memo instanceof JsonResponse) {
            return $memo;
        }

        $validator = Validator::make($request->all(), [
            'item_type' => 'required|in:raw_material,consumable_item,inventory_item',
            'item_id' => 'required|integer',
            'qty' => 'nullable|numeric|min:0.01|max:1000000',
            'note' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Data yang dikirim tidak valid.', 'errors' => $validator->errors()], 422);
        }

        try {
            $this->service->addItem($memo, $request->user('api'), $request->item_type, (int) $request->item_id, $request->filled('qty') ? (float) $request->qty : null, $request->note);
        } catch (InvalidArgumentException $e) {
            return $this->fail($e);
        }

        return $this->ok('Barang dicatat.', $memo, $request);
    }

    public function updateHandoverItem(Request $request, int $id, int $itemId)
    {
        $memo = $this->find($id);

        if ($memo instanceof JsonResponse) {
            return $memo;
        }

        $item = MaterialMemoItem::where('material_memo_id', $memo->id)->find($itemId);

        if (! $item) {
            return response()->json(['success' => false, 'message' => 'Baris tidak ditemukan.'], 404);
        }

        $validator = Validator::make($request->all(), ['qty' => 'required|numeric|min:0.01|max:1000000']);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Data yang dikirim tidak valid.', 'errors' => $validator->errors()], 422);
        }

        try {
            $this->service->updateItemQty($memo, $item, $request->user('api'), (float) $request->qty);
        } catch (InvalidArgumentException $e) {
            return $this->fail($e);
        }

        return $this->ok('Jumlah diubah.', $memo, $request);
    }

    public function destroyHandoverItem(Request $request, int $id, int $itemId)
    {
        $memo = $this->find($id);

        if ($memo instanceof JsonResponse) {
            return $memo;
        }

        $item = MaterialMemoItem::where('material_memo_id', $memo->id)->find($itemId);

        if (! $item) {
            return response()->json(['success' => false, 'message' => 'Baris tidak ditemukan.'], 404);
        }

        try {
            $this->service->removeItem($memo, $item, $request->user('api'));
        } catch (InvalidArgumentException $e) {
            return $this->fail($e);
        }

        return $this->ok('Barang dihapus dari catatan.', $memo, $request);
    }

    /** Gudang mencatat bahan sisa yang dikembalikan. Body: {"qty_returned": 1.5} */
    public function returnHandoverItem(Request $request, int $id, int $itemId)
    {
        $memo = $this->find($id);

        if ($memo instanceof JsonResponse) {
            return $memo;
        }

        $item = MaterialMemoItem::where('material_memo_id', $memo->id)->find($itemId);

        if (! $item) {
            return response()->json(['success' => false, 'message' => 'Baris tidak ditemukan.'], 404);
        }

        $validator = Validator::make($request->all(), ['qty_returned' => ['required', 'numeric', 'min:0', 'max:1000000']]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Isi jumlah yang dikembalikan.', 'errors' => $validator->errors()], 422);
        }

        try {
            $this->service->recordReturn($memo, $item, $request->user('api'), (float) $request->qty_returned);
        } catch (InvalidArgumentException $e) {
            return $this->fail($e);
        }

        return $this->ok('Pengembalian dicatat.', $memo, $request);
    }

    public function complete(Request $request, int $id)
    {
        $memo = $this->find($id);

        if ($memo instanceof JsonResponse) {
            return $memo;
        }

        try {
            $this->service->complete($memo, $request->user('api'));
        } catch (InvalidArgumentException $e) {
            return $this->fail($e);
        }

        return $this->ok('Selesai diserahkan. Memo terkunci dan direksi diberi tahu.', $memo, $request);
    }

    /** Isi meter pemakaian gulungan yang diserahkan tanpa meter. Body: {"meters": 3.5} */
    public function setMeters(Request $request, int $id, int $itemId)
    {
        $memo = $this->find($id);

        if ($memo instanceof JsonResponse) {
            return $memo;
        }

        $item = MaterialMemoItem::where('material_memo_id', $memo->id)->find($itemId);

        if (! $item) {
            return response()->json(['success' => false, 'message' => 'Baris tidak ditemukan.'], 404);
        }

        $validator = Validator::make($request->all(), ['meters' => 'required|numeric|min:0.01|max:100000']);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Isi meter pemakaian.', 'errors' => $validator->errors()], 422);
        }

        try {
            $this->service->setMeters($memo, $item, $request->user('api'), (float) $request->meters);
        } catch (InvalidArgumentException $e) {
            return $this->fail($e);
        }

        return $this->ok('Meter pemakaian dicatat.', $memo, $request);
    }
}
