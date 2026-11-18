<?php

namespace App\Documentation;

use Illuminate\Support\Str;

final class Manual
{
    public function entries(): array
    {
        $items = json_decode(file_get_contents(config('documentation.root').'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);

        return array_values(array_filter($items, fn (array $entry): bool => $entry['audience'] !== 'administrator' || bouncer()->hasPermission('configuration')
        ));
    }

    public function find(string $slug): array
    {
        foreach ($this->entries() as $entry) {
            if ($entry['slug'] === $slug) {
                return $entry;
            }
        }

        abort(404);
    }

    public function markdown(array $entry): string
    {
        $root = realpath(config('documentation.root'));
        $file = $root ? realpath($root.'/'.$entry['file']) : false;

        abort_unless($file && str_starts_with($file, $root.DIRECTORY_SEPARATOR) && is_file($file), 404);

        return file_get_contents($file);
    }

    public function html(array $entry): string
    {
        return Str::markdown($this->markdown($entry), ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }

    public function search(string $query): array
    {
        $query = mb_strtolower(trim($query));
        $entries = array_values(array_filter($this->entries(), fn (array $entry): bool => $query === '' ||
            str_contains(mb_strtolower($entry['title'].' '.$entry['summary'].' '.$this->markdown($entry)), $query)
        ));

        DocumentationTelemetry::record(event: 'search.completed', ctx: ['count' => count($entries)]);

        return $entries;
    }
}
