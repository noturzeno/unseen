# 2. Projects and team

A **project** is one product or codebase you want to track, for example a client website or an internal tool.

## Create a project

1. On **Projects**, click **New project**.
2. Fill in:
   | Field | Example | Notes |
   |---|---|---|
   | Name | `Client Portal` | Required |
   | Description | `Self-service portal for agency clients` | One or two sentences |
   | Repository | `git@github.com:example/client-portal.git` | A `https://`, `ssh://`, `git://` or `git@host:org/repo` URL, or an absolute local path like `/Users/you/code/portal` |
   | Main technology | `Laravel, Vue` | Free text |
   | Platforms | iOS, Android | Optional. Tick each platform the project ships on. With two or more, the dashboard shows progress per platform ([guide 4](04-dashboard.md#platforms)) |
   | Track PRD vs design | On | On by default. Turn it off for projects without a separate design: the **PRD vs design** tab, its dashboard card and the agent's instructions for it are hidden, and agents can't record new mismatches. Recorded mismatches are kept ([guide 13](13-prd-vs-design.md)) |
3. Click **Save project**.

You're taken to the project's overview page (see [guide 4](04-dashboard.md)). Next, connect your coding agent:
pick **Just started** or **Already developed**, copy the prompt, and paste it into Claude Code in the project's repository
([guide 5](05-claude-code-cli.md#fastest-paste-a-setup-prompt-into-your-agent)). That's all: the agent does the rest.

## Edit a project

1. Open the project.
2. Click **Settings** (top right).
3. Change the details and click **Save project**.

## Add a colleague to a project

Only the project **owner** (the person who created it) can do this.

1. Make sure your colleague has an account. If not, an admin creates one ([guide 10](10-administration.md)).
2. Open the project → **Settings**.
3. In **People**, type their email address and click **Add member**.

Your colleague now sees the project in their list.

**What members can and can't do**

| Action | Owner | Member |
|---|---|---|
| View the project, features, reports, scans, test runs | ✅ | ✅ |
| Edit project details, features, categories, checks | ✅ | ✅ |
| Review scan suggestions, close unresolved work | ✅ | ✅ |
| Add or remove members | ✅ | ❌ |
| Archive or restore the project | ✅ | ❌ |
| Leave the project | n/a | ✅ |

## Remove a colleague, or leave a project

- **Owner:** Settings → People → **Remove** next to their name → confirm.
- **Member leaving:** Settings → People → **Leave project** next to your own name → confirm.

## Archive a project

Archiving hides a project from the list without deleting anything.

1. Open the project → **Settings**.
2. Under **Archive project**, click **Archive project**.

To see archived projects, tick **Show archived** on the Projects page. To bring one back, open it →
**Settings** → **Restore project**.

> Projects cannot be deleted from Unseen. This keeps the history and evidence intact.

## What's next

→ [3. Features and categories](03-features.md)
