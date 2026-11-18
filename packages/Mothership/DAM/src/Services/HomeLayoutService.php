<?php

namespace Mothership\DAM\Services;

use Illuminate\Support\Facades\DB;

/**
 * Home is one fixed page: everyone gets the same sections, filled with the
 * widgets their role may use. Nothing is saved per person any more.
 */
class HomeLayoutService
{
    public function __construct(private HomeWidgetRegistry $registry) {}

    public function read(): array
    {
        $catalog = $this->registry->catalog();

        return [
            'widgets' => array_map(fn (array $widget): array => ['id' => $widget['id'], 'settings' => $widget['settings']], $catalog),
            'catalog' => $catalog,
            'links'   => $this->savedLinks(),
        ];
    }

    /**
     * The Quick links a person saved while Home was customizable. The dam_home_layouts rows are kept, never written,
     * so those shortcuts stay reachable; anything that is not a titled http(s) link is dropped.
     *
     * @return list<array{title: string, url: string}>
     */
    private function savedLinks(): array
    {
        $stored = DB::table('dam_home_layouts')->where('admin_id', auth('admin')->id())->value('widgets');
        $widgets = is_string($stored) ? json_decode($stored, true) : null;
        $links = [];
        foreach (is_array($widgets) ? $widgets : [] as $widget) {
            if (! is_array($widget) || ($widget['id'] ?? null) !== 'links') {
                continue;
            }
            foreach ((array) ($widget['settings']['links'] ?? []) as $link) {
                $title = is_array($link) ? trim((string) ($link['title'] ?? '')) : '';
                $url = is_array($link) ? (string) ($link['url'] ?? '') : '';
                if ($title !== '' && mb_strlen($title) <= 80 && preg_match('#^https?://#i', $url) === 1 && strlen($url) <= 2048) {
                    $links[] = ['title' => $title, 'url' => $url];
                }
            }
        }

        return array_slice($links, 0, 12);
    }
}
