<?php
/**
 * GO CONNECTIVO powered-by badge — include on admin and other PHP pages.
 * @param string $modifier Optional: 'login' | 'admin' | 'portal' | '' (default)
 */
$gc_pb_mod = $gc_pb_mod ?? '';
$gc_pb_class = 'gc-powered-wrap';
if ($gc_pb_mod === 'login') {
    $gc_pb_class .= ' gc-powered-wrap--login';
} elseif ($gc_pb_mod === 'admin') {
    $gc_pb_class .= ' gc-powered-wrap--admin';
} elseif ($gc_pb_mod === 'portal') {
    $gc_pb_class .= ' gc-powered-wrap--portal';
}
?>
<div class="<?php echo htmlspecialchars($gc_pb_class); ?>">
    <div class="gc-powered-badge" role="contentinfo" aria-label="Powered by GO CONNECTIVO">
        <span class="gc-powered-eyebrow">Powered by</span>
        <span class="gc-powered-lockup" aria-hidden="true">
            <svg class="gc-powered-mark" viewBox="0 0 44 44" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M30 22c0 4.4-3.6 8-8 8" stroke="currentColor" stroke-width="2.8" stroke-linecap="round"/>
                <path d="M32 22c0 5.5-4.5 10-10 10" stroke="currentColor" stroke-width="2.8" stroke-linecap="round"/>
                <path d="M34 22c0 6.6-5.4 12-12 12" stroke="currentColor" stroke-width="2.8" stroke-linecap="round"/>
            </svg>
            <span class="gc-powered-wordmark">
                <span class="gc-powered-go">GO</span>
                <span class="gc-powered-name">CONNECTIVO</span>
            </span>
        </span>
    </div>
</div>
