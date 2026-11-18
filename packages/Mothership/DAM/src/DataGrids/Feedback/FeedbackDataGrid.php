<?php

declare(strict_types=1);

namespace Mothership\DAM\DataGrids\Feedback;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\FeedbackReport;
use Mothership\DataGrid\DataGrid;

/**
 * Settings → Feedback: every report, newest first, filterable by status, type,
 * reporter and severity. Only FeedbackAdminController serves it, after FeedbackAccess::canManage().
 */
class FeedbackDataGrid extends DataGrid
{
    protected $primaryColumn = 'id';

    protected $sortColumn = 'dam_feedback_reports.id';

    protected $sortOrder = 'desc';

    protected $itemsPerPage = 25;

    /** Where a report came from, by route-name prefix; the first match wins, so the longer prefixes come first. */
    private const PLACES = [
        'admin.dam.hub'          => 'hub',
        'admin.home'             => 'home',
        'admin.dam.feedback'     => 'feedback',
        'admin.settings'         => 'settings',
        'admin.configuration'    => 'settings',
        'admin.dam'              => 'library',
    ];

    public function prepareQueryBuilder()
    {
        $queryBuilder = DB::table('dam_feedback_reports')
            ->leftJoin('admins', 'admins.id', '=', 'dam_feedback_reports.admin_id')
            ->select(
                'dam_feedback_reports.id',
                'dam_feedback_reports.title',
                'dam_feedback_reports.type',
                'dam_feedback_reports.severity',
                'dam_feedback_reports.status',
                'dam_feedback_reports.admin_id as reporter',
                'admins.name as reporter_name',
                'dam_feedback_reports.hub_app',
                'dam_feedback_reports.route_name',
                'dam_feedback_reports.screenshot_file_object_id',
                'dam_feedback_reports.created_at',
            );

        $this->addFilter('id', 'dam_feedback_reports.id');
        $this->addFilter('title', 'dam_feedback_reports.title');
        $this->addFilter('type', 'dam_feedback_reports.type');
        $this->addFilter('severity', 'dam_feedback_reports.severity');
        $this->addFilter('status', 'dam_feedback_reports.status');
        $this->addFilter('reporter', 'dam_feedback_reports.admin_id');
        $this->addFilter('created_at', 'dam_feedback_reports.created_at');

        return $queryBuilder;
    }

    public function prepareColumns()
    {
        $this->addColumn([
            'index'      => 'id',
            'label'      => trans('dam::app.admin.feedback.triage.columns.id'),
            'type'       => 'integer',
            'searchable' => false,
            'filterable' => false,
            'sortable'   => true,
        ]);

        $this->addColumn([
            'index'      => 'title',
            'label'      => trans('dam::app.admin.feedback.triage.columns.report'),
            'type'       => 'string',
            'searchable' => true,
            'filterable' => false,
            'sortable'   => true,
        ]);

        $this->addColumn([
            'index'      => 'type',
            'label'      => trans('dam::app.admin.feedback.triage.columns.type'),
            'type'       => 'dropdown',
            'searchable' => false,
            'filterable' => true,
            'sortable'   => true,
            'options'    => ['type' => 'basic', 'params' => ['options' => $this->options(prefix: 'types', values: FeedbackReport::TYPES)]],
        ]);

        $this->addColumn([
            'index'      => 'severity',
            'label'      => trans('dam::app.admin.feedback.triage.columns.severity'),
            'type'       => 'dropdown',
            'searchable' => false,
            'filterable' => true,
            'sortable'   => true,
            'options'    => ['type' => 'basic', 'params' => ['options' => $this->options(prefix: 'severities', values: FeedbackReport::SEVERITIES)]],
        ]);

        $this->addColumn([
            'index'      => 'status',
            'label'      => trans('dam::app.admin.feedback.triage.columns.status'),
            'type'       => 'dropdown',
            'searchable' => false,
            'filterable' => true,
            'sortable'   => true,
            'options'    => ['type' => 'basic', 'params' => ['options' => $this->options(prefix: 'statuses', values: FeedbackReport::STATUSES)]],
        ]);

        $this->addColumn([
            'index'      => 'reporter',
            'label'      => trans('dam::app.admin.feedback.triage.columns.reporter'),
            'type'       => 'dropdown',
            'searchable' => false,
            'filterable' => true,
            'sortable'   => false,
            'options'    => ['type' => 'basic', 'params' => ['options' => $this->reporterOptions()]],
        ]);

        $this->addColumn([
            'index'      => 'created_at',
            'label'      => trans('dam::app.admin.feedback.triage.columns.reported'),
            'type'       => 'date_range',
            'searchable' => false,
            'filterable' => true,
            'sortable'   => true,
        ]);
    }

    /** Adds what the rows show but no column filters on: the reporter's name, where the report came from. */
    public function formatData(): array
    {
        $data = parent::formatData();

        foreach ($data['records'] as $record) {
            $record->reporter_name = $record->reporter_name ?? trans('dam::app.admin.feedback.triage.deleted-user');
            $record->where = $this->place(hubApp: $record->hub_app, routeName: $record->route_name);
            $record->has_screenshot = $record->screenshot_file_object_id !== null;
            // The column is a zone-less timestamp in the app's zone; the browser formats it in the reader's own.
            $record->created_at = $record->created_at === null ? null : Carbon::parse($record->created_at, config('app.timezone'))->toIso8601String();
            unset($record->screenshot_file_object_id);
        }

        return $data;
    }

    /**
     * @param  list<string>  $values
     * @return list<array{label: string, value: string}>
     */
    private function options(string $prefix, array $values): array
    {
        return array_map(fn (string $value): array => ['label' => trans('dam::app.admin.feedback.triage.'.$prefix.'.'.$value), 'value' => $value], $values);
    }

    /**
     * Everyone who has sent a report, by name.
     *
     * @return list<array{label: string, value: int}>
     */
    private function reporterOptions(): array
    {
        return DB::table('admins')
            ->whereIn('id', DB::table('dam_feedback_reports')->whereNotNull('admin_id')->select('admin_id'))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (object $admin): array => ['label' => (string) $admin->name, 'value' => (int) $admin->id])
            ->values()
            ->all();
    }

    private function place(?string $hubApp, ?string $routeName): ?string
    {
        // A report sent from a Creative Hub app names it by its stored id (the in-app Hub and its registry are gone).
        if ($hubApp !== null && $hubApp !== '') {
            return $hubApp;
        }

        foreach (self::PLACES as $prefix => $place) {
            if ($routeName !== null && str_starts_with($routeName, $prefix)) {
                return trans('dam::app.admin.feedback.triage.places.'.$place);
            }
        }

        return null;
    }
}
