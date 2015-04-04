<?php

namespace Kasha\Mail;

use Temple\Util;
use Kasha\Core\Runtime;

// @TODO Model, AdminAlert

class MailMessageQueue
	extends Model
{
	public function __construct()
	{
		$this->setTableName('mail_message_queue');
		$this->setAllowHtml(true);
	}

	public function send($transports = array()) {
		$output = false;
		$queuedMessageInfo = $this->getData();
		try {
			$options = array(
				'intro' =>Util::lavnn('intro', $queuedMessageInfo, ''),
				'manual' =>Util::lavnn('manual', $queuedMessageInfo, 0),
				'signature' =>Util::lavnn('signature', $queuedMessageInfo, 'team'),
				'language' =>Util::lavnn('language', $queuedMessageInfo, 'en'),
				'sender' =>Util::lavnn('sender', $queuedMessageInfo, 0)
			);
			if (count($transports) > 0) {
				$options['transport'] = $transports[0];
			}
			if (count($transports) > 1) {
				$options['alternativeTransport'] = $transports[1];
			}

			$output = MailUtil::mail(
				$queuedMessageInfo['email'],
				$queuedMessageInfo['subject'],
				$queuedMessageInfo['message'],
				$options
			);
		} catch (\Exception $ex) {
			$env = Runtime::getInstance()->config['ENV'];
			$adminAlert = new AdminAlert();
			$adminAlert->insert(
				array(
					 'title' => '[' . $env . '] Failed email sending attempt to address "' . $queuedMessageInfo['email'],
					 'description' => print_r($queuedMessageInfo, 1),
					 'context' => print_r($ex->getTrace(), 1)
				)
			);
		}

		return $output;
	}

}
