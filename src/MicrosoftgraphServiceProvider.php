<?php

namespace LLoadout\Microsoftgraph;

use Illuminate\Support\ServiceProvider;
use LLoadout\Microsoftgraph\Providers\EventServiceProvider;

class MicrosoftgraphServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/microsoftgraph.php', 'microsoftgraph');
    }
    public function boot(): void
    {
        $this->loadRoutes();
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/Models/MicrosoftGraphAccessToken.php' => app_path('Models/MicrosoftGraphAccessToken.php'),
            ], 'microsoftgraph-model');
            $this->publishes([
                __DIR__ . '/../config/microsoftgraph.php' => config_path('microsoftgraph.php'),
            ], 'microsoftgraph-config');
            $this->publishes([
                __DIR__ . '/database/migrations/create_microsoft_graph_access_tokens_table.php.stub' =>
                database_path('migrations/create_microsoft_graph_access_tokens_table.php.stub'),
            ], 'microsoftgraph-migrations');
        }
        $this->registerDynamicConfig();
        $this->app->register(EventServiceProvider::class);
    }
    protected function loadRoutes(): void
    {
        $this->app['router']->get('microsoft/connect', [
            'uses' => '\LLoadout\Microsoftgraph\Authenticate@connect',
            'as' => 'graph.connect',
        ])->middleware('web');
        $this->app['router']->get('microsoft/callback', [
            'uses' => '\LLoadout\Microsoftgraph\Authenticate@callback',
            'as' => 'graph.callback',
        ])->middleware('web');
    }
    protected function registerDynamicConfig(): void
    {
        $microsoftConfig = config('microsoftgraph');
        $this->app['config']->set('services.microsoft', array_merge(
            $this->app['config']->get('services.microsoft', []),
            [
                'tenant' => $microsoftConfig['tenant'] ?? null,
                'client_id' => $microsoftConfig['client_id'] ?? null,
                'client_secret' => $microsoftConfig['client_secret'] ?? null,
                'redirect' => $microsoftConfig['redirect'] ?? null,
                'redirect_after_callback' => $microsoftConfig['redirect_after_callback'] ?? '/',
                'single_user' => $microsoftConfig['single_user'] ?? false,
            ]
        ));
        $this->app['config']->set('mail.mailers.microsoftgraph', [
            'transport' => 'microsoftgraph',
        ]);
        $this->app['config']->set('filesystems.disks.onedrive', [
            'driver' => 'onedrive',
            'root' => $microsoftConfig['onedrive_root'] ?? '/',
        ]);
    }
}
