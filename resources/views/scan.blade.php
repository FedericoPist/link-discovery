@extends('layout')
@section('title', 'Report scansione #'.$scan->id)
@section('content')
@php($filter = request('filter') ?: 'issues')
<a class="back-link" href="{{ route('dashboard') }}">← Tutti i siti</a>
<div class="page-heading report-heading">
    <div>
        <div class="eyebrow">{{ $scan->site->name }} · SCANSIONE #{{ $scan->id }}</div>
        <h1>Link esterni da controllare</h1>
        <p class="url">{{ $scan->settings['url'] }} <span class="separator">·</span> {{ $scan->created_at->timezone('Europe/Rome')->format('d/m/Y H:i') }}</p>
    </div>
    <div class="actions">
        <a class="button secondary" href="{{ request()->fullUrl() }}">↻ Aggiorna</a>
        <a class="button primary" href="{{ route('scans.export', ['scan' => $scan, 'filter' => $filter, 'q' => request('q')]) }}">↓ Esporta risultati</a>
    </div>
</div>
<div class="metrics issue-metrics" aria-label="Riepilogo dei link esterni">
    <a class="metric issue-total {{ $filter === 'issues' ? 'metric-selected' : '' }}" href="{{ route('scans.show', ['scan' => $scan, 'filter' => 'issues']) }}" @if($filter === 'issues') aria-current="true" @endif>
        <span>Tutte le anomalie <span aria-hidden="true">↗</span></span><strong>{{ $counts['issues'] }}</strong><small>Link unici con errori o verifiche bloccate</small>
    </a>
    <a class="metric {{ $filter === 'errors' ? 'metric-selected' : '' }}" href="{{ route('scans.show', ['scan' => $scan, 'filter' => 'errors']) }}" @if($filter === 'errors') aria-current="true" @endif>
        <span>Errori HTTP <span aria-hidden="true">↗</span></span><strong>{{ $counts['errors'] }}</strong><small>404, 410, accessi negati e altri 4xx / 5xx</small>
    </a>
    <a class="metric {{ $filter === 'network' ? 'metric-selected' : '' }}" href="{{ route('scans.show', ['scan' => $scan, 'filter' => 'network']) }}" @if($filter === 'network') aria-current="true" @endif>
        <span>Rete e redirect <span aria-hidden="true">↗</span></span><strong>{{ $counts['network'] }}</strong><small>DNS, connessione, timeout e redirect</small>
    </a>
    <a class="metric {{ $filter === 'unverifiable' ? 'metric-selected' : '' }}" href="{{ route('scans.show', ['scan' => $scan, 'filter' => 'unverifiable']) }}" @if($filter === 'unverifiable') aria-current="true" @endif>
        <span>Non verificabili <span aria-hidden="true">↗</span></span><strong>{{ $counts['unverifiable'] }}</strong><small>Robots.txt e destinazioni non consentite</small>
    </a>
</div>
<div class="scan-status panel report-progress">
    <div>
        <span class="badge {{ $scan->status }}">{{ $scan->label() }}</span>
        <div>
            <strong>{{ $counts['checked'] }} / {{ $counts['checks'] }} link esterni verificati</strong>
            <p>{{ $counts['pages'] }} / {{ $counts['discovered'] }} pagine lette · {{ $counts['retry'] }} verifiche da riprovare</p>
            @if($scan->active())
                <p>{{ $counts['pages'] < $counts['discovered'] && $counts['checked'] === 0 ? 'Raccolta dei link in corso. Le verifiche esterne iniziano dopo l’esplorazione delle pagine.' : 'Verifiche in corso. Premi Aggiorna per vedere i nuovi esiti.' }}</p>
            @endif
        </div>
    </div>
    <div class="actions">
        @if($scan->active())
            <form method="post" action="{{ route('scans.cancel', $scan) }}">@csrf<button class="text-button danger">Interrompi scansione</button></form>
        @elseif(in_array($scan->status, ['failed', 'cancelled']))
            <form method="post" action="{{ route('scans.resume', $scan) }}">@csrf<button class="text-button">Riprendi scansione →</button></form>
            <form method="post" action="{{ route('sites.scan', $scan->site) }}">@csrf<button class="text-button">Nuova scansione →</button></form>
        @else
            <form method="post" action="{{ route('sites.scan', $scan->site) }}">@csrf<button class="text-button">Avvia nuova scansione →</button></form>
        @endif
        <a class="text-link danger" href="{{ route('scans.delete', $scan) }}">Elimina scansione</a>
    </div>
