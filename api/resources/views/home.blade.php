@php($url = rtrim(url('/'), '/'))
@php($download = $url.'/downloads/unseen-cli.tgz')
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="description" content="Install the unseen CLI and connect a repository to {{ config('app.name') }}.">

        <title>{{ config('app.name') }} · Track what coding agents built</title>

        <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
    </head>
    <body>
        <header class="topbar">
            <div class="inner">
                <a class="brand" href="{{ route('home') }}">
                    <x-logo /><span class="brand-name">{{ config('app.name') }}</span>
                </a>
                <span class="spacer"></span>
                @auth
                    <a class="btn primary" href="{{ route('projects.index') }}">Open dashboard</a>
                @else
                    <a class="btn primary" href="{{ route('login') }}">Sign in</a>
                @endauth
            </div>
        </header>

        <main class="home">
            <section class="home-hero">
                <p class="eyebrow">Coding-agent tracking</p>
                <h1>See what's built and what's verified</h1>
                <p class="muted">{{ config('app.name') }} keeps each project's feature inventory up to date. Coding agents record their work with the <code>unseen</code> CLI, CI test runs verify features, and your team reviews it all on the dashboard.</p>
            </section>

            <section class="card home-package" aria-labelledby="package-title">
                <div class="card-head">
                    <h2 id="package-title"><code>unseen-cli</code>@if ($version) <span class="badge">v{{ $version }}</span>@endif</h2>
                    @if ($size)
                        <a class="btn" href="{{ $download }}" download>Download .tgz <span class="muted small">({{ number_format($size / 1024) }} KB)</span></a>
                    @endif
                </div>
                <p class="muted">Links a repository to an {{ config('app.name') }} project so a developer or Claude Code can see the feature inventory, report work, scan the code for features, and send test results.</p>
                <dl class="home-facts">
                    <div><dt>Requires</dt><dd>Node.js 22.18 or newer</dd></div>
                    <div><dt>Dependencies</dt><dd>None at runtime</dd></div>
                    <div><dt>Command</dt><dd><code>unseen</code></dd></div>
                </dl>
                <pre class="home-code"><code>npm install -g {{ $download }}</code></pre>
            </section>

            <section class="card" aria-labelledby="fastest-title">
                <h2 id="fastest-title">Fastest: let your coding agent set it up</h2>
                <ol class="home-steps">
                    <li><a href="{{ route('login') }}">Sign in</a> and open your project. A new project shows <strong>Connect your coding agent</strong> (later: <strong>Settings → Coding agent</strong>).</li>
                    <li>Pick <strong>Just started</strong> or <strong>Already developed</strong>, then click <strong>Copy prompt</strong>.</li>
                    <li>Paste the prompt into Claude Code (or another coding agent) in the project's repository. It installs the CLI, links the repository and records the features.</li>
                </ol>
            </section>

            <section class="card" aria-labelledby="manual-title">
                <h2 id="manual-title">Set up by hand</h2>
                <ol class="home-steps">
                    <li>
                        Install the CLI:
                        <pre class="home-code"><code>npm install -g {{ $download }}</code></pre>
                    </li>
                    <li>
                        In the repository, sign in and pick a project:
                        <pre class="home-code"><code>cd ~/code/my-project
unseen init --api-url {{ $url }}</code></pre>
                        The token is saved in <code>~/.config/unseen/credentials.json</code>, never in the repository.
                    </li>
                    <li>Commit <code>.unseen.json</code>. It holds only the URL and project id, so everyone on the team uses the same project.</li>
                    <li>
                        In CI, set <code>UNSEEN_TOKEN</code> and send test results so features can be verified:
                        <pre class="home-code"><code>unseen verify --run "php artisan test --log-junit junit.xml" --junit junit.xml</code></pre>
                    </li>
                </ol>
            </section>

            <section class="card" aria-labelledby="commands-title">
                <h2 id="commands-title">Commands</h2>
                <div class="table-wrap">
                    <table class="home-commands">
                        <tbody>
                            <tr><td><code>unseen init</code></td><td>Link this repository to a project</td></tr>
                            <tr><td><code>unseen status</code></td><td>Show features, progress and open work (<code>--json</code> for agents)</td></tr>
                            <tr><td><code>unseen report</code></td><td>Record what was done, with tests and unresolved work</td></tr>
                            <tr><td><code>unseen scan</code></td><td>Find features already in the code and docs</td></tr>
                            <tr><td><code>unseen verify</code></td><td>Send automated test results, normally from CI</td></tr>
                            <tr><td><code>unseen roadmap</code></td><td>Show or sync the project's roadmap</td></tr>
                            <tr><td><code>unseen ticket</code></td><td>Work with tickets and their QA and client threads</td></tr>
                            <tr><td><code>unseen mismatch</code></td><td>Record where the PRD and the design disagree</td></tr>
                            <tr><td><code>unseen runner</code></td><td>Open Claude Code when someone clicks <strong>Proceed</strong></td></tr>
                        </tbody>
                    </table>
                </div>
                <p class="muted small">Run <code>unseen --help</code> for every option.</p>
            </section>

            <p class="home-foot muted small">No public sign-up: ask your administrator for an account.</p>
        </main>

        @include('partials.powered-by')
    </body>
</html>
