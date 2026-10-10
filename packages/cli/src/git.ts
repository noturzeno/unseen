import { execFileSync } from 'node:child_process';

export interface GitInfo {
  branch: string | null;
  commit: string | null;
}

function git(cwd: string, args: string[]): string | null {
  try {
    return execFileSync('git', args, { cwd, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'], timeout: 5000 }).trim() || null;
  } catch {
    return null;
  }
}

/**
 * True when the working tree has changes not in HEAD (tracked or untracked), ignoring
 * the given paths (e.g. the JUnit report files the test run just wrote). Null outside git.
 */
export function hasUncommittedChanges(cwd: string, ignorePaths: string[] = []): boolean | null {
  const status = git(cwd, ['status', '--porcelain']);
  if (status === null) return git(cwd, ['rev-parse', '--git-dir']) === null ? null : false;
  const ignored = new Set(ignorePaths);
  return status.split('\n').some((line) => {
    const path = line.slice(3).replace(/^"|"$/g, '').split(' -> ').pop()!;
    return line.trim() !== '' && !ignored.has(path);
  });
}

/** Current branch and HEAD commit, or nulls outside a git repository. */
export function gitInfo(cwd: string): GitInfo {
  const branch = git(cwd, ['rev-parse', '--abbrev-ref', 'HEAD']);
  return {
    branch: branch === 'HEAD' ? null : branch, // detached HEAD
    commit: git(cwd, ['rev-parse', 'HEAD']),
  };
}
