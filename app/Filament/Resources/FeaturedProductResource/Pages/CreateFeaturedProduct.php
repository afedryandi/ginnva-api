<?php

namespace App\Filament\Resources\FeaturedProductResource\Pages;

use App\Filament\Resources\FeaturedProductResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFeaturedProduct extends CreateRecord
{
    protected static string $resource = FeaturedProductResource::class;
}
