// js/call-shell.js - Complete WebRTC calling and screen sharing controller

(function () {
    const channel = new BroadcastChannel('balitech-hrms-calls');
    let livekitSdkLoaded = false;
    let ws = null;
    let currentCall = null; // { id, uuid, room_name, token, status, role }
    let callTimer = null;
    let callDuration = 0;
    let activeRoom = null; // LiveKit Room object
    let localAudioTrack = null;
    let localScreenTrack = null;
    let synthRingInterval = null;
    let synthRingContext = null;
    let livekitWsUrl = 'wss://call.example.com';
    const path = (window.location.pathname || '').toLowerCase();
    const isWfh = path.includes('/workfromhome/');
    const inAttendance = path.includes('/attendance/');
    const prefix = (isWfh || inAttendance) ? '../' : '';

    // Load LiveKit SDK from CDN dynamically
    function loadLiveKitSDK(callback) {
        if (livekitSdkLoaded || window.LiveKit || window.LivekitClient) {
            if (callback) callback();
            return;
        }
        const script = document.createElement('script');
        script.src = "https://cdn.jsdelivr.net/npm/livekit-client/dist/livekit-client.umd.min.js";
        script.onload = function () {
            livekitSdkLoaded = true;
            console.log('LiveKit Client SDK loaded successfully');
            if (callback) callback();
        };
        script.onerror = function () {
            console.error('Failed to load LiveKit Client SDK');
        };
        document.head.appendChild(script);
    }

    // Initialize BroadcastChannel listener for multi-tab sync
    channel.onmessage = function (event) {
        const msg = event.data;
        if (!msg) return;

        if (msg.type === 'call-answered-elsewhere') {
            dismissIncomingCallUI();
            stopRingtone();
        } else if (msg.type === 'call-declined-elsewhere') {
            dismissIncomingCallUI();
            stopRingtone();
        }
    };

    // Synthesize a Teams-like ringtone using Web Audio API (completely self-contained!)
    function playRingtone() {
        try {
            if (synthRingInterval) return;
            const AudioContext = window.AudioContext || window.webkitAudioContext;
            if (!AudioContext) return;
            synthRingContext = new AudioContext();

            function triggerBeep() {
                if (!synthRingContext || synthRingContext.state === 'closed') return;
                const osc1 = synthRingContext.createOscillator();
                const osc2 = synthRingContext.createOscillator();
                const gain = synthRingContext.createGain();

                osc1.type = 'sine';
                osc2.type = 'sine';
                osc1.frequency.setValueAtTime(440, synthRingContext.currentTime); // A4
                osc2.frequency.setValueAtTime(480, synthRingContext.currentTime); // Ring tone beat frequency

                gain.gain.setValueAtTime(0, synthRingContext.currentTime);
                gain.gain.linearRampToValueAtTime(0.3, synthRingContext.currentTime + 0.1);
                gain.gain.exponentialRampToValueAtTime(0.001, synthRingContext.currentTime + 1.2);

                osc1.connect(gain);
                osc2.connect(gain);
                gain.connect(synthRingContext.destination);

                osc1.start();
                osc2.start();
                osc1.stop(synthRingContext.currentTime + 1.3);
                osc2.stop(synthRingContext.currentTime + 1.3);
            }

            triggerBeep();
            synthRingInterval = setInterval(triggerBeep, 2000);
        } catch (e) {
            console.warn('Ringtone playback error:', e);
        }
    }

    function stopRingtone() {
        if (synthRingInterval) {
            clearInterval(synthRingInterval);
            synthRingInterval = null;
        }
        if (synthRingContext) {
            synthRingContext.close();
            synthRingContext = null;
        }
    }

    // Connect to WebSocket gateway for Call Signaling
    async function initWebSocketSignaling() {
        try {
            // Fetch WebRTC Calling Config
            const configRes = await fetch(prefix + 'api/call_api.php?action=config').catch(() => null);
            if (configRes) {
                const configData = await configRes.json().catch(() => null);
                if (configData && configData.success && configData.data.livekit_ws_url) {
                    livekitWsUrl = configData.data.livekit_ws_url;
                }
            }

            const res = await fetch(prefix + 'api/chat_api.php?action=wsConfig');
            const data = await res.json();
            if (!data.success || !data.data.enabled) {
                console.warn('WebSocket signaling is disabled or unavailable.');
                return;
            }

            const wsUrl = data.data.url;
            const token = data.data.token;

            ws = new WebSocket(wsUrl);

            ws.onopen = function () {
                ws.send(JSON.stringify({ type: 'auth', token: token }));
            };

            ws.onmessage = function (e) {
                let msg;
                try {
                    msg = JSON.parse(e.data);
                } catch {
                    return;
                }

                if (msg.type === 'call.invite') {
                    handleIncomingInvite(msg);
                } else if (msg.type === 'call.accepted') {
                    handleCallAccepted(msg);
                } else if (msg.type === 'call.declined') {
                    handleCallDeclined(msg);
                } else if (msg.type === 'call.cancelled') {
                    handleCallCancelled(msg);
                } else if (msg.type === 'call.ended') {
                    handleCallEnded(msg);
                } else if (msg.type === 'call.participant_left') {
                    handleParticipantLeft(msg);
                } else if (msg.type === 'call.busy') {
                    handleRecipientBusy(msg);
                }
            };

            ws.onclose = function () {
                // Reconnect loop after 5 seconds
                setTimeout(initWebSocketSignaling, 5000);
            };
        } catch (err) {
            console.warn('Could not establish WebSocket signaling:', err);
            setTimeout(initWebSocketSignaling, 10000);
        }
    }

    // Handle Incoming Invite
    function handleIncomingInvite(payload) {
        if (currentCall) {
            // Already in a call, reject automatically as busy
            sendDeclineRequest(payload.uuid);
            return;
        }

        currentCall = {
            id: payload.call_id,
            uuid: payload.uuid,
            room_name: payload.room_name,
            conversation_id: payload.conversation_id,
            status: 'ringing',
            role: 'invitee'
        };

        playRingtone();
        showIncomingCallUI(payload.caller_name, payload.call_type);
    }

    function showIncomingCallUI(callerName, callType) {
        // Remove any old modal first
        dismissIncomingCallUI();

        const overlay = document.createElement('div');
        overlay.className = 'bt-call-modal-overlay';
        overlay.id = 'btCallIncomingModal';
        overlay.innerHTML = `
            <div class="bt-call-modal-card bt-call-shell-root">
                <div class="bt-call-avatar-container">
                    ${callerName.charAt(0).toUpperCase()}
                    <div class="bt-call-avatar-ripple"></div>
                </div>
                <div class="bt-call-modal-title">${callerName}</div>
                <div class="bt-call-modal-subtitle">Incoming ${callType} audio call...</div>
                <div class="bt-call-btn-group">
                    <button class="bt-call-btn bt-call-btn-decline" id="btnDeclineIncoming">
                        <i class="fas fa-phone-slash"></i> Decline
                    </button>
                    <button class="bt-call-btn bt-call-btn-accept" id="btnAcceptIncoming">
                        <i class="fas fa-phone"></i> Accept
                    </button>
                </div>
            </div>
        `;

        document.body.appendChild(overlay);

        document.getElementById('btnAcceptIncoming').onclick = acceptCall;
        document.getElementById('btnDeclineIncoming').onclick = declineCall;
    }

    function dismissIncomingCallUI() {
        const modal = document.getElementById('btCallIncomingModal');
        if (modal) modal.remove();
    }

    // Accept/Decline action handlers
    async function acceptCall() {
        if (!currentCall) return;
        stopRingtone();
        dismissIncomingCallUI();

        channel.postMessage({ type: 'call-answered-elsewhere' });

        try {
            const res = await fetch(prefix + 'api/call_api.php?action=accept', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ uuid: currentCall.uuid })
            });
            const data = await res.json();
            if (!data.success) {
                alert(data.message || 'Could not join call');
                currentCall = null;
                return;
            }

            currentCall.token = data.data.token;
            currentCall.status = 'active';

            loadLiveKitSDK(function () {
                initiateLiveKitSession();
            });

        } catch (e) {
            console.error('Accept call failed:', e);
            currentCall = null;
        }
    }

    async function declineCall() {
        if (!currentCall) return;
        stopRingtone();
        dismissIncomingCallUI();

        channel.postMessage({ type: 'call-declined-elsewhere' });
        sendDeclineRequest(currentCall.uuid);
        currentCall = null;
    }

    async function sendDeclineRequest(uuid) {
        try {
            await fetch(prefix + 'api/call_api.php?action=decline', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ uuid: uuid })
            });
        } catch (e) {
            console.warn('Decline request failed:', e);
        }
    }

    // Initiate Call from Caller side
    async function startRtcCall(conversationId) {
        if (currentCall) return;

        try {
            const res = await fetch(prefix + 'api/call_api.php?action=create', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ conversation_id: conversationId })
            });
            const data = await res.json();
            if (!data.success) {
                alert(data.message || 'Calling service currently unavailable');
                return;
            }

            currentCall = {
                id: data.data.call.id,
                uuid: data.data.call.uuid,
                room_name: data.data.call.room_name,
                token: data.data.token,
                status: 'initiated',
                role: 'host'
            };

            showActiveCallWindow('Calling...', true);

            loadLiveKitSDK(function () {
                initiateLiveKitSession();
            });

        } catch (e) {
            console.error('Initiate call error:', e);
            alert('Calling service currently offline');
        }
    }

    // LiveKit Room Management
    async function initiateLiveKitSession() {
        if (!currentCall || !currentCall.token) return;

        const LkSdk = window.LiveKit || window.LivekitClient;
        if (!LkSdk) {
            console.error('LiveKit SDK not found globally');
            hangupCall();
            return;
        }

        try {
            activeRoom = new LkSdk.Room({
                adaptiveStream: true,
                dynacast: true
            });

            // Room Event Handlers
            activeRoom
                .on(LkSdk.RoomEvent.ParticipantConnected, function (participant) {
                    addParticipantCard(participant);
                })
                .on(LkSdk.RoomEvent.ParticipantDisconnected, function (participant) {
                    removeParticipantCard(participant);
                })
                .on(LkSdk.RoomEvent.TrackSubscribed, function (track, publication, participant) {
                    handleTrackSubscribed(track, participant);
                })
                .on(LkSdk.RoomEvent.TrackUnsubscribed, function (track, publication, participant) {
                    handleTrackUnsubscribed(track, participant);
                })
                .on(LkSdk.RoomEvent.ActiveSpeakersChanged, function (speakers) {
                    handleSpeakersChanged(speakers);
                })
                .on(LkSdk.RoomEvent.Disconnected, function () {
                    handleLiveKitDisconnect();
                });

            // Connect to LiveKit Room
            await activeRoom.connect(livekitWsUrl, currentCall.token);
            console.log('Connected to LiveKit Room:', activeRoom.name);

            // Enable Mic
            localAudioTrack = await LkSdk.createLocalAudioTrack();
            await activeRoom.localParticipant.publishTrack(localAudioTrack);

            showActiveCallWindow('Connected', false);
            startCallTimer();

            // Render existing participants
            activeRoom.remoteParticipants.forEach(function (p) {
                addParticipantCard(p);
            });

        } catch (err) {
            console.error('LiveKit connection error:', err);
            alert('Calling service is currently unavailable (could not connect to LiveKit media server).');
            hangupCall();
        }
    }

    // Track Subscription Handlers
    function handleTrackSubscribed(track, participant) {
        const LkSdk = window.LiveKit || window.LivekitClient;
        if (LkSdk && track.kind === LkSdk.Track.Kind.Audio) {
            // Attach audio stream
            const el = track.attach();
            document.body.appendChild(el);
        } else if (LkSdk && track.kind === LkSdk.Track.Kind.Video) {
            // Screen sharing track
            const mainContainer = document.getElementById('btCallViewport');
            if (mainContainer) {
                mainContainer.innerHTML = '';
                const videoEl = track.attach();
                videoEl.className = 'bt-call-screen-share-video';
                videoEl.autoplay = true;
                const wrap = document.createElement('div');
                wrap.className = 'bt-call-screen-share-wrap';
                wrap.appendChild(videoEl);
                mainContainer.appendChild(wrap);
            }
        }
    }

    function handleTrackUnsubscribed(track, participant) {
        const LkSdk = window.LiveKit || window.LivekitClient;
        track.detach().forEach(el => el.remove());
        if (LkSdk && track.kind === LkSdk.Track.Kind.Video) {
            // Restore participant grid
            restoreParticipantGrid();
        }
    }

    function restoreParticipantGrid() {
        const mainContainer = document.getElementById('btCallViewport');
        if (!mainContainer) return;
        mainContainer.innerHTML = '<div class="bt-call-main-grid" id="btCallMainGrid"></div>';
        if (activeRoom) {
            activeRoom.remoteParticipants.forEach(p => addParticipantCard(p));
        }
    }

    function handleSpeakersChanged(speakers) {
        document.querySelectorAll('.bt-call-participant-card').forEach(function (card) {
            card.classList.remove('speaking');
        });
        speakers.forEach(function (s) {
            const card = document.getElementById(`participant-${s.identity}`);
            if (card) card.classList.add('speaking');
        });
    }

    function addParticipantCard(participant) {
        const grid = document.getElementById('btCallMainGrid');
        if (!grid) return;

        // Remove old card if exists
        removeParticipantCard(participant);

        const card = document.createElement('div');
        card.className = 'bt-call-participant-card';
        card.id = `participant-${participant.identity}`;
        card.innerHTML = `
            <div class="bt-call-avatar-container" style="width: 64px; height: 64px; font-size: 20px;">
                ${(participant.name || 'P').charAt(0).toUpperCase()}
            </div>
            <div class="bt-call-participant-name">${participant.name || 'Participant'}</div>
        `;
        grid.appendChild(card);
    }

    function removeParticipantCard(participant) {
        const card = document.getElementById(`participant-${participant.identity}`);
        if (card) card.remove();
    }

    // Timer functions
    function startCallTimer() {
        callDuration = 0;
        const timerLabel = document.getElementById('btCallTimer');
        if (callTimer) clearInterval(callTimer);
        callTimer = setInterval(function () {
            callDuration++;
            const m = Math.floor(callDuration / 60).toString().padStart(2, '0');
            const s = (callDuration % 60).toString().padStart(2, '0');
            if (timerLabel) timerLabel.innerText = `${m}:${s}`;
        }, 1000);
    }

    function stopCallTimer() {
        if (callTimer) {
            clearInterval(callTimer);
            callTimer = null;
        }
    }

    // Hangup and teardown
    async function hangupCall() {
        stopCallTimer();
        stopRingtone();

        if (localAudioTrack) {
            localAudioTrack.stop();
            localAudioTrack = null;
        }
        if (localScreenTrack) {
            localScreenTrack.stop();
            localScreenTrack = null;
        }
        if (activeRoom) {
            activeRoom.disconnect();
            activeRoom = null;
        }

        if (currentCall) {
            try {
                const action = currentCall.role === 'host' ? 'end' : 'leave';
                await fetch(`${prefix}api/call_api.php?action=${action}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ uuid: currentCall.uuid })
                });
            } catch (e) {
                console.warn('Hangup notification failed:', e);
            }
        }

        currentCall = null;
        const windowEl = document.getElementById('btCallActiveWindow');
        if (windowEl) windowEl.remove();
    }

    function handleLiveKitDisconnect() {
        console.warn('Disconnected from LiveKit Room');
        hangupCall();
    }

    // Call Signaling events
    function handleCallAccepted(payload) {
        if (currentCall && currentCall.status === 'initiated') {
            currentCall.status = 'active';
            const label = document.getElementById('btCallStatusLabel');
            if (label) label.innerText = 'Connected';
            startCallTimer();
        }
    }

    function handleCallDeclined(payload) {
        if (currentCall && currentCall.role === 'host') {
            console.log('Call declined by:', payload.user_name);
        }
    }

    function handleCallCancelled(payload) {
        if (currentCall && currentCall.uuid === payload.uuid) {
            hangupCall();
        }
    }

    function handleCallEnded(payload) {
        if (currentCall && currentCall.uuid === payload.uuid) {
            hangupCall();
        }
    }

    function handleParticipantLeft(payload) {
        console.log('Participant left:', payload.user_name);

        // Direct 1-to-1 call: close the host side when the other person leaves
        if (
            currentCall &&
            currentCall.role === 'host' &&
            currentCall.uuid === payload.uuid
        ) {
            hangupCall();
        }
    }

    function handleRecipientBusy(payload) {
        if (currentCall && currentCall.role === 'host') {
            console.log('Recipient busy:', payload.user_id);
        }
    }

    // Toggle local mic
    async function toggleMute() {
        if (!activeRoom) return;
        const micBtn = document.getElementById('btnToggleMic');
        const enabled = localAudioTrack.isMuted;

        if (enabled) {
            await localAudioTrack.unmute();
            if (micBtn) {
                micBtn.innerHTML = '<i class="fas fa-microphone"></i>';
                micBtn.classList.remove('active');
            }
        } else {
            await localAudioTrack.mute();
            if (micBtn) {
                micBtn.innerHTML = '<i class="fas fa-microphone-slash"></i>';
                micBtn.classList.add('active');
            }
        }
    }

    // Toggle Screen Share
    async function toggleScreenShare() {
        if (!activeRoom) return;
        const screenBtn = document.getElementById('btnToggleScreen');

        if (localScreenTrack) {
            // Stop sharing
            await activeRoom.localParticipant.unpublishTrack(localScreenTrack);
            localScreenTrack.stop();
            localScreenTrack = null;
            if (screenBtn) screenBtn.classList.remove('active');
            restoreParticipantGrid();
            try {
                await fetch(prefix + 'api/call_api.php?action=screenShareStopped', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ uuid: currentCall.uuid })
                });
            } catch (e) {}
        } else {
            // Start sharing
            try {
                const LkSdk = window.LiveKit || window.LivekitClient;
                if (!LkSdk) return;
                const tracks = await LkSdk.createLocalScreenTracks({ audio: false });
                localScreenTrack = tracks[0];
                await activeRoom.localParticipant.publishTrack(localScreenTrack);
                if (screenBtn) screenBtn.classList.add('active');
                
                // Monitor browser-level "stop sharing"
                localScreenTrack.mediaStreamTrack.onended = function () {
                    toggleScreenShare();
                };

                try {
                    await fetch(prefix + 'api/call_api.php?action=screenShareStarted', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ uuid: currentCall.uuid })
                    });
                } catch (e) {}
            } catch (err) {
                console.warn('Screen share permission denied:', err);
            }
        }
    }

    // Render Active Call overlay window
    function showActiveCallWindow(statusLabel, isInitiator) {
        const oldWindow = document.getElementById('btCallActiveWindow');
        if (oldWindow) oldWindow.remove();

        const windowEl = document.createElement('div');
        windowEl.className = 'bt-call-active-window bt-call-shell-root';
        windowEl.id = 'btCallActiveWindow';
        windowEl.innerHTML = `
            <header class="bt-call-header">
                <div class="bt-call-title-wrap">
                    <span class="bt-call-badge">Audio</span>
                    <span id="btCallStatusLabel">${statusLabel}</span>
                    <span id="btCallTimer" style="margin-left: 12px; font-weight: 600;">00:00</span>
                </div>
                <div class="bt-call-actions-header">
                    <button class="bt-call-btn-icon" id="btnMinimizeCall" title="Minimize"><i class="fas fa-compress"></i></button>
                </div>
            </header>
            <div class="bt-call-viewport" id="btCallViewport">
                <div class="bt-call-main-grid" id="btCallMainGrid"></div>
                <div class="bt-call-device-menu" id="btCallDeviceMenu" style="display: none;">
                    <div class="bt-call-device-item">
                        <label>Microphone</label>
                        <select id="btCallMicSelect" class="bt-call-device-select"></select>
                    </div>
                    <div class="bt-call-device-item">
                        <label>Speaker</label>
                        <select id="btCallSpeakerSelect" class="bt-call-device-select"></select>
                    </div>
                </div>
            </div>
            <div class="bt-call-toolbar-container">
                <button class="bt-call-toolbar-btn" id="btnToggleMic" title="Mute/Unmute Mic"><i class="fas fa-microphone"></i></button>
                <button class="bt-call-toolbar-btn" id="btnToggleScreen" title="Share Screen"><i class="fas fa-desktop"></i></button>
                <button class="bt-call-toolbar-btn" id="btnDeviceSettings" title="Device Settings"><i class="fas fa-cog"></i></button>
                <button class="bt-call-toolbar-btn bt-call-toolbar-btn-hangup" id="btnHangupCall" title="Leave Call"><i class="fas fa-phone-slash"></i></button>
            </div>
        `;

        document.body.appendChild(windowEl);

        document.getElementById('btnHangupCall').onclick = hangupCall;
        document.getElementById('btnToggleMic').onclick = toggleMute;
        document.getElementById('btnToggleScreen').onclick = toggleScreenShare;

        const deviceBtn = document.getElementById('btnDeviceSettings');
        const deviceMenu = document.getElementById('btCallDeviceMenu');
        deviceBtn.onclick = async function () {
            if (deviceMenu.style.display === 'none') {
                deviceMenu.style.display = 'block';
                await populateDevices();
            } else {
                deviceMenu.style.display = 'none';
            }
        };

        async function populateDevices() {
            try {
                const devices = await navigator.mediaDevices.enumerateDevices();
                const micSelect = document.getElementById('btCallMicSelect');
                const speakerSelect = document.getElementById('btCallSpeakerSelect');
                
                micSelect.innerHTML = '';
                speakerSelect.innerHTML = '';
                
                // Audio Inputs
                devices.filter(d => d.kind === 'audioinput').forEach(d => {
                    const opt = document.createElement('option');
                    opt.value = d.deviceId;
                    opt.text = d.label || `Microphone (${d.deviceId.slice(0, 5)})`;
                    micSelect.appendChild(opt);
                });
                if (micSelect.options.length === 0) {
                    const opt = document.createElement('option');
                    opt.value = 'default';
                    opt.text = 'Default Microphone';
                    micSelect.appendChild(opt);
                }

                // Audio Outputs (Speakers)
                devices.filter(d => d.kind === 'audiooutput').forEach(d => {
                    const opt = document.createElement('option');
                    opt.value = d.deviceId;
                    opt.text = d.label || `Speaker (${d.deviceId.slice(0, 5)})`;
                    speakerSelect.appendChild(opt);
                });
                if (speakerSelect.options.length === 0) {
                    const opt = document.createElement('option');
                    opt.value = 'default';
                    opt.text = 'Default Speaker';
                    speakerSelect.appendChild(opt);
                }
                
                // Select currently active devices if possible
                if (activeRoom) {
                    const currentMic = activeRoom.getActiveDevice('audioinput');
                    if (currentMic) micSelect.value = currentMic;
                    
                    const currentSpeaker = activeRoom.getActiveDevice('audiooutput');
                    if (currentSpeaker) speakerSelect.value = currentSpeaker;
                }
                
                micSelect.onchange = async function () {
                    if (activeRoom) {
                        await activeRoom.switchActiveDevice('audioinput', micSelect.value);
                    }
                };
                
                speakerSelect.onchange = async function () {
                    if (activeRoom) {
                        await activeRoom.switchActiveDevice('audiooutput', speakerSelect.value);
                    }
                };
            } catch (e) {
                console.warn('Could not list media devices:', e);
            }
        }

        const minimizeBtn = document.getElementById('btnMinimizeCall');
        minimizeBtn.onclick = function () {
            const isMin = windowEl.classList.toggle('minimized');
            minimizeBtn.innerHTML = isMin ? '<i class="fas fa-expand"></i>' : '<i class="fas fa-compress"></i>';
        };
    }

    // Listen for postMessage triggers from inside the Chat Portal iframe
    window.addEventListener('message', function (event) {
        if (event.origin !== window.location.origin) return;
        const msg = event.data;
        if (!msg) return;

        if (msg.type === 'bt-call-request' && msg.conversation_id) {
            startRtcCall(msg.conversation_id);
        }
    });

    // Start WebSocket listener on load only if NOT embedded in an iframe
    const isEmbedded = (window.self !== window.top);
    if (!isEmbedded) {
        initWebSocketSignaling();
    }
})();
