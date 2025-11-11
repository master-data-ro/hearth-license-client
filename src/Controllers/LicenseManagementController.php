<?php

namespace Hearth\LicenseClient\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Hearth\LicenseClient\Encryption;

class LicenseManagementController extends Controller
{
    public function index()
    {
        $path = storage_path('license.json');
        $license = null;
        $error = null;

        $isValid = false;
        $validUntil = null;

        if (!file_exists($path)) {
            return view('license-client::licente.index', ['license' => null, 'error' => null, 'isValid' => false, 'validUntil' => null]);
        }

        try {
            $raw = file_get_contents($path);
            $wrapper = json_decode($raw, true);
            if (!is_array($wrapper) || empty($wrapper['payload'])) {
                $error = 'Fișierul de licență este corupt sau format neașteptat.';
            } else {
                $decrypted = Encryption::decryptString($wrapper['payload']);
                $license = json_decode($decrypted, true);
                // Determine validity: look for common fields
                $data = $license['data'] ?? [];
                if (!empty($data)) {
                    if (array_key_exists('valid', $data)) {
                        $isValid = (bool) $data['valid'];
                    }

                    // try various expiry keys
                    foreach (['expires_at','valid_until','expiry','expires','valid_until_at'] as $k) {
                        if (!empty($data[$k])) {
                            try {
                                $dt = new \DateTime($data[$k]);
                                $validUntil = $dt->format(DATE_ATOM);
                                if (empty($isValid)) {
                                    $isValid = (new \DateTime()) < $dt;
                                }
                                break;
                            } catch (\Throwable $e) {
                                // ignore parse errors
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
     * POST /licente/verify — a lightweight verify action that simply ensures the stored
     * license can be decrypted. More advanced server checks can be implemented later.
     */
    public function verify(Request $request)
    {
        $path = storage_path('license.json');
        if (!file_exists($path)) {
            return redirect()->route('license-client.licente.index')->with('error', 'Nu există nicio licență instalată.');
        }

        try {
            $raw = file_get_contents($path);
            $wrapper = json_decode($raw, true);
            if (!is_array($wrapper) || empty($wrapper['payload'])) {
                return redirect()->route('license-client.licente.index')->with('error', 'Fișierul de licență este corupt.');
            }
            $decrypted = Encryption::decryptString($wrapper['payload']);
            // If decrypt didn't throw, consider it valid for now
            return redirect()->route('license-client.licente.index')->with('success', 'Licența a fost decriptată cu succes.');
        } catch (\Throwable $e) {
            return redirect()->route('license-client.licente.index')->with('error', 'Verificare eșuată: ' . $e->getMessage());
        }
    }

    /**
     * POST /licente/upload — accept a manual license key from the admin UI.
     * Body: license_key=STRING
     */
    public function upload(Request $request)
    {
        $request->validate(['license_key' => 'required|string']);

        $key = trim($request->input('license_key'));

        // Prevent overwriting a valid existing license from the UI.
        $existingPath = storage_path('license.json');
        if (file_exists($existingPath)) {
            try {
                $raw = file_get_contents($existingPath);
                $wrapper = json_decode($raw, true);
                if (is_array($wrapper) && !empty($wrapper['payload'])) {
                    $decrypted = Encryption::decryptString($wrapper['payload']);
                    $existing = json_decode($decrypted, true);
                    $existingData = $existing['data'] ?? [];
                    $existingValid = false;
                    if (array_key_exists('valid', $existingData)) {
                        $existingValid = (bool) $existingData['valid'];
                    }
                    if ($existingValid) {
                        return redirect()->route('license-client.licente.index')->with('error', 'O licență validă este deja instalată și nu poate fi suprascrisă din interfață. Ștergeți-o manual mai întâi sau folosiți fluxul de autorizare.');
                    }
                }
            } catch (\Throwable $e) {
                // ignore and allow upload if existing is unreadable
            }
        }

        // Create a minimal license payload and save it encrypted. This allows
        // administrators to paste an authority-provided license key.
        $payload = [
            'license_key' => $key,
            'domain' => parse_url(config('app.url') ?? env('APP_URL', ''), PHP_URL_HOST) ?: gethostname(),
            'data' => ['valid' => true, 'issued_by_manual_upload' => true],
            'fetched_at' => now()->toIso8601String(),
        ];

        try {
            $plaintext = json_encode($payload, JSON_UNESCAPED_SLASHES);
            $encrypted = Encryption::encryptString($plaintext);
            $wrapper = json_encode([
                'encrypted' => true,
                'version' => 1,
                'payload' => $encrypted,
            ], JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);

            file_put_contents(storage_path('license.json'), $wrapper);
            return redirect()->route('license-client.licente.index')->with('success', 'Licența a fost instalată local.');
        } catch (\Throwable $e) {
            return redirect()->route('license-client.licente.index')->with('error', 'Eroare la instalare: ' . $e->getMessage());
        }
    }

    public function destroy()
    {
        $path = storage_path('license.json');
        if (file_exists($path)) {
            // Prevent deleting a valid installed license from the UI
            try {
                $raw = file_get_contents($path);
                $wrapper = json_decode($raw, true);
                if (is_array($wrapper) && !empty($wrapper['payload'])) {
                    try {
                        $decrypted = Encryption::decryptString($wrapper['payload']);
                        $existing = json_decode($decrypted, true);
                        $existingData = $existing['data'] ?? [];
                        if (array_key_exists('valid', $existingData) && $existingData['valid']) {
                            return redirect()->route('license-client.licente.index')->with('error', 'Licența este validă și nu poate fi ștearsă din interfață.');
                        }
                    } catch (\Throwable $e) {
                        // ignore decryption errors and allow deletion
                    }
                }

                unlink($path);
                return redirect()->route('license-client.licente.index')->with('success', 'Fișierul de licență a fost șters.');
            } catch (\Throwable $e) {
                return redirect()->route('license-client.licente.index')->with('error', 'Eroare la ștergere: ' . $e->getMessage());
            }
        }

        return redirect()->route('license-client.licente.index')->with('error', 'Nu există fișier de licență de șters.');
    }
}
