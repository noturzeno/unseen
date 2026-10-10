# 5. Connecting Claude Code (the `unseen` CLI)

The `unseen` command-line tool connects a code repository to an Unseen project. Claude Code (or you)
uses it to:

| Command | Does |
|---|---|
| `unseen init` | Link this repository to an Unseen project |
| `unseen status` | Show the project's features, progress and open work |
| `unseen report` | Record what was done ([guide 6](06-development-reports.md)) |
| `unseen scan` | Find features already in the code ([guide 7](07-repository-scan.md)) |
| `unseen verify` | Send automated test results ([guide 8](08-verification.md)), normally from CI |

## Fastest: paste a setup prompt into your agent

1. Open the project in Unseen. A new project shows **Connect your coding agent** (later: **Settings → Coding
   agent**). Pick where the project is:
   - **Just started** (little or no code): the agent records the planned features from the README, docs or
     specs (asking you about anything unclear), then tracks them as it builds.
   - **Already developed** (existing codebase): the agent scans the code, reviews every suggestion, and records
     each existing feature with an honest status, linked tests and diagrams.
   - **Join this project** (shown instead once features are recorded): for a new contributor joining a project
     someone already set up. The agent installs the CLI, links the repository with its own token and reads the
     existing inventory, without scanning or importing it again, so nothing is duplicated. The two setup options
     are still under **Set up from scratch instead**.
2. Click **Copy prompt**.
3. Open the real project's repository in Claude Code (or another coding agent) and paste the prompt as a message.

The agent then installs the CLI from your Unseen server, links the repository, saves the token in its
private credentials file (never in the repository), adds the Unseen section to `CLAUDE.md`, scans the code and
records the features as described above. Features appear in Unseen as it works.

**About the token in the prompt.** It is shown once and is limited on purpose: it works for **this project only**,
can record features, reports and scans but can't verify, change projects or members, or manage tokens, and expires
after 90 days. Paste it only into your coding agent. Revoke it on **API tokens** if it leaks; generate a new prompt
for each new repository or computer. Each project gets its own token, also on the agent's computer: linking a
second project there never replaces the first project's token.

The steps below do the same by hand.

## One-click: Proceed buttons

Every **Copy prompt** in Unseen (feature, diagram sync, roadmap phase, unresolved work, suggested next steps) has a
**Proceed** button next to it (on the Diagrams card it reads **Sync with code**) that opens Claude Code with that prompt in the project's folder for you.

1. **Once per project:** link the repository (the setup prompt above), then in Unseen open the project's
   **Settings** and fill in **Project folder on your computer**, e.g. `/Users/you/code/client-portal`.
2. **On that computer,** start the runner in a terminal and leave it open (Ctrl+C stops it):
   ```bash
   unseen runner
   ```
   It watches every project this computer is linked to. Settings shows **Runner online** while it runs.
