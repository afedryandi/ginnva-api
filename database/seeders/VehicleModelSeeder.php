<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Vehicle;

/**
 * Seed data merek + tipe kendaraan lengkap per kategori ukuran
 * berdasarkan Price List PPF & Kaca Film Ginnva.
 *
 * Jalankan: php artisan db:seed --class=VehicleModelSeeder
 */
class VehicleModelSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            'S' => [
                'AION'       => ['UT', 'Y PLUS'],
                'BMW'        => ['Z4'],
                'BYD'        => ['DOLPHIN', 'ATTO 1'],
                'FIAT'       => ['ABARTHA 2011'],
                'HONDA'      => ['BRIO', 'CITY', 'WRV'],
                'HYUNDAI'    => ['CRETA', 'IONIQ 5'],
                'MAZDA'      => ['MX5'],
                'MERCY'      => ['E300 2 DOTS'],
                'MINI'       => ['COOPER', 'COOPER GP', 'COOPER GP S CABRIO'],
                'MITSUBISHI' => ['ECLIPSE CROSS 2019'],
                'NETA'       => ['V'],
                'PORSCHE'    => ['BOXSTER'],
                'SUBARU'     => ['BRZ 5 MT', 'BRZ 5 EYESIGHT'],
                'TOYOTA'     => ['GR YARIS', 'RAIZE'],
                'VINFAST'    => ['VF3'],
                'WULING'     => ['AIR EV', 'BINGUO'],
            ],
            'M' => [
                'BMW'        => ['SERIES 1', 'SERIES 2', 'SERIES 3', 'X1'],
                'BAIC'       => ['BJ30', 'BJ40 PLUS'],
                'BYD'        => ['ATTO 3', 'SEAL', 'SEALION'],
                'CHERRY'     => ['J6', 'J6 FULL', 'J6T', 'OMODA 5', 'OMODA 5 EV', 'TIGGO CROSS'],
                'GEELY'      => ['EX 5'],
                'GWM'        => ['HAVAL JULION'],
                'HONDA'      => ['BRV', 'CIVIC', 'HRV'],
                'HYUNDAI'    => ['IONIQ 5', 'IONIQ 6', 'KONA', 'SANTA FE 2019', 'SANTA FE 2024', 'STARGAZER'],
                'JEEP'       => ['RUBICON SWB'],
                'JETOUR'     => ['DASHING'],
                'KIA'        => ['CARENS', 'EV 6'],
                'LAND ROVER' => ['DEFENDER 90', 'RANGE ROVER EVOQUE'],
                'LEXUS'      => ['NX', 'UX 300', 'RX 350', 'RX 450'],
                'MAZDA'      => ['CX 3', 'RX 8'],
                'MERCY'      => ['A CLASS', 'C CLASS', 'GT 36S', 'SL'],
                'MG'         => ['4 EV', 'ZS'],
                'MINI'       => ['CLUBMAN', 'COUNTRYMAN'],
                'MITSUBISHI' => ['DESTINATOR', 'XFORCE', 'XPANDER'],
                'NISSAN'     => ['KICK'],
                'PEUGEOT'    => ['3008', '5008'],
                'PORSCHE'    => ['CAYMAN 718', 'TAYCAN'],
                'SUBARU'     => ['CROSSTREK'],
                'SUZUKI'     => ['BALENO', 'ERTIGA', 'JIMNY 5 DOORS', 'XL 7'],
                'TOYOTA'     => ['BZ4X', 'CHR 2020', 'COROLLA CROSS', 'COROLLA ALTIS', 'FT86', 'RUSH', 'SUPRA', 'YARIS CROSS'],
                'VOLKSWAGEN' => ['GOLF'],
                'VOLVO'      => ['EX30', 'EX40', 'EC40', 'XC40'],
                'WULING'     => ['ALVEZ', 'CONFERO', 'CORTEZ'],
                'XPENG'      => ['G6'],
            ],
            'L' => [
                'AION'       => ['HYPTEC', 'V PLUS'],
                'AUDI'       => ['Q5'],
                'BMW'        => ['I3S 120', 'I4', 'I5', 'I8', 'IX', 'IX 1', 'M2', 'SERIES 4', 'SERIES 5', 'X2', 'XM'],
                'BAIC'       => ['X55'],
                'BYD'        => ['M6'],
                'CHERRY'     => ['TIGGO 7', 'TIGGO 8'],
                'FORD'       => ['MUSTANG'],
                'GWM'        => ['HAVAL H6'],
                'HONDA'      => ['ACCORD', 'CRV'],
                'JAECOO'     => ['J5', 'J7', 'J8'],
                'JEEP'       => ['RUBICON 4D', 'WRANGLER'],
                'JETOUR'     => ['T2', 'X70 PLUS', 'X90 PLUS'],
                'KIA'        => ['EV 9'],
                'LAND ROVER' => ['DEFENDER LONG', 'RANGE ROVER VOGUE'],
                'LEXUS'      => ['RX 300'],
                'MAZDA'      => ['6 2019', '6 ESTATE', 'CX 5', 'CX 60', 'CX 8', 'RX 7'],
                'MERCY'      => ['E CLASS', 'GT53', 'GLA', 'GLC', 'GLE', 'CLS 350', 'SLS'],
                'MAXUS'      => ['MIFA 7'],
                'NISSAN'     => ['GTR', 'LEAF', 'SERENA'],
                'PORSCHE'    => ['911', 'CARERRA', 'MACAN'],
                'SUBARU'     => ['OUTBACK', 'WRX'],
                'SUZUKI'     => ['GRAND VITARA'],
                'TESLA'      => ['MODEL 3', 'MODEL Y'],
                'TOYOTA'     => ['CAMRY ALL', 'FJ CRUISER', 'FORTUNER', 'INNOVA 2019', 'PRIUS', 'VOXY'],
                'VOLVO'      => ['XC 60'],
                'WULING'     => ['ALMAZ', 'DARION'],
            ],
            'XL' => [
                'BMW'         => ['I7', 'M3', 'M4', 'M5', 'M8', 'SERIES 6', 'SERIES 7', 'SERIES 8', 'X3', 'X4', 'X5', 'X6'],
                'DENZA'       => ['D9'],
                'FERRARI'     => ['296', '360 SPINDER', '430', '458 2D', '488', '488 GTO', '599 2011', 'F12 F430', 'FF', 'GTC', 'GTC 4', 'PISTA', 'PORTOFINO', 'ROMA', 'SF90', 'TDF'],
                'FORD'        => ['EVEREST', '4 RANGER'],
                'GWM'         => ['TANK 300', 'TANK 500'],
                'HONDA'       => ['STEP WGN'],
                'HYUNDAI'     => ['PALISADE', 'STARIA'],
                'JEEP'        => ['GLADIATOR'],
                'KIA'         => ['GRAND CARNIVAL', 'SEDONA'],
                'LAMBORGHINI' => ['AVENTADOR', 'AVENTADOR 2013', 'GALLARDO', 'HURACAN', 'URUS'],
                'LAND ROVER'  => ['RANGE ROVER SPORT'],
                'LEXUS'       => ['ES', 'LM 350', 'LS 460'],
                'MAZDA'       => ['CX 9'],
                'MC LAREN'    => ['570S', '720S'],
                'MERCY'       => ['S CLASS', 'V CLASS', 'GLS'],
                'MITSUBISHI'  => ['TRITON', 'PAJERO'],
                'MAXUS'       => ['MIFA 9'],
                'PORSCHE'     => ['911 TARGA', 'CAYENNE', 'PANAMERA'],
                'TOYOTA'      => ['ALPHARD', 'HILUX SINGLE CABIN', 'HILUX DOUBLE CABIN', 'LAND CRUISER'],
                'VOLVO'       => ['XC90'],
                'XPENG'       => ['X9'],
            ],
            'XXL' => [
                'BENTLEY'    => ['CONTINENTAL'],
                'BMW'        => ['X5 COMPETITION', 'X7'],
                'FORD'       => ['F 150', 'RAPTOR'],
                'GMC'        => ['YUKON DENALI'],
                'KIA'        => ['CARNIVAL 7'],
                'LAND ROVER' => ['DEFENDER 110 FULL'],
                'LEXUS'      => ['LX 570', 'LX 600'],
                'MCLAREN'    => ['MP4'],
                'MERCY'      => ['G CLASS'],
                'NISSAN'     => ['PATROL'],
                'ROLLS ROYCE'=> ['GHOST', 'PHANTOM'],
                'TOYOTA'     => ['GRAND ACE', 'LAND CRUISER PRADO'],
            ],
        ];

        foreach ($data as $size => $brands) {
            foreach ($brands as $brand => $models) {
                foreach ($models as $model) {
                    Vehicle::firstOrCreate(
                        [
                            'brand'         => $brand,
                            'model'         => $model,
                            'size_category' => $size,
                        ],
                        ['variant' => null]
                    );
                }
            }
        }

        $this->command->info('VehicleModelSeeder: ' . Vehicle::whereNotNull('model')->count() . ' tipe kendaraan berhasil di-seed.');
    }
}
