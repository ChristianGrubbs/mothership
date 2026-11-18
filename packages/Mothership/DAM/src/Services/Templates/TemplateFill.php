<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Templates;

use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\LineageInput;
use Mothership\DAM\Models\TemplateResource;
use Mothership\DAM\Models\TemplateVersion;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\Lineage;
use Mothership\User\Models\Admin;

/**
 * Checks a filler's slot values against the exact template version they were offered (P8′, D25), before anything
 * is rendered or saved. The filler sends values only, keyed by slot id; the layout and every region come from the
 * version itself, so nothing a request carries can move, resize or replace them.
 *
 * - text: a string within the slot's max length, one line unless the slot is multi-line, that fits its region at
 *   the slot's largest size or, when the slot shrinks to fit, at no less than its smallest; otherwise refused.
 * - image: the id of a Library image the filler can open now, decided through the access seam, and at least the
 *   slot's minimum size. A hidden id is refused exactly as a missing one. Uploaded files are never accepted.
 * - choice: one of the author's option values; anything else is refused.
 *
 * Unknown slot ids are refused; a required slot left empty is refused.
 */
class TemplateFill
{
    public function __construct(
        private readonly AssetAccessDecision $access,
        private readonly TemplateRenderer $renderer,
        private readonly Lineage $lineage,
    ) {}

    /**
     * @param  array<string, mixed>  $values  slot id => value
     * @param  bool  $preview  an author's or filler's preview: empty slots show their sample or a placeholder
     * @return array{slots: list<array<string, mixed>>, inputs: list<array<string, mixed>>, values: array<string, mixed>}
     *                                                                                                                    what to draw, the lineage inputs and the normalized values to store
     */
    public function resolve(TemplateVersion $version, array $values, Admin $actor, string $via, bool $preview = false): array
    {
        $ctx = ['template_id' => $version->template_id, 'version' => $version->version];
        $slots = collect($version->slots())->keyBy('id');

        foreach (array_keys($values) as $id) {
            if (! $slots->has((string) $id)) {
                throw TemplateRefused::because(reason: TemplateRefused::UNKNOWN_SLOT, slot: mb_substr((string) $id, 0, 64), ctx: $ctx);
            }
        }

        $draw = [];
        $inputs = [];
        $stored = [];

        foreach ($slots as $id => $slot) {
            $value = $values[$id] ?? null;

            if (is_string($value) && trim($value) === '') {
                $value = null;
            }

            if ($value === null && $preview) {
                $value = $this->sample(slot: $slot);
            }

            if ($value === null) {
                if ($slot['required'] && ! $preview) {
                    throw TemplateRefused::because(reason: TemplateRefused::MISSING_REQUIRED, slot: $id, ctx: $ctx);
                }

                if ($preview && $slot['type'] === 'image') {
                    $draw[] = ['region' => $slot['region'], 'placeholder' => true];
                }

                continue;
            }

            $resolved = match ($slot['type']) {
                'text'   => $this->text(slot: $slot, value: $value, ctx: $ctx),
                'image'  => $this->image(slot: $slot, value: $value, actor: $actor, via: $via, ctx: $ctx),
                'choice' => $this->choice(version: $version, slot: $slot, value: $value, ctx: $ctx),
            };

            $draw[] = ['region' => $slot['region'], ...$resolved['draw']];
            $stored[$id] = $resolved['value'];

            if (isset($resolved['input'])) {
                $inputs[] = $resolved['input'];
            }
        }

        return ['slots' => $draw, 'inputs' => $inputs, 'values' => $stored];
    }

    /** What a preview shows in an empty slot: the author's sample text, or the first option. */
    private function sample(array $slot): mixed
    {
        return match ($slot['type']) {
            'text'   => ($slot['sample'] ?? '') !== '' ? $slot['sample'] : null,
            'choice' => ($slot['sample'] ?? '') !== '' ? $slot['sample'] : ($slot['constraints']['options'][0]['value'] ?? null),
            default  => null,
        };
    }

