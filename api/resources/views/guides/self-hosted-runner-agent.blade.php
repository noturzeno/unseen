# Run this repository's CI on this Mac (self-hosted GitHub Actions runner)

Agent reference. The human version is {!! $url !!}/guides/self-hosted-runner.

Use this when GitHub Actions jobs fail to start with "recent account payments have failed or your spending limit needs to be increased": the private repository is out of Actions minutes. You will register this Mac as a self-hosted runner and move the workflow's jobs onto it, so CI (and `unseen verify`) keeps running for free.

## Rules

- Work through the steps in order. Run each step's check before moving on.
- Some steps need the human (interactive sign-in, `sudo`, approving a background service). When a step says **ASK THE HUMAN**, stop, tell them exactly what to run (as a `! <command>` line if they use Claude Code), and wait. Don't try to get around a permission prompt or a denied command.
- Never print, commit or save the runner registration token or `UNSEEN_TOKEN`. Use the registration token inline, as shown.
- Only change the workflow file(s) and, for Laravel, `phpunit.xml`. Don't change application code to make CI pass.
- Don't push until the human agrees.

## 1. Collect facts

Run:

    uname -m
    git remote get-url origin
    ls .github/workflows/
    gh auth status

- `arm64` means the runner package is `osx-arm64`; `x86_64` means `osx-x64`. Call it ARCH.
- From the remote URL, take `OWNER/REPO` (e.g. `git@github.com:acme/app.git` gives `acme/app`). Call it REPO.
- Read every workflow file. Note each job's `runs-on`, its setup steps, and any `npm install -g`.
- If `gh` is missing, run `brew install gh`. If `gh auth status` is not logged in, **ASK THE HUMAN** to run `gh auth login`.
- Check that REPO is private: `gh repo view REPO --json isPrivate --jq .isPrivate`. If it is public, tell the human that public repositories get standard runners free, so they likely don't need this. Stop unless they still want it.

## 2. Check local tools

Every tool the workflow installs must already work on this Mac, because the runner uses the Mac's own tools. Check the ones the workflow needs, for example:

    php -v && composer -V      # PHP / Laravel
    node -v && npm -v          # Node
    xcodebuild -version        # iOS
    command -v xcbeautify      # iOS, if the workflow pipes to it

- Install missing CLI tools with Homebrew (e.g. `brew install xcbeautify`).
- If a language version differs from the workflow's pinned one (e.g. workflow pins PHP 8.4, Mac has 8.5), tell the human and continue.

## 3. Download the runner

Skip if `~/actions-runner/config.sh` already exists.

    mkdir -p ~/actions-runner && cd ~/actions-runner
    V=$(gh api repos/actions/runner/releases/latest --jq .tag_name | sed 's/^v//')
    curl -sSL -o runner.tar.gz "https://github.com/actions/runner/releases/download/v$V/actions-runner-ARCH-$V.tar.gz"
    tar xzf runner.tar.gz && rm runner.tar.gz

Check: `ls ~/actions-runner/config.sh ~/actions-runner/svc.sh`.

## 4. Register and start the runner (ASK THE HUMAN)

This installs a background service that runs CI jobs on the Mac. That is a lasting change, so **ASK THE HUMAN** to run it themselves, with REPO filled in. Pick a label; this guide uses `my-mac`, but a project-specific one (e.g. `acme-mac`) is clearer.

    ! cd ~/actions-runner && ./config.sh --unattended --url https://github.com/REPO --token $(gh api -X POST repos/REPO/actions/runners/registration-token --jq .token) --labels my-mac && ./svc.sh install && ./svc.sh start

Check (you can run this):

    gh api repos/REPO/actions/runners --jq '.runners[] | "\(.name) \(.status) \([.labels[].name] | join(","))"'

Expect a line with `online` and your label. Don't continue until it shows.

## 5. Edit the workflow

In each workflow file:

1. Set every job's `runs-on` to `[self-hosted, my-mac]` (your label).
2. Delete steps that install toolchains the Mac already has. They can fail, need `sudo`, or install a second copy. For example: `shivammathur/setup-php`, `maxim-lobanov/setup-xcode`, `actions/setup-python`, `ruby/setup-ruby`. Also delete `actions/cache` steps that only cache a package manager's download folder. Keep `actions/checkout`, `actions/setup-node`, and artifact upload/download steps.
3. Replace `npm install -g <pkg>` followed by `<cmd> ...` with a single `npx --yes -p <pkg> <cmd> ...`, so CI doesn't change the Mac's global packages. For Unseen:

        npx --yes -p {!! $url !!}/downloads/unseen-cli.tgz unseen verify --junit <report.xml>

