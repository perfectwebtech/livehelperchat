<?php

$tpl = erLhcoreClassTemplate::getInstance('lhgdpr/subject.tpl.php');

$identifier = isset($_POST['identifier']) ? trim((string)$_POST['identifier']) : '';
$tpl->set('identifier', $identifier);

if (ezcInputForm::hasPostData()) {

    if (!isset($_POST['csfr_token']) || !$currentUser->validateCSFRToken($_POST['csfr_token'])) {
        erLhcoreClassModule::redirect('gdpr/subject');
        exit;
    }

    try {
        if (isset($_POST['ExportSubject'])) {
            $export = erLhcoreClassGdpr::exportSubject($identifier);
            erLhcoreClassAuditTrail::record(erLhcoreClassAuditTrail::CATEGORY_DATA_PROTECTION, 'export_subject', array(
                'subject_hash' => hash('sha256', $export['subject']['type'] . ':' . $export['subject']['value']),
                'subject_type' => $export['subject']['type'],
                'chats' => $export['chat_count'],
            ));
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="data-subject-export-' . gmdate('Ymd-His') . '.json"');
            header('Cache-Control: no-store');
            echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        if (isset($_POST['EraseSubject'])) {
            if (!isset($_POST['confirm_erase']) || $_POST['confirm_erase'] !== $identifier) {
                throw new Exception(erTranslationClassLhTranslation::getInstance()->getTranslation('gdpr/subject', 'To erase, type the e-mail address or phone number again in the confirmation field.'));
            }
            $deleted = erLhcoreClassGdpr::eraseSubject($identifier);
            $tpl->set('erased', $deleted);
        }

        $tpl->set('chats', erLhcoreClassGdpr::findSubjectChats($identifier));
    } catch (Exception $e) {
        $tpl->set('errors', array($e->getMessage()));
    }
}

$Result['content'] = $tpl->fetch();
$Result['path'] = array(
    array('url' => erLhcoreClassDesign::baseurl('system/configuration'), 'title' => erTranslationClassLhTranslation::getInstance()->getTranslation('system/htmlcode', 'System configuration')),
    array('title' => erTranslationClassLhTranslation::getInstance()->getTranslation('gdpr/subject', 'Data subject requests'))
);
