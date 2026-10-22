<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Checkpoint QR · {{ $client->name }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 24px; font-family: ui-sans-serif, system-ui, sans-serif; color: #111827; background: #fff; }
        header { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 16px; }
        h1 { font-size: 18px; margin: 0; }
        p { margin: 0; color: #4b5563; font-size: 12px; }
        .grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0; }
        .sticker { border: 1px dashed #9ca3af; padding: 18px; text-align: center; break-inside: avoid; }
        .sticker svg { width: 200px; height: 200px; }
        .name { font-size: 16px; font-weight: 700; margin-top: 8px; }
        .site { font-size: 12px; color: #4b5563; }
        .hint { font-size: 11px; color: #6b7280; margin-top: 6px; }
        button { padding: 8px 14px; border-radius: 8px; border: 1px solid #d1d5db; background: #fff; font-weight: 600; cursor: pointer; }
        @media print { button { display: none; } body { padding: 0; } }
    </style>
</head>
<body>
    <header>
        <div>
            <h1>Patrol checkpoints · {{ $client->name }}</h1>
            <p>{{ $client->locationLabel() }} · {{ $stickers->count() }} {{ str('checkpoint')->plural($stickers->count()) }}. Cut along the dashed lines and stick each at its spot.</p>
        </div>
        <button type="button" onclick="window.print()">Print</button>
    </header>

    <div class="grid">
        @forelse ($stickers as $sticker)
            <div class="sticker">
                {!! $sticker['svg'] !!}
                <div class="name">{{ $sticker['name'] }}</div>
                <div class="site">{{ $client->name }}</div>
                <div class="hint">OSSI patrol checkpoint · scan with My Attendance</div>
            </div>
        @empty
            <p>No checkpoints yet. Add them on Patrols → Checkpoints.</p>
        @endforelse
    </div>
</body>
</html>
