#!/usr/bin/env node
'use strict';
// Bounded login + explicit-session probe. Prints pass/fail metadata, never raw agent output.
const crypto = require('node:crypto');
const { runAgent } = require('./lib/agents.cjs');
const { read, write } = require('./lib/state.cjs');
const { parseArgs } = require('./ralph-loop.cjs');
async function main() {
  const config = parseArgs();
  if (config.help || !config['resume-file'] || !config.model || !config.effort) {
    console.log('Usage: node scripts/ralph-smoke.cjs --agent claude|codex --model MODEL --effort LEVEL --resume-file FILE\nRun twice, optionally recreating the container between calls. First saves a session; second verifies memory. No files or tools requested from the agent.');
    return config.help ? 0 : 1;
  }
  const file = config['resume-file'];
  const saved = read(file, null);
  const nonce = saved?.nonce || crypto.randomBytes(8).toString('hex');
  if (saved && saved.agent !== config.agent) throw new Error('Probe agent mismatch');
  const prompt = saved
    ? 'Reply with only the probe word from our previous message. Do not call tools or read files.'
    : `Remember this probe word: ${nonce}. Reply with exactly AUTH_OK. Do not call tools or read files.`;
  config.permissionMode = require('node:fs').existsSync('/.dockerenv') ? 'bypassPermissions' : 'auto';
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), 90000);
  try {
    const result = await runAgent(config, prompt, saved?.session_id, () => {}, controller.signal);
    const passed = result.success && result.final.trim() === (saved ? nonce : 'AUTH_OK');
    if (passed && !saved) write(file, { agent: config.agent, session_id: result.sessionId, nonce });
    console.log(JSON.stringify({ agent: config.agent, check: saved ? 'resume' : 'login', passed, kind: passed ? null : result.kind }));
    return passed ? 0 : 1;
  } finally { clearTimeout(timer); }
}
main().then(code => { process.exitCode = code; }).catch(() => { console.error('Probe failed; raw output suppressed.'); process.exitCode = 1; });
