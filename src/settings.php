<?php
/* Osmium
 * Copyright (C) 2012, 2013, 2014, 2016 Romain "Artefact2" Dalmaso <artefact2@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace Osmium\Page\Settings;

require __DIR__.'/../inc/root.php';
require \Osmium\ROOT.'/inc/login-common.php';

const MASK = '********';
const NICKNAME_CHANGE_WINDOW = 1209600;

$p = new \Osmium\DOM\Page();
$ctx = new \Osmium\DOM\RenderContext();
$p->title = 'Account settings';
$ctx->relative = '.';

\Osmium\State\assume_logged_in($ctx->relative);
$a = \Osmium\State\get_state('a');

$ssocharacternames = [];
$ssocharacterids = [];
$cidq = \Osmium\Db\query_params(
	'SELECT ccpoauthcharacterid
	FROM osmium.accountcredentials
	WHERE accountid = $1 AND ccpoauthcharacterid IS NOT NULL
	ORDER BY accountcredentialsid ASC',
	[ $a['accountid'] ]
);
while($row = \Osmium\Db\fetch_row($cidq)) {
	$ssocharacterids[] = $row[0];
}
while($ssocharacterids !== []) {
	$batch = array_slice($ssocharacterids, 0, \Osmium\EveApi\CHARACTER_AFFILIATION_MAX_IDS);
	$ssocharacterids = array_slice($ssocharacterids, \Osmium\EveApi\CHARACTER_AFFILIATION_MAX_IDS);
	$xml = \Osmium\EveApi\fetch('/eve/CharacterAffiliation.xml.aspx', [
		'ids' => implode(',', $batch),
	], null, $etype, $estr);
	if($xml === false) {
		/* XXX: display this properly */
		foreach($batch as $charid) $ssocharacternames[$charid] = 'Character #'.$charid;
	} else {
		foreach($xml->result->rowset->row as $row) {
			$ssocharacternames[(string)$row['characterID']] = (string)$row['characterName'];
		}
	}
}



if(isset($_POST['verifyfromsso']) && isset($ssocharacternames[$_POST['characterid']])) {
	if(\Osmium\State\register_ccp_oauth_character_account_auth($a['accountid'], $_POST['characterid'], $etype, $estr) !== true) {
		$p->formerrors['characterid'][] = '('.$etype.') '.$estr;
	} else {
		\Osmium\State\do_post_login($a['accountid']);
		$a = \Osmium\State\get_state('a');
	}
} else if(isset($_POST['unverify'])) {
	\Osmium\State\unverify_account($a['accountid']);
	\Osmium\Db\query_params(
		'UPDATE osmium.accounts SET characterid = NULL, charactername = NULL
		WHERE accountid = $1',
		[ $a['accountid'] ]
	);
	\Osmium\State\do_post_login($a['accountid']);
	$a = \Osmium\State\get_state('a');
	unset($_POST['key_id']);
	unset($_POST['v_code']);
} else if(isset($_POST['verify'])) {
	$key_id = $_POST['key_id'];
	$v_code = $_POST['v_code'];

	if(isset($a['verificationcode']) && substr($v_code, 0, strlen(MASK)) === MASK
	   && strlen($v_code) == strlen(MASK) + 4) {
		$v_code = $a['verificationcode'];
	}

	if($v_code !== \Osmium\State\get_state('eveapi_auth_vcode')) {
		$p->formerrors['v_code'][] = 'You must use the verification code given above.';
	} else if($verified = \Osmium\State\register_eve_api_key_account_auth(
		$a['accountid'], $key_id, $v_code,
		$etype, $estr
	) === false) {
		$p->formerrors['key_id'][] = '('.$etype.') '.$estr;
	} else {
		\Osmium\State\do_post_login($a['accountid']);
		$a = \Osmium\State\get_state('a');
	}

	if(isset($a['notwhitelisted']) && $a['notwhitelisted']) {
		if(\Osmium\State\check_whitelist($a)) {
			unset($a['notwhitelisted']);
			\Osmium\State\put_state('a', $a);
		}
	}
} else {
	if(isset($a['keyid'])) {
		$_POST['key_id'] = $a['keyid'];
		$_POST['v_code'] = MASK.substr($a['verificationcode'], -4);
	}
}



