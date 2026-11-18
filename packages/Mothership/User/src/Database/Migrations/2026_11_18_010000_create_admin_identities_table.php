<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Provider accounts linked to an admin, keyed by the provider's stable subject id. An admin can hold
     * one link per provider account; sign-in never links by email. Existing admins.sso_* pairs are copied
     * over so admins who already signed in with a provider keep working.
     */
    public function up(): void
    {
        Schema::create('admin_identities', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('admin_id');
            $table->string('provider', 64);
            $table->string('subject');
            $table->string('email_at_link')->nullable();
            $table->timestamp('linked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'subject'], 'admin_identities_provider_subject_unique');
            $table->index('admin_id');
            $table->foreign('admin_id')->references('id')->on('admins')->cascadeOnDelete();
        });

        if (! Schema::hasColumn('admins', 'sso_provider') || ! Schema::hasColumn('admins', 'sso_identifier')) {
            return;
        }

        $now = now();

        DB::table('admins')
            ->whereNotNull('sso_provider')
            ->whereNotNull('sso_identifier')
            ->orderBy('id')
            ->get(['id', 'email', 'sso_provider', 'sso_identifier'])
            ->each(function (object $admin) use ($now): void {
                DB::table('admin_identities')->insertOrIgnore([
                    'admin_id'      => $admin->id,
                    'provider'      => $admin->sso_provider,
                    'subject'       => $admin->sso_identifier,
                    'email_at_link' => $admin->email,
                    'linked_at'     => $now,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_identities');
    }
};
