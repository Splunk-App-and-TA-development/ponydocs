<?php
if ( !defined( 'MEDIAWIKI' ) ) {
	die( "PonyDocs MediaWiki Extension" );
}

/**
 * This is the main class to manage the PonyDocs specific extensions and modifications to the behavior
 * of MediaWiki.  The goal is to include all functional changes inside this extension and the custom
 * PonyDocs skin/theme.  To activate this extension you must modify your LocalSettings.php file and
 * add the following lines:
 * 
 * 	require_once( "$IP/extensions/PonyDocs/PonyDocsExtension.php" ) ;
 * 
 * There are also a set of custom configuration directives TBD.
 * 
 * This file contains the actual body/functions, the above contains the setup for the extension.
 */

/**
 * The primary purpose of this class is as a simple container for any defined hook or extension functions.
 * They will be implemented as static methods.  Currently there is no other use for this class.
 */
class PonyDocsExtension {
	const ACCESS_GROUP_PRODUCT = 0;
	const ACCESS_GROUP_VERSION = 1;

	protected static $speedProcessingEnabled;

	/**
	 * Set SERVER['PATH_INFO'] manually
	 * Set a hook when the path is for a topic
	 * TODO: PonyDocsWiki should be instantiated here, because we have URL logic here.
	 * Unless! We want to move the hook assignment to efPonyDocsSetup, but I'm not sure that will work...
	 * we should check when hooks are called...
	 */
	public function __construct() {
		global $wgArticlePath, $wgHooks, $wgScriptPath;

		$this->setPathInfo();
		
		// <namespace>/<product>/<version>/<manual>/<topic>
		// Register a hook to map the URL to a page
		if ( preg_match(
			'/^' . str_replace( "/", "\/", $wgScriptPath ) . '\/' . PONYDOCS_DOCUMENTATION_NAMESPACE_NAME
				. '\/(.*)\/(.*)\/(.*)\/(.*)$/i',
			$_SERVER['PATH_INFO'],
			$match ) ) {
			$wgHooks['ArticleFromTitle'][] = 'PonyDocsExtension::onArticleFromTitle';
		}
	}

	/**
	 * This sets $_SERVER[PATH_INFO] based on the article path and request URI if PATH_INFO is not set.  You should
	 * STILL set $wgUsePathInfo to false in your settings if PATH_INFO is not set by your web server (PBR).  This
	 * is just a quick fix to make URL aliasing work properly in those cases.
	 *
	 * @return string
	 */
	public function setPathInfo() 	{
		global $wgArticlePath;

		if( !isset( $_SERVER['PATH_INFO'] ))
		{
			$p = str_replace( '$1', '', $wgArticlePath );
			if(!empty($_SERVER['REQUEST_URI']) && !empty($p)) {
				$_SERVER['PATH_INFO'] = substr( $_SERVER['REQUEST_URI'], strpos( $_SERVER['REQUEST_URI'], $p ));
			}
			else {
				$_SERVER['PATH_INFO'] = $wgArticlePath;
			}
		}
		return $_SERVER['PATH_INFO'];
	}
	
	/**
	 * Returns the manual data for a version in cache.  If the cache is not populated for 
	 * that version, then build it and return it.
	 * @param string $product product short name
	 * @param string $version version name
	 * @return array of manual navigation items
	 */
	static public function fetchNavDataForVersion( $product, $version ) {
		$key = "NAVDATA-" . $product . "-" . $version;
		$cache = PonyDocsCache::getInstance();
		$cacheEntry = $cache->get( $key );
		if ( $cacheEntry === NULL ) {
			if ( PONYDOCS_DEBUG ) {
				error_log(
					"DEBUG [" . __METHOD__ . "] Creating new navigation cache file for product $product version $version" );
			}
			$oldVersion = PonyDocsProductVersion::GetSelectedVersion( $product );
			PonyDocsProductVersion::SetSelectedVersion( $product, $version );
			$ver = PonyDocsProductVersion::GetVersionByName(
				$product, PonyDocsProductVersion::GetSelectedVersion( $product ) );
			if ( !is_object( $ver ) ) {
				return array();
			}
			$pr = PonyDocsProduct::GetProductByShortName( $product );
			$manuals = PonyDocsProductManual::LoadManualsForProduct( $product, TRUE );

			$cacheEntry = array();
			foreach($manuals as $manual) {
				if ( $manual->isStatic() ) {
					$staticVersions = $manual->getStaticVersionNames();
					if (in_array($version, $staticVersions)) {
						$cacheEntry[$manual->getShortName()] = array(
							'shortName' => $manual->getShortName(),
							'longName' => $manual->getLongName(),
							'firstUrl' => '/' . implode(
								'/', 
								array( PONYDOCS_DOCUMENTATION_NAMESPACE_NAME, $product, $version, $manual->getShortName() ) ) );
					}
				} else {
					$toc = new PonyDocsTOC($manual, $ver, $pr);
					list($items, $prev, $next, $start) = $toc->loadContent();
					foreach($items as $entry) {
						if(isset($entry['link']) && $entry['link'] != '') {
							// Found first article.
							$cacheEntry[$manual->getShortName()] = array(
								'shortName' => $manual->getShortName(),
								'longName' => $manual->getLongName(),
								'categories' => implode(',', $manual->getCategories()),
								'description' => $toc->getManualDescription(),
								'firstTitle' => $entry['title'],
								'firstUrl' => $entry['link']);
							break;
						}
					}
				}
			}
			$cache->put( $key, $cacheEntry, NAVDATA_CACHE_TTL, NAVDATA_CACHE_TTL / 4 );
			// Restore old version
			PonyDocsProductVersion::SetSelectedVersion( $product, $oldVersion );
			PonyDocsProductManual::LoadManualsForProduct( $product, TRUE );
		}
		else {
			if (PONYDOCS_DEBUG) {
				error_log("DEBUG [" . __METHOD__ . "] Fetched navigation cache from PonyDocsCache for product $product");
			}
		}
		return $cacheEntry;
	}
	
	/**
	 * Get the Default URL
	 **/
	static public function getDefaultUrl() {
		global $wgArticlePath;
		$defaultRedirect = str_replace( '$1', PONYDOCS_DOCUMENTATION_NAMESPACE_NAME, $wgArticlePath );
		return $defaultRedirect;
	}

	/**
	 * function which
	 * -  redirect to the landing page
	**/
	static public function redirectToLandingPage() {
		global $wgOut, $wgServer;		
		$defaultUrl = self::getDefaultUrl();		
		$wgOut->redirect( $wgServer . $defaultUrl );  		
	}
	
	static public function handle404(&$out) {
		global $wgOut;
		$wgOut->clearHTML();
		$wgOut->setPageTitle("The Requested Topic Does Not Exist");
		$wgOut->addHTML("<p>Hi!  This page does not exist, or has been removed from the Documentation.</p>");
		$wgOut->addHTML("<p>To find what you need, you can:<ul><li>Search using the box in the upper right</li></ul><p>OR</p><ul><li>Select a manual from the list above and then a topic from the Table of Contents on the left</li></ul><p>Thanks!</p>");
		$wgOut->setArticleFlag(false);
		$wgOut->setStatusCode(404);
		return true;
	}

	/**
	 * Deletes PonyDocs category cache associated with the article
	 * @param Article $article
	 */
	static public function clearArticleCategoryCache($article) {
		$topic = new PonyDocsTopic($article);
		$cache = PonyDocsCache::getInstance();
		$ponydocsVersions = $topic->getProductVersions();
		if (count($ponydocsVersions) > 0) {
			foreach ($ponydocsVersions as $ver) {
				$cache->remove("category-Category:V:" . $ver->getProductName() . ':' . $ver->getVersionShortName());
			}
		}
	}

	/**
	 * Updates or deletes Doc Links for the article being passed in.
	 * @param string $updateOrDelete possible values: "update" or "delete"
	 * @param Article $article the article to be updated or deleted
	 * @param string $content content of the article to be updated or deleted
	 */
	static public function updateOrDeleteDocLinks($updateOrDelete, $article, $content = NULL) {
		$dbh = wfGetDB(DB_MASTER);

		if ($updateOrDelete == "update") {
			// Match all links in the article
			/* Breakdown of the regex below:
			 * two left brackets
			 * followed by zero or more misc chars ($match[1]), until
			 * a pound followed by one or more misc chars ($match[2]) -- but this section is optional
			 * followed by an optional |
			 * followed by zero or more misc chars ($match[4]) ($match[3] is the misc chars plus the |)
			 * followed by two right brackets
			 * Things that would match:
			 * [[TextHere:OtherTextHere#MoreText|StillMoreText]]
			 * [[TextHere:MoreText:Text:Text|StillMoreText]]
			 * [[TextHere:MoreText:Text:Text:Text|StillMoreText]]
			 * [[TextHere:OtherTextHere|StillMoreText]]
			 * [[TextHereStillMoreText]]
			 * etc.
			 * For PonyDocs, this maps to:
			 * [[Topic]]
			 * [[Documentation:Product:Manual:Topic]]
			 * [[Documentation:Product:Manual:Topic:Version]]
			 * [[OtherNamespace:Topic]]
			 */
			// TODO we really should refactor this regex; for now, leaving intact
			$regex = "/\[\[([A-Za-z0-9,:._ -]*)(\#[A-Za-z0-9 _-]+)?([|]?([A-Za-z0-9,:.'_?!@\/\"()#$ -]*))\]\]/";
			preg_match_all($regex, $content, $matches, PREG_SET_ORDER);
		}

		// Get the title of the article
		$title = $article->getTitle()->getFullText();
		$titlePieces = explode(':', $title);
		$fromNamespace = $titlePieces[0];
		$toAndFromLinksToInsert = array();
		$fromLinksToDelete = array();
		// $titlePieces[3] is the version
		// if this is not set, we're not looking at a Topic (probably we're looking at a TOC) and we don't need doclinks
		if ($fromNamespace == PONYDOCS_DOCUMENTATION_NAMESPACE_NAME && isset( $titlePieces[3] ) ) {
			// TODO only process this topic if it's not a TOC.
			// Do PonyDocs-specific stuff (loop through all inherited versions)

			// Get the versions associated with this topic
			$topic = new PonyDocsTopic($article);
			PonyDocsProductVersion::LoadVersionsForProduct($titlePieces[1], true, true);
			$ponydocsVersions = $topic->getProductVersions();

			// Add a link to the database for each version
			foreach ($ponydocsVersions as $ver) {
				
				// Make a pretty PonyDocs URL (with slashes) out of the mediawiki title (with colons)
				// Put this $ver in the version spot. We want one URL per inherited version
				$titleNoVersion = $fromNamespace . ":" . $titlePieces[1] . ":" . $titlePieces[2] . ":" . $titlePieces[3];
				$humanReadableTitle = self::translateTopicTitleForDocLinks($titleNoVersion, $fromNamespace, $ver, $topic); // this will add the version
				// Add this title to the array of titles to be deleted from the database
				$fromLinksToDelete[] = $humanReadableTitle;

				if ($updateOrDelete == "update") {
					// Add links in article to database
					foreach ($matches as $match) {
						// Get pretty to_link
						$toUrl = self::translateTopicTitleForDocLinks($match[1], $fromNamespace, $ver, $topic);

						// Add this from_link and to_link to array to be inserted into the database
						if($toUrl) {
							$toAndFromLinksToInsert[] = array(
								'from_link' => $humanReadableTitle,
								'to_link' => $toUrl
							);
						}
					}
				}
			}
		} elseif ( $fromNamespace != PONYDOCS_DOCUMENTATION_NAMESPACE_NAME ) {
			// Do generic mediawiki stuff for non-PonyDocs namespaces

			// Add this title to the array of titles to be deleted from the database
			// We don't need to translate title here since we're not in the PonyDocs namespace
			$fromLinksToDelete[] = $title;

			if ($updateOrDelete == "update") {
				// Add links in article to database
				foreach ($matches as $match) {
					// Get pretty to_link
					$toUrl = self::translateTopicTitleForDocLinks($match[1]);

					// Add this from_link and to_link to array to be inserted into the database
					if($toUrl) {
						$toAndFromLinksToInsert[] = array(
							'from_link' => $title,
							'to_link' => $toUrl
						);
					}
				}
			}
		}

		// Perform database queries using arrays populated above

		// First, delete to clear old data out of the database
		if (!empty($fromLinksToDelete)) {
			foreach ($fromLinksToDelete as &$fromLinkToDelete) {
				$fromLinkToDelete = $dbh->strencode($fromLinkToDelete);
			}
			$deleteWhereConds = implode("' OR from_link = '", $fromLinksToDelete);
			$deleteQuery = "DELETE FROM ponydocs_doclinks WHERE from_link = '" . $deleteWhereConds . "'";
			$dbh->query($deleteQuery);
		}

		// Now insert new data, if we have any
		if (!empty($toAndFromLinksToInsert)) {
			$insertValuesAry = array();
			foreach ($toAndFromLinksToInsert as $toAndFromLink) {
				$insertValuesAry[] = "'" . $dbh->strencode($toAndFromLink['from_link']) . "', '" . $dbh->strencode($toAndFromLink['to_link']) . "'";
			}
			$insertValuesString = implode("), (", $insertValuesAry);
			$insertQuery = "INSERT INTO ponydocs_doclinks (from_link, to_link) VALUES (" . $insertValuesString . ")";
			$dbh->query($insertQuery);
		}


		return true;
	}

