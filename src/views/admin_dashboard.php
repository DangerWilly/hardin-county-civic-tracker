<?php
/**
 * src/views/admin_dashboard.php
 *
 * Receives:
 *   $counts — array of counts: ['bodies'=>n, 'meetings'=>n, 'upcoming'=>n,
 *             'agenda_items'=>n, 'pending_submissions'=>n]
 *   $upcoming — array of upcoming meeting rows for the dashboard
 */
?>
<h1>Dashboard</h1>

<div class="admin-stats">
    <div class="admin-stat">
        <span class="admin-stat__num"><?= (int) $counts['bodies'] ?></span>
        <span class="admin-stat__label">Bodies</span>
    </div>
    <div class="admin-stat">
        <span class="admin-stat__num"><?= (int) $counts['meetings'] ?></span>
        <span class="admin-stat__label">Meetings</span>
    </div>
    <div class="admin-stat">
        <span class="admin-stat__num"><?= (int) $counts['upcoming'] ?></span>
        <span class="admin-stat__label">Upcoming</span>
    </div>
    <div class="admin-stat">
        <span class="admin-stat__num"><?= (int) $counts['agenda_items'] ?></span>
        <span class="admin-stat__label">Agenda items</span>
    </div>
    <div class="admin-stat">
        <span class="admin-stat__num"><?= (int) $counts['pending_submissions'] ?></span>
        <span class="admin-stat__label">Pending feedback</span>
    </div>
</div>

<div class="admin-actions">
    <a href="/admin/bodies/new"   class="admin-btn admin-btn--primary">+ New body</a>
    <a href="/admin/meetings/new" class="admin-btn admin-btn--primary">+ New meeting</a>
</div>

<?php if (!empty($upcoming)): ?>
    <h2 class="admin-h2">Upcoming meetings</h2>
    <table class="admin-table">
        <thead><tr>
            <th>When</th>
            <th>Body</th>
            <th>Title</th>
            <th></th>
        </tr></thead>
        <tbody>
            <?php foreach ($upcoming as $m): ?>
                <tr>
                    <td><?= e(date('M j, Y · g:i A', (int) $m['starts_at'])) ?></td>
                    <td><?= e($m['body_name']) ?></td>
                    <td><?= e($m['title'] ?? '(untitled)') ?></td>
                    <td><a href="/meetings/<?= (int) $m['id'] ?>">View on site &rarr;</a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <h2 class="admin-h2">Upcoming meetings</h2>
    <p class="admin-empty">No upcoming meetings yet. <a href="/admin/meetings/new">Add the first one &rarr;</a></p>
<?php endif; ?>
