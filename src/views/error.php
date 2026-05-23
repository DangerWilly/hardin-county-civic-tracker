<?php
/**
 * src/views/error.php — generic error view (403, 429, etc).
 *
 * Receives:
 *   $title    — page <title>, e.g. "Forbidden" or "Too Many Requests"
 *   $heading  — short headline shown in the article body
 *   $message  — one-paragraph explanation
 */
?>
<main class="container">
    <section class="stub">
        <p class="stub__ornament">&sect;</p>
        <p class="stub__kicker"><?= e($title) ?></p>
        <h1><?= e($heading) ?></h1>
        <p class="stub__dek"><?= e($message) ?></p>
        <p class="stub__back"><a href="/">&larr; Back to the front page</a></p>
    </section>
</main>
