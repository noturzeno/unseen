# 8. Verification with automated tests

Verification answers *"does it actually work?"* **Only trusted automated test runs verify features.** Agents
link tests to each feature (checks), and CI (or `unseen verify` with a verify token) sends the results.
Results are recorded as *Confirmed by tests*. Nobody can mark a feature verified or failed by hand or through the
feature API.

## How it works

1. You add **checks** to a feature: patterns naming the tests that prove it works.
2. CI runs your test suite and `unseen verify` sends the results (JUnit XML) to Unseen.
3. Unseen applies the **verification policy** to every feature that has checks.

### The verification policy

| Result | When | Effect |
|---|---|---|
| ❌ **Failed** | Any of the feature's checks has a failing or erroring test | Verification → **Failed** |
| ✅ **Verified** | Every check has at least one passing test and none failing, **and** the feature is *Implemented*, **and** the run is from a git commit with **no uncommitted changes** | Verification → **Verified** |
| Passed, not applied | All checks passed, but one of the conditions above is missing | No change (reason shown) |
| Incomplete | No failures, but some check had no tests in this run | No change |
| Not covered | None of the feature's checks ran (e.g. a partial test run) | No change |

- Skipped tests don't count as passing.
- **Features without checks are never verified automatically.** The dashboard lists them so the gap is visible.
- If an agent later reports changing a verified feature, it goes back to *Needs verification* until the next passing run.

## Step 1: Agents link checks to each feature

The coding agent adds checks through the API (`POST /api/v1/features/{id}/checks` with a `pattern`). The feature
page lists them under **Automated checks** with their latest result. Patterns look like this:

| Pattern | Matches |
|---|---|
| `Tests\Feature\InvoiceTest` | Every test in that class |
| `*InvoiceTest::test_export*` | Tests whose name matches (`*` = anything) |
| `tests/invoice.test.ts` | Every test in that file |

One check per group of tests that proves part of the feature (e.g. API tests and browser tests), optionally
with a description of what it covers.

> Keep patterns specific. A pattern like `*` would let unrelated tests "verify" the feature.

## Step 2: Make your tests produce JUnit XML

| Test tool | Command |
|---|---|
| PHPUnit / Laravel | `php artisan test --log-junit junit.xml` |
| Vitest | `npx vitest run --reporter=junit --outputFile=junit.xml` |
| Jest | install `jest-junit`, then `npx jest --reporters=default --reporters=jest-junit` (writes `junit.xml`) |
| pytest | `pytest --junitxml=junit.xml` |
| Node test runner | `node --test --test-reporter=junit --test-reporter-destination=junit.xml` |

Add `junit.xml` to `.gitignore`.

## Step 3: Create a CI token

Agent tokens **cannot** submit test results. That's deliberate, so an agent can't verify its own work.

1. In Unseen, click **API tokens** (top bar).
2. **Name:** `CI – client-portal` · **Access:** *CI test results* · **Expires after:** e.g. `365`.
3. Click **Create token** and copy the value. It's shown only once.
4. Store it as a secret in your CI, named `UNSEEN_TOKEN` (GitHub: repository → Settings → Secrets and variables → Actions → New repository secret).

## Step 4: Send results from CI

Make sure `.unseen.json` is committed.

The CLI is **not published to npm**. CI must get it from the Unseen source code. Once the Unseen code is
in a git repository your CI can read, a GitHub Actions example for a Laravel project is:

```yaml
- name: Get the Unseen CLI
  uses: actions/checkout@v4
  with:
    repository: your-org/unseen          # where the Unseen code lives
    path: .unseen-src
    token: ${{ secrets.UNSEEN_SRC_READ_TOKEN }}   # only needed if that repository is private

- name: Build the Unseen CLI
  run: cd .unseen-src/packages/cli && npm ci && npm run build

- name: Run tests and send results to Unseen
  run: node .unseen-src/packages/cli/dist/cli.js verify --run "php artisan test --log-junit junit.xml" --junit junit.xml
  env:
    UNSEEN_TOKEN: ${{ secrets.UNSEEN_TOKEN }}
```

Add `.unseen-src/` to the project's `.gitignore`, because it's a checkout, not your code. The `verify`
step checks for uncommitted changes; files listed in `.gitignore` don't count.

`--run` runs your tests, sends the results, and **exits with the tests' exit code**, so the CI job still fails
when tests fail. You can also run tests separately and just send the file:

```bash
unseen verify --junit junit.xml                       # several files: repeat --junit
unseen verify --junit junit.xml --dry-run             # see what would be sent
```

Example output:
```
Test run #5: 42 passed, 0 failed, 0 skipped (42 total).
Features: 6 verified, 2 not covered by this run.
https://unseen.example.com/test-runs/5
```

## Step 5: Read the results

- **Feature page → Automated checks:** each check shows *Passed*, *Failed* (with the failing test and message),
  *No matching tests*, or *Not run yet*, plus the run and commit.
- **Project overview → Verification:** the latest run, and implemented features **without** checks.
- **Test run page** (link from either): for every feature with checks, whether it was verified or failed, or why it wasn't changed.

## Running `verify` on your own computer

Possible with a *CI test results* token in `UNSEEN_TOKEN`, but remember: **uncommitted changes block
verification** (failures are still recorded). Commit first, then run.

## What's next

→ [9. API tokens and data safety](09-tokens-and-security.md)
