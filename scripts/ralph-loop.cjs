#!/usr/bin/env node
'use strict';

// Durable Claude/Codex loop. See README: Agent execution and recovery.
const fs = require('node:fs');
const path = require('node:path');
const { runAgent } = require('./lib/agents.cjs');
const { TERMINAL, write, read, lock, transition } = require('./lib/state.cjs');
const codex = require('./lib/codex.cjs');

function parseArgs(args = process.argv.slice(2)) {
  const values = {};
  const flags = new Set(['fresh', 'resume', 'supervisor', 'help']);
  for (let i = 0; i < args.length; i++) {
    const name = args[i].replace(/^--/, '');
    values[name] = flags.has(name) ? true : args[++i];
  }
  return {
    ...values, agent: values.agent || 'claude', permissionMode: values['permission-mode'] || 'acceptEdits',
    iterations: Number(values.iterations || 30), maxFailures: Number(process.env.AGENT_MAX_CONSECUTIVE_FAILURES || 3),
    interval: Number(values.interval || 1200), statePath: values['state-path'], logPath: values['log-path'],
    marker: process.env.AGENT_COMPLETION_MARKER || '<promise>COMPLETE</promise>',
    blockedMarker: process.env.AGENT_BLOCKED_MARKER || '<promise>BLOCKED</promise>',
  };
}

async function main(config = parseArgs()) {
  if (config.help) {
    console.log('Usage: node ralph-loop.cjs --prompt FILE --name NAME [--agent claude|codex] [--model MODEL] [--effort LEVEL] [--state-path FILE] [--resume] [--fresh] [--supervisor --interval SECONDS]\nExit: 0 complete, 1 failed/auth/config, 3 cancelled, 4 blocked on a human. Quota waits preserve work attempts. Never put credentials in prompts.');
    return 0;
  }
  if (!['claude', 'codex'].includes(config.agent)) throw new Error('Unsupported agent');
  if (!config.prompt || !fs.statSync(config.prompt).isFile()) throw new Error('Prompt must be an existing file');
  if (!Number.isInteger(config.iterations) || config.iterations < 1 || !Number.isFinite(config.interval) || config.interval < 1) throw new Error('Invalid iteration or interval setting');
  if (config.permissionMode === 'bypassPermissions' && !fs.existsSync('/.dockerenv')) throw new Error('Full permissions require a container');
  const statePath = config.statePath || path.join('storage/ralph-logs', config.name || 'ralph', 'state.json');
  const unlock = lock(statePath);
  const abort = new AbortController();
  const stop = () => abort.abort();
  process.once('SIGTERM', stop);
  process.once('SIGINT', stop);
  const log = value => {
    const entry = JSON.stringify({ at: new Date().toISOString(), ...value });
    console.log(entry);
    if (config.logPath) fs.appendFileSync(config.logPath, entry + '\n');
  };
  let state = read(statePath, { status: 'waiting', attempts: 0, failures: 0, session_id: null, next_attempt_at: null });
  try {
    if (config.agent === 'codex') {
      let menu;
      try {
        menu = await codex.models();
      } catch {
        state.status = 'waiting_for_network';
        state.next_attempt_at = Date.now() + 900000;
        write(statePath, state);
        log({ status: state.status, reason: 'model_metadata_unavailable' });
        return 1;
      }
      if ((config.model && !menu.some(model => model.model === config.model && (!config.effort || model.efforts.includes(config.effort)))) || config.budget) {
        state.status = 'blocked_config';
        write(statePath, state);
        log({ status: state.status, reason: 'unsupported_model_effort_or_budget' });
        return 1;
      }
    }
    while (!abort.signal.aborted) {
      if (fs.existsSync(statePath + '.cancel')) return 3;
      if (TERMINAL.includes(state.status)) return { complete: 0, cancelled: 3, blocked_work: 4 }[state.status] ?? 1;
      if (state.next_attempt_at > Date.now()) {
        await new Promise(resolve => setTimeout(resolve, Math.min(1000, state.next_attempt_at - Date.now())));
        continue;
      }
      if (config.agent === 'codex' && state.status === 'waiting_for_quota') {
        try {
          const next = codex.quotaReset(await codex.request('account/rateLimits/read'));
          if (next) { state.next_attempt_at = next; write(statePath, state); continue; }
        } catch {
          // Inference remains the final check when account metadata is unavailable.
        }
      }
      const basePrompt = fs.readFileSync(config.prompt, 'utf8').trim();
      const prompt = [basePrompt, process.env.AGENT_PROMPT_SUFFIX || '', state.session_id ? (process.env.AGENT_CONTINUATION_PROMPT || 'Continue from the saved work. Preserve existing changes.') : ''].filter(Boolean).join('\n\n');
      state.status = 'running';
      write(statePath, state);
      log({ status: state.status, agent: config.agent, model: config.model || 'default', effort: config.effort || 'default', attempts: state.attempts });
      let result;
      try {
        result = await runAgent(config, prompt, state.session_id, (event, current) => {
          if (current.sessionId && /^[a-zA-Z0-9_-]+$/.test(current.sessionId)) {
            state.session_id = current.sessionId;
            write(statePath, state);
          }
          // Raw tool output may contain credentials. Persist only lifecycle metadata.
          if (['result', 'turn.completed', 'turn.failed'].includes(event.type)) log({ event: event.type });
        }, abort.signal);
      } catch {
        if (abort.signal.aborted) break;
        result = { success: false, kind: 'network', final: '', sessionId: state.session_id };
      }
      state = transition(state, result, config, Date.now());
      write(statePath, state);
      log({ status: state.status, attempts: state.attempts, next_attempt_at: state.next_attempt_at });
    }
    return 3;
  } finally {
    unlock();
    process.removeListener('SIGTERM', stop);
    process.removeListener('SIGINT', stop);
  }
}

module.exports = { parseArgs, main };
if (require.main === module) main().then(code => { process.exitCode = code; }).catch(error => { console.error(error.message); process.exitCode = 1; });
