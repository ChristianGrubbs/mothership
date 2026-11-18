<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\CalendarAsset;
use Mothership\DAM\Models\CalendarEvent;
use Mothership\DAM\Models\CalendarTask;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\AssetFavoriteService;
use Mothership\DAM\Services\HomeWidgetRegistry;
use Mothership\User\Models\Admin;

/**
 * The Home calendar (docs/features/event-calendar.md): events and campaigns, the tasks on them and the
 * assets linked to them. Everyone who may use the calendar widget may add events, add and change tasks, assign them
 * (to anyone, to themselves or to nobody) and link assets. A linked asset is listed only to someone who may see it in
 * the Library, and only such an asset can be linked.
 */
class HomeCalendarController extends Controller
{
    private const MAX_RANGE_DAYS = 400;

    private const MINE_LIMIT = 50;

    public function __construct(private HomeWidgetRegistry $registry, private AssetFavoriteService $assets) {}

    /** Entries overlapping [from, to), with the counts a calendar cell shows. */
    public function events(Request $request): JsonResponse
    {
        $this->registry->allowed('calendar');
        $data = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after:from']]);
        $from = CarbonImmutable::parse($data['from']);
        $to = CarbonImmutable::parse($data['to']);
        abort_if($from->diffInDays($to) > self::MAX_RANGE_DAYS, 422, 'That range is too long.');

        $events = $this->entries()
            ->where('starts_at', '<', $to->format('Y-m-d H:i:s'))
            ->where(fn ($query) => $query->where('starts_at', '>=', $from->format('Y-m-d H:i:s'))->orWhere('ends_at', '>=', $from->format('Y-m-d H:i:s')))
            ->orderBy('starts_at')->orderBy('id')->limit(2000)->get();

        return $this->json(['events' => $this->summaries(events: $events)]);
    }

    /**
     * The signed-in person's unfinished tasks, soonest event first, and the tasks on coming events that nobody has.
     * Both lists stop at MINE_LIMIT rows; `totals` counts all of them, so the sentence on Home is right past the cap.
     */
    public function mine(): JsonResponse
    {
        $this->registry->allowed('calendar');
        $me = (int) auth('admin')->id();
        $visible = $this->entries()->select('dam_calendar_events.id');
        $open = fn () => CalendarTask::query()->where('dam_calendar_tasks.status', '!=', 'done')
            ->join('dam_calendar_events', 'dam_calendar_events.id', '=', 'dam_calendar_tasks.event_id')
            ->whereIn('dam_calendar_tasks.event_id', $visible);
        $own = fn () => $open()->where('dam_calendar_tasks.assignee_id', $me);
        $nobody = fn () => $open()->whereNull('dam_calendar_tasks.assignee_id')->where('dam_calendar_events.starts_at', '>=', now()->subDay());
        $rows = fn (Builder $tasks): Collection => $tasks->with(['event', 'assignee'])
            ->orderBy('dam_calendar_events.starts_at')->orderBy('dam_calendar_tasks.id')->select('dam_calendar_tasks.*')->limit(self::MINE_LIMIT)->get();

        // Both lists are rows of dam_calendar_tasks: the queries start at CalendarTask and select only its columns.
        /** @var Collection<int, CalendarTask> $mine */
        $mine = $rows($own());
        /** @var Collection<int, CalendarTask> $unassigned */
        $unassigned = $rows($nobody());
        $row = function (CalendarTask $task): array {
            $event = $this->eventOf(task: $task);

            return $this->task(task: $task) + ['event' => ['id' => $event->id, 'kind' => $event->kind, 'title' => $event->title, 'starts_at' => $this->stamp($event->starts_at)]];
        };

        return $this->json([
            'mine'       => $mine->map($row)->values(),
            'unassigned' => $unassigned->map($row)->values(),
            'totals'     => [
                'mine'       => $own()->count(),
                'overdue'    => $own()->whereDate('dam_calendar_tasks.due_on', '<', $this->today())->count(),
                'unassigned' => $nobody()->count(),
            ],
        ]);
    }

    /** The entry a task row belongs to. mine() joins it to every row, so a row without one is a broken row. */
    private function eventOf(CalendarTask $task): CalendarEvent
    {
        $event = $task->event;

        if (! $event instanceof CalendarEvent) {
            throw new \LogicException("Calendar task {$task->id} has no calendar entry.");
        }

        return $event;
    }