4. If the workflow runs on `pull_request`, add this to every job that has no `needs:` (jobs that depend on them are skipped with them), so code from forks never runs on the Mac:

@verbatim
        if: ${{ github.event_name != 'pull_request' || github.event.pull_request.head.repo.full_name == github.repository }}
@endverbatim

    If a job already has an `if:`, combine the two with `&&`.
5. Optional: if a job is slow (e.g. iOS), run it only when its files change, using `dorny/paths-filter@v3` in a small job that the slow job `needs`.
6. Add a comment at the top of `jobs:` saying the jobs run on the self-hosted Mac and why.

Check: the file still parses, and no `ubuntu-*`/`macos-*` runner is left:

    ruby -ryaml -e 'YAML.load_file(".github/workflows/ci.yml"); puts "ok"'
    grep -nE "runs-on:.*(ubuntu|macos|windows)-" .github/workflows/*.yml || echo "all self-hosted"

## 6. Project-specific fixes

- **Laravel / PHPUnit:** Homebrew PHP limits memory to 128M; hosted CI's setup-php set no limit. If `<ini name="memory_limit"` is not in `phpunit.xml`, add `<ini name="memory_limit" value="-1"/>` as the first child of `<php>`. A `php -d memory_limit=-1 artisan test` flag is not enough: `artisan test` starts PHPUnit in a new process that doesn't inherit it.
- **iOS:** check `DevToolsSecurity -status`. If it says disabled, **ASK THE HUMAN** to run `! sudo DevToolsSecurity -enable`. Without it, tests hang with "The test runner hung before establishing connection".

## 7. Run the tests locally first

Run the same test commands the workflow runs, from this Mac, and fix workflow problems before pushing (e.g. `cd web && php artisan test`). If tests fail for reasons unrelated to CI, report them and don't change application code.

## 8. Commit, push and watch (ask first)

Show the human the diff and ask before pushing. Then:

    git add .github/workflows phpunit.xml 2>/dev/null; git commit -m "Run CI on the self-hosted Mac runner"
    git push
    gh run watch $(gh run list --limit 1 --json databaseId --jq '.[0].databaseId') --exit-status

Note: if the workflow uses `concurrency: cancel-in-progress`, a new push cancels the running one. Don't push again while you are waiting on a run.

If a job fails, read its log, not the summary:

    gh api repos/REPO/actions/jobs/<job id>/logs

## 9. Troubleshooting

| Symptom | Cause and fix |
| --- | --- |
| Job stays "Waiting for a runner" | Runner offline or label mismatch. Check step 4's command; `cd ~/actions-runner && ./svc.sh start`; make `runs-on` match the label. |
| `Allowed memory size of 134217728 bytes exhausted` | PHP memory limit. Apply the Laravel fix in step 6. |
| iOS job runs far longer than usual, `xcodebuild` at 0% CPU | Test runner hung. Look in `build/**/Tests.xcresult/Staging/*/Diagnostics/*/scheduling.log` for "hung before establishing connection", then apply the iOS fix in step 6. If it persists, ask the human to stop the service (`./svc.sh stop`) and run `./run.sh` in a Terminal window. |
| `command not found` | The service's PATH is `~/actions-runner/.path`. Ask the human to add the tool's folder there and restart with `./svc.sh stop && ./svc.sh start`. |
| `file_get_contents(.../.env)` warnings | CI checkouts have no `.env`. Usually harmless; set required values in the workflow `env:` or `phpunit.xml`. |

## 10. Report back

Tell the human:

- The runner name, label and status.
- What you changed in each file, and why.
- The result of the CI run (job by job), with the run URL.
- Anything they still have to do (e.g. enable Developer Mode), and the trade-offs: CI runs only while the Mac is awake, jobs run one at a time, and iOS tests use the Mac's simulators.

To undo later: change `runs-on` back to hosted runners, then the human runs `cd ~/actions-runner && ./svc.sh uninstall && ./config.sh remove --token $(gh api -X POST repos/REPO/actions/runners/remove-token --jq .token)`.
