<?php

namespace MediaWiki\Extension\LinkLogin;

use \MediaWiki\MediaWikiServices;
use SMWQueryProcessor;
use SpecialPage;

class SpecialLinkLoginUsers extends SpecialPage {
	function __construct() {
		parent::__construct( 'LinkLoginUsers', 'loginlogs' );
	}

	function execute( $par ) {
		$this->setHeaders();
		LinkLogin::populateLoginTokens();

		//Show Users and Pages belonging to the group set in $par
		if( isset( $par ) && $par != '' ) {
			if( in_array($par, LinkLogin::getLinkLoginGroups(true) ) ) {
				$this->showGroupDetails( $par );
				return true;
			}
		}

		$this->showOverview();
	}


	/**
	 * Show overview of all groups
	 * 
	 * @return void
	 */
	function showOverview() {
		$output = $this->getOutput();
		$groups = LinkLogin::getLinkLoginGroups(true);
		$output->addHTML('<table class="table table-bordered table-sm"><tr>');
		$output->addHTML('<th>' . wfMessage('linklogin-groups')->text() . '</th>');
		$output->addHTML('</tr>');
		$output->addHTML('<tr>');
		foreach( $groups as $group ) {
			$url = SpecialPage::getTitleFor( 'LinkLoginUsers' )->getLocalURL() . '/' . $group;
			$output->addHTML('<td>' . '<a href="' . $url . '">' . $group . '</a>' . '</td>');
			$output->addHTML('</tr>');
		}
		$output->addHTML('</table>');
	}


