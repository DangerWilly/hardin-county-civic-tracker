<?php
/**
 * src/views/bills.php — public list of recently-active Ohio bills.
 *
 * Receives:
 *   $bills        — array of bill rows (with body chamber inferred from identifier prefix)
 *   $total        — total matching this filter+search combo, for pagination
 *   $page, $pages — current page (1-indexed) and total pages
 *   $filter       — 'all','house','senate','resolution' — for chip active state
 *   $query        — current search string, may be empty
 *   $per_page     — bills per page (passed through to pagination links)
 */
$filter   = $filter   ?? 'all';
$query    = $query    ?? '';
$page     = (int) ($page  ?? 1);
$pages    = (int) ($pages ?? 1);
$per_page = (int) ($per_page ?? 25);

// Build the base query-string for filter / pagination links so chips and
// page links preserve each other's state.
function bills_url(array $overrides): string {
    global $filter, $query;
    $qs = array_filter([
        'filter' => $overrides['filter'] ?? ($filter !== 'all' ? $filter : null),
        'q'      => $overrides['q']      ?? ($query !== ''     ? $query  : null),
        'page'   => $overrides['page']   ?? null,
    ], fn($v) => $v !== null && $v !== '');
    return '/bills' . ($qs ? '?' . http_build_query($qs) : '');
}
?>
<main class="container">

    <section class="article" style="max-width: 920px;">
        <p class="article__kicker">The Statehouse</p>
        <h1>Ohio Bills</h1>
        <p class="lede__dek" style="text-align:left; margin: 0 0 1.5rem;">
            Legislation moving through the Ohio General Assembly &mdash; sorted by most recent action.
            Data via <a href="https://openstates.org/" rel="noopener noreferrer">OpenStates</a>, refreshed nightly.
        </p>

        <form class="bills__search" action="/bills" method="get" role="search">
            <?php if ($filter !== 'all'): ?>
                <input type="hidden" name="filter" value="<?= e($filter) ?>">
            <?php endif; ?>
            <input
                class="bills__search-input"
                type="search"
                name="q"
                value="<?= e($query) ?>"
                placeholder="Search by title or identifier (e.g. 'school funding', 'HB 446')…"
                autocomplete="off"
                aria-label="Search bills">
            <button type="submit" class="bills__search-btn">Search</button>
            <?php if ($query !== ''): ?>
                <a href="<?= e(bills_url(['q' => null])) ?>" class="bills__search-clear">&times; clear</a>
            <?php endif; ?>
        </form>

        <nav class="chips" aria-label="Filter by chamber">
            <a class="chip <?= $filter === 'all'        ? 'is-active' : '' ?>" href="<?= e(bills_url(['filter' => null])) ?>">All</a>
            <a class="chip <?= $filter === 'house'      ? 'is-active' : '' ?>" href="<?= e(bills_url(['filter' => 'house'])) ?>">House</a>
            <a class="chip <?= $filter === 'senate'     ? 'is-active' : '' ?>" href="<?= e(bills_url(['filter' => 'senate'])) ?>">Senate</a>
            <a class="chip <?= $filter === 'resolution' ? 'is-active' : '' ?>" href="<?= e(bills_url(['filter' => 'resolution'])) ?>">Resolutions</a>
        </nav>

        <p class="bills__count">
            <?php if ($query !== ''): ?>
                <strong><?= (int) $total ?></strong> match<?= $total === 1 ? '' : 'es' ?> for &ldquo;<?= e($query) ?>&rdquo;
            <?php else: ?>
                <strong><?= (int) $total ?></strong> bill<?= $total === 1 ? '' : 's' ?>
            <?php endif; ?>
            <?php if ($pages > 1): ?>
                &middot; page <?= (int) $page ?> of <?= (int) $pages ?>
            <?php endif; ?>
        </p>

        <?php if (empty($bills)): ?>
            <p class="meetings__empty">No bills match. Try clearing search or switching chambers.</p>
        <?php else: ?>
            <ul class="bills__list">
                <?php foreach ($bills as $b): ?>
                    <?php
                    // Infer chamber+classification from identifier prefix for display
                    $prefix = strtoupper(strtok((string) $b['identifier'], ' '));
                    $type_label = match (true) {
                        str_starts_with($prefix, 'HB') => 'House Bill',
                        str_starts_with($prefix, 'SB') => 'Senate Bill',
                        str_starts_with($prefix, 'HR') => 'House Resolution',
                        str_starts_with($prefix, 'SR') => 'Senate Resolution',
                        str_starts_with($prefix, 'HJR'), str_starts_with($prefix, 'SJR') => 'Joint Resolution',
                        str_starts_with($prefix, 'HCR'), str_starts_with($prefix, 'SCR') => 'Concurrent Resolution',
                        default => 'Bill',
                    };
                    ?>
                    <li class="bills__row">
                        <div class="bills__id">
                            <span class="bills__identifier"><?= e($b['identifier']) ?></span>
                            <span class="bills__type"><?= e($type_label) ?></span>
                        </div>
                        <div class="bills__body">
                            <h3 class="bills__title">
                                <a href="/bills/<?= (int) $b['id'] ?>"><?= e($b['title']) ?></a>
                            </h3>
                            <p class="bills__meta">
                                <?php if ($b['last_action_at']): ?>
                                    Last action <?= e(date('M j, Y', (int) $b['last_action_at'])) ?>
                                <?php endif; ?>
                                <?php if ($b['session']): ?>
                                    &middot; Session <?= e($b['session']) ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($pages > 1): ?>
            <nav class="pager" aria-label="Pagination">
                <?php if ($page > 1): ?>
                    <a href="<?= e(bills_url(['page' => $page - 1])) ?>" class="pager__btn">&larr; Previous</a>
                <?php else: ?>
                    <span class="pager__btn pager__btn--disabled">&larr; Previous</span>
                <?php endif; ?>
                <span class="pager__center">Page <?= (int) $page ?> of <?= (int) $pages ?></span>
                <?php if ($page < $pages): ?>
                    <a href="<?= e(bills_url(['page' => $page + 1])) ?>" class="pager__btn">Next &rarr;</a>
                <?php else: ?>
                    <span class="pager__btn pager__btn--disabled">Next &rarr;</span>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>
</main>
