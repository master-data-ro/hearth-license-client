@extends('layouts.app')

@section('content')
<div class="container py-4">
    <h1>Licență locală</h1>

    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif
    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if($error)
        <div class="alert alert-warning">{{ $error }}</div>
    @endif

    @if(!$license)
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
            <form method="POST" action="{{ route('license-client.licente.verify') }}" class="d-inline">
                @csrf
                <button class="btn btn-primary" @if(!empty($isValid)) disabled title="Licența este validă și nu poate fi verificată manual">Verifică licența</button>
            </form>

            <form method="POST" action="{{ route('license-client.licente.destroy') }}" class="d-inline ms-2">
                @csrf
                @method('DELETE')
                <button class="btn btn-danger" @if(!empty($isValid)) disabled title="Licența este validă și nu poate fi ștersă din interfață" onclick="return false;" @else onclick="return confirm('Șterge fișierul local de licență?')" @endif>Șterge licența</button>
            </form>
        </div>
    @endif
    <hr />
    <h3>Instalează manual o licență</h3>
    @if(!empty($isValid))
        <div class="alert alert-info">O licență validă este deja instalată. Dacă vrei s-o înlocuiești, mai întâi șterge fișierul de licență (din server) sau dezactivează licența curentă.</div>
    @else
        <form method="POST" action="{{ route('license-client.licente.upload') }}" class="mb-3">
            @csrf
            <div class="mb-2">
                <label class="form-label">Cheie de licență (paste)</label>
                <input name="license_key" class="form-control" placeholder="Introduceți cheie de licență">
            </div>
            <button type="submit" class="btn btn-primary">Salvează și verifică</button>
        </form>
    @endif
</div>
@endsection
