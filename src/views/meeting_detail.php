<?php
/**
 * src/views/meeting_detail.php
 *
 * Receives:
 *   $meeting — full meeting row with body_name joined
 *   $items   — array of agenda_items, ordered by position
 */
$is_past = (int) $meeting['starts_at'] < time();
?>
<main class="container">

    <article class="article" style="max-width: 760px;">
        <p class="article__kicker"><?= e($meeting['body_name']) ?></p>
        <h1><?= e($meeting['title'] ?? 'Regular Meeting') ?></h1>

        <p class="meeting__when">
            <strong><?= e(date('l, F j, Y', (int) $meeting['starts_at'])) ?></strong>
            at <?= e(date('g:i A', (int) $meeting['starts_at'])) ?>
            <?php if ($meeting['location_name'] || $meeting['address']): ?>
                <br><span class="meeting__where">
                    <?= e(trim(($meeting['location_name'] ?? '') . ($meeting['address'] ? ' · ' . $meeting['address'] : ''), ' ·')) ?>
                </span>
            <?php endif; ?>
        </p>

        <?php if (in_array($meeting['status'], ['cancelled','live'], true)): ?>
            <p class="meeting__status meeting__status--<?= e($meeting['status']) ?>">
                <?= e(strtoupper($meeting['status'])) ?>
            </p>
        <?php endif; ?>

        <?php if ($meeting['summary']): ?>
            <div class="meeting__summary">
                <p class="article__kicker" style="margin-bottom: 0.6rem;">AI Summary</p>
                <?= md_to_html($meeting['summary']) ?>
            </div>
        <?php endif; ?>

        <?php $links = array_filter([
            'Official agenda (PDF)' => $meeting['agenda_url'],
            'Approved minutes'      => $meeting['minutes_url'],
            'Recording'             => $meeting['video_url'],
            'Livestream / Zoom'     => $meeting['remote_url'],
        ]); ?>
        <?php if (!empty($links)): ?>
            <ul class="meeting__links">
                <?php foreach ($links as $label => $url): ?>
                    <li><a href="<?= e($url) ?>" rel="noopener noreferrer">&rarr; <?= e($label) ?></a></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <h2 class="meeting__h2">Agenda</h2>
        <?php if (empty($items)): ?>
            <p class="meeting__empty">No agenda items have been entered for this meeting yet.</p>
        <?php else: ?>
            <ol class="agenda">
                <?php foreach ($items as $item): ?>
                    <li class="agenda__item">
                        <?php if ($item['item_number']): ?>
                            <span class="agenda__num"><?= e($item['item_number']) ?></span>
                        <?php endif; ?>
                        <h3 class="agenda__title"><?= e($item['title']) ?></h3>
                        <?php if ($item['summary']): ?>
                            <div class="agenda__summary"><?= md_to_html($item['summary']) ?></div>
                        <?php elseif ($item['body_md']): ?>
                            <div class="agenda__body"><?= md_to_html($item['body_md']) ?></div>
                        <?php endif; ?>
                        <?php if ($item['outcome']): ?>
                            <p class="agenda__outcome">Outcome: <?= e($item['outcome']) ?></p>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>

        <?php if ($meeting['notes_md']): ?>
            <h2 class="meeting__h2">Editor's notes</h2>
            <div class="article__body"><?= md_to_html($meeting['notes_md']) ?></div>
        <?php endif; ?>

        <p class="article__meta">
            <?= $is_past ? 'Meeting concluded' : 'Scheduled' ?> &middot;
            Last updated <?= e(date('M j, Y', (int) $meeting['updated_at'])) ?> &middot;
            Source: <?= e($meeting['source']) ?>
        </p>
    </article>
</main>
