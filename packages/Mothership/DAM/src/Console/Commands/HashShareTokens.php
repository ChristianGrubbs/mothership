<?php

namespace Mothership\DAM\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Support\ShareLog;

/**
 * P2 S5a: re-stores every legacy plaintext share token as a hash plus an encrypted copy (what a new link stores), and
 * scrubs the plaintext tokens the share audit trail recorded before tokens were excluded from it. A legacy link keeps
 * working throughout: the hash of its URL token is what it is looked up by afterwards.
 */
class HashShareTokens extends Command
{
    /** Every form of the token a share audit may hold; S5a excludes all three from new audits. */
    private const TOKEN_KEYS = ['token', 'token_hash', 'token_encrypted'];

    protected $signature = 'dam:shares:hash-tokens
        {--apply : Convert the legacy rows and scrub the audit trail; without it, only report the counts}';

    protected $description = 'Re-store legacy plaintext share tokens hashed and encrypted, and scrub plaintext tokens from the share audit trail. Dry-run unless --apply. Run once at deploy; never scheduled';

    protected $help = <<<'HELP'
        Without --apply this only counts:
          - legacy links: dam_shares rows with a plaintext token and no token_hash;
          - audit rows: share audits whose old or new values hold a token.

        With --apply, each legacy row is re-stored in one conditional write (token_hash and token_encrypted set,
        token cleared, only while token_hash is still empty), and the token keys are removed from those audit
        rows. Rerunning is safe: a converted row or a scrubbed audit no longer matches.

        Every run appends to storage/logs/dam-shares.jsonl. Encryption uses APP_KEY: run it with the production
        key, since a link encrypted under another key cannot be shown to its creator.
        HELP;

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $legacy = $this->legacyRows()->count();
        $audits = $this->auditRowsWithToken();

        if (! $apply) {
            $this->table(['What', 'Count'], [['Legacy links (plaintext token)', $legacy], ['Share audit rows holding a token', count($audits)]]);
            $this->line('Dry run: nothing was written. Rerun with --apply to convert and scrub.');
            ShareLog::write('share_tokens.dry_run', ['legacy' => $legacy, 'audits' => count($audits)]);

            return self::SUCCESS;
        }

        [$converted, $skipped] = $this->convert();
        $scrubbed = $this->scrub($audits);

        $this->table(['What', 'Count'], [['Legacy links converted', $converted], ['Changed meanwhile, left alone', $skipped], ['Audit rows scrubbed', $scrubbed]]);
        ShareLog::write('share_tokens.applied', ['converted' => $converted, 'skipped' => $skipped, 'scrubbed' => $scrubbed]);

        return self::SUCCESS;
    }

    private function legacyRows(): Builder
    {
        return DB::table('dam_shares')->whereNull('token_hash')->whereNotNull('token');
    }

    /**
     * @return array{0: int, 1: int} converted, and skipped because the row gained a hash meanwhile (first use or
     *                               reissue)
     */
    private function convert(): array
    {
        $converted = 0;
        $skipped = 0;

        $this->legacyRows()->select(['id', 'token'])->chunkById(500, function ($rows) use (&$converted, &$skipped) {
            foreach ($rows as $row) {
                $updated = DB::table('dam_shares')
                    ->where('id', $row->id)
                    ->whereNull('token_hash')
                    ->update(Share::tokenColumns((string) $row->token));

                $updated === 1 ? $converted++ : $skipped++;
            }
        });

        return [$converted, $skipped];
    }

    /**
     * The share audit rows whose old or new values hold a token key. LIKE narrows the read portably; decoding decides.
     *
     * @return array<int, array{old: ?array, new: ?array}> audit id => decoded values
     */
    private function auditRowsWithToken(): array
    {
        $found = [];

        $this->audits()
            ->where('auditable_type', (new Share)->getMorphClass())
            ->where(function ($query) {
                foreach (self::TOKEN_KEYS as $key) {
                    $query->orWhere('old_values', 'like', '%"'.$key.'"%')->orWhere('new_values', 'like', '%"'.$key.'"%');
                }
            })
            ->select(['id', 'old_values', 'new_values'])
            ->orderBy('id')
            ->chunk(500, function ($rows) use (&$found) {
                foreach ($rows as $row) {
                    $old = is_string($row->old_values) ? json_decode($row->old_values, true) : null;
                    $new = is_string($row->new_values) ? json_decode($row->new_values, true) : null;

                    if ($this->holdsToken($old) || $this->holdsToken($new)) {
                        $found[(int) $row->id] = ['old' => is_array($old) ? $old : null, 'new' => is_array($new) ? $new : null];
                    }
                }
            });

        return $found;
    }

    /** @param array<int, array{old: ?array, new: ?array}> $audits */
    private function scrub(array $audits): int
    {
        $scrubbed = 0;

        foreach ($audits as $id => $values) {
            $changes = [];

            foreach (['old' => 'old_values', 'new' => 'new_values'] as $side => $column) {
                if ($this->holdsToken($values[$side])) {
                    $changes[$column] = json_encode(array_diff_key($values[$side], array_flip(self::TOKEN_KEYS)), JSON_THROW_ON_ERROR);
                }
            }

            $scrubbed += $this->audits()->where('id', $id)->update($changes);
        }

        return $scrubbed;
    }

    private function holdsToken(mixed $values): bool
    {
        return is_array($values) && array_intersect_key($values, array_flip(self::TOKEN_KEYS)) !== [];
    }

    private function audits(): Builder
    {
        return DB::connection(config('audit.drivers.database.connection'))->table(config('audit.drivers.database.table') ?? 'audits');
    }
}
