<?php

namespace Kasha\Mail;

// @TODO Model

class MailMessageSent
	extends Model
{
	public function __construct()
	{
		$this->setTableName('mail_message_sent');
		$this->setAllowHtml(true);
	}

}
