/**
 * Reception desk voice announcements (female English, 3×).
 * HR portal queues via API only — no speech on HR PC.
 */
(function (global) {
    'use strict';

    let preferredVoice = null;
    let voicesReady = false;
    let isPlaying = false;
    const playQueue = [];

    function delay(ms) {
        return new Promise((resolve) => setTimeout(resolve, ms));
    }

    function pickFemaleVoice() {
        if (!global.speechSynthesis) return null;
        const voices = global.speechSynthesis.getVoices();
        const prefer = [
            'Microsoft Zira',
            'Microsoft Hazel',
            'Google UK English Female',
            'Google US English Female',
            'Samantha',
            'Victoria',
            'Karen',
            'Moira'
        ];
        for (const name of prefer) {
            const v = voices.find((x) => x.name.includes(name));
            if (v) return v;
        }
        return voices.find((v) =>
            v.lang && v.lang.startsWith('en') &&
            (/female|zira|samantha|hazel/i.test(v.name))
        ) || voices.find((v) => v.lang && v.lang.startsWith('en')) || null;
    }

    function refreshVoices() {
        preferredVoice = pickFemaleVoice();
        voicesReady = !!preferredVoice || global.speechSynthesis.getVoices().length > 0;
    }

    if (global.speechSynthesis) {
        refreshVoices();
        global.speechSynthesis.onvoiceschanged = refreshVoices;
    }

    function speakOnce(text) {
        return new Promise((resolve) => {
            if (!global.speechSynthesis) {
                resolve(false);
                return;
            }
            const utterance = new SpeechSynthesisUtterance(text);
            const voice = preferredVoice || pickFemaleVoice();
            if (voice) utterance.voice = voice;
            utterance.lang = (voice && voice.lang) || 'en-US';
            utterance.rate = 0.88;
            utterance.pitch = 1.1;
            utterance.volume = 1;
            utterance.onend = () => resolve(true);
            utterance.onerror = () => resolve(false);
            try {
                global.speechSynthesis.speak(utterance);
            } catch (e) {
                console.warn('speechSynthesis.speak failed', e);
                resolve(false);
            }
        });
    }

    function showVoicePanel(name, count, total) {
        const panel = document.getElementById('voicePanel');
        const nameEl = document.getElementById('voiceName');
        const countEl = document.getElementById('voiceCount');
        const msgEl = document.getElementById('voiceMessage');
        if (nameEl) nameEl.textContent = name || 'Candidate';
        if (countEl) countEl.textContent = `${count}/${total}`;
        if (msgEl) {
            msgEl.textContent = `Please come to the interview desk — announcement ${count} of ${total}`;
        }
        if (panel) {
            panel.classList.add('active');
            if (count >= total) {
                setTimeout(() => panel.classList.remove('active'), 4000);
            }
        }
    }

    async function playAnnouncement(name, room, repeatCount) {
        const total = Math.max(1, Math.min(3, repeatCount || 3));
        const safeName = (name || 'Candidate').trim();
        const safeRoom = (room || 'HR').trim();

        if (!global.speechSynthesis) {
            console.warn('Speech synthesis not supported');
            return false;
        }

        refreshVoices();

        for (let i = 1; i <= total; i++) {
            const message = `${safeName}, please come to ${safeRoom} office for your interview. Announcement ${i} of ${total}.`;
            showVoicePanel(safeName, i, total);
            global.speechSynthesis.cancel();
            await delay(80);
            await speakOnce(message);
            if (i < total) await delay(800);
        }
        return true;
    }

    async function drainQueue() {
        if (isPlaying || !playQueue.length) return;
        isPlaying = true;
        while (playQueue.length) {
            const job = playQueue.shift();
            try {
                await playAnnouncement(job.name, job.room, job.repeatCount);
            } catch (e) {
                console.warn('Voice job failed', e);
            }
            await delay(400);
        }
        isPlaying = false;
    }

    function enqueueAnnouncement(name, room, repeatCount) {
        playQueue.push({ name, room, repeatCount: repeatCount || 3 });
        drainQueue();
    }

    /** Local announce button on reception portal */
    function announceCandidate(name, room) {
        enqueueAnnouncement(name, room || 'Interview', 3);
        if (typeof global.showToast === 'function') {
            global.showToast(`Announcing ${name} (3 times)`, 'info');
        }
    }

    /** Test voice — requires user click */
    async function testVoice() {
        refreshVoices();
        const ok = await playAnnouncement('Test Candidate', 'Reception', 1);
        if (typeof global.showToast === 'function') {
            global.showToast(ok ? 'Voice system is working' : 'Voice failed — check browser permissions', ok ? 'success' : 'error');
        }
        return ok;
    }

    const processingIds = new Set();

    async function pollHrVoiceNotifications() {
        try {
            const res = await fetch('api/portal_notifications.php?action=list&target=reception&unplayed=1&type=voice_call', {
                credentials: 'include'
            });
            const data = await res.json();
            if (!data.success || !Array.isArray(data.data) || !data.data.length) return;

            const voiceRows = data.data
                .filter((row) => (row.notification_type || '') === 'voice_call')
                .sort((a, b) => new Date(a.created_at) - new Date(b.created_at));

            for (const row of voiceRows) {
                if (processingIds.has(row.id)) continue;
                processingIds.add(row.id);

                const p = row.payload || {};
                const name = p.name || 'Candidate';
                const room = p.room || 'HR';
                const repeatCount = p.repeat_count || 3;

                await playAnnouncement(name, room, repeatCount);

                await fetch('api/portal_notifications.php?action=markPlayed', {
                    method: 'POST',
                    credentials: 'include',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ ids: [row.id], consumer_portal: 'reception' })
                });

                processingIds.delete(row.id);
            }
        } catch (e) {
            console.warn('HR voice poll error:', e);
        }
    }

    function startHrVoicePoller(intervalMs) {
        const ms = Math.max(intervalMs || 4000, 3000);
        const tick = () => {
            if (!document.hidden) {
                pollHrVoiceNotifications();
            }
        };
        tick();
        setInterval(tick, ms);
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) tick();
        });
    }

    global.ReceptionVoice = {
        announceCandidate,
        testVoice,
        playAnnouncement,
        pollHrVoiceNotifications,
        startHrVoicePoller,
        refreshVoices
    };

    global.announceCandidate = announceCandidate;
    global.testVoice = testVoice;
})(window);
