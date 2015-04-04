<?php

namespace Kasha\Mail;

use Mailgun\Mailgun;

use Temple\DateTimeUtil;
use Temple\Util;

use Kasha\Templar\TextProcessor;
use Kasha\Core\Config;
use Kasha\Core\Runtime;

// @TODO Model, AdminAlert, User

class MailUtil
{
	private static $cache = array();

	public static function queueMail($to, $subj, $body, $intro = '', $manual = false, $signature = 'team', $language = '')
	{
		if ($language == '') {
			$language = $_SESSION['language']['code'];
		}
		$messageParams = array(
			'email' => $to,
			'subject' => $subj,
			'message' => $body,
			'intro' => $intro,
			'manual' => $manual ? 1 : 0,
			'signature' => $signature,
			'language' => $language
		);
		if ($signature == 'user') {
			$messageParams['sender'] = $_SESSION['user']['id'];
		}
		if ($to != '') {
			$queueModel = new MailMessageQueue();
			$queueModel->insert($messageParams);
		} else {
			$adminAlert = new AdminAlert();
			$adminAlert->insert(
				array(
					 'title' => 'Message queued with empty email address',
					 'description' => print_r($messageParams, 1),
					 'context' => print_r(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 1)
				)
			);
		}
	}

	/**
	 * Sends mail to external email address. wraps standard php function with useful options:
	 *   'intercept': expects an email address to send.
	 *      All MAMP mails are intercepted by default.
	 *      To prevent intercepting on local machine, Runtime context can be used (explicitly set 'intercept' context item to false)
	 *   'format': expects either 'text' or 'html' ('html' is used by default)
	 *
	 * @param $to
	 * @param $subj
	 * @param $body
	 * @param array $options
	 *
	 * @return bool
	 */
	public static function mail($to, $subj, $body, $options = array())
	{
		$config = Config::getInstance();
		$env = $config->get('ENV');
		// Intercept message
		if (in_array($env, array('MAMP', 'TEST')) && Runtime::getInstance()->getContextItem('intercept', true)) {
			// on MAMP, intercept every outgoing message, if not explicitly disabled in Runtime context
			// on TEST, add admin email(s) to original address, if not explicitly disabled in Runtime context
			$intercept = Util::lavnn('adminEmail', $config->getEnvConfig(), $config->get('adminEmail'));
			if ($env == 'TEST') {
				$intercept = $to . ',' . $intercept;
			}
			$subj = '[' . $env . '] ' . $subj;
		} else {
			$intercept = Util::lavnn('intercept', $options, '');
		}

		// generate a unique hash for the mail
		$now = DateTimeUtil::fixTime();
		$toAsString = is_array($to) ? join(',', $to) : $to;
		$hash = md5($toAsString . ':' . $now . ':' . $subj);

		if ($intercept != '') {
			//$to = explode(',', $intercept);
			$to = $intercept;
		}

		// set the transport option
		if (!isset($options['transport'])) {
			$options['transport'] = $config->getEnvSetting('mailTransport');
		}

		$result = false;
		$intro = Util::lavnn('intro', $options, '');
		if ($to != '') {
			$result = MailUtil::transportMail($to, $subj, $body, $hash, $options);
			// if first sending attempt to provided transport failed, try alternatives (if any)
			if (!$result && isset($options['alternativeTransport'])) {
				foreach ((array)$options['alternativeTransport'] as $alternativeTransport) {
					$options['transport'] = $alternativeTransport;
					if ($to != '') {
						$result = MailUtil::transportMail($to, $subj, $body, $hash, $options);
						if ($result) {
							break;
						}
					}
				}
			}
		} else {
			$messageParams = array(
				'email' => $to,
				'subject' => $subj,
				'message' => $body,
				'intro' => $intro
			);
			$adminAlert = new AdminAlert();
			$adminAlert->insert(
				array(
					 'title' => 'Message sent with empty email address',
					 'description' => print_r($messageParams, 1),
					 'context' => print_r(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 1)
				)
			);
		}

		if ($result) {
			// save sent email to the database
			$mailModel = new MailMessageSent();
			$mailParams = array(
				'hash' => $hash,
				'email' => (is_array($to) ? join(', ', $to) : $to),
				'subject' => $subj,
				'message' => $body,
				'intro' => $intro,
				'sent' => $now
			);
			$manual = Util::lavnn('manual', $options, 0);
			if ($manual > 0) {
				$mailParams['manual'] = 1;
			}
			$sender = Util::lavnn('sender', $options, 0);
			if ($sender > 0) {
				$mailParams['sender'] = $sender;
			}
			$mailParams['signature'] = Util::lavnn('signature', $options, 'team');
			$mailParams['language'] = Util::lavnn('language', $options, 'en'); // @TODO use from $config
			$result = $mailModel->insert($mailParams) > 0;
		}

		return $result;
	}

