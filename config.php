<?php

	define('IMAP_HOST', 'mail.example.org:993');
	define('MAIL_ADDRESS', 'backup <backup@example.org>');
	define('BACKUP_POLICY', [
		// Keep all files for first two weeks
		[
			'maxAge' => '2W',
			'keep' => '*'
		],
		// Keep a single file per week for first two months
		[
			'maxAge' => '2M',
			'keep' => '1W'
		],
		// Keep two files per month for first year
		[
			'maxAge' => '1Y',
			'keep' => '2M'
		]
		// As a result: Do not keep any files older than a year
	]);

?>
