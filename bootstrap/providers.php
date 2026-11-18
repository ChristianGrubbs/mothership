<?php

use App\Providers\AppServiceProvider;
use Mothership\Admin\Providers\AdminServiceProvider;
use Mothership\AdminApi\Providers\AdminApiServiceProvider;
use Mothership\AdminApi\Providers\McpOAuthServiceProvider;
use Mothership\AiAgent\Providers\AiAgentServiceProvider;
use Mothership\AppUrlGuard\Providers\AppUrlGuardServiceProvider;
use Mothership\Attribute\Providers\AttributeServiceProvider;
use Mothership\Category\Providers\CategoryServiceProvider;
use Mothership\Completeness\Providers\CompletenessServiceProvider;
use Mothership\Core\Providers\CoreServiceProvider;
use Mothership\Core\Providers\EnvValidatorServiceProvider;
use Mothership\DAM\Providers\DAMServiceProvider;
use Mothership\DAM\Providers\MothershipMcpServiceProvider;
use Mothership\DataGrid\Providers\DataGridServiceProvider;
use Mothership\DataTransfer\Providers\DataTransferServiceProvider;
use Mothership\DebugBar\Providers\DebugBarServiceProvider;
use Mothership\ElasticSearch\Providers\ElasticSearchServiceProvider;
use Mothership\HistoryControl\Providers\HistoryControlServiceProvider;
use Mothership\Installer\Providers\InstallerServiceProvider;
use Mothership\MagicAI\Providers\MagicAIServiceProvider;
use Mothership\Measurement\Providers\MeasurementServiceProvider;
use Mothership\Notification\Providers\NotificationServiceProvider;
use Mothership\Product\Providers\ProductServiceProvider;
use Mothership\SphereSeam\Providers\SphereSeamServiceProvider;
use Mothership\Theme\Providers\ThemeServiceProvider;
use Mothership\User\Providers\UserServiceProvider;
use Mothership\Webhook\Providers\WebhookServiceProvider;

return [
    EnvValidatorServiceProvider::class,
    AppServiceProvider::class,
    AdminApiServiceProvider::class,
    McpOAuthServiceProvider::class,
    AdminServiceProvider::class,
    AttributeServiceProvider::class,
    CategoryServiceProvider::class,
    CoreServiceProvider::class,
    DAMServiceProvider::class,
    MothershipMcpServiceProvider::class,
    DataGridServiceProvider::class,
    DataTransferServiceProvider::class,
    DebugBarServiceProvider::class,
    HistoryControlServiceProvider::class,
    InstallerServiceProvider::class,
    MagicAIServiceProvider::class,
    NotificationServiceProvider::class,
    ProductServiceProvider::class,
    ThemeServiceProvider::class,
    UserServiceProvider::class,
    ElasticSearchServiceProvider::class,
    WebhookServiceProvider::class,
    CompletenessServiceProvider::class,
    AiAgentServiceProvider::class,
    MeasurementServiceProvider::class,
    AppUrlGuardServiceProvider::class,
    SphereSeamServiceProvider::class,
];
