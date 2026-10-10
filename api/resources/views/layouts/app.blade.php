<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">

        <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>

        <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">

        @livewireStyles
    </head>
    <body>
        @auth
            @php($initials = Str::of(auth()->user()->name)->explode(' ')->filter()->take(2)->map(fn ($w) => Str::upper(Str::substr($w, 0, 1)))->implode(''))
            <header class="topbar">
                <div class="inner">
                    <a class="brand" href="{{ route('projects.index') }}" wire:navigate>
                        <x-logo /><span class="brand-name">{{ config('app.name') }}</span>
                    </a>
                    <a @class(['nav-link', 'active' => ! request()->routeIs('settings.*')]) href="{{ route('projects.index') }}" wire:navigate>Projects</a>
                    <a @class(['nav-link', 'active' => request()->routeIs('settings.tokens')]) href="{{ route('settings.tokens') }}" wire:navigate>API tokens</a>
                    <span class="spacer"></span>
                    <div class="user">
                        <span class="avatar" aria-hidden="true">{{ $initials }}</span>
                        <span class="user-name">{{ auth()->user()->name }}</span>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button class="btn ghost" type="submit">Sign out</button>
                        </form>
                    </div>
                </div>
            </header>

            <main>
                @if (session('status'))
                    <div class="flash" role="status">{{ session('status') }}</div>
                @endif

                {{ $slot }}
            </main>
        @else
            <div class="auth">
                {{ $slot }}
            </div>
        @endauth

        @livewireScripts
    </body>
</html>
