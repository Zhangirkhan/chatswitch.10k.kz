const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');
const { Client } = require('whatsapp-web.js');
const { buildClientOptions, AUTH_DIR } = require('./clientConfig');
const { attachEventBindings, attachRuntimeEvents } = require('./clientEventBindings');
const { runExclusive } = require('./sessionMutex');
const { releaseStaleChromiumProfileLocks } = require('./sessionProfileCleanup');
const { syncMissedInboundMessages, stopInboundSyncPoller } = require('./syncMissedInbound');

class WhatsAppClient {
  constructor(sessionName, companyId = null) {
    this.sessionName = sessionName;
    this.companyId = companyId;
    this.client = null;
    this.qrCode = null;
    this.isReady = false;
    this.isInitializing = false;
    this.lastError = null;
    this.runtimeEventsBound = false;
    this.runtimeEventsClient = null;
  }

  ensureRuntimeEvents(reason = 'ensure') {
    attachRuntimeEvents(this);
    if (this.isReady && this.client) {
      syncMissedInboundMessages(this, { reason, force: reason === 'manual_sync' }).catch((err) => {
        console.error(`[${this.sessionName}] ensure sync failed:`, err.message);
      });
    }
  }

  async initialize() {
    return runExclusive(this.sessionName, async () => {
      // If transport is already connected, avoid recreating the puppeteer/browser.
      // Re-initialization for a session whose browser is still alive can throw:
      // "The browser is already running for <userDataDir>"
      // which stops the session from becoming ready.
      if (this.client) {
        const state = await this.getState();
        const browserConnected = this.browserConnected();
        if (browserConnected && state === 'CONNECTED') {
          if (!this.isReady) {
            console.log(
              `[${this.sessionName}] CONNECTED without READY (existing client)`
            );
            this.isReady = true;
            this.isInitializing = false;
            this.qrCode = null;
          }
          this.ensureRuntimeEvents('existing_client');
          return;
        }
      }

      if (this.isReady) {
        const state = await this.getState();
        if (this.browserConnected() && state === 'CONNECTED') {
          this.ensureRuntimeEvents('ready_connected');
          return;
        }

        console.warn(
          `[${this.sessionName}] stale ready state detected, reinitializing browser session`
        );
        this.isReady = false;
      }

      if (this.isInitializing) {
        console.log(`[${this.sessionName}] already initializing, skip`);
        return;
      }

      this.isInitializing = true;
      this.lastError = null;
      console.log(`[${this.sessionName}] initializing...`);

      try {
        if (this.client) {
          await this.safeDestroyCurrentClient();
        }

        // Если у нас нет живого `this.client`, но Chromium под этим `userDataDir` уже висит,
        // то `whatsapp-web.js` упадёт с "The browser is already running".
        // Почистим возможные stale lock'и до запуска нового клиента.
        if (!this.client) {
          try {
            await releaseStaleChromiumProfileLocks(AUTH_DIR, this.sessionName);
          } catch (_) {
            // best-effort: очистка не должна ломать инициализацию
          }
        }

        this.client = new Client(buildClientOptions(this.sessionName));
        this.runtimeEventsBound = false;
        this.runtimeEventsClient = null;
        attachEventBindings(this);
        await this.client.initialize();

        // Fallback: some WA Web builds may not emit READY reliably.
        // If transport is already connected, mark session ready and bind runtime events.
        const state = await this.getState();
        if (state === 'CONNECTED' && !this.isReady) {
          console.log(`[${this.sessionName}] CONNECTED without READY (initialize fallback)`);
          this.isReady = true;
          this.isInitializing = false;
          this.qrCode = null;
          this.ensureRuntimeEvents('initialize_fallback');
        }
      } catch (err) {
        this.lastError = err.message;
        this.isReady = false;
        this.isInitializing = false;
        console.error(`[${this.sessionName}] init error:`, err.message);
        throw err;
      }
    });
  }

  checkExistingSession() {
    const sessionPath = path.join(AUTH_DIR, `session-${this.sessionName}`);
    if (!fs.existsSync(sessionPath)) {
      return;
    }

    console.log(`[${this.sessionName}] found existing session, auto-initializing`);
    this.initialize().catch((err) =>
      console.error(`[${this.sessionName}] auto-init failed:`, err.message)
    );
  }

  getQRCode() {
    return this.qrCode;
  }

  async getState() {
    if (!this.client) {
      return null;
    }

    try {
      return await this.client.getState();
    } catch (err) {
      this.lastError = err.message;
      return null;
    }
  }

  browserConnected() {
    try {
      return Boolean(this.client?.pupBrowser?.isConnected?.());
    } catch (_) {
      return false;
    }
  }

  async verify() {
    const started = process.hrtime.bigint();
    const state = await this.getState();
    const browserConnected = this.browserConnected();
    const alive = this.isReady && browserConnected && state === 'CONNECTED';
    const reasoning = [];

    if (!this.client) reasoning.push('client_missing');
    if (!browserConnected) reasoning.push('browser_disconnected');
    if (!this.isReady) reasoning.push('not_ready');
    if (this.isInitializing) reasoning.push('initializing');
    if (this.qrCode) reasoning.push('qr_pending');
    if (state && state !== 'CONNECTED') reasoning.push(`state_${state}`);
    if (this.lastError) reasoning.push(`last_error:${this.lastError}`);

    return {
      success: true,
      sessionName: this.sessionName,
      alive,
      state,
      browserConnected,
      isReady: this.isReady,
      isInitializing: this.isInitializing,
      hasQR: Boolean(this.qrCode),
      platform: this.client?.info?.platform || null,
      latencyMs: Number((process.hrtime.bigint() - started) / 1000000n),
      lastError: this.lastError,
      reasoning,
    };
  }

  async logout() {
    return runExclusive(this.sessionName, async () => {
      if (this.client) {
        try {
          await this.client.logout();
        } catch (err) {
          this.lastError = err.message;
          console.error(`[${this.sessionName}] logout error:`, err.message);
        }
      }

      await this.safeDestroyCurrentClient();
      stopInboundSyncPoller(this);
      this.isReady = false;
      this.qrCode = null;
      this.isInitializing = false;
    });
  }

  async destroy() {
    return runExclusive(this.sessionName, async () => {
      await this.safeDestroyCurrentClient();
      stopInboundSyncPoller(this);
      this.isReady = false;
      this.qrCode = null;
      this.isInitializing = false;
    });
  }

  async safeDestroyCurrentClient() {
    if (!this.client) {
      return;
    }

    try {
      await this.client.destroy();
    } catch (err) {
      console.error(`[${this.sessionName}] destroy error:`, err.message);
    } finally {
      // whatsapp-web.js should close Chromium, but in practice we sometimes still
      // get "The browser is already running for ...session-XXX" on the next
      // initialize(). Force-kill any remaining chrome processes for our
      // userDataDir so the next initialize can start cleanly.
      try {
        const userDataDir = path.join(AUTH_DIR, `session-${this.sessionName}`);
        execSync(`pkill -f "user-data-dir=${userDataDir}" || true`);
        execSync(`pkill -f "${userDataDir}" || true`);
      } catch (e) {
        // ignore: best-effort cleanup
      }

      // Give Chrome time to actually exit before we attempt to re-launch.
      await new Promise((r) => setTimeout(r, 500));

      this.client = null;
      this.runtimeEventsBound = false;
      this.runtimeEventsClient = null;
    }
  }
}

module.exports = { WhatsAppClient };
