<?php
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('settings');

$selfId = (int) Auth::id();
$id     = input_int($_GET, 'id', 1);
$target   = null;
if ($id !== null) {
    $target = Users::find($id) ?? throw new HttpException(404, 'User not found.');
}
$isSelf = $target !== null && (int) $target['id'] === $selfId;
$page['title'] = $target ? $target['full_name'] : 'Add User';
$self = 'user-form.php' . ($id !== null ? '?id=' . $id : '');

// ---------------------------------------------------------------------
// Save (PRG). Passwords are never flashed back into the session.
// ---------------------------------------------------------------------
if (is_post()) {
    Csrf::verifyRequest();
    [$data, $errors] = Users::validate($_POST, $id, $selfId);

    if ($errors) {
        flash_old(array_intersect_key(array_filter($_POST, 'is_string'), array_flip(['username', 'full_name', 'role'])));
        flash_errors($errors);
        flash('error', 'Please fix the highlighted fields.');
        redirect('pages/' . $self);
    }

    if ($target) {
        Users::update($id, $data);
        if ($isSelf && $data['password'] !== '') {
            Auth::refreshPasswordStamp(); // stay signed in here; other sessions end
        }
        flash('success', "{$data['full_name']} was updated." . ($data['password'] !== ''
            ? ($isSelf ? ' Your password was changed.' : ' Their password was reset; they are signed out everywhere else.')
            : ''));
    } else {
        Users::create($data);
        flash('success', "{$data['full_name']} can now sign in as {$data['username']}.");
    }
    redirect('pages/users.php');
}

$val   = static fn (string $key): string => old($key, (string) ($target[$key] ?? ''));
$role  = old('role', (string) ($target['role'] ?? 'cashier'));
$roles = config('app.roles');

$pageStyles = ['css/settings.css'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <a class="back-link" href="<?= e(url('pages/users.php')) ?>"><?= icon('arrow-left') ?> Users</a>
        <h1><?= e($target ? $target['full_name'] : 'Add User') ?></h1>
        <?php if ($target): ?>
            <p class="muted">
                @<?= e($target['username']) ?> · added <?= e(date('M j, Y', strtotime($target['created_at']))) ?>
                <?php if ((int) $target['is_active'] !== 1): ?> · <span class="badge">Inactive</span><?php endif; ?>
            </p>
        <?php endif; ?>
    </div>
</div>

<form class="form-layout" method="post" action="<?= e(url('pages/' . $self)) ?>" novalidate id="userForm" autocomplete="off">
    <section class="card card--pad">
        <?= Csrf::field() ?>
        <h2 class="card__title">Account</h2>
        <div class="form-grid">
            <label class="form-field form-field--full">
                <span class="form-label">Full name *</span>
                <input class="form-input" name="full_name" maxlength="100" required value="<?= e($val('full_name')) ?>"<?= invalid('full_name') ?>>
                <?= field_error('full_name') ?>
            </label>
            <label class="form-field">
                <span class="form-label">Username *</span>
                <input class="form-input" name="username" maxlength="50" required autocapitalize="none" spellcheck="false"
                       value="<?= e($val('username')) ?>"<?= invalid('username') ?>>
                <?= field_error('username') ?>
                <p class="form-hint">Letters, numbers, dot, dash or underscore. Used to sign in.</p>
            </label>
            <fieldset class="form-field role-field">
                <legend class="form-label">Role *</legend>
                <?php if ($isSelf): ?>
                    <input type="hidden" name="role" value="<?= e($target['role']) ?>">
                <?php endif; ?>
                <div class="segmented">
                    <?php foreach ($roles as $value => $label): ?>
                        <label>
                            <input type="radio" name="role" value="<?= e($value) ?>"<?= $role === $value ? ' checked' : '' ?><?= $isSelf ? ' disabled' : '' ?>>
                            <span><?= icon($value === 'admin' ? 'settings' : 'cart') ?> <?= e($label) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <?= field_error('role') ?>
                <p class="form-hint"><?= $isSelf ? "You can't change your own role." : 'Admin: every page. Cashier: POS, Sales History and Customers.' ?></p>
            </fieldset>
        </div>
    </section>

    <aside class="form-side">
        <section class="card card--pad">
            <h2 class="card__title"><?= $target ? 'Reset Password' : 'Password' ?></h2>
            <?php if ($target): ?>
                <p class="form-hint preview-hint">Leave empty to keep the current password.<?= $isSelf ? '' : ' A new password signs this user out on every other device.' ?></p>
            <?php endif; ?>
            <div class="form-grid form-grid--single">
                <label class="form-field">
                    <span class="form-label">New password<?= $target ? '' : ' *' ?></span>
                    <input class="form-input" type="password" name="password" maxlength="<?= Users::MAX_PASSWORD ?>" autocomplete="new-password"<?= invalid('password') ?>>
                    <?= field_error('password') ?>
                    <p class="form-hint">At least <?= Users::MIN_PASSWORD ?> characters. Avoid the username and common passwords.</p>
                </label>
                <label class="form-field">
                    <span class="form-label">Confirm password<?= $target ? '' : ' *' ?></span>
                    <input class="form-input" type="password" name="password_confirm" maxlength="<?= Users::MAX_PASSWORD ?>" autocomplete="new-password"<?= invalid('password_confirm') ?>>
                    <?= field_error('password_confirm') ?>
                </label>
            </div>
        </section>
        <?php if ($target): ?>
            <section class="card card--pad">
                <h2 class="card__title">Activity</h2>
                <dl class="detail-list">
                    <div><dt>Sales rung up</dt><dd><?= number_format((int) $target['sales']) ?></dd></div>
                    <div><dt>Last sign-in</dt><dd><?= $target['last_login_at'] ? e(date('M j, Y g:i A', strtotime($target['last_login_at']))) : 'Never' ?></dd></div>
                </dl>
            </section>
        <?php endif; ?>
    </aside>

    <div class="form-actions">
        <a class="btn btn--light" href="<?= e(url('pages/users.php')) ?>">Cancel</a>
        <button type="submit" class="btn btn--primary"><?= icon('save') ?> <?= $target ? 'Save Changes' : 'Add User' ?></button>
    </div>
</form>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
