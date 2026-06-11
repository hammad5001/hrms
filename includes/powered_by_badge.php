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
            <img src="assets/images/go-connectivo-logo.png" alt="GO CONNECTIVO" class="gc-powered-logo-img" decoding="async">
        </span>
    </div>
</div>
