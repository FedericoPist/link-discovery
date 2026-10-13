<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Scan extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['settings' => 'array', 'limit_reached' => 'boolean', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'heartbeat_at' => 'datetime'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(Page::class);
    }

    public function checks(): HasMany
    {
        return $this->hasMany(LinkCheck::class);
    }

    public function active(): bool
    {
        return in_array($this->status, ['queued', 'running'], true);
    }

    public function label(): string
    {
        return match ($this->status) {
            'queued' => 'In coda', 'running' => 'In corso', 'completed' => 'Completata',
            'cancelled' => 'Interrotta', 'failed' => 'Errore', default => $this->status,
        };
    }
}
