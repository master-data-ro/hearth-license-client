<?php

namespace Hearth\LicenseClient\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class MakeLicenseServerCommand extends Command
{
    protected $signature = 'make:license-server {key? : The license key to verify} {--passphrase= : Optional passphrase to derive the encryption key (defaults to APP_KEY)} {--show : Show/decrypt existing saved license instead of verifying a new key}';

    protected $description = 'Verify a license key with hearth.master-data.ro and store the validated license locally.';

    public function handle()
    {
        $key = $this->argument('key');
        $passphrase = $this->option('passphrase') ?: env('APP_LICENSE_PASSPHRASE', null);

        if ($this->option('show')) {
            $store = storage_path('license.json');
            if (!file_exists($store)) {
                $this->error('No saved license file at ' . $store);
                return 1;
            }
            $raw = file_get_contents($store);
            $decoded = json_decode($raw, true);
            if (empty($decoded['payload']) || empty($decoded['encrypted'])) {
                $this->error('Saved license file is not in expected encrypted format.');
                return 2;
            }
            try {
                $plaintext = \Hearth\LicenseClient\Encryption::decryptString($decoded['payload'], $passphrase);
                $this->line($plaintext);
                return 0;
            } catch (\Throwable $e) {
                $this->error('Failed to decrypt saved license: ' . $e->getMessage());
                return 3;
            }
        }

        if (empty($key)) {
            $this->error('No license key provided. Either pass a key or use --show to display the saved license.');
            return 4;
        }

        $this->info('Verifying license key against hearth.master-data.ro...');

        $authority = config('license.authority', 'https://hearth.master-data.ro');

        // determine domain (host) from APP_URL
        $appUrl = config('app.url') ?? env('APP_URL', '');
        $domain = parse_url($appUrl, PHP_URL_HOST) ?: gethostname();

        try {
            $resp = Http::timeout(10)->post(rtrim($authority, '/') . '/api/verify', [
                'license_key' => $key,
                'domain' => $domain,
            ]);
        } catch (\Throwable $e) {
            $this->error('Request failed: ' . $e->getMessage());
            return 1;
        }

        if (!$resp->successful()) {
            $this->error('Server returned HTTP ' . $resp->status());
            $this->line($resp->body());
            return 2;
        }

        $json = $resp->json();
        if (empty($json['data']) || !isset($json['signature'])) {
            $this->error('Invalid response from authority.');
            return 3;
        }

        $data = $json['data'];
        $signature = base64_decode($json['signature']);

        // Fetch public PEM from authority
        try {
            $pemResp = Http::timeout(10)->get(rtrim($authority, '/') . '/keys/pem');
            if (!$pemResp->successful()) {
                $this->error('Failed to fetch public key from authority: HTTP ' . $pemResp->status());
                return 4;
            }
            $pem = $pemResp->body();
        } catch (\Throwable $e) {
            $this->error('Failed to fetch public key: ' . $e->getMessage());
            return 4;
        }

        // Recreate the exact JSON string that server signed
        $payloadJson = json_encode($data);

        $pub = openssl_pkey_get_public($pem);
        if ($pub === false) {
            $this->error('Authority public key is invalid.');
            return 5;
        }

        $ok = openssl_verify($payloadJson, $signature, $pub, OPENSSL_ALGO_SHA256) === 1;
        openssl_free_key($pub);

        if (!$ok) {
            $this->error('Signature verification failed.');
            return 6;
        }

        // Verified: save license metadata locally in storage/license.json (encrypted using APP_KEY by default)
        $store = storage_path('license.json');
        $payload = [
            'license_key' => $key,
            'domain' => $domain,
            'data' => $data,
            'fetched_at' => now()->toIso8601String(),
            'authority' => $authority,
        ];

        // Use package Encryption helper to encrypt the stored payload.
        try {
            $plaintext = json_encode($payload, JSON_UNESCAPED_SLASHES);
            $encrypted = \Hearth\LicenseClient\Encryption::encryptString($plaintext, $passphrase);
            $wrapper = json_encode([
                'encrypted' => true,
                'version' => 1,
                'payload' => $encrypted,
            ], JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
            file_put_contents($store, $wrapper);
        } catch (\Throwable $e) {
            $this->error('Failed to save encrypted license: ' . $e->getMessage());
            return 7;
        }

        $this->info('License verified and saved to ' . $store . ' (encrypted)');
        $serverMessage = $data['message'] ?? '';
        $this->line('Server message: ' . $serverMessage);

        // If the authority indicates the license doesn't yet exist / was only registered,
        // give a clearer instruction to retry activation later.
        $mLower = mb_strtolower($serverMessage);
        if (str_contains($mLower, 'nu există') || str_contains($mLower, 'înregistrat') || str_contains($mLower, 'înregistrată')) {
            $this->line('Notă: licența a fost înregistrată pentru aprobare la autoritate.');
        }

        return 0;
    }
}
