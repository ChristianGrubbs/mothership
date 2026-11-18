<?php

namespace Mothership\DAM\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Services\Access\AccessComparator;
use Mothership\DAM\Services\Access\AccessListReader;
use Mothership\DAM\Services\Access\AccessListRebuilder;
use Mothership\DAM\Services\Access\AccessModel;

class AccessReport extends Command
{
    protected $signature = 'dam:access:report
        {--compare : Compare the grants answer with the lists answer for every active admin, folder and file; exit 1 on a failing difference}
        {--json : Print one JSON line; with --compare it also lists every differing pair by ids}';

    protected $description = 'Report the DAM access model and its access lists (read-only)';

    public function handle(AccessModel $model, AccessComparator $comparator): int
    {
        // The stored state beside the model reads use: `pending:<run>` names a switch that has not verified.
        $state = $model->raw();

        if (! $this->option('compare')) {
            $status = ['model' => $model->current(), 'state' => $state];

            foreach (AccessListRebuilder::LIST_TABLES as $table) {
                $status[$table] = DB::table($table)->count();
            }

            $status += [
                'everyone_group_id'      => DB::table('dam_groups')->where('system_key', AccessListReader::EVERYONE)->value('id'),
                'role_groups'            => DB::table('dam_groups')->where('system_key', 'like', 'role:%')->count(),
                'folders_without_owner'  => DB::table('dam_directories')->whereNull('created_by')->count(),
                'files_without_owner'    => DB::table('dam_assets')->whereNull('created_by')->count(),
            ];

            $this->option('json')
                ? $this->line(json_encode($status, JSON_THROW_ON_ERROR))
                : $this->table(['Item', 'Value'], collect($status)->map(fn ($value, $item) => [$item, $value ?? '-'])->values()->all());

            return self::SUCCESS;
        }

        $report = $comparator->compare(withPairs: (bool) $this->option('json'));

        if ($this->option('json')) {
            $this->line(json_encode(['model' => $model->current(), 'state' => $state] + $report, JSON_THROW_ON_ERROR));
        } else {
            $this->table(['Difference', 'Pairs', 'Verdict'], collect($report['differences'])
                ->map(fn ($pairs, $difference) => [$difference, $pairs, in_array($difference, AccessComparator::FAILING, true) ? 'fails the switch' : 'allowed'])
                ->values()
                ->all());
            $this->line("{$report['admins']} active admins, {$report['folders']} folders, {$report['files']} files: {$report['failures']} failing differences.");
            $this->line('Model: '.$model->current().' (state: '.($state ?? 'no row').').');
        }

        return $report['failures'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
