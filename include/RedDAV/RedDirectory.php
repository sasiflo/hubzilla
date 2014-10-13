<?php

namespace RedMatrix\RedDAV;

use Sabre\DAV;

/**
 * @brief RedDirectory class.
 *
 * A class that represents a directory.
 *
 * @extends \Sabre\DAV\Node
 * @implements \Sabre\DAV\ICollection
 * @implements \Sabre\DAV\IQuota
 *
 * @link http://github.com/friendica/red
 * @license http://opensource.org/licenses/mit-license.php The MIT License (MIT)
 */
class RedDirectory extends DAV\Node implements DAV\ICollection, DAV\IQuota {

	/**
	 * @brief The path inside /cloud
	 *
	 * @var string
	 */
	private $red_path;
	private $folder_hash;
	/**
	 * @brief The full path as seen in the browser.
	 * /cloud + $red_path
	 * @todo I think this is not used anywhere, we always strip '/cloud' and only use it in debug
	 * @var string
	 */
	private $ext_path;
	private $root_dir = '';
	private $auth;
	/**
	 * @brief The real path on the filesystem.
	 * The actual path in store/ with the hashed names.
	 *
	 * @var string
	 */
	private $os_path = '';

	/**
	 * @brief Sets up the directory node, expects a full path.
	 *
	 * @param string $ext_path a full path
	 * @param RedBasicAuth &$auth_plugin
	 */
	public function __construct($ext_path, &$auth_plugin) {
		logger('RedDirectory::__construct() ' . $ext_path, LOGGER_DATA);
		$this->ext_path = $ext_path;
		// remove "/cloud" from the beginning of the path
		$this->red_path = ((strpos($ext_path, '/cloud') === 0) ? substr($ext_path, 6) : $ext_path);
		if (! $this->red_path) {
			$this->red_path = '/';
		}
		$this->auth = $auth_plugin;
		$this->folder_hash = '';
		$this->getDir();

		if ($this->auth->browser) {
			$this->auth->browser->set_writeable();
		}
	}

	private function log() {
		logger('RedDirectory::log() ext_path ' . $this->ext_path, LOGGER_DATA);
		logger('RedDirectory::log() os_path ' . $this->os_path, LOGGER_DATA);
		logger('RedDirectory::log() red_path ' . $this->red_path, LOGGER_DATA);
	}

	/**
	 * @brief Returns an array with all the child nodes.
	 *
	 * @throws DAV\Exception\Forbidden
	 * @return array DAV\INode[]
	 */
	public function getChildren() {
		logger('RedDirectory::getChildren() called for ' . $this->ext_path, LOGGER_DATA);
		$this->log();

		if (get_config('system', 'block_public') && (! $this->auth->channel_id) && (! $this->auth->observer)) {
			throw new DAV\Exception\Forbidden('Permission denied.');
		}

		if (($this->auth->owner_id) && (! perm_is_allowed($this->auth->owner_id, $this->auth->observer, 'view_storage'))) {
			throw new DAV\Exception\Forbidden('Permission denied.');
		}

		$contents = RedCollectionData($this->red_path, $this->auth);
		return $contents;
	}

	/**
	 * @brief Returns a child by name.
	 *
	 *
	 * @throw DAV\Exception\Forbidden
	 * @throw DAV\Exception\NotFound
	 * @param string $name
	 */
	public function getChild($name) {
		logger('RedDirectory::getChild(): ' . $name, LOGGER_DATA);

		if (get_config('system', 'block_public') && (! $this->auth->channel_id) && (! $this->auth->observer)) {
			throw new DAV\Exception\Forbidden('Permission denied.');
		}

		if (($this->auth->owner_id) && (! perm_is_allowed($this->auth->owner_id, $this->auth->observer, 'view_storage'))) {
			throw new DAV\Exception\Forbidden('Permission denied.');
		}

		if ($this->red_path === '/' && $name === 'cloud') {
			return new RedDirectory('/cloud', $this->auth);
		}

		$x = RedFileData($this->ext_path . '/' . $name, $this->auth);
		if ($x) {
			return $x;
		}

		throw new DAV\Exception\NotFound('The file with name: ' . $name . ' could not be found.');
	}

