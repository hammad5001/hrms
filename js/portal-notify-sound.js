/**
 * Short SMS-style notification tone (Web Audio API).
 * Shared by employee portal and chat.
 */
(function (global) {
    'use strict';

    let ctx = null;
    let lastPlay = 0;
    const MIN_GAP_MS = 1400;
    const MUTE_KEY = 'portal_notify_sound_muted';

    function getCtx() {
        if (!ctx) {
            const AC = global.AudioContext || global.webkitAudioContext;
            if (!AC) return null;
            ctx = new AC();
        }
        return ctx;
    }

    function isMuted() {
        try { return localStorage.getItem(MUTE_KEY) === '1'; } catch { return false; }
    }

    function playSmsTone() {
        if (isMuted()) return;
        const now = Date.now();
        if (now - lastPlay < MIN_GAP_MS) return;
        lastPlay = now;

        const ac = getCtx();
        if (!ac) return;
        if (ac.state === 'suspended') ac.resume();

        try {
            const freqs = [880, 1174];
            freqs.forEach((freq, i) => {
                const osc = ac.createOscillator();
                const gain = ac.createGain();
                osc.type = 'sine';
                osc.frequency.value = freq;
                const t = ac.currentTime + i * 0.11;
                gain.gain.setValueAtTime(0, t);
                gain.gain.linearRampToValueAtTime(0.14, t + 0.018);
                gain.gain.exponentialRampToValueAtTime(0.001, t + 0.13);
                osc.connect(gain);
                gain.connect(ac.destination);
                osc.start(t);
                osc.stop(t + 0.15);
            });
        } catch { /* ignore autoplay / audio errors */ }
    }

    function unlock() {
        const ac = getCtx();
        if (ac && ac.state === 'suspended') ac.resume();
    }

    global.PortalNotifySound = {
        play: playSmsTone,
        unlock,
        setMuted: (muted) => {
            try { localStorage.setItem(MUTE_KEY, muted ? '1' : '0'); } catch { /* ignore */ }
        },
        isMuted,
    };
})(window);
