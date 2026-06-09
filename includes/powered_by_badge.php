<?php
/**
 * GO CONNECTIVO powered-by badge — include on admin and other PHP pages.
 * @param string $modifier Optional: 'login' | 'admin' | '' (default)
 */
$gc_pb_mod = $gc_pb_mod ?? '';
$gc_pb_class = 'gc-powered-wrap';
if ($gc_pb_mod === 'login') {
    $gc_pb_class .= ' gc-powered-wrap--login';
} elseif ($gc_pb_mod === 'admin') {
    $gc_pb_class .= ' gc-powered-wrap--admin';
}
?>
<div class="<?php echo htmlspecialchars($gc_pb_class); ?>">
    <div class="gc-powered-badge" role="contentinfo" aria-label="Powered by GO CONNECTIVO">
        <span class="gc-powered-label">Powered by</span>
        <span class="gc-powered-divider" aria-hidden="true"></span>
        <span class="gc-powered-brand">
            <img src="assets/images/go-connectivo-logo.png" alt="GO CONNECTIVO" class="gc-powered-logo" width="180" height="36" loading="lazy" decoding="async">
        </span>
    </div>
</div>