	/**
	 * Transports mail using given $options
	 *
	 * @param $to
	 * @param $subj
	 * @param $body
	 * @param $hash
	 * @param $options
	 *
	 * @return bool
	 */
	public static function transportMail($to, $subj, $body, $hash, $options)
	{
		$intro = Util::lavnn('intro', $options, '');
		if ($options['transport'] == 'phpmailer') {
			return self::transportMailPhpMailer($to, $subj, $body, $hash, $intro, $options);
		} elseif ($options['transport'] == 'mailgun') {
			return self::transportMailMailgun($to, $subj, $body, $hash, $intro, $options);
		} elseif ($options['transport'] == 'mandrill') {
			return self::transportMailMandrill($to, $subj, $body, $hash, $intro, $options);
		} else {
			return self::transportMailSendmail($to, $subj, $body, $hash, $intro, $options);
		}
	}

	/**
	 * Transport mail message with sendmail
	 *
	 * @param $to
	 * @param $subj
	 * @param $body
	 * @param $hash
	 * @param $intro
	 * @param $options
	 *
	 * @return bool
	 */
	public static function transportMailSendmail($to, $subj, $body, $hash, $intro, $options)
	{
		// Set headers and prepare the body depending on expected format
		$headers = Util::lavnn('headers', $options, array());

		$signature = Util::lavnn('signature', $options, 'team');
		$language = Util::lavnn('language', $options, 'en');
		$sender = Util::lavnn('sender', $options, 0);

		if (Util::lavnn('format', $options, 'html') == 'html') {
			$headers[] = 'Content-type: text/html; charset=utf-8';
			$body = self::prepareHtmlMail($subj, $body, $hash, $intro, $signature, $language, $sender);
		} else {
			$body = self::prepareTextMail($subj, $body, $hash, $intro, $signature, $language, $sender);
		}

		$from = Config::getInstance()->getEnvSetting('mailFrom');
		if ($signature != 'team'  && $sender > 0) {
			$userModel = new User();
			if ($userModel->load($sender)->isValid()) {
				$from = $userModel->get('fname') . ' ' . $userModel->get('lname') . ' <' . $userModel->get('email') . '>';
			}
		}
		$headers[] = 'Reply-To: ' . $from; // @TODO from address too?

		$headers = array_merge($headers, array('MIME-Version: 1.0'));

		if (is_array($to)) {
			$to = join(', ', $to);
		}

		return @mail($to, $subj, $body, join(PHP_EOL, $headers));
	}

	/**
	 * Transport mail message with mailgun services
	 *
	 * @param $to
	 * @param $subj
	 * @param $body
	 * @param $hash
	 * @param $intro
	 * @param $options
	 *
	 * @return bool
	 */
	public static function transportMailMailgun($to, $subj, $body, $hash, $intro, $options)
	{
		// @TODO make sure that from and reply-to addresses are correctly set in headers

		if (!is_array($to)) {
			$to = explode(',', $to);
		}

		$signature = Util::lavnn('signature', $options, 'team');
		$language = Util::lavnn('language', $options, 'en');
		$sender = Util::lavnn('sender', $options, 0);

		$config = Config::getInstance();

		# Instantiate the client.
		$mgClient = new Mailgun($config->get('mailgun.apiKey'));
		$domain = $config->get('mailgun.domain');

		$msgBuilder = $mgClient->MessageBuilder();
		$from = $config->get('mailgun.from');
		if ($signature != 'team'  && $sender > 0) {
			$userModel = new User();
			if ($userModel->load($sender)->isValid()) {
				$from = $userModel->get('fname') . ' ' . $userModel->get('lname') . ' <' . $userModel->get('email') . '>';
			}
		}
		$msgBuilder->setFromAddress($from);

		foreach ($to as $recipient) {
			$msgBuilder->addToRecipient(trim($recipient));
		}
		$msgBuilder->setSubject($subj);
		$msgBuilder->setTextBody(self::prepareTextMail($subj, $body, $hash, $intro, $signature, $language, $sender));
		$msgBuilder->setHtmlBody(self::prepareHtmlMail($subj, $body, $hash, $intro, $signature, $language, $sender));

		$result = $mgClient->post("{$domain}/messages", $msgBuilder->getMessage(), $msgBuilder->getFiles());

		return ($result->http_response_code == 200);
	}

