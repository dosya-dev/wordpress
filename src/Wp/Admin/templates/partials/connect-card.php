<?php
/**
 * Connection card on the dosya tab. $vm: ConnectCard::build() output plus unencrypted, connect_url, open_url, excludes_url.
 *
 * @package dosya-backup
 */

defined('ABSPATH') || exit;
?>
<section class="dosya-panel dosya-connect" data-dosya="connect-card" data-state="<?php echo esc_attr($vm['state']); ?>" data-tone="<?php echo esc_attr($vm['tone']); ?>">
    <h2><?php echo esc_html($vm['headline']); ?></h2>
    <?php if ($vm['sub'] !== '') : ?>
        <p class="dosya-connect__sub"><?php echo esc_html($vm['sub']); ?></p>
    <?php endif; ?>
    <?php if ($vm['consent'] !== null) : ?>
        <p class="dosya-connect__consent"><?php echo esc_html($vm['consent']); ?></p>
    <?php endif; ?>
    <?php if ($vm['facts']) : ?>
        <dl class="dosya-facts">
            <?php foreach ($vm['facts'] as $dosya_backup_fact) : ?>
                <dt><?php echo esc_html($dosya_backup_fact['label']); ?></dt>
                <dd><?php echo esc_html($dosya_backup_fact['value']); ?></dd>
            <?php endforeach; ?>
        </dl>
    <?php endif; ?>
    <?php if ($vm['meter'] !== null) : ?>
        <div class="dosya-meter<?php echo $vm['state'] === 'nearly_full' ? ' dosya-meter--hot' : ''; ?>" data-dosya="meter" role="progressbar" aria-label="<?php echo esc_attr($vm['meter']['storage_text']); ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr((string) $vm['meter']['percent']); ?>">
            <span class="dosya-meter__fill" style="width:<?php echo esc_attr((string) $vm['meter']['percent']); ?>%"></span>
        </div>
        <p class="dosya-meter__text">
            <?php echo esc_html($vm['meter']['storage_text']); ?>
            <?php if ($vm['meter']['projection'] !== null) : ?>
                · <?php echo esc_html($vm['meter']['projection']); ?>
            <?php endif; ?>
        </p>
    <?php endif; ?>
    <?php if ($vm['fix'] !== null) : ?>
        <p class="dosya-connect__fix"><?php echo esc_html($vm['fix']); ?></p>
    <?php endif; ?>
    <?php if ($vm['unencrypted']) : ?>
        <p class="dosya-connect__warning"><?php esc_html_e('Your host has no encryption support for PHP (sodium), so the saved dosya key is stored unencrypted in the database.', 'dosya-backup-restore-migration'); ?></p>
    <?php endif; ?>
    <p class="dosya-connect__actions">
        <?php if ($vm['primary'] !== null && $vm['primary']['kind'] === 'connect') : ?>
            <a class="button dosya-button--dosya" href="<?php echo esc_url($vm['connect_url']); ?>"><?php echo esc_html($vm['primary']['label']); ?></a>
        <?php elseif ($vm['primary'] !== null && $vm['primary']['kind'] === 'review_excludes') : ?>
            <a class="button button-primary" href="<?php echo esc_url($vm['excludes_url']); ?>"><?php echo esc_html($vm['primary']['label']); ?></a>
        <?php endif; ?>
        <?php foreach ($vm['secondary'] as $dosya_backup_button) : ?>
            <?php if ($dosya_backup_button['kind'] === 'open') : ?>
                <a class="button" href="<?php echo esc_url($vm['open_url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($dosya_backup_button['label']); ?></a>
            <?php elseif ($dosya_backup_button['kind'] === 'disconnect') : ?>
                <button type="button" class="button" data-dosya="disconnect" data-confirm="<?php echo esc_attr((string) $vm['disconnect_confirm']); ?>"><?php echo esc_html($dosya_backup_button['label']); ?></button>
            <?php endif; ?>
        <?php endforeach; ?>
        <span class="dosya-status-text" data-dosya="disconnect-status" aria-live="polite"></span>
    </p>
    <?php if ($vm['plan'] !== null && $vm['plan'] !== '') : ?>
        <p class="dosya-connect__plan"><?php echo esc_html($vm['plan']); ?></p>
    <?php endif; ?>
</section>
