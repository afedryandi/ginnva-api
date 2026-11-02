<?php

namespace App\Http\Controllers\Api\Staff\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Opname massal dari mobile: staf gudang menghitung banyak bahan dalam satu sesi lalu mengirim semuanya sekaligus. Aturannya sama
 * dengan opname satu per satu (adjustStock + ambang catatan per bahan); semua baris divalidasi lebih dulu dan disimpan dalam satu
 * transaksi, jadi sesi tidak setengah tersimpan.
 *
 * Pemakai wajib menyediakan denyUnlessWrite(Request) dan stockModelClass() / stockLabel().
 */
trait AdjustsStockInBulk
{
    /** @return class-string<\Illuminate\Database\Eloquent\Model> */
    abstract protected function stockModelClass(): string;

    abstract protected function stockLabel(): string;

    public function bulkAdjust(Request $request)
    {
        if ($denied = $this->denyUnlessWrite($request)) {
            return $denied;
        }

        $validator = Validator::make($request->all(), [
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.id' => ['required', 'integer', 'distinct'],
            'items.*.actual_quantity' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'items.*.note' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Data yang dikirim tidak valid.', 'errors' => $validator->errors()], 422);
        }

        $model = $this->stockModelClass();
        $rows = collect($request->input('items'));
        $items = $model::query()->whereIn('id', $rows->pluck('id'))->get()->keyBy('id');

        // Validasi semua baris dulu, dengan pesan per baris, sebelum ada yang disimpan.
        $problems = [];
        foreach ($rows as $index => $row) {
            $item = $items->get((int) $row['id']);

            if (! $item) {
                $problems[] = ['index' => $index, 'id' => (int) $row['id'], 'message' => $this->stockLabel() . ' tidak ditemukan.'];

                continue;
            }

            if ($item->adjustmentNeedsNote((float) $row['actual_quantity']) && trim((string) ($row['note'] ?? '')) === '') {
                $limit = rtrim(rtrim(number_format((float) $item->adjustment_note_threshold, 2, '.', ''), '0'), '.');
                $problems[] = ['index' => $index, 'id' => $item->id, 'message' => "{$item->name}: selisih melebihi {$limit} {$item->unit}, isi catatan alasan."];
            }
        }

        if ($problems !== []) {
            return response()->json([
                'success' => false,
                'message' => count($problems) === 1 ? $problems[0]['message'] : count($problems) . ' baris perlu diperbaiki sebelum disimpan.',
                'problems' => $problems,
                // Format yang dibaca ApiError di mobile: kunci row_{id} per baris yang bermasalah.
                'errors' => collect($problems)->mapWithKeys(fn (array $p) => ['row_' . $p['id'] => [$p['message']]])->all(),
            ], 422);
        }

        $user = $request->user('api');

        $results = DB::transaction(function () use ($rows, $items, $user) {
            return $rows->map(function (array $row) use ($items, $user) {
                $item = $items->get((int) $row['id']);
                $before = (float) $item->current_stock;
                $movement = $item->adjustStock((float) $row['actual_quantity'], $user->id, $row['note'] ?? null, $user->store_id);

                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'unit' => $item->unit,
                    'before' => $before,
                    'after' => (float) $item->fresh()->current_stock,
                    'delta' => $movement ? (float) $movement->quantity : 0.0,
                    'adjusted' => $movement !== null,
                ];
            })->values();
        });

        $changed = $results->where('adjusted', true)->count();

        return response()->json([
            'success' => true,
            'message' => $changed === 0
                ? 'Semua hasil hitung sama dengan stok di sistem — tidak ada penyesuaian yang dicatat.'
                : "{$changed} dari {$results->count()} {$this->stockLabel()} disesuaikan.",
            'data' => $results,
        ]);
    }
}