<div class="nyk-db-sync {{ ! empty($standalone) ? 'nyk-db-sync-page' : '' }}">
    <style>
        .nyk-db-sync { font: 16px/1.5 ui-sans-serif, system-ui, sans-serif; color: #1c1917; }
        .nyk-db-sync-page { max-width: 880px; margin: 0 auto; padding: 32px 20px 64px; }
        .nyk-db-sync h1 { font-size: 1.6rem; margin: 0 0 8px; }
        .nyk-db-sync h2 { font-size: 1.05rem; margin: 0 0 8px; }
        .nyk-db-sync p { margin: 0 0 12px; }
        .nyk-db-sync .muted { color: #57534e; font-size: 0.92rem; }
        .nyk-db-sync .panel { background: #fff; border: 1px solid #e7e5e4; border-radius: 16px; padding: 20px; margin-top: 16px; }
        .nyk-db-sync .warn { border-color: #fcd34d; background: #fffbeb; }
        .nyk-db-sync .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin: 12px 0; }
        .nyk-db-sync .stat { border: 1px solid #e7e5e4; border-radius: 12px; padding: 12px; }
        .nyk-db-sync .stat span { display: block; color: #78716c; font-size: 0.75rem; }
        .nyk-db-sync .stat strong { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.95rem; }
        .nyk-db-sync .live { color: #166534; font-weight: 650; }
        .nyk-db-sync .down { color: #991b1b; font-weight: 650; }
        .nyk-db-sync .stat .detail { margin-top: 4px; word-break: break-word; }
        .nyk-db-sync table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        .nyk-db-sync th, .nyk-db-sync td { text-align: left; padding: 6px 8px 6px 0; border-bottom: 1px solid #f5f5f4; }
        .nyk-db-sync label { display: block; font-size: 0.92rem; margin: 10px 0; }
        .nyk-db-sync select, .nyk-db-sync input[type="text"] { width: 100%; max-width: 320px; padding: 8px 10px; border: 1px solid #d6d3d1; border-radius: 10px; }
        .nyk-db-sync button { background: #b45309; color: white; border: 0; border-radius: 10px; padding: 8px 14px; font-weight: 650; cursor: pointer; }
        .nyk-db-sync button.secondary { background: #1c1917; }
        .nyk-db-sync .check { display: flex; gap: 8px; align-items: center; }
        .nyk-db-sync .banner { padding: 10px 12px; border-radius: 10px; margin-bottom: 12px; }
        .nyk-db-sync .ok { background: #ecfdf3; color: #166534; }
        .nyk-db-sync .bad { background: #fef2f2; color: #991b1b; }
        .nyk-db-sync code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.85em; }
    </style>

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
            @foreach ($connections as $connection)
                <div class="stat">
                    <span>{{ $connection['role'] }}</span>
                    <strong>{{ $connection['name'] !== '' ? $connection['name'] : 'not configured' }}</strong>
                    <span class="detail {{ $connection['connected'] ? 'live' : 'down' }}">
                        {{ $connection['connected'] ? 'Connected' : 'Not connected' }}
                        @if ($connection['driver'] || $connection['database'])
                            · {{ $connection['driver'] }} {{ $connection['database'] }}
                        @endif
                    </span>
                    @if ($connection['error'])
                        <span class="detail down">{{ $connection['error'] }}</span>
                    @endif
                </div>
            @endforeach
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
        <p>{{ $estimate['label'] }}</p>
        @if ($estimate['note'] !== '')
            <p class="muted">{{ $estimate['note'] }}</p>
        @endif
        <p class="muted">Missing tables are created from the primary. Existing columns are left as they are. New columns are added.</p>
        @if ($summary)
            <p class="muted">
                Last sync {{ $summary['ran_at'] ?? '' }}
                ({{ $summary['primary'] ?? '' }} → {{ $summary['secondary'] ?? '' }})
                @if ($lastDuration)
                    took {{ $lastDuration }}.
                @endif
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
</div>
