<?php

namespace Hearth\LicenseClient;

use Illuminate\Support\ServiceProvider;

class LicenseServiceProvider extends ServiceProvider
{
    public function register()
    {
        // Merge default config so config() calls work even if the config
        // file hasn't been published.
        $this->mergeConfigFrom(__DIR__ . '/../config/license-client.php', 'license-client');
    }

    public function boot()
    {
        // publish config
        $this->publishes([
            __DIR__ . '/../config/license-client.php' => config_path('license-client.php'),
        ], 'license-client-config');

        // Load package views (embedded, not publishable) so blocked page is always available
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'license-client');

        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\MakeLicenseServerCommand::class,
            ]);
            return;
        }

        // Always enforce: push the middleware into the 'web' group so web requests
        // are blocked until a valid license is present. This is intentionally
        // non-configurable to ensure the client application cannot disable
        // enforcement at runtime.
        try {
            $router = $this->app->make('router');
            $router->pushMiddlewareToGroup('web', \Hearth\LicenseClient\Middleware\EnsureHasValidLicense::class);
        } catch (\Throwable $e) {
            // don't break the application if something goes wrong here
        }
    }
}
