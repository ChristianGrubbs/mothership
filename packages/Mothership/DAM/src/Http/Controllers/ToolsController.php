<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers;

use Carbon\CarbonImmutable;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Services\Home\HomeToolUses;
use Mothership\DAM\Services\HomeWidgetRegistry;

/**
 * The Tools page (a tile for each tool this account may open) and the tool frame (one tool, full screen, under the
 * top bar every tool shares). The tool's own page is still served by HomeToolController::file.
 */
class ToolsController extends Controller
{
    public function index(HomeWidgetRegistry $registry, HomeToolUses $uses)
    {
        $admin = auth('admin')->user();
        $now = now()->toImmutable()->setTimezone($admin->timezone ?: config('app.timezone'));
        $lastUsed = $uses->lastUsed(adminId: (int) $admin->id);

        $tiles = array_map(
            fn (array $tool): array => $tool + $this->standing(tool: $tool, lastUsed: $lastUsed[$tool['id']] ?? null, now: $now),
            $registry->tools(),
        );

        return view('dam::tools.index', ['tiles' => $tiles]);
    }

    public function show(string $widget, HomeWidgetRegistry $registry)
    {
        $definition = $registry->allowed($widget);
        abort_unless($definition['kind'] === 'tool' && isset($definition['entry']), 404);

        return view('dam::tools.frame', [
            'id'      => $widget,
            'tool'    => $definition,
            'toolUrl' => route('admin.home.tool', ['widget' => $widget, 'path' => $definition['entry']]),
        ]);
    }

    /**
     * What a tile's footer says, and its lamp: 'idle' on a tool that needs set-up, 'new' on a tool added lately
     * that this person has not opened, and none once there is a day to show.
     *
     * @return array{status: string, lamp: ?string}
     */
    private function standing(array $tool, ?CarbonImmutable $lastUsed, CarbonImmutable $now): array
    {
        if ($tool['url'] === null) {
            return ['status' => trans('dam::app.admin.dam.tools.needs-setup'), 'lamp' => 'idle'];
        }
        if ($lastUsed !== null) {
            return ['status' => $this->usedOn(day: $lastUsed->setTimezone($now->getTimezone()), now: $now), 'lamp' => null];
        }

        $added = $tool['added'] === null ? null : CarbonImmutable::parse($tool['added'], $now->getTimezone());
        $new = $added !== null && $added->addDays((int) config('dam-home.tools.new_days'))->greaterThan($now);

        return $new
            ? ['status' => trans('dam::app.admin.dam.tools.new'), 'lamp' => 'new']
            : ['status' => trans('dam::app.admin.dam.tools.never-used'), 'lamp' => null];
    }

    private function usedOn(CarbonImmutable $day, CarbonImmutable $now): string
    {
        if ($day->isSameDay($now)) {
            return trans('dam::app.admin.dam.tools.used-today');
        }
        if ($day->isSameDay($now->subDay())) {
            return trans('dam::app.admin.dam.tools.used-yesterday');
        }

        return trans('dam::app.admin.dam.tools.used-on', [
            'day' => $day->isoFormat($day->isSameYear($now) ? 'ddd D MMM' : 'D MMM YYYY'),
        ]);
    }
}
