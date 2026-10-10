import { lstatSync, readdirSync, readFileSync } from 'node:fs';
import { basename, join, relative, sep } from 'node:path';
import { isArchivedDoc, isPrivateDoc, parsePhases, parseRequirements, type DocPhase, type StatusHint } from './docs.ts';
import { canonicalKey, humanize, IGNORED_KEYS, keyFromClass, keyFromPath, toKey } from './keys.ts';

export type EvidenceType = 'route' | 'controller' | 'model' | 'migration' | 'page' | 'component' | 'test' | 'doc';

export interface Evidence {
  type: EvidenceType;
  path: string;
  detail?: string;
  line?: number;
}

export interface Candidate {
  key: string;
  name: string;
  description: string;
  evidence: Evidence[];
  /** From the project's docs: the section a requirement sits in, and what the doc says its status is. */
  category?: string;
  status?: StatusHint;
}

interface DocEntry {
  name: string;
  description: string;
  category: string | null;
  status: StatusHint | null;
  /** Set from an archived doc; a newer doc replaces it. */
  archived: boolean;
  /** Rank of the table the wording came from (see DocRequirement.rank). */
  rank: number;
  statusArchived: boolean;
  evidence: Evidence[];
}

const slug = (s: string) => s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');

export interface ScanResult {
  candidates: Candidate[];
  filesScanned: number;
  truncated: boolean;
  /** More than 500 candidates were found; the rest were left out (documented requirements first). */
  capped: boolean;
}

const SKIP_DIRS = new Set([
  '.git', 'node_modules', 'vendor', 'dist', 'build', 'out', '.next', '.nuxt', '.svelte-kit', 'coverage',
  'storage', 'bootstrap', 'public', '.idea', '.vscode', '__pycache__', '.venv', 'venv', 'tmp', '.cache',
]);
const EXTENSIONS = /\.(php|ts|tsx|js|jsx|mjs|cjs|vue|svelte|py|rb|prisma|md)$/;
const MAX_FILE_BYTES = 512 * 1024;

const lineOf = (content: string, index: number) => content.slice(0, index).split('\n').length;

/**
 * Walks the repository (no symlinks, skips dependency/build folders) and groups evidence
 * of possible features by resource key. Only paths, route paths and names leave this
 * function. File contents are never returned.
 */
export function scanRepository(root: string, opts: { maxFiles?: number } = {}): ScanResult {
  const maxFiles = opts.maxFiles ?? 20_000;
  const found = new Map<string, Evidence[]>();
  const docs = new Map<string, DocEntry>();
  let filesScanned = 0;
  let truncated = false;

  const add = (rawKey: string, evidence: Evidence) => {
    const key = canonicalKey(rawKey);
    if (IGNORED_KEYS.has(key)) return;
    const list = found.get(key) ?? [];
    if (!list.some((e) => e.type === evidence.type && e.path === evidence.path && e.detail === evidence.detail)) list.push(evidence);
    found.set(key, list);
  };

  const walk = (dir: string) => {
    let entries: string[];
    try {
      entries = readdirSync(dir).sort();
    } catch {
      return;
    }
    for (const entry of entries) {
      if (filesScanned >= maxFiles) { truncated = true; return; }
      const full = join(dir, entry);
      const stat = lstatSync(full);
      if (stat.isSymbolicLink()) continue;
      if (stat.isDirectory()) {
        if (!SKIP_DIRS.has(entry) && !entry.startsWith('.')) walk(full);
        continue;
      }
      if (!stat.isFile() || !EXTENSIONS.test(entry) || stat.size > MAX_FILE_BYTES) continue;
      const rel = relative(root, full).split(sep).join('/');
      if (isPrivateDoc(rel)) continue; // never read files that may hold credentials
      filesScanned++;
      inspect(rel, () => readFileSync(full, 'utf8'), add);
      if (rel.toLowerCase().endsWith('.md')) addDocRequirements(rel, readFileSync(full, 'utf8'));
    }
  };

  /** Requirement rows in the project's docs; one entry per requirement id across all docs. */
  const addDocRequirements = (rel: string, content: string) => {
    const archived = isArchivedDoc(rel);
    for (const req of parseRequirements(content)) {
      const key = req.id ? `req-${slug(req.id)}` : `doc-${slug(req.text).split('-').slice(0, 8).join('-')}`;
      if (key === 'doc-') continue;
      const name = req.text.length > 120 ? `${req.text.slice(0, 119).replace(/\s+\S*$/, '')}…` : req.text;
      const evidence: Evidence = {
        type: 'doc', path: rel, line: req.line,
        detail: [req.id, req.statusLabel ? `status: ${req.statusLabel}` : null, req.category].filter(Boolean).join(' · ').slice(0, 500) || undefined,
      };
      const entry = docs.get(key);
      if (!entry) {
        docs.set(key, {
          name, description: req.id ? `${req.id}: ${req.text}` : req.text, category: req.category, status: req.status,
          archived, rank: req.rank, statusArchived: archived, evidence: [evidence],
        });
        continue;
      }
      if (entry.evidence.length < 50) entry.evidence.push(evidence);
      // Wording: a newer doc beats an archived one; then a spec beats a tracker beats a passing mention.
      const better = (entry.archived && !archived) || (archived === entry.archived && req.rank > entry.rank);
      if (better) {
        Object.assign(entry, {
          name, description: req.id ? `${req.id}: ${req.text}` : req.text, category: req.category ?? entry.category,
          archived, rank: req.rank,
        });
      }
      if (req.status && (entry.status === null || (entry.statusArchived && !archived))) {
        entry.status = req.status;
        entry.statusArchived = archived;
      }
    }
  };

  walk(root);

  const docCandidates: Candidate[] = [...docs].map(([key, d]) => ({
    key,
    name: d.name,
    description: `${d.description.slice(0, 1800)} (from the project's docs)`,
    evidence: d.evidence,
    ...(d.category ? { category: d.category.slice(0, 255) } : {}),
    ...(d.status ? { status: d.status } : {}),
  }));

  const codeCandidates = [...found]
    .map(([key, evidence]) => ({
      key,
      name: humanize(key),
      description: describe(evidence),
      evidence: evidence.slice(0, 50),
    }))
    .sort((a, b) => b.evidence.length - a.evidence.length || a.key.localeCompare(b.key));

  // Documented requirements first: for a documented project they are the feature list.
  const candidates = [...docCandidates, ...codeCandidates].slice(0, 500);

  return { candidates, filesScanned, truncated, capped: docCandidates.length + codeCandidates.length > 500 };
}