	/**
	 * @brief Returns the name of the directory.
	 *
	 * @return string
	 */
	public function getName() {
		logger('RedDirectory::getName() returns: ' . basename($this->red_path), LOGGER_DATA);
		return (basename($this->red_path));
	}

	/**
	 * @brief Renames the directory.
	 *
	 * @todo handle duplicate directory name
	 *
	 * @throw DAV\Exception\Forbidden
	 * @param string $name The new name of the directory.
	 * @return void
	 */
	public function setName($name) {
		logger('RedDirectory::setName(): ' . basename($this->red_path) . ' -> ' . $name, LOGGER_DATA);

		if ((! $name) || (! $this->auth->owner_id)) {
			logger('RedDirectory::setName(): permission denied');
			throw new DAV\Exception\Forbidden('Permission denied.');
		}

		if (! perm_is_allowed($this->auth->owner_id, $this->auth->observer, 'write_storage')) {
			logger('RedDirectory::setName(): permission denied');
			throw new DAV\Exception\Forbidden('Permission denied.');
		}

		list($parent_path, ) = DAV\URLUtil::splitPath($this->red_path);
		$new_path = $parent_path . '/' . $name;

		$r = q("UPDATE attach SET filename = '%s' WHERE hash = '%s' AND uid = %d LIMIT 1",
			dbesc($name),
			dbesc($this->folder_hash),
			intval($this->auth->owner_id)
		);

		$this->red_path = $new_path;
	}