	static public function isSpeedProcessingEnabled() {
		return PonyDocsExtension::$speedProcessingEnabled;
	}

	static public function setSpeedProcessing($enabled) {
		PonyDocsExtension::$speedProcessingEnabled = $enabled;
	}

	/**
	 * This function will take the constant for the base author group and concatinate
	 * it with the current product.  It accepts either the type of "product" or "preview"
	 * 
	 * This same formula is used to define the groups per
	 * product, thus you can check if the current author in the current product has permission
	 * to edit, branch or inherit with:
	 * 	$groups = $wgUser->getAllGroups( );
	 * 	if( in_array( getDerivedGroup(), $groups ){
	 * 		//do something protected here
	 * 	}
	 *
	 * @param int $type access group to retrieve (either for product or version)
	 * @param string $productName short name of product
	 * @return string or boolean false on failure
	 */
	static public function getDerivedGroup($type = self::ACCESS_GROUP_PRODUCT, $productName = NULL){
		global $wgPonyDocsBaseAuthorGroup, $wgPonyDocsBasePreviewGroup;

		// if product not specified, take product from session
		if (is_null($productName)) {
			$product = PonyDocsProduct::GetSelectedProduct();
		} else {
			$product = $productName;
		}

		switch ($type) {
			case self::ACCESS_GROUP_PRODUCT:
				$group = $product . '-' . $wgPonyDocsBaseAuthorGroup;
				break;

			case self::ACCESS_GROUP_VERSION:
				$group = $product . '-' . $wgPonyDocsBasePreviewGroup;
				break;

			default:
				// if we're here we failed
				$group = false;
		}

		return $group;
	}

	/**
	 * Get configured temporary directory path
	 * @return string value of configured directory constant
	 * @throw Exception when constant doesn't exist
	 */
	static public function getTempDir() {
		if (!defined('PONYDOCS_TEMP_DIR')) {
			throw new Exception('Temporary directory is undefined');
		}
		return PONYDOCS_TEMP_DIR;
	}

	static public function translateTopicTitleForDocLinks($title, $fromNamespace = NULL, $ver = NULL, $topic = NULL) {

		if (PONYDOCS_DEBUG) {
			error_log("DEBUG [PonyDocs] [" . __METHOD__ . "] Raw title: " . $title);
		}

		// Get rid of whitespace at the end of the title
		$title = trim($title);
		
		// If we're missing the namespace from a title AND we're in the PonyDocs namespace, prepend PonyDocs namespace to title
		if (strpos($title, ':') === false && $fromNamespace == PONYDOCS_DOCUMENTATION_NAMESPACE_NAME) {
			$title = $fromNamespace . ':' . $title;
		}

		// Default
		$toUrl = $title;

		// Do special parsing for PonyDocs titles
		if (strpos($toUrl, PONYDOCS_DOCUMENTATION_NAMESPACE_NAME) !== false) {
			$pieces = explode(':', $title);
			// Evaluate based on the different "forms" our internal documentation links can take.
			if (sizeof($pieces) == 2) {
				// Handles links with no product/manual/version specified:
				// (Namespace was prepended at the beginning of this function)
				// [[Documentation:Topic]] ->
				// Documenation/Product/Version/Manual/Topic
				if ($ver === NULL || $topic === NULL) {
					error_log("WARNING [PonyDocs] [" . __METHOD__ . "] If no Product, Manual, and Version specified in PonyDocs title, must include version and topic objects when calling translateTopicTitleForDocLinks().");
					return false;
				}
				// Get the manual
				$toTitle = $topic->getTitle();
				$topicMetaData = PonyDocsArticleFactory::getArticleMetadataFromTitle($toTitle);

				// Put together the $toUrl
				$toUrl = $pieces[0] . '/' . $ver->getProductName() . '/' . $ver->getVersionShortName() . '/' . $topicMetaData['manual'] . '/' . $pieces[1];

			} else if (sizeof($pieces) == 4) {
				// Handles links with no version specified:
				// [[Documentation:Product:Manual:Topic]] ->
				// Documentation/Product/Version/Manual/Topic

				// Handle links to other products that don't specify a version
				if ($ver !== NULL) { // link is from non-Ponydocs namespace
					$fromProduct = $ver->getProductName();
				} else {
					$fromProduct = '';
				}
				$toProduct = $pieces[1];
				if ($fromProduct != $toProduct) {
					$toVersion = "latest";
				} else {
					if ($ver === NULL) {
						error_log("WARNING [PonyDocs] [" . __METHOD__ . "] If Version is not specified in title, must include version object when calling translateTopicTitleForDocLinks().");
						return false;
					}
					$toVersion = $ver->getVersionShortName();
				}

				// Put together the $toUrl
				$toUrl = $pieces[0] . '/' . $pieces[1] . '/' . $toVersion . '/' . $pieces[2] . '/' . $pieces[3];

			} else if(sizeof($pieces) == 5) {
				// Handles links with full product/version/manual specified:
				// [[Documentation:Product:Manual:Topic:Version]] =>
				// Documentation/Product/Version/Manual/Topic
				$toUrl = $pieces[0] . '/' . $pieces[1] . '/' . $pieces[4] . '/' . $pieces[2] . '/' . $pieces[3];
			} else {
				// Not a valid number of pieces in title
				error_log("WARNING [PonyDocs] [" . __METHOD__ . "] Wrong number of pieces in PonyDocs title.");
				return false;
			}
		}

		if (PONYDOCS_DEBUG) {
			error_log("DEBUG [PonyDocs] [" . __METHOD__ . "] Final title: " . $toUrl);
		}

		return $toUrl;
	}

	/**
	 * Extension functions
	 */
	
	/**
	 * Primary setup function 
	 * - Sets up session for anonymous users
	 * - Handles URL rewrites for aliasing (per spec)
	 * - Instantiates a PonyDocsWiki singleton instance which loads versions and manuals for the requested product
	 */
	function efPonyDocsSetup() {
		// Start session for anonymous traffic
		if ( session_id() == '' ) {
			wfSetupSession();
			if ( PONYDOCS_DEBUG ) {
				error_log( "DEBUG [" . __METHOD__ . "] started session" );
			}
		}

		// This has the side effect of loading versions and manuals for the product
		$wiki = PonyDocsWiki::getInstance();
		
		if ( ( $wiki->getRequestType() == 'Topic' || $wiki->getRequestType() == 'TOC' )
			&& !PonyDocsProductVersion::GetSelectedVersion( $wiki->getProduct()->getShortName(), FALSE ) ) {
			// This version isn't available to this user; go away
			$defaultRedirect = PonyDocsExtension::getDefaultUrl();
			if ( PONYDOCS_DEBUG ) {
				error_log( "DEBUG [" . __METHOD__ . ":" . __LINE__ . "] redirecting to $defaultRedirect" );
			}
			header( "Location: " . $defaultRedirect );
			exit;
		}
	}	
	

	/**
	 * Hooks
	 */

	/**
	 * Called when an article is deleted, we want to purge any doclinks entries 
	 * that refer to that article if it's in the documentation namespace.
	 *
	 * NB $article is a WikiPage and not an article
	 */
	static public function onArticleDelete( &$article, &$user, &$user, $error ) {
		$title = $article->getTitle();
		$realArticle = Article::newFromWikiPage( $article, RequestContext::getMain() );

		// Delete doc links
		PonyDocsExtension::updateOrDeleteDocLinks( "delete", $realArticle );

		//Delete the PDF on deleting the topic -WEB-7042
		if ( strpos( $title->getPrefixedText(), PONYDOCS_DOCUMENTATION_NAMESPACE_NAME ) === 0 
			&& strpos($title->getPrefixedText(), ':') !== FALSE ) {			
			PonyDocsExtension::clearArticleCategoryCache( $realArticle );
			$productArr  = explode( ':', $title->getText( ) );
			$productName = $productArr[0];	
			if ( PonyDocsProduct::IsProduct( $productName ) 
				&& count( $productArr ) == 4 
				&& preg_match( PONYDOCS_PRODUCTMANUAL_REGEX, $productArr[1] ) 				
				&& preg_match( PONYDOCS_PRODUCTVERSION_REGEX, $productArr[3] ) ) {						
				$topic = new PonyDocsTopic( $realArticle );
				$topicVersions = $topic->getProductVersions();					
				$manual = PonyDocsProductManual::GetCurrentManual( $productName, $title );			
				if ( $manual != null ) {
					foreach( $topicVersions as $key => $version ) {
						PonyDocsPdfBook::removeCachedFile( $productName, $manual->getShortName(), $version->getVersionShortName() );
					}	
				}
								
			}
		}		

		// Okay, article is in doc namespace
		
		return true;
	}

