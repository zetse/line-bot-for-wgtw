<?php

class webhook
{
	protected $input;
	protected $inputMessage;
	protected $inputArgument;
	protected $replyToken;

	protected $noClassMessage = '沒有這堂課耶，你要來當老師嗎 ';
	protected $noOfficeMessage = '沒有這家店耶，你要來當開一家嗎 ';

	protected $lineAPI = 'https://api.line.me/v2/bot/message/reply';
	protected $wgPostAPI = 'http://www.worldgymtaiwan.com/api/post';
	protected static $curl = null;
	protected $defaultAreaSort = [
		'北部'   => [],
		'桃竹苗' => [],
		'中部'   => [],
		'南部'   => [],
		'宜花東' => [],
	];

	protected $accessToken = 'LINE_ACCESS_TOKEN';

	private function getEmoji($code) {
		$bin = hex2bin(str_repeat('0', 8 - strlen($code)) . $code);
		return mb_convert_encoding($bin, 'UTF-8', 'UTF-32BE');
	}

	private function getFile($fileName) {
		return json_decode(file_get_contents($fileName), true);
	}

	private function jsonResponse($message = [])
	{
		header("Content-type: application/json; charset=utf-8");
		echo json_encode($message);
		exit;
	}

	private function parseLineInput()
	{
		if (empty($this->input)) {
			$this->jsonResponse([
				'success' => true,
				'message' => 'no paramters'
			]);
		}

		if (empty($this->input['events']) || empty($this->input['events'][0])) {
			$this->jsonResponse([
				'success' => true,
				'message' => 'empty event'
			]);
		}

		$event = $this->input['events'][0];
		if (empty($event['message'])) {
			$this->jsonResponse([
				'success' => true,
				'message' => 'empty user message'
			]);
		}

		$userMessageInfo = $event['message'];
		$this->messagetype = $userMessageInfo['type'];
		$this->inputMessage = $userMessageInfo['text'];
		$this->replyToken = $event['replyToken'];

		if ('text' != $this->messagetype) {
			$this->jsonResponse([
				'success' => true,
				'message' => 'not text message'
			]);
		}
	}

	private function getTodayClasses($inputClasses, $type = 'course')
	{
		$classes = [];

		date_default_timezone_set('Asia/Taipei');
		$now = date('H:i:s');
		$day = date('N');

		if ( ! empty($inputClasses[$day])) {
			$wantedOfficeClasses = $inputClasses[$day];

			foreach ($wantedOfficeClasses as $time => $infos) {

				// if ($time > $now) {
				foreach ($infos as $info) {

					if ('course' == $type) {
						$temp = [$info['office'], $time, $info['teacher']];
					} else {
						$temp = [$time, $info['teacher'], $info['course']];
					}

					if ('course' == $type) {
						$this->defaultAreaSort[$info['area']][] = implode('  ', $temp);
						//$nextClasses[$info['area']][] = implode('  ', $temp);
					} else {
						$nextClasses[] = implode('  ', $temp);
					}
				}
				// }
			}
		}

		if ('course' == $type) {
			$temp = [];
			foreach ($this->defaultAreaSort	as $area => $cs) {
				$temp[] = $area;
				$temp = array_merge($temp, $cs);
			}

			$nextClasses = $temp;
		}

		return $nextClasses;
	}

	private function getClasses()
	{
		$messages = [];

		$officeList = $this->getFile('line_office_list.json');
		if ( ! empty($officeList[$this->inputArgument])) {
			$messages[] = $this->getTodayClasses($officeList[$this->inputArgument], 'office');
		} else {
			$courseList = $this->getFile('line_course_list.json');
			$courseAliasList = $this->getFile('line_course_alias_list.json');

			// chinese name to english name
			if ( ! empty($courseAliasList[$this->inputArgument])) {
				$this->inputArgument = $courseAliasList[$this->inputArgument];
			}

			if ( ! empty($courseList[$this->inputArgument])) {
				$messages[] = $this->getTodayClasses($courseList[$this->inputArgument]);
			} else {
				// find partial match
				$matchedClass = [];
				foreach ($courseAliasList as $chineseName => $englishName) {

					if (false === strpos($chineseName, $this->inputArgument)) {
						continue;
					}
					$matches = $this->getTodayClasses($courseList[$englishName]);

					$messages[] = array_merge(
						[$this->getEmoji('2B50').$chineseName],
						$matches,
						['']
					);
				}
			}
		}



		if (empty($messages)) {
			$messages[] = $this->noClassMessage. $this->getEmoji('100078');
		} else {
			foreach ($messages as &$msg) {
				$msg = implode("\n", $msg);
			}
		}

		return $messages;
	}

	private function getLatestAnnounce()
	{
		$officeMap = $this->getFile('line_office_map.json');

		if (empty($officeMap[$this->inputArgument])) {
			return $this->noOfficeMessage. $this->getEmoji('100078');
		}

		$apiParams = [
			'office_id' => $officeMap[$this->inputArgument],
			'sort' => '-released_at',
		];
		$announceURL = $this->wgPostAPI.'?'.http_build_query($apiParams);

		$res = json_decode(file_get_contents($announceURL), true);
		if ( ! empty($res) && ! empty($res['data'])) {

			$contents = [];

			foreach ($res['data'] as $data) {

				if (3 == count($contents)) {
					break;
				}

				if (empty($data['details'])) {
					continue;
				}

				$content = $data['details'];
				$content = str_replace('&nbsp;', "\n", $content);
				$content = str_replace('</div><div>', "\n", $content);
				$content = strip_tags($content);


				$contents[] = $content;
			}
		}

		return $contents;
	}

	private function handleUserMessage()
	{
		$responseMessage = '';

		if (preg_match('/^查課表\s+(.*)$/', $this->inputMessage, $matches)) {
			$this->inputArgument = strtolower($matches[1]);
			$responseMessages = $this->getClasses();
		}

		if (preg_match('/^查公告\s+(.*)$/', $this->inputMessage, $matches)) {
			$this->inputArgument = strtolower($matches[1]);
			$responseMessages = $this->getLatestAnnounce();
		}


		$messages = [];
		foreach ($responseMessages as $message) {
			$messages[] = [
				"type" => "text",
				"text" => $message
			];
		}

		$response = [
			"replyToken" => $this->replyToken,
			"messages" => array_slice($messages, 0, 5)
		];

		if ( ! empty($this->replyToken)) {
			$this->sendMessagetoLine($response);
			exit;
		} else {
			$this->jsonResponse($response);
		}

	}

	private function sendMessagetoLine($postData = array())
	{
		curl_setopt_array(static::$curl, array(
			CURLOPT_POST            => true,
			CURLOPT_CUSTOMREQUEST   => 'POST',
			CURLOPT_CONNECTTIMEOUT  => 30,
			CURLOPT_RETURNTRANSFER  => true,
			CURLOPT_TIMEOUT         => 30,
			CURLOPT_SSL_VERIFYHOST  => 0,
			CURLOPT_POSTFIELDS      => json_encode($postData),
			CURLOPT_HTTPHEADER      => [
				'Content-Type: application/json',
				'Authorization: Bearer '.$this->accessToken
			]
		));

		$result = curl_exec(static::$curl);
		curl_close(static::$curl);
	}

	function __construct ()
	{
		if (empty($_GET)) {
			$this->input = json_decode(file_get_contents('php://input'), true);
			$this->parseLineInput();
		} else {
			$this->inputMessage = $this->input = $_GET['test'];
		}

		static::$curl = curl_init($this->lineAPI);

		$this->handleUserMessage();
	}
}

new webhook();