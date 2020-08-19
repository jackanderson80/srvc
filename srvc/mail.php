<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/encrypt.php';

require_once __DIR__ . "/../srv/config/app_config.php";
//require_once __DIR__ . "/../srv/config/db_config.php";


use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function sendEmail($to, $subject, $message)
{
	require(__DIR__ . "/../srv/config/app_config.php");

	if (!$mailEnabled)
		return;

	$mail = new PHPMailer;
	$mail->Debugoutput = 'html';
	//$mail->SMTPSecure = 'tls';
	$mail->isSMTP();                                     			 // Set mailer to use SMTP
	$mail->SMTPDebug = 0;                               			 // Disable verbose debug output
	$mail->Host = $mailRelayServer;  				 	             // Specify main and backup SMTP servers
	$mail->SMTPAuth = true;                              			 // Disable SMTP authentication
	$mail->Port = $mailRelayServerPort;	
	$mail->Username = $mailUser;
	$mail->Password = $mailPwd;
	
	
	$mail->Hostname = $mailHostName;

	$mail->From = $mailFromEmail;
	$mail->FromName = $mailFromName;
	$mail->addAddress($to);     							         // Add a recipient
	$mail->addReplyTo($mailFromEmail, $mailFromName);
	$mail->isHTML(false);             			                     // Set email format to plain text


	$mail->Subject = $subject;
	$mail->Body    = $message;

	$mail->send();
}


function sendMailConfirmation($uname, $uemail, $action) {
	require(__DIR__ . "/../srv/config/app_config.php");
   
    $vlink = $baseUrl . '/confirm/?req=' . urlencode(encryptString($uemail, $cryptKey, $salt));

    $to      = $uemail;
    $subject = "Welcome to " . $productName . "! Let's get started";
    $message = "Hi " . $uname . ",\r\n\r\nWelcome to " . $productName . "!\r\n\r\nTo verify your email please click the link below:\r\n\r\n" . $vlink;
    $message = $message . "\r\n\r\nThank you,\r\n" . $teamSignature . "\r\n" . $teamSignatureUrl;
    
    sendEmail($to, $subject, $message); 
} 

function sendPwdReset($uemail) {
	require(__DIR__ . "/../srv/config/app_config.php");
    
    $vlink = $baseUrl . '/reset/?req=' . urlencode(encryptString($uemail, $cryptKey, $salt));

    $to      = $uemail;
    $subject = "Your " . $productName . " password reset request";
    $message = "Hello,\r\n\r\nWe are sending you this email because you requested to reset your " . $productName . " password!\r\n\r\nPlease follow this link to reset your password:\r\n\r\n" . $vlink;
    $message = $message . "\r\n\r\nThank you,\r\n" . $teamSignature . "\r\n" . $teamSignatureUrl;

	sendEmail($to, $subject, $message);     
}


?>