	/**
	 * Implement ArticleFromTitle hook
	 * Using the URL, find a matching MW page and override the Article set by the Title. This is where the magic happens.
	 *
	 * @param Title $title
	 * @param Article $article
	 * @return boolean 
	 */
	static public function onArticleFromTitle( &$title, &$article ) {
		global $wgHooks, $wgScriptPath, $wgTitle;

		$dbr = wfGetDB( DB_SLAVE );
		$defaultRedirect = PonyDocsExtension::getDefaultUrl();

		/**
		 * We only care about Documentation namespace for rewrites and they must contain a slash, so scan for it.
		 * $matches[1] = product
		 * $matches[2] = latest|version
		 * $matches[3] = manual
		 * $matches[4] = topic
		 */
		if ( !preg_match(
			'/^' . PONYDOCS_DOCUMENTATION_NAMESPACE_NAME . '\/([' . PONYDOCS_PRODUCT_LEGALCHARS . ']*)\/(.*)\/(.*)\/(.*)$/i', 
			$title->__toString(),
			$matches ) ) {
			return FALSE;
		}

		/**
		 * At this point $matches contains:
		 * 	0= Full title.
		 *  1= Product name
		 *  2= Version OR 'latest' as a string.
		 *  3= Manual name (short name).
		 *  4= Wiki topic name.
		 */
		$productName = $matches[1];
		$versionName = $matches[2];
		$manualName = $matches[3];
		$topicName = $matches[4];

		$product = PonyDocsProduct::GetProductByShortName( $productName );

		// If we don't have a valid product, display 404
		if ( !( $product instanceof PonyDocsProduct ) ) {
			$wgHooks['BeforePageDisplay'][] = "PonyDocsExtension::handle404";
			return FALSE;
		}

		// If this article doesn't have a valid manual, don't display the article
		if ( !PonyDocsProductManual::IsManual( $productName, $manualName ) ) {
			$wgHooks['BeforePageDisplay'][] = "PonyDocsExtension::handle404";
			return FALSE;
		}

		// If this is a static product return because that should be handled by another function
		if ( $product->isStatic() ) {
			return TRUE;
		}

		// URL version is 'latest'
		if ( strcasecmp( 'latest', $versionName ) === 0 ) {
			$latestReleasedVersion = PonyDocsProductVersion::GetLatestReleasedVersion( $productName );
			// If there is no latest version, display 404
			if ( !$latestReleasedVersion) {
				$wgHooks['BeforePageDisplay'][] = "PonyDocsExtension::handle404";
				return FALSE;
			} 
			
			$res = $dbr->select(
				array( 'categorylinks', 'page' ),
				'page_title' ,
				array(
					'cl_from = page_id',
					'page_namespace = "' . NS_PONYDOCS . '"',
					"cl_to = 'V:" . $dbr->strencode( $productName . ':' . $latestReleasedVersion->getVersionShortName() ) . "'",
					'cl_type = "page"',
					"cl_sortkey LIKE '%:" . $dbr->strencode( strtoupper( "$manualName:$topicName" ) ) . ":%'",
				),
				__METHOD__
			);

			if ( !$res->numRows() ) {
				// If there are any older versions of the topic, redirect to special latest doc.
				// Get all versions for the topic
				$res2 = $dbr->select(
					'categorylinks',
					'cl_to',
					array(
						'cl_to LIKE "V:' . $dbr->strencode( $productName ) . ':%"',
						'cl_type = "page"',
						"cl_sortkey LIKE '%:" . $dbr->strencode( strtoupper( "$manualName:$topicName" ) ) . ":%'",
					),
					__METHOD__
				);				
				
				// For each version, if any are released, then redirect to special latest doc
				if ( $res2->numRows() ) {
					while ( $row = $dbr->fetchObject( $res ) ) {
						if ( preg_match( '/^V:(.*):(.*)/i', $row->cl_to, $vmatch ) ) {
							if (PonyDocsProductVersion::IsReleasedVersion( $vmatch[1], $vmatch[2] ) ) {
								if ( PONYDOCS_DEBUG ) {
									error_log( "DEBUG [" . __METHOD__ . ":" . __LINE__
										. "] redirecting to $wgScriptPath/Special:PonyDocsLatestDoc?t=$title" );
								}
								header( "Location: " . $wgScriptPath . "/Special:SpecialLatestDoc?t=$title", TRUE, 302 );
								exit( 0 );
							}
						}
					}
				}
				
				// The topic doesn't exist in any released version, default redirect.
				if ( PONYDOCS_DEBUG ) {
					error_log( "DEBUG [" . __METHOD__ . ":" . __LINE__ . "] redirecting to $defaultRedirect" );
				}
				header( "Location: " . $defaultRedirect );
				exit( 0 );
			} else {
				$row = $dbr->fetchObject( $res );
				$title = Title::newFromText( PONYDOCS_DOCUMENTATION_NAMESPACE_NAME . ":{$row->page_title}" );

				$article = new Article( $title );
				$article->loadContent();

				PonyDocsProductVersion::SetSelectedVersion( $productName, $latestReleasedVersion->getVersionShortName() );

				if ( !$article->exists() ) {
					$article = NULL;
				} else {
					// Without this we lose SplunkComments and version switcher.
					// TODO: replace with a RequestContext
					$wgTitle = $title;
				}

				return TRUE;
			}
		// Specific version in URL
		} else {
			$versionSelectedName = PonyDocsProductVersion::GetSelectedVersion( $productName );
			
			// Default redirect if version specified in aliased URL is not a valid version
			$version = PonyDocsProductVersion::GetVersionByName( $productName, $versionName );
			if ( !$version ) {
				if ( PONYDOCS_DEBUG ) {
					error_log("DEBUG [" . __METHOD__ . ":" . __LINE__ . "] unable to retrieve version ($versionName)"
						. " for product ($productName); redirecting to $defaultRedirect");
				}
				header( "Location: " . $defaultRedirect );
				exit( 0 );
			}

			/**
			 * Look up the TOPIC in the categorylinks and find the one which is tagged with the version supplied. 
			 * This is the URL to redirect to.  
			 */
			$res = $dbr->select(
				array( 'categorylinks', 'page' ),
				'page_title' ,
				array(
					'cl_from = page_id',
					'page_namespace = "' . NS_PONYDOCS . '"',
					"cl_to = 'V:" . $dbr->strencode( $productName . ':' . $versionSelectedName ) . "'",
					'cl_type = "page"',
					"cl_sortkey LIKE '%:" . $dbr->strencode( strtoupper( "$manualName:$topicName" ) ) . ":%'",
				),
				__METHOD__
			);

			if ( !$res->numRows() ) {
				/**
				 * Handle invalid redirects?
				 */
				$wgHooks['BeforePageDisplay'][] = "PonyDocsExtension::handle404";
				return FALSE;
			}

			$row = $dbr->fetchObject( $res );
			$title = Title::newFromText( PONYDOCS_DOCUMENTATION_NAMESPACE_NAME . ":{$row->page_title}" );
			/// FIXME this shouldn't be necessary because selected version already comes from here
			PonyDocsProductVersion::SetSelectedVersion( $productName, $versionSelectedName );

			$article = new Article( $title );
			$article->loadContent();

			if ( !$article->exists() ) {
				$article = NULL;
			} else {
				// Without this we lose SplunkComments and version switcher.
				// TODO: replace with a RequestContext
				$wgTitle = $title;
			}

			return TRUE;
		}

		return FALSE;
	}

	/**
	 * 
	 * Implement ArticleFromtitle Hook
	 * - Set the version correctly when editing a topic
	 * - Redirect to the first topic in a manual if the user requested a bare manual URL
	 * - Redirect to the landing page when there are no available versions
	 */
	static public function onArticleFromTitleQuickLookup(&$title, &$article) {
		global $wgScriptPath;
		if ( preg_match( '/&action=edit/', $_SERVER['PATH_INFO'] ) ) {
			// Check referrer and see if we're coming from a doc page.
			// If so, we're editing it, so we should force the version 
			// to be from the referrer.
			if ( preg_match('/^' . str_replace("/",
				"\/", $wgScriptPath) . '\/' . PONYDOCS_DOCUMENTATION_NAMESPACE_NAME . '\/(\w+)\/((latest|[\w\.]*)\/)?(\w+)\/?/i', 
				$_SERVER['HTTP_REFERER'], $match ) ) {
				$targetProduct = $match[1];
				$targetVersion = $match[3];
				if ( $targetVersion == "latest" ) {
					PonyDocsProductVersion::SetSelectedVersion($targetProduct,
						PonyDocsProductVersion::GetLatestReleasedVersion( $targetProduct )->getVersionShortName() );
				}
				else {
					PonyDocsProductVersion::SetSelectedVersion( $targetProduct, $targetVersion );
				}
			}
		}

		// Match a URL like /Documentation/PRODUCT
		if ( preg_match( '/^' . str_replace("/", "\/", $wgScriptPath ) . '\/' . PONYDOCS_DOCUMENTATION_NAMESPACE_NAME
			. '\/([' . PONYDOCS_PRODUCT_LEGALCHARS . ']+)$/i', $_SERVER['PATH_INFO'], $match ) ) {
			$targetProduct = $match[1];
			$version = PonyDocsProductVersion::GetVersions( $targetProduct, TRUE );
			//check for product not found
			if ( empty( $version ) ) {
				PonyDocsExtension::redirectToLandingPage();
				return TRUE;
			}
		}

		// Matches a URL like /Documentation/PRODUCT/VERSION/MANUAL
		// TODO: Should match PONYDOCS_PRODUCTMANUAL_LEGALCHARS instead of \w at the end
		if ( preg_match('/^' . str_replace("/", "\/", $wgScriptPath) . '\/' . PONYDOCS_DOCUMENTATION_NAMESPACE_NAME
			. '\/([' . PONYDOCS_PRODUCT_LEGALCHARS . ']+)\/([' . PONYDOCS_PRODUCTVERSION_LEGALCHARS . ']+)\/(\w+)\/?$/i',
			$_SERVER['PATH_INFO'], $match ) ) {
			$targetProduct = $match[1];
			$targetVersion = $match[2];
			$targetManual = $match[3];

			$p = PonyDocsProduct::GetProductByShortName( $targetProduct );

			if ( !( $p instanceof PonyDocsProduct ) ) {
				$wgHooks['BeforePageDisplay'][] = "PonyDocsExtension::handle404";
				return FALSE;
			}

			// User wants to find first topic in a requested manual.
			// Load up versions
			PonyDocsProductVersion::LoadVersionsForProduct( $targetProduct );

			// Determine version
			if ( $targetVersion == '' ) {
				// No version specified, use the user's selected version
				$ver = PonyDocsProductVersion::GetVersionByName(
					$targetProduct, PonyDocsProductVersion::GetSelectedVersion( $targetProduct ) );
			} elseif ( strtolower($targetVersion) == "latest" ) {
				// User wants the latest version.
				$ver = PonyDocsProductVersion::GetLatestReleasedVersion( $targetProduct );
			} else {
				// Okay, they want to get a version by a specific name
				$ver = PonyDocsProductVersion::GetVersionByName( $targetProduct, $targetVersion );
			}
			
			if ( !$ver ) {
				if ( PONYDOCS_DEBUG ) {
					error_log("DEBUG [" . __METHOD__ . ":" . __LINE__ . "] redirecting to $wgScriptPath/" 
						. PONYDOCS_DOCUMENTATION_NAMESPACE_NAME);
				}
				header( 'Location: ' . $wgScriptPath . '/' . PONYDOCS_DOCUMENTATION_NAMESPACE_NAME );
				die();
			}

			// Okay, the version is valid, let's set the user's version.
			PonyDocsProductVersion::SetSelectedVersion( $targetProduct, $ver->getVersionShortName() );
			PonyDocsProductManual::LoadManualsForProduct( $targetProduct );
			$manual = PonyDocsProductManual::GetManualByShortName( $targetProduct, $targetManual );
			if ( !$manual ) {
				// Rewrite to Main documentation
				if ( PONYDOCS_DEBUG ) {
					error_log( "DEBUG [" . __METHOD__ . ":" . __LINE__ . "] redirecting to $wgScriptPath/"
						. PONYDOCS_DOCUMENTATION_NAMESPACE_NAME );
				}
				header( 'Location: ' . $wgScriptPath . '/' . PONYDOCS_DOCUMENTATION_NAMESPACE_NAME );
				die();
			} elseif ( !$manual->isStatic() ) {
				// Get the TOC out of here! heehee
				$toc = new PonyDocsTOC( $manual, $ver, $p );
				list( $toc, $prev, $next, $start ) = $toc->loadContent();
				//Added empty check for WEB-10038
				if ( empty( $toc ) ) {
					PonyDocsExtension::redirectToLandingPage();
					return FALSE;
				}
				
				foreach ( $toc as $entry ) {
					if ( isset( $entry['link'] ) && $entry['link'] != "" ) {
						// We found the first article in the manual with a link.  
						// Redirect to it.
						if ( PONYDOCS_DEBUG ) {
							error_log( "DEBUG [" . __METHOD__ . ":" . __LINE__ . "] redirecting to " . $entry['link'] );
						}
						header( "Location: " . $entry['link'] );
						die();
					}
				}
				//Replace die with a warning log and redirect
				error_log( "WARNING [" . __METHOD__ . ":" . __LINE__ . "] redirecting to " . PonyDocsExtension::getDefaultUrl() );
				PonyDocsExtension::redirectToLandingPage();
				return FALSE;
			}
		}
		return TRUE;
	}

