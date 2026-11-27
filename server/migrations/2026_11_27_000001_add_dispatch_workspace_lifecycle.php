<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dispatch workspace: manifests that are published to drivers, and plan runs
 * that remember what the Orchestrator could not place.
 *
 * Manifests gain `published_at` (when drivers could first see the route) and
 * `publish_at` (a publish booked for later; a queued job carries it out).
 * Driver-facing endpoints now return only manifests that were published.
 *
 * Existing manifests are left alone. Every manifest committed before this
 * migration is a `draft`, so drivers stop seeing those routes until a
 * dispatcher publishes them. That is deliberate: there is no record of which
 * drafts a dispatcher meant drivers to run, and guessing would put stale
 * routes in front of drivers.
 *
 * `orchestration_runs` keeps one row per plan run: its scope, the engine that
 * ran, its assignments and the reason each order was left unplaced.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('manifests', function (Blueprint $table) {
            if (!Schema::hasColumn('manifests', 'published_at')) {
                $table->timestamp('published_at')->nullable()->after('scheduled_date');
            }
            if (!Schema::hasColumn('manifests', 'publish_at')) {
                $table->timestamp('publish_at')->nullable()->index()->after('published_at');
            }
        });

        if (Schema::hasTable('orchestration_runs')) {
            return;
        }

        Schema::create('orchestration_runs', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->string('public_id', 64)->unique()->nullable();
            $table->char('company_uuid', 36)->nullable()->index();
            $table->char('created_by_uuid', 36)->nullable();
            $table->string('mode', 50)->nullable();
            $table->string('engine', 100)->nullable();
            $table->date('scheduled_date')->nullable()->index();
            $table->json('order_ids')->nullable();
            $table->json('assignments')->nullable();
            $table->json('unassigned')->nullable();
            $table->json('unassigned_reasons')->nullable();
            $table->json('summary')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orchestration_runs');

        Schema::table('manifests', function (Blueprint $table) {
            foreach (['publish_at', 'published_at'] as $column) {
                if (Schema::hasColumn('manifests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
