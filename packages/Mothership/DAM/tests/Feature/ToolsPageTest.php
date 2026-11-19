<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * The Tools page and the tool frame (redesign v2, Phase 5b): which tiles an account gets, what each tile says about
 * when the tool was last used, and who may open a tool's frame.
 */

/** Each tile on the page, in the order drawn: what its footer says, its lamp, and whether it is a link. */
function toolTiles(string $html): array
{
    preg_match_all('#data-tool-tile="([^"]+)"(.*?)</li>#s', $html, $matches, PREG_SET_ORDER);

    $tiles = [];
    foreach ($matches as $match) {
        preg_match('#data-tool-standing>(.*?)</span>#s', $match[2], $standing);
        $tiles[$match[1]] = [
            'standing' => trim(strip_tags($standing[1] ?? '')),
            'lamp'     => preg_match('#bridge-lamp is-([a-z]+)#', $match[2], $lamp) ? $lamp[1] : null,
            'link'     => ! str_contains($match[2], 'data-tool-setup'),
        ];
    }

    return $tiles;
}

beforeEach(function () {
    config(['dam-home.video_api' => null]);
    $this->travelTo(CarbonImmutable::parse('2026-11-19 12:00:00', 'UTC'));
    $this->toolsAdmin = Admin::factory()->create(['timezone' => 'UTC']);
    $this->actingAs($this->toolsAdmin, 'admin');
});

it('gives a full admin every tool in the page order, and says which one needs set-up', function () {
    $response = $this->get('/admin/tools')->assertOk();
    $tiles = toolTiles($response->getContent());

    expect(array_keys($tiles))->toBe(['plaque', 'palette-studio', 'sound-effects', 'stylepress', 'looper', 'loop-maker', 'vectorization', 'sphereseam', 'video-downloader'])
        ->and($tiles['plaque'])->toBe(['standing' => 'New', 'lamp' => 'new', 'link' => true])
        ->and($tiles['looper'])->toBe(['standing' => 'Never used', 'lamp' => null, 'link' => true])
        // Without the video service the tool can not open; only a full admin can fix that, so only they see it.
        ->and($tiles['video-downloader'])->toBe(['standing' => 'Needs set-up', 'lamp' => 'idle', 'link' => false]);
    $response->assertSee('MOTHERSHIP_HOME_VIDEO_API')->assertSee(route('admin.tools.show', ['widget' => 'plaque']), false);

    // The rail marks Tools as the page in view, and not Home.
    expect($response->getContent())
        ->toMatch('#<a\s+href="[^"]*/admin/tools"\s+aria-label="Tools"\s+title="Tools"\s+aria-current="page"#')
        ->not->toMatch('#<a\s+href="[^"]*/admin/home"\s+aria-label="Home"\s+title="Home"\s+aria-current="page"#');

    config(['dam-home.video_api' => 'http://video.test']);
    expect(toolTiles($this->get('/admin/tools')->getContent())['video-downloader'])->toBe(['standing' => 'Never used', 'lamp' => null, 'link' => true]);
});

it('gives a narrow role only its tools, and never a tool that needs set-up', function () {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['settings']]);
    $this->actingAs(Admin::factory()->create(['role_id' => $role->id, 'timezone' => 'UTC']), 'admin');

    // No library permission and no stored list: the four tools every account has.
    expect(array_keys(toolTiles($this->get('/admin/tools')->assertOk()->getContent())))
        ->toBe(['plaque', 'palette-studio', 'sound-effects', 'loop-maker']);

    $this->get('/admin/tools/plaque')->assertOk();
    $this->get('/admin/tools/stylepress')->assertForbidden();
    $this->get('/admin/tools/video-downloader')->assertForbidden();
});

it('says when a tool was last used, for the person who used it', function () {
    $this->get('/admin/home/tools/palette-studio/index.html')->assertOk();
    // A tool's other files are not a use.
    $this->get('/admin/home/tools/plaque/style.css')->assertOk();

    expect(DB::table('dam_home_tool_uses')->where('admin_id', $this->toolsAdmin->id)->pluck('widget')->all())->toBe(['palette-studio']);
    $tiles = toolTiles($this->get('/admin/tools')->getContent());
    expect($tiles['palette-studio'])->toBe(['standing' => 'Used today', 'lamp' => null, 'link' => true])
        ->and($tiles['plaque']['standing'])->toBe('New');

    $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00:00', 'UTC'));
    expect(toolTiles($this->get('/admin/tools')->getContent())['palette-studio']['standing'])->toBe('Used yesterday');

    $this->travelTo(CarbonImmutable::parse('2026-12-02 09:00:00', 'UTC'));
    expect(toolTiles($this->get('/admin/tools')->getContent())['palette-studio']['standing'])->toBe('Used Thu 19 Nov');

    $this->travelTo(CarbonImmutable::parse('2027-03-01 09:00:00', 'UTC'));
    $tiles = toolTiles($this->get('/admin/tools')->getContent());
    expect($tiles['palette-studio']['standing'])->toBe('Used 19 Nov 2026')
        // "New" lasts 90 days from the day a tool arrived.
        ->and($tiles['plaque'])->toBe(['standing' => 'Never used', 'lamp' => null, 'link' => true]);

    // Another account has its own reading.
    $this->travelTo(CarbonImmutable::parse('2026-11-19 12:00:00', 'UTC'));
    $this->actingAs(Admin::factory()->create(['timezone' => 'UTC']), 'admin');
    expect(toolTiles($this->get('/admin/tools')->getContent())['palette-studio']['standing'])->toBe('New');
});

it('opens a tool in the frame page and refuses what is not a tool', function () {
    $this->get('/admin/tools/palette-studio')->assertOk()
        ->assertSee('data-tool="palette-studio"', false)
        ->assertSee('data-tool-url="'.route('admin.home.tool', ['widget' => 'palette-studio', 'path' => 'index.html']).'"', false)
        ->assertSee('data-tools-url="'.route('admin.tools.index').'"', false)
        ->assertSee('Opening Palette Studio…')
        // The frame page is a page of its own: no rail.
        ->assertDontSee('id="mothership-sidebar"', false);

    // The frame page is not a use by itself: the tool's own page being served is.
    expect(DB::table('dam_home_tool_uses')->where('admin_id', $this->toolsAdmin->id)->count())->toBe(0);

    $this->get('/admin/tools/no-such-tool')->assertNotFound();
    // Signal has cards on Home and no page of its own; Recent files is not a tool.
    $this->get('/admin/tools/rss')->assertNotFound();
    $this->get('/admin/tools/recent')->assertNotFound();
});

it('requires a signed-in account', function () {
    auth('admin')->logout();

    $this->get('/admin/tools')->assertRedirect(route('admin.session.create'));
    $this->get('/admin/tools/plaque')->assertRedirect(route('admin.session.create'));
});
