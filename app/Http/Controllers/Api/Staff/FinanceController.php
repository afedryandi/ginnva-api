<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\CashCount;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use App\Models\FinanceTransactionApprovalRequest;
use App\Models\PaymentAccount;
use App\Models\Receivable;
use App\Models\Store;
use App\Models\User;
use App\Services\CashCountService;
use App\Services\FinanceTransactionApprovalService;
use App\Services\FinanceTransactionPostingService;
use App\Services\FinancialStatementService;
use App\Services\PettyCashService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Keuangan di aplikasi staf (Keuangan Fase 5, 2026-11-08): ringkasan kas toko, catat pengeluaran dengan foto bukti, persetujuan pengeluaran,
 * dan hitung kas harian. Memakai layanan yang sama dengan panel (PettyCashService, FinanceTransactionApprovalService, CashCountService),
 * jadi aturan kas kecil, batas persetujuan, dan periode tertutup berlaku sama. Non-akses-penuh hanya melihat toko sendiri.
 */
class FinanceController extends Controller
{
    private const DUPLICATE_WINDOW_MINUTES = 5;

    // ------------------------------------------------------------- akses

    private function canRecord(User $user): bool
    {
        return $user->canAccessStaffArea() && $user->hasMenuAccess(\App\Filament\Resources\FinanceTransactionResource::class);
    }

    private function canApprove(User $user): bool
    {
        return $user->canAccessStaffArea() && ($user->isFullAccess() || $user->isStoreManager());
    }

    private function canCount(User $user): bool
    {
        return $user->canAccessStaffArea()
            && ($user->isFullAccess() || $user->hasMenuAccess(\App\Filament\Resources\CashCountResource::class));
    }

    private function deny()
    {
        return response()->json(['success' => false, 'message' => 'Akun ini tidak punya akses ke fitur keuangan ini.'], 403);
    }

    /** @return Collection<int, PaymentAccount> rekening aktif yang boleh dilihat: semua (akses penuh) atau milik toko sendiri */
    private function accountsFor(User $user, array $kinds): Collection
    {
        return PaymentAccount::with(['chartAccount', 'store:id,name'])
            ->where('is_active', true)
            ->whereIn('kind', $kinds)
            ->when(! $user->isFullAccess(), fn ($q) => $q->where('store_id', $user->store_id))
            ->orderBy('name')
            ->get()
            ->filter(fn (PaymentAccount $a) => $a->isUsable())
            ->values();
    }

    private function transformAccount(PaymentAccount $a): array
    {
        $petty = app(PettyCashService::class);

        return [
            'id' => $a->id,
            'name' => $a->name,
            'kind' => $a->kind,
            'store_name' => $a->store?->name,
            'balance' => $petty->balance($a),
            'float_amount' => $a->float_amount !== null ? (float) $a->float_amount : null,
            'voucher_limit' => $a->voucher_limit !== null ? (float) $a->voucher_limit : null,
            'replenish_amount' => $a->kind === 'kas_kecil' ? $petty->replenishAmount($a) : null,
        ];
    }

    // ------------------------------------------------------------- ringkasan

