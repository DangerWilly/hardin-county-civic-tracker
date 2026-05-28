<?php
/**
 * src/views/representatives.php — public list of Ohio + Hardin officials.
 *
 * Receives:
 *   $groups — associative array: ['Ohio Senate' => [...rows...], 'Ohio House' => [...]]
 *             Each row carries: id, slug, full_name, title, party, photo_url,
 *             current_district, chamber
 *   $total  — total count for the page heading
 */
$total = $total ?? 0;
$groups = $groups ?? [];

// Friendly party labels — "R" alone is austere and a little obscure
function party_label(?string $p): string {
    return match (strtoupper((string) $p)) {
        'R' => 'Republican',
        'D' => 'Democrat',
        'I' => 'Independent',
        'L' => 'Libertarian',
        'G' => 'Green',
        'N' => 'Nonpartisan',
        default => '',
    };
}
function party_class(?string $p): string {
    return 'reps__party reps__party--' . strtolower((string) $p ?: 'unk');
}
?>
<main class="container">

    <section class="article" style="max-width: 920px;">
        <p class="article__kicker">Your Officials</p>
        <h1>Representatives</h1>
        <p class="lede__dek" style="text-align:left; margin: 0 0 1.5rem;">
            Elected officials representing Hardin County and the State of Ohio.
            Data is sourced from <a href="https://openstates.org/" rel="noopener noreferrer">OpenStates</a> for state legislators
            and refreshed nightly. County and city officials (sheriff, mayor, council, etc.) are entered manually.
        </p>

        <p class="bills__count">
            <strong><?= (int) $total ?></strong> official<?= $total === 1 ? '' : 's' ?> on record
        </p>

        <?php if (empty($groups)): ?>
            <p class="meetings__empty">No officials in the database yet. Run <code>python workers/ingest_openstates.py</code> to populate Ohio legislators.</p>
        <?php else: ?>
            <?php foreach ($groups as $group_label => $rows): ?>
                <h2 class="meetings__group"><?= e($group_label) ?> <span class="reps__group-count">(<?= count($rows) ?>)</span></h2>
                <ul class="reps__list">
                    <?php foreach ($rows as $r): ?>
                        <li class="reps__card">
                            <?php $initial = e(strtoupper(substr((string) $r['full_name'], 0, 1))); ?>
                            <div class="reps__photo-wrap">
                                <div class="reps__photo reps__photo--placeholder" aria-hidden="true"><?= $initial ?></div>
                                <?php if (!empty($r['photo_url'])): ?>
                                    <img class="reps__photo reps__photo--img"
                                         src="<?= e($r['photo_url']) ?>"
                                         alt=""
                                         loading="lazy"
                                         referrerpolicy="no-referrer"
                                         onerror="this.remove()">
                                <?php endif; ?>
                            </div>
                            <div class="reps__body">
                                <h3 class="reps__name">
                                    <a class="reps__stretch" href="/representatives/<?= e($r['slug']) ?>"><?= e($r['full_name']) ?></a>
                                </h3>
                                <p class="reps__title">
                                    <?= e($r['title']) ?>
                                    <?php if (!empty($r['current_district'])): ?>
                                        &middot; District <?= e($r['current_district']) ?>
                                    <?php endif; ?>
                                </p>
                                <?php if (!empty($r['party'])): ?>
                                    <span class="<?= e(party_class($r['party'])) ?>">
                                        <?= e(party_label($r['party']) ?: $r['party']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
</main>
