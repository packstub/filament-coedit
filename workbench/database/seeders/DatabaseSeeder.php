<?php

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Workbench\App\Models\Product;
use Workbench\App\Models\User;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Ion Caliman' => 'ion', 'Ana Popescu' => 'ana', 'Mihai Rusu' => 'mihai'] as $name => $login) {
            User::query()->firstOrCreate(['email' => "{$login}@example.com"], ['name' => $name, 'password' => 'password']);
        }

        $products = [
            ['Linen overshirt', 'LIN-OVR-01', 'published', 89, 42, 'Garment-dyed linen with horn buttons and a relaxed fit.', true],
            ['Merino crew sweater', 'MER-CRW-02', 'published', 120, 18, 'Extra-fine merino, knitted in Portugal.', false],
            ['Canvas weekender', 'CNV-WKD-03', 'review', 149, 7, 'Waxed canvas body, leather handles, fits a 15" laptop.', true],
            ['Wool beanie', 'WOL-BNE-04', 'draft', 29, 120, 'Ribbed lambswool in five colours.', false],
            ['Selvedge jeans', 'SLV-JNS-05', 'published', 165, 23, '14 oz Japanese selvedge denim, straight leg.', false],
        ];

        foreach ($products as [$name, $sku, $status, $price, $stock, $description, $featured]) {
            Product::query()->firstOrCreate(['sku' => $sku], compact('name', 'status', 'price', 'stock', 'description') + ['is_featured' => $featured]);
        }
    }
}
