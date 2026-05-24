<?php
/**
 * src/views/admin_body_form.php
 *
 * Receives:
 *   $jurisdictions — array of jurisdiction rows for the dropdown
 *   $error         — optional validation error message
 *   $old           — array of previously-submitted values to repopulate after error
 */
$old   = $old   ?? [];
$error = $error ?? null;

$kind_options = [
    'city_council'      => 'City Council',
    'county_commission' => 'County Commission',
    'school_board'      => 'School Board',
    'township_trustees' => 'Township Trustees',
    'planning'          => 'Planning Commission',
    'zoning'            => 'Zoning Board',
    'library_board'     => 'Library Board',
    'parks_rec'         => 'Parks & Recreation',
    'other'             => 'Other',
];
?>
<h1>New body</h1>
<p class="admin-lede">A "body" is any group that holds meetings — council, commission, board, etc.</p>

<?php if ($error): ?>
    <p class="form__error" role="alert"><?= e($error) ?></p>
<?php endif; ?>

<form action="/admin/bodies/new" method="post" class="form admin-form">
    <?= csrf_field() ?>

    <label class="form__label" for="name">Name *</label>
    <input class="form__input" type="text" id="name" name="name"
        value="<?= e($old['name'] ?? '') ?>"
        maxlength="200" required
        placeholder="e.g. Kenton City Council">

    <label class="form__label" for="jurisdiction_id">Jurisdiction *</label>
    <select class="form__input" id="jurisdiction_id" name="jurisdiction_id" required>
        <option value="">— choose —</option>
        <?php foreach ($jurisdictions as $j): ?>
            <option value="<?= (int) $j['id'] ?>"
                <?= ((int) ($old['jurisdiction_id'] ?? 0)) === (int) $j['id'] ? 'selected' : '' ?>>
                <?= e($j['name']) ?> (<?= e($j['kind']) ?>)
            </option>
        <?php endforeach; ?>
    </select>

    <label class="form__label" for="kind">Kind *</label>
    <select class="form__input" id="kind" name="kind" required>
        <option value="">— choose —</option>
        <?php foreach ($kind_options as $val => $label): ?>
            <option value="<?= e($val) ?>"
                <?= ($old['kind'] ?? '') === $val ? 'selected' : '' ?>>
                <?= e($label) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <label class="form__label" for="slug">Slug</label>
    <input class="form__input" type="text" id="slug" name="slug"
        value="<?= e($old['slug'] ?? '') ?>"
        maxlength="80"
        placeholder="city-council (auto-generated from name if left blank)">

    <label class="form__label" for="website_url">Website URL</label>
    <input class="form__input" type="url" id="website_url" name="website_url"
        value="<?= e($old['website_url'] ?? '') ?>" maxlength="500">

    <label class="form__label" for="contact_email">Contact email</label>
    <input class="form__input" type="email" id="contact_email" name="contact_email"
        value="<?= e($old['contact_email'] ?? '') ?>" maxlength="200">

    <label class="form__label" for="contact_phone">Contact phone</label>
    <input class="form__input" type="tel" id="contact_phone" name="contact_phone"
        value="<?= e($old['contact_phone'] ?? '') ?>" maxlength="40">

    <label class="form__label" for="meeting_info">Meeting info</label>
    <input class="form__input" type="text" id="meeting_info" name="meeting_info"
        value="<?= e($old['meeting_info'] ?? '') ?>" maxlength="200"
        placeholder="e.g. 2nd & 4th Mondays at 7pm, Council Chambers">

    <label class="form__label" for="description">Description (markdown)</label>
    <textarea class="form__input" id="description" name="description"
        rows="4" maxlength="2000"><?= e($old['description'] ?? '') ?></textarea>

    <div class="admin-form__actions">
        <button type="submit" class="admin-btn admin-btn--primary">Create body</button>
        <a href="/admin/bodies" class="admin-btn">Cancel</a>
    </div>
</form>
