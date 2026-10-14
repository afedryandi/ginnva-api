<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\CustomerVehicle;
use App\Models\Vehicle;
use App\Services\VehicleCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Kendaraan Saya" (Fase 1 pengerjaan ulang booking, 2026-10-15): daftar kendaraan milik pelanggan yang login.
 * Merek/model dipilih dari katalog (vehicle_id → ukuran otomatis) atau diketik bebas (ukuran boleh kosong).
 */
class VehicleController extends Controller
{
    private const MAX_PER_CUSTOMER = 10;

    public function index(Request $request): JsonResponse
    {
        $vehicles = $request->user('customer')->vehicles()->orderByDesc('is_default')->orderByDesc('id')->get();

        return response()->json(['success' => true, 'data' => $vehicles->map(fn (CustomerVehicle $v) => $this->format($v))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $customer = $request->user('customer');
        $data = $this->validated($request);

        if ($customer->vehicles()->count() >= self::MAX_PER_CUSTOMER) {
            throw ValidationException::withMessages(['plate' => ['Maksimal ' . self::MAX_PER_CUSTOMER . ' kendaraan. Hapus yang tidak dipakai dulu.']]);
        }

        $this->assertPlateIsFree($customer->id, $data['plate_normalized']);

        $vehicle = DB::transaction(function () use ($customer, $data, $request) {
            $makeDefault = $request->boolean('is_default') || ! $customer->vehicles()->exists();

            if ($makeDefault) {
                $customer->vehicles()->update(['is_default' => false]);
            }

            return $customer->vehicles()->create($data + ['is_default' => $makeDefault]);
        });

        return response()->json(['success' => true, 'message' => 'Kendaraan disimpan.', 'data' => $this->format($vehicle)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $customer = $request->user('customer');
        $vehicle = $customer->vehicles()->where('id', $id)->firstOrFail();
        $data = $this->validated($request);

        $this->assertPlateIsFree($customer->id, $data['plate_normalized'], $vehicle->id);

        DB::transaction(function () use ($customer, $vehicle, $data, $request) {
            if ($request->boolean('is_default') && ! $vehicle->is_default) {
                $customer->vehicles()->update(['is_default' => false]);
                $data['is_default'] = true;
            }

            $vehicle->update($data);
        });

        return response()->json(['success' => true, 'message' => 'Kendaraan diperbarui.', 'data' => $this->format($vehicle->fresh())]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $customer = $request->user('customer');
        $vehicle = $customer->vehicles()->where('id', $id)->firstOrFail();

        DB::transaction(function () use ($customer, $vehicle) {
            $wasDefault = $vehicle->is_default;
            $vehicle->delete(); // booking lama tetap menyimpan salinan data kendaraannya (customer_vehicle_id → null)

            if ($wasDefault) {
                $customer->vehicles()->orderByDesc('id')->first()?->update(['is_default' => true]);
            }
        });

        return response()->json(['success' => true, 'message' => 'Kendaraan dihapus.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $request->validate([
            'vehicle_id' => 'nullable|integer|exists:vehicles,id',
            'brand' => 'required_without:vehicle_id|nullable|string|max:80',
            'model' => 'required_without:vehicle_id|nullable|string|max:120',
            'variant' => 'nullable|string|max:120',
            'plate' => 'required|string|max:20',
            'year' => 'nullable|integer|between:1980,' . (now()->year + 1),
            'color' => 'nullable|string|max:40',
            'size_category' => 'nullable|in:' . implode(',', CustomerVehicle::SIZES),
            'vin' => 'nullable|string|max:25',
            'is_default' => 'sometimes|boolean',
        ], [
            'brand.required_without' => 'Pilih merek dan model kendaraan, atau isi manual.',
            'model.required_without' => 'Pilih merek dan model kendaraan, atau isi manual.',
            'plate.required' => 'Plat nomor wajib diisi.',
        ]);

        $normalized = CustomerVehicle::normalizePlate((string) $request->input('plate'));

        if (! CustomerVehicle::isValidPlate($normalized)) {
            throw ValidationException::withMessages(['plate' => ['Format plat nomor tidak valid. Contoh: B 1234 ABC.']]);
        }

        $catalog = $request->filled('vehicle_id') ? Vehicle::find($request->input('vehicle_id')) : null;

        return [
            'vehicle_id' => $catalog?->id,
            'brand' => $catalog ? app(VehicleCatalogService::class)->canonicalBrand($catalog->brand) : trim((string) $request->input('brand')),
            'model' => $catalog ? (string) $catalog->model : trim((string) $request->input('model')),
            'variant' => $catalog ? $catalog->variant : ($request->filled('variant') ? trim((string) $request->input('variant')) : null),
            'plate' => CustomerVehicle::formatPlate($normalized),
            'plate_normalized' => $normalized,
            'year' => $request->filled('year') ? (int) $request->input('year') : null,
            'color' => $request->filled('color') ? trim((string) $request->input('color')) : null,
            // Ukuran dari katalog kalau ada; kalau diketik manual boleh diisi pelanggan atau kosong (toko yang menentukan).
            'size_category' => $catalog?->size_category ?? ($request->filled('size_category') ? $request->input('size_category') : null),
            'vin' => $request->filled('vin') ? strtoupper(trim((string) $request->input('vin'))) : null,
        ];
    }

    private function assertPlateIsFree(int $customerId, string $normalized, ?int $exceptId = null): void
    {
        $taken = CustomerVehicle::where('customer_id', $customerId)
            ->where('plate_normalized', $normalized)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['plate' => ['Plat nomor ini sudah ada di Kendaraan Saya.']]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function format(CustomerVehicle $vehicle): array
    {
        return [
            'id' => $vehicle->id,
            'vehicle_id' => $vehicle->vehicle_id,
            'brand' => $vehicle->brand,
            'model' => $vehicle->model,
            'variant' => $vehicle->variant,
            'label' => $vehicle->label(),
            'plate' => $vehicle->plate,
            'year' => $vehicle->year,
            'color' => $vehicle->color,
            'size_category' => $vehicle->size_category,
            'vin' => $vehicle->vin,
            'is_default' => $vehicle->is_default,
        ];
    }
}
