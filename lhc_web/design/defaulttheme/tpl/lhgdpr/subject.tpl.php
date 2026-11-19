<h1><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('gdpr/subject','Data subject requests')?></h1>

<?php if (isset($errors)) : ?>
    <?php include(erLhcoreClassDesign::designtpl('lhkernel/validation_error.tpl.php'));?>
<?php endif; ?>

<?php if (isset($erased)) : $msg = sprintf(erTranslationClassLhTranslation::getInstance()->getTranslation('gdpr/subject','%d chat(s) erased, including messages and files.'), (int)$erased); ?>
    <?php include(erLhcoreClassDesign::designtpl('lhkernel/alert_success.tpl.php'));?>
<?php endif; ?>

<p class="text-muted small"><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('gdpr/subject','Find every live and archived chat of a person by e-mail address or phone number. Export gives a JSON file with all their chats, messages and file names (right of access). Erase permanently deletes those chats with their messages, files and visitor tracking (right to erasure). Both actions are written to the audit log with a hash of the identifier, not the identifier itself.');?></p>

<form ng-non-bindable action="" method="post" autocomplete="off">
    <div class="form-group">
        <label><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('gdpr/subject','E-mail address or phone number');?></label>
        <input type="text" class="form-control form-control-sm" name="identifier" value="<?php echo htmlspecialchars($identifier)?>" />
    </div>
    <?php include(erLhcoreClassDesign::designtpl('lhkernel/csfr_token.tpl.php'));?>
    <div class="btn-group" role="group">
        <input type="submit" class="btn btn-secondary" name="FindSubject" value="<?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('gdpr/subject','Find chats'); ?>" />
        <input type="submit" class="btn btn-outline-secondary" name="ExportSubject" value="<?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('gdpr/subject','Export JSON'); ?>" />
    </div>

<?php if (isset($chats)) : ?>
    <h5 class="mt-4"><?php echo sprintf(erTranslationClassLhTranslation::getInstance()->getTranslation('gdpr/subject','%d chat(s) found'), count($chats))?></h5>
    <?php if (!empty($chats)) : ?>
    <table class="table table-sm table-striped">
        <thead><tr>
            <th>ID</th>
            <th><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('gdpr/subject','Started');?></th>
            <th><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('gdpr/subject','Name');?></th>
            <th><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('gdpr/subject','E-mail');?></th>
            <th><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('gdpr/subject','Phone');?></th>
            <th><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('gdpr/subject','Where');?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($chats as $item) : ?>
            <tr>
                <td><?php echo (int)$item['chat']['id']?></td>
                <td><?php echo htmlspecialchars(date(erLhcoreClassModule::$dateDateHourFormat, (int)$item['chat']['time']))?></td>
                <td><?php echo htmlspecialchars($item['chat']['nick'])?></td>
                <td><?php echo htmlspecialchars($item['chat']['email'])?></td>
                <td><?php echo htmlspecialchars($item['chat']['phone'])?></td>
                <td><?php echo $item['pair']['archive'] ? htmlspecialchars($item['pair']['chat']) : erTranslationClassLhTranslation::getInstance()->getTranslation('gdpr/subject','live');?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="card border-danger mt-3"><div class="card-body">
        <div class="form-group">
            <label><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('gdpr/subject','Type the e-mail address or phone number again to permanently erase these chats');?></label>
            <input type="text" class="form-control form-control-sm" name="confirm_erase" value="" autocomplete="off" />
        </div>
        <input type="submit" class="btn btn-danger btn-sm" name="EraseSubject" value="<?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('gdpr/subject','Erase permanently'); ?>" />
    </div></div>
    <?php endif; ?>
<?php endif; ?>
</form>
