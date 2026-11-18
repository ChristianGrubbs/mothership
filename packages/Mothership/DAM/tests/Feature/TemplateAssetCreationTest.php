<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\LineageInput;
use Mothership\DAM\Models\Rendition;
use Mothership\DAM\Models\Template;
use Mothership\DAM\Models\TemplateVersion;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\ImageEditorSchema;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * P8′ (D25): templates built in-app on the image editor. A manager publishes a template with a locked layout and
 * named text, image and choice slots; a filler sends slot values only, and the server re-validates every value
 * against the pinned version, renders the PNG and saves it as a NEW asset whose lineage names the template version
 * and every image-slot revision. These are the roadmap §5 P8 D25 criteria, web and REST.
 */

/** A PNG of the given size and colour, as bytes. */
function p8Png(int $width, int $height, array $rgb = [40, 120, 200]): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, ...$rgb));
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/** A Library image in $directory whose current original is a generated PNG. */
function p8Image(Directory $directory, int $width = 400, int $height = 300, array $rgb = [40, 120, 200]): Asset
{
    $fileName = 'p8-'.uniqid().'.png';
    $path = 'assets/'.$directory->generatePath().'/'.$fileName;
    Storage::disk(Directory::getAssetDisk())->put($path, p8Png($width, $height, $rgb));
    $asset = Asset::factory()->create(['file_name' => $fileName, 'path' => $path, 'extension' => 'png', 'file_type' => 'image', 'mime_type' => 'image/png', 'title' => null]);
    $directory->assets()->attach($asset->id);

    return $asset;
}

/** A member of a custom role holding $permissions who sees only the $granted folders (grants model). */
function p8Member(array $permissions, Directory ...$granted): Admin
{
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.assets', 'dam.asset', 'dam.asset.view', 'dam.template', ...$permissions]]);

    foreach ($granted as $directory) {
        DB::table('dam_directory_role')->insert(['directory_id' => $directory->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => AccessModel::GRANTS]);
    app(AccessModel::class)->flush();
    app(DirectoryPermissionService::class)->flush();

    return Admin::factory()->create(['role_id' => $role->id]);
}

/** The four slots every test template has, on a 400x300 canvas. */
function p8Slots(): array
{
    return [
        ['id'             => 'headline', 'type' => 'text', 'label' => 'Headline', 'region' => ['x' => 10, 'y' => 10, 'w' => 380, 'h' => 60], 'required' => true, 'sample' => 'Spring open day',
            'constraints' => ['max_length' => 60, 'font_size_min' => 10, 'font_size_max' => 40, 'multiline' => false, 'overflow' => 'shrink', 'color' => '#ffffff', 'align' => 'center']],
        ['id'             => 'tag', 'type' => 'text', 'label' => 'Tag', 'region' => ['x' => 10, 'y' => 80, 'w' => 60, 'h' => 20], 'required' => false,
            'constraints' => ['max_length' => 200, 'font_size_min' => 16, 'font_size_max' => 16, 'multiline' => false, 'overflow' => 'refuse', 'color' => '#000000', 'align' => 'left']],
        ['id'             => 'photo', 'type' => 'image', 'label' => 'Photo', 'region' => ['x' => 200, 'y' => 120, 'w' => 180, 'h' => 160], 'required' => false,
            'constraints' => ['fit' => 'cover', 'min_width' => 50, 'min_height' => 50]],
        ['id'             => 'band', 'type' => 'choice', 'label' => 'Band colour', 'region' => ['x' => 0, 'y' => 290, 'w' => 400, 'h' => 10], 'required' => true,
            'constraints' => ['kind' => 'color', 'options' => [['value' => 'red', 'label' => 'Red', 'color' => '#dc2626'], ['value' => 'blue', 'label' => 'Blue', 'color' => '#2563eb']],
                'font_size_min'      => 12, 'font_size_max' => 12, 'multiline' => false, 'overflow' => 'shrink', 'color' => '#000000', 'align' => 'left']],
    ];
}

/** A published template (as the signed-in full admin), on a 400x300 base image, with p8Slots(). */
function p8Template(Directory $output, ?array $slots = null): Template
{
    $base = p8Image(test()->library, 400, 300, [20, 20, 20]);
    $id = test()->postJson(route('admin.dam.templates.store'), ['name' => 'Event poster', 'base_asset_id' => $base->id, 'output_directory_id' => $output->id])->assertCreated()->json('template.id');
    test()->putJson(route('admin.dam.templates.update', $id), ['slots' => $slots ?? p8Slots()])->assertOk();
    test()->postJson(route('admin.dam.templates.publish', $id))->assertOk();

    return Template::query()->findOrFail($id);
}

function p8Fill(Template $template, array $values, array $extra = []): array
{
    return ['version' => $template->fresh()->publishedVersion->version, 'values' => $values, ...$extra];
}

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());
    Queue::fake();
    config(['dam.template_logging' => false]);
    $this->admin = $this->loginAsAdmin();
    $this->library = Directory::factory()->create(['name' => 'Library images']);
    $this->outputs = Directory::factory()->create(['name' => 'Posters']);
});