$div = $p->content->appendCreate('div#account_settings');

$ul = $div->appendCreate('ul.tindex');
$ul->appendCreate('li')->appendCreate('a', [ 'href' => '#s_characters', 'Characters and skills' ]);
$ul->appendCreate('li')->appendCreate('a', [ 'href' => '#s_features', 'Account features' ]);
$ul->appendCreate('li')->appendCreate('a', [ 'href' => '#s_accountauth', 'Authentication methods' ]);
$ul->appendCreate('li')->appendCreate('a', [ 'href' => '#s_changenick', 'Change nickname' ]);



$section = $div->appendCreate('section#s_changenick');
$section->appendCreate('h1', 'Change nickname');

$lastchange = \Osmium\Db\fetch_row(\Osmium\Db\query_params(
	'SELECT lastnicknamechange FROM osmium.accounts
	WHERE accountid = $1',
	[ $a['accountid'] ]
))[0];

$now = time();
$cutoff = $now - NICKNAME_CHANGE_WINDOW;

if(($lastchange === null || $lastchange <= $cutoff) && isset($_POST['newnick'])
   && \Osmium\Login\check_nickname($p, 'newnick')) {
	/* XXX: require sudo mode */

	$lastchange = time();
	$a['nickname'] = $_POST['newnick'];
	\Osmium\State\put_state('a', $a);

	\Osmium\Db\query_params(
		'UPDATE osmium.accounts SET nickname = $2, lastnicknamechange = $3 WHERE accountid = $1',
		[ $a['accountid'], $a['nickname'], $lastchange ]
	);

	$section->appendCreate('p.notice_box', 'Nickname was successfully changed.');
}

$canchange = ($lastchange === null || $lastchange <= $cutoff);

if(!$canchange) {
	$section->appendCreate(
		'p',
		'You last changed your nickname '.$p->formatDuration($now - $lastchange, false, 1)
		.' ago. You will be able to change your nickname again in '
		.$p->formatDuration($lastchange + NICKNAME_CHANGE_WINDOW - $now, false, 1).'.'
	);
}

if($canchange) {
	$section->appendCreate('p')->appendCreate(
		'strong',
		'To prevent abuse, you can only change your nickname every '
		.$p->formatDuration(NICKNAME_CHANGE_WINDOW).'.'
	);
}

$tbody = $section
	->appendCreate('o-form', [ 'action' => '#s_changenick', 'method' => 'post' ])
	                     ->appendCreate('table')
	->appendCreate('tbody')
	;

$tbody->append($p->makeFormRawRow(
	[[ 'label', 'Current nickname' ]],
	[[ 'input', [
		'readonly' => 'readonly',
		'type' => 'text',
		'value' => $a['nickname'],
	] ]]
));

if($canchange) {
	$tbody->append($p->makeFormInputRow('text', 'newnick', 'New nickname'));
	$tbody->append($p->makeFormSubmitRow('Change nickname'));
}



$section = $div->appendCreate('section#s_accountauth');
$section->appendCreate('h1', 'Authentication methods');

if(isset($_POST['delete'])) {
	$id = key($_POST['delete']);

	\Osmium\Db\query('BEGIN');

	$count = \Osmium\Db\fetch_row(\Osmium\Db\query_params(
		'SELECT COUNT(accountcredentialsid)
		FROM osmium.accountcredentials
		WHERE accountid = $1',
		[ $a['accountid'] ]
	))[0];

	if($count < 2) {
		$section->appendCreate(
			'p.error_box',
			'You cannot delete your only way of signing in to your account. That\'s silly!'
		);

		\Osmium\Db\query('ROLLBACK');
	} else {
		/* XXX: require sudo mode */
		\Osmium\Db\query_params(
			'DELETE FROM osmium.accountcredentials
			WHERE accountcredentialsid = $1 AND accountid = $2',
			[ $id, $a['accountid'] ]
		);
		\Osmium\Db\query('COMMIT');
	}
}

