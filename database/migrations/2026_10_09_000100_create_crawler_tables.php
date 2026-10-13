<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('url');
            $table->string('host');
            $table->unsignedInteger('max_pages')->default(200);
            $table->unsignedInteger('max_depth')->default(5);
            $table->boolean('include_subdomains')->default(false);
            $table->boolean('include_query')->default(false);
            $table->string('schedule_frequency')->default('manual');
            $table->timestamp('next_scan_at')->nullable()->index();
            $table->timestamps();
        });
        Schema::create('scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('queued')->index();
            $table->string('trigger')->default('manual');
            $table->json('settings');
            $table->boolean('limit_reached')->default(false);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamps();
        });
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_id')->constrained()->cascadeOnDelete();
            $table->text('url');
            $table->char('url_hash', 64);
            $table->text('final_url')->nullable();
            $table->string('kind')->default('html');
            $table->string('status')->default('pending');
            $table->unsignedInteger('depth')->default(0);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('title')->nullable();
            $table->json('h1')->nullable();
            $table->text('meta_description')->nullable();
            $table->json('meta')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
            $table->unique(['scan_id', 'url_hash']);
            $table->index(['scan_id', 'status']);
        });
        Schema::create('link_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_id')->constrained()->cascadeOnDelete();
            $table->text('url');
            $table->char('url_hash', 64);
            $table->string('host');
            $table->string('classification')->default('pending')->index();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('final_url')->nullable();
            $table->json('redirects')->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('retry_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
            $table->unique(['scan_id', 'url_hash']);
        });
        Schema::create('link_occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->foreignId('link_check_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->text('href');
            $table->text('anchor_text');
            $table->text('rel')->nullable();
            $table->timestamps();
            $table->unique(['page_id', 'position']);
        });
    }

    public function down(): void
    {
        foreach (['link_occurrences', 'link_checks', 'pages', 'scans', 'sites'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
