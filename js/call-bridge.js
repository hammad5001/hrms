// js/call-bridge.js - Chat-side integration bridge for starting RTC calls.

(function () {
    function injectCallButton() {
        const headerActions = document.querySelector('.chat-header-actions');
        if (!headerActions) return;

        // Check if button is already injected
        if (document.getElementById('btnStartRtcCall')) return;

        // Create the call button
        const callBtn = document.createElement('button');
        callBtn.type = 'button';
        callBtn.className = 'header-action-btn bt-call-start-btn';
        callBtn.id = 'btnStartRtcCall';
        callBtn.title = 'Start audio call';
        callBtn.innerHTML = '<i class="fas fa-phone"></i>';
        callBtn.style.marginRight = '8px';

        callBtn.addEventListener('click', function () {
            if (typeof Chat !== 'undefined' && Chat.activeId) {
                // Send call request to parent window (main HRMS portal shell)
                window.parent.postMessage({
                    type: 'bt-call-request',
                    conversation_id: Chat.activeId
                }, window.location.origin);
            }
        });

        // Insert before search button or first child
        const btnSearch = document.getElementById('btnSearchInChat');
        if (btnSearch) {
            headerActions.insertBefore(callBtn, btnSearch);
        } else {
            headerActions.appendChild(callBtn);
        }
    }

    // Monitor conversation selection updates
    setInterval(function () {
        if (typeof Chat !== 'undefined' && Chat.activeId) {
            injectCallButton();
        } else {
            // Remove button if no active conversation
            const btn = document.getElementById('btnStartRtcCall');
            if (btn) btn.remove();
        }
    }, 1000);
})();
