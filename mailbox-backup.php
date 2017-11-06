<?php

	// Retrieve configuration
	require_once('config.php');

	// Constants
	define('DATE_TIME_FORMAT', 'Y-m-d\TH:i:s.v\Z');		// <yyyy>-<mm>-<dd>T<HH>:<MM>:<SS>.<SSS>Z
	define('CREATE_FROM_STRING_FORMAT', 'Y-m-d*H:i:s.u*');	// Same as above except replaced fixed characters T and Z and changed milliseconds to microseconds (sinds microseconds are not supported during creation)
	define('DATE_TIME_REGEX', '\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z');	// Same as above in RegExp format
	define('MAX_CONNECT_ATTEMPTS', 3);
	define('RESPONSE_SUCCESS', 200);
	define('RESPONSE_RESOURCE_CREATED', 201);
	define('RESPONSE_BAD_REQUEST', 400);
	define('RESPONSE_UNAUTHORIZED', 401);
	define('RESPONSE_RESOURCE_NOT_FOUND', 404);
	define('RESPONSE_INTERNAL_ERROR', 500);

	// Get current date (milliseconds accuracy)
	function now() {
		// PHP pre 7.1: get time with milliseconds accuracy
		return DateTime::createFromFormat('U.u', number_format(microtime(true), 3, '.', ''));
	}

	// MailboxFile class
	class MailboxFile {

		// Private instance variables
		private $id;
		private $date;
		private $content;

		// Private constructor
		private function __construct($id, $date, $content) {
			$this->id = $id;
			$this->date = $date;
			$this->content = $content;
		}

		// Public class methods
		public static function fromMailbox($id, $dateString) {
			$date = DateTime::createFromFormat(CREATE_FROM_STRING_FORMAT, $dateString);
			if($date === FALSE) {
				error_log('MailboxBackupSystem: Internal failure creating date/time from string "' . $dateString . '". Continuing with fake date of 2000-01-01.');
				$date = DateTime::createFromFormat('Y-m-d H:i:s', '2000-01-01 0:0:0');
			}
			return new MailboxFile($id, $date, FALSE);
		}
		public static function fromContent($content) {
			$date = now();
			return new MailboxFile(FALSE, $date, $content);
		}
		public static function compareNewestFirst($a, $b) {
			if($a->date < $b->date) {
				return +1;
			} else if($a->date > $b->date) {
				return -1;
			} else {
				return 0;
			}
		}

		// Public methods
		public function getId() {
			return $this->id;
		}
		public function getDate() {
			return $this->date;
		}
		public function getDateString() {
			return $this->date->format(DATE_TIME_FORMAT);
		}
		public function getContent() {
			return $this->content;
		}
		public function isNew() {
			return $this->id === FALSE;
		}
	}

	// MailboxBackupSystem class
	class MailboxBackupSystem {

		// Private instance variables
		private $host;
		private $folder;
		private $username;
		private $password;
		private $imapStream;

		// Public constructor/destructor
		public function __construct($folder, $username, $password) {
			$this->host = IMAP_HOST;
			$this->folder = $folder;
			$this->username = $username;
			$this->password = $password;
			$this->imapStream = FALSE;
		}
		public function __destruct() {
			$this->close();
		}

		// Public methods
		public function open() {

			// Validate current state
			if(!$this->imapStream) {

				// Open mailbox
				$this->imapStream = imap_open($this->getMailboxFolder(), $this->username, $this->password, 0, MAX_CONNECT_ATTEMPTS);
				if($this->imapStream === FALSE) {
					throw new Exception('Cannot open mailbox at host "' . $this->host . '" with the provided credentials.');
				}
			}

			return $this;
		}
		public function close() {

			// Validate current state
			if($this->imapStream) {

				// Remove any pending errors (will otherwise create PHP Notices)
				imap_errors();

				// Close current mailbox
				if(!imap_close($this->imapStream)) {
					throw new Exception('Cannot close mailbox at host "' . $this->host . '".');
				}
				$this->imapStream = FALSE;
			}

			return $this;
		}
		public function getFiles() {
			$files = [];

			// Read in headers
			$headers = imap_headers($this->imapStream);
			if($headers === FALSE) {
				throw new Exception('Cannot read mailbox headers for folder "' . $this->folder . '" on host "' . $this->host . '".');
			}

			// Convert header lines into files
			foreach($headers as $header) {

				// Extract message id and date from header line
				//
				// Header line has format: <flags><msg-nr>)<date> <sender> <subject-short> (<size>)
				//	flags: alphabetical characters (ignored)
				//	msg-nr: numeric id
				//	date: short form containing both numbers and letters (ignored)
				//	sender: mail address (ignored)
				//	subject-short: subject limited in size, will contain date in DATE_TIME_FORMAT if saved by the receiver
				//	size: size of message (not of file inside message, ignored)
				$matchResult = preg_match('/^[^\d]*(\d+)\).*(' . DATE_TIME_REGEX . ').*/', $header, $matches);
				if($matchResult === FALSE) {
					throw new Exception('Cannot parse mail header "' . $header . '".');
				}

				// Only handle headers which match the specified format
				if($matchResult === 1) {

					// Add file to collection
					$id = $matches[1];
					$date = $matches[2];
					$files[] = MailboxFile::fromMailbox($id, $date);
				}
			}

			return $files;
		}
		public function readFile($file) {

			// Validate input
			if($file->isNew()) {
				throw new Exception('Only existing files can be read from the mailbox.');
			}

			// Read message structure
			$messageId = $file->getId();
			$fileContent = FALSE;
			$structure = imap_fetchstructure($this->imapStream, $messageId);
			if(isset($structure->parts) && count($structure->parts) > 0) {
				foreach($structure->parts as $partNumber => $part) {
					if(!$fileContent) {

						// Part numbers are 1, 2, etc and with subparts 1.1, 1.2, etc
						$fileContent = $this->readPart($messageId, $part, $partNumber + 1);
					}
				}
			}

			return $fileContent;
		}
		private function readPart($messageId, $part, $partNumber) {

			// Fetch part data
			$partData = imap_fetchbody($this->imapStream, $messageId, $partNumber);

			// Check parameters to decide if this part is attachment
			$isAttachment = FALSE;
			$parametersArray = [];
			if($part->ifdparameters) {
				$parametersArray[] = $part->dparameters;
			}
			if($part->ifparameters) {
				$parametersArray[] = $part->parameters;
			}
			foreach($parametersArray as $parameters) {
				foreach($parameters as $parameter) {
					if(strtolower($parameter->attribute) === 'filename') {
						$isAttachment = TRUE;
					}
				}
			}

			// Answer attachment if found, otherwise try to find attachment in subparts
			if($isAttachment) {

				// Decode part data (if necessary)
				if($part->encoding === ENCBASE64) {
					$partData = base64_decode($partData);
				}

				return $partData;
			} else {

				// Find attachment in subparts
				if(isset($part->parts) && count($part->parts) > 0) {
					$fileContent = FALSE;
					foreach($part->parts as $subPartNumber => $subPart) {
						if(!$fileContent) {
							$fileContent = $this->readPart($messageId, $subPart, $partNumber . '.' . ($subPartNumber + 1));
						}
					}
					return $fileContent;
				}
				return FALSE;
			}
		}
		public function addFile($file) {

			// Validate input
			if(!$file->isNew()) {
				throw new Exception('Only new files can be added to the mailbox.');
			}

			// Create attachment from file content
			$attachment = chunk_split(base64_encode($file->getContent()));

			// Create message
			$message =
				"From: " . MAIL_ADDRESS . "\r\n" .
				"To: " . MAIL_ADDRESS . "\r\n" .
				"Date: " . date('Y-m-d H:i:s') . "\r\n" .
				"Subject: " . $file->getDateString() . "\r\n" .
				"MIME-Version: 1.0\r\n" .
				"Content-Type: multipart/mixed; boundary=\"AttachmentBoundary\"\r\n" .
				"\r\n" .
				"\r\n" .
				"--AttachmentBoundary\r\n" .
				"Content-Type: application/octet-stream; name=\"backup.bin\"\r\n" .
				"Content-Transfer-Encoding: base64\r\n" .
				"Content-Disposition: attachment; filename=\"backup.bin\"\r\n" .
				"\r\n" .
				$attachment . "\r\n" .
				"\r\n" .
				"\r\n" .
				"\r\n" .
				"--AttachmentBoundary--\r\n\r\n"
			;

			// Create the message
			if(!imap_append($this->imapStream, $this->getMailboxFolder(), $message)) {
				throw new Exception('Cannot add message to folder "' . $this->folder . '" on host "' . $this->host . '".');
			}

			return $this;
		}
		public function removeFile($file) {

			// Validate input
			if($file->isNew()) {
				throw new Exception('Only existing files can be removed from the mailbox.');
			}

			// Remove message
			imap_delete($this->imapStream, $file->getId());
		}

		// Private methods
		private function getMailboxRoot() {
			return '{' . $this->host . '/service=imap/ssl/validate-cert}INBOX';
		}
		private function getMailboxFolder() {
			return $this->getMailboxRoot() . str_replace('/', '.', $this->folder);
		}
	}

	// BackupRequest class
	class BackupRequest {

		// Private instance variables
		private $parameters;
		private $backupPolicy;
		private $responseCode;
		private $action;
		private $username;
		private $password;
		private $folder;

		// Public constructor
		public function __construct($parameters, $backupPolicy) {
			$this->parameters = $parameters;
			$this->backupPolicy = $backupPolicy;
			$this->responseCode = FALSE;
		}

		// Public methods
		public function handle() {

			// Validate receiver
			if($this->validate()) {

				// Perform action
				try {
					$this->performAction();
				} catch(Exception $e) {
					error_log('MailboxBackupSystem Exception: ' . $e->getMessage());

					$this->responseCode = RESPONSE_INTERNAL_ERROR;
				}
			}

			// Set response code
			if($this->responseCode) {
				http_response_code($this->responseCode);
			}
		}

		// Private methods
		private function validate() {
			if(php_sapi_name() === 'cli' || !isset($this->parameters) || !is_array($this->parameters)) {
				error_log('This application should be invoked by a webserver.');
				return FALSE;
			}
			if(!array_key_exists('REQUEST_METHOD', $this->parameters)) {
				error_log('MailboxBackupSystem: No request method present.');

				// Bad request
				$this->responseCode = RESPONSE_BAD_REQUEST;
				return FALSE;
			}
			if(!array_key_exists('QUERY_STRING', $this->parameters)) {
				error_log('MailboxBackupSystem: No QUERY_STRING present (which should contain the folder name).');

				// Bad request
				$this->responseCode = RESPONSE_BAD_REQUEST;
				return FALSE;
			}
			if(preg_match('/^\/[a-zA-Z0-9_\-\/]+$/', $this->parameters['QUERY_STRING']) !== 1) {
				error_log('MailboxBackupSystem: Invalid folder name. Allowed characters [a-zA-Z0-9\-_]. Every (sub)folder should start with a "/". Received "' . $this->parameters['QUERY_STRING'] . '".');

				// Resource not found
				$this->responseCode = RESPONSE_RESOURCE_NOT_FOUND;
				return FALSE;
			}
			if(!array_key_exists('PHP_AUTH_USER', $this->parameters) || !array_key_exists('PHP_AUTH_PW', $this->parameters)) {
				header('WWW-Authenticate: Basic realm="Mailbox Backup System"');

				// Unauthorized (no authorization information provided)
				$this->responseCode = RESPONSE_UNAUTHORIZED;
				return FALSE;
			}

			// Store required parameters
			$this->action = $this->parameters['REQUEST_METHOD'];
			$this->username = $this->parameters['PHP_AUTH_USER'];
			$this->password = $this->parameters['PHP_AUTH_PW'];
			$this->folder = $this->parameters['QUERY_STRING'];

			return TRUE;
		}
		private function performAction() {

			// Set header fields to prevent caching
			header('Cache-Control: no-cache, no-store, must-revalidate');
			header('Expires: 0');

			// Open mailbox backup system on the provided folder
			$mailboxBackupSystem = new MailboxBackupSystem($this->folder, $this->username, $this->password);
			$mailboxBackupSystem->open();

			// Read existing files and order by date (newest first)
			$files = $mailboxBackupSystem->getFiles();
			$lastDate = FALSE;
			if(count($files) > 0) {
				usort($files, "MailboxFile::compareNewestFirst");
				$lastDate = $files[0]->getDate();
			}

			// Handle request action
			switch($this->action) {
				case 'HEAD':
					if($lastDate) {

						// Only set necessary header
						header('Last-Modified: ' . $lastDate->format('D, d M Y H:i:s') . ' GMT');

						// Success
						$this->responseCode = RESPONSE_SUCCESS;
					} else {

						// No resource available, ie Resource not found
						$this->responseCode = RESPONSE_RESOURCE_NOT_FOUND;
					}
				break;
				case 'GET':
					if($lastDate) {

						// Read file content
						$fileContent = $mailboxBackupSystem->readFile($files[0]);

						// Set necessary headers
						header('Last-Modified: ' . $lastDate->format('D, d M Y H:i:s') . ' GMT');
						header("Content-Length: " . strlen($fileContent));
						header('Content-Transfer-Encoding: binary');

						// Add file content
						print($fileContent);

						// Success
						$this->responseCode = RESPONSE_SUCCESS;
					} else {

						// No resource available, ie Resource not found
						$this->responseCode = RESPONSE_RESOURCE_NOT_FOUND;
					}
				break;
				case 'PUT':

					// Read content
					$fileContent = file_get_contents('php://input');
					if($fileContent !== FALSE) {

						// Create file
						$file = MailboxFile::fromContent($fileContent);

						// Store file
						$mailboxBackupSystem->addFile($file);

						// Apply backup policy (ie remove files not within policy)
						$this->applyPolicy($mailboxBackupSystem, $files);

						// Resource created
						$this->responseCode = RESPONSE_RESOURCE_CREATED;
					} else {
						error_log('MailboxBackupSystem: No file content for PUT.');

						// Bad request?
						$this->responseCode = RESPONSE_BAD_REQUEST;
					}
				break;
				default:
					error_log('MailboxBackupSystem: Unknown method/action "' . $this->action . '".');

					// Bad request
					$this->responseCode = RESPONSE_BAD_REQUEST;
				break;
			}
		}
		private function applyPolicy($mailboxBackupSystem, $files) {

			// Select files to keep
			$filesToKeep = $this->selectFilesToKeep($files);

			// Select files to remove (cannot use array_diff here, because of used string comparison)
			$filesToRemove = [];
			foreach($files as $file) {
				if(!self::isFilePresent($filesToKeep, $file)) {
					$filesToRemove[] = $file;
				}
			}

			// Remove files
			foreach($filesToRemove as $file) {
				$mailboxBackupSystem->removeFile($file);
			}
		}
		private function selectFilesToKeep($files) {

			// Iterate backup policy rules
			$filesToKeep = [];
			foreach($this->backupPolicy as $backupRule) {

				// Extract rule values
				$maxAge = $backupRule['maxAge'];
				$keepCount = FALSE;
				$keepPeriod = '1D';
				if($backupRule['keep'] !== '*') {
					if(preg_match('/^(\d+)\/(\d+[YMWD])$/', $backupRule['keep'], $keepMatches) === 1) {
						$keepCount = intval($keepMatches[1]);
						$keepPeriod = $keepMatches[2];
					} else {
						error_log('Invalid "keep" value in backup rule: "' . $backupRule['keep'] . '". Keeping all files.');
					}
				}

				// Calculate end date based on age (subtracting age from 'now')
				$now = now();
				$endDate = now();
				$endDate->sub(new DateInterval('P' . $maxAge));

				// Select all files which match the backup rule period and are not already marked for keeping
				$newFilesWithinPeriod = $this->selectNewFilesWithinPeriod($files, $endDate, $filesToKeep);

				// Reverse file order so oldest files are kept (within period) using following algorithm
				$newFilesWithinPeriod = array_reverse($newFilesWithinPeriod);

				// Select the files to keep by going 'back' to 'now' and selecting $keepCount files per sub-period
				while($endDate < $now) {

					// Calculate sub-period
					$nextEndDate = clone $endDate;
					$nextEndDate->add(new DateInterval('P' . $keepPeriod));

					// Count how many files are already to be kept (matching this sub-period)
					if($keepCount !== FALSE) {
						$foundCount = self::countFilesMatchingPeriod($filesToKeep, $endDate, $nextEndDate);
					}

					// Find additional files which match this sub-period (if still more are required)
					if($keepCount === FALSE || $foundCount < $keepCount) {
						$newFilesWithinSubPeriod = array_filter($newFilesWithinPeriod, function($file) use($endDate, $nextEndDate) {
							return self::fileMatchesPeriod($file, $endDate, $nextEndDate);
						});

						// Add required number of files
						if($keepCount === FALSE || count($newFilesWithinSubPeriod) <= $keepCount - $foundCount) {
							$filesToKeep = array_merge($filesToKeep, $newFilesWithinSubPeriod);
						} else {
							$filesToKeep = array_merge($filesToKeep, self::selectCountFiles($newFilesWithinSubPeriod, $keepCount - $foundCount));
						}
					}

					// Skip to next period
					$endDate = $nextEndDate;
				}
			}

			return $filesToKeep;
		}
		private static function selectNewFilesWithinPeriod($files, $endDate, $filesToKeep) {
			return array_filter($files, function($file) use($filesToKeep, $endDate) {

				// Test if file is already marked for being kept
				if(self::isFilePresent($filesToKeep, $file)) {
					return FALSE;
				}

				// Compare if file has date within period (from now to $endDate)
				return $file->getDate() >= $endDate;
			});
		}
		private static function selectCountFiles($files, $count) {

			// Beware: this method does not select files based on their dates,
			//	but simply on their position within the array.

			// Chop array of files into $count number of chunks
			$filesChunks = array_chunk($files, ceil(count($files) / $count));

			// Select first file from every chunk
			return array_reduce($filesChunks, function($result, $filesChunk) {
				$result[] = $filesChunk[0];
				return $result;
			}, []);
		}
		private static function countFilesMatchingPeriod($files, $endDate, $nextEndDate) {
			return array_reduce($files, function($count, $file) use($endDate, $nextEndDate) {
				if(self::fileMatchesPeriod($file, $endDate, $nextEndDate)) {
					++$count;
				}
				return $count;
			});
		}
		private static function fileMatchesPeriod($file, $endDate, $nextEndDate) {
			$fileDate = $file->getDate();
			return $fileDate >= $endDate && $fileDate < $nextEndDate;
		}
		private static function isFilePresent($files, $file) {
			return count(array_filter($files, function($fileToTest) use($file) {
				return $fileToTest === $file;
			})) > 0;
		}
	}

	// Create backup request and handle it
	$backupRequest = new BackupRequest($_SERVER, BACKUP_POLICY);
	$backupRequest->handle();
?>
