const { getAllClientInstances, removeClient } = require('./clientManager');
const { AUTH_DIR } = require('./clientConfig');
const { sweepStaleLocksOnStartup } = require('./sessionProfileCleanup');

const WATCHDOG_INTERVAL_MS = 60_000;
const RECOVER_COOLDOWN_MS = 120_000;
const STUCK_INITIALIZING_MS = parseInt(process.env.WA_STUCK_INITIALIZING_MS || '600000', 10);
const LOCK_SWEEP_EVERY_TICKS = 10;

let watchdogTicks = 0;

function isRecoverableError(message) {
  const text = String(message || '').toLowerCase();
  return (
    text.includes('detached')
    || text.includes('target closed')
    || text.includes('session closed')
    || text.includes('browser_disconnected')
    || text.includes('cannot read properties of null')
  );
}

function isInitializingStuck(service) {
  if (!service.isInitializing) {
    return false;
  }

  const since = service._initializingSince;
  if (!since) {
    return true;
  }

  return Date.now() - since >= STUCK_INITIALIZING_MS;
}

function needsHardReset(service, verify) {
  if (isInitializingStuck(service)) {
    return true;
  }

  if (!verify) {
    return false;
  }

  if (verify.isReady && !verify.browserConnected) {
    return true;
  }

  if (Array.isArray(verify.reasoning) && verify.reasoning.includes('browser_disconnected')) {
    return true;
  }

  return isRecoverableError(verify.lastError || service.lastError);
}

async function recoverSession(service, verify, reason) {
  const tag = `[${service.sessionName}]`;
  const nowMs = Date.now();

  if (
    service._lastWatchdogRecoverAt
    && nowMs - service._lastWatchdogRecoverAt < RECOVER_COOLDOWN_MS
  ) {
    return;
  }

  service._lastWatchdogRecoverAt = nowMs;
  const hardReset = needsHardReset(service, verify);

  console.warn(
    `${tag} watchdog recover (${reason}, hardReset=${hardReset}): ${JSON.stringify(verify?.reasoning || [])}`
  );

  try {
    if (hardReset) {
      service.isInitializing = false;
      service._initializingSince = null;
      await service.destroy();
      removeClient(service.sessionName);
    }

    await service.initialize();
  } catch (err) {
    console.error(`${tag} watchdog recover failed:`, err.message);
  }
}

async function checkSession(service) {
  if (service.qrCode) {
    return;
  }

  if (service.isInitializing && !isInitializingStuck(service)) {
    return;
  }

  let verify;
  try {
    verify = await service.verify();
  } catch (err) {
    console.error(`[${service.sessionName}] watchdog verify error:`, err.message);
    return;
  }

  if (verify?.alive) {
    return;
  }

  const reason = isInitializingStuck(service) ? 'stuck_initializing' : 'not_alive';
  await recoverSession(service, verify, reason);
}

async function maybeRecoverFromSyncFailure(service, message, reason) {
  if (!isRecoverableError(message)) {
    return;
  }

  const verify = await service.verify();
  if (verify?.alive) {
    return;
  }

  await recoverSession(service, verify, reason);
}

function maybeSweepStaleLocks() {
  watchdogTicks += 1;
  if (watchdogTicks % LOCK_SWEEP_EVERY_TICKS !== 0) {
    return;
  }

  try {
    sweepStaleLocksOnStartup(AUTH_DIR);
  } catch (err) {
    console.error('[WATCHDOG] periodic lock sweep failed:', err.message);
  }
}

function startSessionWatchdog() {
  if (global.__accelSessionWatchdogStarted) {
    return;
  }

  global.__accelSessionWatchdogStarted = true;

  setInterval(() => {
    maybeSweepStaleLocks();

    for (const service of getAllClientInstances()) {
      checkSession(service).catch((err) => {
        console.error(`[${service.sessionName}] watchdog check failed:`, err.message);
      });
    }
  }, WATCHDOG_INTERVAL_MS);

  console.log(
    `[WATCHDOG] session health check every ${WATCHDOG_INTERVAL_MS / 1000}s, `
    + `stuck init threshold ${STUCK_INITIALIZING_MS / 1000}s, `
    + `lock sweep every ${(WATCHDOG_INTERVAL_MS * LOCK_SWEEP_EVERY_TICKS) / 1000}s`
  );
}

module.exports = {
  startSessionWatchdog,
  needsHardReset,
  isRecoverableError,
  isInitializingStuck,
  maybeRecoverFromSyncFailure,
};
