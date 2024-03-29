<?php

/**
 * ownCloud
 *
 * @author Frank Karlitschek
 * @copyright 2012 Frank Karlitschek frank@owncloud.org
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */
/*
 *
 * The following SQL statement is just a help for developers and will not be
 * executed!
 *
 * CREATE TABLE `users` (
 *   `uid` varchar(64) COLLATE utf8_unicode_ci NOT NULL,
 *   `password` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
 *   PRIMARY KEY (`uid`)
 * ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
 *
 */

require_once 'phpass/PasswordHash.php';

/**
 * Class for user management in a SQL Database (e.g. MySQL, SQLite)
 */
class OC_User_Database extends OC_User_Backend {
	/**
	 * @var PasswordHash
	 */
	static private $hasher=null;

	private function getHasher() {
		if(!self::$hasher) {
			//we don't want to use DES based crypt(), since it doesn't return a has with a recognisable prefix
			$forcePortable=(CRYPT_BLOWFISH!=1);
			self::$hasher=new PasswordHash(8,$forcePortable);
		}
		return self::$hasher;

	}

	/**
	 * @brief Create a new user
	 * @param $uid The username of the user to create
	 * @param $secret The password of the new user
	 * @returns true/false
	 *
	 * Creates a new user. Basic checking of username is done in OC_User
	 * itself, not in its subclasses.
	 */
	public function createUser( $uid, $secret ) {
		if( $this->userExists($uid) ) {
			return false;
		}else{
			$hasher=$this->getHasher();
			$hash = $hasher->HashPassword($secret.OC_Config::getValue('passwordsalt', ''));
			$query = OC_DB::prepare( 'INSERT INTO `*PREFIX*users` ( `uid`, `password` ) VALUES( ?, ? )' );
			$result = $query->execute( array( $uid, $hash));

			return $result ? true : false;
		}
	}

	/**
	 * @brief delete a user
	 * @param $uid The username of the user to delete
	 * @returns true/false
	 *
	 * Deletes a user
	 */
	public function deleteUser( $uid ) {
		// Delete user-group-relation
		$query = OC_DB::prepare( 'DELETE FROM `*PREFIX*users` WHERE uid = ?' );
		$query->execute( array( $uid ));
		return true;
	}

	/**
	 * @brief Set password
	 * @param $uid The username
	 * @param $secret The new password
	 * @returns true/false
	 *
	 * Change the password of a user
	 */
	public function setPassword( $uid, $secret ) {
		if( $this->userExists($uid) ) {
			$hasher=$this->getHasher();
			$hash = $hasher->HashPassword($secret.OC_Config::getValue('passwordsalt', ''));
			$query = OC_DB::prepare( 'UPDATE `*PREFIX*users` SET `password` = ? WHERE `uid` = ?' );
			$query->execute( array( $hash, $uid ));

			return true;
		}else{
			return false;
		}
	}

	/**
	 * @brief Check if the password is correct
	 * @param $uid The username
	 * @param $secret The password
	 * @returns string
	 *
	 * Check if the password is correct without logging in the user
	 * returns the user id or false
	 */
	public function checkPassword( $uid, $secret ) {
		$query = OC_DB::prepare( 'SELECT `uid`, `password`, `sslcert` FROM `*PREFIX*users` WHERE LOWER(`uid`) = LOWER(?)' );
		$result = $query->execute( array( $uid ) );

		$row=$result->fetchRow();
		if($row) {
			$storedHash = $row['password'];
			$storedCertificate = $row['sslcert'];
			$secretIsCertificate = strpos($secret, "--BEGIN CERTIFICATE--") > -1;
			if ( $secretIsCertificate ) {
				// try certificate based login
				if ( strtolower(trim($secret)) == strtolower(trim($storedCertificate)) ) {
					return $row['uid'];
				} else {
					return false;
				}
			} elseif ($storedHash[0]=='$') {
				//the new phpass based hashing
				$hasher=$this->getHasher();
				if($hasher->CheckPassword($secret.OC_Config::getValue('passwordsalt', ''), $storedHash)) {
					return $row['uid'];
				}else{
					return false;
				}
			} else {
				//old sha1 based hashing
				if(sha1($secret)==$storedHash) {
					//upgrade to new hashing
					$this->setPassword($row['uid'], $secret);
					return $row['uid'];
				}else{
					return false;
				}
			}
		}else{
			return false;
		}
	return false;
	}

	/**
	 * @brief Get a list of all users
	 * @returns array with all uids
	 *
	 * Get a list of all users.
	 */
	public function getUsers($search = '', $limit = null, $offset = null) {
		$query = OC_DB::prepare('SELECT `uid` FROM `*PREFIX*users` WHERE LOWER(`uid`) LIKE LOWER(?)',$limit,$offset);
		$result = $query->execute(array($search.'%'));
		$users = array();
		while ($row = $result->fetchRow()) {
			$users[] = $row['uid'];
		}
		return $users;
	}

	/**
	 * @brief check if a user exists
	 * @param string $uid the username
	 * @return boolean
	 */
	public function userExists($uid) {
		$query = OC_DB::prepare( 'SELECT * FROM `*PREFIX*users` WHERE LOWER(`uid`) = LOWER(?)' );
		$result = $query->execute( array( $uid ));

		return $result->numRows() > 0;
	}

	/**
	* @brief get the user's home directory
	* @param string $uid the username
	* @return boolean
	*/
	public function getHome($uid) {
		if($this->userExists($uid)) {
			return OC_Config::getValue( "datadirectory", OC::$SERVERROOT."/data" ) . '/' . $uid;
		}else{
			return false;
		}
	}
}
