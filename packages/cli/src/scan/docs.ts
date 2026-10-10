/**
 * Reads a project's Markdown docs (PRDs, implementation matrices, plans, phase status reports) for
 * requirements and roadmap phases. Most documented projects keep this in tables:
 *   | Req | Requirement | Status | …      → one feature per row, with the doc's status as a hint
 *   ## BR — Business goals                → category for the rows below it
 *   ## Phase 4 — Public discovery · L     → a roadmap phase, with the status tables inside it as items
 * Only short cell texts leave the machine (trimmed, and masked for secrets by the caller).
 */

export type StatusHint = 'implemented' | 'in_progress' | 'blocked' | 'planned' | 'not_implemented';

export interface DocRequirement {
  id: string | null;
  text: string;
  status: StatusHint | null;
  statusLabel: string | null;
  category: string | null;
  line: number;
  /**
   * Whose wording wins when the same id is in several docs: 3 = requirements spec (id + requirement
   * column, no status column, like a PRD), 2 = status tracker (adds a status column, like an
   * implementation matrix), 1 = passing mention.
   */
  rank: 1 | 2 | 3;
}

export interface DocPhase {
  key: string;
  name: string;
  description: string | null;
  line: number;
  items: { text: string; done: boolean }[];
}

/** Doc files that may hold credentials or local notes are never read. */
export function isPrivateDoc(path: string): boolean {
  return /(^|\/)[^/]*(\.local\.|secret|credential|password|passwd|\.env)[^/]*$/i.test(path);
}

/** Superseded docs: used only when nothing newer says the same thing. */
export function isArchivedDoc(path: string): boolean {
  return /(^|\/)(_?archive[sd]?|old|deprecated|obsolete)\//i.test(path);
}

/** A requirement-style id: BR-01, F01, USR.3, B12, DV.17, REQ-12a. */
const REQ_ID = /^[A-Z][A-Z0-9]{0,7}[-._]?\d+[a-z]?([.-]\d+)*$/;