if(isset($_POST['changepw'])) {
	$id = key($_POST['changepw']);

	if(\Osmium\Login\check_passphrase(
		$p, 'newpw['.$id.']', 'newpw2['.$id.']',
		$_POST['newpw'][$id], $_POST['newpw2'][$id]
	)) {
		/* XXX: require sudo mode for this */

		$newhash = \Osmium\State\hash_password($_POST['newpw'][$id]);
		\Osmium\Db\query_params(
			'UPDATE osmium.accountcredentials SET passwordhash = $1
			WHERE accountcredentialsid = $3 AND accountid = $2 AND passwordhash IS NOT NULL',
			[ $newhash, $a['accountid'], $id ]
		);

		$section->appendCreate('p.notice_box', 'Passphrase was successfully changed.');	
	}
}

if(isset($_POST['username'])
   && \Osmium\Login\check_username_and_passphrase($p, 'username', 'passphrase', 'passphrase2')) {
	\Osmium\Db\query_params(
		'INSERT INTO osmium.accountcredentials (accountid, username, passwordhash)
		VALUES ($1, $2, $3)', [
			$a['accountid'],
			$_POST['username'],
			\Osmium\State\hash_password($_POST['passphrase']),
	]);
}

if(isset($_POST['ccpssoinit'])) {
	/* XXX: require sudo mode */
	\Osmium\State\ccp_oauth_redirect([
		'action' => 'associate',
		'accountid' => $a['accountid'],
	]);
}

$table = $section->appendCreate('table.d');

$thead = $table->appendCreate('thead');
$tbody = $table->appendCreate('tbody');

$trh = $thead->appendCreate('tr');
$trh->appendCreate('th', '#');
$trh->appendCreate('th', 'Type');
$trh->appendCreate('th', 'UID');
$trh->appendCreate('th', [ 'colspan' => '2', 'Actions' ]);

$crq = \Osmium\Db\query_params(
	'SELECT accountcredentialsid, username, passwordhash, ccpoauthcharacterid, ccpoauthownerhash
	FROM osmium.accountcredentials
	WHERE accountid = $1
	ORDER BY accountcredentialsid ASC',
	[ $a['accountid'] ]
);

$ccpoauthonly = true;

while($row = \Osmium\Db\fetch_assoc($crq)) {
	$tr = $tbody->appendCreate('tr');
	
	$id = $row['accountcredentialsid'];
	$tr->appendCreate('th', '#'.$id);
	$type = $tr->appendCreate('th');
	$uid = $tr->appendCreate('td');

	$actions = $tr
		->appendCreate('td.actions')
		->appendCreate('o-form', [ 'action' => '#s_accountauth', 'method' => 'post' ]);

	$tr
		->appendCreate('td')
		->appendCreate('o-form', [ 'action' => '#s_accountauth', 'method' => 'post' ])
		->appendCreate('input.confirm.dangerous', [
			'type' => 'submit',
			'name' => 'delete['.$id.']',
			'value' => 'Delete this method',
		]);

	if($row['ccpoauthcharacterid'] === null) $ccpoauthonly = false;

	if($row['username'] !== null) {
		$type->append('Username and passphrase');
		$uid->appendCreate('code', $row['username']);

		$actions->appendCreate('o-input', [
			'type' => 'password',
			'placeholder' => 'New passphrase…',
			'name' => 'newpw['.$id.']',
		]);
		$actions->appendCreate('o-input', [
			'type' => 'password',
			'placeholder' => 'Confirm passphrase…',
			'name' => 'newpw2['.$id.']',
		]);
		$actions->appendCreate('input', [
			'type' => 'submit',
			'name' => 'changepw['.$id.']',
			'value' => 'Change passphrase'
		]);
	} else if($row['ccpoauthcharacterid'] !== null) {
		$type->append('CCP OAuth2 (Single Sign On)');
		$uid->setAttribute('class', 'sso');
		$uid->appendCreate(
			'o-eve-img',
			[ 'alt' => '', 'src' => '/Character/'.$row['ccpoauthcharacterid'].'_128.jpg' ]
		);
		$code = $uid->appendCreate('code');
		$code->append($ssocharacternames[$row['ccpoauthcharacterid']]);
		$code->appendCreate('br');
		$code->appendCreate('abbr', [ 'OwnerHash', 'title' => 'A unique identifier of the character owner. If this character ever gets transferred to another EVE account, this value will change and the character will no longer be able to authenticate this account.' ]);
		$code->append(' '.$row['ccpoauthownerhash']);
	}
}

