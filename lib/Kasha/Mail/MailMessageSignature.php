<?php

namespace Kasha\Mail;

use Kasha\Templar\TextProcessor;

// @TODO Model, Database

class MailMessageSignature
	extends Model
{
	public function __construct()
	{
		$this->setTableName('mail_message_signature');
		$this->setAllowHtml(true);
	}

	static public function listCodes() {
		$sqlParams = array('language' => $_SESSION['language']['code']);
		$query = TextProcessor::doText(file_get_contents(__DIR__ . "/sql/ListMailSignatureCodes.sql"), $sqlParams);

		return Database::getInstance()->getArray($query);
	}

	static public function getByCodeFormatLanguage($code, $format, $language) {
		$sqlParams = array('code' => $code, 'format' => $format, 'language' => $language);
		$query = TextProcessor::doText(file_get_contents(__DIR__ . "/sql/GetMailSignature.sql"), $sqlParams);
		$result = Database::getInstance()->getRow($query);

		return (is_array($result) && isset($result['content'])) ? $result['content'] : '';
	}

}
