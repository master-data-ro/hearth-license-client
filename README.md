# Hearth License Client

[View on GitHub](https://github.com/emsici/hearth-license-client) | [View on Packagist](https://packagist.org/packages/hearth/license-client)

## Instalare / Installation

Adaugă în `composer.json` sau instalează direct:

```bash
composer require hearth/license-client
```

Pentru testare locală, poți adăuga un repository de tip `path`:

```json
"repositories": [
  {
    "type": "path",
    "url": "../cale/către/hearth/master-data/sdk/laravel-license-client"
  }
]
```

## Utilizare / Usage

1. Rulează comanda artisan pentru a valida o cheie de licență (va contacta autoritatea):

```bash
php artisan make:license-server LICENTA-TA
```

2. La succes, pachetul va salva fișierul `storage/license.json` cu metadatele licenței (criptat).

3. Middleware-ul `Hearth\\LicenseClient\\Middleware\\EnsureHasValidLicense` va fi adăugat automat în grupa `web` la boot. Aplicația va returna HTTP 403 până când există o licență validă.

## Cum funcționează (Principiul "ping-pong")

1. **Clientul** (aplicația ta) trimite cheia de licență și domeniul către autoritate (hearth.master-data.ro) folosind comanda:
   ```bash
   php artisan make:license-server YOUR-LICENSE-KEY
   ```
2. **Autoritatea** verifică cheia și domeniul:
   - Dacă licența este validă, răspunde cu un payload semnat și criptat, ce conține metadatele licenței.
   - Dacă licența nu există sau necesită aprobare, răspunde cu un mesaj de pending/în așteptare.
   - Dacă licența este invalidă, răspunde cu eroare și motiv.
3. **Clientul** verifică semnătura autorității (folosind cheia publică) și salvează local payload-ul, criptat cu `APP_KEY` sau `APP_LICENSE_PASSPHRASE`.
4. **Middleware-ul** pachetului blochează accesul la aplicație până când există o licență validă și verificată local.
5. Poți re-verifica oricând licența locală cu autoritatea (din UI sau CLI) pentru a actualiza statusul.

Acest flux asigură că doar licențele validate de autoritate pot debloca aplicația, iar orice modificare locală este detectată și blocată.

## Diagramă flux licențiere (textuală, compatibilă cu GitHub):

1. **Clientul** (comandă/Interfață): Trimite cheie + domeniu către autoritate
2. **Autoritatea** (hearth.master-data.ro): Răspunde cu status (valid/pending/invalid) + semnătură
3. **Clientul**: Verifică semnătura, salvează local fișierul de licență criptat
4. **Middleware**: Verifică la fiecare request dacă licența este validă
5. **Aplicația Laravel**: Permite acces doar dacă licența este validă

Flux simplificat:

Client → Autoritate → Client → Middleware → Aplicație

- Cerere licență   →   Răspuns semnat   →   Salvare locală   →   Enforcement   →   Acces

## Detalii suplimentare

- **Verificare periodică:** Poți re-verifica licența oricând (din UI sau CLI) pentru a actualiza statusul fără a reinstala.
- **Pending/În aprobare:** Dacă autoritatea răspunde cu pending, aplicația va afișa statusul "În aprobare" și va bloca funcționalitatea până la aprobare.
- **Securitate:** Orice modificare manuală a fișierului de licență va fi detectată și va bloca accesul.
- **Push automat:** Autoritatea poate trimite licențe noi/actualizate direct către endpoint-ul clientului 
- **Configurare flexibilă:** Endpoint-urile și comportamentul pot fi ajustate din `config/license-client.php` și `.env`.
- **Debug:** Mesajele de la autoritate sunt afișate clar în UI pentru transparență.

## Securitate / Security

- Licența este salvată local, criptată cu `APP_KEY` (sau `APP_LICENSE_PASSPHRASE` dacă este setat).
- Cheia publică a autorității este preluată automat de la: `https://hearth.master-data.ro/keys/pem`.
- Pentru extra siguranță, folosește o parolă dedicată în `.env`:
  ```env
  APP_LICENSE_PASSPHRASE=parola_ta_licenta
  ```

## Notă enforcement

- Middleware-ul de enforcement nu poate fi dezactivat din environment.

## Linkuri utile

- [GitHub: emsici/hearth-license-client](https://github.com/emsici/hearth-license-client)
- [Packagist: hearth/license-client](https://packagist.org/packages/hearth/license-client)

---
© 2025 master-data.ro