	/**
	 * Implement ArticleFromTitle Hook
	 * Retrieve data for static Topics
	 * 
	 * @param Title $title Mediawiki title object passed from core
	 * @param Article $article Mediawiki article object passed from core
	 * @return boolean
	 */
	static public function onArticleFromTitleStatic(&$title, &$article) {
		global $wgScriptPath;
		// Check for static URI
		if (!preg_match( '/^' . str_replace("/", "\/", PONYDOCS_STATIC_URI) . '(.*)$/i', $title->__toString( ), $matches )) {
			return true;
		}
		// Check if request is for a "static" product
		$articleMeta = PonyDocsArticleFactory::getArticleMetaDataFromURL($_SERVER['PATH_INFO'], $wgScriptPath);
		if (isset($articleMeta['product'])) {
			$product = PonyDocsProduct::GetProductByShortName($articleMeta['product']);
			if ($product->isStatic()) {
				$article = PonyDocsArticleFactory::getStaticArticleByTitle($_SERVER['PATH_INFO'], $wgScriptPath);
				$article->loadContent();
				return false;
			}
		}
		return true;
	}

	/**
	 * Implement ArticleSave Hook which is called when a request to save an article is made BUT BEFORE anything is done.
	 * 
	 * We trap these for certain special circumstances and perform additional processing.
	 * Otherwise we simply fall through and allow normal processing to occur.
	 * It returns 
	 * - true on success and then falls through to other hooks
	 * - a string on error
	 * - and false on success but skips additional processing.
	 * 
	 * Actions include:
	 *
	 *	- If a page is saved in the Documentation namespace and is tagged for a version that another form of
	 *	  the SAME topic has already been tagged with, we needs to generate a confirmation page which offers
	 *	  to strip the version tag from the older/other topic, via AJAX. See onUnknownAction for the handling
	 *	  of the AJAX call 'ajax-removetags'.
	 *  - Ensure any Version category tags present reference a defined version, if not we produce an error.
	 *
	 * @param Article $article
	 * @param User $user
	 * @param string $text
	 * @param string $summary
	 * @param boolean $minor
	 * @param boolean $watch
	 * @param $sectionanchor
	 * @param integer $flags
	 * 
	 * @deprecated Use PageContentSave hook instead
	 */
	static public function onArticleSave( &$article, &$user, &$text, &$summary, $minor, $watch, $sectionanchor, &$flags ) {
		global $wgRequest, $wgOut, $wgArticlePath, $wgRequest, $wgScriptPath, $wgHooks, $wgPonyDocsEmployeeGroup;

		// Set product we're working on from query string
		// Default to selected product if query string is not set
		if ( $wgRequest->getVal( "ponydocsproduct" ) ) {
			$editPonyDocsProduct = $wgRequest->getVal( "ponydocsproduct" );
			PonyDocsProduct::setSelectedProduct($editPonyDocsProduct);
		} else {
			$editPonyDocsProduct = PonyDocsProduct::GetSelectedProduct();
		}
		// Likewise for version
		if ( $wgRequest->getVal( "ponydocsversion" ) ) {
			$editPonyDocsVersion = $wgRequest->getVal( "ponydocsversion" );
		} else {
			$editPonyDocsVersion = PonyDocsProductVersion::GetSelectedVersion( $editPonyDocsProduct );
			PonyDocsProductVersion::SetSelectedVersion( $editPonyDocsProduct, $editPonyDocsVersion );
		}

		// Gate for speed processing
		if ( PonyDocsExtension::isSpeedProcessingEnabled() ) {
			return TRUE;
		}

		// Gate for ponydocs namespace
		$title = $article->getTitle();
		if ( !preg_match( '/' . PONYDOCS_DOCUMENTATION_NAMESPACE_NAME . ':/', $title->__toString() ) ) {
			return TRUE;
		}

		// Add a entry into the error_log to dictate who edited, if they're an employee, and what topic they modified.
		$groups = $user->getGroups();
		$isEmployee = FALSE;
		if ( in_array( $wgPonyDocsEmployeeGroup, $groups ) ) {
			$isEmployee = TRUE;
		}
		error_log( "INFO [wikiedit] username=\"" . $user->getName() . "\" usertype=\""
			. ($isEmployee ? 'employee' : 'nonemployee') . "\" url=\"" . $article->getTitle()->getFullURL() . "\"" );

		$dbr = wfGetDB( DB_SLAVE );

		/**
		 * Check to see if we have any version tags -- if we don't we don't care about this and can skip and return true.
		 */
		if ( preg_match_all( '/\[\[Category:V:([A-Za-z0-9 _.-]*):([A-Za-z0-9 _.-]*)\]\]/i', $text, $matches, PREG_SET_ORDER ) ) {

			$categories = array();
			foreach ( $matches as $m ) {
				$categories[] = $m[2];
			}

			/**
j			 * Ensure ALL Category tags present reference defined versions.
			 */
			foreach ( $categories as $c ) {
				$v = PonyDocsProductVersion::GetVersionByName( $editPonyDocsProduct, $c );
				if ( !$v ) {
					$wgOut->addHTML('<h3>The version <span style="color:red;">' . $c . '</span> does not exist.'
						. 'Please update version list if you wish to use it.</h3>' );
					return FALSE;
				}
			}

			/**
			 * Now let's find out topic name.
			 * From that we can look in categorylinks for all tags for this topic, regardless of topic name
			 * (i.e. PONYDOCS_DOCUMENTATION_NAMESPACE_NAME . ':User:HowToFoo:%').
			 * We need to restrict this so that we do not query ourselves (our own topic name)
			 * and we need to check for 'cl_to' to be in $categories generated above.
			 * If we get 1 or more hits then we need to inject a form element (or something) and return FALSE.
			 *
			 * @FIXME:  Should also work on TOC management pages!
			 */

			$q = '';

			if ( preg_match(
				'/' . PONYDOCS_DOCUMENTATION_NAMESPACE_NAME . ':(.*):(.*):(.*):(.*)/', $title->__toString( ), $titleMatch ) ) {
				$res = $dbr->select(
					array('categorylinks', 'page'),
					array('cl_to', 'page_title') ,
					array(
						'cl_from = page_id',
						'page_namespace = "' . NS_PONYDOCS . '"',
						"cl_to IN ('V:{$titleMatch[1]}:" . implode( "','V:{$titleMatch[1]}:", $categories ) . "')",
						'cl_type = "page"',
						"cl_sortkey LIKE '" . $dbr->strencode( strtoupper( "{$titleMatch[2]}:{$titleMatch[3]}" ) ) . ":%'",
						"cl_sortkey <> '"
							. $dbr->strencode( strtoupper( "{$titleMatch[2]}:{$titleMatch[3]}:{$titleMatch[4]}" ) ) . "'",
					),
					__METHOD__
				);
			} elseif ( preg_match(
				'/' . PONYDOCS_DOCUMENTATION_NAMESPACE_NAME . ':(.*):(.*)TOC(.*)/', $title->__toString(), $titleMatch ) ) {
				$res = $dbr->select(
					array('categorylinks', 'page'),
					array('cl_to', 'page_title') ,
					array(
						'cl_from = page_id',
						'page_namespace = "' . NS_PONYDOCS . '"',
						"cl_to IN ('V:{$titleMatch[1]}:" . implode( "','V:{$titleMatch[1]}:", $categories ) . "')",
						'cl_type = "page"',
						"cl_sortkey LIKE '" . $dbr->strencode( strtoupper( "{$titleMatch[2]}TOC" ) ) . "%'",
						"cl_sortkey <> '" . $dbr->strencode( strtoupper( "{$titleMatch[2]}TOC{$titleMatch[3]}" ) ) . "'",
					),
					__METHOD__
				);
			} else {
				return TRUE;
			}
			if ( !$res->numRows() ) {
				return TRUE;
			}
			
			$duplicateVersions = array( );
			$topic = '';

			while( $row = $dbr->fetchObject( $res ) ) {
				if ( preg_match( '/^V:' . $editPonyDocsProduct . ':(.*)/i', $row->cl_to, $vmatch ) ) {
					$topic = PONYDOCS_DOCUMENTATION_NAMESPACE_NAME . ":{$row->page_title}";
					$duplicateVersions[] = $vmatch[1];
				}
			}

			/**
			 * Produce a warning message with a link to the topic which has the duplicates.
			 * This will list the topic which is already tagged to these versions and the versions tagged for.
			 * It will also contain a simple link to click, which uses AJAX to call the special action 'removetags'
			 * (see onUnknownAction method)
			 * and passes it a string of colon delimited versions.
			 * This will strip the version tags from the topic and then hide the output message (the warning)
			 * and allow the user to submit again.
			 *
			 * @FIXME:  Update this to use the stuff from PonyDocsAjax.php to be cleaner.
			 */

			$msg =	<<<HEREDOC

				function ajaxRemoveVersions( url ) {
					var xmlHttp;

					try {
						xmlHttp = new XMLHttpRequest();
					}
					catch( e ) {
						try {
							xmlHttp = new ActiveXObject( "Msxml2.XMLHTTP" );
						}
						catch( e ) {
							try {
								xmlHttp = new ActiveXObject( "Microsoft.XMLHTTP" );
							}
							catch( e )	 {
								alert( "No AJAX support." );
								return false;
							}
						}
					}

					xmlHttp.onreadystatechange = function() {
						if( xmlHttp.readyState == 4 ) {
							if( document.getElementById ) {
								document.getElementById("version-warning").style.display="none";
								document.getElementById("version-warning-done").style.display="block";
							}
						}
					}

					xmlHttp.open( "GET", url, true );
					xmlHttp.send(null);
				}
HEREDOC;

			$wgOut->addInLineScript( $msg );

			$msg = '<div id="version-warning"><h3>There\'s already a topic with the same name as this topic.' .
				'That other topic is already tagged with version(s): ' . implode( ',', $duplicateVersions ) .  '.' .
				' Click <a href="#" onClick="ajaxRemoveVersions(\'' . $wgScriptPath . '/index.php?title=' . $topic .
				'&action=ajax-removetags&product=' . $editPonyDocsProduct . '&versions=' . implode( ',', $duplicateVersions ) .
				'\');">here</a> to remove the version tag(s) from the other topic and use this one instead.' .
				' Here\'s a link to that topic so you can decide which one you want to use: ' .
				'<a href="' . str_replace( '$1', $topic, $wgArticlePath ) . '">' . $topic . '</a></div>' . 

				'<div id="version-warning-done" style="display:none;"><h4>Version tags removed from article ' .
				'<a href="' . str_replace( '$1', $topic, $wgArticlePath ) . '">' . $topic . '</a> ' .
				'- Submit to save changes to this topic.</h4></div>';

			$wgOut->addHTML( $msg );

			/**
			 * No idea why but things were interfering and causing this to not work.
			 */
			$wgHooks['ArticleSave'] = array();

			return FALSE;
		}

		return TRUE;
	}