	/**
	 * @brief Creates a new file in the directory.
	 *
	 * Data will either be supplied as a stream resource, or in certain cases
	 * as a string. Keep in mind that you may have to support either.
	 *
	 * After successful creation of the file, you may choose to return the ETag
	 * of the new file here.
	 *
	 * @throws DAV\Exception\Forbidden
	 * @param string $name Name of the file
	 * @param resource|string $data Initial payload
	 * @return null|string ETag
	 */
	public function createFile($name, $data = null) {
		logger('RedDirectory::createFile(): ' . $name, LOGGER_DATA);

		if (! $this->auth->owner_id) {
			logger('RedDirectory::createFile(): permission denied');
			throw new DAV\Exception\Forbidden('Permission denied.');
		}

		if (! perm_is_allowed($this->auth->owner_id, $this->auth->observer, 'write_storage')) {
			logger('RedDirectory::createFile(): permission denied');
			throw new DAV\Exception\Forbidden('Permission denied.');
		}

		$mimetype = z_mime_content_type($name);

		$c = q("SELECT * FROM channel WHERE channel_id = %d AND NOT (channel_pageflags & %d) LIMIT 1",
			intval($this->auth->owner_id),
			intval(PAGE_REMOVED)
		);

		if (! $c) {
			logger('RedDirectory::createFile(): no channel');
			throw new DAV\Exception\Forbidden('Permission denied.');
		}

		$filesize = 0;
		$hash = random_string();

		$r = q("INSERT INTO attach ( aid, uid, hash, creator, filename, folder, flags, filetype, filesize, revision, data, created, edited, allow_cid, allow_gid, deny_cid, deny_gid )
			VALUES ( %d, %d, '%s', '%s', '%s', '%s', '%s', '%s', %d, %d, '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) ",
			intval($c[0]['channel_account_id']),
			intval($c[0]['channel_id']),
			dbesc($hash),
			dbesc($this->auth->observer),
			dbesc($name),
			dbesc($this->folder_hash),
			dbesc(ATTACH_FLAG_OS),
			dbesc($mimetype),
			intval($filesize),
			intval(0),
			dbesc($this->os_path . '/' . $hash),
			dbesc(datetime_convert()),
			dbesc(datetime_convert()),
			dbesc($c[0]['channel_allow_cid']),
			dbesc($c[0]['channel_allow_gid']),
			dbesc($c[0]['channel_deny_cid']),
			dbesc($c[0]['channel_deny_gid'])
		);

		$f = 'store/' . $this->auth->owner_nick . '/' . (($this->os_path) ? $this->os_path . '/' : '') . $hash;

		// returns the number of bytes that were written to the file, or FALSE on failure
		$size = file_put_contents($f, $data);
		// delete attach entry if file_put_contents() failed
		if ($size === false) {
			logger('RedDirectory::createFile(): file_put_contents() failed for ' . $name, LOGGER_DEBUG);
			attach_delete($c[0]['channel_id'], $hash);
			return;
		}

		// returns now
		$edited = datetime_convert();

		// updates entry with filesize and timestamp
		$d = q("UPDATE attach SET filesize = '%s', edited = '%s' WHERE hash = '%s' AND uid = %d LIMIT 1",
			dbesc($size),
			dbesc($edited),
			dbesc($hash),
			intval($c[0]['channel_id'])
		);

		// update the folder's lastmodified timestamp
		$e = q("UPDATE attach SET edited = '%s' WHERE hash = '%s' AND uid = %d LIMIT 1",
			dbesc($edited),
			dbesc($this->folder_hash),
			intval($c[0]['channel_id'])
		);

		$maxfilesize = get_config('system', 'maxfilesize');
		if (($maxfilesize) && ($size > $maxfilesize)) {
			attach_delete($c[0]['channel_id'], $hash);
			return;
		}

		// check against service class quota
		$limit = service_class_fetch($c[0]['channel_id'], 'attach_upload_limit');
		if ($limit !== false) {
			$x = q("SELECT SUM(filesize) AS total FROM attach WHERE aid = %d ",
				intval($c[0]['channel_account_id'])
			);
			if (($x) && ($x[0]['total'] + $size > $limit)) {
				logger('reddav: service class limit exceeded for ' . $c[0]['channel_name'] . ' total usage is ' . $x[0]['total'] . ' limit is ' . $limit);
				attach_delete($c[0]['channel_id'], $hash);
				return;
			}
		}
	}

	/**
	 * @brief Creates a new subdirectory.
	 *
	 * @param string $name the directory to create
	 * @return void
	 */
	public function createDirectory($name) {
		logger('RedDirectory::createDirectory(): ' . $name, LOGGER_DEBUG);

		if ((! $this->auth->owner_id) || (! perm_is_allowed($this->auth->owner_id, $this->auth->observer, 'write_storage'))) {
			throw new DAV\Exception\Forbidden('Permission denied.');
		}

		$r = q("SELECT * FROM channel WHERE channel_id = %d AND NOT (channel_pageflags & %d) LIMIT 1",
			intval($this->auth->owner_id),
			intval(PAGE_REMOVED)
		);

		if ($r) {
			$result = attach_mkdir($r[0], $this->auth->observer, array('filename' => $name, 'folder' => $this->folder_hash));
			if (! $result['success']) {
				logger('RedDirectory::createDirectory(): ' . print_r($result, true), LOGGER_DEBUG);
			}
		}
	}

	/**
	 * @brief Checks if a child exists.
	 *
	 * @param string $name
	 * @return boolean
	 */
	public function childExists($name) {
		// On /cloud we show a list of available channels.
		// @todo what happens if no channels are available?
		if ($this->red_path === '/' && $name === 'cloud') {
			logger('RedDirectory::childExists() /cloud: true', LOGGER_DATA);
			return true;
		}

		$x = RedFileData($this->ext_path . '/' . $name, $this->auth, true);
		logger('RedFileData returns: ' . print_r($x, true), LOGGER_DATA);
		if ($x)
			return true;
		return false;
	}

	/**
	 * @todo add description of what this function does.
	 *
	 * @throw DAV\Exception\NotFound
	 * @return void
	 */
	function getDir() {
		logger('RedDirectory::getDir(): ' . $this->ext_path, LOGGER_DEBUG);
		$this->auth->log();

		$file = $this->ext_path;

		$x = strpos($file, '/cloud');
		if ($x === false)
			return;
		if ($x === 0) {
			$file = substr($file, 6);
		}

		if ((! $file) || ($file === '/')) {
			return;
		}

		$file = trim($file, '/');
		$path_arr = explode('/', $file);

		if (! $path_arr)
			return;

		logger('RedDirectory::getDir(): path: ' . print_r($path_arr, true), LOGGER_DATA);

		$channel_name = $path_arr[0];

		$r = q("SELECT channel_id FROM channel WHERE channel_address = '%s' AND NOT ( channel_pageflags & %d ) LIMIT 1",
			dbesc($channel_name),
			intval(PAGE_REMOVED)
		);

		if (! $r) {
			throw new DAV\Exception\NotFound('The file with name: ' . $channel_name . ' could not be found.');
			return;
		}

		$channel_id = $r[0]['channel_id'];
		$this->auth->owner_id = $channel_id;
		$this->auth->owner_nick = $channel_name;

		$path = '/' . $channel_name;
		$folder = '';
		$os_path = '';

		for ($x = 1; $x < count($path_arr); $x++) {
			$r = q("select id, hash, filename, flags from attach where folder = '%s' and filename = '%s' and uid = %d and (flags & %d)",
				dbesc($folder),
				dbesc($path_arr[$x]),
				intval($channel_id),
				intval(ATTACH_FLAG_DIR)
			);

			if ($r && ( $r[0]['flags'] & ATTACH_FLAG_DIR)) {
				$folder = $r[0]['hash'];
				if (strlen($os_path))
					$os_path .= '/';
				$os_path .= $folder;

				$path = $path . '/' . $r[0]['filename'];
			}
		}
		$this->folder_hash = $folder;
		$this->os_path = $os_path;
		return;
	}

	/**
	 * @brief Returns the last modification time for the directory, as a UNIX
	 *        timestamp.
	 *
	 * It looks for the last edited file in the folder. If it is an empty folder
	 * it returns the lastmodified time of the folder itself, to prevent zero
	 * timestamps.
	 *
	 * @return int last modification time in UNIX timestamp
	 */
	public function getLastModified() {
		$r = q("SELECT edited FROM attach WHERE folder = '%s' AND uid = %d ORDER BY edited DESC LIMIT 1",
			dbesc($this->folder_hash),
			intval($this->auth->owner_id)
		);
		if (! $r) {
			$r = q("SELECT edited FROM attach WHERE hash = '%s' AND uid = %d LIMIT 1",
				dbesc($this->folder_hash),
				intval($this->auth->owner_id)
			);
			if (! $r)
				return '';
		}
		return datetime_convert('UTC', 'UTC', $r[0]['edited'], 'U');
	}

	/**
	 * @brief Return quota usage.
	 *
	 * Do guests relly see the used/free values from filesystem of the complete store directory?
	 *
	 * @return array with used and free values in bytes.
	 */
	public function getQuotaInfo() {
		// values from the filesystem of the complete <i>store/</i> directory
		$limit = disk_total_space('store');
		$free = disk_free_space('store');

		if ($this->auth->owner_id) {
			$c = q("select * from channel where channel_id = %d and not (channel_pageflags & %d) limit 1",
				intval($this->auth->owner_id),
				intval(PAGE_REMOVED)
			);

			$ulimit = service_class_fetch($c[0]['channel_id'], 'attach_upload_limit');
			$limit = (($ulimit) ? $ulimit : $limit);

			$x = q("select sum(filesize) as total from attach where aid = %d",
				intval($c[0]['channel_account_id'])
			);
			$free = (($x) ? $limit - $x[0]['total'] : 0);
		}

		return array(
			$limit - $free,
			$free
		);
	}
}