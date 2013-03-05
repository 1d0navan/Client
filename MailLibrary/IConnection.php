<?php
/**
 * @package MailLibrary
 * @author Tomáš Blatný
 */

namespace greeny\MailLib;

/**
 * Holds connection to mail server
 */
interface IConnection
{
	public function getResource();
	public function getMailboxes();
	public function getMailbox($name);
}
