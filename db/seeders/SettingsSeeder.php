<?php

namespace Database\Seeders;

use App\Livewire\Admin\SettingsManager;
use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Seeds every key declared in SettingsManager::SCHEMA with its default,
 * but only when the row doesn't exist yet — idempotent, safe to re-run.
 *
 * Run on a fresh install with:
 *   docker compose exec app php artisan db:seed --class=SettingsSeeder
 */
class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (SettingsManager::SCHEMA as $key => $def) {
            if (Setting::query()->whereKey($key)->exists()) {
                continue;
            }
            Setting::query()->create([
                'key'         => $key,
                'value'       => (string) ($def['default'] ?? ''),
                'description' => $def['label'] ?? null,
            ]);
        }
    }
}
