<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dam_ai_index', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('asset_id')->unique();
            $table->foreign('asset_id')->references('id')->on('dam_assets')->cascadeOnDelete();
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error', 120)->nullable();
            $table->char('content_sha256', 64)->nullable();
            $table->text('lexical_text')->nullable();
            $table->text('caption')->nullable();
            $table->text('visible_text')->nullable();
            $table->json('keywords')->nullable();
            $table->string('caption_model', 80)->nullable();
            $table->string('embed_model', 80)->nullable();
            $table->string('prompt_version', 20)->nullable();
            $table->timestamp('indexed_at')->nullable();
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('dam_ai_tags', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('asset_id');
            $table->foreign('asset_id')->references('id')->on('dam_assets')->cascadeOnDelete();
            $table->string('term', 64);
            $table->string('status', 16)->default('suggested');
            $table->unsignedInteger('decided_by')->nullable();
            $table->foreign('decided_by')->references('id')->on('admins')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['asset_id', 'term']);
        });

        Schema::create('dam_ai_vectors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('asset_id');
            $table->foreign('asset_id')->references('id')->on('dam_assets')->cascadeOnDelete();
            $table->string('kind', 16);
            $table->unsignedSmallInteger('page')->nullable();
            $table->longText('vector');
            $table->text('text')->nullable();
            $table->string('model', 80);
            $table->timestamps();
            $table->index(['asset_id', 'kind']);
        });

        $connection = DB::connection();

        if ($connection->getDriverName() === 'pgsql') {
            $grammar = $connection->getQueryGrammar();
            $table = $grammar->wrapTable('dam_ai_index');
            $connection->statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
            $connection->statement('CREATE EXTENSION IF NOT EXISTS unaccent');
            $connection->statement("ALTER TABLE {$table} ADD COLUMN search_tsv tsvector");
            $connection->statement(sprintf('CREATE INDEX %s ON %s USING GIN (search_tsv)', $grammar->wrap('dam_ai_index_search_tsv_index'), $table));
            $connection->statement(sprintf('CREATE INDEX %s ON %s USING GIN (lexical_text gin_trgm_ops)', $grammar->wrap('dam_ai_index_lexical_trgm_index'), $table));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_ai_vectors');
        Schema::dropIfExists('dam_ai_tags');
        Schema::dropIfExists('dam_ai_index');
    }
};
