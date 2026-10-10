import { dirname } from 'node:path';
import { VERSION } from '../api.ts';
import { findProjectConfig } from '../config.ts';
import { linkedProject, type Context } from '../context.ts';
import { gitInfo } from '../git.ts';
import { redactSecrets } from '../redact.ts';
import { scanRepository } from '../scan/scanner.ts';

export interface ScanOptions {
  dryRun?: boolean;
  json?: boolean;
  git?: boolean;
}

/**
 * Scans the linked repository locally and sends candidate features to Unseen as
 * suggestions. Only file paths, route paths and class names are sent, never file contents.
 * Nothing becomes a feature until someone reviews it in Unseen.
 */
export async function scan(opts: ScanOptions, ctx: Context): Promise<number> {
  const { config, client, token } = linkedProject(ctx);
  const root = dirname(findProjectConfig(ctx.cwd)!.path);

  const result = scanRepository(root);
  const git = opts.git === false ? null : gitInfo(root);
  const payload = {
    scanner: `unseen-cli ${VERSION}`,
    git_branch: git?.branch ?? undefined,
    git_commit: git?.commit ?? undefined,
    candidates: result.candidates.map((c) => ({
      key: c.key, name: c.name, description: c.description, evidence: c.evidence,
      ...(c.category ? { category: c.category } : {}), ...(c.status ? { status: c.status } : {}),
    })),
  };
  const { value: clean } = redactSecrets(payload, [token]);

  if (result.truncated) ctx.err('Stopped after 20,000 files; some of the repository was not scanned.');
  if (result.capped) ctx.err('Found more than 500 candidates; sent the first 500 (documented requirements first).');

  if (opts.dryRun) {
    if (opts.json) {
      ctx.out(JSON.stringify(clean, null, 2));
    } else {
      ctx.out(`Scanned ${result.filesScanned} files in ${root}. ${result.candidates.length} candidate features:`);
      for (const c of result.candidates) {
        const types = [...new Set(c.evidence.map((e) => e.type))].join(', ');
        const hints = [c.category, c.status ? `docs say ${c.status.replace(/_/g, ' ')}` : null].filter(Boolean).join(', ');
        ctx.out(`  ${c.name} (${c.evidence.length}: ${types})${hints ? ` [${hints}]` : ''}`);
      }
    }
    ctx.err('Dry run: nothing was sent.');
    return 0;
  }

  const summary = await client.submitScan(config.projectId, clean);

  if (opts.json) {
    ctx.out(JSON.stringify({ ...summary, review_url: `${config.apiUrl}/projects/${config.projectId}/suggestions` }, null, 2));
    return 0;
  }

  ctx.out(`Scanned ${result.filesScanned} files: ${summary.candidates} candidate features (${summary.new} new, ${summary.updated} seen before).`);
  ctx.out(`${summary.pending_review} waiting for review: ${config.apiUrl}/projects/${config.projectId}/suggestions`);
  ctx.out('Suggestions become features only after someone accepts them. Code existing does not prove a feature works.');
  return 0;
}