3. **Click Proceed.** A new Terminal window opens in the project folder running `claude` with the prompt. The
   button shows *Sending to your computer…*, then *Opened in Claude Code ✓* (or why it couldn't).

**Safety:** Unseen can't run anything on your computer by itself: the runner you started does. It only opens
folders that exist and are linked (`.unseen.json`) to the same project and server, the prompt is built by
Unseen from what you clicked, and requests nobody picks up within 10 minutes expire. Claude Code opens in a
normal, visible session and still asks before running commands. The project folder can only be set in the web
app, never through the API. Stop the runner when you don't want Proceed to work.

**Which Claude account:** the runner opens Claude Code with the project folder's own environment. If you use
[direnv](https://direnv.net/) and a `.envrc` there (or in a parent folder, e.g. one for all client work) sets
`CLAUDE_CONFIG_DIR`, that account is used, exactly as when you `cd` into the folder yourself. Only `.envrc` files
you have allowed (`direnv allow`) are applied. The Terminal window shows the account it uses.

**Options:** `--print` shows the command instead of opening a terminal; `--claude <command>` uses another Claude
Code command; `--project <id>` watches one project. macOS opens Terminal (the first time, macOS may ask to let
the runner control Terminal); Linux opens the first terminal program it finds.

## Step 1: Install the CLI (once per computer)

```bash
cd ~/Desktop/VisibleOne/tracker/packages/cli
npm install
npm run build
npm link
```

Check it works:
```bash
unseen --version     # prints 0.1.0
unseen --help
```

## Step 2: Make sure the project exists in Unseen

Create it in the web app first ([guide 2](02-projects-and-team.md)). Note its **id**: it's the number in the
address bar, e.g. `http://127.0.0.1:8000/projects/3` → id **3**.

## Step 3: Link the repository

```bash
cd ~/code/client-portal          # your project's repository
unseen init
```

Answer the prompts:

1. **Unseen URL:** press Enter for `https://onez-unseen.vercel.app`, or type another address such as `http://127.0.0.1:8000` for a local server (must be `https://` unless it's on your own machine).
2. **Email / Password:** your Unseen login. Nothing appears on screen while you type the password.
3. **Which project is this repository?** Type the number from the list.

You'll see:
```
Token saved to /Users/you/.config/unseen/credentials.json (readable only by you).
Linked to "Client Portal" (id 3) as Your Name.
Wrote /Users/you/code/client-portal/.unseen.json. It holds no secrets, so commit it.
```

What happened:
- A **coding-agent token** was created for you: it can read the project, record features (with their statuses,
  criteria, checks and diagrams), and send reports and scans, but cannot verify anything. It **expires after 90
  days**; run `unseen init --force` to renew it.
- The token is stored in your home folder with private permissions, **not** in the repository.
- `.unseen.json` (URL and project id only) was written to the repository. It's safe to commit.

**Without prompts** (scripts, or when you already have a token from the API tokens page):
```bash
export UNSEEN_TOKEN=...     # paste from the API tokens page; don't save it in the repo
unseen init --api-url https://onez-unseen.vercel.app --project 3
```

## Step 4: Check the connection

```bash
unseen status
```

You'll see progress, items needing attention, open unresolved work (with `#ids`) and every feature with its `#id`.
For Claude Code, the same information is available as JSON:
```bash
unseen status --json
```

## Step 5: Tell Claude Code to use Unseen

Add this to the repository's `CLAUDE.md` (create the file if it doesn't exist). A longer version, and an
`AGENTS.md` version for other agents, are in the [instruction snippets](../agent-guide/instructions-snippet.md).
The full rules the agent follows are in the [agent guide](../agent-guide/README.md).

```markdown
## Unseen

This repository is tracked in Unseen (`.unseen.json`).

- Before starting a task, run `unseen status --json` to see the existing features, their
  status, and open unresolved work (with ids). Prefer existing feature names over inventing new ones.
- After finishing a task, write a report JSON (see `unseen report --help`) and submit it with
  `unseen report --file <file>`. Include every feature you changed, the files changed, the
  exact test commands you ran with their real outcomes (`not_run` if you did not run them),
  anything left unresolved, and honest limitations.
- Only claim `implemented` when the behaviour is actually built. Never claim verification; that
  comes from CI test runs (`unseen verify`), which this token cannot submit.
- Never put secrets, `.env` values or personal data in a report.
- If your work fully resolves an open unresolved item, list its id in `resolves`.
- If a feature you worked on is not in Unseen, say so in `unresolved` instead of guessing a name.
- After adding new routes, models or pages, run `unseen scan` so they show up for review.
```

From now on, Claude Code checks Unseen before working and reports afterwards.

## Step 6: Try it

Ask Claude Code something small, e.g. *"Fix the typo on the login page, then report to Unseen."*
Then open the project in Unseen → **Reports**. The new report should be there.

## Updating the CLI

Unseen notes which CLI version each person's requests come from. When someone on the project has used an older
version in the last 30 days, the project overview shows **Update the Unseen CLI** with their names. Click **Copy
update prompt** and paste it into the coding agent on that computer, or click **Proceed** to send it to your own
runner. You can also reinstall by hand: run the same `npm install -g …/downloads/unseen-cli.tgz` command as in step 1.
The saved token and the repository link are kept. Restart `unseen runner` afterwards if it was running.

## Using the CLI from a subfolder

All commands work from any folder inside the repository. They find `.unseen.json` by looking upwards.

## What's next

→ [6. Development reports](06-development-reports.md)
