<label class="field component-field">
    <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?><?= !empty($required) ? ' *' : '' ?></span>
    <div><i data-lucide="<?= htmlspecialchars($icon ?? 'text-cursor-input', ENT_QUOTES, 'UTF-8') ?>"></i><input name="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>" placeholder="<?= htmlspecialchars($placeholder ?? '', ENT_QUOTES, 'UTF-8') ?>" <?= !empty($required) ? 'required' : '' ?>></div>
</label>
