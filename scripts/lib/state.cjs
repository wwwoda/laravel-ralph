'use strict';

const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');

// Statuses that stop the loop; the watcher never restarts them.
const TERMINAL = ['complete', 'failed', 'blocked_auth', 'blocked_config', 'blocked_work', 'cancelled'];

function write(file, value) {
  fs.mkdirSync(path.dirname(file), { recursive: true });
  const temp = `${file}.${process.pid}.tmp`;
  fs.writeFileSync(temp, JSON.stringify(value, null, 2) + '\n', { mode: 0o600 });
  fs.renameSync(temp, file);
}
function read(file, fallback) {
  try { return JSON.parse(fs.readFileSync(file, 'utf8')); }
  catch (error) { if (error.code === 'ENOENT') return fallback; throw error; }
}
function lock(file) {
  const target = file + '.lock';
  fs.mkdirSync(path.dirname(target), { recursive: true });
  for (let attempt = 0; attempt < 2; attempt++) {
    try {
      const fd = fs.openSync(target, 'wx', 0o600);
      fs.writeFileSync(fd, JSON.stringify({ pid: process.pid, host: os.hostname() }));
      fs.closeSync(fd);
      return () => fs.unlinkSync(target);
    } catch (error) {
      if (error.code !== 'EEXIST') throw error;
      const owner = read(target, null);
      if (!owner) continue;
      let alive = owner.host === os.hostname();
      if (alive) {
        try { process.kill(owner.pid, 0); } catch (e) { alive = e.code !== 'ESRCH'; }
      }
      if (alive) throw new Error('Ralph session already has an owner');
      fs.unlinkSync(target);
    }
  }
  throw new Error('Cannot acquire Ralph session lock');
}
function transition(state, result, config, now) {
  const next = { ...state, session_id: result.sessionId || state.session_id, updated_at: now };
  // Markers count only in a successful final message, never in tool output or a failed turn.
  const last = result.success ? (result.final || '').trim().split('\n').at(-1).trim() : null;
  if (last === config.marker) return { ...next, status: 'complete', next_attempt_at: null };
  if (config.blockedMarker && last === config.blockedMarker) return { ...next, status: 'blocked_work', next_attempt_at: null };
  if (result.kind === 'auth' && !result.success) return { ...next, status: 'blocked_auth', next_attempt_at: null };
  if (!result.success && ['quota', 'network'].includes(result.kind)) {
    const waits = (state.waits || 0) + 1;
    return { ...next, status: result.kind === 'quota' ? 'waiting_for_quota' : 'waiting_for_network', waits,
      next_attempt_at: Math.max(now + 30000, result.retryAt || now + Math.min(900000 * 2 ** Math.min(waits - 1, 5), 18000000)) };
  }
  const attempts = state.attempts + 1;
  const failures = result.success ? 0 : state.failures + 1;
  const exhausted = failures >= config.maxFailures || (!config.supervisor && attempts >= config.iterations);
  return { ...next, attempts, failures, waits: 0, status: exhausted ? 'failed' : 'waiting',
    session_id: config.fresh ? null : next.session_id,
    next_attempt_at: exhausted ? null : now + (config.supervisor ? config.interval * 1000 : 1000) };
}
module.exports = { TERMINAL, write, read, lock, transition };
