<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Tambah Widget" — pilih akun COA jadi kartu KPI custom (audit Majoo,
 * f46), dibangun 2026-09-22 atas keputusan user (versi sederhana).
 * 1 baris = 1 akun COA yang DIPIN 1 user ke Laporan Keuangan --
 * per-user (bukan global), supaya tiap staff full-access bisa pilih
 * akun yang relevan buat dia sendiri.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_dashboard_widgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chart_of_account_id')->constrained()->cascadeOnDelete();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'chart_of_account_id'], 'fdw_user_account_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_dashboard_widgets');
    }
};
