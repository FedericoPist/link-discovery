<?php

namespace App\Providers;

use App\Services\LocalWorker;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(Looping::class, function (Looping $event): void {
            if ($event->connectionName === config('queue.default') && $event->queue === 'default') {
                app(LocalWorker::class)->heartbeat();
            }
        });
        View::composer(['dashboard', 'scan'], function (\Illuminate\View\View $view): void {
            $view->with('worker', app(LocalWorker::class)->status());
        });
    }
}