    private function text(array $slot, mixed $value, array $ctx): array
    {
        if (! is_string($value)) {
            throw TemplateRefused::because(reason: TemplateRefused::INVALID_VALUE, slot: $slot['id'], ctx: $ctx);
        }

        $c = $slot['constraints'];
        $value = str_replace(["\r\n", "\r"], "\n", $value);

        if (mb_strlen($value) > $c['max_length']) {
            throw TemplateRefused::because(reason: TemplateRefused::TEXT_TOO_LONG, slot: $slot['id'], ctx: $ctx);
        }

        if (! $c['multiline'] && str_contains($value, "\n")) {
            throw TemplateRefused::because(reason: TemplateRefused::TEXT_MULTILINE, slot: $slot['id'], ctx: $ctx);
        }

        // The font draws a character it lacks as an empty box: refuse the text instead of rendering tofu (G3-04).
        if (($font = TemplateRenderer::fontPath()) !== null && FontCoverage::firstMissing(fontPath: $font, text: $value) !== null) {
            throw TemplateRefused::because(reason: TemplateRefused::TEXT_UNSUPPORTED, slot: $slot['id'], ctx: $ctx);
        }

        return ['draw' => $this->fitted(slot: $slot, text: $value, style: $c, ctx: $ctx), 'value' => $value];
    }

    private function fitted(array $slot, string $text, array $style, array $ctx): array
    {
        $fitted = $this->renderer->fitText(text: $text, region: $slot['region'], style: $style);

        if ($fitted === null) {
            throw TemplateRefused::because(reason: TemplateRefused::TEXT_OVERFLOW, slot: $slot['id'], ctx: $ctx);
        }

        return ['text' => $fitted, 'style' => ['color' => $style['color'], 'align' => $style['align']]];
    }

    private function image(array $slot, mixed $value, Admin $actor, string $via, array $ctx): array
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($id === false) {
            throw TemplateRefused::because(reason: TemplateRefused::INVALID_VALUE, slot: $slot['id'], ctx: $ctx);
        }

        // Missing, hidden, trashed, merged or unavailable to this filler: all the same answer.
        $decision = $this->access->for(AccessContext::forAdmin(admin: $actor, purpose: AccessContext::PURPOSE_PREVIEW, via: $via), $id);
        $revision = $decision->visible ? $this->lineage->currentRevision(assetId: $id)?->loadMissing('fileObject') : null;

        if (! $revision instanceof AssetFile || $revision->fileObject === null) {
            throw TemplateRefused::because(reason: TemplateRefused::IMAGE_UNAVAILABLE, slot: $slot['id'], ctx: $ctx);
        }

        $image = $decision->asset?->file_type === 'image' ? $this->renderer->decode(object: $revision->fileObject) : null;

        if ($image === null) {
            throw TemplateRefused::because(reason: TemplateRefused::IMAGE_UNSUPPORTED, slot: $slot['id'], ctx: $ctx);
        }

        $c = $slot['constraints'];

        if (imagesx($image) < $c['min_width'] || imagesy($image) < $c['min_height']) {
            throw TemplateRefused::because(reason: TemplateRefused::IMAGE_TOO_SMALL, slot: $slot['id'], ctx: $ctx);
        }

        return [
            'draw'  => ['image' => $revision->fileObject, 'fit' => $c['fit']],
            'value' => $id,
            'input' => ['role' => LineageInput::ROLE_SLOT_IMAGE, 'asset_file_id' => (int) $revision->id, 'sha256' => $revision->fileObject->sha256],
        ];
    }

    private function choice(TemplateVersion $version, array $slot, mixed $value, array $ctx): array
    {
        $c = $slot['constraints'];
        $option = is_string($value) ? collect($c['options'])->firstWhere('value', $value) : null;

        if ($option === null) {
            throw TemplateRefused::because(reason: TemplateRefused::CHOICE_INVALID, slot: $slot['id'], ctx: $ctx);
        }

        if ($c['kind'] === 'text') {
            return ['draw' => $this->fitted(slot: $slot, text: (string) $option['text'], style: $c, ctx: $ctx), 'value' => $value];
        }

        if ($c['kind'] === 'color') {
            return ['draw' => ['color' => $option['color']], 'value' => $value];
        }

        $resource = TemplateResource::query()->where('template_id', $version->template_id)->with('fileObject')->find($option['resource']);

        if ($resource?->fileObject === null) {
            throw TemplateRefused::because(reason: TemplateRefused::RENDER_FAILED, slot: $slot['id'], ctx: $ctx);
        }

        return [
            'draw'  => ['image' => $resource->fileObject, 'fit' => 'cover'],
            'value' => $value,
            'input' => ['role' => LineageInput::ROLE_CHOICE_IMAGE, 'asset_file_id' => $resource->source_asset_file_id, 'sha256' => $resource->fileObject->sha256],
        ];
    }
}
