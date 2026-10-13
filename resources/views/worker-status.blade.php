@if(!$worker['running'])
<div class="notice" role="status">
    @if($worker['starting'])
        <strong>Avvio del motore di scansione…</strong> Premi Aggiorna tra qualche secondo per vedere i primi risultati.
    @else
        <strong>Il motore di scansione non è attivo.</strong> I lavori restano in coda finché l’elaborazione non viene avviata.
        @if($worker['enabled'])
            <form method="post" action="{{ route('worker.start') }}" class="worker-action">@csrf<button class="button primary small-button">Avvia elaborazione →</button></form>
        @else
            <p>Avvia il comando <code>composer dev</code> dalla cartella del progetto.</p>
        @endif
    @endif
</div>
@endif
