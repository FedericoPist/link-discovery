<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LinkCheck extends Model
{
    public const UNVERIFIABLE_CLASSIFICATIONS = ['robots', 'robots_unavailable', 'blocked'];

    public const ISSUE_CLASSIFICATIONS = [
        'broken', 'server_error', 'access_denied', 'rate_limited', 'client_error',
        'network_error', 'redirect_error', 'retry', ...self::UNVERIFIABLE_CLASSIFICATIONS,
    ];

    public const LABELS = [
        'pending' => 'Da verificare', 'retry' => 'Nuovo tentativo', 'ok' => 'Raggiungibile',
        'broken' => 'Non trovato', 'server_error' => 'Errore server', 'access_denied' => 'Accesso negato',
        'rate_limited' => 'Troppe richieste', 'client_error' => 'Altro errore 4xx',
        'network_error' => 'Errore di rete', 'robots' => 'Escluso da robots.txt', 'robots_unavailable' => 'Robots.txt non verificabile',
        'blocked' => 'URL non consentito', 'redirect_error' => 'Redirect non risolto',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['redirects' => 'array', 'checked_at' => 'datetime', 'retry_at' => 'datetime'];
    }

    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(LinkOccurrence::class);
    }

    public function label(): string
    {
        return self::LABELS[$this->classification] ?? $this->classification;
    }

    public static function classify(int $status): string
    {
        return match (true) {
            $status >= 200 && $status < 300 => 'ok',
            in_array($status, [404, 410]) => 'broken',
            in_array($status, [401, 403]) => 'access_denied',
            $status === 429 => 'rate_limited',
            $status >= 500 => 'server_error',
            $status >= 400 => 'client_error',
            default => 'redirect_error',
        };
    }
}