    /**
     * GET /api/staff/finance/overview
     */
    public function overview(Request $request)
    {
        $user = $request->user('api');

        if (! $this->canRecord($user) && ! $this->canApprove($user) && ! $this->canCount($user)) {
            return $this->deny();
        }

        $storeId = $user->isFullAccess() ? null : $user->store_id;
        $today = Carbon::today();

        $expenses = FinanceTransaction::withoutGlobalScopes()->where('type', 'out')->whereDate('transaction_date', $today)
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId));

        $overdue = Receivable::withoutGlobalScopes()->whereIn('status', ['unpaid', 'partial'])->whereDate('due_date', '<', $today)
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId));

        return response()->json([
            'success' => true,
            'can_record_expense' => $this->canRecord($user),
            'can_approve' => $this->canApprove($user),
            'can_count_cash' => $this->canCount($user),
            'accounts' => $this->accountsFor($user, ['kas', 'kas_kecil'])->map(fn (PaymentAccount $a) => $this->transformAccount($a))->values(),
            'today' => [
                'expenses_total' => (float) (clone $expenses)->sum('amount'),
                'expenses_count' => (clone $expenses)->count(),
            ],
            'receivables_overdue' => [
                'count' => (clone $overdue)->count(),
                'total' => (float) (clone $overdue)->selectRaw('COALESCE(SUM(amount - amount_paid), 0) as total')->value('total'),
            ],
            'pending_approvals' => $this->canApprove($user) ? $this->approvalsFor($user)->count() : 0,
        ]);
    }

    // ------------------------------------------------------------- pengeluaran

    /**
     * GET /api/staff/finance/expense-options
     */
    public function expenseOptions(Request $request)
    {
        $user = $request->user('api');

        if (! $this->canRecord($user)) {
            return $this->deny();
        }

        return response()->json([
            'success' => true,
            'categories' => FinanceCategory::where('type', 'out')->where('is_group', false)->where('is_active', true)->orderBy('sort_order')->orderBy('name')
                ->get(['id', 'name']),
            'accounts' => $this->accountsFor($user, ['kas', 'kas_kecil', 'bank'])->map(fn (PaymentAccount $a) => $this->transformAccount($a))->values(),
            'stores' => $user->isFullAccess() ? Store::where('is_active', true)->orderBy('name')->get(['id', 'name']) : [],
        ]);
    }

    /**
     * POST /api/staff/finance/expenses (multipart bila ada foto bukti)
     * Kas kecil sampai batas per bukti langsung tercatat; selain itu non-akses-penuh menjadi pengajuan persetujuan.
     */
    public function storeExpense(Request $request)
    {
        $user = $request->user('api');

        if (! $this->canRecord($user)) {
            return $this->deny();
        }

        $validated = $request->validate([
            'finance_category_id' => 'required|integer',
            'payment_account_id' => 'nullable|integer',
            'store_id' => 'nullable|integer',
            'amount' => 'required|numeric|min:0.01|max:99999999999.99',
            'transaction_date' => 'nullable|date|before_or_equal:today',
            'description' => 'nullable|string|max:500',
            'receipt' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:5120',
        ]);

        $category = FinanceCategory::find($validated['finance_category_id']);

        if (! $category || ! $category->is_active || $category->is_group || $category->type !== 'out') {
            return response()->json(['success' => false, 'message' => 'Pilih kategori pengeluaran yang aktif.'], 422);
        }

        $storeId = $user->isFullAccess() ? ($validated['store_id'] ?? $user->store_id) : $user->store_id;

        if (! $storeId || ! Store::where('id', $storeId)->where('is_active', true)->exists()) {
            return response()->json(['success' => false, 'message' => 'Toko tidak ditemukan atau nonaktif.'], 422);
        }

        $data = [
            'type' => 'out',
            'finance_category_id' => $category->id,
            'store_id' => (int) $storeId,
            'amount' => round((float) $validated['amount'], 2),
            'transaction_date' => ($validated['transaction_date'] ?? now()->toDateString()),
            'description' => $validated['description'] ?? null,
        ];

        $accountId = $validated['payment_account_id'] ?? null;

        if ($accountId) {
            $account = $this->accountsFor($user, ['kas', 'kas_kecil', 'bank'])->firstWhere('id', (int) $accountId);

            if (! $account) {
                return response()->json(['success' => false, 'message' => 'Rekening tidak ditemukan atau bukan milik toko Anda.'], 422);
            }

            $data['payment_account_id'] = $account->id;
        }

        if ($this->isDuplicate($data, $user)) {
            return response()->json(['success' => false, 'message' => 'Pengeluaran yang sama baru saja dikirim. Tunggu ' . self::DUPLICATE_WINDOW_MINUTES . ' menit bila memang ingin mengirim ulang.'], 422);
        }

        $petty = app(PettyCashService::class);
        $pettyAccount = $petty->find($data['payment_account_id'] ?? null);

        try {
            if ($pettyAccount) {
                $petty->assertSufficient($pettyAccount, (float) $data['amount']);
            }

            $spendsDirectly = $user->isFullAccess() || ($pettyAccount && $petty->allowsDirectSpending($data, $user));

            if ($request->hasFile('receipt')) {
                $data['receipt'] = $request->file('receipt')->store('finance-receipts', config('filament.default_filesystem_disk', 'public'));
            }

            if (! $spendsDirectly) {
                $approval = app(FinanceTransactionApprovalService::class)->submit($data, $user);

                return response()->json(['success' => true, 'status' => 'pending_approval', 'request_id' => $approval->id], 201);
            }

            $transaction = DB::transaction(function () use ($data, $user) {
                $transaction = FinanceTransaction::create($data + ['created_by' => $user->id]);
                $entry = app(FinanceTransactionPostingService::class)->post($transaction->load('category'), $user->id);
                $transaction->update(['journal_entry_id' => $entry->id]);

                return $transaction;
            });
        } catch (RuntimeException $e) {
            if (! empty($data['receipt'])) {
                Storage::disk(config('filament.default_filesystem_disk', 'public'))->delete($data['receipt']);
            }

            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'status' => 'recorded', 'transaction_number' => $transaction->transaction_number], 201);
    }

    private function isDuplicate(array $data, User $user): bool
    {
        $recent = now()->subMinutes(self::DUPLICATE_WINDOW_MINUTES);

        $transaction = FinanceTransaction::withoutGlobalScopes()->where('created_by', $user->id)->where('created_at', '>=', $recent)
            ->where('finance_category_id', $data['finance_category_id'])->where('store_id', $data['store_id'])->where('amount', $data['amount'])
            ->whereDate('transaction_date', $data['transaction_date'])->exists();

        $request = FinanceTransactionApprovalRequest::where('requested_by', $user->id)->where('created_at', '>=', $recent)->whereIn('status', ['pending_manager', 'pending_direksi'])
            ->get()->contains(fn ($r) => (int) ($r->payload['finance_category_id'] ?? 0) === (int) $data['finance_category_id']
                && (float) ($r->payload['amount'] ?? 0) === (float) $data['amount']
                && (int) ($r->payload['store_id'] ?? 0) === (int) $data['store_id']);

        return $transaction || $request;
    }

    /**
     * GET /api/staff/finance/my-expenses
     */
    public function myExpenses(Request $request)
    {
        $user = $request->user('api');

        if (! $this->canRecord($user)) {
            return $this->deny();
        }

        $recorded = FinanceTransaction::withoutGlobalScopes()->with('category:id,name')->where('created_by', $user->id)->where('type', 'out')
            ->latest('id')->limit(30)->get()
            ->map(fn (FinanceTransaction $t) => [
                'key' => "t{$t->id}", 'amount' => (float) $t->amount, 'category' => $t->category?->name, 'date' => $t->transaction_date->toDateString(),
                'description' => $t->description, 'status' => 'recorded', 'note' => $t->transaction_number,
            ]);

        $requests = FinanceTransactionApprovalRequest::where('requested_by', $user->id)->where('status', '!=', 'approved')->latest('id')->limit(30)->get()
            ->map(fn (FinanceTransactionApprovalRequest $r) => [
                'key' => "r{$r->id}", 'amount' => (float) ($r->payload['amount'] ?? 0),
                'category' => FinanceCategory::whereKey($r->payload['finance_category_id'] ?? null)->value('name'),
                'date' => $r->payload['transaction_date'] ?? $r->created_at->toDateString(), 'description' => $r->payload['description'] ?? null,
                'status' => $r->status, 'note' => $r->rejection_note,
            ]);

        return response()->json(['success' => true, 'expenses' => $recorded->concat($requests)->sortByDesc('date')->values()]);
    }

    // ------------------------------------------------------------- persetujuan

    /** @return Collection<int, FinanceTransactionApprovalRequest> */
    private function approvalsFor(User $user): Collection
    {
        $pending = FinanceTransactionApprovalRequest::with('requester:id,name')->whereIn('status', ['pending_manager', 'pending_direksi'])
            ->where('requested_by', '!=', $user->id)->latest('id')->get();

        if ($user->isFullAccess()) {
            return $pending;
        }

        return $pending->filter(fn (FinanceTransactionApprovalRequest $r) => $r->status === 'pending_manager'
            && (int) $r->store_id_from_payload === (int) $user->store_id)->values();
    }

    /**
     * GET /api/staff/finance/approvals
     */
    public function approvals(Request $request)
    {
        $user = $request->user('api');

        if (! $this->canApprove($user)) {
            return $this->deny();
        }

        return response()->json([
            'success' => true,
            'approvals' => $this->approvalsFor($user)->map(function (FinanceTransactionApprovalRequest $r) {
                $receipt = $r->payload['receipt'] ?? null;

                return [
                    'id' => $r->id,
                    'stage' => $r->status === 'pending_manager' ? 'manager' : 'direksi',
                    'requester' => $r->requester?->name,
                    'amount' => (float) ($r->payload['amount'] ?? 0),
                    'category' => FinanceCategory::whereKey($r->payload['finance_category_id'] ?? null)->value('name'),
                    'store' => Store::whereKey($r->store_id_from_payload)->value('name'),
                    'date' => $r->payload['transaction_date'] ?? null,
                    'description' => $r->payload['description'] ?? null,
                    'receipt_url' => $receipt ? Storage::disk(config('filament.default_filesystem_disk', 'public'))->url($receipt) : null,
                    'created_at' => $r->created_at->toIso8601String(),
                ];
            })->values(),
        ]);
    }

    /**
     * POST /api/staff/finance/approvals/{id}/approve
     */
    public function approve(Request $request, int $id)
    {
        $user = $request->user('api');

        if (! $this->canApprove($user)) {
            return $this->deny();
        }

        $approval = FinanceTransactionApprovalRequest::find($id);

        if (! $approval) {
            return response()->json(['success' => false, 'message' => 'Pengajuan tidak ditemukan.'], 404);
        }

        try {
            $service = app(FinanceTransactionApprovalService::class);

            $approval->isPendingManager() ? $service->approveByManager($approval, $user) : $service->approveByDireksi($approval, $user);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'status' => $approval->fresh()->status]);
    }

    /**
     * POST /api/staff/finance/approvals/{id}/reject
     */
    public function reject(Request $request, int $id)
    {
        $user = $request->user('api');

        if (! $this->canApprove($user)) {
            return $this->deny();
        }

        $note = $request->validate(['note' => 'nullable|string|max:500'])['note'] ?? null;
        $approval = FinanceTransactionApprovalRequest::find($id);

        if (! $approval) {
            return response()->json(['success' => false, 'message' => 'Pengajuan tidak ditemukan.'], 404);
        }

        try {
            app(FinanceTransactionApprovalService::class)->reject($approval, $user, $note);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------- hitung kas

    /**
     * GET /api/staff/finance/cash-counts
     */
    public function cashCounts(Request $request)
    {
        $user = $request->user('api');

        if (! $this->canCount($user)) {
            return $this->deny();
        }

        $accountIds = $this->accountsFor($user, ['kas', 'kas_kecil'])->pluck('id');

        $counts = CashCount::withoutGlobalScopes()->with('paymentAccount:id,name')->whereIn('payment_account_id', $accountIds)->latest('count_date')->latest('id')->limit(20)->get()
            ->map(fn (CashCount $c) => [
                'id' => $c->id, 'account' => $c->paymentAccount?->name, 'date' => $c->count_date->toDateString(),
                'counted_amount' => (float) $c->counted_amount, 'system_balance' => (float) $c->system_balance, 'difference' => (float) $c->difference,
                'notes' => $c->notes, 'adjusted' => $c->isAdjusted(),
            ]);

        $todayDone = CashCount::withoutGlobalScopes()->whereIn('payment_account_id', $accountIds)->whereDate('count_date', today())->pluck('payment_account_id')->all();

        return response()->json([
            'success' => true,
            'accounts' => $this->accountsFor($user, ['kas', 'kas_kecil'])->map(fn (PaymentAccount $a) => $this->transformAccount($a) + ['counted_today' => in_array($a->id, $todayDone, true)])->values(),
            'counts' => $counts,
        ]);
    }

    /**
     * POST /api/staff/finance/cash-counts
     */
    public function storeCashCount(Request $request)
    {
        $user = $request->user('api');

        if (! $this->canCount($user)) {
            return $this->deny();
        }

        $validated = $request->validate([
            'payment_account_id' => 'required|integer',
            'counted_amount' => 'required|numeric|min:0|max:99999999999.99',
            'notes' => 'nullable|string|max:500',
        ]);

        $account = $this->accountsFor($user, ['kas', 'kas_kecil'])->firstWhere('id', (int) $validated['payment_account_id']);

        if (! $account) {
            return response()->json(['success' => false, 'message' => 'Kas tidak ditemukan atau bukan milik toko Anda.'], 422);
        }

        try {
            $count = app(CashCountService::class)->record($account, Carbon::today(), (float) $validated['counted_amount'], $validated['notes'] ?? null, $user->id);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'count' => ['id' => $count->id, 'system_balance' => (float) $count->system_balance, 'difference' => (float) $count->difference],
        ], 201);
    }
}
