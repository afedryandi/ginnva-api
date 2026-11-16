{{--
    Gaya halaman Login panel admin. Hanya mengubah tampilan; isian, validasi, dan alur masuk tidak berubah.
    Disisipkan global lewat render hook HEAD_END di AdminPanelProvider, tetapi semua aturannya berpijak pada kelas tata letak sederhana Filament
    (.fi-simple-layout dan turunannya) yang hanya dipakai halaman masuk/daftar, jadi halaman panel lain tidak terpengaruh. Memakai variabel warna
    Filament (--gray-*, --primary-*) agar ikut warna primer perusahaan dan mode gelap (.dark).

    Ringkasnya: kanvas abu-abu sangat muda dengan sorotan primer samar di atas; kartu putih bersudut 1rem bergaris tipis tanpa bayangan tebal;
    logo di tengah dan judul kecil; isian 44px dengan cincin fokus primer lembut; tombol masuk selebar kartu; catatan kaki redup.
--}}
<style>
    .fi-simple-layout {
        --lg-canvas: rgb(var(--gray-50));
        --lg-surface: #fff;
        --lg-line: rgba(var(--gray-200), .7);
        --lg-title: rgb(var(--gray-900));
        --lg-muted: rgb(var(--gray-500));
        --lg-glow: rgba(var(--primary-500), .07);
        background:
            radial-gradient(60rem 24rem at 50% -8rem, var(--lg-glow), transparent 70%),
            var(--lg-canvas);
        font-variant-numeric: tabular-nums;
    }
    .dark .fi-simple-layout {
        --lg-canvas: rgb(var(--gray-950));
        --lg-surface: rgb(var(--gray-900));
        --lg-line: rgba(255, 255, 255, .08);
        --lg-title: rgb(var(--gray-50));
        --lg-muted: rgb(var(--gray-400));
        --lg-glow: rgba(var(--primary-500), .12);
    }

    /* Kartu masuk: lebar nyaman, tanpa bayangan tebal. */
    .fi-simple-layout .fi-simple-main {
        width: 100%;
        max-width: 26rem;
        padding: 2rem 1.75rem;
        background: var(--lg-surface);
        border: 1px solid var(--lg-line);
        border-radius: 1rem;
        box-shadow: 0 1px 2px rgba(15, 23, 42, .04);
        --tw-ring-shadow: 0 0 #0000;
        --tw-ring-color: transparent;
        --tw-shadow: 0 0 #0000;
    }
    @media (max-width: 480px) {
        .fi-simple-layout .fi-simple-main { padding: 1.5rem 1.25rem; border-radius: .875rem; }
    }

    /* Kepala: logo, judul, dan keterangan rapat dan di tengah. */
    .fi-simple-layout .fi-simple-header { margin-bottom: 1.5rem; row-gap: .375rem; }
    .fi-simple-layout .fi-simple-header .fi-logo { height: 2.5rem; margin-bottom: .75rem; }
    .fi-simple-layout .fi-simple-header-heading {
        font-size: 1.25rem;
        line-height: 1.75rem;
        font-weight: 650;
        letter-spacing: -.01em;
        color: var(--lg-title);
    }
    .fi-simple-layout .fi-simple-header-subheading { font-size: .8125rem; line-height: 1.25rem; color: var(--lg-muted); }

    /* Isian: label tegas tapi kecil, kotak 44px, cincin fokus primer lembut. */
    .fi-simple-layout .fi-fo-field-wrp-label span { font-size: .8125rem; font-weight: 600; color: rgb(var(--gray-700)); }
    .dark .fi-simple-layout .fi-fo-field-wrp-label span { color: rgb(var(--gray-200)); }
    .fi-simple-layout .fi-input-wrp {
        border-radius: .625rem;
        box-shadow: none;
        border: 1px solid rgba(var(--gray-300), .8);
        background: var(--lg-surface);
        transition: border-color .15s ease, box-shadow .15s ease;
    }
    .dark .fi-simple-layout .fi-input-wrp { border-color: rgba(255, 255, 255, .12); background: rgba(255, 255, 255, .03); }
    .fi-simple-layout .fi-input-wrp:hover { border-color: rgb(var(--gray-400)); }
    .fi-simple-layout .fi-input-wrp:focus-within {
        border-color: rgb(var(--primary-500));
        box-shadow: 0 0 0 3px rgba(var(--primary-500), .16);
    }
    .fi-simple-layout .fi-input { min-height: 2.75rem; font-size: .9375rem; }
    .fi-simple-layout .fi-fo-checkbox-list-option-label,
    .fi-simple-layout .fi-fo-checkbox .fi-fo-field-wrp-label span { font-weight: 500; color: var(--lg-muted); }

    /* Tombol masuk selebar kartu, sudut sama dengan isian. */
    .fi-simple-layout .fi-form-actions { margin-top: .5rem; }
    .fi-simple-layout .fi-form-actions .fi-btn {
        width: 100%;
        min-height: 2.75rem;
        border-radius: .625rem;
        font-size: .9375rem;
        font-weight: 600;
        box-shadow: none;
    }

    /* Galat validasi dan tautan bantu. */
    .fi-simple-layout .fi-fo-field-wrp-error-message { font-size: .8125rem; }
    .fi-simple-layout .fi-link { font-size: .8125rem; }

    /* Catatan kaki redup di bawah formulir. */
    .fi-simple-layout .lg-foot { margin-top: 1.25rem; padding-top: 1rem; border-top: 1px solid var(--lg-line); font-size: .75rem; color: var(--lg-muted); text-align: center; }
</style>
