@extends('layout')
@section('title', 'Elimina scansione #'.$scan->id)
@section('content')
<a class="back-link" href="{{ route('scans.show', $scan) }}">← Torna al report</a>
<div class="page-heading"><div><div class="eyebrow">{{ $scan->site->name }}</div><h1>Eliminare la scansione #{{ $scan->id }}?</h1><p class="url">{{ $scan->settings['url'] }} · {{ $scan->created_at->timezone('Europe/Rome')->format('d/m/Y H:i') }}</p></div></div>
<div class="panel delete-scan-panel">
    <div class="form-section">
        <p>Verranno eliminati definitivamente il report, le {{ $scan->pages_count }} pagine raccolte, i {{ $scan->checks_count }} link esterni e tutte le loro occorrenze.</p>
        @if($scan->active())<p class="delete-scan-warning">La scansione è ancora attiva: l’eliminazione fermerà anche la sua elaborazione.</p>@endif
        <p class="help">Il sito, le sue impostazioni e le altre scansioni rimangono disponibili. Questa operazione non è annullabile.</p>
    </div>
    <form method="post" action="{{ route('scans.destroy', $scan) }}" class="form-footer">
        @csrf
        @method('DELETE')
        <input type="hidden" name="confirm" value="1">
        <a class="button secondary" href="{{ route('scans.show', $scan) }}">Annulla</a>
        <button class="button destructive">Elimina definitivamente</button>
    </form>
</div>
@endsection
