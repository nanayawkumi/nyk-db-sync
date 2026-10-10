<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Database sync</title>
    <style>
        :root { color-scheme: light; }
        body { margin: 0; font: 16px/1.5 ui-sans-serif, system-ui, sans-serif; background: #f6f4ef; color: #1c1917; }
        main { max-width: 880px; margin: 0 auto; padding: 32px 20px 64px; }
        h1 { font-size: 1.6rem; margin: 0 0 8px; }
        h2 { font-size: 1.05rem; margin: 0 0 8px; }
        p { margin: 0 0 12px; }
        .muted { color: #57534e; font-size: 0.92rem; }
        .panel { background: #fff; border: 1px solid #e7e5e4; border-radius: 16px; padding: 20px; margin-top: 16px; }
        .warn { border-color: #fcd34d; background: #fffbeb; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin: 12px 0; }
        .stat { border: 1px solid #e7e5e4; border-radius: 12px; padding: 12px; }
        .stat span { display: block; color: #78716c; font-size: 0.75rem; }
        .stat strong { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.95rem; }
        table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        th, td { text-align: left; padding: 6px 8px 6px 0; border-bottom: 1px solid #f5f5f4; }
        label { display: block; font-size: 0.92rem; margin: 10px 0; }
        select, input[type="text"] { width: 100%; max-width: 320px; padding: 8px 10px; border: 1px solid #d6d3d1; border-radius: 10px; }
        button { background: #b45309; color: white; border: 0; border-radius: 10px; padding: 8px 14px; font-weight: 650; cursor: pointer; }
        button.secondary { background: #1c1917; }
        .check { display: flex; gap: 8px; align-items: center; }
        .banner { padding: 10px 12px; border-radius: 10px; margin-bottom: 12px; }
        .ok { background: #ecfdf3; color: #166534; }
        .bad { background: #fef2f2; color: #991b1b; }
        code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.85em; }
    </style>
</head>
<body>
<main>
    <h1>Live database engine</h1>
    <p class="muted">
        Sync copies the primary schema and rows onto the secondary connection.
        Switching writes <code>DB_CONNECTION</code> in <code>.env</code>, clears config, and signals queue workers to restart.
    </p>

    @if (session('status'))
        <div class="banner ok">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="banner bad">{{ session('error') }}</div>
    @endif

    <section class="panel">
        <div class="grid">
            <div class="stat"><span>Primary</span><strong>{{ $primary }}</strong></div>
            <div class="stat"><span>Secondary</span><strong>{{ $secondary ?? 'not configured' }}</strong></div>
            <div class="stat"><span>Dual-write</span><strong>{{ $enabled ? 'On' : 'Off' }}</strong></div>
        </div>
        <p class="muted">
            @if ($alignment)
                {{ $alignment }}
            @else
                Row counts are close enough to switch.
            @endif
        </p>
    </section>

    <section class="panel">
        <h2>Sync</h2>
        <p class="muted">Missing tables are created from the primary. Existing columns are left as they are. New columns are added.</p>
        @if ($summary)
            <p class="muted">
                Last sync {{ $summary['ran_at'] ?? '' }}
                ({{ $summary['primary'] ?? '' }} → {{ $summary['secondary'] ?? '' }}).
            </p>
        @endif
        <form method="post" action="{{ route('nyk-db-sync.sync') }}">
            @csrf
            <label>
                Single table
                <input type="text" name="table" value="{{ old('table') }}" placeholder="all discovered tables">
            </label>
            <label class="check">
                <input type="checkbox" name="schema_only" value="1" @checked(old('schema_only'))>
                Schema only
            </label>
            <button class="secondary" type="submit">Run sync</button>
        </form>
        @if ($counts !== [])
            <table>
                <thead>
                    <tr><th>Table</th><th>Source</th><th>Destination</th><th>Gap</th></tr>
                </thead>
                <tbody>
                    @foreach ($counts as $row)
                        <tr>
                            <td><code>{{ $row['table'] }}</code></td>
                            <td>{{ $row['source'] }}</td>
                            <td>{{ $row['destination'] === null ? 'missing' : $row['destination'] }}</td>
                            <td>{{ $row['delta'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <section class="panel warn">
        <h2>Switch live engine</h2>
        <form method="post" action="{{ route('nyk-db-sync.switch') }}">
            @csrf
            <label>
                Switch live engine to
                <select name="engine">
                    @foreach ($engines as $engine)
                        <option value="{{ $engine }}" @selected(old('engine', $primary === 'pgsql' ? 'mysql' : 'pgsql') === $engine)>{{ $engine }}</option>
                    @endforeach
                </select>
            </label>
            <label class="check">
                <input type="checkbox" name="keep_dual_write" value="1" @checked(old('keep_dual_write', true))>
                Keep dual-write on after the switch
            </label>
            <label class="check">
                <input type="checkbox" name="confirmed" value="1">
                I understand this updates <code>.env</code> and restarts workers
            </label>
            <button type="submit" @disabled(! $reachable)>Switch live database</button>
        </form>
        <form method="post" action="{{ route('nyk-db-sync.repair') }}" style="margin-top: 16px;">
            @csrf
            <p class="muted">Align Postgres id sequences to the highest copied id. Switching to Postgres does this as well.</p>
            <button class="secondary" type="submit">Repair Postgres IDs</button>
        </form>
    </section>
</main>
</body>
</html>