	/**
	 * Implement ArticleSave hook
	 * 
	 * This is used to scan a topic in the Documentation namespace when saved for wiki links
	 * Any links found should 
	 * - auto create the topic in the namespace (if it does not exist), respecting PONYDOCS_AUTOCREATE_ON_ARTICLE_EDIT
	 * - set the H1 to the alternate text (if supplied)
	 * - tag it for the versions of the currently being viewed page
	 * 
	 * Links that autocreate topics:
	 * [[SomeTopic|My Topic Here]] <- Creates Documentation:<currentProduct>:<currentManual>:SomeTopic:<selectedVersion> and sets H1.
	 * [[Dev:HowToFoo|How To Foo]] <- Creates Dev:HowToFoo and sets H1.
	 * [[Documentation:User:SomeTopic|Some Topic]] <- To create link to another manual, will use selected version.
	 * [[Documentation:User:SomeTopic:1.0|Topic]] <- Specific title in another manual.
	 * [[:Main_Page|Main Page]] <- Link to a page in the global namespace.
	 *
	 * Other link formats:
	 * [[TopicNameOnly]]								Links to Documentation:<currentProduct>:<currentManual>:<topicName>:<selectedVersion>
	 * [[Documentation:Manual:Topic]]					Links to a different manual from a manual (uses selectedVersion and selectedProduct).
	 * [[Documentation:Product:Manual:Topic]]			Links to a different product and a different manual.
	 * [[Documentation:Product:Manual:Topic:Version]]	Links to a different product and a different manual.
	 * [[Dev:SomeTopicName]]							Links to another namespace and topic explicitly.
	 *
	 * When creating the link in Documentation namespace, it uses the CURRENT MANUAL being viewed.. and the selected version?
	 * 
	 * @param Article $article
	 * @param User $user
	 * @param string $text
	 * @param string $summary
	 * @param boolean $minor
	 * @param boolean $watch
	 * @param $sectionanchor
	 * @param integer $flags
	 * 
	 * @deprecated Use PageContentSave hook instead
	 */
	static public function onArticleSave_AutoLinks( 
		&$article, &$user, &$text, &$summary, $minor, $watch, $sectionanchor, &$flags ) {

		$dbr = wfGetDB( DB_SLAVE );
		$title = $article->getTitle();
		$missingTopics = array();

		// Gate for speed processing
		if ( PonyDocsExtension::isSpeedProcessingEnabled() ) {
			return TRUE;
		}

		// Gate for namespace
		if ( !preg_match( '/' . PONYDOCS_DOCUMENTATION_NAMESPACE_NAME . ':/', $title->__toString() ) ) {
			return TRUE;
		}
		
		// Gate for doclink autocreate
		if ( !preg_match( '/^' . PONYDOCS_DOCUMENTATION_NAMESPACE_NAME . ':(.*):(.*)TOC(.*)/i', $title )
			&& !PONYDOCS_AUTOCREATE_ON_ARTICLE_EDIT ) {
			return TRUE;
		}

		// Autocreate doclinks
		if ( preg_match_all( "/\[\[([" . Title::legalChars() . "]*)([|]?([^\]]*))\]\]/", $text, $matches, PREG_SET_ORDER ) ) {
			/**
			 * $match[1] = Wiki Link
			 * $match[3] = Alternate Text
			 */

			foreach ( $matches as $match ) {
				/**
				 * Doclink formats:
				 * - [[TopicNameOnly]]								Links to Documentation:<currentProduct>:<currentManual>:<topicName>:<selectedVersion>
				 * - [[Documentation:Manual:Topic]]					Links to a different manual from a manual (uses selectedVersion and selectedProduct).
				 * - [[Documentation:Product:Manual:Topic]]			Links to a different product and a different manual.
				 * - [[Documentation:Product:Manual:Topic:Version]]	Links to a different product and a different manual.
				 * - [[Dev:SomeTopicName]]							Links to another namespace and topic explicitly.
				 */
				
				 // So we first need to detect the use of a namespace.
				if ( strpos( $match[1], ':' ) !== FALSE ) {
					$pieces = explode( ':', $match[1] );

					if ( !strcasecmp( $pieces[0], PONYDOCS_DOCUMENTATION_NAMESPACE_NAME ) ) {
						/**
						 * Handle [[Documentation:Manual:Topic]] referencing selected version -AND-
						 * [[Documentation:User:HowToFoo]] as an explicit link to a page.
						 * [[Documentation:Product:Manual:Topic|Some Alternate Text]]
						 */
						if ( sizeof( $pieces ) == 3 || sizeof( $pieces ) == 4 ) {
							if ( sizeof( $pieces ) == 3 ) {
								$product = PonyDocsProduct::GetSelectedProduct();
								$manual = $pieces[1];
								$topic = $pieces[2];
							} else {
								$product = $pieces[1];
								$manual = $pieces[2];
								$topic = $pieces[3];
							}

							// if link is to current product, get currect selected version, otherwise we have to guess
							// and get the latest released version of the linked product
							if ( $product == PonyDocsProduct::GetSelectedProduct() ) {
								$version = PonyDocsProductVersion::GetSelectedVersion( $product );
							} else {
								if ( PonyDocsProduct::IsProduct( $product ) ) {
									// Need to load the product versions if this topic is for a different product
									PonyDocsProductVersion::LoadVersionsForProduct( $product );
									
									$pVersion = PonyDocsProductVersion::GetLatestReleasedVersion( $product );
									
									// If there is no available latest released version go to the next match
									if ( !$pVersion ) {
										continue;
									}
									
									$version  = $pVersion->getVersionShortName();
								}
							}

							/**
							 * Does this topic exist?  Look for a topic with this name tagged for the current version and current product.
							 * If nothing is found, we create a new article.
							 */
							$sqlMatch = $product . ':' . $manual . ':' . $topic;
							$res = $dbr->select(
								'categorylinks',
								'cl_from',
								array(
									"cl_to = 'V:" . $dbr->strencode( "$product:$version" ) . "'",
									'cl_type = "page"',
									"cl_sortkey LIKE '" . $dbr->strencode( strtoupper( $sqlMatch ) ) . ":%'",
								),
								__METHOD__
							);

							if ( !$res->numRows() ) {
								$topicTitle = PONYDOCS_DOCUMENTATION_NAMESPACE_NAME . ':' . $sqlMatch . ':' . $version;
								$tempArticle = new Article( Title::newFromText( $topicTitle ) );
								if ( !$tempArticle->exists() ) {
									/**
									* Create the new article in the system;  if we have alternate text then set our H1 to this.
									* Tag it with the currently selected version only.
									*/
									$content = '';
									if ( strlen( $match[3] ) ) {
										$content = '= ' . $match[3] . " =\n";
									} else {
										$content = '= ' . $topicTitle . " =\n";
									}

									$content .= "\n[[Category:V:$product:$version]]";

									$tempArticle->doEdit(
										$content,
										"Auto-creation of topic $topicTitle via reference from " . $title->__toString() . '.',
										EDIT_NEW );
									if ( PONYDOCS_DEBUG ) {
										error_log("DEBUG [" . __METHOD__ . ":" . __LINE__ . "]"
											. " Auto-created $topicTitle using link {$match[1]} in " . $title->__toString() );
									}
								}
							}
						/**
						 * Explicit link of the form:
						 * [[Documentation:Product:Manual:Topic:Version|Some Alternate Text]]
						 */
						} elseif( sizeof( $pieces ) == 5 ) {
							$product = $pieces[1];
							$version = PonyDocsProductVersion::GetSelectedVersion( $product );
							$version = $pieces[4];
							$topicTitle = $match[1];

							$tempArticle = new Article( Title::newFromText( $topicTitle ) );
							if ( !$tempArticle->exists() ) {
								/**
								* Create the new article in the system;  if we have alternate text then set our H1 to this.
								*/
								$content = '';
								if ( strlen( $match[3] ) ) {
									$content = '= ' . $match[3] . " =\n";
								} else {
									$content = '= ' . $topicTitle . " =\n";
								}
								
								$content .= "\n[[Category:V:" . $product . ':' . $version . "]]";

								$tempArticle->doEdit(
									$content,
									'Auto-creation of topic ' . $topicTitle . ' via reference from ' . $title->__toString() . '.',
									EDIT_NEW );
								if ( PONYDOCS_DEBUG ) {
									error_log(
										"DEBUG [" . __METHOD__ . ":" . __LINE__ . "] Auto-created $topicTitle using link " 
										. $match[1] . " in " . $title->__toString() );
								}
							} 
						}
					/**
					 * Handle non-Documentation NS references, such as 'Dev:SomeTopic'.  This is much simpler -- if it doesn't exist,
					 * create it and add the H1.  Nothing else.
					 */
					} else {
						$topicTitle = $match[1];
						$tempTitleForArticle = Title::newFromText( $topicTitle );
						if ( is_object( $tempTitleForArticle ) ) {
							$tempArticle = new Article( $tempTitleForArticle );
							if ( !$tempArticle->exists() ) {
								/**
								* Create the new article in the system;  if we have alternate text then set our H1 to this.
								*/
								$content = '';
								if ( strlen( $match[3] ) ) {
									$content = '= ' . $match[3] . " =\n";
								} else {
									$content = '= ' . $match[1] . " =\n";
								}

								$tempArticle->doEdit(
									$content,
									'Auto-creation of topic ' . $topicTitle . ' via reference from ' . $title->__toString() . '.',
									EDIT_NEW );
								if ( PONYDOCS_DEBUG ) {
									error_log( 
										"DEBUG [" . __METHOD__ . ":" . __LINE__ . "] Auto-created " . $topicTitle . " using link "
										. $match[1] . " in " . $title->__toString() );
								}
							}
						}
					}
				/**
				 * Here we handle simple topic links:
				 * [[SomeTopic|Some Display Title]]
				 * Which assumes CURRENT manual in Documentation namespace.  It finds the topic which must share a version tag
				 * with the currently displayed title.
				 */
				} else {
					$product = PonyDocsProduct::GetSelectedProduct();
					$pManual = PonyDocsProductManual::GetCurrentManual( $product );
					$version = PonyDocsProductVersion::GetSelectedVersion( $product );
					if ( !$pManual ) {
						// Cancel out.
						return TRUE;
					}
					
					/**
					 * Does this topic exist?  Look for a topic with this name tagged for the current version.
					 * If nothing is found, we create a new article.
					 */
					$sqlMatch = $product . ':' . $pManual->getShortName() . ':' . $match[1];
					$res = $dbr->select(
						'categorylinks',
						'cl_from',
						array(
							"cl_to = 'V:" . $dbr->strencode( "$product:$version" ) . "'",
							'cl_type = "page"',
							"cl_sortkey LIKE '" . $dbr->strencode( strtoupper( $sqlMatch ) ) . ":%'",
						),
						__METHOD__
					);

					if ( !$res->numRows() ) {
						$topicTitle = PONYDOCS_DOCUMENTATION_NAMESPACE_NAME . ":$sqlMatch:$version";

						$tempArticle = new Article( Title::newFromText( $topicTitle ));
						if ( !$tempArticle->exists() ) {
							/**
							* Create the new article in the system;  if we have alternate text then set our H1 to this.
							*/
							$content = '';
							if( strlen( $match[3] ) ) {
								$content = '= ' . $match[3] . " =\n";
							} else {
								$content = '= ' . $topicTitle . " =\n";
							}

							$content .= "\n[[Category:V:" . $product . ':' . $version . "]]";

							$tempArticle->doEdit(
								$content,
								'Auto-creation of topic ' . $topicTitle . ' via reference from ' . $title->__toString() . '.',
								EDIT_NEW );

							if ( PONYDOCS_DEBUG ) {
								error_log(
									"DEBUG [" . __METHOD__ . ":" . __LINE__ . "] Auto-created $topicTitle using link " . $match[1]
									. " in " . $title->__toString() );
							}
						}
					}
				}
			}
		}

		return TRUE;
	}

