<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P5c (D8): the AI vocabulary moves from configuration to a table an admin edits (Settings, Library, AI vocabulary).
 * Seeded here from config('ai-vocabulary.terms'), which stays the seed and the fallback while the table is empty.
 *
 * - `term` is the key the AI bridge is sent and answers with; it never changes once created.
 * - `label` is the shared tag name an accepted suggestion becomes, unless `maps_to_tag_id` names an existing tag.
 * - `synonyms` (JSON list) are other words the bridge may answer with for the same term.
 * - A disabled term is not sent to the bridge and its open suggestions leave the review queue; tags people already
 *   accepted stay.
 *
 * The only foreign key is the mapped tag's, set to null when that tag is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dam_ai_vocabulary', function (Blueprint $table): void {
            $table->id();
            $table->string('term', 64)->unique();
            $table->string('label', 191);
            $table->boolean('enabled')->default(true);
            $table->json('synonyms')->nullable();
            $table->foreignId('maps_to_tag_id')->nullable()->constrained('dam_tags')->nullOnDelete();
            $table->timestamps();
        });

        $now = now();
        $rows = [];

        foreach ((array) config('ai-vocabulary.terms') as $term => $label) {
            $rows[] = ['term' => (string) $term, 'label' => (string) $label, 'enabled' => true, 'synonyms' => null, 'created_at' => $now, 'updated_at' => $now];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('dam_ai_vocabulary')->insert($chunk);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_ai_vocabulary');
    }
};
