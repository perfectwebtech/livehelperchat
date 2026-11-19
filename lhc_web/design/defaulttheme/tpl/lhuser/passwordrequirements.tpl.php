<h1><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('users/autologin','Password requirements')?></h1>

<?php if (isset($errors)) : ?>
    <?php include(erLhcoreClassDesign::designtpl('lhkernel/validation_error.tpl.php'));?>
<?php endif; ?>

<?php if (isset($updated) && $updated == 'done') : $msg = erTranslationClassLhTranslation::getInstance()->getTranslation('users/autologin','Settings updated'); ?>
    <?php include(erLhcoreClassDesign::designtpl('lhkernel/alert_success.tpl.php'));?>
<?php endif; ?>

<?php if (isset($updated) && $updated == 'unlocked') : $msg = erTranslationClassLhTranslation::getInstance()->getTranslation('user/loginthrottle','Lock removed'); ?>
    <?php include(erLhcoreClassDesign::designtpl('lhkernel/alert_success.tpl.php'));?>
<?php endif; ?>

<form ng-non-bindable action="" method="post" autocomplete="off">

    <div class="form-group">
        <label><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('users/autologin','User has to change password every n days');?></label>
        <input type="number" class="form-control form-control-sm" name="expires_in" value="<?php (isset($password_data['expires_in'])) ? print htmlspecialchars($password_data['expires_in']) : print '' ?>" />
    </div>

    <div class="form-group">
        <label><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('users/autologin','Disable user automatically if from last login passed X number of days');?></label>
        <input type="number" class="form-control form-control-sm" name="disable_after" value="<?php (isset($password_data['disable_after'])) ? print htmlspecialchars($password_data['disable_after']) : print '' ?>" />
    </div>

    <div class="form-group">
        <label><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('users/autologin','Force user logout if last login was X hours ago');?></label>
        <input type="number" class="form-control form-control-sm" name="logout_after" value="<?php (isset($password_data['logout_after'])) ? print htmlspecialchars($password_data['logout_after']) : print '' ?>" />
    </div>

    <div class="form-group">
        <label><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('users/autologin','Maximum number of failed logins before account is disabled.');?></label>
        <input type="number" class="form-control form-control-sm" name="max_attempts" value="<?php (isset($password_data['max_attempts'])) ? print htmlspecialchars($password_data['max_attempts']) : print '' ?>" />
    </div>

    <div class="form-group">
        <label><input type="checkbox" class="me-1" value="on" <?php (isset($password_data['generate_manually']) && $password_data['generate_manually'] == 1) ? print 'checked="checked"': print '' ?> name="generate_manually" /><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('users/autologin','We should generate password for password reminder');?></label>
    </div>

    <h5><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('users/autologin','Password format requirements');?></h5>
    <div class="form-group">
        <label><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('users/autologin','Minimal password length');?></label>
        <input type="number" class="form-control form-control-sm" name="length" value="<?php (isset($password_data['length'])) ? print htmlspecialchars($password_data['length']) : print '' ?>" />
    </div>
    <div class="form-group">
        <label><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('users/autologin','Uppercase letters required');?></label>
        <input type="number" class="form-control form-control-sm" name="uppercase_required" value="<?php (isset($password_data['uppercase_required'])) ? print htmlspecialchars($password_data['uppercase_required']) : print '0' ?>" />
    </div>
    <div class="form-group">
        <label><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('users/autologin','Lowercase characters required');?></label>
        <input type="number" class="form-control form-control-sm" name="lowercase_required" value="<?php (isset($password_data['lowercase_required'])) ? print htmlspecialchars($password_data['lowercase_required']) : print '0' ?>" />
    </div>
    <div class="form-group">
        <label><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('users/autologin','Numbers required');?></label>
        <input type="number" class="form-control form-control-sm" name="number_required" value="<?php (isset($password_data['number_required'])) ? print htmlspecialchars($password_data['number_required']) : print '0' ?>" />
    </div>
    <div class="form-group">
        <label><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('users/autologin','Special characters required');?></label>
        <input type="number" class="form-control form-control-sm" name="special_required" value="<?php (isset($password_data['special_required'])) ? print htmlspecialchars($password_data['special_required']) : print '0' ?>" />
    </div>

    <h5><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('user/loginthrottle','Brute-force protection');?></h5>
    <p class="text-muted small"><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('user/loginthrottle','Failed logins are counted per IP address and per username. When a limit is reached inside the time window, further attempts are rejected for the lock duration. Every consecutive lock doubles the duration up to the maximum. Locks expire on their own and never disable the account.');?></p>

    <div class="form-group">
        <label><input type="checkbox" class="me-1" value="on" <?php ($throttle_settings['throttle_enabled'] == 1) ? print 'checked="checked"': print '' ?> name="throttle_enabled" /><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('user/loginthrottle','Enable brute-force protection for operator logins (web and REST API)');?></label>
    </div>
    <div class="row">
        <div class="col-md-6">
            <div class="form-group">
                <label><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('user/loginthrottle','Failed attempts allowed per IP address (0 - disable this counter)');?></label>
                <input type="number" min="0" class="form-control form-control-sm" name="throttle_ip_attempts" value="<?php echo htmlspecialchars($throttle_settings['throttle_ip_attempts']) ?>" />
            </div>
            <div class="form-group">
                <label><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('user/loginthrottle','Failed attempts allowed per username (0 - disable this counter)');?></label>
                <input type="number" min="0" class="form-control form-control-sm" name="throttle_user_attempts" value="<?php echo htmlspecialchars($throttle_settings['throttle_user_attempts']) ?>" />
            </div>
            <div class="form-group">
                <label><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('user/loginthrottle','Time window in minutes');?></label>
                <input type="number" min="1" class="form-control form-control-sm" name="throttle_window" value="<?php echo htmlspecialchars($throttle_settings['throttle_window']) ?>" />
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-group">
                <label><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('user/loginthrottle','First lock duration in minutes');?></label>
                <input type="number" min="1" class="form-control form-control-sm" name="throttle_lock" value="<?php echo htmlspecialchars($throttle_settings['throttle_lock']) ?>" />
            </div>
            <div class="form-group">
                <label><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('user/loginthrottle','Maximum lock duration in minutes');?></label>
                <input type="number" min="1" class="form-control form-control-sm" name="throttle_lock_max" value="<?php echo htmlspecialchars($throttle_settings['throttle_lock_max']) ?>" />
            </div>
        </div>
    </div>

    <?php include(erLhcoreClassDesign::designtpl('lhkernel/csfr_token.tpl.php'));?>

    <div class="btn-group" role="group" aria-label="...">
        <input type="submit" class="btn btn-secondary" name="StorePasswordSettings" value="<?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('system/buttons','Save'); ?>" />
    </div>


