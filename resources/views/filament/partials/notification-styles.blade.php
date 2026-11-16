{{--
    Gaya panel Notifikasi (slide-over lonceng di navbar). Hanya mengubah tampilan, bukan isi, urutan, atau perilaku notifikasi. Disisipkan
    global lewat render hook HEAD_END di AdminPanelProvider. Memakai kelas Filament (fi-modal-*, fi-no-notification-*, fi-link, fi-btn) dan
    variabel warna Filament (--gray-*, --primary-*) agar ikut warna primer perusahaan dan mode gelap (.dark).

    Panel notifikasi dikenali lewat :has(.fi-no-notification), supaya modal lain tidak ikut berubah.

    Ringkasnya: panel bersudut 1rem di tepi kiri tanpa bayangan tebal; kepala lapang dengan dua tautan aksi berupa pil kecil; tiap notifikasi
    berjarak lega, judul tegas, tanggal dan isi redup, tombol "Buka" kecil berlatar primer pucat; notifikasi belum dibaca berlatar tipis dengan
    garis primer di kiri.
--}}
<style>
    .fi-modal-window:has(.fi-no-notification) {
        --nt-line: rgba(var(--gray-200), .7);
        --nt-ink: rgb(var(--gray-950));
        --nt-muted: rgb(var(--gray-500));
        --nt-body: rgb(var(--gray-600));
        --nt-unread: rgba(var(--primary-500), .045);
        --nt-soft: rgba(var(--primary-500), .08);
        --nt-soft-hover: rgba(var(--primary-500), .14);
        --nt-soft-text: rgb(var(--primary-700));
        border-radius: 1rem 0 0 1rem;
        box-shadow: -16px 0 40px -16px rgba(var(--gray-950), .22);
        --tw-ring-shadow: 0 0 #0000; --tw-ring-color: transparent;
    }
    .dark .fi-modal-window:has(.fi-no-notification) {
        --nt-line: rgba(255, 255, 255, .08);
        --nt-ink: #fff;
        --nt-muted: rgb(var(--gray-400));
        --nt-body: rgb(var(--gray-300));
        --nt-unread: rgba(var(--primary-400), .07);
        --nt-soft: rgba(var(--primary-400), .14);
        --nt-soft-hover: rgba(var(--primary-400), .22);
        --nt-soft-text: rgb(var(--primary-300));
        box-shadow: -16px 0 40px -16px rgba(0, 0, 0, .7);
    }

    /* Kepala: judul tegas, tautan aksi berupa pil kecil. */
    .fi-modal-window:has(.fi-no-notification) .fi-modal-header { padding: 1.25rem 1.5rem 1rem; border-bottom: 1px solid var(--nt-line); }
    .fi-modal-window:has(.fi-no-notification) .fi-modal-heading { font-size: 1.0625rem; font-weight: 600; letter-spacing: -.01em; color: var(--nt-ink); }
    .fi-modal-window:has(.fi-no-notification) .fi-modal-header .fi-link {
        font-size: .75rem; font-weight: 500; padding: .25rem .625rem; border-radius: .5rem; transition: background-color .12s ease;
    }
    .fi-modal-window:has(.fi-no-notification) .fi-modal-header .fi-link:hover { background: rgba(var(--gray-500), .1); }
    .fi-modal-window:has(.fi-no-notification) .fi-modal-header .fi-link.fi-color-primary { color: var(--nt-soft-text); background: var(--nt-soft); }
    .fi-modal-window:has(.fi-no-notification) .fi-modal-header .fi-link.fi-color-primary:hover { background: var(--nt-soft-hover); }
    .fi-modal-window:has(.fi-no-notification) .fi-modal-header .fi-link.fi-color-danger { color: var(--nt-muted); }

    /* Daftar: pemisah tipis, belum dibaca berlatar tipis dengan garis primer di kiri (bawaan Filament). */
    .fi-modal-window:has(.fi-no-notification) .divide-y > * + * { border-color: var(--nt-line); }
    .fi-modal-window:has(.fi-no-notification) [class*="before:bg-primary-600"] { background: var(--nt-unread); }

    /* Satu notifikasi: ruang lega, judul tegas, tanggal dan isi redup. */
    .fi-no-notification.fi-inline > div { padding: 1rem 1.5rem; gap: .75rem; }
    .fi-no-notification.fi-inline .fi-no-notification-title { font-size: .875rem; line-height: 1.25rem; font-weight: 600; color: var(--nt-ink, rgb(var(--gray-950))); }
    .fi-no-notification.fi-inline .fi-no-notification-date { font-size: .75rem; color: var(--nt-muted, rgb(var(--gray-500))); }
    .fi-no-notification.fi-inline .fi-no-notification-body { font-size: .8125rem; line-height: 1.35rem; color: var(--nt-body, rgb(var(--gray-600))); }
    .fi-no-notification.fi-inline .fi-no-notification-actions { margin-top: .625rem; }

    /* Tombol "Buka": kecil, berlatar primer pucat (bukan blok merah penuh), tetap jelas sebagai aksi. */
    .fi-no-notification.fi-inline .fi-btn {
        padding: .3125rem .75rem; font-size: .75rem; font-weight: 600; border-radius: .5rem; box-shadow: none; --tw-ring-shadow: 0 0 #0000;
    }
    .fi-no-notification.fi-inline .fi-btn.fi-btn-color-primary {
        background: var(--nt-soft); color: var(--nt-soft-text); --tw-ring-color: transparent;
    }
    .fi-no-notification.fi-inline .fi-btn.fi-btn-color-primary:hover { background: var(--nt-soft-hover); }

    /* Tombol tutup (X) per notifikasi dan di kepala: kecil dan redup. */
    .fi-no-notification.fi-inline .fi-icon-btn { width: 1.75rem; height: 1.75rem; border-radius: .5rem; color: var(--nt-muted, rgb(var(--gray-500))); }
</style>
