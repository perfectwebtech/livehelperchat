<?php
/**
 * Test fixture for the data protection smoke test: seeds live and archived
 * chats with personal data, messages, a file and visitor tracking.
 *   php .github/scripts/gdpr-fixture.php <lhc_web dir>   prints the chat ids as JSON
 */
chdir($argv[1]);
$_SERVER['REQUEST_URI'] = '/';
require_once 'ezcomponents/Base/src/base.php';
ezcBase::addClassRepository('./', './lib/autoloads');
spl_autoload_register(array('ezcBase', 'autoload'), true, false);
spl_autoload_register(array('erLhcoreClassSystem', 'autoload'), true, false);
erLhcoreClassSystem::init();
ezcBaseInit::setCallback('ezcInitDatabaseInstance', 'erLhcoreClassLazyDatabaseConfiguration');
$db = ezcDbInstance::get();
$now = time();

$make = function ($label, $daysAgo, $status, $email, $phone) use ($now) {
    $chat = new erLhcoreClassModelChat();
    $chat->nick = "Visitor $label";
    $chat->email = $email; $chat->phone = $phone;
    $chat->ip = '203.0.113.' . rand(1, 250); $chat->uagent = 'Mozilla/5.0 test';
    $chat->referrer = 'https://shop.example.com/cart?user=' . $label; $chat->session_referrer = 'https://google.com/?q=' . $label;
    $chat->additional_data = json_encode(array(array('key' => 'order', 'value' => 'ORD-' . $label)));
    $chat->chat_variables = json_encode(array('customer_id' => 'C-' . $label));
    $chat->remarks = "VIP customer $label"; $chat->city = 'London'; $chat->lat = '51.5'; $chat->lon = '-0.12';
    $chat->user_typing_txt = 'my card number is'; $chat->country_code = 'gb'; $chat->country_name = 'United Kingdom';
    $chat->time = $now - $daysAgo * 86400; $chat->status = $status; $chat->dep_id = 1; $chat->hash = erLhcoreClassChat::generateHash();
    $chat->saveThis();
    foreach (array(array(0, "Hi, I am $label, email $email"), array(1, "Hello $label, how can I help?"), array(0, "My address is 1 High St")) as $m) {
        $msg = new erLhcoreClassModelmsg(); $msg->chat_id = $chat->id; $msg->user_id = $m[0]; $msg->msg = $m[1]; $msg->time = $chat->time; $msg->name_support = $m[0] ? 'Agent Smith' : '';
        $msg->meta_msg = $m[0] == 0 ? json_encode(array('content' => array('form' => array('email' => $email)))) : '';
        $msg->saveThis();
    }
    return $chat;
};

$ids = array();
$ids['anon']    = $make('A', 40, 2, 'alice@example.com', '+44 7700 900111')->id;  // closed, 40d  -> anonymise
$ids['delete']  = $make('B', 400, 2, 'bert@example.com', '')->id;                 // closed, 400d -> delete
$ids['active']  = $make('C', 40, 1, 'carol@example.com', '')->id;                  // active, 40d  -> untouched
$ids['recent']  = $make('D', 2, 2, 'dave@example.com', '')->id;                    // closed, 2d   -> untouched
$ids['legacy']  = $make('L', 40, 2, 'lena@example.com', '')->id;                   // anonymized=1 by old cron -> completed
$db->query("UPDATE lh_chat SET anonymized = 1 WHERE id = " . (int)$ids['legacy']);

// file + visitor tracking for the anonymise target
@mkdir('var/storage/gdprtest', 0777, true);
file_put_contents('var/storage/gdprtest/passport.png', 'fake image');
$file = new erLhcoreClassModelChatFile(); $file->chat_id = $ids['anon']; $file->name = 'passport.png'; $file->upload_name = 'passport.png'; $file->file_path = 'var/storage/gdprtest/'; $file->size = 10; $file->type = 'image/png'; $file->extension = 'png'; $file->date = time(); $file->user_id = 0; $file->online_user_id = 0; $file->persistent = 0; $file->saveThis();
$db->query("INSERT INTO lh_chat_online_user (vid, ip, chat_id, referrer, current_page, page_title, notes, user_agent, identifier, online_attr, online_attr_system, operator_message, requires_email, requires_username, requires_phone, visitor_tz, user_country_code, user_country_name, city, lat, lon, first_visit, last_visit, last_check_time, conversion_id, dep_id, device_type, invitation_id, operator_user_id, operator_user_proactive, message_seen, message_seen_ts, pages_count, tt_pages_count, invitation_count, invitation_seen_count, last_visit_prev, user_active, total_visits, time_on_site, tt_time_on_site, reopen_chat, screenshot_id, operation, operation_chat, chat_time) VALUES ('vidA','203.0.113.9'," . (int)$ids['anon'] . ",'https://shop.example.com','/checkout','Checkout','notes','UA','','','','',0,0,0,'','gb','UK','London','0','0',0,0,0,0,0,0,0,0,'',0,0,0,0,0,0,0,0,0,0,0,0,0,'','',0)");

// archived chats: create an archive range and move two chats into it
$db->query("INSERT INTO lh_chat_archive_range (`id`, `range_from`, `range_to`, `year_month`, `older_than`, `last_id`, `first_id`) VALUES (900, 0, 0, 0, 0, 0, 0)");
$db->query("CREATE TABLE IF NOT EXISTS lh_chat_archive_900 LIKE lh_chat");
$db->query("CREATE TABLE IF NOT EXISTS lh_chat_archive_msg_900 LIKE lh_msg");
$ids['archived_anon'] = $make('E', 60, 2, 'erin@example.com', '')->id;          // archived, 60d -> anonymise
$ids['subject_live'] = $make('F', 1, 2, 'Bob.Subject@Example.com', '+1 (555) 010-9999')->id;   // subject request
$ids['subject_arch'] = $make('G', 3, 2, 'bob.subject@example.com', '')->id;  // subject request, archived
foreach (array($ids['archived_anon'], $ids['subject_arch']) as $id) {
    $db->query("INSERT INTO lh_chat_archive_900 SELECT * FROM lh_chat WHERE id = $id");
    $db->query("INSERT INTO lh_chat_archive_msg_900 SELECT * FROM lh_msg WHERE chat_id = $id");
    $db->query("DELETE FROM lh_msg WHERE chat_id = $id");
    $db->query("DELETE FROM lh_chat WHERE id = $id");
}
echo json_encode($ids), "\n";
