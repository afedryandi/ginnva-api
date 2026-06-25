<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Vehicle;
use App\Models\FilmProduct;
use App\Models\PriceRule;
use App\Models\Quotation;
use App\Models\QuotationItem;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class QuotationController extends Controller
{
    /**
     * POST /api/quotation/calculate
     * Menghitung rincian harga per bagian dan total penawaran.
     */
    public function calculate(Request $request)
    {
        $request->validate([
            'vehicle_id' => 'required|exists:vehicles,id',
            'customer_name' => 'required|string|max:255',
            'customer_phone' => 'nullable|string',
            'license_plate' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.film_product_id' => 'required|exists:film_products,id',
            'items.*.car_part' => 'required|in:front,back,side,full_set',
        ]);

        $vehicle = Vehicle::findOrFail($request->vehicle_id);
        $calculatedItems = [];
        $totalPrice = 0;

        foreach ($request->items as $item) {
            $product = FilmProduct::findOrFail($item['film_product_id']);

            $rule = PriceRule::where('vehicle_size', $vehicle->size_category)
                             ->where('car_part', $item['car_part'])
                             ->first();

            $coefficient = $rule ? $rule->coefficient : 1.00;
            $calculatedPrice = $product->base_price * $coefficient;

            $calculatedItems[] = [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'car_part' => $item['car_part'],
                'base_price' => (float) $product->base_price,
                'coefficient' => (float) $coefficient,
                'calculated_price' => (float) $calculatedPrice,
            ];

            $totalPrice += $calculatedPrice;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'customer_name' => $request->customer_name,
                'vehicle' => $vehicle->brand . ' ' . $vehicle->model . ' (' . $vehicle->size_category . ')',
                'items' => $calculatedItems,
                'total_price' => $totalPrice
            ]
        ]);
    }

    /**
     * POST /api/quotation/generate-pdf
     * Menghitung, menyimpan data ke database, dan menghasilkan berkas DomPDF dengan QR Code.
     */
    public function generatePdf(Request $request)
    {
        $request->validate([
            'vehicle_id' => 'required|exists:vehicles,id',
            'customer_name' => 'required|string|max:255',
            'customer_phone' => 'nullable|string',
            'license_plate' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.film_product_id' => 'required|exists:film_products,id',
            'items.*.car_part' => 'required|in:front,back,side,full_set',
        ]);

        $vehicle = Vehicle::findOrFail($request->vehicle_id);

        return DB::transaction(function () use ($request, $vehicle) {
            $quotationNumber = 'QTN-' . date('Ym') . '-' . strtoupper(Str::random(4));

            $quotation = Quotation::create([
                'quotation_number' => $quotationNumber,
                'vehicle_id' => $vehicle->id,
                'customer_name' => $request->customer_name,
                'customer_phone' => $request->customer_phone,
                'license_plate' => $request->license_plate,
                'total_price' => 0,
                'status' => 'draft',
            ]);

            $totalPrice = 0;
            $itemsDataForPdf = [];

            foreach ($request->items as $item) {
                $product = FilmProduct::findOrFail($item['film_product_id']);
                $rule = PriceRule::where('vehicle_size', $vehicle->size_category)
                                 ->where('car_part', $item['car_part'])
                                 ->first();

                $coefficient = $rule ? $rule->coefficient : 1.00;
                $calculatedPrice = $product->base_price * $coefficient;

                QuotationItem::create([
                    'quotation_id' => $quotation->id,
                    'film_product_id' => $product->id,
                    'price_rule_id' => $rule ? $rule->id : null,
                    'base_price_snapshot' => $product->base_price,
                    'coefficient_snapshot' => $coefficient,
                    'calculated_price' => $calculatedPrice,
                ]);

                $totalPrice += $calculatedPrice;

                $itemsDataForPdf[] = [
                    'product_name' => $product->name,
                    'car_part' => strtoupper($item['car_part']),
                    'base_price' => $product->base_price,
                    'coefficient' => $coefficient,
                    'calculated_price' => $calculatedPrice
                ];
            }

            $quotation->update(['total_price' => $totalPrice]);

            $qrUrl = "https://ginnva.id/warranty/verify?qtn=" . $quotationNumber;
            $qrCode = "https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=" . urlencode($qrUrl);

            $pdfData = [
                'logo_url' => 'https://www.ginnvafilm.com/static/home/images/logoO.png',
                'quotation_number' => $quotationNumber,
                'date' => date('d F Y'),
                'customer_name' => $quotation->customer_name,
                'customer_phone' => $quotation->customer_phone ?? '-',
                'license_plate' => $quotation->license_plate ? strtoupper($quotation->license_plate) : '-',
                'vehicle_model' => $vehicle->brand . ' ' . $vehicle->model . ' (' . $vehicle->size_category . ')',
                'items' => $itemsDataForPdf,
                'total_price' => $totalPrice,
                'qr_code' => $qrCode
            ];

            $pdf = Pdf::loadView('pdf.quotation', $pdfData);
            return $pdf->download($quotationNumber . '.pdf');
        });
    }
}