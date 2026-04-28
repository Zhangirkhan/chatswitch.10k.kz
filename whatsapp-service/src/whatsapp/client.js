const fs = require('fs');
const path = require('path');
const { Client } = require('whatsapp-web.js');
const { buildClientOptions, AUTH_DIR } = require('./clientConfig');
const { attachEventBindings } = require('./clientEventBindings');
const { runExclusive } = require('./sessionMutex');

class WhatsAppClient {
  constructor(sessionName) {
    this.sessionName = sessionName;
    this.client = null;
    this.qrCode = null;
    this.isReady = false;
    this.isInitializing = false;
    this.lastError = null;
  }

  async initialize() {
    return runExclusive(this.sessionName, async () => {
      if (this.isReady) {
        const state = await this.getState();
        if (this.browserConnected() && state === 'CONNECTED') {
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

        this.client = new Client(buildClientOptions(this.sessionName));
        attachEventBindings(this);
        await this.client.initialize();
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
      this.isReady = false;
      this.qrCode = null;
      this.isInitializing = false;
    });
  }

  async destroy() {
    return runExclusive(this.sessionName, async () => {
      await this.safeDestroyCurrentClient();
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
      this.client = null;
    }
  }
}

module.exports = { WhatsAppClient };
