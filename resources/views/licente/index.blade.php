@extends('layouts.app')
@section('content')
<div class="container py-4">
    <h1>Licență locală</h1>

    {{-- Prefer license-specific flash keys to avoid duplicate generic alerts rendered by host layout --}}
    @if (session('license_error'))
        <div class="alert alert-danger">{{ session('license_error') }}</div>
    @elseif (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    @if (session('license_success'))
        <div class="alert alert-success">{{ session('license_success') }}</div>
    @elseif (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if(!empty($error))
        <div class="alert alert-warning">{{ $error }}</div>
    @endif

    @if(empty($license))
        <p>Nu există licență instalată pe această aplicație.</p>
    @else
        <div class="card mb-3">
            <div class="card-body">
                <h5 class="card-title">Cheie: {{ $license['license_key'] ?? '—' }}</h5>
                <p class="card-text">Domeniu: {{ $license['domain'] ?? '—' }}</p>

                <p class="card-text">Status:
                    @if(!empty($isValid))
                        <span class="badge bg-success">Validă</span>
                    @else
                        <span class="badge bg-danger">Invalidă</span>
                    @endif
                </p>

                @if(!empty($validUntil))
                    <p class="card-text">Valabilă până la: {{ $validUntil }}</p>
                @endif

                <p class="card-text">Informații suplimentare:</p>
                <pre class="small bg-light p-2">{{ json_encode($license['data'] ?? [], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
        </div>

        <div class="mb-2">
            @php
                $isManual = isset($license['data']['issued_by_manual_upload']) && $license['data']['issued_by_manual_upload'];
                $isValid = isset($license['data']['valid']) && $license['data']['valid'];
                $isPending = isset($license['data']['pending']) && $license['data']['pending'];
            @endphp

            @if ($isValid)
                <div class="alert alert-success">Licența este instalată și validă.</div>
            @elseif ($isPending)
                <div class="alert alert-warning">Licența a fost trimisă spre aprobare și este în așteptare.</div>
                <form method="POST" action="{{ route('license-client.licente.verify') }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-primary">Re-verifică la autoritate</button>
                </form>
                <form method="POST" action="{{ route('license-client.licente.destroy') }}" class="d-inline ms-2">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-danger" onclick="return confirm('Șterge fișierul local de licență?')">Șterge licența</button>
                </form>
            @else
                <div class="alert alert-danger">Licența instalată nu este validă. Introduceți o licență nouă mai jos și folosiți butonul de verificare online.</div>
                <form method="POST" action="{{ route('license-client.licente.destroy') }}" class="d-inline ms-2">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-secondary" onclick="return confirm('Șterge fișierul local de licență?')">Șterge licența curentă</button>
                </form>
            @endif
        </div>
    @endif

    <hr />

    <h3>Instalează manual o licență</h3>

    {{-- Un singur formular de upload+verificare: dacă există licență validă, îl dezactivăm. --}}
    <form method="POST" action="{{ route('license-client.licente.upload') }}" class="mb-3">
        @csrf
        <div class="mb-2">
            <label class="form-label">Cheie de licență (paste)</label>
            <input name="license_key" class="form-control" placeholder="Introduceți cheie de licență" @if(!empty($isValid)) disabled @endif>
        </div>
        <button type="submit" class="btn btn-primary" @if(!empty($isValid)) disabled title="Licența este validă și nu poate fi înlocuită" @endif>Verifică și instalează</button>
    </form>

</div>
@endsection
