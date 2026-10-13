<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Page extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['h1' => 'array', 'meta' => 'array', 'checked_at' => 'datetime'];
    }

    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(LinkOccurrence::class);
    }
}
