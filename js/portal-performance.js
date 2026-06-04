/**
 * Shared performance helpers: cached fetch, visibility-aware polling, render dedupe.
 */
(function (global) {
    'use strict';

    const fetchCache = new Map();

    function cacheKey(url) {
        return url;
    }

    async function fetchJson(url, options) {
        const opts = options || {};
        const ttl = opts.ttlMs != null ? opts.ttlMs : 12000;
        const bust = opts.bypassCache === true;
        const key = cacheKey(url);
        const now = Date.now();

        if (!bust) {
            const hit = fetchCache.get(key);
            if (hit && now - hit.at < ttl) {
                return hit.data;
            }
        }

        const res = await fetch(url, {
            credentials: 'include',
            headers: opts.headers || { Accept: 'application/json' }
        });
        const text = await res.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            throw new Error('Invalid JSON from ' + url);
        }
        fetchCache.set(key, { at: now, data });
        return data;
    }

    function invalidateCache(urlPrefix) {
        if (!urlPrefix) {
            fetchCache.clear();
            return;
        }
        for (const key of fetchCache.keys()) {
            if (key.includes(urlPrefix)) {
                fetchCache.delete(key);
            }
        }
    }

    /** Call after any candidate stage/update so all portals see fresh data on next fetch. */
    function invalidatePipelineCaches() {
        [
            'get_candidates_global',
            'get_reception_scheduled',
            'get_recruiter_leads',
            'get_all_leads',
            'recruiter_stats'
        ].forEach(invalidateCache);
    }

    function dataFingerprint(rows, fields) {
        if (!Array.isArray(rows)) return '';
        return JSON.stringify(rows.map((r) => {
            const o = { id: r.id };
            (fields || ['status', 's']).forEach((f) => { o[f] = r[f] ?? r.status; });
            return o;
        }));
    }

    const pollCallbacks = {};

    const PortalPoll = {
        start(id, fn, intervalMs, options) {
            const opts = options || {};
            const ms = Math.max(intervalMs || 30000, 10000);
            PortalPoll.stop(id);
            pollCallbacks[id] = fn;

            const run = async () => {
                if (opts.pauseWhenHidden !== false && document.hidden) {
                    return;
                }
                try {
                    await fn();
                } catch (e) {
                    console.warn('[PortalPoll]', id, e);
                }
            };

            if (opts.runImmediately !== false) {
                run();
            }
            pollCallbacks['_interval_' + id] = setInterval(run, ms);
        },
        stop(id) {
            clearInterval(pollCallbacks['_interval_' + id]);
            delete pollCallbacks['_interval_' + id];
            delete pollCallbacks[id];
        },
        refreshAll() {
            Object.keys(pollCallbacks).forEach((k) => {
                if (!k.startsWith('_interval_') && typeof pollCallbacks[k] === 'function') {
                    pollCallbacks[k]();
                }
            });
        }
    };

    if (!global._portalVisibilityBound) {
        global._portalVisibilityBound = true;
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                PortalPoll.refreshAll();
            }
        });
    }

    global.PortalFetch = { fetchJson, invalidateCache, invalidatePipelineCaches };
    global.PortalPoll = PortalPoll;
    global.portalDataFingerprint = dataFingerprint;
})(window);
