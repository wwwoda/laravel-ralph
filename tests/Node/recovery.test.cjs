'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { TERMINAL, transition, write, read, lock } = require('../../scripts/lib/state.cjs');
const { argumentsFor, consume } = require('../../scripts/lib/agents.cjs');
const { quotaReset } = require('../../scripts/lib/codex.cjs');
const config = { marker: '<promise>COMPLETE</promise>', iterations: 3, maxFailures: 3, interval: 1200 };
const state = { status: 'running', attempts: 1, failures: 0, session_id: 'original' };

test('quota pause preserves attempts and session across a persisted restart', () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'ralph-state-'));
  try {
    const file = path.join(directory, 'state.json');
    const next = transition(state, { kind: 'quota', sessionId: 'thread-id', retryAt: 18000000 }, config, 1000);
    write(file, next);
    const restored = read(file);
    assert.equal(restored.status, 'waiting_for_quota');
    assert.equal(restored.attempts, 1);
    assert.equal(restored.failures, 0);
    assert.equal(restored.session_id, 'thread-id');
    assert.equal(restored.next_attempt_at, 18000000);
    assert.equal(transition(restored, { success: true, final: config.marker }, config, 18000001).status, 'complete');
  } finally { fs.rmSync(directory, { recursive: true }); }
});

test('weekly exhaustion prevents resuming at the short window reset', () => {
  assert.equal(quotaReset({ rateLimits: { primary: { usedPercent: 100, resetsAt: 200 }, secondary: { usedPercent: 100, resetsAt: 600 } } }, 1000), 630000);
  assert.equal(quotaReset({ rateLimits: { primary: { usedPercent: 20, resetsAt: 200 } } }, 1000), null);
});

test('unknown reset uses bounded backoff and never consumes work attempts', () => {
  const next = transition({ ...state, waits: 200 }, { kind: 'quota' }, config, 1000);
  assert.equal(next.next_attempt_at, 18001000);
  assert.equal(next.attempts, state.attempts);
});

test('auth failure blocks instead of retrying', () => {
  const next = transition(state, { kind: 'auth' }, config, 1000);
  assert.equal(next.status, 'blocked_auth');
  assert.equal(next.next_attempt_at, null);
});

test('the blocked marker ends the run without retries, distinct from completion', () => {
  const blocked = transition(state, { success: true, final: 'Need a decision.\n<promise>BLOCKED</promise>' }, { ...config, blockedMarker: '<promise>BLOCKED</promise>' }, 1000);
  assert.equal(blocked.status, 'blocked_work');
  assert.equal(blocked.next_attempt_at, null);
  assert.ok(TERMINAL.includes('blocked_work'));
  assert.equal(transition(state, { success: true, final: '<promise>BLOCKED</promise>' }, config, 1000).status, 'waiting');
});

test('a marker in a failed turn is not completion', () => {
  assert.notEqual(transition(state, { success: false, final: config.marker, kind: 'work' }, config, 1000).status, 'complete');
});

test('supervisor schedules another turn after success without finishing', () => {
  const next = transition(state, { success: true, final: 'Waiting for CI' }, { ...config, supervisor: true }, 1000);
  assert.equal(next.next_attempt_at, 1201000);
  assert.equal(next.status, 'waiting');
});

test('Codex starts and resumes explicit threads with model and effort', () => {
  const selected = { agent: 'codex', model: 'model-id', effort: 'high', permissionMode: 'auto' };
  const first = argumentsFor(selected, 'prompt');
  const resumed = argumentsFor(selected, 'continue', 'thread-42');
  assert.equal(first.command, 'codex');
  assert.ok(first.args.includes('--approve-for-me'));
  assert.deepEqual(resumed.args.slice(0, 4), ['exec', '--approve-for-me', 'resume', 'thread-42']);
  assert.ok(resumed.args.includes('model-id'));
  assert.ok(resumed.args.includes('model_reasoning_effort="high"'));
  assert.ok(!resumed.args.includes('--last'));
});

test('Claude keeps its own command syntax', () => {
  const invocation = argumentsFor({ agent: 'claude', model: 'opus', effort: 'high', permissionMode: 'auto' }, 'prompt', 'session');
  assert.equal(invocation.command, 'claude');
  assert.ok(invocation.args.includes('--resume'));
  assert.ok(invocation.args.includes('--effort'));
  assert.ok(!invocation.args.includes('--json'));
});

test('Codex tool output cannot signal completion', () => {
  const result = { final: '', success: false };
  consume(result, { type: 'item.completed', item: { type: 'command_execution', aggregated_output: config.marker } }, 'codex');
  assert.equal(result.final, '');
  consume(result, { type: 'thread.started', thread_id: 'thread-1' }, 'codex');
  assert.equal(result.sessionId, 'thread-1');
});

test('Claude rejection carries its recorded reset time', () => {
  const result = {};
  consume(result, { type: 'rate_limit_event', rate_limit_info: { status: 'rejected', resetsAt: 12345 } }, 'claude');
  assert.equal(result.kind, 'quota');
  assert.equal(result.retryAt, 12345000);
});

test('one runtime owner at a time', () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'ralph-lock-'));
  try {
    const file = path.join(directory, 'state');
    const release = lock(file);
    assert.throws(() => lock(file), /already has an owner/);
    release();
    lock(file)();
  } finally { fs.rmSync(directory, { recursive: true }); }
});
