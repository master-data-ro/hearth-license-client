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
                    if (!empty($data['valid'])) {
                        $isValid = true;
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

        // verificăm dacă există o licență validă
        if (file_exists($existingPath)) {
            try {
                $raw = file_get_contents($existingPath);
                $wrapper = json_decode($raw, true);
                if (is_array($wrapper) && !empty($wrapper['payload'])) {
                    $decrypted = Encryption::decryptString($wrapper['payload']);
                    $existing = json_decode($decrypted, true);
                    $existingValid = $existing['data']['valid'] ?? false;
                    if ($existingValid) {
                        return redirect()->route('license-client.licente.index')
                            ->with('license_error', 'O licență validă este deja instalată și nu poate fi suprascrisă. Ștergeți-o manual mai întâi.');
                    }
                }
            } catch (\Throwable $e) {
                // dacă fișierul este corupt, permitem suprascrierea
            }
        }

        // verificare la autoritate
        $authority = config('license-client.authority_url');
        if (empty($authority)) {
            return redirect()->route('license-client.licente.index')
                ->with('license_error', 'Autoritatea nu este configurată; nu se poate verifica licența online.');
        }

        $verifyPath = config('license-client.verify_endpoint', '/api/verify');
        $verifyUrl = rtrim($authority, '/') . '/' . ltrim($verifyPath, '/');

        try {
            $resp = Http::timeout(config('license-client.remote_timeout', 5))
                $json = $resp->json();

                // Emulează exact logica make:license-server
                if (empty($json['data']) || !isset($json['signature'])) {
                    return redirect()->route('license-client.licente.index')->with('license_error', 'Răspuns invalid de la autoritate (așteptat data+signature).');
                }

                $data = $json['data'];
                $signature = base64_decode($json['signature']);

                // Fetch public PEM from authority (configurable)
                $pemPath = config('license-client.pem_endpoint', '/keys/pem');
                try {
                    $pemResp = Http::timeout(config('license-client.remote_timeout', 5))->get(rtrim($authority, '/') . '/' . ltrim($pemPath, '/'));
                    if (! $pemResp->successful()) {
                        return redirect()->route('license-client.licente.index')->with('license_error', 'Nu am putut prelua cheia publică de la autoritate: HTTP ' . $pemResp->status());
                    }
                    $pem = $pemResp->body();
                } catch (\Throwable $e) {
                    return redirect()->route('license-client.licente.index')->with('license_error', 'Eroare la descărcarea cheii publice: ' . $e->getMessage());
                }

                $payloadJson = json_encode($data);
                $pub = openssl_pkey_get_public($pem);
                if ($pub === false) {
                    return redirect()->route('license-client.licente.index')->with('license_error', 'Cheia publică primită de la autoritate este invalidă.');
                }

                $ok = openssl_verify($payloadJson, $signature, $pub, OPENSSL_ALGO_SHA256) === 1;
                openssl_free_key($pub);

                if (!$ok) {
                    return redirect()->route('license-client.licente.index')->with('license_error', 'Verificarea semnăturii a eșuat.');
                }

                // Persist exact ca make:license-server
                $payload = [
                    'license_key' => $key,
                    'domain' => parse_url(config('app.url') ?? env('APP_URL', ''), PHP_URL_HOST) ?: gethostname(),
                    'data' => $data,
                    'fetched_at' => now()->toIso8601String(),
                    'authority' => $authority,
                ];

                try {
                    $passphrase = $request->input('passphrase') ?: env('APP_LICENSE_PASSPHRASE', null);
                    $plaintext = json_encode($payload, JSON_UNESCAPED_SLASHES);
                    $encrypted = Encryption::encryptString($plaintext, $passphrase);
                    $wrapper = json_encode([
                        'encrypted' => true,
                        'version' => 1,
                        'payload' => $encrypted,
                    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                    file_put_contents($existingPath, $wrapper);
                    $serverMessage = $data['message'] ?? null;
                    $msg = 'Licența a fost verificată și salvată local.' . ($serverMessage ? ' Mesaj server: ' . $serverMessage : '');
                    return redirect()->route('license-client.licente.index')->with('license_success', $msg);
                } catch (\Throwable $e) {
                    return redirect()->route('license-client.licente.index')->with('license_error', 'Eroare la salvarea licenței: ' . $e->getMessage());
                }
                $encrypted = Encryption::encryptString($plaintext);
                $wrapper = json_encode(['encrypted' => true, 'version' => 1, 'payload' => $encrypted], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                file_put_contents($existingPath, $wrapper);
                return redirect()->route('license-client.licente.index')->with('license_success', 'Licența a fost trimisă spre aprobare (pending).');
            } catch (\Throwable $e) {
                return redirect()->route('license-client.licente.index')
                    ->with('license_error', 'Eroare la salvarea licenței: ' . $e->getMessage());
            }
        }

        return redirect()->route('license-client.licente.index')
            ->with('license_error', 'Autoritatea a răspuns că licența nu este validă.');
    }

    /**
     * DELETE /licente — șterge fișierul local de licență.
     */
    public function destroy()
    {
        $path = storage_path('license.json');
        if (!file_exists($path)) {
            return redirect()->route('license-client.licente.index')
                ->with('license_error', 'Nu există fișier de licență de șters.');
        }

        try {
            $raw = file_get_contents($path);
            $wrapper = json_decode($raw, true);
            if (is_array($wrapper) && !empty($wrapper['payload'])) {
                try {
                    $decrypted = Encryption::decryptString($wrapper['payload']);
                    $existing = json_decode($decrypted, true);
                    if (!empty($existing['data']['valid'])) {
                        return redirect()->route('license-client.licente.index')
                            ->with('license_error', 'Licența este validă și nu poate fi ștearsă din interfață.');
                    }
                } catch (\Throwable $e) {
                    // dacă decriptarea eșuează, continuăm
                }
            }

            unlink($path);
            return redirect()->route('license-client.licente.index')->with('license_success', 'Fișierul de licență a fost șters.');
        } catch (\Throwable $e) {
            return redirect()->route('license-client.licente.index')
                ->with('license_error', 'Eroare la ștergere: ' . $e->getMessage());
        }
    }

    /**
     * POST /licente/verify — verifică licența locală cu autoritatea (semnătură + PEM).
     */
    public function verify(Request $request)
    {
        $path = storage_path('license.json');
        if (!file_exists($path)) {
            return redirect()->route('license-client.licente.index')
                ->with('license_error', 'Nu există nicio licență instalată.');
        }

        try {
            $raw = file_get_contents($path);
            $wrapper = json_decode($raw, true);
            if (!is_array($wrapper) || empty($wrapper['payload'])) {
                return redirect()->route('license-client.licente.index')
                    ->with('license_error', 'Fișierul de licență este corupt.');
            }

            $decrypted = Encryption::decryptString($wrapper['payload']);
            $license = json_decode($decrypted, true);
            $key = $license['license_key'] ?? null;
            $authority = config('license-client.authority_url');

            if (empty($authority) || empty($key)) {
                return redirect()->route('license-client.licente.index')
                    ->with('license_error', 'Configurare invalidă: lipsește endpointul sau cheia.');
            }

            // === apel API ===
            $verifyPath = config('license-client.verify_endpoint', '/api/verify');
            $verifyUrl = rtrim($authority, '/') . '/' . ltrim($verifyPath, '/');
            $resp = Http::timeout(config('license-client.remote_timeout', 5))
                ->post($verifyUrl, [
                    'license_key' => $key,
                    'domain' => $license['domain'] ?? null,
                ]);

            if (!$resp->successful()) {
                return redirect()->route('license-client.licente.index')
                    ->with('license_error', 'Verificare eșuată (HTTP ' . $resp->status() . ').');
            }

            $json = $resp->json();
            if (empty($json['data']) || empty($json['signature'])) {
                return redirect()->route('license-client.licente.index')
                    ->with('license_error', 'Răspuns invalid de la autoritate (lipsă data+signature).');
            }

            // === verificare semnătură ===
            $data = $json['data'];
            $signature = base64_decode($json['signature']);
            $pemPath = config('license-client.pem_endpoint', '/keys/pem');
            $pemResp = Http::timeout(config('license-client.remote_timeout', 5))
                ->get(rtrim($authority, '/') . '/' . ltrim($pemPath, '/'));

            if (!$pemResp->successful()) {
                return redirect()->route('license-client.licente.index')
                    ->with('license_error', 'Nu am putut prelua cheia publică de la autoritate.');
            }

            $pub = openssl_pkey_get_public($pemResp->body());
            if (!$pub) {
                return redirect()->route('license-client.licente.index')
                    ->with('license_error', 'Cheia publică primită este invalidă.');
            }

            $ok = openssl_verify(json_encode($data), $signature, $pub, OPENSSL_ALGO_SHA256) === 1;
            openssl_free_key($pub);

            if (!$ok) {
                return redirect()->route('license-client.licente.index')
                    ->with('license_error', 'Semnătura autorității nu este validă.');
            }

            // === salvăm actualizarea ===
            $license['data'] = $data;
            $license['fetched_at'] = now()->toIso8601String();

            $plaintext = json_encode($license, JSON_UNESCAPED_SLASHES);
            $encrypted = Encryption::encryptString($plaintext, env('APP_LICENSE_PASSPHRASE', null));
            $wrapper = json_encode(['encrypted' => true, 'version' => 1, 'payload' => $encrypted], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            file_put_contents($path, $wrapper);

            return redirect()->route('license-client.licente.index')
                ->with('license_success', 'Licența a fost verificată și actualizată cu succes.');
        } catch (\Throwable $e) {
            return redirect()->route('license-client.licente.index')
                ->with('license_error', 'Eroare la verificare: ' . $e->getMessage());
        }
    }
}
