const { WhatsAppClient } = require('./client');

const clients = new Map();

function getDefaultSessionName() {
  return process.env.WHATSAPP_DEFAULT_SESSION || 'default';
}

function getOrCreateClient(sessionName) {
  if (!clients.has(sessionName)) {
    clients.set(sessionName, new WhatsAppClient(sessionName));
  }
  return clients.get(sessionName);
}

function getClient(sessionName) {
  return clients.get(sessionName) || null;
}

function getAllClients() {
  return Object.fromEntries(
    [...clients.entries()].map(([name, c]) => [
      name,
      { sessionName: name, isReady: c.isReady, hasQR: !!c.qrCode },
    ])
  );
}

function removeClient(sessionName) {
  clients.delete(sessionName);
}

async function destroyAll() {
  const promises = [...clients.values()].map((c) => c.destroy());
  await Promise.allSettled(promises);
  clients.clear();
}

function resolveSessionName(req) {
  return (
    req.headers['x-whatsapp-session'] ||
    req.query.session ||
    getDefaultSessionName()
  );
}

module.exports = {
  getDefaultSessionName,
  getOrCreateClient,
  getClient,
  getAllClients,
  removeClient,
  destroyAll,
  resolveSessionName,
};
