<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Organization;

use Mothership\DAM\Models\Label;
use Mothership\DAM\Models\SavedSearch;

/**
 * P4: the organization half of one Library listing, resolved for its viewer: the label, board or section being
 * browsed (a container, which lists files only and can sort by manual position), and the label, favorites and
 * orientation filters. Built by LibraryOrganizationFilters::resolve(), which has already refused (404) a board or
 * label the viewer cannot see.
 */
final class OrganizationScope
{
    /** @param  array<int, int>  $labelIds */
    public function __construct(
        public readonly ?int $viewerId = null,
        public readonly array $labelIds = [],
        public readonly string $labelsMode = LibraryOrganizationFilters::MODE_ALL,
        public readonly bool $favorites = false,
        public readonly ?string $orientation = null,
        public readonly ?Label $label = null,
        public readonly ?SavedSearch $section = null,
        public readonly bool $matchesNothing = false,
    ) {}

    /** Browsing a label, board or section rather than a folder: files only, and a manual order to sort by. */
    public function isContainer(): bool
    {
        return $this->label !== null || $this->section !== null;
    }

    /** The section's admin-set default sort, as [sort_by, sort_order], when browsing a section that has one. */
    public function defaultSort(): ?array
    {
        $default = $this->section?->default_sort;

        if (! is_string($default) || ! str_contains($default, ':')) {
            return null;
        }

        return explode(':', $default, 2);
    }

    /** What the listing's meta reports about the container, for the Library header. */
    public function meta(): ?array
    {
        if ($this->label !== null) {
            return ['type' => $this->label->kind === Label::KIND_BOARD ? 'board' : 'label', 'id' => (int) $this->label->id, 'name' => $this->label->name];
        }

        if ($this->section !== null) {
            return ['type' => 'section', 'id' => (int) $this->section->id, 'name' => $this->section->name, 'default_sort' => $this->section->default_sort];
        }

        return null;
    }
}
