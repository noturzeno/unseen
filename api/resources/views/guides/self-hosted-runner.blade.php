@php($download = rtrim(url('/'), '/').'/downloads/unseen-cli.tgz')
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="description" content="Out of GitHub Actions minutes? Run your CI, and unseen verify, on your own Mac with a self-hosted runner.">

        <title>{{ config('app.name') }} · Run CI on your own Mac</title>

        <link rel="alternate" type="text/markdown" href="{{ route('guides.self-hosted-runner.agent') }}" title="Agent reference">
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
                <p class="eyebrow">Guide · About 15 minutes</p>
                <h1>Run CI on your own Mac</h1>
                <p class="muted">If GitHub stops your jobs with <em>"recent account payments have failed or your spending limit needs to be increased"</em>, your private repository has used up its free Actions minutes. macOS jobs, such as iOS builds, use them 10 times faster than Linux jobs. A <strong>self-hosted runner</strong> lets your own Mac run the jobs instead: free, with no minute limit, and <code>unseen verify</code> keeps sending test results as before.</p>
            </section>

            <section class="card home-package" aria-labelledby="agent-title">
                <h2 id="agent-title">Fastest: let your coding agent do it</h2>
                <p class="muted">Paste this into Claude Code (or another coding agent) in the repository. It follows the <a href="{{ route('guides.self-hosted-runner.agent') }}">agent reference</a>, does the steps it can, and asks you for the few that need you: signing in, starting the runner and <code>sudo</code>.</p>
                <pre class="home-code wrap"><code>Read {{ route('guides.self-hosted-runner.agent') }} and follow it to move this repository's CI onto this Mac.</code></pre>
                <p class="muted small">Prefer to do it yourself? Follow the steps below.</p>
            </section>

            <section class="card" aria-labelledby="before-title">
                <h2 id="before-title">Before you start</h2>
                <ul class="home-steps">
                    <li>A Mac that is often switched on. CI only runs while it is awake.</li>
                    <li>Admin access to the GitHub repository.</li>
                    <li><a href="https://brew.sh">Homebrew</a>, plus the tools your project already uses locally (for example PHP and Composer, Node, or Xcode).</li>
                </ul>
                <p class="muted small">In the commands below, replace <code>OWNER/REPO</code> with your repository, for example <code>acme/my-app</code>.</p>
            </section>

            <section class="card" aria-labelledby="step1-title">
                <h2 id="step1-title">1. Install the tools</h2>
                <p>Open Terminal, install the GitHub CLI and sign in:</p>
                <pre class="home-code"><code>brew install gh
gh auth login</code></pre>
                <p>iOS projects also need the log formatter most workflows use:</p>
                <pre class="home-code"><code>brew install xcbeautify</code></pre>
            </section>

            <section class="card" aria-labelledby="step2-title">
                <h2 id="step2-title">2. Download the runner</h2>
                <p>The runner is a small program from GitHub that picks up your CI jobs. Paste the whole block:</p>
                <pre class="home-code"><code>mkdir -p ~/actions-runner && cd ~/actions-runner
V=$(gh api repos/actions/runner/releases/latest --jq .tag_name | sed 's/^v//')
curl -sSL -o runner.tar.gz "https://github.com/actions/runner/releases/download/v$V/actions-runner-osx-arm64-$V.tar.gz"
tar xzf runner.tar.gz && rm runner.tar.gz</code></pre>
                <p class="muted small">On an Intel Mac, change <code>osx-arm64</code> to <code>osx-x64</code>. Not sure? <code>uname -m</code> prints <code>arm64</code> on Apple silicon.</p>
            </section>

            <section class="card" aria-labelledby="step3-title">
                <h2 id="step3-title">3. Connect it to your repository</h2>
                <p>This registers your Mac with the label <code>my-mac</code>. Workflows use the label to send jobs to it.</p>
                <pre class="home-code"><code>cd ~/actions-runner
./config.sh --unattended \
  --url https://github.com/OWNER/REPO \
  --token $(gh api -X POST repos/OWNER/REPO/actions/runners/registration-token --jq .token) \
  --labels my-mac</code></pre>
                <p class="muted small">You should see <strong>Runner successfully added</strong> and <strong>Settings Saved</strong>.</p>
            </section>

            <section class="card" aria-labelledby="step4-title">
                <h2 id="step4-title">4. Keep it running in the background</h2>
                <p>Install it as a service, so it starts by itself whenever you log in:</p>
                <pre class="home-code"><code>./svc.sh install
./svc.sh start</code></pre>
                <p class="muted small">Check it on GitHub under <strong>Settings → Actions → Runners</strong>: your Mac should show as <strong>Idle</strong>.</p>
            </section>

            <section class="card" aria-labelledby="step5-title">
                <h2 id="step5-title">5. Point your workflow at your Mac</h2>
                <p>Edit your workflow in <code>.github/workflows/</code> (often <code>ci.yml</code>):</p>
                <ol class="home-steps">
                    <li>
                        Change every <code>runs-on:</code> line to your runner's label:
                        <pre class="home-code"><code>runs-on: [self-hosted, my-mac]</code></pre>
                    </li>
                    <li>
                        Delete steps that install tools your Mac already has. They can break there or ask for a password:
                        <pre class="home-code"><code>- uses: shivammathur/setup-php@v2       # delete
