@extends('layout')
@section('title', 'Panoramica')
@section('content')
@if($stats['active'] > 0) @include('worker-status') @endif
<div class="page-heading"><div><div class="eyebrow">IL TUO OSSERVATORIO SUI LINK</div><h1>Ogni link racconta un’opportunità.</h1><p>Trova i collegamenti esterni non funzionanti e le pagine che li ospitano.</p></div><a class="button primary" href="{{ route('sites.create') }}">＋ Aggiungi un sito</a></div>
<div class="metrics">
    <div class="metric"><span>Siti nel workspace</span><strong>{{ $stats['sites'] }}</strong><small>I domini che hai aggiunto</small></div>
    <div class="metric"><span>Scansioni in corso <i class="status-dot"></i></span><strong>{{ $stats['active'] }}</strong><small>Elaborazione in background</small></div>
    <div class="metric"><span>Scansioni totali</span><strong>{{ $stats['scans'] }}</strong><small>Il tuo storico di ricerca</small></div>
    <div class="metric accent"><span>Errori HTTP rilevati</span><strong>{{ $stats['errors'] }}</strong><small>Risposte 4xx e 5xx nello storico</small></div>
</div>
<section class="section">
    <div class="section-heading"><div><h2>I tuoi siti <span class="count">{{ $sites->count() }}</span></h2><p>Impostazioni e scansioni, dominio per dominio.</p></div><a class="text-link" href="{{ route('dashboard') }}">Aggiorna ↻</a></div>
    @if($sites->isEmpty())
    <div class="empty-state hero-empty">
        <div class="orbit" aria-hidden="true"><span class="orbit-small">↗</span><span class="orbit-main">⌕</span><span class="orbit-dot"></span></div>
        <span class="eyebrow">LA TUA PRIMA ESPLORAZIONE</span><h2>Parti da un sito. Trova nuove possibilità.</h2><p>Inserisci un dominio: raccoglieremo link esterni, anchor text<br class="desktop-only"> e metadati delle pagine in un unico report.</p><a class="button primary" href="{{ route('sites.create') }}">Aggiungi il primo sito <span>→</span></a>
        <div class="empty-features"><span>✓ Scansioni su richiesta</span><span>✓ Contesto completo</span><span>✓ Esportazione CSV</span></div>
    </div>
    @else
    <div class="site-grid">@foreach($sites as $site)
        <article class="site-card"><div class="site-top"><span class="domain-icon">{{ strtoupper(mb_substr($site->name, 0, 1)) }}</span><span class="badge {{ $site->schedule_frequency === 'manual' ? 'neutral' : 'ok' }}">{{ ['manual'=>'Solo manuale', 'daily'=>'Ogni giorno', 'weekly'=>'Ogni settimana', 'monthly'=>'Ogni mese'][$site->schedule_frequency] }}</span></div>
            <h3>{{ $site->name }}</h3><p class="url">{{ $site->host }}</p><div class="site-facts"><span>{{ number_format($site->max_pages, 0, ',', '.') }} pagine max</span><span>{{ $site->scans_count }} scansioni</span></div>
            @if($site->next_scan_at)<p class="small">Prossima: {{ $site->next_scan_at->timezone('Europe/Rome')->format('d/m/Y H:i') }}</p>@endif
            @if($latest = $site->scans->first())<a class="last-scan" href="{{ route('scans.show', $latest) }}"><span>Ultima scansione <strong>{{ $latest->label() }}</strong></span><span>↗</span></a>@endif
            <div class="card-actions"><form method="post" action="{{ route('sites.scan', $site) }}">@csrf<button class="button primary small-button">{{ $latest && $latest->active() ? 'Vedi scansione' : 'Avvia scansione' }} →</button></form><a class="text-link" href="{{ route('sites.edit', $site) }}">Impostazioni</a></div>
        </article>
    @endforeach</div>
    @endif
</section>
<section class="section" id="recent"><div class="section-heading"><div><h2>Scansioni recenti</h2><p>Tutte le tue verifiche, con i risultati sempre disponibili.</p></div><span class="small">Ultime 8 scansioni</span></div>
<div class="panel table-wrap"><table><thead><tr><th>Sito</th><th>Avviata il</th><th>Stato</th><th>Pagine lette</th><th>Link esterni</th><th><span class="sr-only">Report</span></th></tr></thead><tbody>
@forelse($recent as $scan)<tr><td><strong>{{ $scan->site->name }}</strong><span class="cell-sub">{{ $scan->site->host }}</span></td><td>{{ $scan->created_at->timezone('Europe/Rome')->format('d/m/Y · H:i') }}</td><td><span class="badge {{ $scan->status }}">{{ $scan->label() }}</span></td><td>{{ $scan->pages_count }}</td><td>{{ $scan->checks_count }}</td><td><div class="actions"><a class="text-link" href="{{ route('scans.show', $scan) }}">Apri report ↗</a><a class="text-link danger" href="{{ route('scans.delete', $scan) }}" aria-label="Elimina scansione #{{ $scan->id }}">Elimina</a></div></td></tr>
@empty<tr><td colspan="6" class="empty-row">Le tue scansioni appariranno qui. Aggiungi un sito per iniziare.</td></tr>@endforelse
</tbody></table></div></section>
@endsection
