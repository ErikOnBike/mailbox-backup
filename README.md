# mailbox-backup
Use IMAP mailbox as backup storage for individual files.

## Purpose
Having a backup storage with a custom backup policy. Only single file backups are supported. Typical usage: backup your Keepass file or other encrypted password file.

## Usage
ONLY use this if you really know what you are doing. This is still beta code.

ONLY use it on a webserver which can only be accessed using a SSL/TLS connection.

ONLY use it to store files which are already encrypted or which content is not confidential.

## Usage setup (if your still confident)
Create a folder (under Inbox) in your IMAP mail account per file you want to backup. Preferably use a mail account for the sole purpose of storing backup files. Having the folder prevents junk mail from entering the backup storage system directly.

Set the appropriate values in `config.php` to access this mailbox. Store both files on your preferred webserver. Please make sure it is only accessible using SSL/TLS and is stored in a location you do not share with others.

## Usage (if you like CLIs)
Folders in your mailbox are specified using the HTTP query string. Folder names are expressed as paths and can have multiple levels like `/backups/keepass` or `/backups/mine/keeweb`.

Adding a file to the backup storage (assuming you created folder `backup` in your IMAP account under your Inbox):

    curl -X PUT --data-binary @keepass.kdbx -u "username:password" https://your.site.org/secret/mailbox-backup.php?/backup

Retrieving the latest backup (all others must be retrieved using a mail client for now):

    curl -u "username:password" https://your.site.org/secret/mailbox-backup.php?/backup

The username and password are your mailbox username and password.

You can have a webserver rewrite rule to access the backup storage directly instead of using the query string if you do not like or can use that construct.

## Usage (if you use Keeweb)
In [Keeweb](https://github.com/keeweb/keeweb) go for the WebDAV storage and choose 'Overwrite kdbx file with PUT' in the settings. For your WebDAV connection use the following fields (assuming here you created folder `backup` and subfolder `keeweb` in your IMAP account under your Inbox):

    URL: https://your.site.org/secret/mailbox-backup.php?/backup/keeweb
    Username: <mailbox username>
    Password: <mailbox password>

(You need to add the kdbx file to the backup storage using a CLI solution the first time around, since Keeweb does not yet support putting it there when connecting the first time.)

## What does it do?
The mailbox-backup stores a copy of the delivered file (using HTTP PUT) into the designated folder of your IMAP mailbox. It creates a mail message per copy and stores the file inside the message as attachment. The message subject contains the timestamp of delivery. The message does not contain any other content.

When the mailbox-backup is queried (using HTTP GET) the most recent file is retrieved from the set of available messages.

The backup policy takes effect when a new (version of the) file is deliverd to the mailbox. Based on the policy (in `config.php`) old files will be removed from the mailbox folder after the new file is added.

You can have as many folders (and therefore backup files) as you like. Just do not use a single folder for different files, since the backup policy can and will not distinguish between files. It will remove old files and do so based solely on the timestamp of the file. Finding a file is also difficult even when using a mailclient, since all messages will look similar and have no indication of the file content. Only the attachment size might give it away. 

Only messages with the correct date time format (`<yyyy>-<mm>-<dd>T<HH>:<MM>:<SS>.<SSS>Z`) in the subject will be processed when applying the backup policy. All others are ignored.

## What do backup policies look like?
You can specify per period how many copies you like to keep. The example in `config.php`:

    define('BACKUP_POLICY', [
    	// Keep all files for first two weeks
    	[
    		'maxAge' => '2W',
    		'keep' => '*'
    	],
    	// Keep a single file per week for first two months
    	[
    		'maxAge' => '2M',
    		'keep' => '1/1W'
    	],
    	// Keep two files per month for first year
    	[
    		'maxAge' => '1Y',
    		'keep' => '2/1M'
    	]
    	// As a result: Do not keep any files older than a year
    ]);

You can specify as many rules as you like. If rules overlap in time, the biggest amount of files per period is kept. So if a rule specifies 'keep a single file per day for first two months' and another rule specifies 'keep 3 files per day for first month', the 3 files per day are kept for the first month and a single file is kept for the second month. This is independent of the order in which the rules are specified.

## Why not any existing cloud storage?
I'm not a huge fan of existing cloud storage providers like DropBox or Google Drive and they lack the ability to create or select a backup policy. Most existing mail providers go to great lengths to make their storage reliable as well, so keeping your backups there seems a good choice as well. My mail provider is also very keen on privacy and makes sure it is stored safely.

## Is it secure?
The approach taken for communication is equally secure as using a WebDAV storage with Basic Authentication since it uses the same technique. Again: only use SSL/TLS when connecting to the webserver! The connection to the mailbox is also made using SSL and the mailbox server certificate is validated when the connection is made. Of course, introducing another remote 'layer' or 'proxy' like this will not make the total solution more secure. If a wrong doer is able to hack into your webserver, he/she can manipulate the backup storage application and thereby retrieve access to you mailbox (or prevent you from accessing your mailbox). If you use this for storing your Keepass file, the Keepass content will remain encrypted and should therefore remain secure.

## Why PHP?
Seemed to be a safe bet for many hosting providers and has the right libraries needed for the job.