	/**
	 * Implements ArticleSaveComplete hook
	 * 
	 * Auto creates topics which don't exist yet when saving a TOC.
	 * 
	 * @param WikiPage $article
	 * @param User $user
	 * @param string $text
	 * @param string $summary
	 * @param boolean $minor
	 * @param boolean $watch
	 * @param $sectionanchor
	 * @param integer $flags
	 * 
	 * @deprecated Replace with PageContentSaveComplete hook
	 */
	static public function onArticleSaveComplete_CheckTOC( &$article, &$user, $text, $summary, $minor, $watch, $sectionanchor, &$flags ) {

		// Dangerous.  Only set the flag if you know that you should be skipping this processing.
		// Currently used for branch/inherit.
		if ( PonyDocsExtension::isSpeedProcessingEnabled() ) {
			return TRUE;
		}

		$title = $article->getTitle();
		$realArticle = Article::newFromWikiPage( $article, RequestContext::getMain() );

		$matches = array();

		if ( preg_match(
			'/' . PONYDOCS_DOCUMENTATION_NAMESPACE_NAME . ':([' . PONYDOCS_PRODUCT_LEGALCHARS . ']*):(['
				. PONYDOCS_PRODUCTMANUAL_LEGALCHARS . ']*)TOC([' . PONYDOCS_PRODUCTVERSION_LEGALCHARS . ']*)/i',
			$title->__toString( ),
			$match ) ) {
			$dbr = wfGetDB( DB_MASTER );

			/**
			 * Get all topics
			 */
			$topicRegex = '/' . PonyDocsTopic::getTopicRegex() . '/';
			preg_match_all( $topicRegex, $text, $matches, PREG_SET_ORDER );

			/**
			 * Create any topics which do not already exist in the saved TOC.
			 */
			$pProduct = PonyDocsProduct::GetProductByShortName( $match[1] );
			$pManual = PonyDocsProductManual::GetManualByShortName( $pProduct->getShortName(), $match[2] );
			$pManualTopic = new PonyDocsTopic( $realArticle );

			$manVersionList = $pManualTopic->getProductVersions();
			if ( !sizeof( $manVersionList ) ) {
				return TRUE;
			}

			// Clear all TOC cache entries for each version.
			if ( $pManual ) {
				foreach ( $manVersionList as $version ) {
					PonyDocsTOC::clearTOCCache( $pManual, $version, $pProduct );
					PonyDocsProductVersion::clearNAVCache( $version );
				}
			}

			$earliestVersion = PonyDocsProductVersion::findEarliest( $pProduct->getShortName(), $manVersionList );

			foreach ( $matches as $m ) {
				$wikiTopic = preg_replace( '/([^' . str_replace( ' ', '', Title::legalChars() ) . '])/', '', $m[1] );
				$wikiPath = PONYDOCS_DOCUMENTATION_NAMESPACE_NAME . ':' . $match[1] . ':' . $match[2] . ':' . $wikiTopic;

				$versionIn = array();
				foreach ( $manVersionList as $pV ) {
					$versionIn[] = $pV->getProductName() . ':' . $pV->getVersionShortName();
				}

				$res = $dbr->select(
					array('categorylinks'),
					'cl_from',
					array(
						"cl_to IN ('V:" . implode( "','V:", $versionIn ) . "')",
						'cl_type = "page"',
						"cl_sortkey LIKE '" . $dbr->strencode( strtoupper( "{$match[1]}:{$match[2]}:$wikiTopic" ) ) . ":%'",
					),
					__METHOD__
				);

				$topicName = '';
				if ( !$res->numRows() ) {
					/**
					 * No match -- so this is a "new" topic.  Set name and create.
					 */
					$topicName = PONYDOCS_DOCUMENTATION_NAMESPACE_NAME . ':' . $match[1] . ':' . $match[2].
						':' . $wikiTopic . ':' . $earliestVersion->getVersionShortName();

					$topicArticle = new Article( Title::newFromText( $topicName ) );
					if ( !$topicArticle->exists() ) {
						$content = 	"= " . $m[1] . "=\n\n" ;
						foreach ( $manVersionList as $pVersion ) {
							$content .= '[[Category:V:' . $pVersion->getProductName() . ':' . $pVersion->getVersionShortName( ) . ']]';
						}

						$topicArticle->doEdit(
							$content,
							'Auto-creation of topic ' . $topicName . ' via TOC ' . $title->__toString( ),
							EDIT_NEW );
						error_log( "INFO [" . __METHOD__ . ":" . __LINE__ . "] Auto-created $topicName from TOC "
							. $title->__toString() );
					}
				}
			}
		}
		return TRUE;
	}

	/**
	 * Implements ArticleSaveComplete hook
	 * 
	 * Clean-up for doclinks and caches when a Topic is saved.
	 * 
	 * @param WikiPage $article
	 * @param User $user
	 * @param string $text
	 * @param string $summary
	 * @param boolean $minor
	 * @param boolean $watch
	 * @param $sectionanchor
	 * @param integer $flags
	 * @param Revision $revision
	 * @param Status $status
	 * @param integer $baseRevId
	 * 
	 * @deprecated Replace with PageContentSaveComplete hook
	 */
	static public function onArticleSaveComplete(
		&$article, &$user, $text, $summary, $minoredit, $watchthis, $sectionanchor, &$flags, $revision, &$status, $baseRevId ) {
		$title = $article->getTitle();
		$realArticle = Article::newFromWikiPage( $article, RequestContext::getMain() );
		$productName = PonyDocsProduct::GetSelectedProduct();
		$product = PonyDocsProduct::GetProductByShortName( $productName );
		$manual = PonyDocsProductManual::GetCurrentManual( $productName, $title );
		$topic = new PonyDocsTopic( $realArticle );
		$previousRevisionId = $title->getPreviousRevisionID($realArticle->getRevIdFetched());
		$previousArticle = new Article( $title, $title->getPreviousRevisionID($realArticle->getRevIdFetched()) );

		// Update doc links
		PonyDocsExtension::updateOrDeleteDocLinks( "update", $realArticle, $text );

		// Make sure this is a docs article
		if ( !preg_match( '/^' . PONYDOCS_DOCUMENTATION_NAMESPACE_NAME . ':/i', $title->__toString(), $matches ) ) {
			return TRUE;
		}
		// Okay, article is in doc namespace

		// Now we need to remove any pdf books for this topic.
		// Since the person is editing the article, it's safe to say that the 
		// version and manual can be fetched from the classes and not do any 
		// manipulation on the article itself.
		$productName = PonyDocsProduct::GetSelectedProduct();
		$product = PonyDocsProduct::GetProductByShortName($productName);
		$version = PonyDocsProductVersion::GetSelectedVersion($productName);
		$manual = PonyDocsProductManual::GetCurrentManual($productName, $title);

		if($manual != null) {
			// Then we are in the documentation namespace, but we're not part of 
			// manual.
			// Clear any PDF for this manual
			$topicName = $topic->getTopicName();
			$topicVersions = $topic->getProductVersions();	
			foreach( $topicVersions as $key => $version ) {
				PonyDocsPdfBook::removeCachedFile( $productName, $manual->getShortName(), $version->getVersionShortName() );				
				PonyDocsPdfBook::removeCachedFile( 
					$productName, $manual->getShortName(), $version->getVersionShortName(), $topicName );
			}				
			
		}
		
		// Clear cache entries for each version on the Topic
		if ( $manual ) {
			$versionsToClear = $topic->getProductVersions();
			
			// Add any versions removed from the Topic
			$categories = $realArticle->getParserOutput()->getCategories();
			$previousCategories = $realArticle->getParserOutput($previousRevisionId)->getCategories();
			$removedCategories = array_diff(array_keys($previousCategories), array_keys($categories));
			foreach ( $removedCategories as $removedCategory ) {
				$removedVersion = $topic->convertCategoryToVersion( $removedCategory );
				if ( $removedVersion ) {
					array_push( $removedVersion, $versionsToClear );
				}
			}
			
			
			foreach ( $versionsToClear as $versionToClear ) {
				// Clear PDF cache, because article content may have been updated
				PonyDocsPdfBook::removeCachedFile( $productName, $manual->getShortName(), $versionToClear->getVersionShortName() );
				if ( !PonyDocsExtension::isSpeedProcessingEnabled() ) {
					// Clear TOC and NAV cache in case h1 was edited (I think)
					PonyDocsTOC::clearTOCCache( $manual, $versionToClear, $product );
					PonyDocsProductVersion::clearNAVCache( $versionToClear );
				}
			}
		}
		PonyDocsExtension::clearArticleCategoryCache( $realArticle );

		// if this is product versions or manuals page, clear navigation cache for all versions in the product
		// TODO: Don't clear anything we just cleared above (maybe this is exclusive with the above?)
		if ( preg_match( PONYDOCS_PRODUCTVERSION_TITLE_REGEX, $title->__toString() ) ||
			 preg_match( PONYDOCS_PRODUCTMANUAL_TITLE_REGEX, $title->__toString() ) ) {
			// reload to get updated version list
			PonyDocsProductVersion::LoadVersionsForProduct( $productName, TRUE );
			$prodVersionList = PonyDocsProductVersion::GetVersions( $productName );
			foreach( $prodVersionList as $version ) {
				PonyDocsProductVersion::clearNAVCache( $version );
			}
		}

		return TRUE;
	}

	static public function onBeforePageDisplay(&$out, &$sk) {
		$out->addModules( 'ext.PonyDocs' );
		return TRUE;
	}

