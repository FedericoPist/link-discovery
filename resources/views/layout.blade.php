<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>@yield('title', 'Panoramica') · Link Discovery</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
<a class="skip-link" href="#main">Vai al contenuto</a>
<aside class="sidebar">
    <a class="brand" href="{{ route('dashboard') }}" aria-label="Link Discovery, homepage">
        <span class="brand-icon"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m10 13 4-4m-5 7-1 1a4 4 0 0 1-6-6l4-4a4 4 0 0 1 6 0m0 10a4 4 0 0 0 6 0l4-4a4 4 0 0 0-6-6l-1 1" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></span>
        <span>Link Discovery<small>EXTERNAL LINK INTELLIGENCE</small></span>
    </a>
    <div class="nav-caption">WORKSPACE</div>
    <nav aria-label="Navigazione principale">
        <a class="nav-item {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}"><span aria-hidden="true">▦</span> Panoramica</a>
        <a class="nav-item {{ request()->routeIs('sites.*') ? 'active' : '' }}" href="{{ route('sites.create') }}"><span aria-hidden="true">＋</span> Aggiungi sito</a>
        <a class="nav-item" href="{{ route('dashboard') }}#recent"><span aria-hidden="true">↗</span> Scansioni</a>
    </nav>
    <div class="sidebar-note"><span class="status-dot"></span> Il controllo è tuo<p>Avvia una scansione quando vuoi. La ripetizione è sempre facoltativa.</p></div>
    <div class="sidebar-bottom"><span class="avatar">LD</span><div>Workspace locale<small>La tua ricerca, in un solo posto</small></div></div>
</aside>
<div class="workspace">
    <header class="topbar"><span>Workspace <span class="separator">/</span> <strong>@yield('title', 'Panoramica')</strong></span><span class="local-badge"><span class="status-dot"></span> Versione locale</span></header>
    <main id="main">
        @if(session('success'))<div class="notice success" role="status">{{ session('success') }}</div>@endif
        @if($errors->any())<div class="notice error" role="alert"><strong>Controlla i dati inseriti.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @yield('content')
    </main>
    <footer>Link Discovery <span>Scopri i collegamenti. Conserva il contesto.</span></footer>
</div>
</body>
</html>