	/**
	 * Show Table of all Users and Pages belonging to a group
	 * 
	 * @return void
	 */
	function showGroupDetails($par) {
		$output = $this->getOutput();
		$uom = MediaWikiServices::getInstance()->getUserOptionsManager();

		//get loginpage if set
		$loginpage = LinkLogin::getLoginpage($par);

		//get users
		$lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
		$dbr = $lb->getConnectionRef( DB_REPLICA );
		$conds = [
			'ug_group' => $par
		];
		$users = $dbr->select(
			['user', 'user_groups'],
			['user_name', 'user_id', 'user_email_token'],
			$conds,
			__METHOD__,
			[],
			[
				'user' => [ 'INNER JOIN', [ 'user_id=ug_user'] ]
			]
		) ?: [];

		//get Displaytitles
		$categories = LinkLogin::getLinkLoginCategories([$par]);
		foreach( $categories as $category ) {
			$filter = '';
			$params = [
				'[[Category:' . $category . ']]' . $filter, // die Abfragebedingungen (Query)
				'?Display title of=', // ein zusätzliches Attribut, das ausser dem Seitentitel ausgegeben werden soll
				'format=array', // das Ausgabeformat
				'link=none', // der Seitentitel würde sonst als Link (in Wiki-Markup) ausgegeben
				'sep=<SEP>', // das Trennzeichen zwischen den Seiten
				'propsep=<PROP>', // das Trennzeichen zwischen den Attributen
			];
			list( $query, $processed_params ) = SMWQueryProcessor::getQueryAndParamsFromFunctionParams( $params, SMW_OUTPUT_WIKI, SMWQueryProcessor::SPECIAL_PAGE, false );
			$result = SMWQueryProcessor::getResultFromQuery( $query, $processed_params, SMW_OUTPUT_WIKI, SMWQueryProcessor::SPECIAL_PAGE );
			$titles = explode( '<SEP>', $result );
			foreach( $titles as $title ) {
				if( !empty($title ) ) {
					list( $title, $displaytitle ) = explode("<PROP>", $title );
				} else {
					$displaytitle = '';
				}
				if( $displaytitle == '' ) {
					$displaytitle = $title;
				}
				$displaytitles[$displaytitle] = $title;
			}
		}

		$linked_pages = [];
		$used_pages = [];

		// get pages for every user in the group
		foreach( $users as $user ) {
			$pages = LinkLogin::getPagesForUser( $user->user_id, $categories );
			if( !empty($pages) ) {
				foreach($pages as $page){
					$page->displaytitle = array_search( str_replace( '_' ,' ', $page->page_title ), $displaytitles );
					$linked_pages[$user->user_name][$page->page_id] = $page->displaytitle;
					$used_pages[] = $page->displaytitle;
				}
			}   
		}

		//get all pages belonging to the group's categories
		$unlinked_pages = [];
		$lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
		$dbr = $lb->getConnectionRef( DB_REPLICA );
		$conds = [
			'cl_to' => $categories
		];
		$pages = $dbr->select(
			['categorylinks','page'],
			['page_title','page_id'],
			$conds,
			__METHOD__,
			[],
			[
				'categorylinks' => [ 'INNER JOIN', [ 'cl_from=page_id'] ]
			]
		) ?: [];
		if( !empty($pages) ) {
			foreach($pages as $page){
				$page->displaytitle = array_search( str_replace( '_' ,' ', $page->page_title ), $displaytitles );
				$unlinked_pages[$page->page_id] = $page->displaytitle;
			}
		}   

		foreach( $unlinked_pages as $key => $unlinked_page ) {
			if( in_array($unlinked_page,$used_pages) ) {
				unset($unlinked_pages[$key]);
			}
		}
		$output->addHTML('<container id="linklogin-body">');
		$output->addHTML('<table class="table table-bordered table-sm"><tr>');
		$output->addHTML('<th>' . wfMessage("linklogin-username")->text() . '</th>');
		$output->addHTML('<th>' . wfMessage("linklogin-pages")->text() . '</th>');
		$output->addHTML('<th class="semorg-showedit"></th>');
		$output->addHTML('</tr>');

		$preferences = array_keys( $GLOBALS['wgLinkLoginPreferences'] );
		if( !in_array( 'email', $preferences ) ) {
			array_unshift($preferences, 'email');
		}

		foreach( $users as $user ) {
			$user_name = str_replace(' ', '_', $user->user_name);
			$output->addHTML('<tr id=' . '"' . $user_name . '"' . '>');
			$output->addHTML('<td>' . '<span>' . $user->user_name . '</span>' . ' ' . '<a href="#"><i class="fa fa-pen edit"></i></a>' . '</td>');
			$output->addHTML('<td id="' . $user_name . 'Pages">');
			if( array_key_exists($user->user_name, $linked_pages)){
				$output->addHTML('<ul id="' . $user_name . 'List">');
				foreach( $linked_pages[$user->user_name] as $id_key => $linked_page){
					$output->addHTML('<li id="listitem-' . $id_key . '">');
					$output->addHTML('<span>' . $linked_page . '</span>');
					$output->addHTML('<a href="#" class="unlink pages" style="float:right">' . '&times;' . '</a>');
					$output->addHTML('</li>');
				}
				$output->addHTML('</ul>');
			}
			$output->addHTML('<div class="dropdown">');
			$output->addHTML('<button class="btn btn-secondary dropdown-toggle" type="button" id="dropdownMenuButton" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">');
			$output->addHTML(wfMessage('linklogin-assign-page')->text());
			$output->addHTML('</button>');
			$output->addHTML('<div class="dropdown-menu pageslist" aria-labelledby="dropdownMenuButton">');
			foreach($unlinked_pages as $key => $unlinked_page){

				// show only pages not already associated with the user
				if(!in_array($unlinked_page,$linked_pages)){
					$output->addHTML('<a href="#" class="dropdown-item pages" id="dropdownitem-'. $key .'">' . $unlinked_page . '</a>');
				}
			}
			$output->addHTML('</div></div>');
			$output->addHTML('</td>');

			//Look if User has an e-mail assoiciated to them
			$user_mail = \User::newFromId($user->user_id);
			foreach( $preferences as $preference ) {
				$user_mail->{$preference} = $uom->getOption( $user_mail, $preference );
			}
			if( isset($user_mail->email) ){
				$email = $user_mail->email;
			} else {
				$email = "";
			}

			//Add quick custom mail icons 
			$output->addHTML('<td class="semorg-showedit">');
			if( !is_null($loginpage) &&  !is_null($user->user_email_token)) {
				$link = $this->createCustomMailLink($loginpage,$user);
				if( !empty($email) ){
					$encoded_link = urlencode($link);
					$output->addWikiTextAsInterface('[mailto:' . $email .'?body=' . $encoded_link . ' <i class="fa fa-envelope fa-sm" data-toggle="tooltip" title="' . wfMessage('linklogin-custom-mail')->text() . '"></i>]');
				}
				$output->addHTML('<a id="' . $link . '" class="copy clipboard" href="#" title="' . wfMessage('linklogin-clipboard')->text() . '" data-toggle="tooltip"><i class="fa fa-clipboard"></i></a>');
			}
			$output->addHTML('</td>');
			$output->addHTML('</tr>');
		}
		$output->addHTML('</table>');
		$output->addHTML('</container>');
	}

	/**
	 * Create link to send custom mail
	 * 
	 * @param $mailing
	 * @param $recipient
	 * 
	 * @return String HTML for link
	 */
	function createCustomMailLink( $loginpage, $user ) {
		$title = \Title::newFromText($loginpage);
		$link = $title->getFullUrl([ 'login' => $user->user_email_token ]);
		return $link;
	}
} 