if($ccpoauthonly) {
	$table->before($p->element('p.warning_box', [[
		'strong',
		'If the characters below move to another EVE account, you will be locked out of your Osmium account for good. It is recommended you create a username and passphrase using the form below.'
	]]));
}

$section->appendCreate('h1', 'Add a new authentication method');
$ul = $section->appendCreate('ul');

$li = $ul->appendCreate('li');
$li->appendCreate('h3', 'Username and passphrase');
$tbody = $li
	->appendCreate('o-form', [ 'action' => '#s_accountauth', 'method' => 'post' ])
	->appendCreate('table')
	->appendCreate('tbody')
	;

$tbody->append($p->makeFormInputRow('text', 'username', 'User name'));
$tbody->append($p->makeFormInputRow('password', 'passphrase', 'Passphrase'));
$tbody->append($p->makeFormInputRow('password', 'passphrase2', [ 'Passphrase', [ 'br' ], [ 'small', '(confirm)' ] ]));
$tbody->append($p->makeFormSubmitRow('Add username and passphrase'));

$li = $p->element('li');
$li->appendCreate('h3', 'CCP OAuth2 (Single Sign On)');
$li->appendCreate('p')->appendCreate(
	'o-form',
	[ 'method' => 'post', 'action' => '#s_accountauth' ]
)->appendCreate(
	'input',
	[ 'type' => 'submit', 'value' => 'Associate my EVE character', 'name' => 'ccpssoinit' ]
);
if(\Osmium\get_ini_setting('ccp_oauth_available')) $ul->append($li);



$section = $div->appendCreate('section#s_features');
$section->appendCreate('h1', 'Account features');

if($a['apiverified'] === 't' && isset($a['notwhitelisted']) && $a['notwhitelisted']) {
	$section->appendCreate(
		'p.error_box',
		'Your character is not allowed to access this Osmium instance. Please contact the administrators if you have trouble authenticating your character.'
	);
} else if(isset($a['notwhitelisted']) && $a['notwhitelisted']) {
	$section->appendCreate(
		'p.error_box',
		'You need to add an EVE character to your account before you can access this Osmium instance.'
	);
}

$ul = $section->appendCreate('ul#features');

$li = $ul->appendCreate('li');
if($a['apiverified'] === 't') {
	$li->append('Character ');
	$li->appendCreate('strong', $a['charactername']);
	$li->append(' is used as your display name and used to check permissions.');

	if($a['keyid'] > 0 && $a['expirationdate'] !== null) {
		$expiresin = $a['expirationdate'] - time();
		if($expiresin <= 0) {
			$ul->appendCreate('li.m', 'API key is expired!');
		} else {
			$ul->appendCreate('li', 'API key expires ')->append($p->formatRelativeDate($a['expirationdate']));
		}
	}
} else {
	$li->append('Nickname ');
	$li->appendCreate('strong', $a['nickname']);
	$li->append(' is used as your display name.');
}

