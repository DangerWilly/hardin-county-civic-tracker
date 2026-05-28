<?php
/**
 * src/views/bill_detail.php
 *
 * Receives:
 *   $bill         — full bill row
 *   $actions      — array of bill_actions rows, oldest first
 *   $sponsors     — array of bill_sponsorships rows; each may have linked official_id + full_name
 */
$subjects = [];
if (!empty($bill['subject'])) {
    $decoded = json_decode((string) $bill['subject'], true);
    if (is_array($decoded)) $subjects = $decoded;
}

$prefix = strtoupper(strtok((string) $bill['identifier'], ' '));
$kind = match (true) {
    str_starts_with($prefix, 'HB') => 'House Bill',
    str_starts_with($prefix, 'SB') => 'Senate Bill',
    str_starts_with($prefix, 'HR') => 'House Resolution',
    str_starts_with($prefix, 'SR') => 'Senate Resolution',
    str_starts_with($prefix, 'HJR'), str_starts_with($prefix, 'SJR') => 'Joint Resolution',
    str_starts_with($prefix, 'HCR'), str_starts_with($prefix, 'SCR') => 'Concurrent Resolution',
    default => 'Bill',
};
?>
<main class="container">
    <article class="article" style="max-width: 760px;">
        <p class="article__kicker"><?= e($kind) ?> &middot; Ohio General Assembly, Session <?= e($bill['session']) ?></p>
        <h1><?= e($bill['identifier']) ?>: <?= e($bill['title']) ?></h1>

        <?php if (!empty($bill['summary_md'])): ?>
            <div class="meeting__summary">
                <p class="article__kicker" style="margin-bottom: 0.6rem;">AI Summary</p>
                <?= md_to_html($bill['summary_md']) ?>
            </div>
        <?php elseif (!empty($bill['abstract'])): ?>
            <div class="article__body" style="margin-top: 1rem;">
                <p style="font-style: italic; color: var(--ink-soft);"><?= e($bill['abstract']) ?></p>
            </div>
        <?php endif; ?>

        <?php if ($subjects): ?>
            <p class="bill__subjects">
                <?php foreach ($subjects as $s): ?>
                    <span class="bill__tag"><?= e($s) ?></span>
                <?php endforeach; ?>
            </p>
        <?php endif; ?>

        <?php $links = array_filter([
            'OpenStates record' => $bill['openstates_url'],
            'Official source'   => $bill['source_url'],
        ]); ?>
        <?php if (!empty($links)): ?>
            <ul class="meeting__links">
                <?php foreach ($links as $label => $url): ?>
                    <li><a href="<?= e($url) ?>" rel="noopener noreferrer">&rarr; <?= e($label) ?></a></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if (!empty($sponsors)): ?>
            <h2 class="meeting__h2">Sponsors</h2>
            <ul class="bill__sponsors">
                <?php foreach ($sponsors as $s): ?>
                    <li class="bill__sponsor">
                        <?php if (!empty($s['official_slug'])): ?>
                            <a href="/representatives/<?= e($s['official_slug']) ?>">
                                <?= e($s['linked_name'] ?? $s['sponsor_name']) ?>
                            </a>
                        <?php else: ?>
                            <?= e($s['sponsor_name']) ?>
                        <?php endif; ?>
                        <span class="bill__sponsor-class"><?= e($s['classification']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <h2 class="meeting__h2">Timeline</h2>
        <?php if (empty($actions)): ?>
            <p class="meeting__empty">No actions recorded.</p>
        <?php else: ?>
            <ol class="timeline">
                <?php foreach ($actions as $a): ?>
                    <li class="timeline__item">
                        <span class="timeline__date"><?= e(date('M j, Y', (int) $a['acted_on'])) ?></span>
                        <div class="timeline__body">
                            <p class="timeline__desc"><?= e($a['description']) ?></p>
                            <?php if ($a['organization']): ?>
                                <p class="timeline__org"><?= e($a['organization']) ?></p>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>

        <p class="article__meta">
            <?php if ($bill['last_action_at']): ?>
                Last action <?= e(date('M j, Y', (int) $bill['last_action_at'])) ?> &middot;
            <?php endif; ?>
            Data refreshed nightly via OpenStates
        </p>
    </article>
</main>
