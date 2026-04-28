/**
 * Простой промис-мьютекс на сессию: initialize / destroy / logout никогда
 * не выполняются параллельно для одной и той же сессии, даже если кто-то
 * дважды дёрнул API. Это главный способ избавиться от «The browser is
 * already running for …»: повторный запуск просто ждёт окончания текущего.
 */
const queues = new Map();

/**
 * @template T
 * @param {string} key
 * @param {() => Promise<T>} task
 * @returns {Promise<T>}
 */
function runExclusive(key, task) {
  const prev = queues.get(key) || Promise.resolve();
  const next = prev.then(() => task()).catch((err) => {
    throw err;
  });
  // Мьютекс не должен копить цепочку ошибок — оставляем resolved-версию.
  queues.set(
    key,
    next.then(
      () => undefined,
      () => undefined,
    ),
  );
  // Очищаем карту, когда хвост опустел.
  next.finally(() => {
    if (queues.get(key) && queues.get(key) === next) {
      queues.delete(key);
    }
  });
  return next;
}

module.exports = { runExclusive };
