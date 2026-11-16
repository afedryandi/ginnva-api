import preset from '../../../../vendor/filament/support/tailwind.config.preset'

/** Tema panel admin Ginnva (Filament 3). Dibuild lewat Vite; lihat resources/css/filament/admin/theme.css. */
export default {
    presets: [preset],
    content: [
        './app/Filament/**/*.php',
        './resources/views/filament/**/*.blade.php',
        './vendor/filament/**/*.blade.php',
    ],
}
