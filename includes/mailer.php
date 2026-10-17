<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

require_once ROOT . '/vendor/PHPMailer/Exception.php';
require_once ROOT . '/vendor/PHPMailer/PHPMailer.php';
require_once ROOT . '/vendor/PHPMailer/SMTP.php';

/** Gửi email HTML qua Gmail SMTP. Trả về true/false; lỗi được ghi vào storage/mail_error.log. */
function send_mail(string $to, string $toName, string $subject, string $html): bool {
    $c = cfg('mail');
    $m = new PHPMailer(true);
    try {
        $m->isSMTP();
        $m->Host = $c['host'];
        $m->Port = (int)$c['port'];
        $m->SMTPAuth = true;
        $m->Username = $c['username'];
        $m->Password = $c['password'];
        $m->SMTPSecure = $c['secure'] === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $m->CharSet = 'UTF-8';
        $m->Timeout = 15;
        $m->setFrom($c['from_email'] ?: $c['username'], $c['from_name']);
        $m->addAddress($to, $toName);
        $m->isHTML(true);
        $m->Subject = $subject;
        $m->Body = $html;
        $m->AltBody = trim(strip_tags(str_replace(['<br>', '</p>'], "\n", $html)));
        $m->send();
        return true;
    } catch (MailException | Throwable $ex) {
        @file_put_contents(ROOT . '/storage/mail_error.log', '[' . date('Y-m-d H:i:s') . '] ' . $to . ' | ' . $m->ErrorInfo . ' | ' . $ex->getMessage() . "\n", FILE_APPEND);
        return false;
    }
}
