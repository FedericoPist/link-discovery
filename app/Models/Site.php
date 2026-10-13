<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Site extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['include_subdomains' => 'boolean', 'include_query' => 'boolean', 'continue_on_robots_error' => 'boolean', 'next_scan_at' => 'datetime'];
    }

    public function scans(): HasMany
    {
        return $this->hasMany(Scan::class);
    }

    public function nextRun(): ?Carbon
    {
        return match ($this->schedule_frequency) {
            'daily' => now()->addDay(),
            'weekly' => now()->addWeek(),
            'monthly' => now()->addMonthNoOverflow(),
            default => null,
        };
    }
}
