<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quality Check dengan daftar periksa per layanan (Fase 3A pengerjaan ulang booking, 2026-10-17).
 *
 *  - qc_checklist_items: butir periksa per layanan (kunci layanan = kaca_film/ppf/detailing/premium_wash), diatur dari Filament.
 *  - booking_qc_results: satu baris per pemeriksaan (bisa diulang bila gagal); pemeriksa wajib BUKAN pengerja unit itu.
 *  - booking_qc_result_items: salinan butir saat diperiksa (label disalin supaya riwayat tidak berubah bila daftar diedit).
 *  - bookings.ready_at: kendaraan siap diambil (diisi saat QC lulus). Sengaja bukan status baru: status booking tetap
 *    pending/confirmed/completed/cancelled supaya kapasitas, jurnal, dan aplikasi lama tidak terpengaruh.
 */
return new class extends Migration
{
    private const DEFAULT_ITEMS = [
        'ppf' => [
            'Tidak ada gelembung atau debu di bawah film',
            'Tepi dan sudut film rapi dan menempel kuat',
            'Tidak ada kerutan, bagian terangkat, atau potongan salah',
            'Bodi, lampu, dan kaca bersih dari sisa larutan pasang',
            'Tidak ada baret atau kerusakan baru pada cat',
        ],
        'kaca_film' => [
            'Tidak ada gelembung, debu, atau goresan pada film',
            'Tepi film rapi dan merata di semua kaca',
            'Kaca bersih tanpa sisa larutan pasang',
            'Semua kaca naik-turun normal',
            'Defogger dan sensor tidak terganggu',
        ],
        'detailing' => [
            'Interior bersih dan kering',
            'Eksterior bersih tanpa sisa pasta atau compound',
            'Kaca bersih tanpa noda',
            'Velg dan ban bersih',
            'Tidak ada kerusakan baru pada kendaraan',
        ],
        'premium_wash' => [
            'Bodi bersih tanpa sisa sabun',
            'Kaca bersih tanpa noda',
            'Velg dan ban bersih',
            'Interior disedot dan dilap',
            'Tidak ada kerusakan baru pada kendaraan',
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('qc_checklist_items')) {
            Schema::create('qc_checklist_items', function (Blueprint $table) {
                $table->id();
                $table->string('service_key', 30)->index();
                $table->string('label', 160);
                $table->boolean('is_required')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });

            $now = now();
            $rows = [];
            foreach (self::DEFAULT_ITEMS as $service => $labels) {
                foreach ($labels as $i => $label) {
                    $rows[] = ['service_key' => $service, 'label' => $label, 'is_required' => true, 'sort_order' => ($i + 1) * 10, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now];
                }
            }
            DB::table('qc_checklist_items')->insert($rows);
        }

        if (! Schema::hasTable('booking_qc_results')) {
            Schema::create('booking_qc_results', function (Blueprint $table) {
                $table->id();
                $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
                $table->foreignId('inspector_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->boolean('passed');
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['booking_id', 'id']);
            });
        }

        if (! Schema::hasTable('booking_qc_result_items')) {
            Schema::create('booking_qc_result_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('booking_qc_result_id')->constrained()->cascadeOnDelete();
                $table->string('service_key', 30);
                $table->string('label', 160);
                $table->boolean('is_required')->default(true);
                $table->boolean('passed');
                $table->string('note', 255)->nullable();
            });
        }

        if (! Schema::hasColumn('bookings', 'ready_at')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->timestamp('ready_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('bookings', 'ready_at')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->dropColumn('ready_at');
            });
        }

        Schema::dropIfExists('booking_qc_result_items');
        Schema::dropIfExists('booking_qc_results');
        Schema::dropIfExists('qc_checklist_items');
    }
};
