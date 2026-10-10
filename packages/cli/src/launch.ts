import { spawn } from 'node:child_process';
import { chmodSync, existsSync, mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

export interface LaunchRequest {
  folder: string;
  prompt: string;
  title: string;
  /** The Claude Code command, e.g. "claude". */
  claude: string;
}

/** Opens Claude Code with the prompt in a new terminal window, or explains why it couldn't. */
export type Launcher = (request: LaunchRequest) => Promise<{ ok: true; how: string } | { ok: false; error: string }>;

/** Single-quotes a value for bash. */
export const shellQuote = (value: string) => `'${value.replace(/'/g, `'\\''`)}'`;

/**
 * Writes the prompt to a private file and a small script that cds into the folder, reads the prompt,
 * deletes both files, and starts Claude Code with it. Returns the script path.
 */
export function writeLaunchScript(request: LaunchRequest): string {
  const dir = mkdtempSync(join(tmpdir(), 'unseen-run-'));
  chmodSync(dir, 0o700);
  const promptFile = join(dir, 'prompt.txt');
  const script = join(dir, 'run.sh');
  writeFileSync(promptFile, request.prompt, { mode: 0o600 });
  writeFileSync(script, [
    '#!/usr/bin/env bash',
    `cd -- ${shellQuote(request.folder)} || { echo "Unseen: can't open the project folder."; exit 1; }`,
    // Load the folder's own environment the way the user's shell would (e.g. a direnv .envrc that sets
    // CLAUDE_CONFIG_DIR to pick the Claude account). direnv only applies .envrc files the user allowed.
    // Forget direnv state inherited from wherever the runner started, so this folder's .envrc is applied fresh.
    'if command -v direnv >/dev/null 2>&1; then unset DIRENV_DIR DIRENV_FILE DIRENV_DIFF DIRENV_WATCHES; eval "$(direnv export bash 2>/dev/null)"; fi',
    `PROMPT="$(cat -- ${shellQuote(promptFile)})"`,
    `rm -rf -- ${shellQuote(dir)}`,
    `printf '%s\\n' ${shellQuote(`Unseen: ${request.title.replace(/[\r\n]+/g, ' ')}`)}`,
    'printf \'Claude account: %s\\n\\n\' "${CLAUDE_CONFIG_DIR:-default (~/.claude)}"',
    `exec ${shellQuote(request.claude)} "$PROMPT"`,
    '',
  ].join('\n'), { mode: 0o700 });
  return script;
}

function run(command: string, args: string[]): Promise<boolean> {
  return new Promise((resolve) => {
    const child = spawn(command, args, { stdio: 'ignore', detached: true });
    child.on('error', () => resolve(false));
    child.on('spawn', () => { child.unref(); resolve(true); });
  });
}

/** Runs a bash script in a new terminal window. macOS: Terminal. Linux: the first terminal emulator found. */
export async function runInTerminal(script: string): Promise<{ ok: true; how: string } | { ok: false; error: string }> {
  if (process.platform === 'darwin') {
    const command = `bash ${shellQuote(script)}`.replace(/\\/g, '\\\\').replace(/"/g, '\\"');
    const ok = await run('osascript', ['-e', `tell application "Terminal" to do script "${command}"`, '-e', 'tell application "Terminal" to activate']);
    return ok ? { ok: true, how: 'a new Terminal window' } : { ok: false, error: 'Could not start Terminal (osascript failed).' };
  }

  if (process.platform === 'linux') {
    const terminals: [string, string[]][] = [
      ['x-terminal-emulator', ['-e', 'bash', script]],
      ['gnome-terminal', ['--', 'bash', script]],
      ['konsole', ['-e', 'bash', script]],
      ['xfce4-terminal', ['-x', 'bash', script]],
      ['xterm', ['-e', 'bash', script]],
    ];
    for (const [cmd, args] of terminals) {
      if (await run(cmd, args)) return { ok: true, how: `a new ${cmd} window` };
    }
    return { ok: false, error: `No terminal program found. Run it yourself: bash ${script}` };
  }

  return { ok: false, error: `Opening a terminal isn't supported on ${process.platform}. Run it yourself: bash ${script}` };
}

/** Opens Claude Code with the prompt in a new terminal window. */
export const openInTerminal: Launcher = async (request) => {
  if (!existsSync(request.folder)) return { ok: false, error: `Folder not found: ${request.folder}` };
  return runInTerminal(writeLaunchScript(request));
};

/** --print: don't open anything, show the command to run. */
export const printOnly = (out: (text: string) => void): Launcher => async (request) => {
  if (!existsSync(request.folder)) return { ok: false, error: `Folder not found: ${request.folder}` };
  const script = writeLaunchScript(request);
  out(`  Run it with: bash ${shellQuote(script)}`);
  return { ok: true, how: 'a printed command' };
};
