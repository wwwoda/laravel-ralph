#!/usr/bin/env node
'use strict';
const codex = require('./lib/codex.cjs');
async function main() {
  const operation = process.argv[2];
  if (operation === 'models') console.log(JSON.stringify(await codex.models(), null, 2));
  else if (operation === 'quota') {
    const result = await codex.request('account/rateLimits/read');
    console.log(JSON.stringify({ next_attempt_at: codex.quotaReset(result) }));
  } else {
    console.log('Usage: node scripts/ralph-metadata.cjs models|quota\nRead Codex model/effort choices or next quota reset. No credentials or account details are printed.');
    if (operation !== '--help') process.exitCode = 1;
  }
}
main().catch(() => { console.error('Codex metadata unavailable. Check CLI login.'); process.exitCode = 1; });