function describe(evidence: Evidence[]): string {
  const counts = new Map<string, number>();
  evidence.forEach((e) => counts.set(e.type, (counts.get(e.type) ?? 0) + 1));
  const parts = [...counts].map(([type, n]) => `${n} ${type}${n === 1 ? '' : 's'}`);
  return `Found in the repository: ${parts.join(', ')}.`;
}

type Add = (key: string, evidence: Evidence) => void;

/** Runs every detector that applies to one file. */
export function inspect(rel: string, read: () => string, add: Add): void {
  const file = basename(rel);
  const lower = rel.toLowerCase();

  // Tests (checked first so test files are not also counted as routes/components).
  const testMatch = file.match(/^(.+?)(Test|\.test|\.spec|_test)\.(php|tsx?|jsx?|py|rb)$/) ?? file.match(/^test_(.+)\.py$/);
  if (testMatch || /(^|\/)(tests?|__tests__|spec)\//.test(lower)) {
    if (testMatch) add(keyFromClass(testMatch[1]!), { type: 'test', path: rel });
    return;
  }

  // Laravel / PHP routes files and JS/TS route registrations.
  if (/^routes\/.+\.php$/.test(lower) || /\.(ts|js|mjs|cjs)$/.test(file)) {
    const content = read();
    const routeCall = /Route::(get|post|put|patch|delete|any|match|livewire|view|redirect)\(\s*(?:\[[^\]]*\]\s*,\s*)?['"]([^'"]+)['"]/g;
    const resourceCall = /Route::(?:api)?[Rr]esources?\(\s*['"]([^'"]+)['"]/g;
    const jsRoute = /\b(?:app|router|server|api|route)\.(get|post|put|patch|delete|all)\(\s*['"`]([^'"`]+)['"`]/g;
    for (const m of content.matchAll(routeCall)) {
      add(keyFromPath(m[2]!), { type: 'route', path: rel, detail: `${m[1]!.toUpperCase()} /${m[2]!.replace(/^\//, '')}`, line: lineOf(content, m.index!) });
    }
    for (const m of content.matchAll(resourceCall)) {
      const name = m[1]!.split('.').pop()!;
      add(toKey(name), { type: 'route', path: rel, detail: `resource ${m[1]}`, line: lineOf(content, m.index!) });
    }
    if (!lower.endsWith('.php')) {
      for (const m of content.matchAll(jsRoute)) {
        add(keyFromPath(m[2]!), { type: 'route', path: rel, detail: `${m[1]!.toUpperCase()} ${m[2]}`, line: lineOf(content, m.index!) });
      }
    }
  }

  // Controllers.
  const controller = file.match(/^(\w+)Controller\.(php|ts|js|rb)$/) ?? file.match(/^(\w+)_controller\.rb$/);
  if (controller && controller[1] !== '' && file !== 'Controller.php') {
    add(keyFromClass(controller[1]!), { type: 'controller', path: rel });
  }

  // Models.
  if (/(^|\/)(app\/models|models)\/[^/]+\.(php|ts|js|py|rb)$/.test(lower) && !/^(index|base)\./i.test(file)) {
    add(keyFromClass(file), { type: 'model', path: rel });
  }
  if (file === 'schema.prisma') {
    const content = read();
    for (const m of content.matchAll(/^model\s+(\w+)\s*\{/gm)) add(toKey(m[1]!), { type: 'model', path: rel, detail: `model ${m[1]}`, line: lineOf(content, m.index!) });
  }

  // Migrations creating tables.
  const migration = file.match(/create_(\w+?)_table\.php$/);
  if (migration && lower.includes('migrations/')) add(toKey(migration[1]!), { type: 'migration', path: rel, detail: `table ${migration[1]}` });

  // Livewire / UI components.
  if (/^app\/livewire\/.+\.php$/.test(lower)) add(keyFromClass(file), { type: 'component', path: rel });

  // File-based routing (Next.js app/pages, Nuxt, SvelteKit).
  const appPage = rel.match(/(?:^|\/)app\/(.*?)\/?page\.(tsx|jsx|ts|js)$/);
  const pagesPage = rel.match(/(?:^|\/)pages\/(.+)\.(tsx|jsx|ts|js|vue)$/);
  const sveltePage = rel.match(/(?:^|\/)routes\/(.*?)\/?\+page\.svelte$/);
  const route = appPage?.[1] ?? sveltePage?.[1] ?? pagesPage?.[1];
  if (route !== undefined && !/(^|\/)_/.test(route)) {
    const isApi = route.startsWith('api/');
    const cleaned = route.replace(/(^|\/)index$/, '');
    add(keyFromPath(cleaned), { type: isApi ? 'route' : 'page', path: rel, detail: `/${cleaned}` });
  }

  // README "Features" section bullets.
  if (/^readme\.md$/i.test(rel)) {
    const lines = read().split('\n');
    let inFeatures = false;
    let level = 0;
    lines.forEach((line, i) => {
      const heading = line.match(/^(#{1,6})\s+(.*)$/);
      if (heading) {
        if (inFeatures && heading[1]!.length <= level) inFeatures = false;
        if (/feature/i.test(heading[2]!)) { inFeatures = true; level = heading[1]!.length; }
        return;
      }
      const bullet = inFeatures && line.match(/^\s*[-*]\s+(?:\*\*)?([^*:—–-][^*:—–]{1,80}?)(?:\*\*)?\s*(?:[:—–-]|$)/);
      if (bullet) add(toKey(bullet[1]!.trim()), { type: 'doc', path: rel, detail: bullet[1]!.trim(), line: i + 1 });
    });
  }
}

export interface RoadmapDraft {
  /** The doc the phases came from (relative path), or null when no doc has phases. */
  source: string | null;
  phases: DocPhase[];
  /** Other docs with phases (e.g. older plans), for the agent to check. */
  others: { path: string; phases: number }[];
}

/** Markdown files the scanner may read (same skips as the scan: dependencies, hidden, private). */
export function markdownFiles(root: string, maxFiles = 5_000): string[] {
  const out: string[] = [];
  const walk = (dir: string) => {
    let entries: string[];
    try { entries = readdirSync(dir).sort(); } catch { return; }
    for (const entry of entries) {
      if (out.length >= maxFiles) return;
      const full = join(dir, entry);
      const stat = lstatSync(full);
      if (stat.isSymbolicLink()) continue;
      if (stat.isDirectory()) { if (!SKIP_DIRS.has(entry) && !entry.startsWith('.')) walk(full); continue; }
      const rel = relative(root, full).split(sep).join('/');
      if (stat.isFile() && /\.md$/i.test(entry) && stat.size <= MAX_FILE_BYTES && !isPrivateDoc(rel)) out.push(rel);
    }
  };
  walk(root);
  return out;
}

/**
 * A roadmap drafted from the project's plan docs ("## Phase 2 — …" sections and their status tables).
 * Picks the current (not archived) doc with the most phases. A starting point for the agent to check.
 */
export function draftRoadmap(root: string): RoadmapDraft {
  const found = markdownFiles(root)
    .map((path) => ({ path, phases: parsePhases(readFileSync(join(root, path), 'utf8')) }))
    .filter((d) => d.phases.length >= 2)
    .sort((a, b) => Number(isArchivedDoc(a.path)) - Number(isArchivedDoc(b.path))
      || b.phases.length - a.phases.length
      || b.phases.reduce((n, p) => n + p.items.length, 0) - a.phases.reduce((n, p) => n + p.items.length, 0));
  const [best, ...rest] = found;
  return {
    source: best?.path ?? null,
    phases: best?.phases ?? [],
    others: rest.map((d) => ({ path: d.path, phases: d.phases.length })),
  };
}
