<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Penanganan klaim after-sales (audit ACCURATE 5, form RMA dan Aktifitas Proses Klaim): siapa yang menangani,
     * perkiraan selesai, dan hasil akhirnya. Semua nullable: klaim lama tetap valid dan tidak diubah.
     */
    public function up(): void
    {
        Schema::table('warranty_claims', function (Blueprint $table) {
            $table->foreignId('handled_by')->nullable()->after('reviewed_at')->constrained('users')->nullOnDelete();
            $table->date('estimated_completion_date')->nullable()->after('handled_by');
            $table->string('resolution', 20)->nullable()->after('estimated_completion_date');
            $table->text('resolution_notes')->nullable()->after('resolution');
            $table->timestamp('resolved_at')->nullable()->after('resolution_notes');

            $table->index('resolution');
        });
    }

    public function down(): void
    {
        Schema::table('warranty_claims', function (Blueprint $table) {
            $table->dropIndex(['resolution']);
            $table->dropConstrainedForeignId('handled_by');
            $table->dropColumn(['estimated_completion_date', 'resolution', 'resolution_notes', 'resolved_at']);
        });
    }
};
