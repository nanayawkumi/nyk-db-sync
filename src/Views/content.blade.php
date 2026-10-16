<div class="nyk-db-sync {{ ! empty($standalone) ? 'nyk-db-sync-page' : '' }}">
    <style>
        .nyk-db-sync, .nyk-db-sync * { box-sizing: border-box; }
        .nyk-db-sync {
            --ink: #16324f;
            --muted: #5d7186;
            --card: #ffffff;
            --line: #d5e2ee;
            --teal: #0f6f78;
            --teal-deep: #0b555c;
            --amber: #c56a12;
            --good: #0d7a4f;
            --good-soft: #e5f6ee;
            --bad: #b42318;
            --bad-soft: #fdeceb;
            --warn: #8a5a12;
            --warn-soft: #fff5e6;
            font: 16px/1.5 ui-sans-serif, system-ui, sans-serif;
            color: var(--ink);
            background: linear-gradient(180deg, #f7fbff 0%, #e7f0f8 100%);
            border: 1px solid #c5d5e6;
            border-radius: 24px;
            padding: 24px;
            box-shadow: 0 16px 40px rgba(22, 50, 79, 0.08);
        }
        .nyk-db-sync-page { max-width: 920px; margin: 0 auto; padding: 28px 20px 64px; background: transparent; border: 0; box-shadow: none; }
        .nyk-db-sync h1 { font-size: 1.7rem; line-height: 1.2; margin: 0 0 8px; letter-spacing: -0.02em; }
        .nyk-db-sync h2 { font-size: 1.05rem; margin: 0 0 8px; }
        .nyk-db-sync p { margin: 0 0 12px; }
        .nyk-db-sync .lede { color: var(--muted); max-width: 62ch; }
        .nyk-db-sync .muted { color: var(--muted); font-size: 0.92rem; }
        .nyk-db-sync .panel { background: var(--card); border: 1px solid var(--line); border-radius: 18px; padding: 20px; margin-top: 16px; box-shadow: 0 1px 0 rgba(255, 255, 255, 0.8) inset; }
        .nyk-db-sync .warn { border-color: #f0d7b0; background: linear-gradient(180deg, #fffaf3 0%, #fff 40%); }
        .nyk-db-sync .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin: 12px 0; }
        .nyk-db-sync .stat { background: #f5f9fc; border: 1px solid var(--line); border-radius: 14px; padding: 14px; border-top: 3px solid var(--teal); }
        .nyk-db-sync .stat.down-card { border-top-color: var(--bad); background: var(--bad-soft); }
        .nyk-db-sync .stat.write-on { border-top-color: var(--good); }
        .nyk-db-sync .stat.write-off { border-top-color: #8aa0b5; }
        .nyk-db-sync .stat span { display: block; color: var(--muted); font-size: 0.75rem; letter-spacing: 0.04em; text-transform: uppercase; }
        .nyk-db-sync .stat strong { display: block; margin-top: 4px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 1rem; color: var(--ink); }
        .nyk-db-sync .live { color: var(--good); font-weight: 700; }
        .nyk-db-sync .down { color: var(--bad); font-weight: 700; }
        .nyk-db-sync .stat .detail { margin-top: 6px; text-transform: none; letter-spacing: 0; font-size: 0.82rem; word-break: break-word; }
        .nyk-db-sync .progress-top { display: flex; justify-content: space-between; gap: 12px; align-items: baseline; margin-bottom: 8px; }
        .nyk-db-sync .progress-top strong { font-size: 1.15rem; }
        .nyk-db-sync .left { color: var(--amber); font-weight: 700; }
        .nyk-db-sync .percent { color: var(--teal-deep); font-weight: 700; font-size: 1.15rem; }
        .nyk-db-sync .meter { height: 12px; border-radius: 999px; background: #e4edf5; overflow: hidden; }
        .nyk-db-sync .meter span { display: block; height: 100%; border-radius: inherit; background: linear-gradient(90deg, #12838d, #1aa37a); }
        .nyk-db-sync .meter.slim { height: 6px; margin-top: 6px; }
        .nyk-db-sync table { width: 100%; border-collapse: collapse; font-size: 0.85rem; margin-top: 8px; }
        .nyk-db-sync th { color: var(--muted); font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.04em; }
        .nyk-db-sync th, .nyk-db-sync td { text-align: left; padding: 8px 8px 8px 0; border-bottom: 1px solid #e8f0f6; }
        .nyk-db-sync label { display: block; font-size: 0.92rem; margin: 10px 0; color: var(--ink); }
        .nyk-db-sync select, .nyk-db-sync input[type="text"], .nyk-db-sync input[type="password"], .nyk-db-sync input[type="number"] { width: 100%; max-width: 320px; padding: 8px 10px; border: 1px solid #c5d5e4; border-radius: 10px; background: #fff; color: var(--ink); }
        .nyk-db-sync .fields { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 0 16px; }
        .nyk-db-sync .fields input { max-width: none; }
        .nyk-db-sync button { background: var(--amber); color: white; border: 0; border-radius: 10px; padding: 9px 14px; font-weight: 700; cursor: pointer; }
        .nyk-db-sync button.secondary { background: var(--teal); }
        .nyk-db-sync button:disabled { background: #b7c6d4; cursor: not-allowed; }
        .nyk-db-sync .check { display: flex; gap: 8px; align-items: center; }
        .nyk-db-sync .banner { padding: 10px 12px; border-radius: 12px; margin-bottom: 12px; }
        .nyk-db-sync .ok { background: var(--good-soft); color: var(--good); }
        .nyk-db-sync .bad { background: var(--bad-soft); color: var(--bad); }
        .nyk-db-sync code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.85em; color: var(--teal-deep); }
    </style>

    <h1>Live database engine</h1>
    <p class="lede">
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
                <div class="stat {{ $connection['connected'] ? '' : 'down-card' }}">
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
            <div class="stat {{ $enabled ? 'write-on' : 'write-off' }}"><span>Dual-write</span><strong>{{ $enabled ? 'On' : 'Off' }}</strong></div>
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
        <h2>Create Postgres database</h2>
        <p class="muted">Connects as the local <code>postgres</code> superuser, creates the empty database and app login, grants <code>CONNECT</code> plus <code>USAGE</code> and <code>CREATE</code> on schema <code>public</code>, and overwrites <code>PGSQL_HOST</code>, <code>PGSQL_PORT</code>, <code>PGSQL_DATABASE</code>, <code>PGSQL_USERNAME</code>, and <code>PGSQL_PASSWORD</code>.</p>
        <form method="post" action="{{ route('nyk-db-sync.create-database') }}">
            @csrf
            <div class="fields">
                <label>
                    Host
                    <input type="text" name="host" value="{{ old('host', '127.0.0.1') }}" autocomplete="off">
                </label>
                <label>
                    Port
                    <input type="number" name="port" value="{{ old('port', '5432') }}" min="1" max="65535">
                </label>
                <label>
                    Database name
                    <input type="text" name="database" value="{{ old('database') }}" autocomplete="off">
                </label>
                <label>
                    App login
                    <input type="text" name="username" value="{{ old('username') }}" autocomplete="off">
                </label>
                <label>
                    App login password
                    <input type="password" name="password" autocomplete="new-password">
                </label>
                <label class="check">
                    <input type="checkbox" name="generate_password" value="1" @checked(old('generate_password'))>
                    Generate a password
                </label>
                <label>
                    Postgres superuser password
                    <input type="password" name="admin_password" autocomplete="new-password" placeholder="empty for local trust auth">
                </label>
            </div>
            <button class="secondary" type="submit">Create database</button>
        </form>
        <script>
            (function () {
                var form = document.currentScript && document.currentScript.previousElementSibling;
                if (!form) return;
                var toggle = form.querySelector('input[name="generate_password"]');
                var password = form.querySelector('input[name="password"]');
                if (!toggle || !password) return;
                var apply = function () {
                    password.disabled = toggle.checked;
                    if (toggle.checked) password.value = '';
                };
                toggle.addEventListener('change', apply);
                apply();
            }());
        </script>
    </section>

    <section class="panel">
        <h2>Sync</h2>
        @if ($progress['known'])
            <div class="progress-top">
                <strong>{{ number_format($progress['copied']) }} copied</strong>
                <span class="percent">{{ $progress['percent'] }}%</span>
                <span class="left">{{ number_format($progress['left']) }} left</span>
            </div>
            <div class="meter" role="img" aria-label="{{ $progress['percent'] }} percent of primary rows are on the secondary">
                <span style="width: {{ $progress['percent'] }}%"></span>
            </div>
            <p class="muted">{{ number_format($progress['copied']) }} of {{ number_format($progress['source']) }} primary rows are on the secondary.</p>
        @else
            <p class="muted">Connect the secondary database to see how many rows are copied and how many are left.</p>
        @endif
        <p>{{ $estimate['label'] }}</p>
        @if ($estimate['note'] !== '')
            <p class="muted">{{ $estimate['note'] }}</p>
        @endif
        <p class="muted">Missing tables are created from the primary. Existing columns are left as they are. New columns are added.</p>
        @if ($summary)
            <p class="muted">
                @if (($summary['finished'] ?? true) === false)
                    Sync stopped {{ $summary['ran_at'] ?? '' }}
                    ({{ $summary['primary'] ?? '' }} → {{ $summary['secondary'] ?? '' }})
                    @if ($lastDuration)
                        after copying for {{ $lastDuration }}.
                    @endif
                @else
                    Last sync {{ $summary['ran_at'] ?? '' }}
                    ({{ $summary['primary'] ?? '' }} → {{ $summary['secondary'] ?? '' }})
                    @if ($lastDuration)
                        took {{ $lastDuration }}.
                    @endif
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
                    <tr><th>Table</th><th>Source</th><th>Destination</th><th>Left</th><th>Progress</th></tr>
                </thead>
                <tbody>
                    @foreach ($counts as $row)
                        @php
                            $filled = $row['destination'] === null ? 0 : min($row['source'], $row['destination']);
                            $rowPercent = $row['source'] === 0 ? 100 : (int) min(100, round($filled / $row['source'] * 100));
                        @endphp
                        <tr>
                            <td>
                                <code>{{ $row['table'] }}</code>
                                <div class="meter slim" role="img" aria-label="{{ $rowPercent }} percent of {{ $row['table'] }} is copied"><span style="width: {{ $rowPercent }}%"></span></div>
                            </td>
                            <td>{{ number_format($row['source']) }}</td>
                            <td>{{ $row['destination'] === null ? 'missing' : number_format($row['destination']) }}</td>
                            <td>{{ number_format(max(0, $row['source'] - ($row['destination'] ?? 0))) }}</td>
                            <td class="percent">{{ $rowPercent }}%</td>
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
