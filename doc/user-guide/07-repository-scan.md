# 7. Repository scan

Already have a codebase but no feature list? A **scan** looks through the code for signs of features and turns
them into **suggestions** for you to review. Nothing becomes a feature until a person accepts it.

## What the scan looks at

| Evidence | Examples |
|---|---|
| Routes | Laravel `routes/*.php`, Express-style `router.get('/orders')`, Next.js / Nuxt / SvelteKit pages |
| Controllers | `InvoiceController.php` |
| Models | `app/Models/Invoice.php`, Prisma `model Invoice` |
| Table migrations | `create_invoices_table.php` |
| UI components | Livewire components |
| Tests | `InvoiceTest.php`, `invoice.test.ts`, `test_invoice.py` |
| README | Bullet points under a heading containing "Features" |
| **Project docs** (any `.md`, e.g. `docs/`) | Requirement tables in PRDs, specs and implementation matrices: rows with an id like `BR-01` or `F01` and a requirement/feature column |

**Documented projects.** Most existing projects keep their real feature list in Markdown docs, usually as tables.
The scan reads every requirement table it finds and suggests **one feature per requirement**:

| From the docs | Becomes |
|---|---|
| The requirement id (`BR-01`) | The suggestion's key, so rescans update it instead of duplicating it |
| The requirement wording | The name and description. A spec (PRD) beats a status tracker, which beats a passing mention; current docs beat `archive/` |
| The section heading (`## BR — Business goals`) | The category (*Business goals*), created when the suggestion is accepted |
| A status column (🟢 Done, 🔵 Base, 🟡 Partial, 🔴 Blocked, ⚪ To do, or words like *done*, *in progress*, *blocked*) | The suggested status: *Implemented*, *In progress*, *Blocked* or *Planned*. Accepting uses it, so a partial requirement isn't recorded as built. Docs never make anything *verified* |

The same id in several docs (PRD, matrix, status report) is merged into one suggestion, with each doc listed as
evidence. Task lists (`| # | Item |`), QA findings and permission matrices are not features and are skipped.
Files that may hold credentials (`*.local.*`, names containing *secret*, *credential*, *password* or `.env`) are
never read.

Related evidence is grouped: `InvoiceController`, the `Invoice` model, `GET /invoices` and `InvoiceTest` become
**one** suggestion called *Invoice*. Login, logout, password reset and token routes are grouped as *Authentication*.

**Privacy:** the scan runs on your computer. Only file paths, line numbers, route paths and class names are sent,
plus, from requirement tables, the requirement id, its wording (up to 300 characters), the section heading and
the status cell, with likely secrets masked. Never whole files. It skips `vendor/`, `node_modules/`, build output and hidden folders, and doesn't follow links
to outside folders.

## Step 1: Preview the scan

In the linked repository ([guide 5](05-claude-code-cli.md)):

```bash
unseen scan --dry-run
```
```
Scanned 96 files in /Users/you/code/client-portal. 17 candidate features:
  Project (23: controller, component, model, migration, route, test)
  Invoice (9: controller, model, migration, route, test)
  Authentication (5: controller, component, route, test)
  ...
Dry run: nothing was sent.
```

## Step 2: Send it

```bash
unseen scan
```
```
Scanned 96 files: 17 candidate features (17 new, 0 seen before).
17 waiting for review: http://127.0.0.1:8000/projects/3/suggestions
```

## Step 3: Agents review the suggestions

The coding agent reviews each suggestion through the API and either **accepts** it (creating a feature, which
starts as *Needs verification* when implemented), **merges** it into a feature it already tracks, or **dismisses**
it (helper code, framework plumbing). These decisions are recorded as *Reported by an agent*.

On the project → **Suggestions** tab you can follow along:
- **Waiting for review**, **Accepted**, **Merged** and **Dismissed** tabs, with counts.
- **Evidence** on each suggestion: exactly which files and routes were found.
- **Possible duplicate** when a suggestion's name matches a feature already tracked.
- For accepted and merged suggestions, a link to the feature they became part of.

## Step 4: Rescan whenever the code changes

```bash
unseen scan
```

Rescanning is safe:
- Existing suggestions are **updated**, never duplicated.
- **Dismissed** suggestions stay dismissed.
- Accepted or merged suggestions stay linked to their feature, and your edited names are never overwritten.
- A badge **"Not seen in the latest scan"** marks suggestions whose code seems to have gone.

Accepted or merged features show their evidence on the feature page under **Found in the repository**.

## Tips

- Expect some noise: the scan is heuristic. Dismiss freely.
- Do the first review in one sitting. After that, only new items appear.
- Ask Claude Code to run `unseen scan` after adding routes, models or pages (the CLAUDE.md snippet already says so).

## What's next

→ [8. Verification with automated tests](08-verification.md)
