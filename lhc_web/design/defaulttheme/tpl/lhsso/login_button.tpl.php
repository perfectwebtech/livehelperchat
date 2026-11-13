<?php if (erLhcoreClassSsoOidc::isEnabled()) : $ssoSettings = erLhcoreClassSsoOidc::getSettings(); ?>
<div class="mb-3">
    <a class="btn btn-outline-primary w-100" href="<?php echo erLhcoreClassDesign::baseurl('sso/login')?><?php echo (isset($redirect_url) && $redirect_url != '') ? '/(r)/' . rawurlencode($redirect_url) : '' ?>"><?php echo htmlspecialchars($ssoSettings['button_label'])?></a>
</div>
<?php if (isset($_SESSION['lhc_sso_error'])) : $errors = array($_SESSION['lhc_sso_error']); unset($_SESSION['lhc_sso_error']); ?>
    <?php include(erLhcoreClassDesign::designtpl('lhkernel/validation_error.tpl.php'));?>
<?php endif; ?>
<?php endif; ?>
