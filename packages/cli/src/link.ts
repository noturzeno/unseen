import { execFile } from 'node:child_process';
import { chmodSync, existsSync, mkdirSync, mkdtempSync, readFileSync, realpathSync, rmSync, statSync, unlinkSync, writeFileSync } from 'node:fs';
import { homedir, tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { credentialsPath, PROJECT_FILE } from './config.ts';
import type { Context } from './context.ts';
import { runInTerminal, shellQuote } from './launch.ts';

/**
 * "Start runner" buttons in Unseen are unseen://runner links. The CLI registers itself as the
 * handler for them (macOS: a tiny background app; Linux: a .desktop entry), and `unseen open-link`
 * starts the runner in a new terminal window. Any web page can open such a link, so it can only ever
 * start the runner: no other commands, and the folder is used only if it is linked to Unseen.
 */

const SCHEME = 'unseen';
const MAC_APP = join(homedir(), 'Applications', 'Unseen Runner.app');
const LINUX_DESKTOP = 'unseen-link.desktop';

type Exec = (cmd: string, args: string[]) => Promise<boolean>;

const exec: Exec = (cmd, args) => new Promise((done) => execFile(cmd, args, (error) => done(!error)));

/** What the link asks for: only "start the runner", optionally in a folder. */
export function parseRunnerLink(url: string): { folder: string | null } {
  let parsed: URL;
  try {
    parsed = new URL(url);
  } catch {
    throw new Error('Not an Unseen link.');
  }
  if (parsed.protocol !== `${SCHEME}:` || parsed.hostname !== 'runner') throw new Error('Not an Unseen runner link.');

  const folder = parsed.searchParams.get('folder');
  if (folder !== null && !/^(\/|~\/)[^\r\n\0]*$/.test(folder)) throw new Error('The folder in the link is not a full path.');
  return { folder };
}

/** The runner's pid file, next to credentials.json. */
export function runnerPidPath(env: Context['env']): string {
  return join(dirname(credentialsPath(env)), 'runner.pid');
}

export function runnerIsRunning(env: Context['env']): boolean {
  const path = runnerPidPath(env);
  if (!existsSync(path)) return false;
  const pid = Number(readFileSync(path, 'utf8').trim());
  if (!Number.isInteger(pid) || pid <= 0) return false;
  try {
    process.kill(pid, 0);
    return true;
  } catch {
    return false;
  }
}

/** Records this process as the running runner; returns a cleanup function. */
export function markRunnerRunning(env: Context['env']): () => void {
  const path = runnerPidPath(env);
  mkdirSync(dirname(path), { recursive: true, mode: 0o700 });
  writeFileSync(path, String(process.pid), { mode: 0o600 });
  const cleanup = () => {
    try {
      if (readFileSync(path, 'utf8').trim() === String(process.pid)) unlinkSync(path);
    } catch { /* already gone */ }
  };
  process.once('exit', cleanup);
  return cleanup;
}

/** A folder the link may open: it exists and is linked to Unseen. Anything else falls back to home. */
function linkedFolder(folder: string | null): string | null {
  if (!folder) return null;
  const path = resolve(folder.startsWith('~/') ? join(homedir(), folder.slice(2)) : folder);
  try {
    if (!statSync(path).isDirectory() || !existsSync(join(path, PROJECT_FILE))) return null;
    return realpathSync(path);
  } catch {
    return null;
  }
}

export interface OpenLinkDeps {
  terminal?: (script: string) => Promise<{ ok: true; how: string } | { ok: false; error: string }>;
}

/** `unseen open-link <url>`: what the browser runs when a "Start runner" button is clicked. */
export async function openLink(url: string, ctx: Context, deps: OpenLinkDeps = {}): Promise<number> {
  const { folder } = parseRunnerLink(url);
  if (runnerIsRunning(ctx.env)) {
    ctx.out('The Unseen runner is already running on this computer.');
    return 0;
  }

  const cwd = linkedFolder(folder) ?? homedir();
  const dir = mkdtempSync(join(tmpdir(), 'unseen-runner-'));
  chmodSync(dir, 0o700);
  const script = join(dir, 'runner.sh');
  writeFileSync(script, [
    '#!/usr/bin/env bash',
    `rm -rf -- ${shellQuote(dir)}`,
    `cd -- ${shellQuote(cwd)} || exit 1`,
    `exec ${shellQuote(nodePath())} ${shellQuote(cliPath())} runner`,
    '',
  ].join('\n'), { mode: 0o700 });

  const result = await (deps.terminal ?? runInTerminal)(script);
  if (!result.ok) {
    rmSync(dir, { recursive: true, force: true });
    ctx.err(result.error);
    return 1;
  }
  ctx.out(`Started the Unseen runner in ${result.how}.`);
  return 0;
}

/** Node, preferring a stable link (e.g. Homebrew's /opt/homebrew/bin/node) over a versioned path that upgrades remove. */
function nodePath(): string {
  for (const candidate of ['/opt/homebrew/bin/node', '/usr/local/bin/node', '/usr/bin/node']) {
    try {
      if (realpathSync(candidate) === realpathSync(process.execPath)) return candidate;
    } catch { /* not there */ }
  }
  return process.execPath;
}

function cliPath(): string {
  return realpathSync(process.argv[1]!);
}

const appleScriptString = (value: string) => `"${value.replace(/\\/g, '\\\\').replace(/"/g, '\\"')}"`;

/**
 * Registers this CLI as the handler for unseen:// links, so "Start runner" buttons work. Safe to run
 * repeatedly: it does nothing when the handler already points at this CLI.
 */
export async function installLinkHandler(run: Exec = exec): Promise<{ ok: true; changed: boolean } | { ok: false; error: string }> {
  const node = nodePath();
  const cli = cliPath();
  const stamp = JSON.stringify({ node, cli });

  if (process.platform === 'darwin') {
    const stampFile = join(MAC_APP, 'Contents', 'Resources', 'unseen-link.json');
    if (existsSync(stampFile) && readFileSync(stampFile, 'utf8') === stamp) return { ok: true, changed: false };

    const command = `${appleScriptString(shellQuote(node) + ' ' + shellQuote(cli) + ' open-link ')} & quoted form of theURL & " > /dev/null 2>&1 &"`;
    rmSync(MAC_APP, { recursive: true, force: true });
    mkdirSync(dirname(MAC_APP), { recursive: true });
    const plist = join(MAC_APP, 'Contents', 'Info.plist');
    const steps: [string, string[]][] = [
      ['osacompile', ['-o', MAC_APP, '-e', 'on open location theURL', '-e', `do shell script ${command}`, '-e', 'end open location']],
      ['plutil', ['-replace', 'CFBundleIdentifier', '-string', 'dev.unseen.runner-link', plist]],
      ['plutil', ['-replace', 'LSUIElement', '-bool', 'YES', plist]],
      ['plutil', ['-replace', 'CFBundleURLTypes', '-json', JSON.stringify([{ CFBundleURLName: 'Unseen runner', CFBundleURLSchemes: [SCHEME] }]), plist]],
      ['/System/Library/Frameworks/CoreServices.framework/Frameworks/LaunchServices.framework/Support/lsregister', ['-f', MAC_APP]],
    ];
    for (const [cmd, args] of steps) {
      if (!(await run(cmd, args))) return { ok: false, error: `Couldn't set up the Start runner button (${cmd.split('/').pop()} failed).` };
    }
    writeFileSync(stampFile, stamp);
    return { ok: true, changed: true };
  }

  if (process.platform === 'linux') {
    const appsDir = join(process.env.XDG_DATA_HOME ?? join(homedir(), '.local', 'share'), 'applications');
    const file = join(appsDir, LINUX_DESKTOP);
    const entry = [
      '[Desktop Entry]',
      'Type=Application',
      'Name=Unseen runner',
      'NoDisplay=true',
      `Exec=${shellQuote(node)} ${shellQuote(cli)} open-link %u`,
      `MimeType=x-scheme-handler/${SCHEME};`,
      '',
    ].join('\n');
    if (existsSync(file) && readFileSync(file, 'utf8') === entry) return { ok: true, changed: false };
    mkdirSync(appsDir, { recursive: true });
    writeFileSync(file, entry);
    if (!(await run('xdg-mime', ['default', LINUX_DESKTOP, `x-scheme-handler/${SCHEME}`]))) {
      return { ok: false, error: "Couldn't set up the Start runner button (xdg-mime failed)." };
    }
    return { ok: true, changed: true };
  }

  return { ok: false, error: `The Start runner button isn't supported on ${process.platform}; start "unseen runner" in a terminal.` };
}
