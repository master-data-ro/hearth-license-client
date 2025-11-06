<?php

namespace Hearth\LicenseClient;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Http;

class LicenseServiceProvider extends ServiceProvider
{
    public function register()
    {
        // Merge default config so config() calls work even if the config
        // file hasn't been published.
        $this->mergeConfigFrom(__DIR__ . '/../config/license-client.php', 'license-client');
    }

    /**
     * Verify that the bundled public key corresponds to one of the keys in the
     * authority's JWKS (if the JWKS endpoint is reachable). If the JWKS is
     * reachable but doesn't include our key, we abort to avoid running with a
     * mismatched verification key.
     *
     * @throws \RuntimeException
     */
    protected function verifyBundledKeyAgainstJwks()
    {
        $authority = config('license-client.authority_url') ?? null;
        if (empty($authority)) {
            return; // nothing to verify against
        }

        $jwksUrl = rtrim($authority, '/') . '/.well-known/jwks.json';

        try {
            $resp = Http::timeout(config('license-client.remote_timeout', 5))->get($jwksUrl);
        } catch (\Throwable $e) {
            // Could not fetch JWKS; allow boot to continue (network issues)
            logger()->warning('Could not fetch JWKS for license-client verification: ' . $e->getMessage());
            return;
        }

        if (! $resp->successful()) {
            logger()->warning('JWKS not available (HTTP ' . $resp->status() . ')');
            return;
        }

        $json = $resp->json();
        if (empty($json['keys']) || !is_array($json['keys'])) {
            logger()->warning('JWKS payload malformed or empty');
            return;
        }

        // Extract local public key n/e in base64url form
        $localPemPath = __DIR__ . '/../keys/public.pem';
        if (!file_exists($localPemPath)) {
            // If the bundle doesn't include a public.pem (e.g., path repository during
            // development), don't abort the boot — log and skip verification.
            logger()->warning('Bundled public.pem not found for license-client; skipping JWKS verification.');
            return;
        }
        $localPem = file_get_contents($localPemPath);
        $res = openssl_pkey_get_public($localPem);
        if ($res === false) {
            logger()->warning('Bundled public.pem is invalid; skipping JWKS verification.');
            return;
        }
        $details = openssl_pkey_get_details($res);
        if (empty($details['rsa']['n']) || empty($details['rsa']['e'])) {
            logger()->warning('Failed to extract RSA parameters from bundled public key; skipping JWKS verification.');
            return;
        }
        $localN = $this->base64UrlEncode($details['rsa']['n']);
        $localE = $this->base64UrlEncode($details['rsa']['e']);

        foreach ($json['keys'] as $jwk) {
            if (!empty($jwk['n']) && !empty($jwk['e'])) {
                if (hash_equals($localN, $jwk['n']) && hash_equals($localE, $jwk['e'])) {
                    // match found
                    return;
                }
            }
        }

        // JWKS reachable but no matching key found -> abort
        throw new \RuntimeException('Bundled public key does not match any key in authority JWKS. Aborting to avoid running with mismatched verification key.');
    }

    protected function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public function boot()
    {
        // Verify that the bundled public key matches the authority JWKS if available.
        try {
            $this->verifyBundledKeyAgainstJwks();
        } catch (\Throwable $e) {
            // If verification fails, abort boot to avoid running with a mismatched key.
            // Throwing here will surface the problem early.
            throw $e;
        }

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

        // Register a simple signed-push endpoint so the authority can POST a
        // signed license payload directly to this client. This is registered
        // on the API middleware group to avoid CSRF requirements.
        try {
            \Illuminate\Support\Facades\Route::post('/.well-known/push-license', '\\Hearth\\LicenseClient\\Controllers\\PushLicenseController@receive')
                ->middleware('api');
        } catch (\Throwable $e) {
            // ignore if route registration fails
        }
    }
}