    public function show(int $event): JsonResponse
    {
        $this->registry->allowed('calendar');

        return $this->json($this->detail(event: $this->entry(id: $event)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->registry->allowed('calendar');
        $data = $this->eventInput(request: $request, creating: true);
        $event = CalendarEvent::query()->create($data + ['source' => CalendarEvent::SOURCE_MANUAL, 'created_by' => auth('admin')->id()]);

        return $this->json($this->detail(event: $event), 201);
    }

    public function update(Request $request, int $event): JsonResponse
    {
        $this->registry->allowed('calendar');
        $model = $this->entry(id: $event);
        $model->update($this->eventInput(request: $request, creating: false));

        return $this->json($this->detail(event: $model));
    }

    public function destroy(int $event): JsonResponse
    {
        $this->registry->allowed('calendar');
        $model = $this->entry(id: $event);
        abort_unless($this->superuser() || $model->created_by === (int) auth('admin')->id(), 403, 'Only the person who added this entry can delete it.');
        $model->delete();

        return $this->json(['deleted' => true]);
    }

    public function storeTask(Request $request, int $event): JsonResponse
    {
        $this->registry->allowed('calendar');
        $model = $this->entry(id: $event);
        $data = $this->taskInput(request: $request, event: $model, creating: true);
        $position = (int) CalendarTask::query()->where('event_id', $model->id)->max('position') + 1;
        CalendarTask::query()->create($data + ['event_id' => $model->id, 'position' => $position, 'created_by' => auth('admin')->id()]);

        return $this->json($this->detail(event: $model), 201);
    }

    public function updateTask(Request $request, int $task): JsonResponse
    {
        $this->registry->allowed('calendar');
        $model = CalendarTask::query()->findOrFail($task);
        $event = $this->entry(id: $model->event_id);
        $model->update($this->taskInput(request: $request, event: $event, creating: false));

        return $this->json($this->detail(event: $event));
    }

    public function destroyTask(int $task): JsonResponse
    {
        $this->registry->allowed('calendar');
        $model = CalendarTask::query()->findOrFail($task);
        $event = $this->entry(id: $model->event_id);

        DB::transaction(function () use ($model): void {
            $ids = CalendarTask::query()->where('parent_id', $model->id)->pluck('id')->push($model->id);
            // The assets stay linked to the event; only their place under this task goes.
            CalendarAsset::query()->whereIn('task_id', $ids)->update(['task_id' => null]);
            CalendarTask::query()->whereIn('id', $ids)->delete();
        });

        return $this->json($this->detail(event: $event));
    }

    /** Link assets to the event, or to one task or sub-item of it. Linking the same asset twice is a no-op. */
    public function linkAssets(Request $request, int $event): JsonResponse
    {
        $this->registry->allowed('calendar');
        $model = $this->entry(id: $event);
        $data = $request->validate([
            'asset_ids'   => ['required', 'array', 'min:1', 'max:100'],
            'asset_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
            'task_id'     => ['nullable', 'integer', Rule::exists('dam_calendar_tasks', 'id')->where('event_id', $model->id)],
        ]);
        $taskId = $data['task_id'] ?? null;
        // Only assets this person may see are linked; an id they may not see is skipped like a missing one.
        $visible = $this->assets->accessibleAssets()->whereIn('dam_assets.id', $data['asset_ids'])->pluck('dam_assets.id');
        $linked = CalendarAsset::query()->where('event_id', $model->id)->where(fn ($query) => $taskId === null ? $query->whereNull('task_id') : $query->where('task_id', $taskId))
            ->whereIn('asset_id', $visible)->pluck('asset_id')->all();

        foreach ($visible->diff($linked) as $assetId) {
            CalendarAsset::query()->create(['event_id' => $model->id, 'task_id' => $taskId, 'asset_id' => $assetId, 'added_by' => auth('admin')->id()]);
        }

        return $this->json($this->detail(event: $model));
    }

    public function unlinkAsset(int $link): JsonResponse
    {
        $this->registry->allowed('calendar');
        $model = CalendarAsset::query()->findOrFail($link);
        // A link to an asset this person may not see answers like a missing one.
        $this->assets->findAccessible(assetId: $model->asset_id);
        $event = $this->entry(id: $model->event_id);
        $model->delete();

        return $this->json($this->detail(event: $event));
    }

    /** The people a task can be given to. */
    public function people(): JsonResponse
    {
        $this->registry->allowed('calendar');

        $people = Admin::query()->where('status', 1)->orderBy('name')->limit(500)->get(['id', 'name'])->all();

        return $this->json(['people' => array_map(fn (Admin $admin): array => ['id' => $admin->id, 'name' => $admin->name], $people)]);
    }

    /** Library assets to link, newest first, optionally matching a name. */
    public function assetSearch(Request $request): JsonResponse
    {
        $this->registry->allowed('calendar');
        $term = mb_strtolower(mb_substr(trim((string) $request->string('q')), 0, 100));
        $query = $this->library()->withDisplayName();

        if ($term !== '') {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
            $fileName = DB::connection()->getQueryGrammar()->wrap('dam_assets.file_name');
            $query->where(fn ($where) => $where->whereRaw('LOWER('.$fileName.") LIKE ? ESCAPE '!'", [$like])
                ->orWhereRaw('LOWER('.Asset::displayNameSql().") LIKE ? ESCAPE '!'", [$like]));
        }

        $rows = $query->orderByDesc('dam_assets.created_at')->orderByDesc('dam_assets.id')->limit(24)->get();

        return $this->json(['assets' => $rows->map(fn (Asset $asset) => $this->asset(asset: $asset))->values()]);
    }

    /** @return array<string, mixed> */
    private function eventInput(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'title'       => [$required, 'string', 'max:200'],
            'kind'        => ['sometimes', Rule::in(CalendarEvent::KINDS)],
            'description' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'location'    => ['sometimes', 'nullable', 'string', 'max:200'],
            'category'    => ['sometimes', 'nullable', 'string', 'max:120'],
            'starts_at'   => [$required, 'date'],
            'ends_at'     => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_at'],
            'all_day'     => ['sometimes', 'boolean'],
            'status'      => ['sometimes', Rule::in([CalendarEvent::STATUS_CONFIRMED, CalendarEvent::STATUS_CANCELLED])],
        ]);

        // Wall-clock times: what was typed is what is stored, whatever zone the browser sent.
        foreach (['starts_at', 'ends_at'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = CarbonImmutable::parse(substr((string) $data[$key], 0, 19))->format('Y-m-d H:i:s');
            }
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function taskInput(Request $request, CalendarEvent $event, bool $creating): array
    {
        return $request->validate([
            'title'       => [$creating ? 'required' : 'sometimes', 'string', 'max:200'],
            'role'        => ['sometimes', 'nullable', 'string', 'max:60'],
            // Null leaves it unassigned. Anyone may assign anyone, themselves included.
            'assignee_id' => ['sometimes', 'nullable', 'integer', Rule::exists('admins', 'id')],
            'status'      => ['sometimes', Rule::in(CalendarTask::STATUSES)],
            'due_on'      => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            // One level: a sub-item's parent is a task of the same event that is not itself a sub-item.
            'parent_id'   => $creating
                ? ['sometimes', 'nullable', 'integer', Rule::exists('dam_calendar_tasks', 'id')->where('event_id', $event->id)->whereNull('parent_id')]
                : ['prohibited'],
        ]);
    }

    /** @return array<string, mixed> */
    private function detail(CalendarEvent $event): array
    {
        $tasks = CalendarTask::query()->with('assignee')->where('event_id', $event->id)->orderBy('position')->orderBy('id')->get();
        $links = CalendarAsset::query()->where('event_id', $event->id)->orderBy('id')->get();
        $visible = $links->isEmpty() ? collect() : $this->library()->withDisplayName()
            ->whereIn('dam_assets.id', $links->pluck('asset_id')->unique())->get()->keyBy('id');

        $summary = $this->summaries(events: collect([$event]))[0];

        return [
            'event' => $summary + [
                'description' => $event->description,
                'url'         => $event->url,
                'can_edit'    => true,
                'can_delete'  => $this->superuser() || $event->created_by === (int) auth('admin')->id(),
            ],
            'tasks'  => $tasks->map(fn (CalendarTask $task) => $this->task(task: $task))->values(),
            'assets' => array_map(
                fn (CalendarAsset $link): array => ['link_id' => $link->id, 'task_id' => $link->task_id] + $this->asset(asset: $visible[$link->asset_id]),
                $links->filter(fn (CalendarAsset $link) => $visible->has($link->asset_id))->values()->all(),
            ),
            // Links to assets this person may not see are counted, never described.
            'hidden_assets' => $links->reject(fn (CalendarAsset $link) => $visible->has($link->asset_id))->count(),
        ];
    }

    /**
     * @param  Collection<int, CalendarEvent>  $events
     * @return list<array<string, mixed>>
     */
    private function summaries(Collection $events): array
    {
        $ids = $events->pluck('id');
        $me = (int) auth('admin')->id();
        $tasks = $ids->isEmpty() ? collect() : CalendarTask::query()->whereIn('event_id', $ids)->get(['event_id', 'status', 'assignee_id'])->groupBy('event_id');
        // Counted like the Library counts: only the files this person may list.
        $assets = $ids->isEmpty() ? collect() : CalendarAsset::query()->whereIn('event_id', $ids)->whereIn('asset_id', $this->library()->select('dam_assets.id'))->selectRaw('event_id, COUNT(DISTINCT asset_id) AS total')->groupBy('event_id')->pluck('total', 'event_id');

        return array_map(function (CalendarEvent $event) use ($tasks, $assets, $me): array {
            $own = $tasks->get($event->id, collect());

            return [
                'id'         => $event->id,
                'source'     => $event->source,
                'kind'       => $event->kind,
                'title'      => $event->title,
                'location'   => $event->location,
                'category'   => $event->category,
                'starts_at'  => $this->stamp($event->starts_at),
                'ends_at'    => $event->ends_at ? $this->stamp($event->ends_at) : null,
                'all_day'    => $event->all_day,
                'status'     => $event->status,
                'tasks'      => $own->count(),
                'tasks_done' => $own->where('status', 'done')->count(),
                'unassigned' => $own->whereNull('assignee_id')->where('status', '!=', 'done')->count(),
                'mine'       => $own->where('assignee_id', $me)->where('status', '!=', 'done')->count(),
                'assets'     => (int) $assets->get($event->id, 0),
            ];
        }, $events->values()->all());
    }

    /** @return array<string, mixed> */
    private function task(CalendarTask $task): array
    {
        return [
            'id'        => $task->id,
            'parent_id' => $task->parent_id,
            'title'     => $task->title,
            'role'      => $task->role,
            'status'    => $task->status,
            'due_on'    => $task->due_on?->format('Y-m-d'),
            'assignee'  => $this->owner(task: $task),
        ];
    }

    /**
     * Who a task is given to.
     *
     * @return array{id: int|null, name: string}|null
     */
    private function owner(CalendarTask $task): ?array
    {
        return $task->assignee ? ['id' => $task->assignee->id, 'name' => $task->assignee->name] : null;
    }

    /** Today's date on the application clock, which is what a due date is compared with. */
    private function today(): string
    {
        return now()->format('Y-m-d');
    }

    /**
     * Every entry this person may see. Each read of the calendar tables starts here.
     *
     * @return Builder<CalendarEvent>
     */
    private function entries(): Builder
    {
        return CalendarEvent::query();
    }

    /** One entry, or 404 when it is missing. */
    private function entry(int $id): CalendarEvent
    {
        return $this->entries()->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function asset(Asset $asset): array
    {
        return [
            'id'        => $asset->id,
            'name'      => $asset->display_name,
            'type'      => HomeController::typeLabel($asset),
            'url'       => route('admin.dam.assets.edit', $asset->id),
            'thumbnail' => route('admin.dam.file.thumbnail', ['path' => urlencode($asset->path)]),
        ];
    }

    /**
     * The assets this person may list in the Library, as AssetFavoriteService::accessibleAssets() builds them.
     *
     * @return Builder<Asset>
     */
    private function library(): Builder
    {
        $query = Asset::query()->visibleCaptures();
        app(AssetAccessDecision::class)->scopeListable($query, AccessContext::library(AccessContext::PURPOSE_LIST));

        return $query;
    }

    /** Wall-clock, no zone: the browser shows it as written. */
    private function stamp(\DateTimeInterface $at): string
    {
        return $at->format('Y-m-d\TH:i');
    }

    private function superuser(): bool
    {
        return auth('admin')->user()?->role?->permission_type === 'all';
    }

    /** @param  array<string, mixed>  $data */
    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'private, no-store');
    }
}