	/**
	 * Implement DoEditSectionLink Hook
	 * 
	 * Add product and version parameters to the query string
	 * s.t. we can redirect back to the previous product/version when editing a product-inherited Topic
	 * 
	 * @param Skin $skin
	 * @param Title $title
	 * @param string $section
	 * @param string $tooltip
	 * @param string $result
	 * @param string $lang
	 * @return boolean
	 */
	static public function onDoEditSectionLink(  $skin, $title, $section, $tooltip, $result, $lang = false ) {
		$selectedProductName = PonyDocsProduct::GetSelectedProduct();
		$selectedVersionName = PonyDocsProductVersion::GetSelectedVersion($selectedProductName);
		// This is hacky, but at least it's not regex :)
		$result = str_replace("section=$section", "section=$section&amp;product=$selectedProductName&amp;version=$selectedVersionName", $result);
		
		return TRUE;
	}

	/**
	 * When a new TOC is being edited for the first time, use a JS document.ready() function to add a version category.
	 *
	 * @param EditPage $editpage
	 * @return boolean
	 */
	static public function onEdit_TOCPage( $editpage ) {
		global $wgTitle, $wgOut;
		
		if ( !preg_match( '/^' . PONYDOCS_DOCUMENTATION_NAMESPACE_NAME . ':(.*):(.*)TOC(.*)/i', $wgTitle->__toString(), $match ) ) {
			return TRUE;
		}

		if ( !$wgTitle->exists() ) {
			$productName = PonyDocsProduct::GetSelectedProduct();
			$versionName = PonyDocsProductVersion::GetSelectedVersion($productName);
			$script = <<<EOJS
$(function() {
	if ( $('#wpTextbox1').val() == '' ) {
		$('#wpTextbox1').val('\\n\\n[[Category:V:$productName:$versionName]]');
	}
});
EOJS;
			$wgOut->addInLineScript( $script );
		}

		return TRUE;
	}

	/**
	 * Implement GetFullURL hook
	 * 
	 * Returns the pretty url of a document if it's in the Documentation namespace and is a topic in a manual.
	 */
	static public function onGetFullURL($title, $url, $query) {
		global $wgScriptPath;
		// Check to see if we're in the Documentation namespace when viewing
		if ( preg_match( '/^' . str_replace("/", "\/", $wgScriptPath ) . '\/' . PONYDOCS_DOCUMENTATION_NAMESPACE_NAME .
			'\/(.*)$/i', $_SERVER['PATH_INFO'])) {
			if ( !preg_match( '/' . PONYDOCS_DOCUMENTATION_NAMESPACE_NAME . ':/', $title->__toString() ) ) {
				return TRUE;
			}
			// Okay, we ARE in the documentation namespace.  Let's try and rewrite 
			$url = preg_replace('/' . PONYDOCS_DOCUMENTATION_NAMESPACE_NAME . ':([^:]+):([^:]+):([^:]+):([^:]+)$/i',
				PONYDOCS_DOCUMENTATION_NAMESPACE_NAME . '/' . PonyDocsProduct::GetSelectedProduct() . "/" .
				PonyDocsProductVersion::GetSelectedVersion(PonyDocsProduct::GetSelectedProduct()) . "/$2/$3",
				$url);
		}  elseif ( preg_match( '/' . PONYDOCS_DOCUMENTATION_NAMESPACE_NAME . ':/', $title->__toString() ) ) {
			$editing = false; 		// This stores if we're editing an article or not
			if ( preg_match( '/&action=submit/', $_SERVER['PATH_INFO'] ) ) {
				// Then it looks like we are editing.
				$editing = true;
			}
			// Okay, we're not in the documentation namespace, but we ARE 
			// looking at a documentation namespace title.  So, let's rewrite
			if ( !$editing ) {
				$url = preg_replace('/' . PONYDOCS_DOCUMENTATION_NAMESPACE_NAME .
					':([^:]+):([^:]+):([^:]+):([^:]+)$/i', PONYDOCS_DOCUMENTATION_NAMESPACE_NAME .
					"/$1/$4/$2/$3", $url);
			} else {
				// Then we should inject the user's current version into the 
				// article.
				$currentProduct = PonyDocsProduct::GetSelectedProduct();
				$currentVersion = PonyDocsProductVersion::GetSelectedVersion($currentProduct);
				$targetVersion = $currentVersion;
				// Now, let's get the Article, and fetch the versions 
				// it applies to.
				$title = Title::newFromText($title->__toString());
				$article = new Article($title);
				$topic = new PonyDocsTopic($article);
				$topicVersions = $topic->getProductVersions();
				$found = false;
				// Now let's go through each version and make sure the user's 
				// current version is still in the topic.
				foreach ( $topicVersions as $version ) {
					if ( $version->getVersionShortName() == $currentVersion ) {
						$found = true;
						break;
					}
				}
				
				if ( !$found ) {
					// This is an edge case, it shouldn't happen often.  But 
					// this means that the editor removed the version the user 
					// is in from the topic.  So in this case, we want to 
					// return the Documentation url with the version being the 
					// latest released version in the topic.
					$targetVersion = "latest";

					foreach ( $topicVersions as $version ) {
						if ( $version->getVersionStatus() == "released" ) {
							$targetVersion = $version->getVersionShortName();
						}
					}
				}
				
				$url = preg_replace('/' . PONYDOCS_DOCUMENTATION_NAMESPACE_NAME .
					':([^:]+):([^:]+):([^:]+):([^:]+)$/i', PONYDOCS_DOCUMENTATION_NAMESPACE_NAME .
					"/$currentProduct/$targetVersion/$2/$3", $url);
			}
		}
		return TRUE;
	}

	/**
	 * Implement ParserBeforeStrip Hook
	 * 
	 * This hook is called before any form of substitution or parsing is done on the text.
	 * $text is modifiable -- we can do any sort of substitution, addition/deleting, replacement, etc. on it
	 * This is perfect to doing wiki link substitution for URL rewriting and so forth.
	 *
	 * @static
	 * @param Parser $parser
	 * @param string $text
	 * @return boolean|string
	 */
	static public function onParserBeforeStrip( &$parser, &$text )
	{
		global $action, $wgTitle, $wgArticlePath, $wgOut, $action;

		$dbr = wfGetDB( DB_SLAVE );
		if(empty($wgTitle)) {
			return true;
		}

		// We want to do link substitution in all namespaces now.
		$doWikiLinkSubstitution = true;
		$matches = array( 	'/^' . PONYDOCS_DOCUMENTATION_NAMESPACE_NAME . ':(.*):(.*):(.*):(.*)/');

		$doStripH1 = false;
		foreach( $matches as $m )
			if( preg_match( $m, $wgTitle->__toString( )))
				$doStripH1 = true;

		if( !strcmp( $action, 'submit' ) && preg_match( '/^Someone else has changed this page/i', $text ))
		{
			$text = '';
			return true;
		}

		/**
		 * Strip out ANY H1 HEADER.  This has the nice effect of only stripping it out during render and not during edit or
		 * anything.  We should only be doing this for Documentation namespace?
		 *
		 * Note, we've put false into the if statement, because we're 
		 * disabling this "feature", per WEB-2890.
		 *
		 * Keeping the code in, just in case we want to re-enable.
		 */
		if( $doStripH1 && false )
			$text = preg_replace( '/^\s*=.*=.*\n?/', '', $text );

		/**
		 * Handle our wiki links, which are always of the form [[<blah>]].  There are built-in functions however that also use
		 * this structure (like Category tags).  We need to filter these out AND filter out any external links.  The rest we
		 * need to grab and produce proper anchor's and replace in the output.  In each:
		 * 	0=Entire string to match
		 *  1=Title
		 *  2=Ignore
		 *  3=Display Text (optional)
		 * Possible forms:
		 *	[[TopicName]]								Translated to Documentation:<currentManual>:<topicName>:<selectedVersion>
		 *	[[Documentation:<manual>:<topic>]]			Translated to Documentation:<manual>:<topic>:<selectedVersion>
		 *	[[Documentation:<manual>:<topic>:<version>]]No translation done -- exact link.
		 *	[[Namespace:Topic]]							No translation done -- exact link.
		 *  [[:Topic]]									Link to topic in global namespace - preceding colon required!
		 */

		//if( $doWikiLinkSubstitution && preg_match_all( "/\[\[([A-Za-z0-9,:._ -]*)([|]?([A-Za-z0-9,:.'_!@\"()#$ -]*))\]\]/", $text, $matches, PREG_SET_ORDER ))
		if( $doWikiLinkSubstitution 
			&& preg_match_all(
				"/\[\[([A-Za-z0-9,:._ -]*)(\#[A-Za-z0-9 ._-]+)?([|]?([A-Za-z0-9,:.'_?!@\/\"()#$ -{}]*))\]\]/",
				$text,
				$matches,
				PREG_SET_ORDER ) ) {
			//echo '<pre>'; print_r( $matches ); die();
			/**
			 * For each, find the topic in categorylinks which is tagged with currently selected version then produce
			 * link and replace in output ($text).  Simple!
			 */
			$selectedProduct = PonyDocsProduct::GetSelectedProduct();
			$selectedVersion = PonyDocsProductVersion::GetSelectedVersion( $selectedProduct );
			$pManual = PonyDocsProductManual::GetCurrentManual( $selectedProduct );
			// No longer bail on $pManual not being set.  We should only need it 
			// for [[Namespace:Topic]]

			foreach ( $matches as $match ) {
				/**
				 * Namespace used.  If NOT Documentation, just output the link.
				 */
				if ( strpos( $match[1], ':' ) !== false && strpos( $match[1], PONYDOCS_DOCUMENTATION_NAMESPACE_NAME ) === 0 ) {
					$pieces = explode( ':', $match[1] );
					/**
					 * [[Documentation:Manual:Topic]] => Documentation/<currentProduct>/<currentVersion>/Manual/Topic
					 */
					if ( 3 == sizeof( $pieces ) ) {
						$res = $dbr->select(
							'categorylinks',
							'cl_from', 
							array(
								"cl_to = 'V:" . $selectedProduct . ":" . $selectedVersion . "'",
								'cl_type = "page"',
								'cl_sortkey LIKE "' . $dbr->strencode( strtoupper( "{$pieces[1]}:{$pieces[2]}" ) ) . ':%"',
							),
							__METHOD__
						);

						if ( $res->numRows() ) {
							global $title;
							// Our title is our url.  We should check to see if 
							// latest is our version.  If so, we want to FORCE 
							// the URL to include /latest/ as the version 
							// instead of the version that the user is 
							// currently in.
							$tempParts = explode("/", $title);
							$latest = false;
							if(!empty($tempParts[1]) && (!strcmp($tempParts[1], "latest"))) {
								$latest = true;
							}
							// Okay, let's determine if the VERSION that the user is in is latest, 
							// if so, we should set latest to true.
							if($selectedVersion == PonyDocsProductVersion::GetLatestReleasedVersion($selectedProduct)) {
								$latest = true;
							}
							$href = str_replace( 
								'$1',
								//TODO: There is no $pieces[3] per the if clause we're in, so???
								PONYDOCS_DOCUMENTATION_NAMESPACE_NAME . '/' . $selectedProduct . '/' 
									. ( $latest ? "latest" : $selectedVersion ) . '/' . $pieces[2] . '/'  
									. preg_replace( '/([^' . str_replace( ' ', '', Title::legalChars() ) . '])/', '',
										$pieces[3] ),
								$wgArticlePath );
							$href .= $match[2];
							if ( isset( $_SERVER['SERVER_NAME'] ) ) {
								$text =	str_replace(
									$match[0],
									"[http://{$_SERVER['SERVER_NAME']}$href " . ( strlen( $match[4] ) ? $match[4] : $match[1] )
										. ']',
									$text );
							}
						}
					/**
					 * [[Documentation:Product:Manual:Topic]] => Documentation/Product/<latest_or_selected>/Manual/Topic
					 * If linking within same product, stay on selected version; otherwise use "latest" for cross-product link
					 */
					} else if ( 4 == sizeof( $pieces ) ) {
						$linkProduct = $pieces[1]; // set product in link for legibility
						
						// If this is a link to the current project, use the selected version. Otherwise set version to latest.
						if ( !strcmp($selectedProduct, $linkProduct) ) {
							$version = $selectedVersion;
						} else {
							$version = 'latest';
						}
						
						// If the version is "latest", translate that to a real version number. Use product that was in the link.
						if ($version == 'latest') {
							PonyDocsProductVersion::LoadVersionsForProduct($linkProduct);
							$versionObj = PonyDocsProductVersion::GetLatestReleasedVersion($linkProduct);
							$dbVersion = ($versionObj === NULL) ? NULL : $versionObj->getVersionShortName();
						} else {
							$dbVersion = $version;
						}
						
						// Database call to see if this topic exists in the product/version specified in the link
						$res = $dbr->select(
							'categorylinks',
							'cl_from',
							array(
								"cl_to = 'V:" . $linkProduct . ":" . $dbVersion . "'",
								'cl_type = "page"',
								"cl_sortkey LIKE '"
									. $dbr->strencode( strtoupper( implode( ":", array_slice( $pieces, 1 ) ) ) ) . ":%'",
							 ),
							__METHOD__
						);

						if ( !$res->numRows() ) {
							// This article is not found.
							continue;
						}
						
						$href = str_replace(
							'$1',
							PONYDOCS_DOCUMENTATION_NAMESPACE_NAME . '/' . $linkProduct . '/' . $version . '/' . $pieces[2] . '/' 
								. preg_replace( '/([^' . str_replace( ' ', '', Title::legalChars( )) . '])/', '', $pieces[3] ),
							$wgArticlePath );
						$href .= $match[2];
						$text = str_replace(
							$match[0], 
							"[http://{$_SERVER['SERVER_NAME']}$href " . ( strlen( $match[4] ) ? $match[4] : $match[1] ) . ']', 
							$text );
					}

					/**
					 * [[Documentation:Product:User:Topic:Version]] => Documentation/Product/Version/User/Topic
					 */
					else if( 5 == sizeof( $pieces ))
					{
						$href = str_replace( '$1', PONYDOCS_DOCUMENTATION_NAMESPACE_NAME . '/' . $pieces[1] . '/' . $pieces[4] . '/' . $pieces[2] . '/' . preg_replace( '/([^' . str_replace( ' ', '', Title::legalChars( )) . '])/', '', $pieces[3] ), $wgArticlePath );
						$href .= $match[2];

						$text = str_replace( $match[0], '[http://' . $_SERVER['SERVER_NAME'] . $href . ' ' . ( strlen( $match[4] ) ? $match[4] : $match[1] ) . ']', $text );
					}
				}
				else
				{
					// Check if our title is in Documentation and manual is set, if not, don't modify the match.
					if ( !preg_match( '/^' . PONYDOCS_DOCUMENTATION_NAMESPACE_NAME . ':.*:.*:.*:.*/i', $wgTitle->__toString() )
						|| !isset($pManual)) {
						continue;
					}
					
					$res = $dbr->select(
						'categorylinks',
						'cl_from', 
						array(
							"cl_to = 'V:" . $selectedProduct . ":" . $selectedVersion . "'",
							'cl_type = "page"',
							"cl_sortkey LIKE '" . $dbr->strencode(
								strtoupper( $selectedProduct . ':' . $pManual->getShortName() . ':' . $match[1] ) ) . ":%'",
						),
						__METHOD__
					);

					/**
					 * We might need to make it a "non-link" at this point instead of skipping it.
					 */
					if ( !$res->numRows() ) {
						continue;
					}

					$href = str_replace(
						'$1',
						PONYDOCS_DOCUMENTATION_NAMESPACE_NAME . '/' . $selectedProduct . '/' . $selectedVersion . '/'
							. $pManual->getShortName() . '/' 
							. preg_replace('/([^' . str_replace( ' ', '', Title::legalChars() ) . '])/', '', $match[1] ),
						$wgArticlePath
					);
					$href .= $match[2];

					$text = str_replace( 
						$match[0],
						"[http://{$_SERVER['SERVER_NAME']}$href " . ( strlen( $match[4] ) ? $match[4] : $match[1] ) . ']',
						$text
					);
				}
			}
		}
		return true;
	}