if($a['characterid'] !== null) {
	$li = $ul->appendCreate('li.a', 'Passphrase can be reset at any time using character ');
	$li->appendCreate('strong', $a['charactername'] ?: '#'.$a['characterid']);
	$li->append('.');
} else {
	$ul->appendCreate('li.m', 'Passphrase can not be reset. It is strongly recommended you add an EVE character to your account.');
}

if($a['apiverified'] === 't' && $a['mask'] & \Osmium\State\ACCOUNT_STATUS_ACCESS_MASK) {
	$ul->appendCreate(
		'li.a',
		'Your can cast votes. You still need the relevant privileges to cast specific votes.'
	);
} else {
	$ul->appendCreate(
		'li.m',
		'You can not cast votes. It requires an API key with AccountStatus access.'
	);
}

if($a['apiverified'] === 't' && $a['mask'] & \Osmium\State\CONTACT_LIST_ACCESS_MASK) {
	$ul->appendCreate('li.a', 'Contact list is available for standings.');
} else {
	$li = $ul->appendCreate(
		'li.m',
		'Contact list not available. It requires an API key with ContactList access.'
	);
	$li->appendCreate('br');
	$li->append('Loadouts you publish with standings-restricted permissions will not behave as expected, but you can still access standings-restricted loadouts published by others.');
}

if($a['apiverified'] === 't' && $a['mask'] & \Osmium\State\CHARACTER_SHEET_ACCESS_MASK) {
	$li = $ul->appendCreate('li.a', 'Character sheet is available.');
	if($a['isfittingmanager'] === 't') {
		$li->append(' You are a fitting manager in your corporation.');
	} else {
		$li->append(' You are not a fitting manager in your corporation.');
	}
} else if($a['apiverified'] === 't') {
	$ul->appendCreate(
		'li.m',
		'Character sheet not available. By default Osmium will assume you are not a fitting manager in your corporation.'
	);
}

$section->appendCreate('h1', 'Add EVE character from API');

$uri = 'https://community.eveonline.com/support/api-key/CreatePredefined/?accessmask='.(
	\Osmium\State\CHARACTER_SHEET_ACCESS_MASK
	| \Osmium\State\CONTACT_LIST_ACCESS_MASK
	| \Osmium\State\ACCOUNT_STATUS_ACCESS_MASK
);
$par = $section->appendCreate('p', 'You can create an API key here: ');
$par->appendCreate('a', [ 'href' => $uri, $uri ]);

$section->append(\Osmium\Login\make_forced_vcode_box($p, $a['accountid'], 'v_code', '#s_features'));

$section->appendCreate('p', 'You can disable or enable any calls you want, the suggested mask contains all the calls Osmium can make use of.');
$section->appendCreate('p', 'If you are having errors, you may have to wait for the cache to expire, or create a new key to get around the caching.');

$tbody = $section
	->appendCreate('o-form', [ 'action' => '#s_features', 'method' => 'post' ])
	->appendCreate('table')
	->appendCreate('tbody')
	;

$tbody->append($p->makeFormInputRow('text', 'key_id', 'API Key ID'));
$tbody->append($p->makeFormInputRow('text', 'v_code', 'Verification Code'));
$tbody->append($p->makeFormRawRow(
	'',
	[[ 'input', [ 'type' => 'submit', 'name' => 'verify', 'value' => 'Use character' ] ]]
));