</form>

<h5 class="mt-4"><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('user/loginthrottle','Active login locks');?></h5>

<?php if (empty($throttle_locks)) : ?>
    <p class="text-muted"><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('user/loginthrottle','No IP address or username is locked at the moment.');?></p>
<?php else : ?>
    <table class="table table-sm table-striped">
        <thead>
            <tr>
                <th><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('user/loginthrottle','Type');?></th>
                <th><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('user/loginthrottle','Identifier');?></th>
                <th><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('user/loginthrottle','Consecutive locks');?></th>
                <th><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('user/loginthrottle','Last attempt');?></th>
                <th><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('user/loginthrottle','Locked until');?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($throttle_locks as $lock) : ?>
            <tr>
                <td><?php echo htmlspecialchars($lock->type_front) ?></td>
                <td><?php echo htmlspecialchars($lock->identifier) ?></td>
                <td><?php echo (int)$lock->lock_count ?></td>
                <td><?php echo htmlspecialchars($lock->last_attempt_front) ?></td>
                <td><?php echo htmlspecialchars($lock->locked_until_front) ?></td>
                <td class="text-end">
                    <form action="" method="post" class="d-inline">
                        <?php include(erLhcoreClassDesign::designtpl('lhkernel/csfr_token.tpl.php'));?>
                        <button type="submit" class="btn btn-sm btn-outline-secondary" name="UnlockThrottle" value="<?php echo (int)$lock->id ?>"><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('user/loginthrottle','Unlock');?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>