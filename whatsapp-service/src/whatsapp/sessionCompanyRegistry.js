/** @type {Map<string, number>} */
const companyIdsBySession = new Map();

function registerSessionCompany(sessionName, companyId) {
  const name = String(sessionName || '').trim();
  const id = Number(companyId);
  if (name === '' || !Number.isFinite(id) || id <= 0) {
    return;
  }

  companyIdsBySession.set(name, id);
}

function registerMany(entries) {
  if (!entries) return;
  for (const [name, id] of entries) {
    registerSessionCompany(name, id);
  }
}

function companyIdFor(sessionName) {
  const name = String(sessionName || '').trim();
  if (name === '') return null;
  const id = companyIdsBySession.get(name);
  return id != null ? id : null;
}

module.exports = {
  registerSessionCompany,
  registerMany,
  companyIdFor,
  companyIdsBySession,
};