if(\Osmium\get_ini_setting('ccp_oauth_available')) {
	$section->appendCreate('h1', 'Add EVE character from CCP OAuth2 (Single Sign On)');
	$section->appendCreate('p', 'You must first associate your character in the "Authentication methods" tab. It will then appear in the list below.');

	$tbody = $section
		->appendCreate('o-form', [ 'action' => '#s_features', 'method' => 'post' ])
		->appendCreate('table')
		->appendCreate('tbody')
		;

	$tr = $tbody->appendCreate('tr');
	$tr->appendCreate('th')->appendCreate('label', [
		'for' => 'characterid',
		'Character',
	]);

	$select = $tr->appendCreate('td')->appendCreate('o-select', [
		'name' => 'characterid',
		'id' => 'characterid',
	]);

	foreach($ssocharacternames as $id => $name) {
		$select->appendCreate('option', [
			'value' => $id,
			$name,
		]);
	}
	if($ssocharacternames === []) {
		$select->setAttribute('disabled', 'disabled');
		$select->appendCreate('option', [
			'value' => '0',
			'N/A',
		]);
	}

	$tbody->append($p->makeFormRawRow(
		'', $p->element('input', [
			'type' => 'submit',
			'value' => 'Use character',
			'name' => 'verifyfromsso',
		])
	));
}

$section->appendCreate('h1', 'Remove EVE character');
$section->appendCreate('p', 'If you wish to remove the character associated to your account, use the button below. Use this if you plan to transfer the EVE character to someone else, but you want to keep the new owner from being able to reset the passphrase of your Osmium account.');
$section->appendCreate('o-form', [ 'method' => 'post', 'action' => '#s_features' ])->appendCreate(
	'input.dangerous.confirm',
	[ 'type' => 'submit', 'name' => 'unverify', 'value' => 'Remove character', ]
);



$section = $div->appendCreate('section#s_characters');
$section->appendCreate('h1', 'Characters');
$section->appendCreate('p', 'Here you can add characters with custom skills and attributes to use in loadouts.');

$section->appendCreate('h3', 'Create a new character');

if(isset($_POST['newcharname']) && $_POST['newcharname'] !== '') {
	$name = $_POST['newcharname'];
	list($exists) = \Osmium\Db\fetch_row(\Osmium\Db\query_params(
		'SELECT COUNT(accountid) FROM osmium.accountcharacters
		WHERE accountid = $1 AND name = $2',
		array($a['accountid'], $name)
	));

	if($name == 'All 0' || $name == 'All V') {
		$p->formerrors['newcharname'][] = 'This name has a special meaning and cannot be used.';
	} else if($exists) {
		$p->formerrors['newcharname'][] = 'There is already a character with the same name.';
	} else {
		\Osmium\Db\query_params(
			'INSERT INTO osmium.accountcharacters (accountid, name)
			VALUES ($1, $2)',
			array($a['accountid'], $name)
		);
		unset($_POST['newcharname']);
	}
}

$tbody = $section
	->appendCreate('o-form', [ 'action' => '#s_characters', 'method' => 'post' ])
	->appendCreate('table')
	->appendCreate('tbody')
	;

$tbody->append($p->makeFormInputRow('text', 'newcharname', 'Character name'));
$tbody->append($p->makeFormSubmitRow('Create character'));


$section->appendCreate('h3', 'Manage characters');
$csapi = 'https://community.eveonline.com/support/api-key/CreatePredefined/?accessmask=8';
$section->appendCreate('p', [
	'You can use any API key for importing skills and attributes as long as it has CharacterSheet access.',
	[ 'br' ],
	'Create an API key here: ',
	[ 'strong', [[ 'a', 'href' => $csapi, $csapi ]] ],
]);

