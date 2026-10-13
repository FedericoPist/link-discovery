# Link Discovery

Applicazione locale Laravel per trovare link esterni non funzionanti, conservarne anchor text e pagina sorgente, H1, title, meta description e altri meta tag.

## Avvio

Requisiti: PHP 8.4.1 o successivo (sviluppata con PHP 8.5), Composer, estensioni cURL, DOM, SimpleXML, Intl e SQLite. Non servono Node.js, npm o un processo di build frontend.

```bash
composer install
cp .env.example .env  # solo alla prima installazione, se .env non esiste
php artisan key:generate
php artisan migrate
composer dev
```

Apri **http://127.0.0.1:8015**. In questa cartella le dipendenze, `.env` e il database sono già pronti: basta `composer dev`.

Il comando avvia server web, motore della coda e scheduler. Ctrl+C li arresta. In ambiente locale, anche il pulsante di scansione avvia automaticamente il motore se manca: non è necessario lanciare un comando separato. Il motore avviato dalla pagina si arresta dopo 60 secondi senza lavori, resta attivo in presenza di verifiche ritardate e riparte alla scansione successiva. Un lock impedisce di avviare due motori locali contemporaneamente. Il processo deve avere accesso a DNS e HTTP/HTTPS esterni.

Per avviare esplicitamente un motore persistente:

```bash
php artisan discovery:work --idle-seconds=0
```

Lo scheduler può essere eseguito separatamente con `php artisan schedule:work`. L’avvio automatico del motore non crea nuove scansioni e non abilita le scansioni periodiche. È limitato ad `APP_ENV=local`, coda database; può essere disattivato con `CRAWLER_AUTO_START_WORKER=false`. Se il motore manca, il report lo segnala immediatamente e offre il pulsante **Avvia elaborazione**. Il log del motore automatico è `storage/logs/worker.log`.

## Utilizzo

1. Aggiungi un sito, il numero massimo di pagine e la profondità.
2. Lascia **Solo manuale** se non vuoi scansioni ricorrenti. Il salvataggio di un nuovo sito avvia la prima scansione.
3. Apri il report e premi **Aggiorna** per leggere l’avanzamento. Nessun JavaScript, polling o refresh automatico della pagina.
4. Filtra errori HTTP, esiti specifici oppure tutti i link. Espandi **Dettagli** per i metadati.
5. Esporta CSV: l’esportazione usa gli stessi filtri della tabella e include tutte le righe corrispondenti, anche oltre la pagina corrente.
6. Puoi interrompere, riprendere oppure avviare una nuova scansione conservando lo storico.

La frequenza giornaliera, settimanale o mensile è **facoltativa e disattivata di default**. Le nuove pianificazioni partono dopo un intervallo dal salvataggio. Disabilitarle cancella la prossima esecuzione; una scansione già in corso si interrompe dal relativo report. Le date dell’interfaccia sono nel fuso Europe/Rome, il database usa UTC. I tentativi di conferma degli errori transitori appartengono alla stessa scansione e non sono scansioni periodiche.

## Motore

- Laravel 13, Blade e CSS statico, Laravel HTTP Client / Guzzle, Symfony DomCrawler, parser robots.txt di Spatie.
- SQLite e code su database. Le migration sono utilizzabili anche con PostgreSQL/MySQL configurando `.env`.
- Un job lavora una pagina o una destinazione alla volta. Il singolo worker predefinito privilegia velocità controllata e ripresa dopo errori.
- Esplorazione tramite link HTML, sitemap.xml, indici sitemap e sitemap dichiarate in robots.txt (solo nel perimetro del sito).
- Ogni destinazione esterna è deduplicata per scansione; ogni occorrenza conserva anchor, href originale, posizione e rel.
- Snapshot delle impostazioni e dei metadati per scansione. Una modifica alle impostazioni si applica solo alle scansioni successive.
- H1 multipli conservati; per i link costituiti da immagini si usa il testo alt quando il testo è vuoto.
- GET con redirect manuali (massimo 5), timeout, pausa minima per origine e rispetto di robots.txt e crawl-delay.
- 404/410: mancanti; 401/403: accesso negato; 429: limite di richieste; 5xx: server; DNS/TLS/timeout separati dai codici HTTP.
- Un secondo tentativo, dopo almeno 30 secondi, per 5xx, 429 ed errori di rete. Retry-After viene rispettato: se richiede più di 5 minuti viene conservato l’esito senza anticipare la richiesta.
- Difesa SSRF: porte 80/443, URL HTTP(S), niente credenziali nell’URL, indirizzi pubblici verificati e risoluzione DNS fissata per ogni richiesta/redirect. I proxy di ambiente sono disabilitati per non aggirare il controllo della destinazione.
- Protezione CSRF, escape HTML, Content Security Policy senza script, neutralizzazione delle formule nell’export CSV.

## Limiti di questa prima versione

- Workspace **solo locale**, senza account: un middleware accetta esclusivamente connessioni loopback. Prima di esporlo in rete servono autenticazione e autorizzazione.
- Non esegue JavaScript del sito analizzato e non rileva soft 404 che rispondono con HTTP 200.
- robots.txt, anti-bot, autenticazione, timeout e limiti di scansione possono impedire una copertura completa. Gli esiti non verificabili sono separati dai link mancanti. Una pagina interna non leggibile viene segnalata e non ritentata automaticamente.
- Gli altri sottodomini sono opzionali; www e dominio senza www sono trattati come lo stesso sito. I parametri URL interni sono esclusi per default, eccetto l’URL iniziale; quelli esterni sono preservati.
- Massimo 5.000 pagine per scansione, 10 sitemap, 10.000 destinazioni esterne e 3 MB per documento. Sitemap compresse non supportate.
- La scoperta delle pagine precede la verifica dei link esterni. Le statistiche possono crescere mentre la scansione procede.
- Il codice considera le risposte ricevute dal crawler: un 403 non prova che un link sia irraggiungibile per una persona.
- Un robots.txt assente (HTTP 404/410) non impedisce la scansione. Gli errori di lettura sono distinti dai divieti Disallow e riportano la causa effettiva. La cache dura 30 minuti per risposte valide o file assenti, 60 secondi per errori temporanei; per HTTP 429 viene rispettato anche Retry-After.
- Nelle impostazioni di ogni sito è disponibile **Prosegui se robots.txt restituisce un errore 5xx**, disattivato di default. Se abilitato, il crawler registra un avviso nelle pagine e nel CSV e continua solo sul sito in esame. Non ignora i Disallow espliciti, i 401/403/429, gli errori di rete o le regole dei siti esterni; ricontrolla il robots.txt dopo la scadenza della cache.
- Se nessuna pagina HTML è stata letta, la scansione termina con stato Errore e una spiegazione. Per applicare impostazioni modificate, avvia una nuova scansione: le precedenti conservano le loro impostazioni originali.

## Test

```bash
php artisan test
# Prova aggiuntiva che richiede Internet:
php artisan test tests/Network/LiveNetworkTest.php
vendor/bin/pint --format agent
```

La suite predefinita usa un database SQLite in memoria e HTTP simulato; non modifica i dati del workspace. Verifica crawling, deduplicazione, metadati, sitemap, robots.txt, redirect, retry, pianificazioni, cancellazione/ripresa, filtri, CSV e controlli degli URL.

Il database locale è `database/database.sqlite`. Per un backup, arresta i processi e copia questo file insieme a `.env` in un luogo sicuro.
