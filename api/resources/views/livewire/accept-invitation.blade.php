<div class="auth-wrap">
    <div class="auth-brand">
        <span class="brand"><x-logo />{{ config('app.name') }}</span>
    </div>

    <div class="card auth-card">
        @if ($state === 'invalid')
            <h1>Invitation not valid</h1>
            <p class="muted">This link has expired, was already used, or was replaced by a newer one. Ask the project owner for a new link.</p>
        @else
            <h1>Join {{ $invitation->project->name }}</h1>
            <p class="muted">{{ $invitation->inviter?->name ?? 'The project owner' }} invited <strong>{{ $invitation->email }}</strong> to contribute.</p>

            @if ($state === 'wrong-account')
                <p>You're signed in as {{ auth()->user()->email }}. Sign out, then open this link again to join as {{ $invitation->email }}.</p>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="btn block" type="submit">Sign out</button>
                </form>
            @elseif ($state === 'sign-in')
                <p>You already have an account with this email. Sign in and you'll join the project.</p>
                <a class="btn primary block" href="{{ route('login') }}" wire:navigate>Sign in</a>
            @else
                <form wire:submit="register">
                    <div class="field">
                        <label for="name">Your name</label>
                        <input id="name" type="text" wire:model="name" autocomplete="name" autofocus required>
                        @error('name') <div class="error">{{ $message }}</div> @enderror
                    </div>
                    <div class="field">
                        <label for="password">Password</label>
                        <input id="password" type="password" wire:model="password" autocomplete="new-password" required>
                        @error('password') <div class="error">{{ $message }}</div> @enderror
                    </div>
                    <div class="field">
                        <label for="password_confirmation">Confirm password</label>
                        <input id="password_confirmation" type="password" wire:model="password_confirmation" autocomplete="new-password" required>
                    </div>
                    <button class="btn primary block" type="submit" wire:loading.attr="disabled">Create account and join</button>
                </form>
            @endif
        @endif
    </div>
</div>