	/**
	 * Prepare list of recipients in Mandrill format
	 *
	 * @param array $to
	 *
	 * @return array
	 */
	private static function prepareMandrillMailRecipients(array $to)
	{
		$output = array();
		foreach ($to as $recipient) {
			$output[] = array(
				'email' => $recipient,
				'name' => $recipient,
				'type' => 'to'
			);
		}

		return $output;
	}

	/**
	 * Transport mail message with Mandrill services
	 *
	 * @param $to
	 * @param $subj
	 * @param $body
	 * @param $hash
	 * @param $intro
	 * @param $options
	 *
	 * @return bool
	 */
	public static function transportMailMandrill($to, $subj, $body, $hash, $intro, $options)
	{
		if (!is_array($to)) {
			$to = explode(',', $to);
		}

		$signature = Util::lavnn('signature', $options, 'team');
		$language = Util::lavnn('language', $options, 'en');
		$sender = Util::lavnn('sender', $options, 0);

		$config = Config::getInstance();

		# Instantiate the client.
		$mandrillClient = new \Mandrill($config->get('mandrill.apiKey'));

		$message = array(
			'html' => self::prepareHtmlMail($subj, $body, $hash, $intro, $signature, $language, $sender),
			'text' => self::prepareTextMail($subj, $body, $hash, $intro, $signature, $language, $sender),
			'subject' => $subj,
			'from_email' => $config->get('mandrill.fromEmail'),
			'from_name' => $config->get('mandrill.fromName'),
			'to' => self::prepareMandrillMailRecipients($to),
			'headers' => array('Reply-To' => $config->get('mandrill.replyTo')),
		);

		// rewrite from addresses if user template is used
		if ($signature != 'team' && $sender > 0) {
			$userModel = new User();
			if ($userModel->load($sender)->isValid()) {
				$message['from_email'] = $userModel->get('email');
				$message['from_name'] = $userModel->get('fname') . ' ' . $userModel->get('lname');
				$message['headers']['Reply-To'] = $userModel->get('email');
			}
		}

		try {
			$result = $mandrillClient->messages->send($message, false, 'default');
			$status = Util::lavnn('status', array_pop($result), 'sent');
			return in_array($status, array('sent', 'queued'));
		} catch(\Exception $ex) {
			return false;
		}
	}

	/**
	 * Transport mail message with PhpMailer library
	 *
	 * @param $to
	 * @param $subj
	 * @param $body
	 * @param $hash
	 * @param $intro
	 * @param $options
	 *
	 * @return bool
	 */
	public static function transportMailPhpMailer($to, $subj, $body, $hash, $intro, $options)
	{
		if (!is_array($to)) {
			$to = explode(',', $to);
		}

		$signature = Util::lavnn('signature', $options, 'team');
		$language = Util::lavnn('language', $options, 'en');
		$sender = Util::lavnn('sender', $options, 0);

		$config = Config::getInstance();
		$envConfig = $config->getEnvConfig();
		$mail = new \PHPMailer();

		$mail->isSMTP();                            	 // Set mailer to use SMTP
		$mail->Host = $config->get('SMTP_HOST');         // Specify main and backup SMTP servers
		$mail->SMTPAuth = true;                     	 // Enable SMTP authentication
		$mail->Username = $config->get('SMTP_USER');     // SMTP username
		$mail->Password = $config->get('SMTP_PASSWORD'); // SMTP password
		$mail->SMTPSecure = 'tls';                       // Enable encryption, 'ssl' also accepted

		$mail->From = Util::lavnn('mailFrom', $envConfig, 'info@tilpy.com');
		$mail->FromName = Util::lavnn('mailFromName', $envConfig, 'Info@Tilpy');
		foreach($to as $email) {
			$mail->addAddress($email);
		}
		$mail->addReplyTo($mail->From, $mail->FromName);

		/*
		$mail->addBCC('bcc@example.com');
		$mail->WordWrap = 50;                                 // Set word wrap to 50 characters
		$mail->addAttachment('/var/tmp/file.tar.gz');         // Add attachments
		$mail->addAttachment('/tmp/image.jpg', 'new.jpg');    // Optional name
		*/

		$mail->isHTML(true);                                  // Set email format to HTML
		$mail->Subject = $subj;
		$mail->Body    = self::prepareHtmlMail($subj, $body, $hash, $intro, $signature, $language, $sender);
		$mail->AltBody = $body = self::prepareTextMail($subj, $body, $hash, $intro, $signature, $language, $sender);

		$result = $mail->send();
		if (!$result) {
			// @TODO decide what to do with mail sending errors
			echo 'Message could not be sent.';
			echo 'Mailer Error: ' . $mail->ErrorInfo;
		}

		return $result;
	}

