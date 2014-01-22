<?php
/**
 * @author Tomáš Blatný
 */

namespace greeny\MailLibrary\Drivers;

use greeny\MailLibrary\ContactList;
use greeny\MailLibrary\DriverException;
use greeny\MailLibrary\Structures\IStructure;
use greeny\MailLibrary\Structures\ImapStructure;
use greeny\MailLibrary\Mail;
use DateTime;

class ImapDriver implements IDriver
{
	/** @var string */
	protected $username;

	/** @var string */
	protected $password;

	/** @var resource */
	protected $resource;

	/** @var string */
	protected $server;

	/** @var string */
	protected $currentMailbox = NULL;
	
	protected static $filterTable = array(
		Mail::ANSWERED => '%bANSWERED',
		Mail::BCC => 'BCC "%s"',
		Mail::BEFORE => 'BEFORE "%d"',
		Mail::BODY => 'BODY "%s"',
		Mail::CC => 'CC "%s"',
		Mail::DELETED => '%bDELETED',
		Mail::FLAGGED => '%bFLAGGED',
		Mail::FROM => 'FROM "%s"',
		Mail::KEYWORD => 'KEYWORD "%s"',
		Mail::NEW_MESSAGES => 'NEW',
		Mail::NOT_KEYWORD => 'UNKEYWORD "%s"',
		Mail::OLD_MESSAGES => 'OLD',
		Mail::ON => 'ON "%d"',
		Mail::RECENT => 'RECENT',
		Mail::SEEN => '%bSEEN',
		Mail::SINCE => 'SINCE "%d"',
		Mail::SUBJECT => 'SUBJECT "%s"',
		Mail::TEXT => 'TEXT "%s"',
		Mail::TO => 'TO "%s"',
	);

	protected static $contactHeaders = array(
		'to',
		'from',
		'cc',
		'bcc',
	);

	public function __construct($username, $password, $host, $port = 993, $ssl = TRUE)
	{
		$ssl = $ssl ? '/ssl' : '';
		$this->server = '{'.$host.':'.$port.'/imap'.$ssl.'}';
		$this->username = $username;
		$this->password = $password;
	}

	/**
	 * Connects to server
	 *
	 * @throws DriverException if connecting fails
	 */
	public function connect()
	{
		if(!$this->resource = @imap_open($this->server, $this->username, $this->password, CL_EXPUNGE)) { // @ - to allow throwing exceptions
			throw new DriverException("Cannot connect to IMAP server: " . imap_last_error());
		}
	}

	/**
	 * Flushes changes to server
	 *
	 * @throws DriverException if flushing fails
	 */
	public function flush()
	{
		imap_expunge($this->resource);
	}

	/**
	 * Gets all mailboxes
	 *
	 * @return array of string
	 * @throws DriverException
	 */
	public function getMailboxes()
	{
		$mailboxes = array();
		$foo = imap_list($this->resource, $this->server, '*');
		if(!$foo) {
			throw new DriverException("Cannot get mailboxes from server: " . imap_last_error());
		}
		foreach($foo as $mailbox) {
			$mailboxes[] = mb_convert_encoding(str_replace($this->server, '', $mailbox), 'UTF8', 'UTF7-IMAP');
		}
		return $mailboxes;
	}

	/**
	 * Creates new mailbox
	 *
	 * @param string $name
	 * @throws DriverException
	 */
	public function createMailbox($name)
	{
		if(!imap_createmailbox($this->resource, $this->server . $name)) {
			throw new DriverException("Cannot create mailbox '$name': " . imap_last_error());
		}
	}

	/**
	 * Renames mailbox
	 *
	 * @param string $from
	 * @param string $to
	 * @throws DriverException
	 */
	public function renameMailbox($from, $to)
	{
		if(!imap_renamemailbox($this->resource, $this->server . $from, $this->server . $to)) {
			throw new DriverException("Cannot rename mailbox from '$from' to '$to': " . imap_last_error());
		}
	}

	/**
	 * Deletes mailbox
	 *
	 * @param string $name
	 * @throws DriverException
	 */
	public function deleteMailbox($name)
	{
		if(!imap_deletemailbox($this->resource, $this->server . $name)) {
			throw new DriverException("Cannot delete mailbox '$name': " . imap_last_error());
		}
	}

	/**
	 * Switches current mailbox
	 *
	 * @param string $name
	 * @throws DriverException
	 */
	public function switchMailbox($name)
	{
		if($name !== $this->currentMailbox) {
			$this->flush();
			if(!imap_reopen($this->resource, $this->server . $name)) {
				throw new DriverException("Cannot switch to mailbox '$name': " . imap_last_error());
			}
			$this->currentMailbox = $name;
		}
	}

	/**
	 * Finds UIDs of mails by filter
	 *
	 * @param array $filters
	 * @throws DriverException
	 * @return array of UIDs
	 */
	public function getMailIds(array $filters)
	{
		$filter = $this->buildFilters($filters);


		if(!is_array($ids = imap_sort($this->resource, SORTARRIVAL, 0, SE_UID, $filter, 'UTF-8'))) {
			throw new DriverException("Cannot get mails: " . imap_last_error());
		}
		return $ids;
	}