const STRONG_TEXT = /requirement|feature|capabilit|user stor|^stor(y|ies)$/i;
const WEAK_TEXT = /^(item|title|name|description|what|page|screen|deliverable|scope|task)$/i;
const ID_HEADER = /^(req(uirement)?\s*(id|#)?|ref(erence)?|id|code|key|#|no\.?|story)$/i;
/** "#" / "No." columns number tasks and questions; they only count with a requirement/feature column. */
const NUMBERING_HEADER = /^(#|no\.?)$/i;
/** "PRP-01 / SRC-02 price" → id PRP-01, text "price". */
const LEADING_ID = /^([A-Z][A-Z0-9]{0,7}[-._]?\d+[a-z]?(?:[.-]\d+)*)(?:\s*[/,&]\s*[A-Z][A-Z0-9]{0,7}[-._]?\d+[a-z]?(?:[.-]\d+)*)*\s*[:—–-]?\s+(.+)$/;
const STATUS_HEADER = /status|state|verdict|^today$|^done\??$|progress|result/i;

export function cleanCell(cell: string): string {
  return cell
    .replace(/!\[[^\]]*\]\([^)]*\)/g, '')
    .replace(/\[([^\]]+)\]\([^)]*\)/g, '$1')
    .replace(/<[^>]+>/g, ' ')
    .replace(/[`*_]{1,3}([^`*_]+)[`*_]{1,3}/g, '$1')
    .replace(/\\\|/g, '|')
    .replace(/\s+/g, ' ')
    .trim();
}

function splitRow(line: string): string[] {
  return line.trim().replace(/^\|/, '').replace(/\|$/, '').split(/(?<!\\)\|/).map((c) => c.trim());
}

const isSeparator = (line: string) => /^\s*\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?\s*$/.test(line);

/** Maps a doc's status wording or emoji to an Unseen implementation status. Never "verified". */
export function statusFromText(raw: string): StatusHint | null {
  const s = raw.toLowerCase();
  if (!s.trim()) return null;
  if (/🟢|✅|✔|☑|🔵/.test(raw)) return 'implemented';
  if (/🟡|🟠|⚠|◐/.test(raw)) return 'in_progress';
  if (/🔴|⛔|🚫/.test(raw)) return 'blocked';
  if (/⚪|⬜|❌|◯/.test(raw)) return 'planned';
  if (/\b(dropped|descoped|won'?t|cancell?ed|not implemented|out of scope|rejected)\b/.test(s)) return 'not_implemented';
  if (/\b(to ?do|not (yet )?started|not built|nothing built|backlog)\b/.test(s)) return 'planned';
  if (/\b(partial(ly)?|in progress|wip|ongoing|started|half)\b/.test(s)) return 'in_progress';
  if (/\b(blocked|waiting|on hold)\b/.test(s)) return 'blocked';
  if (/\b(planned|pending|missing|open|no)\b/.test(s)) return 'planned';
  if (/\b(done|complete[d]?|implemented|shipped|live|pass(ed)?|built|yes|wired|working|base)\b/.test(s)) return 'implemented';
  return null;
}

/** "## BR — Business goals  (🟢3 · 🟡5)" → "Business goals"; "### 4.1 User Roles" → "User Roles". */
export function cleanHeading(text: string): string {
  return cleanCell(text)
    .replace(/\s*\([^)]*[\p{Extended_Pictographic}·][^)]*\)\s*$/u, '')
    .replace(/^[\d.]+\s+/, '')
    .replace(/^[A-Z]{2,6}\s*[—–-]\s+/, '')
    .replace(/\s*·\s*(XS|S|M|L|XL|XXL)\s*$/, '')
    .trim();
}

interface Table { header: string[]; rows: { cells: string[]; line: number }[]; line: number }

function tables(lines: string[], from = 0, to = lines.length): Table[] {
  const out: Table[] = [];
  for (let i = from; i < to - 1; i++) {
    if (!lines[i]!.trim().startsWith('|') || !isSeparator(lines[i + 1]!)) continue;
    const table: Table = { header: splitRow(lines[i]!).map(cleanCell), rows: [], line: i + 1 };
    let j = i + 2;
    for (; j < to && lines[j]!.trim().startsWith('|'); j++) table.rows.push({ cells: splitRow(lines[j]!), line: j + 1 });
    out.push(table);
    i = j - 1;
  }
  return out;
}

function headingAt(lines: string[]): (line: number) => string | null {
  const headings: { line: number; level: number; text: string }[] = [];
  lines.forEach((l, i) => {
    const m = /^(#{1,6})\s+(.+?)\s*#*\s*$/.exec(l);
    if (m) headings.push({ line: i, level: m[1]!.length, text: m[2]! });
  });
  return (line) => {
    const above = headings.filter((h) => h.line < line && h.level <= 3);
    const nearest = above[above.length - 1];
    return nearest ? cleanHeading(nearest.text) || null : null;
  };
}

const truncate = (s: string, max: number) => (s.length <= max ? s : `${s.slice(0, max - 1).replace(/\s+\S*$/, '')}…`);

/** Requirement rows from every requirement-style table in one Markdown document. */
export function parseRequirements(content: string): DocRequirement[] {
  const lines = content.split('\n');
  const categoryAt = headingAt(lines);
  const out: DocRequirement[] = [];

  for (const table of tables(lines)) {
    const idCol = table.header.findIndex((h) => ID_HEADER.test(h));
    const strongText = table.header.findIndex((h) => STRONG_TEXT.test(h));
    const textCol = strongText >= 0 ? strongText : table.header.findIndex((h) => WEAK_TEXT.test(h));
    if (textCol < 0 || textCol === idCol) continue;
    const statusCol = table.header.findIndex((h, i) => i !== idCol && i !== textCol && STATUS_HEADER.test(h));

    const ids = idCol >= 0 ? table.rows.map((r) => cleanCell(r.cells[idCol] ?? '')).filter(Boolean) : [];
    const idsLookLikeRequirements = idCol >= 0 && !NUMBERING_HEADER.test(table.header[idCol]!)
      && ids.length > 0 && ids.filter((id) => REQ_ID.test(id)).length >= ids.length / 2;
    // Requirement tables only: a requirement/feature column, or ids like BR-01. Task lists ("# | Item") are skipped.
    if (strongText < 0 && !idsLookLikeRequirements) continue;

    for (const row of table.rows) {
      let text = cleanCell(row.cells[textCol] ?? '');
      if (!text || /^[-–—]+$/.test(text)) continue;
      const rawId = idCol >= 0 ? cleanCell(row.cells[idCol] ?? '') : '';
      let id = rawId && REQ_ID.test(rawId) ? rawId : null;
      if (!id) {
        const lead = LEADING_ID.exec(text);
        if (lead) { id = lead[1]!; text = lead[2]!; }
      }
      const statusCell = statusCol >= 0 ? cleanCell(row.cells[statusCol] ?? '') : '';
      out.push({
        id,
        rank: idsLookLikeRequirements && strongText >= 0 && id === rawId ? (statusCol < 0 ? 3 : 2) : 1,
        text: truncate(text, 300),
        status: statusFromText(statusCell),
        statusLabel: statusCell ? truncate(statusCell, 40) : null,
        category: categoryAt(row.line - 1),
        line: row.line,
      });
    }
  }
  return out;
}

const PHASE = /^(#{1,3})\s+(?:\*\*)?(phase|milestone|sprint|release|stage|iteration)\s+([0-9A-Za-z][\w.]*)\b\s*(?:[—–:-]\s*)?(.*?)(?:\*\*)?\s*$/i;

/** Roadmap phases ("## Phase 2 — Auth, static pages (F11–F24) · M") and their status tables / checklists. */
export function parsePhases(content: string): DocPhase[] {
  const lines = content.split('\n');
  const starts: { line: number; level: number; m: RegExpExecArray }[] = [];
  lines.forEach((l, i) => {
    const m = PHASE.exec(l);
    if (m) starts.push({ line: i, level: m[1]!.length, m });
  });

  return starts.map((start, n) => {
    let end = lines.length;
    for (let i = start.line + 1; i < lines.length; i++) {
      const h = /^(#{1,6})\s/.exec(lines[i]!);
      if (h && h[1]!.length <= start.level) { end = i; break; }
    }
    if (starts[n + 1] && starts[n + 1]!.line < end) end = starts[n + 1]!.line;

    const [, , word, num, rest] = start.m;
    const title = cleanHeading(rest ?? '');
    const refs = /\(([^)]*)\)/.exec(title);
    const name = title.replace(/\s*\([^)]*\)\s*/g, ' ').trim() || `${word} ${num}`;
    const items: { text: string; done: boolean }[] = [];

    for (const table of tables(lines, start.line + 1, end)) {
      const statusCol = table.header.findIndex((h) => STATUS_HEADER.test(h));
      const textCols = table.header.map((h, i) => (STRONG_TEXT.test(h) || WEAK_TEXT.test(h) || /block|step/i.test(h) ? i : -1)).filter((i) => i >= 0 && i !== statusCol);
      if (statusCol < 0 || textCols.length === 0) continue;
      for (const row of table.rows) {
        const text = textCols.slice(0, 2).map((i) => cleanCell(row.cells[i] ?? '')).filter(Boolean).join(' – ');
        if (text) items.push({ text: truncate(text, 200), done: statusFromText(cleanCell(row.cells[statusCol] ?? '')) === 'implemented' });
      }
    }
    for (let i = start.line + 1; i < end; i++) {
      const c = /^\s*[-*]\s+\[( |x|X)\]\s+(.+)$/.exec(lines[i]!);
      if (c) items.push({ text: truncate(cleanCell(c[2]!), 200), done: c[1] !== ' ' });
    }

    return {
      key: `${word!.toLowerCase()}-${num!.toLowerCase()}`,
      name: truncate(name, 200),
      description: refs ? `Covers ${refs[1]}` : null,
      line: start.line + 1,
      items: items.slice(0, 200),
    };
  });
}
