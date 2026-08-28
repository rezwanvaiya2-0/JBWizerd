<?php
/**
 * Mobile header + hamburger menu + bottom tab bar.
 * Shown only on small screens (CSS). Expects $activePage to be set.
 * Renders from includes/nav.php (single source of truth).
 */
require_once __DIR__ . '/nav.php';
$activePage = $activePage ?? '';
$navItems = nav_items();
$navFootItems = nav_foot_items();
?>
<div class="mobile-header">
    <div class="brand"><img class="brand-logo" src="assets/logo.png" alt="JBWizerd"></div>
    <button type="button" class="hamburger" id="btn-menu" aria-label="Toggle menu" aria-expanded="false">
        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <line x1="3" y1="6" x2="21" y2="6"></line>
            <line x1="3" y1="12" x2="21" y2="12"></line>
            <line x1="3" y1="18" x2="21" y2="18"></line>
        </svg>
    </button>
</div>
<nav class="mobile-menu" id="mobile-menu" hidden>
    <?php foreach ($navFootItems as $page => [$label, $icon, $adminOnly]): ?>
        <?php if ($adminOnly && !(function_exists('is_admin') && is_admin())) continue; ?>
        <a href="<?= e($page) ?>.php" class="<?= $activePage === $page ? 'active' : '' ?>">
            <span class="mm-dot"></span><?= e($label) ?>
        </a>
    <?php endforeach; ?>
    <div class="mobile-profile">
        <div class="profile-box">
            <div class="profile-meta">
                <div class="profile-name"><?= e(current_user()['username']) ?></div>
                <div class="profile-date"><?= e(date('l, F j, Y')) ?></div>
            </div>
        </div>
        <div class="theme-select" data-theme-select>
            <button type="button" data-mode="auto" title="Follow system theme">Auto</button>
            <button type="button" data-mode="dark" title="Dark mode">☾</button>
            <button type="button" data-mode="light" title="Light mode">☀</button>
        </div>
        <a href="logout.php" class="logout-btn" title="Logout">Logout</a>
    </div>
</nav>

<!-- Bottom tab bar (mobile navigation) -->
<nav class="tabbar" id="tabbar" aria-label="Main navigation">
    <?php foreach ($navItems as $page => [$label, $icon, $adminOnly]): ?>
        <a href="<?= e($page) ?>.php" class="tabbar-item <?= $activePage === $page ? 'active' : '' ?>" <?= $activePage === $page ? 'aria-current="page"' : '' ?>>
            <?= $icon ?>
            <span class="tabbar-label"><?= e($label) ?></span>
        </a>
    <?php endforeach; ?>
</nav>
