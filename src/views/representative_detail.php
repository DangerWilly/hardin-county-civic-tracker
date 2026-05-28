<?php
/**
 * src/views/representative_detail.php
 *
 * Receives:
 *   $official    — full officials row
 *   $sponsored   — array of recent bills they sponsored (max 25)
 *   $sponsor_total — total count of bills they've sponsored (for "see more" hint)
 *
 * Page layout intentionally mirrors bill_detail.php's hierarchy:
 *   1. Header (name, title, district, party)
 *   2. Contact info (the practical part — phone, email, address)
 *   3. Bio (markdown, when present)
 *   4. Sponsored bills (this is the "voting record" preview)
 *   5. External links
 */

function party_label_d(?string $p): string {
    return match (strtoupper((string) $p)) {
        'R' => 'Republican', 'D' => 'Democrat', 'I' => 'Independent',
        'L' => 'Libertarian','G' => 'Green',    'N' => 'Nonpartisan',
        default => '',
    };
}

$sponsored     = $sponsored     ?? [];
$sponsor_total = $sponsor_total ?? 0;
?>
<main class="container">
    <article class="article" style="max-width: 760px;">

        <div class="rep-header">
            <?php $initial = e(strtoupper(substr((string) $official['full_name'], 0, 1))); ?>
            <div class="rep-header__photo-wrap">
                <div class="rep-header__photo rep-header__photo--placeholder" aria-hidden="true"><?= $initial ?></div>
                <?php if (!empty($official['photo_url'])): ?>
                    <img class="rep-header__photo rep-header__photo--img"
                         src="<?= e($official['photo_url']) ?>"
                         alt=""
                         referrerpolicy="no-referrer"
                         onerror="this.remove()">
                <?php endif; ?>
            </div>

            <div class="rep-header__text">
                <p class="article__kicker"><?= e($official['title']) ?>
                    <?php if (!empty($official['current_district'])): ?>
                        &middot; District <?= e($official['current_district']) ?>
                    <?php endif; ?>
                </p>
                <h1 style="margin-bottom: 0.4rem;"><?= e($official['full_name']) ?></h1>
                <?php if (!empty($official['party'])): ?>
                    <span class="reps__party reps__party--<?= e(strtolower($official['party'])) ?>">
                        <?= e(party_label_d($official['party']) ?: $official['party']) ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <?php /* ---- Contact info ---- */ ?>
        <?php $has_contact = $official['contact_phone'] || $official['contact_email'] || $official['office_address']; ?>
        <?php if ($has_contact): ?>
            <h2 class="meeting__h2">Contact</h2>
            <dl class="rep-contact">
                <?php if (!empty($official['contact_phone'])): ?>
                    <dt>Phone</dt>
                    <dd><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $official['contact_phone'])) ?>"><?= e($official['contact_phone']) ?></a></dd>
                <?php endif; ?>
                <?php if (!empty($official['contact_email'])): ?>
                    <dt>Email</dt>
                    <dd><a href="mailto:<?= e($official['contact_email']) ?>"><?= e($official['contact_email']) ?></a></dd>
                <?php endif; ?>
                <?php if (!empty($official['office_address'])): ?>
                    <dt>Office</dt>
                    <dd><?= e($official['office_address']) ?></dd>
                <?php endif; ?>
            </dl>
        <?php endif; ?>

        <?php /* ---- Biography ---- */ ?>
        <?php if (!empty($official['bio_md'])): ?>
            <h2 class="meeting__h2">Biography</h2>
            <div class="article__body" style="font-size: 1.05rem;">
                <?= md_to_html($official['bio_md']) ?>
            </div>
        <?php endif; ?>

        <?php /* ---- Sponsored bills ---- */ ?>
        <?php if (!empty($sponsored)): ?>
            <h2 class="meeting__h2">Sponsored Bills</h2>
            <p class="bill__versions-intro">
                <?= (int) $sponsor_total ?> bill<?= $sponsor_total === 1 ? '' : 's' ?>
                with <?= e($official['full_name']) ?> as sponsor or cosponsor<?= $sponsor_total > count($sponsored) ? ' (most recent ' . count($sponsored) . ' shown)' : '' ?>.
            </p>
            <ul class="rep-sponsored">
                <?php foreach ($sponsored as $b): ?>
                    <li class="rep-sponsored__row">
                        <span class="rep-sponsored__id"><?= e($b['identifier']) ?></span>
                        <div class="rep-sponsored__body">
                            <a class="rep-sponsored__title" href="/bills/<?= (int) $b['id'] ?>"><?= e($b['title']) ?></a>
                            <p class="rep-sponsored__meta">
                                <?= e($b['classification']) ?>
                                <?php if ($b['last_action_at']): ?>
                                    &middot; Last action <?= e(date('M j, Y', (int) $b['last_action_at'])) ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($sponsor_total > count($sponsored)): ?>
                <p style="margin-top: 1rem;">
                    <a href="/bills?sponsor=<?= e($official['slug']) ?>">See all <?= (int) $sponsor_total ?> bills &rarr;</a>
                </p>
            <?php endif; ?>
        <?php endif; ?>

        <?php /* ---- External links ---- */ ?>
        <?php $links = array_filter([
            'Official website' => $official['website_url'],
            'OpenStates page'  => !empty($official['ocd_id']) ? 'https://openstates.org/person/' . urlencode((string) $official['ocd_id']) . '/' : null,
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
            <?php if (!empty($official['updated_at'])): ?>
                Last updated <?= e(date('M j, Y', (int) $official['updated_at'])) ?> &middot;
            <?php endif; ?>
            Source: <?= e($official['source']) ?>
        </p>
    </article>
</main>
