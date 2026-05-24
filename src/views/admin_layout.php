<?php
/**
 * src/views/admin_layout.php
 *
 * Receives via view():
 *   $title    — page <title>
 *   $content  — already-rendered inner HTML from the admin view
 *   $section  — optional, marks the active nav item
 *
 * Admin UI is deliberately plain — minimal CSS, no decorative typography.
 * The point is that you should instantly know "I'm in the admin, not the
 * public site" without staring at the URL bar.
 */
$section = $section ?? '';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> · Admin · The Hardin Civic</title>
<link rel="stylesheet" href="/assets/css/site.css?v=2">
<link rel="stylesheet" href="/assets/css/admin.css?v=1">
</head>
<body class="admin-body">

<header class="admin-bar">
    <a href="/admin" class="admin-bar__brand">⚙ Admin</a>
    <nav class="admin-bar__nav">
        <a href="/admin/bodies"   class="<?= $section === 'bodies'   ? 'is-active' : '' ?>">Bodies</a>
        <a href="/admin/meetings" class="<?= $section === 'meetings' ? 'is-active' : '' ?>">Meetings</a>
    </nav>
    <div class="admin-bar__right">
        <a href="/" class="admin-bar__public">View public site &rarr;</a>
        <form action="/admin/logout" method="post" class="admin-bar__logout">
            <?= csrf_field() ?>
            <button type="submit">Log out</button>
        </form>
    </div>
</header>

<main class="admin-main">
    <?= $content ?>
</main>

</body>
</html>
