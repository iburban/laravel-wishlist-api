<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            ['name' => 'Aeron Ergonomic Office Chair', 'price' => '1395.00', 'description' => 'Breathable mesh task chair with adjustable lumbar support.'],
            ['name' => 'Mechanical Keyboard (Brown Switches)', 'price' => '119.99', 'description' => 'Tactile hot-swappable keyboard with PBT keycaps.'],
            ['name' => '27" 4K UHD Monitor', 'price' => '349.50', 'description' => 'IPS panel, USB-C power delivery, 99% sRGB coverage.'],
            ['name' => 'Wireless Noise-Cancelling Headphones', 'price' => '279.00', 'description' => 'Over-ear headphones with 30-hour battery life.'],
            ['name' => 'USB-C Docking Station', 'price' => '189.95', 'description' => 'Dual-display dock with Ethernet, SD reader, and 100W charging.'],
            ['name' => 'Standing Desk (Electric)', 'price' => '529.00', 'description' => 'Dual-motor sit-stand desk with memory presets.'],
            ['name' => '1TB NVMe SSD', 'price' => '89.99', 'description' => 'Gen4 solid-state drive with up to 7,000 MB/s reads.'],
            ['name' => 'Webcam 1080p', 'price' => '59.95', 'description' => 'Full-HD webcam with auto-focus and dual microphones.'],
            ['name' => 'Laptop Stand (Aluminium)', 'price' => '34.99', 'description' => 'Ventilated aluminium riser for 11" to 17" laptops.'],
            ['name' => 'Smart LED Desk Lamp', 'price' => '44.50', 'description' => 'Dimmable lamp with adjustable colour temperature and USB port.'],
        ];

        foreach ($products as $product) {
            Product::create($product);
        }
    }
}
