/**
 * Balitech HRMS — Offline Daily Report Sync Module
 * Stores pending transfers in IndexedDB, syncs when server is reachable.
 */

const OfflineSync = (() => {
    const DB_NAME    = 'balitech_reports_v1';
    const STORE_NAME = 'pending_transfers';
    const DB_VERSION = 1;
    const PING_URL   = 'api/ping.php';
    const SUBMIT_URL = 'api/submit_daily_report.php';
    const PING_INTERVAL_MS = 30000; // 30 seconds

    let db = null;
    let isOnline = false;
    let pingTimer = null;
    let onStatusChange = null; // callback(isOnline, pendingCount)

    // ── UUID Generator ──────────────────────────────────────────
    function generateUUID() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
            const r = Math.random() * 16 | 0;
            return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
        });
    }

    // ── IndexedDB Init ──────────────────────────────────────────
    function initDB() {
        return new Promise((resolve, reject) => {
            if (db) { resolve(db); return; }
            const req = indexedDB.open(DB_NAME, DB_VERSION);
            req.onupgradeneeded = e => {
                const idb = e.target.result;
                if (!idb.objectStoreNames.contains(STORE_NAME)) {
                    const store = idb.createObjectStore(STORE_NAME, { keyPath: 'uuid' });
                    store.createIndex('created_at', 'created_at');
                    store.createIndex('synced', 'synced');
                }
            };
            req.onsuccess = e => { db = e.target.result; resolve(db); };
            req.onerror   = e => reject(e.target.error);
        });
    }

    // ── Save a record locally ───────────────────────────────────
    async function saveLocally(record) {
        await initDB();
        return new Promise((resolve, reject) => {
            const uuid = generateUUID();
            const entry = {
                uuid,
                synced: false,
                created_at: new Date().toISOString(),
                ...record,
                offline_uuid: uuid,
            };
            const tx    = db.transaction(STORE_NAME, 'readwrite');
            const store = tx.objectStore(STORE_NAME);
            const req   = store.put(entry);
            req.onsuccess = () => resolve({ uuid, entry });
            req.onerror   = e => reject(e.target.error);
        });
    }

    // ── Get all pending (unsynced) records ──────────────────────
    async function getPending() {
        await initDB();
        return new Promise((resolve, reject) => {
            const tx    = db.transaction(STORE_NAME, 'readonly');
            const store = tx.objectStore(STORE_NAME);
            const idx   = store.index('synced');
            const req   = idx.getAll(IDBKeyRange.only(false));
            req.onsuccess = e => resolve(e.target.result || []);
            req.onerror   = e => reject(e.target.error);
        });
    }

    // ── Get all records (including synced) ──────────────────────
    async function getAll() {
        await initDB();
        return new Promise((resolve, reject) => {
            const tx    = db.transaction(STORE_NAME, 'readonly');
            const store = tx.objectStore(STORE_NAME);
            const req   = store.getAll();
            req.onsuccess = e => resolve(e.target.result || []);
            req.onerror   = e => reject(e.target.error);
        });
    }

    // ── Mark a record as synced ─────────────────────────────────
    async function markSynced(uuid) {
        await initDB();
        return new Promise((resolve, reject) => {
            const tx    = db.transaction(STORE_NAME, 'readwrite');
            const store = tx.objectStore(STORE_NAME);
            const getReq = store.get(uuid);
            getReq.onsuccess = e => {
                const entry = e.target.result;
                if (!entry) { resolve(); return; }
                entry.synced = true;
                entry.synced_at = new Date().toISOString();
                const putReq = store.put(entry);
                putReq.onsuccess = () => resolve();
                putReq.onerror   = e2 => reject(e2.target.error);
            };
            getReq.onerror = e => reject(e.target.error);
        });
    }

    // ── Ping server ─────────────────────────────────────────────
    async function ping() {
        try {
            const res = await fetch(PING_URL, { method: 'HEAD', cache: 'no-cache' });
            return res.ok;
        } catch {
            return false;
        }
    }

    // ── Sync all pending to server ──────────────────────────────
    async function syncAll(agentId, sessionCreds) {
        const pending = await getPending();
        if (pending.length === 0) return { synced: 0, failed: 0 };

        let synced = 0, failed = 0;
        for (const entry of pending) {
            try {
                const payload = {
                    customer_number:    entry.customer_number    || '',
                    customer_name:      entry.customer_name      || '',
                    customer_zip:       entry.customer_zip       || '',
                    customer_age:       entry.customer_age       || '',
                    transfer_on:        entry.transfer_on        || 'D1',
                    call_notes:         entry.call_notes         || '',
                    call_duration_mins: entry.call_duration_mins || 0,
                    offline_uuid:       entry.uuid,
                    // Pass agent_id for offline-form syncs that don't have a session
                    ...(agentId ? { agent_biometric_id: agentId } : {}),
                };

                const res = await fetch(SUBMIT_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'include',
                    body: JSON.stringify(payload),
                });
                const data = await res.json();
                if (data.success || data.duplicate) {
                    await markSynced(entry.uuid);
                    synced++;
                } else {
                    failed++;
                }
            } catch {
                failed++;
            }
        }
        return { synced, failed };
    }

    // ── Auto-sync loop ───────────────────────────────────────────
    async function runAutoSync(agentId) {
        const serverUp = await ping();
        const pending  = await getPending();

        if (serverUp !== isOnline) {
            isOnline = serverUp;
        }

        if (serverUp && pending.length > 0) {
            await syncAll(agentId);
        }

        const newPending = await getPending();
        if (typeof onStatusChange === 'function') {
            onStatusChange(serverUp, newPending.length);
        }
    }

    function startAutoSync(agentId, statusCallback) {
        onStatusChange = statusCallback;
        runAutoSync(agentId);
        pingTimer = setInterval(() => runAutoSync(agentId), PING_INTERVAL_MS);
    }

    function stopAutoSync() {
        if (pingTimer) clearInterval(pingTimer);
    }

    // ── Export to CSV ────────────────────────────────────────────
    async function exportCSV() {
        const all = await getAll();
        if (all.length === 0) { alert('No records to export.'); return; }
        const headers = ['UUID', 'Customer Number', 'Customer Name', 'Zip', 'Age', 'Transfer', 'Notes', 'Duration (min)', 'Synced', 'Created At'];
        const rows = all.map(r => [
            r.uuid, r.customer_number, r.customer_name || '', r.customer_zip || '',
            r.customer_age || '', r.transfer_on, r.call_notes || '',
            r.call_duration_mins || 0, r.synced ? 'Yes' : 'No', r.created_at
        ]);
        let csv = headers.map(h => `"${h}"`).join(',') + '\n';
        rows.forEach(r => { csv += r.map(v => `"${String(v).replace(/"/g, '""')}"`).join(',') + '\n'; });
        const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href = url; a.download = `transfers_offline_${new Date().toISOString().slice(0,10)}.csv`;
        a.click(); URL.revokeObjectURL(url);
    }

    return { initDB, saveLocally, getPending, getAll, markSynced, ping, syncAll, startAutoSync, stopAutoSync, exportCSV };
})();
