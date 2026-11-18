<?php

namespace Mothership\DAM\Services\Access;

use Illuminate\Support\Facades\DB;
use Mothership\User\Models\Admin;

/**
 * The proof behind the switch (ADR 0010): for every active admin and every folder and file, today's
 * answer (LegacyGrantReader) against the lists' answer (AccessListReader). Two differences are expected
 * and only listed: an owner gaining their own item, and a folder gained as a path only. Every other
 * difference fails. Folders and files are streamed, and one admin's answers are held at a time.
 */
class AccessComparator
{
    /** Differences the switch accepts. */
    public const ALLOWED = ['folder_gained_by_owner', 'folder_gained_as_path', 'file_gained_by_owner'];

    /** Differences that stop the switch. */
    public const FAILING = ['folder_lost', 'folder_path_lost', 'folder_gained', 'file_lost', 'file_gained'];

    /**
     * @param  bool  $withPairs  also return every differing (admin, item) pair, by ids only
     * @return array{admins: int, folders: int, files: int, differences: array<string, int>, failures: int, duration_ms: int, failing?: list<array>, allowed?: list<array>}
     */
    public function compare(bool $withPairs = false): array
    {
        $started = microtime(true);
        $legacy = new LegacyGrantReader;
        $lists = new AccessListReader;
        $differences = array_fill_keys([...self::FAILING, ...self::ALLOWED], 0);
        $pairs = ['failing' => [], 'allowed' => []];
        $admins = 0;

        // Active admins only: the lists deny a disabled admin outright, and a disabled account cannot sign in.
        foreach (Admin::query()->where('status', true)->with('role')->lazyById(100) as $admin) {
            $admins++;
            $before = $this->answers(reader: $legacy, admin: $admin);
            $after = $this->answers(reader: $lists, admin: $admin);

            // Both readers let this admin reach everything, so no item can differ.
            if ($before['all'] && $after['all']) {
                continue;
            }

            foreach (['folder' => 'dam_directories', 'file' => 'dam_assets'] as $item => $table) {
                foreach (DB::table($table)->select(['id', 'created_by'])->lazyById(1000) as $row) {
                    $id = (int) $row->id;
                    $owned = (int) $row->created_by === (int) $admin->id;
                    $difference = $item === 'folder'
                        ? $this->folderDifference(before: $before, after: $after, id: $id, owned: $owned)
                        : $this->fileDifference(before: $before, after: $after, id: $id, owned: $owned);

                    if ($difference === null) {
                        continue;
                    }

                    $differences[$difference]++;

                    if ($withPairs) {
                        $pairs[in_array($difference, self::FAILING, true) ? 'failing' : 'allowed'][] = [
                            'admin_id'   => (int) $admin->id,
                            'item'       => $item,
                            'id'         => $id,
                            'difference' => $difference,
                        ];
                    }
                }
            }
        }

        $report = [
            'admins'      => $admins,
            'folders'     => DB::table('dam_directories')->count(),
            'files'       => DB::table('dam_assets')->count(),
            'differences' => $differences,
            'failures'    => array_sum(array_intersect_key($differences, array_flip(self::FAILING))),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ];

        return $withPairs ? $report + $pairs : $report;
    }

    /**
     * One admin's answers under one reader, as id sets; `all` stands for a bypass that reaches everything.
     *
     * @return array{all: bool, access: array<int, true>, view: array<int, true>, files: array<int, true>}
     */
    protected function answers(LegacyGrantReader|AccessListReader $reader, Admin $admin): array
    {
        if ($reader->bypass(admin: $admin)) {
            return ['all' => true, 'access' => [], 'view' => [], 'files' => []];
        }

        // The listing filter itself, so the comparison covers the query every index runs.
        $files = DB::table('dam_assets');
        $reader->applyAssetDirectoryGrantFilter(admin: $admin, query: $files, column: 'dam_assets.id');

        return [
            'all'    => false,
            'access' => array_fill_keys($reader->directlyGrantedIds(admin: $admin), true),
            'view'   => array_fill_keys($reader->viewableIds(admin: $admin), true),
            'files'  => array_fill_keys($files->pluck('dam_assets.id')->map(fn ($id) => (int) $id)->all(), true),
        ];
    }

    protected function folderDifference(array $before, array $after, int $id, bool $owned): ?string
    {
        $had = $before['all'] || isset($before['access'][$id]);
        $has = $after['all'] || isset($after['access'][$id]);

        if ($had !== $has) {
            return $had ? 'folder_lost' : ($owned ? 'folder_gained_by_owner' : 'folder_gained');
        }

        $saw = $before['all'] || isset($before['view'][$id]);
        $sees = $after['all'] || isset($after['view'][$id]);

        if ($saw === $sees) {
            return null;
        }

        // Access is the same on both sides here, so the folder is seen, or no longer seen, as a path only.
        return $saw ? 'folder_path_lost' : 'folder_gained_as_path';
    }

    protected function fileDifference(array $before, array $after, int $id, bool $owned): ?string
    {
        $had = $before['all'] || isset($before['files'][$id]);
        $has = $after['all'] || isset($after['files'][$id]);

        if ($had === $has) {
            return null;
        }

        return $had ? 'file_lost' : ($owned ? 'file_gained_by_owner' : 'file_gained');
    }
}
