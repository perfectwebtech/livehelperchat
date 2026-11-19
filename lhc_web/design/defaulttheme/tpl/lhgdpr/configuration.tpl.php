<h1><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('gdpr/configuration','Data retention')?></h1>

<?php if (isset($errors)) : ?>
    <?php include(erLhcoreClassDesign::designtpl('lhkernel/validation_error.tpl.php'));?>
<?php endif; ?>

<?php if (isset($updated) && $updated == 'done') : $msg = erTranslationClassLhTranslation::getInstance()->getTranslation('gdpr/configuration','Settings updated'); ?>
    <?php include(erLhcoreClassDesign::designtpl('lhkernel/alert_success.tpl.php'));?>
<?php endif; ?>

<p class="text-muted small"><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('gdpr/configuration','Applied every hour by the cron/data_retention job (the Docker scheduler container runs it automatically) to live and archived chats. Only closed chats are affected. Anonymising blanks visitor messages and form data; removes name, e-mail, phone, IP, user agent, referrers, chat variables, additional data, operator remarks and geo position; deletes uploaded files and visitor tracking. Country and time zone are kept for statistics. Every run is recorded in the audit log.');?></p>

<form ng-non-bindable action="" method="post" autocomplete="off">
    <div class="row">
        <div class="col-md-6">
            <div class="form-group">
                <label><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('gdpr/configuration','Anonymise closed chats older than this many days (0 = never)');?></label>
                <input type="number" min="0" class="form-control form-control-sm" name="anonymize_after_days" value="<?php echo (int)$settings['anonymize_after_days']?>" />
            </div>
            <div class="form-group">
                <label><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('gdpr/configuration','Delete closed chats older than this many days (0 = never)');?></label>
                <input type="number" min="0" class="form-control form-control-sm" name="delete_after_days" value="<?php echo (int)$settings['delete_after_days']?>" />
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-group">
                <label><input type="checkbox" class="me-1" value="on" <?php ($settings['anonymize_operator_messages'] == 1) ? print 'checked="checked"': print '' ?> name="anonymize_operator_messages" /><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('gdpr/configuration','Also blank operator messages (they often repeat what the visitor wrote)');?></label>
            </div>
            <div class="form-group">
                <label><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('gdpr/configuration','Chats per table per run');?></label>
                <input type="number" min="1" max="5000" class="form-control form-control-sm" name="batch_size" value="<?php echo (int)$settings['batch_size']?>" />
            </div>
        </div>
    </div>

    <?php include(erLhcoreClassDesign::designtpl('lhkernel/csfr_token.tpl.php'));?>

    <input type="submit" class="btn btn-secondary" name="StoreRetentionSettings" value="<?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('system/buttons','Save'); ?>" />
    <a class="btn btn-link" href="<?php echo erLhcoreClassDesign::baseurl('gdpr/subject')?>"><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('gdpr/configuration','Data subject requests')?></a>
</form>
