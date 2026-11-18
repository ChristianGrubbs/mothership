<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The id of the request that queued a status row's work (P1a, §3.6.11), so a failed row leads to its log lines.
 *
 * It is `http_request_id`, not `request_id`: dam_optimizations and dam_vectorizations already have a
 * `request_id`, the browser's idempotency key, which is not null and unique per admin. One name on all six
 * tables keeps the Operational health report and a person reading the tables on one column.
 */
return new class extends Migration
{
    private const TABLES = [
        'dam_optimizations',
        'dam_vectorizations',
        'dam_renditions',
        'dam_upload_batches',
        'dam_chunk_uploads',
        'dam_ai_index',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            if (! Schema::hasTable($name) || Schema::hasColumn($name, 'http_request_id')) {
                continue;
            }

            Schema::table($name, function (Blueprint $table): void {
                $table->string('http_request_id', 64)->nullable();
                $table->index('http_request_id');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            if (! Schema::hasTable($name) || ! Schema::hasColumn($name, 'http_request_id')) {
                continue;
            }

            // The index goes first: SQLite and SQL Server refuse to drop an indexed column.
            Schema::table($name, function (Blueprint $table): void {
                $table->dropIndex(['http_request_id']);
            });

            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn('http_request_id');
            });
        }
    }
};
