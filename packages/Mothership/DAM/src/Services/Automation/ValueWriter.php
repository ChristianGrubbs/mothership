<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Automation;

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Label;
use Mothership\DAM\Models\MetadataField;
use Mothership\DAM\Models\SavedSearch;
use Mothership\DAM\Models\Tag;
use Mothership\DAM\Rules\TagNameRule;
use Mothership\DAM\Services\MetadataFields\FieldValues;
use Mothership\DAM\Services\MetadataFields\FieldValueValidator;
use Mothership\DAM\Services\Organization\LabelService;
use Mothership\DAM\Services\Organization\SectionService;
use Mothership\DAM\Services\Tags\AssetTagWriter;
use Mothership\DAM\Support\AutomationLog;

/**
 * The one door every machine write goes through (P5b, roadmap §3.4): mappings and rules, for tags, typed fields,
 * shared labels and section pins. It enforces precedence, manual > rule/mapping > AI:
 *
 * - a person's value (`manual`, or any source that is not a machine one) is never replaced: refused `manual`;
 * - a value a person removed (a tag tombstone, or a dam_value_decisions row) is never written back: refused `removed`;
 * - fill-empty writes only an empty field; replace-machine also replaces a machine value of lower or equal
 *   precedence, never a higher one (an AI writer meets a rule's value: refused `higher_precedence`);
 * - tags, labels and pins are add-if-absent and fields set-if-different, so a repeated evaluation writes nothing.
 *
 * Every call decides first and writes only when not `$dryRun`, so a dry run returns exactly what the real run
 * would write. Access (whose rights a rule runs with) is the caller's check; tag names go through TagNameRule and
 * field values through FieldValueValidator, as a person's do.
 */
class ValueWriter
{
    public const MODE_FILL_EMPTY = 'fill_empty';

    public const MODE_REPLACE_MACHINE = 'replace_machine';

    public const MODES = [self::MODE_FILL_EMPTY, self::MODE_REPLACE_MACHINE];

    public function __construct(
        private readonly AssetTagWriter $tags,
        private readonly FieldValues $fields,
        private readonly FieldValueValidator $validator,
        private readonly ValueDecisions $decisions,
    ) {}

