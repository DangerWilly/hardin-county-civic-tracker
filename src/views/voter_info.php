<?php
/**
 * src/views/voter_info.php
 *
 * Receives:
 *   $page — content_pages row (with body_md as markdown)
 */
?>
<main class="container">
    <article class="article">
        <p class="article__kicker"><?= e($page['jurisdiction_name']) ?></p>
        <h1><?= e($page['title']) ?></h1>
        <div class="article__body">
            <?= md_to_html($page['body_md']) ?>
        </div>
        <p class="article__meta">
            Last updated <?= e(date('M j, Y', (int) $page['updated_at'])) ?> &middot;
            Source: <?= e($page['source']) ?>
        </p>
    </article>
</main>