</div>
@if($scan->active()) @include('worker-status') @endif
@if($scan->error)<div class="notice error">{{ $scan->error }}</div>@endif
@if(!$scan->active() && $counts['pages'] === 0)<div class="notice">Non è stato possibile leggere alcuna pagina HTML. Non ci sono dati sufficienti per valutare i link esterni. Controlla i dettagli delle pagine del sito in fondo al report.</div>@endif
@if($scan->limit_reached)<div class="notice report-notice">Raggiunto un limite di esplorazione: i risultati coprono solo la parte di sito analizzata.</div>@endif
@if($scan->active() && ($scan->heartbeat_at ?? $scan->created_at)->lt(now()->subMinutes(6)))<div class="notice">La scansione non riceve aggiornamenti da alcuni minuti. Controlla che il processo di elaborazione sia attivo.</div>@endif
<section class="section" id="external-links" aria-labelledby="external-links-title">
    <div class="section-heading">
        <div><h2 id="external-links-title">{{ $filter === 'issues' ? 'Errori e verifiche bloccate' : 'Link esterni · risultati' }}</h2><p>Destinazione, problema rilevato e pagina in cui intervenire.</p></div>
        <span class="small">{{ $results->total() }} link unici</span>
    </div>
    <div class="panel">
        <form class="filters" method="get" action="{{ route('scans.show', $scan) }}#external-links">
            <div class="search-field"><label class="sr-only" for="q">Cerca URL o anchor text</label><input id="q" name="q" value="{{ request('q') }}" maxlength="200" placeholder="Cerca destinazione, pagina sorgente o anchor…"></div>
            <div><label class="sr-only" for="filter">Tipo di risultato</label><select id="filter" name="filter">
                <option value="issues" @selected($filter === 'issues')>Tutti gli errori e i blocchi</option>
                <option value="errors" @selected($filter === 'errors')>Errori HTTP · 4xx e 5xx</option>
                <option value="network" @selected($filter === 'network')>Rete e redirect</option>
                <option value="unverifiable" @selected($filter === 'unverifiable')>Verifiche bloccate</option>
                @foreach(\App\Models\LinkCheck::LABELS as $key => $label)<option value="{{ $key }}" @selected($filter === $key)>{{ $label }}</option>@endforeach
                <option value="all" @selected($filter === 'all')>Tutti i collegamenti</option>
            </select></div>
            <button class="button secondary">Filtra</button>
            @if(request('q'))<a class="text-link" href="{{ route('scans.show', ['scan' => $scan, 'filter' => $filter]) }}">Azzera ricerca</a>@endif
        </form>
        <div class="table-wrap">
            <table class="external-results-table">
                <thead><tr><th>Link esterno / anchor text</th><th>Problema / esito</th><th>Dove si trova</th></tr></thead>
                <tbody>
                @forelse($results as $check)
                    @php($row = $check->occurrences->first())
                    @php($sources = $check->occurrences->groupBy('page_id'))
                    <tr>
                        <td data-label="Link esterno">
                            <a class="result-url" href="{{ $check->url }}" target="_blank" rel="noopener noreferrer">{{ $check->url }} ↗</a>
                            <span class="anchor-text">{{ $row->anchor_text !== '' ? '“'.$row->anchor_text.'”' : 'Anchor text vuoto' }}</span>
                            @if($row->rel)<span class="cell-sub">rel: {{ $row->rel }}</span>@endif
                        </td>
                        <td data-label="Problema / esito">
                            <div class="result-outcome"><span class="http-code {{ $check->classification }}">{{ $check->http_status ?? '—' }}</span><strong>{{ $check->label() }}</strong></div>
                            @if($check->error)<p class="result-reason">{{ $check->error }}</p>@endif
                            @if(in_array($check->classification, \App\Models\LinkCheck::UNVERIFIABLE_CLASSIFICATIONS))<p class="result-reason">La verifica è bloccata: il link non è confermato come rotto.</p>@endif
                            @if($check->classification === 'retry')<span class="cell-sub">Esito provvisorio · nuovo tentativo previsto</span>@endif
                            @if($check->checked_at)<span class="cell-sub">Verificato {{ $check->checked_at->timezone('Europe/Rome')->format('d/m H:i') }}</span>@endif
                        </td>
                        <td data-label="Pagina sorgente">
                            <span class="source-count">{{ $sources->count() }} {{ $sources->count() === 1 ? 'pagina sorgente' : 'pagine sorgenti' }} · {{ $check->occurrences->count() }} {{ $check->occurrences->count() === 1 ? 'presenza' : 'presenze' }}</span>
                            <strong class="page-title">{{ $row->page->title ?: 'Pagina senza title' }}</strong>
                            <a class="source-url" href="{{ $row->page->final_url ?: $row->page->url }}" target="_blank" rel="noopener noreferrer">{{ $row->page->final_url ?: $row->page->url }} ↗</a>
                            @if($check->occurrences->count() > 1)
                                <details class="result-context"><summary>Tutte le sorgenti e gli anchor</summary>
                                    <ul class="link-sources">
                                        @foreach($sources as $occurrences)
                                            @php($source = $occurrences->first()->page)
                                            <li>
                                                <strong class="page-title">{{ $source->title ?: 'Pagina senza title' }}</strong>
                                                <a class="source-url" href="{{ $source->final_url ?: $source->url }}" target="_blank" rel="noopener noreferrer">{{ $source->final_url ?: $source->url }} ↗</a>
                                                @foreach($occurrences->pluck('anchor_text')->unique() as $anchor)<span class="cell-sub">{{ $anchor !== '' ? '“'.$anchor.'”' : 'Anchor text vuoto' }}</span>@endforeach
                                            </li>
                                        @endforeach
                                    </ul>
                                </details>
                            @endif
                            <details class="result-context"><summary>Dettagli della prima sorgente</summary><dl class="metadata">
                                <dt>H1</dt><dd>{{ implode(' | ', $row->page->h1 ?? []) ?: 'Non presente' }}</dd>
                                <dt>Meta description</dt><dd>{{ $row->page->meta_description ?: 'Non presente' }}</dd>
                                <dt>URL finale</dt><dd>{{ $check->final_url ?: 'Non ancora verificato' }}</dd>
                                <dt>Tentativi</dt><dd>{{ $check->attempts }}</dd>
                                @foreach($row->page->meta ?? [] as $name => $values)<dt>{{ $name }}</dt><dd>{{ implode(' | ', $values) }}</dd>@endforeach
                            </dl></details>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3"><div class="empty-state compact">
                        <span class="empty-icon" aria-hidden="true">⌕</span>
                        <h3>{{ $scan->active() ? 'Nessun esito disponibile per questo filtro, per ora.' : 'Nessun collegamento per questo filtro.' }}</h3>
                        <p>{{ $scan->active() ? 'La scansione sta proseguendo. Premi Aggiorna per vedere gli esiti raccolti; l’assenza di risultati non significa che tutti i link funzionino.' : 'Controlla la copertura della scansione o cambia filtro per vedere gli altri esiti.' }}</p>
                        <a class="text-link" href="{{ route('scans.show', ['scan' => $scan, 'filter' => 'all']) }}">Mostra tutti i collegamenti →</a>
                    </div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @include('pagination', ['paginator' => $results->fragment('external-links')])
    </div>
    <p class="help report-note">Una sola riga per URL esterno in questa scansione, anche se compare in più pagine. Le altre sorgenti sono raccolte nei dettagli; anche il CSV contiene una riga per link. 404 e 410 indicano risorse mancanti. Errori di rete, accessi negati e blocchi robots.txt richiedono una verifica: non provano da soli che la risorsa sia stata rimossa.</p>
