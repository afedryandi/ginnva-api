<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Poin kunjungan maintenance
    |--------------------------------------------------------------------------
    | Poin yang diterima customer setiap satu kunjungan maintenance PPF selesai (booking Maintenance PPF completed atau
    | kunjungan walk-in yang dicatat staf). 0 = fitur nonaktif (belum ada poin yang diberikan). Besarannya belum
    | diputuskan (2026-10-10): isi LOYALTY_MAINTENANCE_VISIT_POINTS di .env server lalu jalankan config:cache.
    */
    'maintenance_visit_points' => (int) env('LOYALTY_MAINTENANCE_VISIT_POINTS', 0),

];
