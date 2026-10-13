<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LinkOccurrence extends Model
{
    protected $guarded = [];

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function check(): BelongsTo
    {
        return $this->belongsTo(LinkCheck::class, 'link_check_id');
    }
}
