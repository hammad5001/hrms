/** Load floating chat button — add before </body> on any portal page */
(function () {
    const s = document.createElement('script');
    const inAttendance = /\/attendance\//i.test(window.location.pathname);
    s.src = (inAttendance ? '..' : '.') + '/js/portal-chat-fab.js';
    document.body.appendChild(s);
})();
