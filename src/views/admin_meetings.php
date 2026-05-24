<?php
/**
 * src/views/admin_meetings.php
 *
 * Receives:
 *   $meetings — array of meeting rows with body_name joined in
 */
?>
<div class="admin-page-head">
    <h1>Meetings</h1>
    <a href="/admin/meetings/new" class="admin-btn admin-btn--primary">+ New meeting</a>
</div>

<?php if (empty($meetings)): ?>
    <p class="admin-empty">No meetings yet. <a href="/admin/meetings/new">Add the first one &rarr;</a></p>
<?php else: ?>
    <table class="admin-table">
        <thead><tr>
            <th>When</th>
            <th>Body</th>
            <th>Title</th>
            <th>Status</th>
            <th>Items</th>
            <th></th>
        </tr></thead>
        <tbody>
            <?php foreach ($meetings as $m): ?>
                <tr>
                    <td><?= e(date('M j, Y · g:i A', (int) $m['starts_at'])) ?></td>
                    <td><?= e($m['body_name']) ?></td>
                    <td><?= e($m['title'] ?? '(untitled)') ?></td>
                    <td><span class="admin-badge admin-badge--<?= e($m['status']) ?>">
                        <?= e($m['status']) ?>
                    </span></td>
                    <td><?= (int) $m['item_count'] ?></td>
                    <td><a href="/meetings/<?= (int) $m['id'] ?>">View &rarr;</a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
