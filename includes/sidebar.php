<?php
/**
 * Desktop sidebar — rendered from includes/nav.php (single source of truth).
 * Expects $activePage to be set by the page (e.g. 'dashboard' | 'index' | ...).
 */
require_once __DIR__ . '/nav.php';
$activePage = $activePage ?? '';
$navItems = nav_items();
$navFootItems = nav_foot_items();
?>
<aside class="sidebar">
    <div class="brand"><img class="brand-logo" src="assets/logo.png" alt="JBWizerd"></div>
    <nav>
        <?php foreach ($navItems as $page => [$label, $icon, $adminOnly]): ?>
            <?php if ($adminOnly && !(function_exists('is_admin') && is_admin())) continue; ?>
            <a href="<?= e($page) ?>.php" class="<?= $activePage === $page ? 'active' : '' ?>" <?= $activePage === $page ? 'aria-current="page"' : '' ?>>
                <?= $icon ?><span><?= e($label) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-foot">
        <div class="profile-box">
            <div class="profile-meta">
                <div class="profile-name"><?= e(current_user()['username']) ?></div>
                <div class="profile-date"><?= e(date('l, F j, Y')) ?></div>
            </div>
        </div>
        <?php foreach ($navFootItems as $page => [$label, $icon, $adminOnly]): ?>
            <a href="<?= e($page) ?>.php" class="side-link<?= $activePage === $page ? ' active' : '' ?>" title="<?= e($label) ?>">
                <?= $icon ?> <?= e($label) ?>
            </a>
        <?php endforeach; ?>
        <div class="theme-select" data-theme-select>
            <button type="button" data-mode="auto" title="Follow system theme">Auto</button>
            <button type="button" data-mode="dark" title="Dark mode">☾</button>
            <button type="button" data-mode="light" title="Light mode">☀</button>
        </div>
        <div class="profile-actions">
            <a href="logout.php" class="logout-btn" title="Logout">Logout</a>
        </div>
    </div>
</aside>