it('refuses a fill that tries to change the locked layout or a slot region, server-side, and creates nothing', function () {
    $template = p8Template($this->outputs);
    $before = Asset::query()->count();
    $document = $template->publishedVersion->document;

    foreach ([
        ['slots' => [['id' => 'headline', 'region' => ['x' => 0, 'y' => 0, 'w' => 400, 'h' => 300]]]],
        ['locked'   => ['background' => ['enabled' => true]]],
        ['document' => $document],
        ['regions'  => ['headline' => ['x' => 0, 'y' => 0, 'w' => 1, 'h' => 1]]],
    ] as $tamper) {
        $this->postJson(route('admin.dam.templates.assets.store', $template->id), p8Fill($template, ['headline' => 'Hello', 'band' => 'red'], $tamper))
            ->assertUnprocessable()->assertJsonPath('code', 'unknown_field');
    }

    // A value for a slot the version does not have is refused, not ignored.
    $this->postJson(route('admin.dam.templates.assets.store', $template->id), p8Fill($template, ['headline' => 'Hello', 'band' => 'red', 'logo' => 'x']))
        ->assertUnprocessable()->assertJsonPath('code', 'unknown_slot')->assertJsonPath('slot', 'logo');

    expect(Asset::query()->count())->toBe($before)
        ->and($template->publishedVersion->fresh()->document)->toBe($document);
});

