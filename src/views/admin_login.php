<?php
/**
 * src/views/admin_login.php
 *
 * Receives:
 *   $error (optional) — error message to display
 *   $next  (optional) — URL to redirect to after successful login
 */
$error = $error ?? null;
$next  = $next  ?? '/admin';
?>
<div class="admin-login">
    <h1>Sign in to Admin</h1>
    <p class="admin-login__lede">This area is restricted to site administrators.</p>

    <?php if ($error): ?>
        <p class="form__error" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <form action="/admin/login" method="post" class="form js-once">
        <?= csrf_field() ?>
        <input type="hidden" name="next" value="<?= e($next) ?>">

        <label class="form__label" for="password">Password</label>
        <input
            class="form__input"
            type="password"
            id="password"
            name="password"
            autocomplete="current-password"
            autofocus
            required>

        <button type="submit" class="admin-btn admin-btn--primary" data-busy-text="Signing in…">Sign in</button>
    </form>
</div>
