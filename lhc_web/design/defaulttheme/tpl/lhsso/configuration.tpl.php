<h1><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('sso/configuration','Single sign-on (OpenID Connect)')?></h1>

<?php if (isset($errors)) : ?>
    <?php include(erLhcoreClassDesign::designtpl('lhkernel/validation_error.tpl.php'));?>
<?php endif; ?>

<?php if (isset($updated) && $updated == 'done') : $msg = erTranslationClassLhTranslation::getInstance()->getTranslation('sso/configuration','Settings updated'); ?>
    <?php include(erLhcoreClassDesign::designtpl('lhkernel/alert_success.tpl.php'));?>
<?php endif; ?>

<?php if (isset($discovery)) : $msg = erTranslationClassLhTranslation::getInstance()->getTranslation('sso/configuration','Discovery succeeded. Authorization endpoint:') . ' ' . htmlspecialchars($discovery['authorization_endpoint']); ?>
    <?php include(erLhcoreClassDesign::designtpl('lhkernel/alert_success.tpl.php'));?>
<?php endif; ?>

<p class="text-muted small"><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('sso/configuration','Register Live Helper Chat as a confidential web application at your identity provider (Microsoft Entra ID, Okta, Keycloak, Google Workspace, ...) with the redirect URI below, then enter the issuer URL, client ID and client secret. Operators sign in with the button on the login page; accounts are matched by username, then by e-mail.');?></p>

<div class="form-group">
    <label><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('sso/configuration','Redirect URI to register at the identity provider');?></label>
    <input type="text" class="form-control form-control-sm" readonly value="<?php echo htmlspecialchars($redirect_uri)?>" onclick="this.select()" />
</div>

