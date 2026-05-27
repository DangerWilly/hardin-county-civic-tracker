<?php
/**
 * src/views/layout.php — wraps every rendered view.
 *
 * Receives via the view() helper:
 *   $title    — page <title> (already escaped at use site)
 *   $content  — pre-rendered HTML from the inner view (already trusted)
 */
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#7A2820">
<meta name="description" content="Independent civic information for Hardin County and the City of Kenton, Ohio.">

<title><?= e($title) ?> · The Hardin Civic</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght,SOFT@9..144,400;9..144,500;9..144,600;9..144,800,..100&family=Public+Sans:wght@400;500;600;700&display=swap">

<link rel="stylesheet" href="/assets/css/site.css?v=1">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' fill='%23F5F1E8'/%3E%3Ctext x='50%25' y='50%25' text-anchor='middle' dy='.35em' font-family='Georgia,serif' font-weight='800' font-size='44' fill='%237A2820'%3EH%3C/text%3E%3C/svg%3E">
</head>
<body>

<header class="masthead">
    <div class="masthead__bar">
        <span class="masthead__edition">Vol. I &middot; <?= e(date('l, F j, Y')) ?></span>
        <span class="masthead__locale">Hardin Co. &middot; Kenton, OH</span>
    </div>

    <a class="masthead__title" href="/">The Hardin Civic</a>
    <p class="masthead__tag">Independent civic information for Hardin County and the City of Kenton, Ohio.</p>

    <nav class="masthead__nav" aria-label="Primary">
        <a href="/">Home</a>
        <a href="/voter-info">Voter Info</a>
        <a href="/meetings">Meetings</a>
        <a href="/bills">Bills</a>
        <a href="/representatives">Representatives</a>
    </nav>
</header>

<?= $content ?>

<footer class="footer">
    <div class="footer__rule" aria-hidden="true">&mdash; &middot; &mdash;</div>
    <p>This site is independent of any government. Data is sourced from public records, official APIs, and corrections from neighbors.</p>
    <p class="footer__meta">
        Spotted an error? <a href="/submit-correction">Submit a correction.</a>
        &nbsp;&middot;&nbsp;
        <a href="/healthz">Status</a>
    </p>
</footer>

<script src="/assets/js/site.js?v=2" defer></script>
</body>
</html>
