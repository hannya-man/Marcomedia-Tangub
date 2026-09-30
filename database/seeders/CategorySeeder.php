<?php
namespace Database\Seeders;

use App\Models\MaterialCategory;
use Illuminate\Database\Seeder;

class MaterialCategorySeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            'Textile & Fabric',
            'Ink & Consumables',
            'Acrylic & Plastic',
            'Blanks — Drinkware & Giveaways',
            'Blanks — Awards & Trophies',
            'Paper & Card Stock',
            'Vinyl & Large Format',
            'Hardware & Findings',
            'Packaging',
        ];

        foreach ($defaults as $name) {
            MaterialCategory::firstOrCreate(
                ['slug' => str($name)->slug()],
                ['name' => $name]
            );
        }
    }
}