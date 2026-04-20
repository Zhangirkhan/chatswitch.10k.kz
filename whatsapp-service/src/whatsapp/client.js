const { Client, Events } = require('whatsapp-web.js');
const { buildClientOptions, AUTH_DIR } = require('./clientConfig');
const { attachEventBindings } = require('./clientEventBindings');
const fs = require('fs');
const path = require('path');

class WhatsAppClient {
  constructor(sessionName) {
    this.sessionName = sessionName;
    this.client = null;
    this.qrCode = null;
    this.isReady = false;
    this.isInitializing = false;
  }

  async initialize() {
    if (this.isInitializing) {
      console.log(`[${this.sessionName}] already initializing, skip`);
      return;
    }

    this.isInitializing = true;
    console.log(`[${this.sessionName}] initializing...`);

    try {
      const options = buildClientOptions(this.sessionName);
      this.client = new Client(options);
      attachEventBindings(this);
      await this.client.initialize();
    } catch (err) {
      console.error(`[${this.sessionName}] init error:`, err.message);
      this.isInitializing = false;
      throw err;
    }
  }

  checkExistingSession() {
    const sessionPath = path.join(AUTH_DIR, `session-${this.sessionName}`);
    if (fs.existsSync(sessionPath)) {
      console.log(`[${this.sessionName}] found existing session, auto-initializing`);
      this.initialize().catch((err) =>
        console.error(`[${this.sessionName}] auto-init failed:`, err.message)
      );
    }
  }

  getQRCode() {
    return this.qrCode;
  }

  async logout() {
    if (this.client) {
      try {
        await this.client.logout();
      } catch (e) {
        console.error(`[${this.sessionName}] logout error:`, e.message);
      }
    }
    this.isReady = false;
    this.qrCode = null;
    this.isInitializing = false;
  }

  async destroy() {
    if (this.client) {
      try {
        await this.client.destroy();
      } catch (e) {
        console.error(`[${this.sessionName}] destroy error:`, e.message);
      }
    }
    this.isReady = false;
    this.qrCode = null;
    this.isInitializing = false;
  }
}

module.exports = { WhatsAppClient };
