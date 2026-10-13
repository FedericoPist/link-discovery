<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessScan;
use App\Models\LinkCheck;
use App\Models\LinkOccurrence;
use App\Models\Scan;
use App\Models\Site;
use App\Services\LocalWorker;
use App\Services\ScanStarter;
use App\Services\UrlPolicy;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DiscoveryController extends Controller
{
    public function index(): View
    {
        return view('dashboard', [
            'sites' => Site::with(['scans' => fn ($q) => $q->latest()->limit(1)])->withCount('scans')->latest()->get(),
            'recent' => Scan::with('site')->withCount(['pages' => fn ($q) => $q->where('kind', 'html')->where('status', 'done'), 'checks'])->latest()->limit(8)->get(),
            'stats' => ['sites' => Site::count(), 'active' => Scan::whereIn('status', ['queued', 'running'])->count(), 'scans' => Scan::count(), 'errors' => LinkCheck::whereBetween('http_status', [400, 599])->count()],
        ]);
    }

    public function create(): View
    {
        return view('site-form', ['site' => new Site(['max_pages' => 200, 'max_depth' => 5, 'schedule_frequency' => 'manual'])]);
    }

    public function edit(Site $site): View
    {
        return view('site-form', compact('site'));
    }

    public function store(Request $request, UrlPolicy $urls, ScanStarter $starter): RedirectResponse
    {
        $site = Site::create($this->validated($request, $urls));
        $site->update(['next_scan_at' => $site->nextRun()]);

        return redirect()->route('scans.show', $starter->start($site))->with('success', 'Sito aggiunto. La prima scansione è in coda.');
    }

    public function update(Request $request, Site $site, UrlPolicy $urls): RedirectResponse
    {
        $site->update($this->validated($request, $urls));
        $site->update(['next_scan_at' => $site->nextRun()]);

        return redirect()->route('dashboard')->with('success', 'Impostazioni salvate. Saranno applicate alle prossime scansioni.');
    }

    private function validated(Request $request, UrlPolicy $urls): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'], 'url' => ['required', 'string', 'max:2048'],
            'max_pages' => ['required', 'integer', 'min:1', 'max:5000'], 'max_depth' => ['required', 'integer', 'min:0', 'max:20'],
            'schedule_frequency' => ['required', Rule::in(['manual', 'daily', 'weekly', 'monthly'])],
            'include_subdomains' => ['sometimes', 'boolean'], 'include_query' => ['sometimes', 'boolean'],
            'continue_on_robots_error' => ['sometimes', 'boolean'],
        ]);
        $input = preg_match('~^https?://~i', $data['url']) ? $data['url'] : 'https://'.$data['url'];
        $url = $urls->normalize($input);
        if (! $url) {
            throw ValidationException::withMessages(['url' => 'Inserisci un URL HTTP o HTTPS valido.']);
        }
        try {
            $urls->resolve($url);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['url' => $e->getMessage()]);
        }

        return array_merge($data, ['url' => $url, 'host' => parse_url($url, PHP_URL_HOST), 'include_subdomains' => $request->boolean('include_subdomains'), 'include_query' => $request->boolean('include_query'), 'continue_on_robots_error' => $request->boolean('continue_on_robots_error')]);
    }

    public function start(Site $site, ScanStarter $starter): RedirectResponse
    {
        return redirect()->route('scans.show', $starter->start($site))->with('success', 'Scansione in coda. Se era già attiva, puoi seguirla qui.');
    }

    public function show(Request $request, Scan $scan): View
    {
        $scan->load('site');

        return view('scan', [
            'scan' => $scan, 'results' => $this->results($request, $scan)->paginate(25)->withQueryString(),
            'counts' => [
                'pages' => $scan->pages()->where('kind', 'html')->where('status', 'done')->count(),
                'discovered' => $scan->pages()->where('kind', 'html')->count(),
                'checks' => $scan->checks()->count(),
                'checked' => $scan->checks()->whereNotIn('classification', ['pending', 'retry'])->count(),
                'errors' => $scan->checks()->whereBetween('http_status', [400, 599])->count(),
                'issues' => $scan->checks()->whereIn('classification', LinkCheck::ISSUE_CLASSIFICATIONS)->count(),
                'network' => $scan->checks()->whereIn('classification', ['network_error', 'redirect_error'])->count(),
                'unverifiable' => $scan->checks()->whereIn('classification', LinkCheck::UNVERIFIABLE_CLASSIFICATIONS)->count(),
                'retry' => $scan->checks()->where('classification', 'retry')->count(),
                'occurrences' => LinkOccurrence::whereHas('check', fn ($q) => $q->where('scan_id', $scan->id))->count(),
                'warnings' => $scan->pages()->whereNotNull('warning')->count(),
            ],
            'pages' => $scan->pages()->where('kind', 'html')->latest('id')->paginate(15, ['*'], 'pages_page')->withQueryString(),
            'history' => $scan->site->scans()->latest()->limit(10)->get(),
        ]);
    }

    private function results(Request $request, Scan $scan): Builder
    {
        $request->validate(['filter' => ['nullable', Rule::in(array_merge(['issues', 'errors', 'network', 'unverifiable', 'all'], array_keys(LinkCheck::LABELS)))], 'q' => ['nullable', 'string', 'max:200']]);
        $filter = $request->query('filter') ?: 'issues';
        $query = LinkCheck::with(['occurrences' => fn ($q) => $q->orderBy('id'), 'occurrences.page'])
            ->where('scan_id', $scan->id)->whereHas('occurrences');
        if ($filter === 'issues') {
            $query->whereIn('classification', LinkCheck::ISSUE_CLASSIFICATIONS);
        } elseif ($filter === 'network') {
            $query->whereIn('classification', ['network_error', 'redirect_error']);
        } elseif ($filter === 'unverifiable') {
            $query->whereIn('classification', LinkCheck::UNVERIFIABLE_CLASSIFICATIONS);
        } elseif ($filter === 'errors') {
            $query->whereBetween('http_status', [400, 599]);
        } elseif ($filter !== 'all') {
            $query->where('classification', $filter);
        }
        if ($search = $request->query('q')) {
            $query->where(fn ($q) => $q->where('url', 'like', '%'.$search.'%')
                ->orWhereHas('occurrences', fn ($o) => $o->where('anchor_text', 'like', '%'.$search.'%')
                    ->orWhereHas('page', fn ($p) => $p->where('url', 'like', '%'.$search.'%'))));
        }

        return $query->orderByDesc('id');
    }

    public function confirmDestroy(Scan $scan): View
    {
        $scan->load('site')->loadCount(['pages', 'checks']);

        return view('scan-delete', compact('scan'));
    }

    public function destroy(Request $request, Scan $scan): RedirectResponse
    {
        $request->validate(['confirm' => ['required', 'accepted']]);

        DB::transaction(function () use ($scan): void {
            Scan::whereKey($scan->id)->lockForUpdate()->first()?->delete();
        });

        return redirect()->route('dashboard')->with('success', 'Scansione #'.$scan->id.' eliminata con tutti i suoi risultati.');
    }

    public function cancel(Scan $scan): RedirectResponse
    {
        if ($scan->active()) {
            $scan->update(['status' => 'cancelled', 'finished_at' => now()]);
        }

        return back()->with('success', 'Scansione interrotta. I risultati già raccolti rimangono disponibili.');
    }

    public function resume(Scan $scan): RedirectResponse
    {
        $resumed = DB::transaction(function () use ($scan) {
            $site = Site::whereKey($scan->site_id)->lockForUpdate()->firstOrFail();
            $scan->refresh();
            if (! in_array($scan->status, ['failed', 'cancelled'], true) || $site->scans()->whereIn('status', ['queued', 'running'])->exists()) {
                return false;
            }
            $scan->update(['status' => 'queued', 'finished_at' => null, 'error' => null]);
            ProcessScan::dispatch($scan->id)->afterCommit();

            return true;
        });
        if ($resumed) {
            DB::afterCommit(fn () => app(LocalWorker::class)->ensureRunning());
        }

        return back()->with('success', $resumed ? 'Scansione rimessa in coda.' : 'C’è già una scansione attiva oppure questa scansione è conclusa.');
    }

    public function export(Request $request, Scan $scan): StreamedResponse
    {
        $query = $this->results($request, $scan);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['URL esterno', 'HTTP', 'Classificazione', 'Anchor text', 'Pagina sorgente', 'Title', 'H1', 'Meta description', 'Metadati JSON', 'Rel', 'URL finale', 'Errore', 'Verificato il', 'Avviso pagina sorgente', 'Occorrenze', 'Numero pagine sorgenti', 'Tutte le sorgenti JSON'], ';', '"', '');
            foreach ($query->lazy(25) as $check) {
                $row = $check->occurrences->first();
                $sources = $check->occurrences->map(fn (LinkOccurrence $occurrence): array => [
                    'url' => $occurrence->page->final_url ?: $occurrence->page->url,
                    'anchor_text' => $occurrence->anchor_text, 'rel' => $occurrence->rel,
                    'title' => $occurrence->page->title, 'h1' => $occurrence->page->h1,
                    'meta_description' => $occurrence->page->meta_description,
                    'meta' => $occurrence->page->meta, 'warning' => $occurrence->page->warning,
                ]);
                $values = [$check->url, $check->http_status, $check->label(), $row->anchor_text, $row->page->final_url ?: $row->page->url, $row->page->title, implode(' | ', $row->page->h1 ?? []), $row->page->meta_description, json_encode($row->page->meta, JSON_UNESCAPED_UNICODE), $row->rel, $check->final_url, $check->error, $check->checked_at?->toIso8601String(), $row->page->warning, $check->occurrences->count(), $check->occurrences->pluck('page_id')->unique()->count(), json_encode($sources, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
                $values = array_map(fn ($value) => preg_match('/^[\s]*[=+@-]/u', (string) $value) ? "'".$value : $value, $values);
                fputcsv($out, $values, ';', '"', '');
            }
            fclose($out);
        }, 'link-discovery-scan-'.$scan->id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function startWorker(LocalWorker $worker): RedirectResponse
    {
        $worker->ensureRunning();

        return back()->with('success', 'Avvio del motore richiesto. Premi Aggiorna tra qualche secondo per verificare l’avanzamento.');
    }
}