if(isset($_POST['delete']) && is_array($_POST['delete'])) {
	reset($_POST['delete']);
	$cname = key($_POST['delete']);

	\Osmium\Db\query_params(
		'DELETE FROM osmium.accountcharacters
		WHERE accountid = $1 AND name = $2',
		array($a['accountid'], $cname)
	);
} else if(isset($_POST['fetch']) && is_array($_POST['fetch'])) {
	reset($_POST['fetch']);
	$cname = key($_POST['fetch']);

	list($keyid, $vcode) = \Osmium\Db\fetch_row(\Osmium\Db\query_params(
		'SELECT ac.keyid, eak.verificationcode
		FROM osmium.accountcharacters ac
		LEFT JOIN osmium.eveapikeys eak ON eak.owneraccountid = ac.accountid AND eak.keyid = ac.keyid
		WHERE ac.accountid = $1 AND ac.name = $2',
		array($a['accountid'], $cname)
	));

	$pkeyid = $_POST['keyid'][$cname];
	$pvcode = $_POST['vcode'][$cname];
	$piname = $_POST['iname'][$cname];

	if($pkeyid === '') $pkeyid = $keyid;
	if($pvcode === '' || (substr($pvcode, 0, strlen(MASK)) === MASK
	       && strlen($pvcode) == strlen(MASK) + 4)) {
		$pvcode = $vcode;
	}

	if((string)$pkeyid === '') {
		$p->formerrors['keyid['.$cname.']'][] = 'Must supply a key ID.';
	} else if((string)$pvcode === '') {
		$p->formerrors['vcode['.$cname.']'][] = 'Must supply a verification code.';
	} else {
		$keyinfo = \Osmium\EveApi\fetch(
			'/account/APIKeyInfo.xml.aspx', 
			[ 'keyID' => $pkeyid, 'vCode' => $pvcode ],
			null, $etype, $estr
		);

		if($keyinfo === false) {
			$section->appendCreate(
				'p.error_box',
				'An error occured while fetching API key info: ('.$etype.') '.$estr
			);
		} else if(!((int)$keyinfo->result->key['accessMask'] & \Osmium\State\CHARACTER_SHEET_ACCESS_MASK)) {
			$p->formerrors['keyid['.$cname.']'][] = 'No CharacterSheet access.';
		} else {
			$apicharid = null;

			foreach($keyinfo->result->key->rowset->row as $row) {
				if((string)$piname === '') {
					/* Use first character available */
					$piname = (string)$row['characterName'];
					$apicharid = (int)$row['characterID'];
					break;
				}

				if((string)$row['characterName'] === $piname) {
					$apicharid = (int)$row['characterID'];
					break;
				}
			}

			if($apicharid === null) {
				$p->formerrors['keyid['.$cname.']'][] = [ 'Character ', [ 'strong', $piname ], ' not found.' ];
			} else if(\Osmium\State\register_eve_api_key($a['accountid'], $pkeyid, $pvcode, $etype, $estr) === false) {
				$p->formerrors['keyid['.$cname.']'][] = '('.$etype.') '.$estr;
			} else {
				\Osmium\Db\query_params(
					'UPDATE osmium.accountcharacters
					SET keyid = $1, importname = $2
					WHERE accountid = $3 AND name = $4',
					array(
						$pkeyid,
						$piname,
						$a['accountid'],
						$cname,
					)
				);

				$sheet = \Osmium\EveApi\fetch(
					'/char/CharacterSheet.xml.aspx',
					array(
						'keyID' => $pkeyid,
						'vCode' => $pvcode,
						'characterID' => $apicharid,
					),
					null, $etype, $estr
				);

				if($sheet === false) {
					$section->appendCreate(
						'p.error_box',
						'An error occured while fetching character sheet: ('.$etype.') '.$estr);
				} else {
					/* Update skills */
					$skills = array();
					foreach($sheet->result->rowset as $rowset) {
						if(!isset($rowset['name']) || (string)$rowset['name'] !== 'skills') continue;

						foreach($rowset->row as $row) {
							$skills[(string)$row['typeID']] = (int)$row['level'];
						}

						break;
					}

					ksort($skills);
					\Osmium\Db\query_params(
						'UPDATE osmium.accountcharacters SET importedskillset = $1, lastimportdate = $2
						WHERE accountid = $3 AND name = $4',
						array(
							json_encode($skills),
							time(),
							$a['accountid'],
							$cname,
						));

					/* Update attributes */
					$attribs = [
						'perception' => null,
						'willpower' => null,
						'intelligence' => null,
						'memory' => null,
						'charisma' => null,
					];
					foreach($attribs as $attr => &$v) {
						$val = (int)$sheet->result->attributes->$attr;
						if(isset($sheet->result->attributeEnhancers->{$attr.'Bonus'}->augmentatorValue)) {
							$val += (int)$sheet->result->attributeEnhancers->{$attr.'Bonus'}->augmentatorValue;
						}

						$v = $attr.' = '.$val;
					}
					\Osmium\Db\query_params(
						'UPDATE osmium.accountcharacters SET
						'.implode(', ', $attribs).'
						WHERE accountid = $1 AND name = $2',
						array($a['accountid'], $cname)
					);
				}
			}
		}
	}
} else if(isset($_POST['edit']) && is_array($_POST['edit'])) {
	reset($_POST['edit']);
	$cname = key($_POST['edit']);

	header('Location: ./editcharacter/'.urlencode($cname));
	die();
}

