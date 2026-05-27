<?php
/**
 * src/views/submit_correction.php
 *
 * Receives:
 *   $error  (optional) — validation error message
 *   $old    (optional) — repopulate the form after validation failure
 *                        e.g. ['what' => '...', 'where' => '...']
 */

$old   = $old   ?? ['what' => '', 'where' => ''];
$error = $error ?? null;
?>
<main class="container">
    <section class="article">
        <p class="article__kicker">Reader Feedback</p>
        <h1>Submit a correction</h1>
        <p class="stub__dek" style="text-align:left; margin-bottom: 2rem;">
            Spotted a bad phone number, an outdated meeting time, a misspelled name? Tell us. Corrections go to a human moderator &mdash; nothing publishes unattended.
        </p>

        <?php if ($error): ?>
            <p class="form__error" role="alert"><?= e($error) ?></p>
        <?php endif; ?>

        <form class="form js-once" action="/submit-correction" method="post" novalidate>
            <?= csrf_field() ?>

            <label class="form__label" for="where">Where on the site?</label>
            <input
                class="form__input"
                type="text"
                id="where"
                name="where"
                value="<?= e($old['where']) ?>"
                maxlength="200"
                required
                placeholder="e.g. /voter-info, or 'Hardin County BoE phone'">

            <label class="form__label" for="what">What needs correcting?</label>
            <textarea
                class="form__input"
                id="what"
                name="what"
                rows="6"
                maxlength="2000"
                required
                placeholder="The phone number listed is the old number. The new BoE number is..."><?= e($old['what']) ?></textarea>

            <p class="form__hint">We don&rsquo;t store your name or email. We hash your IP for spam protection only.</p>

            <button type="submit" class="ask__btn" data-busy-text="Submitting…">Submit correction</button>
        </form>
    </section>
</main>