<form ng-non-bindable action="" method="post" autocomplete="off">

    <div class="form-group">
        <label><input type="checkbox" class="me-1" value="on" <?php ($settings['enabled'] == 1) ? print 'checked="checked"': print '' ?> name="enabled" /><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('sso/configuration','Enable single sign-on');?></label>
    </div>

    <div class="row">
        <div class="col-md-6">
            <div class="form-group">
                <label><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('sso/configuration','Issuer URL (the provider publishes /.well-known/openid-configuration under it)');?></label>
                <input type="text" class="form-control form-control-sm" name="issuer" placeholder="https://login.microsoftonline.com/<tenant>/v2.0" value="<?php echo htmlspecialchars($settings['issuer'])?>" />
            </div>
            <div class="form-group">
                <label><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('sso/configuration','Client ID');?></label>
                <input type="text" class="form-control form-control-sm" name="client_id" value="<?php echo htmlspecialchars($settings['client_id'])?>" />
            </div>
            <div class="form-group">
                <label><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('sso/configuration','Client secret (leave empty to keep the stored one)');?></label>
                <input type="password" class="form-control form-control-sm" name="client_secret" value="" autocomplete="new-password" />
                <?php if ($settings['client_secret'] != '') : ?><small class="text-muted"><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('sso/configuration','A secret is stored.');?></small><?php endif; ?>
            </div>
            <div class="form-group">
                <label><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('sso/configuration','Scopes');?></label>
                <input type="text" class="form-control form-control-sm" name="scopes" value="<?php echo htmlspecialchars($settings['scopes'])?>" />
            </div>
            <div class="form-group">
                <label><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('sso/configuration','Login button label');?></label>
                <input type="text" class="form-control form-control-sm" name="button_label" value="<?php echo htmlspecialchars($settings['button_label'])?>" />
            </div>
            <div class="form-group">
                <label><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('sso/configuration','Allowed e-mail domains (comma separated, empty = any)');?></label>
                <input type="text" class="form-control form-control-sm" name="allowed_domains" placeholder="example.com, example.org" value="<?php echo htmlspecialchars($settings['allowed_domains'])?>" />
            </div>
            <div class="form-group">
                <label><input type="checkbox" class="me-1" value="on" <?php ($settings['force_sso'] == 1) ? print 'checked="checked"': print '' ?> name="force_sso" /><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('sso/configuration','Hide the username/password form on the login page (administrators can still open user/login/(local)/1)');?></label>
            </div>
        </div>
        <div class="col-md-6">
            <h5><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('sso/configuration','Account provisioning');?></h5>
            <div class="form-group">
                <label><input type="checkbox" class="me-1" value="on" <?php ($settings['auto_provision'] == 1) ? print 'checked="checked"': print '' ?> name="auto_provision" /><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('sso/configuration','Create operator accounts automatically on first sign-in');?></label>
            </div>
            <div class="form-group">
                <label><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('sso/configuration','Group for newly created operators');?></label>
                <select class="form-control form-control-sm" name="default_group_id">
                    <option value="0"><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('sso/configuration','None');?></option>
                    <?php foreach ($groups as $group) : ?>
                        <option value="<?php echo (int)$group->id?>" <?php if ($settings['default_group_id'] == $group->id) : ?>selected="selected"<?php endif; ?>><?php echo htmlspecialchars($group->name)?> (<?php echo (int)$group->id?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="row">
                <div class="col-6"><div class="form-group"><label><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('sso/configuration','Username claim');?></label><input type="text" class="form-control form-control-sm" name="username_claim" value="<?php echo htmlspecialchars($settings['username_claim'])?>" /></div></div>
                <div class="col-6"><div class="form-group"><label><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('sso/configuration','E-mail claim');?></label><input type="text" class="form-control form-control-sm" name="email_claim" value="<?php echo htmlspecialchars($settings['email_claim'])?>" /></div></div>
                <div class="col-6"><div class="form-group"><label><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('sso/configuration','Name claim');?></label><input type="text" class="form-control form-control-sm" name="name_claim" value="<?php echo htmlspecialchars($settings['name_claim'])?>" /></div></div>
                <div class="col-6"><div class="form-group"><label><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('sso/configuration','Surname claim');?></label><input type="text" class="form-control form-control-sm" name="surname_claim" value="<?php echo htmlspecialchars($settings['surname_claim'])?>" /></div></div>
            </div>
            <h5><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('sso/configuration','Group mapping');?></h5>
            <div class="form-group">
                <label><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('sso/configuration','Groups claim');?></label>
                <input type="text" class="form-control form-control-sm" name="groups_claim" value="<?php echo htmlspecialchars($settings['groups_claim'])?>" />
            </div>
            <div class="form-group">
                <label><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('sso/configuration','Provider group to Live Helper Chat group ids, one per line: provider-group=1,2');?></label>
                <textarea class="form-control form-control-sm" rows="5" name="group_map" placeholder="support-agents=2&#10;support-admins=1"><?php echo htmlspecialchars($settings['group_map'])?></textarea>
                <small class="text-muted"><?php foreach ($groups as $group) : ?><?php echo (int)$group->id?> = <?php echo htmlspecialchars($group->name)?>; <?php endforeach; ?></small>
            </div>
            <div class="form-group">
                <label><input type="checkbox" class="me-1" value="on" <?php ($settings['sync_groups'] == 1) ? print 'checked="checked"': print '' ?> name="sync_groups" /><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('sso/configuration','Re-apply the mapping on every sign-in (membership of mapped groups follows the provider; other groups are untouched)');?></label>
            </div>
        </div>
    </div>

    <?php include(erLhcoreClassDesign::designtpl('lhkernel/csfr_token.tpl.php'));?>

    <div class="btn-group" role="group" aria-label="...">
        <input type="submit" class="btn btn-secondary" name="StoreSsoSettings" value="<?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('system/buttons','Save'); ?>" />
        <input type="submit" class="btn btn-outline-secondary" name="TestSsoDiscovery" value="<?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('sso/configuration','Test discovery'); ?>" />
    </div>
</form>