	/**
	 * Checks if filter is applicable for this driver
	 *
	 * @param string $key
	 * @param mixed  $value
	 * @throws DriverException
	 */
	public function checkFilter($key, $value = NULL) {
		if(!in_array($key, array_keys(self::$filterTable))) {
			throw new DriverException("Invalid filter key '$key'.");
		}
		$filtered = self::$filterTable[$key];
		if(strpos($filtered, '%s') !== FALSE) {
			if(!is_string($value)) {
				throw new DriverException("Invalid value type for filter '$key', expected string, got ".gettype($value).".");
			}
		} else if(strpos($filtered, '%d') !== FALSE) {
			if(!($value instanceof DateTime) && !is_int($value) && !strtotime($value)) {
				throw new DriverException("Invalid value type for filter '$key', expected DateTime or timestamp, or textual representation of date, got ".gettype($value).".");
			}
		} else if(strpos($filtered, '%b') !== FALSE) {
			if(!is_bool($value)) {
				throw new DriverException("Invalid value type for filter '$key', expected bool, got ".gettype($value).".");
			}
		} else if($value !== NULL) {
			throw new DriverException("Cannot assign value to filter '$key'.");
		}
	}

	/**
	 * Gets mail headers
	 *
	 * @param int $mailId
	 * @return array of name => value
	 */
	public function getHeaders($mailId)
	{
		$raw = imap_fetchheader($this->resource, $mailId, FT_UID);
		$lines = explode("\n", $raw);
		$headers = array();
		$lastHeader = NULL;
		foreach($lines as $line) {
			if(mb_substr($line, 0, 1, 'UTF-8') === " ") {
				$headers[$lastHeader] .= $line;
			} else {
				$parts = explode(':', $line);
				$name = $parts[0];
				unset($parts[0]);

				$headers[$name] = implode(':', $parts);
				$lastHeader = $name;
			}
		}

		foreach($headers as $key => $header) {
			if(trim($key) === '') {
				unset($headers[$key]);
				continue;
			}
			if(strtolower($key) === 'subject') {
				$decoded = imap_mime_header_decode($header);

				$text = '';
				foreach($decoded as $part) {
					if($part->charset !== 'UTF-8' && $part->charset !== 'default') {
						$text .= mb_convert_encoding($part->text, 'UTF-8', $part->charset);
					} else {
						$text .= $part->text;
					}
				}

				$headers[$key] = trim($text);
			} else if(in_array(strtolower($key), self::$contactHeaders)) {
				$contacts = imap_rfc822_parse_adrlist(imap_utf8(trim($header)), 'UNKNOWN_HOST');
				$list = new ContactList();
				foreach($contacts as $contact) {
					$list->addContact($contact->mailbox, $contact->host, $contact->personal, $contact->adl);
				}
				$headers[$key] = $list;
			} else {
				$headers[$key] = trim(imap_utf8($header));
			}
		}
		return $headers;
	}

	/**
	 * Creates structure for mail
	 *
	 * @param int $mailId
	 * @return IStructure
	 */
	public function getStructure($mailId)
	{
		return new ImapStructure($this, imap_fetchstructure($this->resource, $mailId, FT_UID), $mailId);
	}

	/**
	 * @param int   $mailId
	 * @param array $partIds
	 * @return string
	 */
	function getBody($mailId, array $partIds)
	{
		$body = array();
		foreach($partIds as $partId) {
			$data = ($partId['id'] == 0) ? imap_body($this->resource, $mailId, FT_UID | FT_PEEK) : imap_fetchbody($this->resource, $mailId, $partId['id'], FT_UID | FT_PEEK);
			$encoding = $partId['encoding'];
			if($encoding === ImapStructure::ENCODING_BASE64) {
				$data = base64_decode($data);
			} else if($encoding === ImapStructure::ENCODING_QUOTED_PRINTABLE) {
				$data = quoted_printable_decode($data);
			}


			$body[] = $data;
		}
		return implode('\n\n', $body);
	}

	/**
	 * Builds filter string from filters
	 *
	 * @param array $filters
	 * @return string
	 */
	protected function buildFilters(array $filters)
	{
		$return = array();
		foreach($filters as $filter) {
			$key = self::$filterTable[$filter['key']];
			$value = $filter['value'];

			if(strpos($key, '%s') !== FALSE) {
				$data = str_replace('%s', str_replace('"', '', (string)$value), $key);
			} else if(strpos($key, '%d') !== FALSE) {
				if($value instanceof DateTime) {
					$timestamp = $value->getTimestamp();
				} else if(is_string($value)) {
					$timestamp = strtotime($value) ?: Time();
				} else {
					$timestamp = (int)$value;
				}
				$data = str_replace('%d', date("d M Y", $timestamp), $key);
			} else if(strpos($key, '%b') !== FALSE) {
				$data = str_replace('%b', ((bool)$value ? '' : 'UN'), $key);
			} else {
				$data = $key;
			}
			$return[] = $data;
		}
		return implode(' ', $return);
	}

	/**
	 * Builds list from ids array
	 *
	 * @param array $ids
	 * @return string
	 */
	protected function buildIdList(array $ids)
	{
		sort($ids);
		return implode(',', $ids);
	}
}