	/**
	 * Prepare mail message in HTML format
	 *
	 * @param $subj
	 * @param $body
	 * @param $hash
	 * @param string $intro
	 * @param string $signature
	 * @param string $language
	 * @param int $sender
	 *
	 * @return string
	 */
	public static function prepareHtmlMail($subj, $body, $hash, $intro = '', $signature = 'team', $language = 'en', $sender = 0)
	{
		$url = Runtime::getBaseUrl() . '/' . $language . '/webmail/' . $hash;
		$params = array(
			'subj' => $subj,
			'body' => $body,
			'intro' => $intro,
			'hash' => $hash,
			'weblink' => $url,
			'signature' => self::prepareMailSignature($signature, true, $language, $sender)
		);

		return TextProcessor::doTemplate('cron', 'mail.html', $params);
	}

	/**
	 * Prepares mail message in the text format
	 *
	 * @param $subj
	 * @param $body
	 * @param $hash
	 * @param string $intro
	 * @param string $signature
	 * @param string $language
	 * @param int $sender
	 *
	 * @return string
	 */
	public static function prepareTextMail($subj, $body, $hash, $intro = '', $signature = 'team', $language = 'en', $sender = 0)
	{
		$url = Runtime::getBaseUrl() . '/' . Runtime::getUrlLanguagePrefix() . 'webmail/' . $hash;
		$params = array(
			'subj' => $subj,
			'body' => strip_tags($body),
			'intro' => $intro,
			'hash' => $hash,
			'weblink' => $url,
			'signature' => self::prepareMailSignature($signature, false, $language, $sender)
		);

		return TextProcessor::doTemplate('cron', 'mail.text', $params);
	}

	/**
	 * Prepares mail signature
	 *
	 * @param string $signatureCode
	 * @param bool $html
	 * @param string $language
	 * @param int $sender
	 *
	 * @return string
	 */
	public static function prepareMailSignature($signatureCode = 'team', $html = false, $language = 'en', $sender = 0)
	{
		$output = '';

		$cachedSignatureCode = $signatureCode . ($signatureCode != 'team' && $sender > 0 ? $sender : '') . ($html ? '.html' : '.txt');
		$format = ($html ? 'html' : 'text');

		if (isset(self::$cache['signature'][$cachedSignatureCode])) {
			$output = self::$cache['signature'][$cachedSignatureCode];
		} else {
			// try to get template for $signatureCode from the database
			$output = MailMessageSignature::getByCodeFormatLanguage($signatureCode, $format, $language);

			// If not found, try to locate default signature template in the database
			if ($output == '' && $signatureCode != 'team') {
				$output = self::prepareMailSignature('team', $html, $language, $sender);
			} elseif ($output == '' && $signatureCode == 'team') {
				// If signature is still not found, use static templates.
				$templateName = 'mail.' . ($html ? 'html' : 'text') . '.signature';
				$output = TextProcessor::doTemplate('cron', $templateName);
			}

			// if non-empty template is found for user/user-empty signature, process text with sender data
			if ($output != '' && in_array($signatureCode, ['user', 'user-empty']) && $sender > 0) {
				$userModel = new User();
				if ($userModel->load($sender)->isValid()) {
					$output = TextProcessor::doText($output, $userModel->getData());
				}
			}

			// save rendered signature to inner cache to reuse on the same HTTP request
			self::$cache['signature'][$cachedSignatureCode] = $output;
		}

		return $output;
	}

}
