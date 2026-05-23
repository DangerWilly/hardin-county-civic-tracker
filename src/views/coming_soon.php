<?php
/**
 * src/views/coming_soon.php
 *
 * Receives:
 *   $title — section name (e.g. "Meetings")
 *   $blurb — one-paragraph description of what'll go here
 */
?>
<main class="container">
    <section class="stub">
        <p class="stub__ornament">&para;</p>
        <p class="stub__kicker">In the works</p>
        <h1><?= e($title) ?></h1>
        <p class="stub__dek"><?= e($blurb) ?></p>
        <p class="stub__back"><a href="/">&larr; Back to the front page</a></p>
    </section>
</main>