it('accepts only Library images the filler can open: a hidden id is refused exactly like a missing one, and uploads are refused', function () {
    $hiddenFolder = Directory::factory()->create(['name' => 'Private']);
    $hidden = p8Image($hiddenFolder);
    $visible = p8Image($this->outputs, 300, 300, [200, 50, 50]);
    $template = p8Template($this->outputs);
    $this->actingAs(p8Member(['dam.template.use', 'dam.asset.upload'], $this->outputs), 'admin');
    config(['app.debug' => false]);
    $url = route('admin.dam.templates.assets.store', $template->id);

    $hiddenResponse = $this->postJson($url, p8Fill($template, ['headline' => 'Hi', 'band' => 'red', 'photo' => $hidden->id]))->assertUnprocessable();
    $missingResponse = $this->postJson($url, p8Fill($template, ['headline' => 'Hi', 'band' => 'red', 'photo' => $hidden->id + 1000]))->assertUnprocessable();

    expect($hiddenResponse->json('code'))->toBe('image_unavailable')
        ->and($hiddenResponse->getContent())->toBe(str_replace((string) ($hidden->id + 1000), (string) $hidden->id, $missingResponse->getContent()))
        ->and($hiddenResponse->getContent())->toBe($missingResponse->getContent());

    // The preview answers the same way, so it can never show a hidden image either.
    $this->postJson(route('admin.dam.templates.use.preview', $template->id), p8Fill($template, ['photo' => $hidden->id]))
        ->assertUnprocessable()->assertJsonPath('code', 'image_unavailable');

    // A device upload is never an image slot value, in the slot or anywhere else in the request.
    $upload = UploadedFile::fake()->image('device.png', 300, 300);
    $this->post($url, [...p8Fill($template, ['headline' => 'Hi', 'band' => 'red']), 'values' => ['headline' => 'Hi', 'band' => 'red', 'photo' => $upload]], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonPath('code', 'upload_not_accepted');
    $this->post($url, [...p8Fill($template, ['headline' => 'Hi', 'band' => 'red']), 'image' => $upload], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonPath('code', 'upload_not_accepted');

    // A visible image goes in.
    $this->postJson($url, p8Fill($template, ['headline' => 'Hi', 'band' => 'red', 'photo' => $visible->id]))->assertCreated();
});

it('refuses a choice outside its list and enforces text limits and overflow on the server', function () {
    $template = p8Template($this->outputs);
    $url = route('admin.dam.templates.assets.store', $template->id);
    $refused = fn (array $values) => $this->postJson($url, p8Fill($template, $values))->assertUnprocessable();

    expect($refused(['headline' => 'Hi', 'band' => 'green'])->json())->toMatchArray(['code' => 'choice_invalid', 'slot' => 'band'])
        ->and($refused(['headline' => 'Hi', 'band' => ['red']])->json('code'))->toBe('choice_invalid')
        ->and($refused(['headline' => str_repeat('a', 61), 'band' => 'red'])->json())->toMatchArray(['code' => 'text_too_long', 'slot' => 'headline'])
        ->and($refused(['headline' => "Two\nlines", 'band' => 'red'])->json('code'))->toBe('text_multiline')
        // 16 px fixed in a 60 px box, no shrinking: overflow is refused, not clipped.
        ->and($refused(['headline' => 'Hi', 'band' => 'red', 'tag' => 'Much too long for the box'])->json())->toMatchArray(['code' => 'text_overflow', 'slot' => 'tag'])
        // A single word wider than the headline box even at the smallest size.
        ->and($refused(['headline' => str_repeat('W', 60), 'band' => 'red'])->json('code'))->toBe('text_overflow')
        ->and($refused(['band' => 'red'])->json())->toMatchArray(['code' => 'missing_required', 'slot' => 'headline']);

    // A long headline that fits once shrunk below the largest size is accepted.
    $this->postJson($url, p8Fill($template, ['headline' => 'A considerably longer headline for the poster', 'band' => 'blue']))->assertCreated();
});

it('saves a new asset with template-version and image-slot lineage; publishing v2 changes nothing made from v1', function () {
    $photo = p8Image($this->library, 320, 240, [10, 200, 10]);
    $photoRevision = AssetFile::query()->where('dam_asset_id', $photo->id)->where('role', AssetFile::ROLE_ORIGINAL)->with('fileObject')->firstOrFail();
    $template = p8Template($this->outputs);
    $v1 = $template->publishedVersion;
    $assetsBefore = Asset::query()->count();

    $created = $this->postJson(route('admin.dam.templates.assets.store', $template->id), p8Fill($template, ['headline' => 'Open day', 'band' => 'red', 'photo' => $photo->id], ['name' => 'Open day poster']))
        ->assertCreated()->json('asset');
    $asset = Asset::query()->findOrFail($created['asset_id']);
    $output = AssetFile::query()->where('dam_asset_id', $asset->id)->where('role', AssetFile::ROLE_ORIGINAL)->with('fileObject')->firstOrFail();
    $bytes = Storage::disk($output->fileObject->disk)->get($output->fileObject->path);
    $rendition = Rendition::query()->where('output_asset_id', $asset->id)->firstOrFail();
    $inputs = LineageInput::query()->where('rendition_id', $rendition->id)->orderBy('position')->get();

    expect(Asset::query()->count())->toBe($assetsBefore + 1)
        ->and($asset->file_name)->toBe('Open day poster.png')
        ->and($asset->directories()->pluck('dam_directories.id')->all())->toBe([$this->outputs->id])
        ->and(getimagesizefromstring($bytes))->toMatchArray([0 => 400, 1 => 300, 'mime' => 'image/png'])
        ->and($asset->meta_data['source'])->toBe('template:'.$template->id.'@1')
        ->and($rendition->recipe)->toBe('template')
        ->and($rendition->parameters)->toMatchArray(['template_id' => $template->id, 'version' => 1, 'version_id' => $v1->id, 'values' => ['headline' => 'Open day', 'photo' => $photo->id, 'band' => 'red']])
        ->and($inputs->map(fn ($row) => [$row->role, $row->template_version_id, $row->asset_file_id])->all())->toBe([
            ['template', $v1->id, null],
            ['slot_image', null, $photoRevision->id],
        ])
        ->and($inputs[0]->sha256)->toBe($v1->layer->fileObject->sha256)
        ->and($inputs[1]->sha256)->toBe($photoRevision->fileObject->sha256);

    // The render really composed the slots: the band is red and the photo region green, over the dark base.
    $image = imagecreatefromstring($bytes);
    expect(imagecolorsforindex($image, imagecolorat($image, 200, 295)))->toMatchArray(['red' => 220, 'green' => 38, 'blue' => 38])
        ->and(imagecolorsforindex($image, imagecolorat($image, 290, 200)))->toMatchArray(['red' => 10, 'green' => 200, 'blue' => 10])
        ->and(imagecolorsforindex($image, imagecolorat($image, 100, 200)))->toMatchArray(['red' => 20, 'green' => 20, 'blue' => 20]);

    // Version 2: the band moves to the top. Version 1 and the asset it made stay exactly as they were.
    $slots = p8Slots();
    $slots[3]['region'] = ['x' => 0, 'y' => 0, 'w' => 400, 'h' => 10];
    $this->putJson(route('admin.dam.templates.update', $template->id), ['slots' => $slots])->assertOk()->assertJsonPath('template.draft_version', 2)->assertJsonPath('template.published_version', 1);
    $this->postJson(route('admin.dam.templates.publish', $template->id))->assertOk()->assertJsonPath('template.published_version', 2);

    $v1->refresh();
    expect($v1->document['slots'][3]['region'])->toBe(['x' => 0, 'y' => 290, 'w' => 400, 'h' => 10])
        ->and(fn () => $v1->fill(['document' => [...$v1->document, 'slots' => []]])->save())->toThrow(LogicException::class)
        ->and(Storage::disk($output->fileObject->disk)->get($output->fileObject->path))->toBe($bytes)
        ->and($rendition->fresh()->parameters['version'])->toBe(1)
        ->and(LineageInput::query()->where('rendition_id', $rendition->id)->where('role', 'template')->value('template_version_id'))->toBe($v1->id);

    // A fill still naming version 1 is refused now that version 2 is published.
    $this->postJson(route('admin.dam.templates.assets.store', $template->id), ['version' => 1, 'values' => ['headline' => 'Late', 'band' => 'red']])
        ->assertStatus(409)->assertJsonPath('code', 'version_changed');

    // The Files tab names the template and version, and offers a new fill from these values.
    $file = collect($this->getJson(route('admin.dam.assets.files.index', $asset->id))->assertOk()->json('files'))->firstWhere('role', 'original');
    expect($file['derived_from'])->toMatchArray(['recipe' => 'template', 'summary' => 'Made from template “Event poster”, version 1'])
        ->and($file['derived_from']['template'])->toMatchArray(['id' => $template->id, 'version' => 1, 'images' => 1, 'reopen_url' => route('admin.dam.templates.use', ['id' => $template->id, 'from' => $asset->id])]);

    // Re-open: the values come back for a new fill; the earlier asset is never written.
    $this->get(route('admin.dam.templates.use', ['id' => $template->id, 'from' => $asset->id]))->assertOk()->assertSee('Open day', false);
});

it('keeps every ImageEditorSchema bound and refuses template documents over them', function () {
    $schema = app(ImageEditorSchema::class);
    $doc = $schema->initial(base: '5f0c4b9e-1d2a-4c3b-8e7f-0a1b2c3d4e5f', width: 400, height: 300);
    $refused = function (array $d) use ($schema) {
        try {
            $schema->validate($d);

            return false;
        } catch (ValidationException) {
            return true;
        }
    };

    // The editor's own bounds are unchanged.
    expect($refused([...$doc, 'resize' => ['width' => 8193, 'height' => 10]]))->toBeTrue()
        ->and($refused([...$doc, 'sourceWidth' => 8000, 'sourceHeight' => 8000, 'crop' => ['x' => 0, 'y' => 0, 'width' => 8000, 'height' => 8000], 'resize' => ['width' => 6000, 'height' => 6000]]))->toBeTrue()
        ->and($refused([...$doc, 'annotations' => array_fill(0, 201, ['id' => 'a', 'type' => 'rectangle', 'x' => 1, 'y' => 1, 'width' => 1, 'height' => 1, 'size' => 2, 'color' => '#000000'])]))->toBeTrue()
        ->and($refused([...$doc, 'annotations' => [['id' => 'a', 'type' => 'text', 'x' => 1, 'y' => 1, 'width' => 1, 'height' => 1, 'size' => 2, 'color' => '#000000', 'text' => str_repeat('x', 10001)]]]))->toBeTrue()
        ->and($refused([...$doc, 'extra' => 1]))->toBeTrue()
        ->and($refused($doc))->toBeFalse();

    $template = p8Template($this->outputs);
    $update = fn (array $slots) => $this->putJson(route('admin.dam.templates.update', $template->id), ['slots' => $slots]);
    $slot = p8Slots()[0];

    $update(array_map(fn ($i) => [...$slot, 'id' => 's'.$i], range(1, 51)))->assertUnprocessable();
    $update([[...$slot, 'region' => ['x' => 300, 'y' => 10, 'w' => 200, 'h' => 20]]])->assertUnprocessable()->assertJsonValidationErrors('document');
    $update([$slot, $slot])->assertUnprocessable()->assertJsonValidationErrors('document');
    $update([[...$slot, 'locked' => true]])->assertUnprocessable()->assertJsonValidationErrors('document');
    $update([[...$slot, 'constraints' => [...$slot['constraints'], 'max_length' => 10001]]])->assertUnprocessable();
    $update([[...$slot, 'region' => [...$slot['region'], 'z' => 1]]])->assertUnprocessable();

    // The locked layout, saved from the image editor, is held to the editor's bounds too.
    $layout = $this->getJson(route('admin.dam.templates.layout', $template->id))->assertOk()->json();
    $over = [...$layout['document'], 'resize' => ['width' => 9000, 'height' => 300]];
    $this->post(route('admin.dam.templates.layout.save', $template->id), ['revision' => $layout['revision'], 'mode' => 'version', 'document' => json_encode($over), 'render' => UploadedFile::fake()->image('r.png', 9000, 300)], ['Accept' => 'application/json'])
        ->assertUnprocessable();
    $this->post(route('admin.dam.templates.layout.save', $template->id), ['revision' => $layout['revision'], 'mode' => 'version', 'document' => json_encode([...$layout['document'], 'slots' => []]), 'render' => UploadedFile::fake()->image('r.png', 400, 300)], ['Accept' => 'application/json'])
        ->assertUnprocessable();

    expect($template->fresh()->latestVersion()->version)->toBe(1);
});

it('saves the locked layout from the image editor into a new draft and never into the published version', function () {
    $template = p8Template($this->outputs);
    $v1 = $template->publishedVersion;
    $layout = $this->getJson(route('admin.dam.templates.layout', $template->id))->assertOk()->json();
    $resource = $this->get($layout['resources'][$layout['original_base']])->assertOk();
    expect(getimagesizefromstring($resource->streamedContent() ?: $resource->getContent())[0])->toBe(400);

    // A background canvas makes the output 480x380; the editor's render comes with it.
    $locked = $layout['document'];
    $locked['background'] = [...$locked['background'], 'enabled' => true, 'padding' => 40, 'color' => '#ff8800'];
    $render = UploadedFile::fake()->createWithContent('render.png', p8Png(480, 380, [255, 136, 0]));
    $saved = $this->post(route('admin.dam.templates.layout.save', $template->id), ['revision' => $layout['revision'], 'mode' => 'version', 'document' => json_encode($locked), 'render' => $render], ['Accept' => 'application/json'])
        ->assertOk()->json();

    expect($saved['version'])->toBe(2)
        ->and($v1->fresh()->document['locked']['background']['enabled'])->toBeFalse()
        ->and(TemplateVersion::query()->where('template_id', $template->id)->where('version', 2)->first()->document['locked']['background']['padding'])->toBe(40);

    // The stale revision is refused, like the asset editor's.
    $this->post(route('admin.dam.templates.layout.save', $template->id), ['revision' => $layout['revision'], 'mode' => 'version', 'document' => json_encode($locked), 'render' => UploadedFile::fake()->createWithContent('render.png', p8Png(480, 380))], ['Accept' => 'application/json'])
        ->assertStatus(409);

    // The draft preview renders on the new layer; fillers still get version 1 until v2 is published.
    $preview = $this->post(route('admin.dam.templates.preview', $template->id), [], ['Accept' => 'image/png'])->assertOk()->assertHeader('Content-Type', 'image/png');
    expect(getimagesizefromstring($preview->getContent()))->toMatchArray([0 => 480, 1 => 380]);
    $this->postJson(route('admin.dam.templates.assets.store', $template->id), p8Fill($template, ['headline' => 'Still v1', 'band' => 'red']))
        ->assertCreated()->assertJsonPath('asset.template.version', 1);
});

it('separates manage from use and needs upload rights on the destination folder', function () {
    $template = p8Template($this->outputs);
    $draft = Template::query()->create(['name' => 'Unpublished', 'status' => Template::STATUS_DRAFT]);
    $base = p8Image($this->library);

    // A filler: lists and fills published templates; cannot author; a draft answers like a missing id.
    $filler = p8Member(['dam.template.use', 'dam.asset.upload'], $this->outputs, $this->library);
    $this->actingAs($filler, 'admin');
    config(['app.debug' => false]);
    expect(collect($this->getJson(route('admin.dam.templates.list'))->assertOk()->json('templates'))->pluck('id')->all())->toBe([$template->id]);
    $this->get(route('admin.dam.templates.index'))->assertOk()->assertSee('data-dam-template-use', false);
    $this->get(route('admin.dam.templates.use', $template->id))->assertOk();
    $this->postJson(route('admin.dam.templates.store'), ['name' => 'x', 'base_asset_id' => $base->id])->assertForbidden();
    $this->putJson(route('admin.dam.templates.update', $template->id), ['name' => 'x'])->assertForbidden();
    $this->postJson(route('admin.dam.templates.publish', $template->id))->assertForbidden();
    $this->get(route('admin.dam.templates.edit', $template->id))->assertForbidden();
    expect($this->getJson(route('admin.dam.templates.show', $draft->id))->assertNotFound()->getContent())
        ->toBe($this->getJson(route('admin.dam.templates.show', $draft->id + 1000))->assertNotFound()->getContent());
    $this->postJson(route('admin.dam.templates.assets.store', $template->id), p8Fill($template, ['headline' => 'Ok', 'band' => 'red']))->assertCreated();

    // The destination: a folder the filler cannot see answers like a missing one; one they see but may not
    // upload to, or no upload permission at all, is refused.
    $hidden = Directory::factory()->create(['name' => 'Hidden']);
    $fill = fn (array $extra) => $this->postJson(route('admin.dam.templates.assets.store', $template->id), p8Fill($template, ['headline' => 'Ok', 'band' => 'red'], $extra));
    expect($fill(['directory_id' => $hidden->id])->assertUnprocessable()->json('errors'))->toBe($fill(['directory_id' => $hidden->id + 1000])->assertUnprocessable()->json('errors'));
    $this->actingAs(p8Member(['dam.template.use'], $this->outputs), 'admin');
    $fill([])->assertForbidden();

    // A manager without use: authors, but cannot fill.
    $manager = p8Member(['dam.template.manage', 'dam.asset.upload'], $this->outputs, $this->library);
    $this->actingAs($manager, 'admin');
    $id = $this->postJson(route('admin.dam.templates.store'), ['name' => 'Manager made', 'base_asset_id' => $base->id])->assertCreated()->json('template.id');
    expect(collect($this->getJson(route('admin.dam.templates.list'))->assertOk()->json('templates'))->pluck('id'))->toContain($draft->id, $id);
    $this->postJson(route('admin.dam.templates.assets.store', $template->id), p8Fill($template, ['headline' => 'No', 'band' => 'red']))->assertForbidden();
    // A base image the manager cannot open fails exactly like a missing id.
    $privateBase = p8Image($hidden);
    expect($this->postJson(route('admin.dam.templates.store'), ['name' => 'x', 'base_asset_id' => $privateBase->id])->assertUnprocessable()->json('errors'))
        ->toBe($this->postJson(route('admin.dam.templates.store'), ['name' => 'x', 'base_asset_id' => $privateBase->id + 1000])->assertUnprocessable()->json('errors'));

    // Neither permission: no Templates surface at all.
    $this->actingAs(p8Member(['dam.asset.upload'], $this->outputs), 'admin');
    $this->get(route('admin.dam.templates.index'))->assertForbidden();
    $this->getJson(route('admin.dam.templates.list'))->assertForbidden();

    // A template that made an asset can only be archived; archiving takes it away from fillers.
    $this->actingAs($this->admin, 'admin');
    $this->deleteJson(route('admin.dam.templates.destroy', $template->id))->assertUnprocessable();
    $this->postJson(route('admin.dam.templates.archive', $template->id))->assertOk();
    $this->actingAs($filler, 'admin');
    $this->getJson(route('admin.dam.templates.show', $template->id))->assertNotFound();
    $this->postJson(route('admin.dam.templates.assets.store', $template->id), p8Fill($template, ['headline' => 'Ok', 'band' => 'red']))->assertNotFound();
});

it('answers REST like the web: same list, same template, same refusals and the same lineage', function () {
    $hiddenFolder = Directory::factory()->create(['name' => 'Private']);
    $hidden = p8Image($hiddenFolder);
    $template = p8Template($this->outputs);
    $headers = $this->getAuthenticationHeaders();
    $apiAdmin = Admin::query()->latest('id')->firstOrFail();

    expect($this->getJson(route('admin.api.dam.templates.index'), $headers)->assertOk()->json('data.templates'))
        ->toBe($this->getJson(route('admin.dam.templates.list'))->assertOk()->json('templates'));
    $shown = $this->getJson(route('admin.api.dam.templates.show', $template->id), $headers)->assertOk()->json('data.template');
    expect($shown)->toBe($this->getJson(route('admin.dam.templates.show', $template->id))->assertOk()->json('template'))
        ->and(collect($shown['published']['slots'])->pluck('id')->all())->toBe(['headline', 'tag', 'photo', 'band']);

    // The same refusal bodies as the web, for the same values.
    foreach ([['headline' => 'Hi', 'band' => 'green'], ['headline' => 'Hi', 'band' => 'red', 'photo' => $hidden->id + 1000]] as $values) {
        $rest = $this->postJson(route('admin.api.dam.templates.assets.store', $template->id), p8Fill($template, $values), $headers)->assertUnprocessable()->json();
        expect($rest)->toBe($this->postJson(route('admin.dam.templates.assets.store', $template->id), p8Fill($template, $values))->assertUnprocessable()->json());
    }

    $this->postJson(route('admin.api.dam.templates.assets.store', $template->id), p8Fill($template, ['headline' => 'Hi', 'band' => 'red'], ['slots' => []]), $headers)
        ->assertUnprocessable()->assertJsonPath('code', 'unknown_field');
    $created = $this->postJson(route('admin.api.dam.templates.assets.store', $template->id), p8Fill($template, ['headline' => 'From REST', 'band' => 'blue']), $headers)
        ->assertCreated()->assertJsonPath('success', true)->json('data.asset');
    $rendition = Rendition::query()->where('output_asset_id', $created['asset_id'])->firstOrFail();
    expect(Asset::query()->find($created['asset_id'])->created_by)->toBe($apiAdmin->id)
        ->and(LineageInput::query()->where('rendition_id', $rendition->id)->where('role', 'template')->value('template_version_id'))->toBe($template->published_version_id);

    // Authoring over REST: create on a Library image, set slots, publish.
    $base = p8Image($this->library);
    $id = $this->postJson(route('admin.api.dam.templates.store'), ['name' => 'REST made', 'base_asset_id' => $base->id], $headers)->assertCreated()->json('data.template.id');
    $this->putJson(route('admin.api.dam.templates.update', $id), ['slots' => [p8Slots()[0]]], $headers)->assertOk();
    $this->postJson(route('admin.api.dam.templates.publish', $id), [], $headers)->assertOk()->assertJsonPath('data.template.published_version', 1);
    $this->getJson(route('admin.api.dam.templates.show', $id + 1000), $headers)->assertNotFound();

    // The key's scope gates each route, and the admin's role still decides manage versus use.
    $useOnly = $this->getAuthenticationHeaders('custom', ['api.dam.templates', 'api.dam.templates.index', 'api.dam.templates.show', 'api.dam.templates.use']);
    app('auth')->forgetGuards();
    $this->postJson(route('admin.api.dam.templates.store'), ['name' => 'x', 'base_asset_id' => $base->id], $useOnly)->assertForbidden();
    $this->getJson(route('admin.api.dam.templates.index'), $useOnly)->assertOk();
});

it('keeps template images and previews to what the viewer may see', function () {
    $hidden = p8Image(Directory::factory()->create(['name' => 'Private']));
    $template = p8Template($this->outputs);
    $layer = $template->publishedVersion->layer_resource_id;

    // A draft layout: its new layer is the author's until it is published.
    $layout = $this->getJson(route('admin.dam.templates.layout', $template->id))->assertOk()->json();
    $this->post(route('admin.dam.templates.layout.save', $template->id), ['revision' => $layout['revision'], 'mode' => 'version', 'document' => json_encode($layout['document']), 'render' => UploadedFile::fake()->createWithContent('render.png', p8Png(400, 300))], ['Accept' => 'application/json'])->assertOk();
    $draftLayer = TemplateVersion::query()->where('template_id', $template->id)->where('version', 2)->value('layer_resource_id');
    $this->get(route('admin.dam.templates.resource', ['id' => $template->id, 'resource' => $draftLayer]))->assertOk();

    $this->actingAs(p8Member(['dam.template.use', 'dam.asset.upload'], $this->outputs), 'admin');
    config(['app.debug' => false]);
    $this->get(route('admin.dam.templates.resource', ['id' => $template->id, 'resource' => $layer]))->assertOk()->assertHeader('Content-Type', 'image/png');
    expect($this->getJson(route('admin.dam.templates.resource', ['id' => $template->id, 'resource' => $draftLayer]))->assertNotFound()->getContent())
        ->toBe($this->getJson(route('admin.dam.templates.resource', ['id' => $template->id, 'resource' => '00000000-0000-4000-8000-000000000000']))->assertNotFound()->getContent());

    // The author's draft preview checks image slots through the seam too: a hidden image answers like a missing one.
    $this->actingAs(p8Member(['dam.template.manage'], $this->outputs, $this->library), 'admin');
    $preview = fn (int $id) => $this->postJson(route('admin.dam.templates.preview', $template->id), ['values' => ['photo' => $id]])->assertUnprocessable()->getContent();
    expect($preview($hidden->id))->toBe($preview($hidden->id + 1000))
        ->and(json_decode($preview($hidden->id), true)['code'])->toBe('image_unavailable');
});

it('refuses text the template font cannot draw instead of rendering empty boxes', function () {
    $template = p8Template($this->outputs);
    $url = route('admin.dam.templates.assets.store', $template->id);

    $this->postJson($url, p8Fill($template, ['headline' => 'Open day 😀', 'band' => 'red']))->assertUnprocessable()
        ->assertJson(['code' => 'text_unsupported', 'slot' => 'headline']);
});
