<?php

declare(strict_types=1);

namespace Mothership\DAM\Processing;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Mothership\DAM\Processing\Recipes\AudioRecipe;
use Mothership\DAM\Processing\Recipes\ClipRecipe;
use Mothership\DAM\Processing\Recipes\ConvertRecipe;
use Mothership\DAM\Processing\Recipes\GifRecipe;
use Mothership\DAM\Processing\Recipes\ResizeRecipe;
use Mothership\DAM\Processing\Recipes\StillRecipe;
use Mothership\DAM\Processing\Recipes\WatermarkRecipe;
use Mothership\DAM\Processing\Recipes\WaveformRecipe;
use Mothership\DAM\Processing\Recipes\WebMp4Recipe;

/**
 * The batch recipes by key (P6a), and the one place their parameters are validated: each against its schema
 * (numbers in range, enums from their list), then the recipe's own cross-field rules. Only declared parameters
 * survive, typed, with defaults filled, so nothing else a client sends reaches a recipe or a command line.
 */
class RecipeRegistry
{
    /** @var array<string, Recipe> */
    private array $recipes = [];

    public function __construct()
    {
        foreach ([
            new ConvertRecipe, new ResizeRecipe, new GifRecipe, new StillRecipe, new AudioRecipe,
            new WaveformRecipe, new WebMp4Recipe, new ClipRecipe, new WatermarkRecipe,
        ] as $recipe) {
            $this->recipes[$recipe->key()] = $recipe;
        }
    }

    /** @return array<string, Recipe> */
    public function all(): array
    {
        return $this->recipes;
    }

    public function has(string $key): bool
    {
        return isset($this->recipes[$key]);
    }

    public function get(string $key): Recipe
    {
        return $this->recipes[$key] ?? throw new \InvalidArgumentException('Unknown recipe.');
    }

    /**
     * The recipe's parameters, validated, typed and completed with defaults.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException keys under `params.`
     */
    public function validate(string $key, mixed $params): array
    {
        $recipe = $this->get($key);
        $params = is_array($params) ? $params : [];
        $schema = $recipe->parameters();
        $rules = [];

        foreach ($schema as $name => $spec) {
            $rules[$name] = $this->rulesFor($spec);
        }

        $validator = Validator::make($params, $rules, [], collect($schema)->keys()->mapWithKeys(fn ($name) => [$name => trans('dam::app.admin.batch.params.'.$name)])->all());

        if ($validator->fails()) {
            throw ValidationException::withMessages(collect($validator->errors()->messages())->mapWithKeys(fn ($messages, $field) => ['params.'.$field => $messages])->all());
        }

        $clean = [];

        foreach ($schema as $name => $spec) {
            $value = $params[$name] ?? null;
            $value = ($value === null || $value === '') ? ($spec['default'] ?? null) : $value;
            $clean[$name] = $value === null ? null : $this->typed($spec, $value);
        }

        $errors = $recipe->check($clean);

        if ($errors !== []) {
            throw ValidationException::withMessages(collect($errors)->mapWithKeys(fn ($message, $field) => ['params.'.$field => [trans($message)]])->all());
        }

        return $clean;
    }

    /**
     * Every recipe with its parameters, for the Process dialog and `GET recipes`.
     *
     * @return list<array<string, mixed>>
     */
    public function describe(): array
    {
        return collect($this->recipes)->map(fn (Recipe $recipe) => [
            'key'        => $recipe->key(),
            'label'      => trans('dam::app.admin.batch.recipes.'.$recipe->key()),
            'file_types' => $recipe->fileTypes(),
            'parameters' => collect($recipe->parameters())->map(fn (array $spec, string $name) => array_filter([
                'name'        => $name,
                'label'       => trans('dam::app.admin.batch.params.'.$name),
                'type'        => $spec['type'],
                'required'    => (bool) ($spec['required'] ?? false),
                'min'         => $spec['min'] ?? null,
                'max'         => $spec['max'] ?? null,
                'integer'     => isset($spec['integer']) ? (bool) $spec['integer'] : null,
                'unit'        => $spec['unit'] ?? null,
                'default'     => $spec['default'] ?? null,
                'placeholder' => isset($spec['placeholder']) ? trans($spec['placeholder']) : null,
                'options'     => isset($spec['options'])
                    ? array_map(fn ($option) => ['value' => $option, 'label' => trans('dam::app.admin.batch.options.'.$option)], $spec['options'])
                    : null,
            ], fn ($value) => $value !== null))->values()->all(),
        ])->values()->all();
    }

    /** @param  array<string, mixed>  $spec */
    private function rulesFor(array $spec): array
    {
        $rules = [($spec['required'] ?? false) ? 'required' : 'nullable'];

        return match ($spec['type']) {
            'number' => [...$rules, ($spec['integer'] ?? false) ? 'integer' : 'numeric', 'min:'.$spec['min'], 'max:'.$spec['max']],
            'enum'   => [...$rules, Rule::in($spec['options'])],
            'asset'  => [...$rules, 'integer', 'min:1'],
        };
    }

    /** @param  array<string, mixed>  $spec */
    private function typed(array $spec, mixed $value): mixed
    {
        return match ($spec['type']) {
            'number' => ($spec['integer'] ?? false) ? (int) $value : round((float) $value, 3),
            'enum'   => (string) $value,
            'asset'  => (int) $value,
        };
    }
}
