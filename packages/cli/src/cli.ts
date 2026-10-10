#!/usr/bin/env node
import { main } from './main.ts';
import { isInteractive } from './prompt.ts';

async function readAll(stream: NodeJS.ReadableStream): Promise<string> {
  let text = '';
  for await (const chunk of stream) text += chunk.toString();
  return text;
}

main(process.argv.slice(2), {
  cwd: process.cwd(),
  env: process.env,
  out: (t) => process.stdout.write(t + '\n'),
  err: (t) => process.stderr.write(t + '\n'),
  fetch,
  interactive: isInteractive(),
  readStdin: () => readAll(process.stdin),
}).then((code) => {
  process.exitCode = code;
});