    public function addTag(Asset $asset, string $name, string $source, bool $dryRun): WriteOutcome
    {
        $this->assertSource($source);

        if (TagNameRule::problem($name) !== null) {
            AutomationLog::write(event: 'write.refused', ctx: ['asset_id' => (int) $asset->id, 'kind' => 'tag', 'reason' => WriteOutcome::REASON_TAG_NAME, 'source' => $source], level: 'notice');

            return WriteOutcome::refused(reason: WriteOutcome::REASON_TAG_NAME);
        }

        $clean = TagNameRule::normalize($name);
        $present = $this->tags->current((int) $asset->id)[mb_strtolower($clean)] ?? null;

        if ($present !== null) {
            return WriteOutcome::skipped(reason: WriteOutcome::REASON_PRESENT, before: $clean, after: $clean);
        }

        $existing = Tag::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($clean)])->first();

        if ($existing !== null && $this->tags->isRemoved(assetId: (int) $asset->id, tagId: (int) $existing->id)) {
            return $this->refuse(asset: $asset, kind: 'tag', reason: WriteOutcome::REASON_REMOVED, source: $source, after: $clean);
        }

        if ($dryRun) {
            return WriteOutcome::written(before: null, after: $clean);
        }

        $tag = $existing ?? $this->tags->resolve($clean)->first();
        $changed = $this->tags->addRespectingRemovals(assetIds: [(int) $asset->id], tagIds: [(int) $tag->id], source: $source);

        return $changed === []
            ? WriteOutcome::skipped(reason: WriteOutcome::REASON_PRESENT, before: $clean, after: $clean)
            : $this->wrote(asset: $asset, kind: 'tag', source: $source, before: null, after: $tag->name);
    }

    public function setField(Asset $asset, MetadataField $field, mixed $raw, string $source, string $mode, bool $dryRun): WriteOutcome
    {
        $this->assertSource($source);

        if ($field->archived_at !== null) {
            return WriteOutcome::refused(reason: WriteOutcome::REASON_TARGET_MISSING);
        }

        if ($this->validator->check(field: $field, raw: $raw, value: $value) !== null || $value === null) {
            return $this->refuse(asset: $asset, kind: 'field', reason: WriteOutcome::REASON_INVALID, source: $source);
        }

        $after = $this->fields->storedText(field: $field, value: $value);
        $verdict = $this->fieldVerdict(asset: $asset, field: $field, value: $value, after: $after, source: $source, mode: $mode);

        if ($verdict['outcome'] !== null) {
            return $verdict['outcome'];
        }

        $before = $verdict['before'];

        if ($dryRun) {
            return WriteOutcome::written(before: $before, after: $after);
        }

        // A person may have replaced or cleared the value since the check above: decide again, under the asset's lock.
        $recheck = null;
        $result = $this->fields->writeMachineGuarded(asset: $asset, field: $field, value: $value, provenance: $source, guard: function () use ($asset, $field, $value, $after, $source, $mode, &$recheck): bool {
            $recheck = $this->fieldVerdict(asset: $asset, field: $field, value: $value, after: $after, source: $source, mode: $mode);

            return $recheck['outcome'] === null;
        });

        if ($result === 'refused') {
            return $recheck['outcome'];
        }

        $before = $recheck['before'] ?? $before;

        return $result === 'written'
            ? $this->wrote(asset: $asset, kind: 'field', source: $source, before: $before, after: $after)
            : WriteOutcome::skipped(reason: WriteOutcome::REASON_UNCHANGED, before: $before, after: $after);
    }

    /**
     * May this machine write put `$value` on the field now? `outcome` is the skip or refusal that says no (already logged
     * when it refuses), or null when the write may go ahead; `before` is the stored text the decision was made against.
     *
     * @return array{outcome: ?WriteOutcome, before: ?string}
     */
    private function fieldVerdict(Asset $asset, MetadataField $field, mixed $value, string $after, string $source, string $mode): array
    {
        $current = $this->fields->current(assetId: (int) $asset->id, field: $field);
        $before = $current['text'] ?? null;

        if ($current !== null && $current['text'] === $after
            && ($field->type !== 'multi_select' || $current['options'] === array_map('strval', array_values((array) $value)))) {
            return ['outcome' => WriteOutcome::skipped(reason: WriteOutcome::REASON_UNCHANGED, before: $before, after: $after), 'before' => $before];
        }

        if ($current === null && $this->decisions->isRemoved(assetId: (int) $asset->id, kind: ValueDecisions::KIND_FIELD, targetId: (int) $field->id)) {
            return ['outcome' => $this->refuse(asset: $asset, kind: 'field', reason: WriteOutcome::REASON_REMOVED, source: $source, after: $after), 'before' => $before];
        }

        if ($current !== null) {
            if (! ValueSource::isMachine($current['source'])) {
                return ['outcome' => $this->refuse(asset: $asset, kind: 'field', reason: WriteOutcome::REASON_MANUAL, source: $source, before: $before, after: $after), 'before' => $before];
            }

            if ($mode === self::MODE_FILL_EMPTY) {
                return ['outcome' => WriteOutcome::skipped(reason: WriteOutcome::REASON_NOT_EMPTY, before: $before, after: $after), 'before' => $before];
            }

            if (! ValueSource::mayReplace(writer: $source, existing: $current['source'])) {
                return ['outcome' => $this->refuse(asset: $asset, kind: 'field', reason: WriteOutcome::REASON_PRECEDENCE, source: $source, before: $before, after: $after), 'before' => $before];
            }
        }

        return ['outcome' => null, 'before' => $before];
    }

    public function addLabel(Asset $asset, ?Label $label, string $source, bool $dryRun): WriteOutcome
    {
        $this->assertSource($source);

        if ($label === null || $label->kind !== Label::KIND_SHARED) {
            return WriteOutcome::refused(reason: WriteOutcome::REASON_TARGET_MISSING);
        }

        if (DB::table('dam_label_assets')->where('label_id', $label->id)->where('asset_id', $asset->id)->exists()) {
            return WriteOutcome::skipped(reason: WriteOutcome::REASON_PRESENT, before: $label->name, after: $label->name);
        }

        if ($this->decisions->isRemoved(assetId: (int) $asset->id, kind: ValueDecisions::KIND_LABEL, targetId: (int) $label->id)) {
            return $this->refuse(asset: $asset, kind: 'label', reason: WriteOutcome::REASON_REMOVED, source: $source, after: $label->name);
        }

        if ($dryRun) {
            return WriteOutcome::written(before: null, after: $label->name);
        }

        return app(LabelService::class)->addByMachine(label: $label, assetId: (int) $asset->id, source: $source)
            ? $this->wrote(asset: $asset, kind: 'label', source: $source, before: null, after: $label->name)
            : WriteOutcome::skipped(reason: WriteOutcome::REASON_PRESENT, before: $label->name, after: $label->name);
    }

    public function pinSection(Asset $asset, ?SavedSearch $section, string $source, bool $dryRun): WriteOutcome
    {
        $this->assertSource($source);

        if ($section === null || ! $section->isSection()) {
            return WriteOutcome::refused(reason: WriteOutcome::REASON_TARGET_MISSING);
        }

        if (DB::table('dam_section_pins')->where('section_id', $section->id)->where('asset_id', $asset->id)->exists()) {
            return WriteOutcome::skipped(reason: WriteOutcome::REASON_PRESENT, before: $section->name, after: $section->name);
        }

        if ($this->decisions->isRemoved(assetId: (int) $asset->id, kind: ValueDecisions::KIND_SECTION, targetId: (int) $section->id)) {
            return $this->refuse(asset: $asset, kind: 'section', reason: WriteOutcome::REASON_REMOVED, source: $source, after: $section->name);
        }

        if ($dryRun) {
            return WriteOutcome::written(before: null, after: $section->name);
        }

        return app(SectionService::class)->pinByMachine(section: $section, assetId: (int) $asset->id, source: $source)
            ? $this->wrote(asset: $asset, kind: 'section', source: $source, before: null, after: $section->name)
            : WriteOutcome::skipped(reason: WriteOutcome::REASON_PRESENT, before: $section->name, after: $section->name);
    }

    private function refuse(Asset $asset, string $kind, string $reason, string $source, ?string $before = null, ?string $after = null): WriteOutcome
    {
        AutomationLog::write(event: 'write.refused', ctx: ['asset_id' => (int) $asset->id, 'kind' => $kind, 'reason' => $reason, 'source' => $source], level: 'notice');

        return WriteOutcome::refused(reason: $reason, before: $before, after: $after);
    }

    private function wrote(Asset $asset, string $kind, string $source, ?string $before, ?string $after): WriteOutcome
    {
        AutomationLog::write(event: 'write.written', ctx: ['asset_id' => (int) $asset->id, 'kind' => $kind, 'source' => $source]);

        return WriteOutcome::written(before: $before, after: $after);
    }

    private function assertSource(string $source): void
    {
        if (! ValueSource::validMachine($source)) {
            throw new \InvalidArgumentException('ValueWriter writes machine sources only.');
        }
    }
}
