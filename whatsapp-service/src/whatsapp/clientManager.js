const { WhatsAppClient } = require('./client');
const { companyIdFor, registerSessionCompany } = require('./sessionCompanyRegistry');

const clients = new Map();

function getDefaultSessionName() {
  return process.env.WHATSAPP_DEFAULT_SESSION || 'default';
}

function getOrCreateClient(sessionName, companyId = null) {
  const resolvedCompanyId = companyId ?? companyIdFor(sessionName);
  if (!clients.has(sessionName)) {
    clients.set(sessionName, new WhatsAppClient(sessionName, resolvedCompanyId));
  } else if (resolvedCompanyId != null && clients.get(sessionName).companyId == null) {
    clients.get(sessionName).companyId = resolvedCompanyId;
    registerSessionCompany(sessionName, resolvedCompanyId);
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
      { sessionName: name, companyId: c.companyId ?? null, isReady: c.isReady, hasQR: !!c.qrCode },
    ])
  );
}

function getAllClientInstances() {
  return [...clients.values()];
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
  getAllClientInstances,
  removeClient,
  destroyAll,
  resolveSessionName,
};