	/**
	 * Implement ShowEditFormFields Hook
	 * 
	 * Render a hidden text field which will hold what product and version we were in.
	 * onArticleSave() uses this to set product and version on article save
	 * which then allows doGetFullURL to set the URL to wherever the editor came from on redirect after save is successful.
	 */
	static public function onShowEditFormFields(&$editpage, &$output) {
		global $wgRequest;
		// Create product and version hidden fields, using values from the query string to set them;
		$selectedProductName = $wgRequest->getVal('product');
		$selectedVersionName = $wgRequest->getVal('version');
		if ($selectedProductName) {
			$output->mBodytext .= "<input type=\"hidden\" name=\"ponydocsproduct\" value=\"" . $selectedProductName . "\" />";
			if ($selectedVersionName) {
				$output->mBodytext .= "<input type=\"hidden\" name=\"ponydocsversion\" value=\"" . $selectedVersionName . "\" />";
			}
		}
		return TRUE;
	}

	/**
	 * Here we handle any unknown/custom actions.  For now these are:
	 *  - 'print':  Produce printer ready output of a single topic or entire manual;  request param 'type' should
	 *		be set to either 'topic' or 'manual' and topic must be defined (as title).
	 *
	 * @static
	 * @param string $action
	 * @param Article $article
	 * @return boolean|string
	 */
	static public function onUnknownAction( $action, &$article ) {
		global $wgHooks, $wgParser, $wgRequest, $wgTitle;

		$ponydocs  = PonyDocsWiki::getInstance();
		$dbr = wfGetDB( DB_SLAVE );

		/**
		 * This is accessed when we want to REMOVE version tags from a supplied topic.
		 *	title=	Topic to remove version tags from.
		 *  versions= List of versions, colon delimited, to remove.
		 *
		 * This is intended to be an AJAX call and produces no output.
		 */
		if( !strcmp( $action, 'ajax-removetags' ))
		{
			/**
			 * First open the title and strip the [[Category]] tags from the content and save.
			 */
			$versions = explode( ',', $wgRequest->getVal( 'versions' ) );
			$product = $wgRequest->getVal('product');
			$title = Title::newFromText( $wgRequest->getVal( 'title' ) );
			$article = new Article( $title );
			$content = $article->getContent( );

			$findArray = $repArray = array( );
			foreach( $versions as $v )
			{
				$findArray[] = '/\[\[\s*Category\s*:\s*V:\s*' . $v . '\]\]/i';
				$repArray[] = '';
			}
			$content = preg_replace( $findArray, $repArray, $content );
			
			$article->doEdit( $content, 'Automatic removal of duplicate version tags.', EDIT_UPDATE );

			/**
			 * Now update the categorylinks (is this needed?).
			 */
			$q = "DELETE FROM categorylinks"
				. " WHERE cl_sortkey = '" . $dbr->strencode( strtoupper( $title->getText() ) ) . "'"
				. " AND cl_to IN ('V:$product:" . implode( "','V:$product:", $versions ) . "')"
				. " AND cl_type = 'page'";

			$res = $dbr->query( $q, __METHOD__ );

			/**
			 * Do not output anything, this is an AJAX call.
			 */
			die();
		}

		return TRUE;
	}

	/**
	 * Handles special cases for permissions, which include:
	 * 
	 * 	- Only AUTHOR group can edit/submit the manuals and versions pages.
	 * 	- Only AUTHORS and EMPLOYEES can edit/submit pages in Documentation namespace.
	 *
	 * @param Title $title The title to test permission against.
	 * @param User $user The user requestion the action.
	 * @param string $action The actual action (edit, view, etc.)
	 * @param boolean $result The result, which we store in;  true=allow, false=do not.
	 * @return boolean Return true to continue checking, false to stop checking, null to not care.
	 */
	static public function onUserCan( &$title, &$user, $action, &$result ) {

		global $wgExtraNamespaces, $wgPonyDocsEmployeeGroup, $wgPonyDocsBaseAuthorGroup;
		$authProductGroup = PonyDocsExtension::getDerivedGroup();

		$continueProcessing = TRUE;
		
		/**
		 * WEB-5280 Only docteam and admin users should be able to see these pages
		 * (Documentation:productShortName:Manuals).
		 */
		if ( preg_match(PONYDOCS_PRODUCTVERSION_TITLE_REGEX, $title->__toString( )) ) {
			$groups = $user->getGroups();
			if ( !in_array( $authProductGroup, $groups ) &&
			!in_array($wgPonyDocsBaseAuthorGroup, $groups) ) {
				$result = FALSE;
				$continueProcessing = FALSE;
			}
			
		}

		if ( !strcmp( 'zipmanual', $action ) ) {
			/**
			 * Users can only see and use "download manual as zip" link if they are a member of that product's docteam group
			 */
			$groups = $user->getGroups();
			if( in_array( $authProductGroup, $groups ) ) {
				$result = TRUE;
				$continueProcessing = FALSE;
			}
		}

		/**
		 * WEB-6031 - Block access to history/diff page for non-employee
		 * @todo: can we use UserRights to handle this instead?
 		 **/
		if ((isset($_REQUEST['action']) && $_REQUEST['action'] == 'history')
			|| (isset($_REQUEST['diff']))) {

			$groups = $user->getGroups();
			if ( !in_array($wgPonyDocsEmployeeGroup, $groups) ) {
				$result = FALSE;
				$continueProcessing = FALSE;
			}
		}
		
		if ( !strcmp( 'edit', $action ) || !strcmp( 'submit', $action ) ) {

			$groups = $user->getGroups();

			/**
			 *WEB-5278 - Documentation:Products should be editable by docteam
			*/
			if ( !strcmp(PONYDOCS_DOCUMENTATION_PRODUCTS_TITLE, $title->__toString( )) ){	
				if ( !in_array($wgPonyDocsBaseAuthorGroup, $groups) ) {
					$result = FALSE;
					$continueProcessing = FALSE;
				}
			} elseif ( preg_match( PONYDOCS_PRODUCTVERSION_TITLE_REGEX, $title->__toString( ) ) ||
				preg_match( PONYDOCS_PRODUCTMANUAL_TITLE_REGEX, $title->__toString( ) ) ) {

				if ( in_array( $authProductGroup, $groups )) {
					$result = TRUE;
					$continueProcessing = FALSE;
				}
			} elseif ( ( $title->getNamespace( ) == NS_PONYDOCS ) ||
				( !strcmp( $title->__toString( ), PONYDOCS_DOCUMENTATION_NAMESPACE_NAME ) ) ) {

				/**
				 * Allow edits for employee or authors/docteam group only.
				 */
				if ( in_array( $authProductGroup, $groups ) || in_array( $wgPonyDocsEmployeeGroup, $groups ) ) {
					$result = TRUE;
					$continueProcessing = FALSE;
				}
			}
		}

		return $continueProcessing;
	}
}