$table = $section
	->appendCreate('o-form', [ 'action' => '#s_characters', 'method' => 'post' ])
	->appendCreate('table.d.scharacters')
	;

$headtr = $p->element('tr', [
	[ 'th', 'Name' ],
	[ 'th', 'Key ID' ],
	[ 'th', 'Verification code' ],
	[ 'th', 'Import character name' ],
	[ 'th', 'Last import date' ],
	[ 'th', 'Actions' ],
]);
$table->appendCreate('thead', $headtr);

$table->appendCreate('tfoot');
$tbody = $table->appendCreate('tbody');

$cq = \Osmium\Db\query_params(
	'SELECT name, ac.keyid, eak.verificationcode, importname, lastimportdate
	FROM osmium.accountcharacters ac
	LEFT JOIN osmium.eveapikeys eak ON eak.owneraccountid = ac.accountid AND eak.keyid = ac.keyid
	WHERE accountid = $1',
	array($a['accountid'])
);
$haschars = false;
while($c = \Osmium\Db\fetch_assoc($cq)) {
	$haschars = true;
	$vcode = $c['verificationcode'];
	if($vcode === null) $vcode = '';
	else $vcode = MASK.substr($vcode, -4);

	$cname = $c['name'];

	$tr = $tbody->appendCreate('tr');
	$tr->appendCreate('td')->appendCreate('strong', $cname);
	$tr->appendCreate('td')->appendCreate('o-input', [
		'type' => 'text',
		'name' => 'keyid['.$cname.']',
		'value' => $c['keyid'],
	]);
	$tr->appendCreate('td')->appendCreate('o-input', [
		'type' => 'text',
		'name' => 'vcode['.$cname.']',
		'value' => $vcode,
	]);
	$tr->appendCreate('td')->appendCreate('o-input', [
		'type' => 'text',
		'name' => 'iname['.$cname.']',
		'value' => $c['importname'],
	]);

	$tr->appendCreate('td')->append(
		$c['lastimportdate'] === null ? $p->element('em', 'never') : $p->formatRelativeDate($c['lastimportdate'])
	);

	$td = $tr->appendCreate('td');

	$td->appendCreate('input', [
		'type' => 'submit',
		'name' => 'fetch['.$cname.']',
		'value' => 'Update from API',
	]);
	$td->appendCreate('input', [
		'type' => 'submit',
		'name' => 'edit['.$cname.']',
		'value' => 'Edit skills and attributes',
	]);
	$td->appendCreate('input', [
		'type' => 'submit',
		'name' => 'delete['.$cname.']',
		'value' => 'Delete character',
	]);
}
if(!$haschars) {
	$tbody
		->appendCreate('tr')
		->appendCreate('td', [ 'colspan' => (string)$headtr->childNodes->length ])
		->appendCreate('p.placeholder', 'No characters.')
		;
}


$p->snippets[] = 'account_settings';
$p->render($ctx);
