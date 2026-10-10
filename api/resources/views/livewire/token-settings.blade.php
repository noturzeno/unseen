<div>
    <div class="page-head">
        <div>
            <h1>API tokens</h1>
            <p class="muted">Tokens let the <code>unseen</code> CLI, coding agents and CI talk to Unseen as you. Give each tool its own token with only the access it needs.</p>
        </div>
    </div>

    @if ($newToken)
        <div class="card highlight">
            <h2>Copy your new token now</h2>
            <p class="small">It won't be shown again. Store it in an environment variable (<code>UNSEEN_TOKEN</code>) or a CI secret, never in a repository.</p>
            <input type="text" readonly value="{{ $newToken }}" onclick="this.select()" aria-label="New token">
            <p><button class="btn" type="button" wire:click="dismissNewToken">I've copied it</button></p>
        </div>
    @endif

    <form class="card" wire:submit="create">
        <h2>Create a token</h2>
        <div class="grid">
            <div class="field">
                <label for="name">Name</label>
                <input id="name" type="text" wire:model="name" placeholder="e.g. Claude Code on my laptop">
                @error('name') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field">
                <label for="preset">Access</label>
                <select id="preset" wire:model.live="preset">
                    @foreach ($presets as $key => $p) <option value="{{ $key }}">{{ $p['label'] }}</option> @endforeach
                </select>
                <div class="hint">{{ $presets[$preset]['hint'] ?? '' }}</div>
                @error('preset') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field">
                <label for="expires">Expires after (days)</label>
                <input id="expires" type="number" min="1" max="365" wire:model="expiresInDays">
                <div class="hint">Leave empty for no expiry (not recommended).</div>
                @error('expiresInDays') <div class="error">{{ $message }}</div> @enderror
            </div>
        </div>
        <button class="btn primary" type="submit">Create token</button>
    </form>

    <div class="card">
        <h2>Your tokens</h2>
        @if ($tokens->isEmpty())
            <p class="muted small">No tokens yet.</p>
        @else
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Name</th><th>Access</th><th>Last used</th><th>Expires</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($tokens as $token)
                            <tr wire:key="t-{{ $token->id }}">
                                <td>{{ $token->name }}</td>
                                <td class="small">{{ implode(', ', $token->abilities) }}</td>
                                <td class="small muted">{{ $token->last_used_at?->diffForHumans() ?? 'Never' }}</td>
                                <td class="small">
                                    @if (! $token->expires_at) <span class="badge warn">Never</span>
                                    @elseif ($token->expires_at->isPast()) <span class="badge bad">Expired</span>
                                    @else {{ $token->expires_at->toDateString() }} @endif
                                </td>
                                <td><button class="btn link danger" type="button" wire:click="revoke({{ $token->id }})"
                                    wire:confirm="Revoke “{{ $token->name }}”? Anything using it stops working.">Revoke</button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
