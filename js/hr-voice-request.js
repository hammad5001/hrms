/**
 * HR / Management: queue voice on reception PC only (no local speech).
 */
(function (global) {
    'use strict';

    async function requestReceptionAnnouncement(candidateName, room) {
        const name = (candidateName || 'Candidate').trim();
        const r = (room || 'HR').trim();
        try {
            const res = await fetch('api/portal_notifications.php?action=create', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    type: 'voice_call',
                    target: 'reception',
                    payload: {
                        name,
                        room: r,
                        repeat_count: 3,
                        voice_pref: 'female_en',
                        timestamp: Date.now()
                    }
                })
            });
            const text = await res.text();
            let data;
            try {
                data = JSON.parse(text);
            } catch (parseErr) {
                console.error('Voice API non-JSON response:', text.slice(0, 300));
                return { ok: false, error: 'Server error — log in again or contact admin' };
            }
            if (!data.success) {
                console.error('Voice queue failed:', data.error);
                return { ok: false, error: data.error || 'Request failed' };
            }
            return { ok: true, id: data.data?.id };
        } catch (e) {
            console.error('Voice queue network error:', e);
            return { ok: false, error: 'Network error — is Apache/MySQL running?' };
        }
    }

    async function makeVoiceCall(candidateName, room) {
        const result = await requestReceptionAnnouncement(candidateName, room);
        const ok = result && result.ok;
        if (typeof global.showToast === 'function') {
            global.showToast(
                ok
                    ? `Voice sent to reception desk for ${candidateName} (plays 3× there only)`
                    : (result.error || 'Could not send voice to reception'),
                ok ? 'success' : 'error'
            );
        }
        return ok;
    }

    global.HrVoice = { makeVoiceCall, requestReceptionAnnouncement };
    global.makeVoiceCall = makeVoiceCall;
})(window);
