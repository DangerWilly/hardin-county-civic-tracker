<?php
/**
 * src/views/bill_detail.php
 *
 * Receives:
 *   $bill         — full bill row
 *   $actions      — array of bill_actions rows, oldest first
 *   $sponsors     — array of bill_sponsorships rows
 *   $versions     — array of bill_versions rows, in display order
 *
 * SECTION ORDER (intentional — reading hierarchy):
 *   1. Header: identifier, title, classification
 *   2. AI summary (when xAI ships) OR official abstract — whichever we have
 *   3. Subject tags (quick orientation: what's this about?)
 *   4. Bill text versions (the source — link to actual text people can read)
 *   5. Sponsors
 *   6. Timeline of actions
 *   7. Footer with external links + freshness note
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

// Friendly label for the media type — readers don't care about MIME types
function bill_format_label(?string $media_type): string {
    return match ($media_type) {
        'application/pdf' => 'PDF',
        'text/html'       => 'HTML',
        null, ''          => 'Link',
        default           => strtoupper(explode('/', $media_type)[1] ?? 'Link'),
    };
}
?>
<main class="container">
    <article class="article" style="max-width: 760px;">
        <p class="article__kicker"><?= e($kind) ?> &middot; Ohio General Assembly, Session <?= e($bill['session']) ?></p>
        <h1><?= e($bill['identifier']) ?>: <?= e($bill['title']) ?></h1>

        <?php /* ---- 'What is this bill about?' section ---- */ ?>
        <?php if (!empty($bill['summary_md'])): ?>
            <section class="bill__about">
                <p class="article__kicker bill__about-kicker">AI Summary</p>
                <div class="bill__about-body"><?= md_to_html($bill['summary_md']) ?></div>
                <p class="bill__about-disclaimer">
                    AI-generated. May contain errors. See official text below for the definitive version.
                </p>
            </section>
        <?php elseif (!empty($bill['abstract'])): ?>
            <section class="bill__about">
                <p class="article__kicker bill__about-kicker">About this bill</p>
                <div class="bill__about-body">
                    <p><?= e($bill['abstract']) ?></p>
                </div>
                <p class="bill__about-disclaimer">
                    Abstract provided by OpenStates. See official text below for the full bill.
                </p>
            </section>
        <?php else: ?>
            <section class="bill__about bill__about--empty">
                <p class="article__kicker bill__about-kicker">About this bill</p>
                <p class="bill__about-body">
                    <em>No summary is available yet for this bill.</em>
                    The text and timeline below contain the official information.
                </p>
            </section>
        <?php endif; ?>

        <?php if ($subjects): ?>
            <p class="bill__subjects">
                <?php foreach ($subjects as $s): ?>
                    <span class="bill__tag"><?= e($s) ?></span>
                <?php endforeach; ?>
            </p>
        <?php endif; ?>

        <?php /* ---- Bill text: versions over time ---- */ ?>
        <?php if (!empty($versions)): ?>
            <h2 class="meeting__h2">Bill Text</h2>
            <p class="bill__versions-intro">
                Each version below is the actual text of the bill as it stood at that point in the legislative process. The most recent version reflects what's currently on the table.
            </p>
            <ul class="bill__versions">
                <?php foreach ($versions as $v): ?>
                    <li class="bill__version">
                        <div class="bill__version-meta">
                            <span class="bill__version-note"><?= e($v['note']) ?></span>
                            <?php if (!empty($v['issued_at'])): ?>
                                <span class="bill__version-date"><?= e(date('M j, Y', (int) $v['issued_at'])) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($v['url'])): ?>
                            <a class="bill__version-link"
                               href="<?= e($v['url']) ?>"
                               rel="noopener noreferrer">
                                Read <?= e(bill_format_label($v['media_type'])) ?> &rarr;
                            </a>
                        <?php else: ?>
                            <span class="bill__version-link bill__version-link--missing">No link available</span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php elseif (!empty($bill['source_url']) || !empty($bill['openstates_url'])): ?>
            <h2 class="meeting__h2">Bill Text</h2>
            <p class="bill__versions-intro">
                Version history hasn't been captured yet. The official source link below has the latest text.
            </p>
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

        <?php $links = array_filter([
            'OpenStates record' => $bill['openstates_url'],
            'Official source'   => $bill['source_url'],
        ]); ?>
        <?php if (!empty($links)): ?>
            <h2 class="meeting__h2">External Links</h2>
            <ul class="meeting__links">
                <?php foreach ($links as $label => $url): ?>
                    <li><a href="<?= e($url) ?>" rel="noopener noreferrer">&rarr; <?= e($label) ?></a></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <p class="article__meta">
            <?php if ($bill['last_action_at']): ?>
                Last action <?= e(date('M j, Y', (int) $bill['last_action_at'])) ?> &middot;
            <?php endif; ?>
            Data refreshed nightly via OpenStates
        </p>
    </article>
</main>
