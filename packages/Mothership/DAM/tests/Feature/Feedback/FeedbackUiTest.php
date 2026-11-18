<?php

use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\FeedbackNote;
use Mothership\DAM\Models\FeedbackReport;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * Feedback & bug reports, the UI: who sees the header control, the dialog and
 * its console buffer, and what My reports shows the reporter.
 */

function feedbackUiPerson(string $type = 'user'): Admin
{
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset.view']]);

    return Admin::factory()->create(['role_id' => $role->id, 'status' => 1, 'type' => $type]);
}

beforeEach(function () {
    config(['dam.feedback.enabled' => true]);
});

it('gives a signed-in person the header control, the dialog, the console buffer and the My reports link', function () {
    $this->actingAs(feedbackUiPerson(), 'admin');

    // Both admin layouts that render the header: the plain one (My reports) and with-history (My account).
    foreach ([route('admin.dam.feedback.mine'), route('admin.account.edit')] as $url) {
        $this->get($url)
            ->assertOk()
            ->assertSee('data-dam-feedback-open', false)
            ->assertSee('aria-keyshortcuts="Alt+Shift+B"', false)
            ->assertSee('id="v-dam-feedback-template"', false)
            ->assertSee(':viewport-fit="true"', false)
            ->assertSee('window.MothershipFeedback = api', false)
            ->assertSee('data-feedback-mine-link', false)
            ->assertSee(route('admin.dam.feedback.store'), false);
    }
});

it('mounts the dialog on asset edit, whose DAM layout renders the header too', function () {
    // PostHog UX review 2026-10-30, finding 2: the asset layout had the header button but no dialog behind it.
    $person = feedbackUiPerson();
    $person->role->update(['permission_type' => 'all']);
    $this->actingAs($person, 'admin');

    $this->get(route('admin.dam.assets.edit', Asset::factory()->create()->id))
        ->assertOk()
        ->assertSee('data-dam-feedback-open', false)
        ->assertSee('id="v-dam-feedback-template"', false);
});

it('renders none of it while the feature is off', function () {
    config(['dam.feedback.enabled' => false]);
    $this->actingAs(feedbackUiPerson(), 'admin');

    $this->get(route('admin.account.edit'))
        ->assertOk()
        ->assertSee('data-account-avatar', false)
        ->assertDontSee('data-dam-feedback-open', false)
        ->assertDontSee('v-dam-feedback-template', false)
        ->assertDontSee('MothershipFeedback', false)
        ->assertDontSee('data-feedback-mine-link', false);
});

it('shows the reporter their reports with status changes and visible replies, never internal notes', function () {
    $person = feedbackUiPerson();
    $report = FeedbackReport::query()->create(['admin_id' => $person->id, 'type' => 'bug', 'title' => 'Group by stack hides New', 'description' => 'At phone width.', 'status' => 'in_progress', 'route_name' => 'admin.dam.assets.index']);
    FeedbackNote::query()->create(['report_id' => $report->id, 'admin_id' => $person->id, 'kind' => 'note', 'body' => 'Internal repro notes', 'visible_to_reporter' => false]);
    FeedbackNote::query()->create(['report_id' => $report->id, 'admin_id' => $person->id, 'kind' => 'note', 'body' => 'Fixed in the next release', 'visible_to_reporter' => true]);
    FeedbackNote::query()->create(['report_id' => $report->id, 'admin_id' => $person->id, 'kind' => 'status_change', 'visible_to_reporter' => true, 'from_status' => 'new', 'to_status' => 'in_progress']);
    FeedbackReport::query()->create(['admin_id' => feedbackUiPerson()->id, 'type' => 'idea', 'title' => 'Someone else\'s idea', 'description' => 'x', 'status' => 'new']);

    $this->actingAs($person, 'admin');

    $this->get(route('admin.dam.feedback.mine'))
        ->assertOk()
        ->assertSee('Group by stack hides New')
        ->assertSee('In progress')
        ->assertSee('Fixed in the next release')
        ->assertSee('Status changed to In progress')
        ->assertDontSee('Internal repro notes')
        ->assertDontSee('Someone else\'s idea');

    $this->getJson(route('admin.dam.feedback.mine'))->assertOk()->assertJsonCount(1, 'data');
});

it('keeps internal notes off My reports when the reporter is a triager', function () {
    $manager = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.feedback.manage']])->id, 'status' => 1]);
    $full = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'all'])->id, 'status' => 1]);

    foreach ([$manager, $full] as $person) {
        $report = FeedbackReport::query()->create(['admin_id' => $person->id, 'type' => 'bug', 'title' => 'Triager own report', 'description' => 'Text-only description', 'status' => 'new']);
        FeedbackNote::query()->create(['report_id' => $report->id, 'admin_id' => $person->id, 'kind' => 'note', 'body' => 'Internal triage note', 'visible_to_reporter' => false]);

        $this->actingAs($person, 'admin');

        $this->get(route('admin.dam.feedback.mine'))->assertOk()->assertSee('Triager own report')->assertSee('Text-only description')->assertDontSee('Internal triage note');
    }
});
