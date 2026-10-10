import { createInterface } from 'node:readline/promises';
import { stdin, stdout } from 'node:process';

export function isInteractive(): boolean {
  return Boolean(stdin.isTTY && stdout.isTTY);
}

export async function ask(question: string, fallback?: string): Promise<string> {
  const rl = createInterface({ input: stdin, output: stdout });
  try {
    const answer = (await rl.question(fallback ? `${question} [${fallback}]: ` : `${question}: `)).trim();
    return answer || fallback || '';
  } finally {
    rl.close();
  }
}

/** Reads a line without echoing it (for passwords). */
export async function askHidden(question: string): Promise<string> {
  stdout.write(`${question}: `);
  stdin.setRawMode?.(true);
  stdin.resume();
  stdin.setEncoding('utf8');

  return new Promise((resolve, reject) => {
    let value = '';
    const onData = (chunk: string) => {
      for (const char of chunk) {
        if (char === '\r' || char === '\n') {
          cleanup();
          stdout.write('\n');
          resolve(value);
          return;
        }
        if (char === '\u0003') { // Ctrl+C
          cleanup();
          stdout.write('\n');
          reject(new Error('Cancelled.'));
          return;
        }
        if (char === '\u007f' || char === '\b') value = value.slice(0, -1);
        else value += char;
      }
    };
    const cleanup = () => {
      stdin.off('data', onData);
      stdin.setRawMode?.(false);
      stdin.pause();
    };
    stdin.on('data', onData);
  });
}
