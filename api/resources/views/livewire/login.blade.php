<div class="auth-wrap">
    <div class="auth-brand">
        <span class="brand"><x-logo />{{ config('app.name') }}</span>
    </div>

    <div class="card auth-card">
        <h1>Sign in</h1>
        <p class="muted">Welcome back. Sign in to see what's built and what's verified.</p>

        <form wire:submit="login">
            <div class="field">
                <label for="email">Email</label>
                <input id="email" type="email" wire:model="email" autocomplete="username" placeholder="you@company.com" autofocus required>
                @error('email') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input id="password" type="password" wire:model="password" autocomplete="current-password" required>
                @error('password') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field">
                <label><input type="checkbox" wire:model="remember"> Keep me signed in</label>
            </div>
            <button class="btn primary block" type="submit" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="login">Sign in</span>
                <span wire:loading wire:target="login">Signing in…</span>
            </button>
        </form>
    </div>

    <p class="auth-foot muted small">Need an account? Ask your administrator.</p>
</div>
