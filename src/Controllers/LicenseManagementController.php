<?php

namespace Hearth\LicenseClient\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;
use Hearth\LicenseClient\Encryption;

class LicenseManagementController extends Controller
{
    /**
     * GET /licente — afișează licența locală curentă (dacă există) și statusul.
     */
    public function index()
    {
        $path = storage_path('license.json');
        $license = null;
        $error = null;
        $isValid = false;
        $validUntil = null;

        if (!file_exists($path)) {
            return view('license-client::licente.index', [
                'license' => null,
                'error' => null,
                'isValid' => false,
                'validUntil' => null,
            ]);
        }

        try {
            $raw = file_get_contents($path);
            $wrapper = json_decode($raw, true);

            if (!is_array($wrapper) || empty($wrapper['payload'])) {
                $error = 'Fișierul de licență este corupt sau are un format neașteptat.';
            } else {
                $decrypted = Encryption::decryptString($wrapper['payload']);
                $license = json_decode($decrypted, true);

                $data = $license['data'] ?? [];
                if (!empty($data)) {
                    if (array_key_exists('valid', $data)) {
                        $isValid = (bool) $data['valid'];
                    }

                    foreach (['expires_at', 'valid_until', 'expiry', 'expires', 'valid_until_at'] as $k) {
                        if (!empty($data[$k])) {
                            try {
                                $dt = new \DateTime($data[$k]);
                                $validUntil = $dt->format(DATE_ATOM);
                                if (empty($isValid)) {
                                    $isValid = (new \DateTime()) < $dt;
                                }
                                break;
                            } catch (\Throwable $e) {
                                // ignorăm erorile de parsare
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            $error = 'Eroare la citirea licenței: ' . $e->getMessage();
        }

        return view('license-client::licente.index', [
            'license' => $license,
            'error' => $error,
            'isValid' => $isValid,
            'validUntil' => $validUntil,
        ]);
    }

    /**
     * POST /licente/upload — instalare manuală de licență din interfață.
     */
    public function upload(Request $request)
    {
        $request->validate(['license_key' => 'required|string']);
        $key = trim($request->input('license_key'));

        $existingPath = storage_path('license.json');
        if (file_exists($existingPath)) {
            try {
                $raw = file_get_contents($existingPath);
                $wrapper = json_decode($raw, true);

                if (is_array($wrapper) && !empty($wrapper['payload'])) {
                    $decrypted = Encryption::decryptString($wrapper['payload']);
                    $existing = json_decode($decrypted, true);
                    $existingData = $existing['data'] ?? [];
                    $existingValid = $existingData['valid'] ?? false;

                    if ($existingValid) {
                        return redirect()
                            ->route('license-client.licente.index')
                            ->with('license_error', 'O licență validă este deja instalată și nu poate fi suprascrisă. Ștergeți-o manual mai întâi.');
                    }
                }
            } catch (\Throwable $e) {
                // dacă fișierul este corupt, permitem suprascrierea
            }
        }

        $payload = [
            'license_key' => $key,
            'domain' => parse_url(config('app.url') ?? env('APP_URL', ''), PHP_URL_HOST) ?: gethostname(),
            'data' => [
                'valid' => false,
                'issued_by_manual_upload' => true,
                'needs_server_verification' => true,
            ],
            'fetched_at' => now()->toIso8601String(),
        ];

        try {
            $plaintext = json_encode($payload, JSON_UNESCAPED_SLASHES);
            $encrypted = Encryption::encryptString($plaintext);
            $wrapper = json_encode([
                'encrypted' => true,
                'version' => 1,
                'payload' => $encrypted,
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

            file_put_contents($existingPath, $wrapper);

            return redirect()
                ->route('license-client.licente.index')
                ->with('license_success', 'Licența a fost salvată local. Este necesară verificarea pe server.');
        } catch (\Throwable $e) {
            return redirect()
                ->route('license-client.licente.index')
                ->with('license_error', 'Eroare la instalare: ' . $e->getMessage());
        }
    }

    /**
     * DELETE /licente — șterge fișierul local de licență.
     */
    public function destroy()
    {
        $path = storage_path('license.json');
        if (!file_exists($path)) {
            return redirect()->route('license-client.licente.index')->with('license_error', 'Nu există fișier de licență de șters.');
        }

        try {
            $raw = file_get_contents($path);
            $wrapper = json_decode($raw, true);

            if (is_array($wrapper) && !empty($wrapper['payload'])) {
                try {
                    $decrypted = Encryption::decryptString($wrapper['payload']);
                    $existing = json_decode($decrypted, true);
                    $existingData = $existing['data'] ?? [];

                    if (!empty($existingData['valid'])) {
                        return redirect()->route('license-client.licente.index')->with('license_error', 'Licența este validă și nu poate fi ștearsă din interfață.');
                    }
                } catch (\Throwable $e) {
                    // dacă decriptarea eșuează, continuăm ștergerea
                }
            }

            unlink($path);
            return redirect()->route('license-client.licente.index')->with('license_success', 'Fișierul de licență a fost șters.');
        } catch (\Throwable $e) {
            return redirect()->route('license-client.licente.index')->with('license_error', 'Eroare la ștergere: ' . $e->getMessage());
        }
    }

    /**
     * POST /licente/verify — verifică licența locală cu autoritatea.
     */
    public function verify(Request $request)
    {
        $path = storage_path('license.json');
        if (!file_exists($path)) {
            return redirect()->route('license-client.licente.index')->with('license_error', 'Nu există nicio licență instalată.');
        }

        try {
            $raw = file_get_contents($path);
            $wrapper = json_decode($raw, true);

            if (!is_array($wrapper) || empty($wrapper['payload'])) {
                return redirect()->route('license-client.licente.index')->with('license_error', 'Fișierul de licență este corupt.');
            }

            $decrypted = Encryption::decryptString($wrapper['payload']);
            $license = json_decode($decrypted, true);

            $key = $license['license_key'] ?? null;
            $authority = config('license-client.authority_url');

            if (empty($authority)) {
                return redirect()->route('license-client.licente.index')->with('license_error', 'Nu este configurat niciun endpoint al autorității.');
            }

            if (empty($key)) {
                return redirect()->route('license-client.licente.index')->with('license_error', 'Licența locală nu conține o cheie validă.');
            }

            $verifyUrl = rtrim($authority, '/') . '/api/licenses/verify';

            try {
                $resp = Http::timeout(config('license-client.remote_timeout', 5))
                    ->post($verifyUrl, [
                        'license_key' => $key,
                        'domain' => $license['domain'] ?? null,
                    ]);
            } catch (\Throwable $e) {
                return redirect()->route('license-client.licente.index')->with('license_error', 'Nu am putut contacta autoritatea: ' . $e->getMessage());
            }

            if (!$resp->successful()) {
                return redirect()->route('license-client.licente.index')
                    ->with('license_error', 'Verificare eșuată (HTTP ' . $resp->status() . '). ' . $resp->body());
            }

            $json = $resp->json();
            if (!empty($json['valid'])) {
                $license['data']['valid'] = true;
                $license['data']['verified_at'] = now()->toIso8601String();

                $plaintext = json_encode($license, JSON_UNESCAPED_SLASHES);
                $encrypted = Encryption::encryptString($plaintext);
                $wrapper = json_encode(['encrypted' => true, 'version' => 1, 'payload' => $encrypted], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

                file_put_contents($path, $wrapper);
                return redirect()->route('license-client.licente.index')->with('license_success', 'Licența a fost verificată și marcată ca validă.');
            }

            return redirect()->route('license-client.licente.index')->with('license_error', 'Autoritatea a răspuns că licența nu este validă.');
        } catch (\Throwable $e) {
            return redirect()->route('license-client.licente.index')->with('license_error', 'Verificare eșuată: ' . $e->getMessage());
        }
    }
}
