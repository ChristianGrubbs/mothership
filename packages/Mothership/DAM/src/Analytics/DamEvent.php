<?php

declare(strict_types=1);

namespace Mothership\DAM\Analytics;

/**
 * Every product event name (P1b, §3.6.3). Config/dam_events.php declares each one's version, emitter, subject and
 * typed props; EventRegistry checks the two agree, so a name cannot exist in one and not the other.
 */
enum DamEvent: string
{
    case TransferAttempted = 'transfer.file.attempted';
    case TransferAcknowledged = 'transfer.file.acknowledged';
    case TransferReceived = 'transfer.file.received';
    case TransferFailed = 'transfer.file.failed';
    case TransferCancelled = 'transfer.file.cancelled';
    case TransferDeclined = 'transfer.file.declined';
    case PwaInstallable = 'pwa.installable';
    case PwaPrompted = 'pwa.install.prompted';
    case PwaChoice = 'pwa.install.choice';
    case PwaInstalled = 'pwa.installed';
    case PwaStandaloneOpened = 'pwa.standalone.opened';
    case PwaUpdateReady = 'pwa.update.ready';
    case PreviewServed = 'asset.preview.served';
    case DownloadServed = 'asset.download.served';
    case UploadAttempted = 'upload.file.attempted';
    case UploadSucceeded = 'upload.file.succeeded';
    case UploadFailed = 'upload.file.failed';
    case ShareCreated = 'share.link.created';
    case ShareRevoked = 'share.link.revoked';
    case ShareVisited = 'share.link.visited';
    case SearchServed = 'search.served';
    case OptimizationSucceeded = 'processing.optimization.succeeded';
    case OptimizationFailed = 'processing.optimization.failed';
    case VectorizationSucceeded = 'processing.vectorization.succeeded';
    case VectorizationFailed = 'processing.vectorization.failed';

    case RouteViewed = 'route.viewed';
    case PreviewOpened = 'asset.preview.opened';
    case SearchSubmitted = 'search.submitted';
    case SearchResultOpened = 'search.result.opened';
    case FilterApplied = 'filter.applied';
    case ModalTabOpened = 'modal.tab.opened';
    case UploadDialogOpened = 'upload.dialog.opened';
    case UploadDialogAbandoned = 'upload.dialog.abandoned';
    case ShareDialogOpened = 'share.dialog.opened';
    case ShareDialogAbandoned = 'share.dialog.abandoned';
    case ClientError = 'client.error.raised';
    case WebVitals = 'client.web_vitals.sampled';
    case HomeFeatureExposed = 'home.feature.exposed';
    case HomeFeatureActivated = 'home.feature.activated';
    case HomeLaunchAttempted = 'home.launch.attempted';
    case HomeLaunchLoaded = 'home.launch.loaded';
    case HomeLaunchSlow = 'home.launch.slow';
    case HomeLaunchAbandoned = 'home.launch.abandoned';
    case HomeLaunchFailed = 'home.launch.failed';
    case HubOpenConfirmed = 'hub.open.confirmed';
    case HubOpenFailed = 'hub.open.failed';
    case HubSaveAttempted = 'hub.save.attempted';
    case HubSaveSucceeded = 'hub.save.succeeded';
    case HubSaveFailed = 'hub.save.failed';
    case HubSaveUnchanged = 'hub.save.unchanged';
    case HubConnectStepOpened = 'hub.connect.step.opened';
    case HubConnectStatusChecked = 'hub.connect.status.checked';
    case HubConnectStatusFailed = 'hub.connect.status.failed';
    case HubConnectCompleted = 'hub.connect.completed';
    case McpTokenIssued = 'craft.oauth.token.issued';
    case McpTokenFailed = 'craft.oauth.token.failed';

    /** @return array{v: int, emitter: string, subject: ?string, props: array<string, mixed>, scopes?: list<string>, window?: int, terminal?: string} */
    public function definition(): array
    {
        return EventRegistry::definition($this->value);
    }

    /** The outcome an event of this name records: attempts and terminal outcomes are what funnels count. */
    public function outcome(): string
    {
        return match (true) {
            in_array($this, [self::PwaInstallable, self::PwaPrompted, self::PwaChoice, self::PwaStandaloneOpened, self::PwaUpdateReady], true) => EventRegistry::OUTCOME_NONE,
            in_array($this, [self::TransferCancelled, self::TransferDeclined], true)                                                           => EventRegistry::OUTCOME_ABANDON,
            $this === self::HubSaveUnchanged, $this === self::HubConnectStatusChecked                                                          => EventRegistry::OUTCOME_NONE,
            in_array($this, [self::HomeFeatureExposed, self::HomeFeatureActivated, self::HomeLaunchLoaded, self::HomeLaunchSlow], true)        => EventRegistry::OUTCOME_NONE,
            str_ends_with($this->value, '.attempted')                                                                                          => EventRegistry::OUTCOME_ATTEMPT,
            str_ends_with($this->value, '.failed'), $this === self::ClientError                                                                => EventRegistry::OUTCOME_FAILURE,
            str_ends_with($this->value, '.abandoned')                                                                                          => EventRegistry::OUTCOME_ABANDON,
            str_ends_with($this->value, '.opened'), $this === self::WebVitals, $this === self::FilterApplied, $this === self::RouteViewed,
            $this === self::SearchSubmitted                                                   => EventRegistry::OUTCOME_NONE,
            default                                                                           => EventRegistry::OUTCOME_SUCCESS,
        };
    }
}