</section>
<div class="scan-secondary">
    <details class="panel scan-disclosure" id="scanned-pages" @if(request()->has('pages_page')) open @endif>
        <summary><span>Pagine del sito <small>{{ $counts['pages'] }} lette su {{ $counts['discovered'] }} · limite {{ $scan->settings['max_pages'] }}</small></span><span class="disclosure-hint">Dettagli esplorazione</span></summary>
        @if($counts['warnings'] > 0)<div class="notice">La scansione è proseguita nonostante errori 5xx di robots.txt, come previsto dalle impostazioni del sito. Gli avvisi sono riportati nelle pagine interessate e nel CSV.</div>@endif
        <div class="table-wrap"><table><thead><tr><th>Pagina interna</th><th>HTTP</th><th>Stato</th></tr></thead><tbody>
            @foreach($pages as $page)<tr><td class="break-url">{{ $page->url }}@if($page->error)<span class="cell-sub">{{ $page->error }}</span>@endif @if($page->warning)<span class="cell-sub">{{ $page->warning }}</span>@endif</td><td>{{ $page->http_status ?? '—' }}</td><td><span class="badge {{ $page->status }}">{{ ['pending' => 'In coda', 'done' => 'Letta', 'error' => 'Errore', 'skipped' => 'Esclusa'][$page->status] ?? $page->status }}</span></td></tr>@endforeach
        </tbody></table></div>
        @include('pagination', ['paginator' => $pages->fragment('scanned-pages')])
    </details>
    <details class="panel scan-disclosure">
        <summary><span>Storico del sito <small>Le ultime {{ $history->count() }} scansioni</small></span><span class="disclosure-hint">Consulta lo storico</span></summary>
        <div class="history">@foreach($history as $item)<div class="history-row"><a class="history-item {{ $item->id === $scan->id ? 'selected' : '' }}" href="{{ route('scans.show', $item) }}"><span><strong>Scansione #{{ $item->id }}</strong><small>{{ $item->created_at->timezone('Europe/Rome')->format('d/m/Y · H:i') }} · {{ $item->trigger === 'manual' ? 'Manuale' : 'Pianificata' }}</small></span><span class="badge {{ $item->status }}">{{ $item->label() }}</span></a><a class="text-link danger history-delete" href="{{ route('scans.delete', $item) }}" aria-label="Elimina scansione #{{ $item->id }}">Elimina</a></div>@endforeach</div>
    </details>
</div>
@endsection
