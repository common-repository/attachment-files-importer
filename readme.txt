=== Attachment Files Importer ===
Contributors: KLicheR
Donate link: 
Tags: importer, import, attachments, files
Requires at least: 3.5
Tested up to: 3.6
Stable tag: 0.3.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Scan your Wordpress installation for all missing attachment files and download them from another Wordpress installation.

== Description ==

Scan your Wordpress installation for all missing attachment files, download them from another Wordpress installation and resize them with your defined image sizes.

Know that the plugin will not create new media on your installation database, it'll just use the existings entries of medias in your database to retrieve the physical files on another Wordpress installation.

**E.g.**: *http://serverA.com* have an entry for the *mediaX* in his database. The path for the physical file is supposed to be *http://serverA.com/wp-content/uploads/2013/06/mediaX.jpg* but it's not here. If you know that this same media exists on *http://serverB.com* + the physical file, you can use this plugin on *http://serverA.com*, specifying *http://serverB.com* in the *Server URL* field and the plugin will search the file here: *http://serverB.com/wp-content/uploads/2013/06/mediaX.jpg*. If it find it, it will be downloaded here: *http://serverA.com/wp-content/uploads/2013/06/mediaX.jpg*.

= Do you need this? =
This plugin is useful if you have multiple instances of a same project installed on multiple servers.

For example, a team of developers, each of them having a copy of the "master" installation located at the production server. Periodically, the database of the "master" installation is given to the developers who update their code through a [VCS](https://en.wikipedia.org/wiki/Revision_control) (like SVN, GIT, Mercurial). There is chance that the *uploads* folder is not versionned, which is good, so the plugin is useful here to download the medias from the "master" installation.

= How tu use it? =
In the admin, go to *Tools/Import* and choose the *Attachment files* importer. From here, enter the URL of the Wordpress installation that contain the physical files of medias and click *Import*.

Existing files will not be overriden.

= Debug =
The plugin is in an early stage and bugs may occurs. If you want to show a detailled report of the importation process, add this line to your __wp-config.php__ file:

`define('ATTACHMENT_FILES_IMPORT_DEBUG', true);`

== Installation ==

1. Upload the `attachment-files-importer` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to the Tools -> Import screen, click on Attachment Files