- uses: maxim-lobanov/setup-xcode@v1    # delete</code></pre>
                        Keep <code>actions/checkout</code>, <code>actions/setup-node</code> and the artifact upload/download steps.
                    </li>
                    <li>
                        Run the CLI with <code>npx</code> instead of <code>npm install -g</code>, so CI doesn't change your Mac's global packages:
                        <pre class="home-code"><code>npx --yes -p {{ $download }} unseen verify --junit reports/junit.xml</code></pre>
                    </li>
                    <li>
                        Keep pull requests from forks off your Mac. Add this to each job:
                        <pre class="home-code"><code>if: {{ '${{' }} github.event_name != 'pull_request' || github.event.pull_request.head.repo.full_name == github.repository }}</code></pre>
                    </li>
                    <li>
                        Laravel: Homebrew PHP caps memory at 128 MB, which a large test suite can exceed. Inside <code>&lt;php&gt;</code> in <code>phpunit.xml</code>, add:
                        <pre class="home-code"><code>&lt;ini name="memory_limit" value="-1"/&gt;</code></pre>
                    </li>
                </ol>
                <p>Commit and push.</p>
            </section>

            <section class="card" aria-labelledby="step6-title">
                <h2 id="step6-title">6. iOS only: enable Developer Mode</h2>
                <p>Without it, iOS tests hang with <strong>"The test runner hung before establishing connection"</strong>: macOS waits for a permission prompt the background runner can't answer.</p>
                <pre class="home-code"><code>sudo DevToolsSecurity -enable</code></pre>
                <p class="muted small">Check with <code>DevToolsSecurity -status</code>.</p>
            </section>

            <section class="card" aria-labelledby="step7-title">
                <h2 id="step7-title">7. Test it</h2>
                <ol class="home-steps">
                    <li>On GitHub, open the repository's <strong>Actions</strong> tab.</li>
                    <li>Pick your workflow and click <strong>Run workflow</strong>, or push a commit.</li>
                    <li>Open the run. Jobs should start within seconds, and the test run appears on the project in {{ config('app.name') }}.</li>
                </ol>
                <p class="muted small">One Mac runs one job at a time, so a full run can take a little longer than on GitHub.</p>
            </section>

            <section class="card" aria-labelledby="trouble-title">
                <h2 id="trouble-title">Troubleshooting</h2>
                <div class="table-wrap">
                    <table class="home-commands">
                        <tbody>
                            <tr><td><strong>Waiting for a runner</strong></td><td>Your Mac is off or asleep, or the runner stopped: run <code>cd ~/actions-runner && ./svc.sh start</code>. Check that <code>runs-on</code> uses the label from step 3.</td></tr>
                            <tr><td><strong>Allowed memory size exhausted</strong></td><td>Add the <code>memory_limit</code> line from step 5.</td></tr>
                            <tr><td><strong>Test runner hung</strong> (iOS)</td><td>Do step 6. If it still hangs, run <code>./svc.sh stop</code> and start the runner in a Terminal window with <code>./run.sh</code>.</td></tr>
                            <tr><td><strong>command not found</strong></td><td>Add the tool's folder to <code>~/actions-runner/.path</code>, then <code>./svc.sh stop && ./svc.sh start</code>.</td></tr>
                            <tr><td><strong>Passes locally, fails in CI</strong></td><td>CI uses a fresh checkout with no <code>.env</code>. Set the values in the workflow or <code>phpunit.xml</code>.</td></tr>
                        </tbody>
                    </table>
                </div>
                <p class="muted small">Runner logs are in <code>~/Library/Logs/actions.runner.*</code>.</p>
            </section>

            <section class="card" aria-labelledby="remove-title">
                <h2 id="remove-title">Good to know</h2>
                <ul class="home-steps">
                    <li>Jobs wait in the queue while your Mac is off or asleep.</li>
                    <li>iOS tests use a simulator on your Mac; avoid using the same one while CI runs.</li>
                    <li>To go back to GitHub's machines, change <code>runs-on</code> back (for example to <code>ubuntu-latest</code>).</li>
                </ul>
                <p>To remove the runner:</p>
                <pre class="home-code"><code>cd ~/actions-runner
./svc.sh uninstall
./config.sh remove --token $(gh api -X POST repos/OWNER/REPO/actions/runners/remove-token --jq .token)
cd ~ && rm -rf ~/actions-runner</code></pre>
            </section>

            <p class="home-foot muted small"><a href="{{ route('home') }}">← Back to {{ config('app.name') }}</a></p>
        </main>

        @include('partials.powered-by')
    </body>
</html>
