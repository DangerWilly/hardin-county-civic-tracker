<?php
/**
 * src/views/admin_meeting_form.php
 *
 * Receives:
 *   $bodies — array of body rows for the dropdown
 *   $error  — optional validation error
 *   $old    — repopulate after error
 */
$old   = $old   ?? [];
$error = $error ?? null;

$status_options = [
    'scheduled' => 'Scheduled',
    'live'      => 'Live now',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
];
?>
<h1>New meeting</h1>
<p class="admin-lede">Schedule or backfill a meeting for one of the bodies you've created.</p>

<?php if ($error): ?>
    <p class="form__error" role="alert"><?= e($error) ?></p>
<?php endif; ?>

<form action="/admin/meetings/new" method="post" class="form admin-form js-once" autocomplete="off">
    <?= csrf_field() ?>

    <label class="form__label" for="body_id">Body *</label>
    <select class="form__input" id="body_id" name="body_id" required>
        <option value="">— choose —</option>
        <?php foreach ($bodies as $b): ?>
            <option value="<?= (int) $b['id'] ?>"
                <?= ((int) ($old['body_id'] ?? 0)) === (int) $b['id'] ? 'selected' : '' ?>>
                <?= e($b['name']) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <label class="form__label" for="title">Meeting title</label>
    <input class="form__input" type="text" id="title" name="title"
        value="<?= e($old['title'] ?? 'Regular Meeting') ?>"
        maxlength="200">

    <div class="admin-form__row">
        <div class="admin-form__col">
            <label class="form__label" for="starts_at">Start date &amp; time *</label>
            <input class="form__input" type="datetime-local" id="starts_at" name="starts_at"
                value="<?= e($old['starts_at'] ?? '') ?>" required>
        </div>
        <div class="admin-form__col">
            <label class="form__label" for="status">Status</label>
            <select class="form__input" id="status" name="status">
                <?php foreach ($status_options as $val => $label): ?>
                    <option value="<?= e($val) ?>"
                        <?= ($old['status'] ?? 'scheduled') === $val ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <label class="form__label" for="location_name">Location name</label>
    <input class="form__input" type="text" id="location_name" name="location_name"
        value="<?= e($old['location_name'] ?? '') ?>" maxlength="200"
        placeholder="Council Chambers">

    <label class="form__label" for="address">Address</label>
    <input class="form__input" type="text" id="address" name="address"
        value="<?= e($old['address'] ?? '') ?>" maxlength="400"
        placeholder="111 W Franklin St, Kenton, OH 43326">

    <label class="form__label" for="agenda_url">Agenda URL (PDF)</label>
    <input class="form__input" type="url" id="agenda_url" name="agenda_url"
        value="<?= e($old['agenda_url'] ?? '') ?>" maxlength="500"
        placeholder="https://...">

    <label class="form__label" for="agenda_items">Agenda items — one per line</label>
    <textarea class="form__input" id="agenda_items" name="agenda_items"
        rows="8" maxlength="10000"
        placeholder="Approval of minutes&#10;Ordinance 2026-04: Water rate adjustment&#10;Public comment"><?= e($old['agenda_items'] ?? '') ?></textarea>
    <p class="form__hint">Each non-empty line becomes one agenda item. You can edit/reorder them after creation (next admin feature).</p>

    <label class="form__label" for="notes_md">Editor's notes (markdown)</label>
    <textarea class="form__input" id="notes_md" name="notes_md"
        rows="4" maxlength="5000"><?= e($old['notes_md'] ?? '') ?></textarea>

    <div class="admin-form__actions">
        <button type="submit" class="admin-btn admin-btn--primary" data-busy-text="Creating…">Create meeting</button>
        <a href="/admin/meetings" class="admin-btn">Cancel</a>
    </div>
</form>
