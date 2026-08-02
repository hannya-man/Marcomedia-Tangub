<?php
namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            'Apparel / Sublimation',
            'Trophies & Plaques',
            'Customized Items',
            'Printing Services',
            'Sublimation, DTP, DTF',
        ];

        foreach ($defaults as $name) {
            Category::firstOrCreate(
                ['slug' => str($name)->slug()],
                ['name' => $name]
            );
        }
    }
}
