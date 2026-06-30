<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * QA Certificate review (sesuai mind map "Quality Assurance Management
     * System" > "Details of Quality Assurance Certificate" > review).
     *
     * Warranty yang masuk dari POST /api/warranty/submit sekarang TIDAK
     * langsung aktif — wajib di-review dulu oleh super_admin:
     * pending_review -> approved / rejected.
     *
     * Kolom `status` lama (active/expired/pending) tetap dipertahankan
     * apa adanya (tidak dihapus), supaya data lama yang sudah ada tidak
     * rusak. Accessor di model Warranty akan disesuaikan agar warranty
     * yang review_status-nya belum approved tidak ikut terhitung aktif.
     */
    public function up(): void
    {
        Schema::table('warranties', function (Blueprint $table) {
            $table->enum('review_status', ['pending_review', 'approved', 'rejected'])
                ->default('pending_review')
                ->after('status');

            $table->text('rejection_reason')
                ->nullable()
                ->after('review_status');

            $table->foreignId('reviewed_by')
                ->nullable()
                ->after('rejection_reason')
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('reviewed_at')
                ->nullable()
                ->after('reviewed_by');

            $table->index('review_status');
        });
    }

    public function down(): void
    {
        Schema::table('warranties', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['review_status', 'rejection_reason', 'reviewed_at']);
        });
    }
};