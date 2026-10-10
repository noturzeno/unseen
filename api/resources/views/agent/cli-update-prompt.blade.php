Update the Unseen CLI on this computer to version {!! $latest !!}. Don't change anything in the repository.

1. Reinstall it from the Unseen server (needs Node.js 22.18 or newer):

   {!! $command !!}

2. Check that `unseen --version` prints {!! $latest !!}, and that `unseen status` still works (the saved token and the repository link are kept).
3. If `unseen runner` is running in a terminal, tell me to restart it (Ctrl+C, then `unseen runner`) so it uses the new version.
4. Read the "Unseen" section of CLAUDE.md (or AGENTS.md) again: newer versions accept more report fields, such as `prompt`.
5. Tell me the old and new version.
