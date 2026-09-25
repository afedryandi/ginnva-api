<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

/**
 * Portal invoice untuk customer (gap "standar enterprise" diperbaiki
 * 2026-09-25, audit Invoice) — SEBELUMNYA modul Invoice sama sekali
 * tidak terhubung ke customer app, staff harus kirim PDF manual lewat
 * WhatsApp/email. Model Invoice sudah punya customer_id/customer_email
 * sejak awal, cuma belum pernah diekspos lewat endpoint apa pun.
 *
 * 'draft' SENGAJA tidak pernah ditampilkan ke customer di sini (belum
 * final, masih bisa berubah bebas di Filament) -- cuma unpaid/paid/void
 * yang genuinely sudah "dikirim".
 */
class InvoiceController extends Controller
{
    /**
     * GET /api/customer/invoices
     */
    public function index(Request $request)
    {
        $invoices = Invoice::where('customer_id', $request->user('customer')->id)
            ->where('status', '!=', 'draft')
            ->with('store:id,name')
            ->orderByDesc('issue_date')
            ->get(['id', 'invoice_number', 'store_id', 'issue_date', 'due_date', 'status', 'total', 'amount_paid']);

        return response()->json(['success' => true, 'data' => $invoices]);
    }

    /**
     * GET /api/customer/invoices/{id}
     */
    public function show(Request $request, int $id)
    {
        $invoice = Invoice::where('customer_id', $request->user('customer')->id)
            ->where('status', '!=', 'draft')
            ->with(['items', 'store:id,name'])
            ->find($id);

        if (! $invoice) {
            abort(404, 'Invoice tidak ditemukan.');
        }

        return response()->json(['success' => true, 'data' => $invoice]);
    }

    /**
     * GET /api/customer/invoices/{id}/download
     * Sama persis template PDF dengan yang dipakai staff di Filament
     * (resources/views/pdf/invoice.blade.php, lihat InvoiceResource
     * action 'print') -- satu sumber tampilan dokumen, tidak ada versi
     * kedua yang bisa menyimpang.
     */
    public function download(Request $request, int $id)
    {
        $invoice = Invoice::where('customer_id', $request->user('customer')->id)
            ->where('status', '!=', 'draft')
            ->with(['items', 'store'])
            ->find($id);

        if (! $invoice) {
            abort(404, 'Invoice tidak ditemukan.');
        }

        $pdf = Pdf::loadView('pdf.invoice', ['invoice' => $invoice])->setPaper('a4', 'portrait');
        $filename = str_replace('/', '-', $invoice->invoice_number) . '.pdf';

        return response()->streamDownload(fn () => print($pdf->output()), $filename);
    }
}
