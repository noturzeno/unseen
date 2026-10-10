/**
 * Best-effort removal of secrets from report text before it leaves the machine.
 * It catches common token formats and key=value secrets; it is not a guarantee.
 */

const MASK = '[REDACTED]';

const PATTERNS: RegExp[] = [
  /-----BEGIN [A-Z ]*PRIVATE KEY-----[\s\S]*?-----END [A-Z ]*PRIVATE KEY-----/g,
  /\bAKIA[0-9A-Z]{16}\b/g, // AWS access key id
  /\bgh[pousr]_[A-Za-z0-9]{36,}\b/g, // GitHub tokens
  /\bgithub_pat_[A-Za-z0-9_]{40,}\b/g,
  /\bxox[abprs]-[A-Za-z0-9-]{10,}\b/g, // Slack
  /\b[rs]k_(live|test)_[A-Za-z0-9]{16,}\b/g, // Stripe
  /\bsk-[A-Za-z0-9_-]{20,}\b/g, // OpenAI / Anthropic style keys
  /\beyJ[A-Za-z0-9_-]{8,}\.eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b/g, // JWT
  /\b\d+\|[A-Za-z0-9]{40,}\b/g, // Laravel Sanctum token
  /\b[a-z][a-z0-9+.-]*:\/\/[^\s:@/]+:[^\s@/]+@/gi, // credentials in URLs (scheme://user:pass@)
];

// key = value / key: value where the key looks secret. Keeps the key, masks the value.
const KEY_VALUE = /\b([A-Za-z0-9_.-]*(?:password|passwd|pwd|secret|token|api[_-]?key|access[_-]?key|private[_-]?key|client[_-]?secret|credentials?)[A-Za-z0-9_.-]*)(\s*[:=]\s*)("[^"\n]*"|'[^'\n]*'|[^\s,;]+)/gi;

const AUTH_HEADER = /\b(Authorization:\s*(?:Bearer|Basic|Token)\s+)[^\s"']+/gi;

export interface RedactionResult<T> {
  value: T;
  /** JSON-path-like locations where something was masked. */
  redacted: string[];
}

export function redactString(text: string, extraSecrets: string[] = []): string {
  let out = text;
  for (const secret of extraSecrets) {
    if (secret && secret.length >= 8) out = out.split(secret).join(MASK);
  }
  for (const pattern of PATTERNS) {
    out = out.replace(pattern, (match) => (match.includes('://') ? match.replace(/\/\/[^@]+@/, `//${MASK}@`) : MASK));
  }
  out = out.replace(AUTH_HEADER, `$1${MASK}`);
  out = out.replace(KEY_VALUE, (match, key: string, sep: string, value: string) =>
    value === MASK || /^\[REDACTED\]/.test(value) ? match : `${key}${sep}${MASK}`);
  return out;
}

export function redactSecrets<T>(value: T, extraSecrets: string[] = [], path = '$'): RedactionResult<T> {
  const redacted: string[] = [];

  const walk = (v: unknown, p: string): unknown => {
    if (typeof v === 'string') {
      const clean = redactString(v, extraSecrets);
      if (clean !== v) redacted.push(p);
      return clean;
    }
    if (Array.isArray(v)) return v.map((item, i) => walk(item, `${p}[${i}]`));
    if (v && typeof v === 'object') {
      return Object.fromEntries(Object.entries(v).map(([k, item]) => [k, walk(item, `${p}.${k}`)]));
    }
    return v;
  };

  return { value: walk(value, path) as T, redacted };
}

const PERSONAL: [RegExp, string][] = [
  [/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/gi, '[EMAIL]'],
  [/\b[A-Z]{1,2}\d{6}\s?\(?[0-9A]\)?(?![A-Za-z0-9])/g, '[ID NUMBER]'], // HKID
  [/\+\d{1,3}[\s-]?\d(?:[\s-]?\d){6,13}\b/g, '[PHONE]'], // international format
  [/(?<![\d.-])[2-9]\d{3}[\s-]?\d{4}(?![\d.-])/g, '[PHONE]'], // Hong Kong 8-digit numbers
];

/**
 * Masks contact details and ID numbers in feedback text (QA and client comments): emails, phone
 * numbers and HKID numbers. Unseen needs what was said, not how to reach who said it (PDPO: minimum data).
 */
export function redactPersonalData<T>(value: T, path = '$'): RedactionResult<T> {
  const redacted: string[] = [];

  const walk = (v: unknown, p: string): unknown => {
    if (typeof v === 'string') {
      const clean = PERSONAL.reduce((text, [pattern, mask]) => text.replace(pattern, mask), v);
      if (clean !== v) redacted.push(p);
      return clean;
    }
    if (Array.isArray(v)) return v.map((item, i) => walk(item, `${p}[${i}]`));
    if (v && typeof v === 'object') {
      return Object.fromEntries(Object.entries(v).map(([k, item]) => [k, walk(item, `${p}.${k}`)]));
    }
    return v;
  };

  return { value: walk(value, path) as T, redacted };
}
