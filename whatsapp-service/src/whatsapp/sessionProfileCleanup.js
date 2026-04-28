const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

function sleep(ms) {
  return new Promise((r) => setTimeout(r, ms));
}

/**
 * Проверить, существует ли процесс с указанным PID.
 * @param {number} pid
 * @returns {boolean}
 */
function isPidAlive(pid) {
  if (!Number.isInteger(pid) || pid <= 0) return false;
  try {
    // signal 0 → проверка без отправки сигнала; бросает при отсутствии процесса/доступа
    process.kill(pid, 0);
    return true;
  } catch (e) {
    if (e.code === 'EPERM') {
      // процесс есть, но не наш — трогать нельзя
      return true;
    }
    return false;
  }
}

/**
 * Прочитать PID из Chromium SingletonLock (это symlink вида "host-PID").
 * @param {string} lockPath
 * @returns {number|null}
 */
function readSingletonLockPid(lockPath) {
  try {
    const stat = fs.lstatSync(lockPath);
    if (!stat.isSymbolicLink()) return null;
    const target = fs.readlinkSync(lockPath);
    const m = String(target).match(/-(\d+)$/);
    return m ? parseInt(m[1], 10) : null;
  } catch (_) {
    return null;
  }
}

function safeUnlink(p) {
  try {
    fs.rmSync(p, { force: true });
    return true;
  } catch (_) {
    return false;
  }
}

/**
 * Дождаться освобождения userDataDir после destroy и снять «залипшие» lock Chrome
 * (частая причина ошибки: The browser is already running for … session-XXX).
 *
 * Стратегия:
 *   1. Если SingletonLock — symlink на умерший PID → просто удаляем файл.
 *   2. Если PID живой → process.kill(-9); если не получилось (fuser доступен) → fuser -k.
 *   3. Очищаем побочные lock-файлы (SingletonCookie, SingletonSocket, lockfile).
 *   4. Ждём освобождение inotify/ОС.
 *
 * @param {string} authDir путь к .wwebjs_auth
 * @param {string} sessionName имя сессии (как в LocalAuth clientId)
 * @returns {Promise<{killed: boolean, hadLock: boolean}>}
 */
async function releaseStaleChromiumProfileLocks(authDir, sessionName) {
  const profileDir = path.join(authDir, `session-${sessionName}`);
  if (!fs.existsSync(profileDir)) {
    await sleep(300);
    return { killed: false, hadLock: false };
  }

  const singletonLock = path.join(profileDir, 'SingletonLock');
  const singletonCookie = path.join(profileDir, 'SingletonCookie');
  const singletonSocket = path.join(profileDir, 'SingletonSocket');
  const lockfile = path.join(profileDir, 'lockfile');

  const hadLock = fs.existsSync(singletonLock);
  let killed = false;

  if (hadLock) {
    const pid = readSingletonLockPid(singletonLock);
    if (pid !== null) {
      if (!isPidAlive(pid)) {
        console.log(`[cleanup][${sessionName}] stale SingletonLock → pid ${pid} dead, removing`);
        safeUnlink(singletonLock);
      } else {
        console.warn(`[cleanup][${sessionName}] found alive Chrome pid ${pid} on profile, killing`);
        try {
          process.kill(pid, 'SIGKILL');
          killed = true;
          await sleep(500);
        } catch (e) {
          console.error(`[cleanup][${sessionName}] process.kill(${pid}) failed:`, e.message);
        }

        if (process.platform === 'linux' && isPidAlive(pid)) {
          try {
            execSync(`fuser -k -9 "${singletonLock}" 2>/dev/null || true`, { timeout: 8000, stdio: 'ignore' });
            killed = true;
          } catch (_) { /* fuser может отсутствовать */ }
        }

        await sleep(800);
        safeUnlink(singletonLock);
      }
    } else {
      // не symlink / нечитаемый → просто удаляем
      safeUnlink(singletonLock);
    }
  }

  for (const f of [singletonCookie, singletonSocket, lockfile]) {
    if (fs.existsSync(f)) safeUnlink(f);
  }

  await sleep(600);
  return { killed, hadLock };
}

/**
 * При старте сервиса: обойти все .wwebjs_auth/session-* и снять lock от процессов,
 * которые не пережили перезапуск Node. Ничего живого не трогаем.
 *
 * @param {string} authDir
 */
function sweepStaleLocksOnStartup(authDir) {
  if (!fs.existsSync(authDir)) return;

  let dirs;
  try {
    dirs = fs.readdirSync(authDir);
  } catch (e) {
    console.error('[cleanup] failed to read auth dir:', e.message);
    return;
  }

  const sessions = dirs
    .filter((d) => d.startsWith('session-'))
    .map((d) => d.slice('session-'.length));

  for (const name of sessions) {
    const singletonLock = path.join(authDir, `session-${name}`, 'SingletonLock');
    if (!fs.existsSync(singletonLock)) continue;

    const pid = readSingletonLockPid(singletonLock);
    if (pid !== null && isPidAlive(pid)) {
      console.warn(`[cleanup][startup][${name}] pid ${pid} still alive (orphan Chrome), killing`);
      try {
        process.kill(pid, 'SIGKILL');
      } catch (e) {
        console.error(`[cleanup][startup][${name}] kill(${pid}) failed:`, e.message);
      }
    } else {
      console.log(`[cleanup][startup][${name}] removing stale SingletonLock (pid=${pid || 'n/a'})`);
    }
    safeUnlink(singletonLock);

    for (const side of ['SingletonCookie', 'SingletonSocket', 'lockfile']) {
      const p = path.join(authDir, `session-${name}`, side);
      if (fs.existsSync(p)) safeUnlink(p);
    }
  }
}

module.exports = {
  releaseStaleChromiumProfileLocks,
  sweepStaleLocksOnStartup,
  readSingletonLockPid,
  isPidAlive,
  sleep,
};
