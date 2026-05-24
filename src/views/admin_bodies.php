<?php
/**
 * src/views/admin_bodies.php
 *
 * Receives:
 *   $bodies — array of body rows with jurisdiction_name joined in
 */
?>
<div class="admin-page-head">
    <h1>Bodies</h1>
    <a href="/admin/bodies/new" class="admin-btn admin-btn--primary">+ New body</a>
</div>

<?php if (empty($bodies)): ?>
    <p class="admin-empty">No bodies yet. <a href="/admin/bodies/new">Create the first one &rarr;</a></p>
<?php else: ?>
    <table class="admin-table">
        <thead><tr>
            <th>Name</th>
            <th>Kind</th>
            <th>Jurisdiction</th>
            <th>Source</th>
            <th>Updated</th>
        </tr></thead>
        <tbody>
            <?php foreach ($bodies as $b): ?>
                <tr>
                    <td><strong><?= e($b['name']) ?></strong></td>
                    <td><?= e(str_replace('_', ' ', $b['kind'])) ?></td>
                    <td><?= e($b['jurisdiction_name']) ?></td>
                    <td><span class="admin-badge"><?= e($b['source']) ?></span></td>
                    <td><?= e(date('M j, Y', (int) $b['updated_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
