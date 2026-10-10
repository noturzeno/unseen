/** Words that describe an action on a resource rather than the resource itself. */
const ACTION_SEGMENTS = new Set([
  'create', 'new', 'edit', 'show', 'update', 'delete', 'destroy', 'store', 'index', 'list',
  'archive', 'unarchive', 'restore', 'history', 'accept', 'merge', 'dismiss', 'current', 'search',
]);

/** Route words that all belong to one "Authentication" feature. */
const AUTH_SEGMENTS = new Set([
  'login', 'logout', 'register', 'signup', 'sign-up', 'signin', 'sign-in', 'password', 'forgot-password',
  'reset-password', 'verify-email', 'email-verification', 'two-factor', 'auth', 'sessions', 'session',
]);

/** Keys that are framework plumbing, not product features. */
export const IGNORED_KEYS = new Set([
  '', 'index', 'home', 'welcome', 'example', 'api', 'up', 'health', 'test', 'base', 'controller', 'model',
  'app', 'web', 'layout', 'error', 'not-found', 'cache', 'cache-lock', 'job', 'job-batch', 'failed-job',
  'migration', 'personal-access-token', 'password-reset-token', 'document', 'livewire', 'me', 'user-resource',
]);

/**
 * Final key for a candidate: anything starting with an auth word ("login", "auth-token",
 * "password-reset") is grouped under "authentication".
 */
export function canonicalKey(key: string): string {
  const first = key.split('-')[0] ?? '';
  return AUTH_SEGMENTS.has(first) || AUTH_SEGMENTS.has(key) ? 'authentication' : key;
}

const IRREGULAR: Record<string, string> = {
  criteria: 'criterion', people: 'person', children: 'child', data: 'data', media: 'media', analyses: 'analysis',
};

function singular(word: string): string {
  if (IRREGULAR[word]) return IRREGULAR[word]!;
  if (/ies$/.test(word) && word.length > 4) return word.slice(0, -3) + 'y';
  if (/(ss|us|is)$/.test(word)) return word;
  if (/(ss|x|ch|sh|us)es$/.test(word)) return word.slice(0, -2);
  if (/s$/.test(word) && word.length > 3) return word.slice(0, -1);
  return word;
}

/** "FeatureCategories", "feature_categories", "feature categories" -> "feature-category". */
export function toKey(value: string): string {
  const words = value
    .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
    .replace(/([A-Z]+)([A-Z][a-z])/g, '$1 $2')
    .toLowerCase()
    .split(/[^a-z0-9]+/)
    .filter(Boolean);
  if (words.length) words[words.length - 1] = singular(words[words.length - 1]!);
  return words.join('-').slice(0, 100);
}

/** "feature-category" -> "Feature category". */
export function humanize(key: string): string {
  const text = key.replace(/-/g, ' ');
  return text.charAt(0).toUpperCase() + text.slice(1);
}

const isParam = (s: string) => /^(\{.*\}|:.+|\[.*\]|\(.*\)|<.*>|\*.*)$/.test(s);

/**
 * Picks the resource a URL path is about: the last segment that is not a parameter,
 * an API/version prefix, or an action word. Auth-related paths map to "authentication".
 */
export function keyFromPath(path: string): string {
  const segments = path.split(/[?#]/)[0]!.split('/')
    .map((s) => s.trim().toLowerCase())
    .filter((s) => s && !isParam(s) && s !== 'api' && !/^v\d+$/.test(s) && !s.startsWith('(') && !s.startsWith('@'));

  if (segments.some((s) => AUTH_SEGMENTS.has(s))) return 'authentication';

  for (let i = segments.length - 1; i >= 0; i--) {
    const seg = segments[i]!;
    if (!ACTION_SEGMENTS.has(seg)) return toKey(seg);
  }
  return '';
}

/** "ProjectsIndex", "FeatureForm", "ProjectDashboardController" -> base resource key. */
export function keyFromClass(name: string): string {
  const base = name
    .replace(/\.(php|tsx?|jsx?|vue|svelte|py|rb)$/, '')
    .replace(/(Controller|Tests?|Spec)$/, '')
    .replace(/(Api|Ui|Web|Http|Integration|Unit|E2e)$/, '')
    .replace(/(Index|Form|Detail|Details|Show|List|Create|Edit|Page|Review|Overview|View|Component)$/, '');
  return toKey(base || name);
}
