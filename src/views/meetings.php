<?php
/**
 * src/views/meetings.php — public-facing meetings list.
 *
 * Receives:
 *   $upcoming — array of meeting rows with body_name + item_count (status=scheduled, in future)
 *   $recent   — array of meeting rows (status=completed, in past, limited)
 */
?>
<main class="container">

    <section class="article" style="max-width: 920px;">
        <p class="article__kicker">The Calendar</p>
        <h1>Public Meetings</h1>
        <p class="lede__dek" style="text-align:left; margin: 0 0 1.5rem;">
            Council, commission, board, and trustee meetings for Hardin County and Kenton.
            Every record links to the official agenda when one is posted.
        </p>

        <h2 class="meetings__group">Upcoming</h2>
        <?php if (empty($upcoming)): ?>
            <p class="meetings__empty">No upcoming meetings on the calendar yet. <a href="/submit-correction">Tell us about one &rarr;</a></p>
        <?php else: ?>
            <ul class="meetings__list">
                <?php foreach ($upcoming as $m): ?>
                    <li class="meetings__row">
                        <div class="meetings__when">
                            <span class="meetings__date"><?= e(date('M j', (int) $m['starts_at'])) ?></span>
                            <span class="meetings__time"><?= e(date('g:i A', (int) $m['starts_at'])) ?></span>
                            <span class="meetings__year"><?= e(date('Y', (int) $m['starts_at'])) ?></span>
                        </div>
                        <div class="meetings__body">
                            <p class="meetings__body-name"><?= e($m['body_name']) ?></p>
                            <h3 class="meetings__title">
                                <a href="/meetings/<?= (int) $m['id'] ?>"><?= e($m['title'] ?? 'Regular Meeting') ?></a>
                            </h3>
                            <p class="meetings__meta">
                                <?php if ($m['location_name']): ?>
                                    <?= e($m['location_name']) ?> &middot;
                                <?php endif; ?>
                                <?= (int) $m['item_count'] ?> agenda item<?= (int) $m['item_count'] === 1 ? '' : 's' ?>
                            </p>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if (!empty($recent)): ?>
            <h2 class="meetings__group">Recent</h2>
            <ul class="meetings__list">
                <?php foreach ($recent as $m): ?>
                    <li class="meetings__row meetings__row--past">
                        <div class="meetings__when">
                            <span class="meetings__date"><?= e(date('M j', (int) $m['starts_at'])) ?></span>
                            <span class="meetings__year"><?= e(date('Y', (int) $m['starts_at'])) ?></span>
                        </div>
                        <div class="meetings__body">
                            <p class="meetings__body-name"><?= e($m['body_name']) ?></p>
                            <h3 class="meetings__title">
                                <a href="/meetings/<?= (int) $m['id'] ?>"><?= e($m['title'] ?? 'Regular Meeting') ?></a>
                            </h3>
                            <p class="meetings__meta"><?= (int) $m['item_count'] ?> agenda item<?= (int) $m['item_count'] === 1 ? '' : 's' ?></p>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

    </section>
</main>
