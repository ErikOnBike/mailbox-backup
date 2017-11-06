# mailbox-backup
Use IMAP mailbox as backup storage for individual files.

## Purpose
Having a backup storage with a custom backup policy. Only single file backups are supported. Typical usage: backup your Keepass file.

## Usage
ONLY use this if you really know what you are doing. This is still beta code.

## Usage (if your still confident)
Create a folder (under Inbox) in your IMAP mail account per file you want to backup.
Set the appropriate values in `config.php`. Store both files on your preferred server. Please make sure it is only accessible using SSL/TLS and is stored in a location you do not share with others.

## Usage (if you like CLIs)
Folders in your mailbox are specified using the query string. Folder names are expressed as paths and can have multiple levels like `/backups/keepass` or `/backups/mine/keeweb`.

Adding a file to the backup storage (assuming you created folder `backup` in your IMAP account under your Inbox):

    curl -X PUT --data-binary @keepass.kdbx -u "username:password" https://your.site.org/secret/mailbox-backup.php?/backup

Retrieving the latest backup (all others must be retrieved using a mail client for now):

    curl -u "username:password" https://your.site.org/secret/mailbox-backup.php?/backup

## Usage (if you use Keeweb)
In Keeweb go for the WebDAV storage and choose 'Overwrite kdbx file with PUT' in the settings. For your WebDAV connection use the following URL (assuming here you created folder `backup` and subfolder `keeweb` in your IMAP account under your Inbox). Use your mailbox username and password as credentials (in Keeweb open WebDAV dialog):

    https://your.site.org/secret/mailbox-backup.php?/backup/keeweb

(You need to add the kdbx file to the backup storage using a CLI solution the first time around, since Keeweb does not support putting it there when connecting the first time.)

## What does it do?
The mailbox-backup stores a copy of the delivered file (using HTTP PUT) into the designated folder of your IMAP mailbox. It creates a message per copy and stores the file inside the message as attachment. The message subject contains the timestamp of delivery. The message does not contain any other content.

When the mailbox-backup is queried (using HTTP GET) the most recent file is retrieved from the set of available messages.

The backup policy takes effect when a new copy is deliverd to the mailbox. Based on the policy (in `config.php`) old files will be removed from the mailbox folder.

You can have as many folders (and therefore backup files) as you like. Just do not use a single folder for different files, since it will remove old files and will not be able to distinguish between files.

Only messages with the correct date time format (`<yyyy>-<mm>-<dd>T<HH>:<MM>:<SS>.<SSS>Z`) in the subject will be processed. All others are ignored.

## What do backup policies look like?
You can specify per period how many copies you like to keep. The example `config.php`:

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

## Why not any existing cloud storage?
I'm not a huge fan of existing cloud storage providers and they lack the ability to select a backup policy. Most existing mail providers go to great lengths to make their storage reliable as well, so keeping your backups there seems a good choice as well. My mail provider is also very keen on privacy and makes sure it is stored safely.

## Why PHP?
Seemed to be a safe bet for many hosting providers and has the right libraries needed for the job.
