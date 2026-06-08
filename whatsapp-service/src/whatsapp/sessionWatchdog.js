const { getAllClientInstances, removeClient } = require('./clientManager');

const WATCHDOG_INTERVAL_MS = 60_000;
const RECOVER_COOLDOWN_MS = 120_000;

function isRecoverableError(message) {
  const text = String(message || '').toLowerCase();
  return (
    text.includes('detached')
    || text.includes('target closed')
    || text.includes('session closed')
    || text.includes('browser_disconnected')
  );
}

function needsHardReset(service, verify) {
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
      await service.destroy();
      removeClient(service.sessionName);
    }

    await service.initialize();
  } catch (err) {
    console.error(`${tag} watchdog recover failed:`, err.message);
  }
}

async function checkSession(service) {
  if (service.isInitializing || service.qrCode) {
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

  await recoverSession(service, verify, 'not_alive');
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

function startSessionWatchdog() {
  if (global.__accelSessionWatchdogStarted) {
    return;
  }

  global.__accelSessionWatchdogStarted = true;

  setInterval(() => {
    for (const service of getAllClientInstances()) {
      checkSession(service).catch((err) => {
        console.error(`[${service.sessionName}] watchdog check failed:`, err.message);
      });
    }
  }, WATCHDOG_INTERVAL_MS);

  console.log(`[WATCHDOG] session health check every ${WATCHDOG_INTERVAL_MS / 1000}s`);
}

module.exports = {
  startSessionWatchdog,
  needsHardReset,
  isRecoverableError,
  maybeRecoverFromSyncFailure,
};
