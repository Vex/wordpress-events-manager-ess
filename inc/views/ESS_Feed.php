<?php
/**
  * View ESS_Feed
  * ESS feed generator.
  *
  * @author  	Brice Pissard
  * @copyright 	No Copyright.
  * @license   	GNU/GPLv2, see http://www.gnu.org/licenses/gpl-2.0.html
  * @link    	http://essfeed.org
  * @link		https://github.com/essfeed
  */
final class ESS_Feed
{
	// use temporary HTML comments to store custom attributes
	const CUSTOM_ATTRIBUTE_SEPARATOR = "||EM_CUSTOM_ATTRIBUTES||";

	function __construct(){}

	public static function output( $event_id=NULL, $event_cat=NULL, $page=1, $is_download=FALSE, $is_push=FALSE )
	{
		//ob_clean();
		//ini_set('zlib.output_handler','');

		$EM_Events = ( ( !empty( $event_id ) )?
			array( em_get_event( $event_id ) )
			:
			EM_Events::get(
				apply_filters(
					'em_calendar_template_args', array(
						'limit'		=> get_option('ess_feed_limit'),
						'page'		=> $page,
						'category'  => $event_cat,
						'owner'		=> FALSE,
						'orderby'	=> 'event_start_date'
					)
				)
			)
		);
		$limit = 0; //no limit for now, but this shall be eventually set in the wP settings page, option name dbem_ess_limit

		//var_dump( $EM_Events );die;
		//echo "count: ".count($EM_Events);
		//echo "limit: " . get_option('ess_feed_limit', $limit );
		//echo "event_id: ". $event_id;
		//die;

	  	$essFeed = new FeedWriter( ( strlen( get_option( 'ess_feed_language' ) ) == 2 )? get_option( 'ess_feed_language', ESS_Database::DEFAULT_LANGUAGE ) : ESS_Database::DEFAULT_LANGUAGE );
		$essFeed->DEBUG = FALSE; // ###  DEBUG  MODE

	  	$essFeed->setTitle( 	self::_g( 'ess_feed_title', __( 'ESS Feed generated by Wordpress', 'em-ess' ) ) );
	  	$essFeed->setLink( 		str_replace( '&push=1', '', str_replace( '&download=1', '', FeedWriter::getCurrentURL() ) ) );
	  	$essFeed->setPublished( ESS_Timezone::get_date_GMT( get_option( 'em_last_modified', current_time( 'timestamp', TRUE ) ) ) );
	  	$essFeed->setUpdated( 	ESS_Timezone::get_date_GMT( 								current_time( 'timestamp', TRUE ) ) );
		$essFeed->setRights( 	self::_g( 'ess_feed_rights', 'Rights' ) );

	  	$count = 0;
		while ( count( $EM_Events ) > 0 )
		{
			foreach ( $EM_Events as $ie => $EM_Event )
			{
				if ( intval( $EM_Event->event_id ) <= 0 ) // event empty
					break;

				if ( get_option( 'ess_feed_limit', $limit) != 0 && $count > get_option( 'ess_feed_limit', $limit) ) //we've reached the max event per feed
					break;

				//dd( $EM_Event );

				//$event_url = self::unhtmlentities( esc_html( urldecode( $EM_Event->guid ) ) );
				$event_url = esc_html( urldecode( $EM_Event->guid ) );

		  		// --- Create a new event to the feed: <feed>
		    	$newEvent = $essFeed->newEventFeed();
				$newEvent->setTitle( 		$EM_Event->output( get_option( 'dbem_rss_title_format', sprintf( __( 'Event Nb: %d', 'em-ess' ), $ie ) ), "rss" ) );
				$newEvent->setUri( 			$event_url );
				$newEvent->setPublished( 	ESS_Timezone::get_date_GMT( strtotime( $EM_Event->post_date 	) ) );
				$newEvent->setUpdated( 		ESS_Timezone::get_date_GMT( strtotime( $EM_Event->post_modified ) ) );
				$newEvent->setAccess( 		( ( intval( $EM_Event->event_private ) === 1 )? EssDTD::ACCESS_PRIVATE : EssDTD::ACCESS_PUBLIC ) );

				// -- encode a unic ID base on the host name + event_id (e.g. www.sample.com:123)
				$newEvent->setId( ( ( strlen( @$_SERVER[ 'SERVER_NAME' ] ) > 0 )? $_SERVER[ 'SERVER_NAME' ] : @$_SERVER[ 'HTTP_HOST' ] ) . ":" . $EM_Event->event_id );

				$description = wpautop( $EM_Event->post_content, TRUE );

				if ( get_option( 'ess_backlink_enabled' ) )
				{
					$feed_uri_host 	= parse_url ( $event_url, PHP_URL_HOST );
					$description 	= "<h6>". __( "Source:", 'em-ess') . " <a target=\"_blank\" title=\"". __( "Source:", 'em-ess') . " ".$feed_uri_host."\" href=\"" . $event_url . "\">" .  $feed_uri_host . "</a></h6>" . $description;
				}

				//dd( $description );

				// Add custom attributes json encoded in HTML comments <!--||ATTR||xx{xx:'xx',xx...}||ATTR||-->
				if ( @count( @$EM_Event->event_attributes ) > 0 && @$EM_Event->event_attributes != NULL )
				{
					$custom_att = $EM_Event->event_attributes;
					//dd( $custom_att );

					$description .=
					"<!--" .
						self::CUSTOM_ATTRIBUTE_SEPARATOR .
							json_encode(
								iconv(
									mb_detect_encoding( $custom_att, mb_detect_order(), TRUE ),
									"UTF-8", $custom_att
								),
								JSON_UNESCAPED_SLASHES
							) .
						self::CUSTOM_ATTRIBUTE_SEPARATOR .
					"-->";
				}

				$newEvent->setDescription( $description );
				//ddd( $description );


				// ====== TAGS ========
				$tags = get_the_terms( $EM_Event->post_id, EM_TAXONOMY_TAG );
				//var_dump( $tags );
				if ( count( $tags ) > 0 && $tags != NULL )
				{
					$arr_ = array();

					foreach ( $tags as $tag )
					{
						if ( strlen( $tag->name ) > 1 && is_numeric( $tag->name ) == FALSE )
							array_push( $arr_, $tag->name );
					}

					if ( count( $arr_ ) > 0 )
						$newEvent->setTags( $arr_ );
				}



				// ====== CATEGORIES ========
				//ddd( $EM_Event );
				// $categories_ = EM_Categories::get( $EM_Event->get_categories()->get_ids() ); // enumerate all the categories
				if ( @count( @$EM_Event->categories ) > 0 && @$EM_Event->categories != NULL )
				{
					$cat_duplicates_ = array();

					foreach ( $EM_Event->categories as $cat )
					{
						$category_type = get_option( 'ess_feed_category_type' );

						if ( strlen( $cat->name ) > 0 && strlen( $category_type ) > 3 )
						{
							if ( in_array( strtolower( $cat->name ), $cat_duplicates_ ) == FALSE )
							{
								$newEvent->addCategory( $category_type, array(
									'name'	=> $cat->name,
									'id'	=> $cat->id
								));
							}
							$cat_duplicates_[] = strtolower( $cat->name );
						}
					}
				}



				// ====== DATES =========
				$event_start = NULL;
				if ( isset( $EM_Event->event_start_date ) && isset( $EM_Event->event_end_date ) )
				{
					$event_start = ESS_Timezone::get_date_GMT( strtotime( $EM_Event->event_start_date ." ". $EM_Event->event_start_time ) );
					$event_stop  = ESS_Timezone::get_date_GMT( strtotime( $EM_Event->event_end_date	  ." ". $EM_Event->event_end_time	) );

					// -- STANDALONE -----
					if ( $EM_Event->recurrence <= 0 )
					{
						$duration_s = FeedValidator::getDateDiff( 's', $event_start, $event_stop ); // number of seconds between two dates

						$newEvent->addDate( 'standalone', 'hour', NULL,NULL,NULL,NULL, array(
	                        'name'     	=> __( 'Date', 'em-ess'), // Must be a unique name per event
	                        'start'     => $event_start,
	                        'duration'	=> ( ( $duration_s > 0 )? $duration_s / 60 / 60 : 0 )
	                    ) );
	               	}
					// -- RECURCIVE -----
					else
					{
						$interval = intval( $EM_Event->recurrence_interval );

						$u = $EM_Event->recurrence_freq;
						$event_unit = ( ( $u == 'daily'   )? 'day'   :
									  ( ( $u == 'weekly'  )? 'week'  :
									  ( ( $u == 'monthly' )? 'month' :
								  	  ( ( $u == 'yearly'  )? 'year'  :
														     'hour'
						))));

						switch ( $event_unit )
						{
							default :
							case 'day'	: $limit = FeedValidator::getDateDiff( 'd',    $event_start, $event_stop ); break; // number of days
							case 'hour'	: $limit = FeedValidator::getDateDiff( 'h',    $event_start, $event_stop ); break; // number of hours
							case 'week'	: $limit = FeedValidator::getDateDiff( 'ww',   $event_start, $event_stop ); break; // number of weeks
							case 'month': $limit = FeedValidator::getDateDiff( 'm',    $event_start, $event_stop ); break; // number of months
							case 'year'	: $limit = FeedValidator::getDateDiff( 'yyyy', $event_start, $event_stop ); break; // number of years
						}

						$d = intval( $EM_Event->recurrence_byday );
						$selected_day = ( ( $event_unit == 'year' || $event_unit == 'month' || $event_unit == 'week' )?
											( ( $d == 0 )? 'sunday' :
											( ( $d == 1 )? 'monday' :
											( ( $d == 2 )? 'tuesday' :
											( ( $d == 3 )? 'wednesday' :
											( ( $d == 4 )? 'thursday' :
											( ( $d == 5 )? 'friday' :
											( ( $d == 6 )? 'saturday' :
												 	   ''
						))))))) : '' );

						$w = intval( $EM_Event->recurrence_byweekno );
						$selected_week = ( ( $event_unit == 'month' )?
											( ( $w == -1 )? 'last' 	 :
										 	( ( $w == 1  )? 'first'  :
										 	( ( $w == 2  )? 'second' :
								 		 	( ( $w == 3  )? 'third'  :
										 	( ( $w == 4  )? 'fourth' :
										 				 	''
						))))) : '' );

						$newEvent->addDate( 'recurrent',
							$event_unit,
							$limit,
							$interval,
							$selected_day,
							$selected_week,
							array(
								'name' 		=> sprintf( __( 'Date: %s', 'em-ess'), $event_start ),
								'start'		=> $event_start,
								'duration'	=> 0 // information lost...
							)
						);
					}
				} // end date



				// ====== PLACES ========
				$places_ = $EM_Event->get_location();
				//ddd( $places_ );
				if ( is_object( $places_ ) && strlen( $places_->location_name ) > 0 )
				{
					$newEvent->addPlace( 'fixed', null, array(
						'name'			=> (($places_->location_name==$places_->location_address)?sprintf( __( 'Place: %s', 'em-ess' ), $places_->location_name ) : $places_->location_name ),
						'latitude'		=> ((isset($places_->location_latitude ))? (round( $places_->location_latitude*10000  ) / 10000):''),
						'longitude' 	=> ((isset($places_->location_longitude))? (round( $places_->location_longitude*10000 ) / 10000):''),
						'address' 		=> ((strlen($places_->location_address)>0)?$places_->location_address:$places_->location_name),
						'city' 			=> $places_->location_town,
						'zip' 			=> $places_->location_postcode,
						'state' 		=> $places_->location_region,
						'state_code' 	=> $places_->location_state,
						'country' 		=> FeedValidator::$COUNTRIES_[ strtoupper( $places_->location_country ) ],
						'country_code' 	=> ( ( strtolower( $places_->location_country ) == 'xe' )? '' : $places_->location_country )
					) );
				} // end place



				// ====== PRICES =========
				//ddd($EM_Event);
				if ( $EM_Event->is_free() == TRUE && $EM_Event->event_rsvp == FALSE )
				{
					$newEvent->addPrice( 'standalone', 'free', NULL, NULL, NULL, NULL, NULL, array(
						'name'		=> __( 'Free', 'em-ess' ),
						'currency' 	=> get_option( 'ess_feed_currency', ESS_Database::DEFAULT_CURRENCY ),
						'value'		=> 0
					));
				}
				else
				{
				    // -- get tickets form Robby, if the tickeing plugin is installed.
                    if ( defined( 'EM_ROBBY_VERSION' ) === TRUE && class_exists( 'HC_Database' ) === TRUE )
                    {
                        $API_credentials_ = HC_Database::get();

                        if ( @count( $API_credentials_ ) > 0 )
                        {
                            // -- get the IDs biding between local ones and Robby.
                            $api = new HC_API();
                            $r = $api->call( 'events/get_ids.json', array( 'id' => $newEvent->getId(), 'api_token' => $API_credentials_[0][ 'api_token' ] ) );
                            $event_ids = ( ( isset( $r->result ) )? @$r->result : NULL );
                            //d( $EM_Event->event_id, $event_ids );

                            $prices_ = $api->call( 'account/events/tickets/list.json', array('eventID' => $event_ids->eventID) );
                            //ddd($prices_);

                            if ( $prices_ && @$prices_->result && @$prices_->result->result && @count($prices_->result->result) > 0)
                            {
                                foreach ( $prices_->result->result as $i => $price )
                                {
                                    if ( is_object( $price ) )
                                    {
                                        $duration_s = ( ( $price->ticket_start && $price->ticket_end )? FeedValidator::getDateDiff( 's', $price->ticket_start, $price->ticket_end ) : 0 );

                                        $ticket_start   = ( ( isset( $price->ticket_start ) )? ESS_Timezone::get_date_GMT( strtotime( $price->ticket_start ) ) : 0 );
                                        $ticket_end     = ( ( isset( $price->ticket_end   ) )? ESS_Timezone::get_date_GMT( strtotime( $price->ticket_end   ) ) : 0 );

                                        $p       = intval( $price->ticket_price );
                                        $e_start = strtotime( $event_start );
                                        $t_end   = strtotime( $ticket_end );

                                        $newEvent->addPrice( (isset($price->type) ? $price->type : 'standalone'), (isset($price->mode) ? $price->mode : "fixed"), "hour", NULL, NULL, NULL, NULL, array(
                                            'name'          => ( ( isset( $price->name ) && strlen( $price->name ) > 0               ) ? $price->name        : sprintf( __( "Ticket Nb %s", 'em-ess' ), $i ) ),
                                            'description'   => ( ( isset( $price->description ) && strlen( $price->description ) > 0 ) ? $price->description : ''),
                                            'currency'      => ( ( isset( $price->currency ) && strlen( $price->currency ) == 3      ) ? $price->currency    : get_option( 'ess_feed_currency', ESS_Database::DEFAULT_CURRENCY )),
                                            'value'         => ( ( isset( $price->value ) && floatval( $price->value ) > 0           ) ? $price->value       : 0  ), // ticket amount
                                            'start'         => ( ( isset( $price->start ) && strlen( $price->start ) > 18            ) ? ESS_Timezone::get_date_GMT( strtotime( $price->start ) ) : '' ), // ex: '2017-02-55T12:44:58+00:00'
                                            'duration'      => ( ( isset( $price->duration ) && floatval( $price->duration ) > 0     ) ? $price->duration    : 0  ), // pre-sells duration in hours
                                            'uri'           => $event_url . "#em-booking"
                                        ));
                                    }
                                }
                            }
                        }
                    }

                    // -- get the tickets from Events Manager
                    else
                    {
    					$prices_ = $EM_Event->get_bookings();
    					//ddd($prices_);

    					if ( $prices_ )
    					{
    						if ( count( $prices_->tickets->tickets ) > 0 && $event_start != NULL && $prices_->tickets->tickets != NULL )
    						{
    							if ( isset( $prices_->tickets ) )
    							{
    								if ( is_array( $prices_->tickets->tickets ) )
    								{
    									foreach ( $prices_->tickets->tickets as $i => $price )
    									{
    										if ( is_object( $price ) )
    										{
    											$duration_s = (
    											 (  isset( $price->ticket_start ) &&
    											    isset( $price->ticket_end ) &&
								                    $price->ticket_start &&
								                    $price->ticket_end
                                                  )?
                                                  FeedValidator::getDateDiff( 's', $price->ticket_start, $price->ticket_end )
                                                  :
                                                  0
                                                );

    											$ticket_start 	= ( ( isset( $price->ticket_start ) )? ESS_Timezone::get_date_GMT( strtotime( $price->ticket_start ) ) : 0 );
    											$ticket_end	 	= ( ( isset( $price->ticket_end   ) )? ESS_Timezone::get_date_GMT( strtotime( $price->ticket_end   ) ) : 0 );

    											$p 		 = intval( $price->ticket_price );
    											$e_start = strtotime( $event_start );
    											$t_end 	 = strtotime( $ticket_end );
    											$t_mode	 = ( ( $p > 0 )?( ( $e_start < $t_end || $ticket_end == 0 )? 'fixed' : 'prepaid' ) : 'free' );

    											$newEvent->addPrice( 'standalone', $t_mode, "hour", NULL, NULL, NULL, NULL, array(
    												'name'		=> ( ( isset( $price->ticket_name ) && strlen( $price->ticket_name ) > 0    )? $price->ticket_name   : sprintf( __( "Ticket Nb %s", 'em-ess' ), $i ) ),
    												'currency' 	=> get_option( 'ess_feed_currency', ESS_Database::DEFAULT_CURRENCY ),
    												'value'		=> ( ( isset( $price->ticket_price) && floatval( $price->ticket_price ) > 0 )? $price->ticket_price  : 0 ),
    												'start' 	=> ( ( isset( $price->ticket_start ) && strlen( $price->start ) > 18        )? ESS_Timezone::get_date_GMT( $ticket_start ) : '' ),
    												'duration' 	=> ( ( floatval( $duration_s ) > 0                                          )? $duration_s / 60 / 60 : 0 ), // pre-sells duration in hours
    												'uri'		=> $event_url."#em-booking"
    											));
    										}
    									}
    								}
    							}
    						}
    					}
					}
                } // end prices



				// ====== PEOPLE ===========
				$people_ = $EM_Event->get_contact();
				//ddd( $people_ );
				if ( $people_ instanceof EM_Person )
				{
					$owner_name = ( ( strlen( self::_g( 'ess_owner_firstname' ) ) > 0 && strlen( self::_g( 'ess_owner_lastname' ) ) > 0 )?
						trim( self::_g('ess_owner_firstname') . " " . self::_g('ess_owner_lastname' ) )
						:
						$people_->data->display_name
					);

					if ( strlen( $owner_name ) > 0 && get_option( 'ess_owner_activate' ) )
					{
					    $organizer_uri = self::_g('ess_owner_website');

                        if (FeedValidator::isValidURL($organizer_uri) == FALSE)
					    {
					        $uri_start = substr( $organizer_uri, 0, 4 );
					        $organizer_uri = (($uri_start != "http" && $uri_start != "ftp:") ? "http://" . $organizer_uri : "");
                            $organizer_uri = ((FeedValidator::isValidURL($organizer_uri) == FALSE) ? "" : $organizer_uri);
					    }

						$newEvent->addPeople( 'organizer', array(
							'name' 			=> $owner_name,
							'firstname' 	=> self::_g('ess_owner_firstname'),
							'lastname' 		=> self::_g('ess_owner_lastname' ),
							'organization' 	=> self::_g('ess_owner_company'	 ),
							'logo' 			=> "",
							'icon'			=> "",
							'uri'			=> $organizer_uri,
							'address'		=> self::_g('ess_owner_address'	 ),
							'city'			=> self::_g('ess_owner_city'	 ),
							'zip'			=> self::_g('ess_owner_zip'		 ),
							'state'			=> self::_g('ess_owner_state'	 ),
							'state_code' 	=> "",
							'country'	 	=> FeedValidator::$COUNTRIES_[ strtoupper( self::_g( 'ess_owner_country' ) ) ],
							'country_code' 	=> ( ( strtolower( self::_g( 'ess_owner_country' ) ) == 'xe' )? 'GB' : self::_g('ess_owner_country' ) ),
							'email'			=> self::_g('ess_owner_email'	 ),
							'phone' 		=> self::_g('ess_owner_phone'	 )
						) );

					}

					foreach ( ESS_Database::$SOCIAL_PLATFORMS as $type => $socials_ )
					{
						foreach ( $socials_ as $social )
						{
							if ( FeedValidator::isValidURL( get_option( 'ess_social_' . $social ) ) )
								$newEvent->addPeople( 'social', array(
									'name' 	=> __( ucfirst( $social ), 'em-ess' ),
									'uri' 	=> self::_g( 'ess_social_' . $social )
								) );
						}
					}

					if ( strlen(  self::_g( 'ess_feed_title' ) ) > 0 && FeedValidator::isValidURL( self::_g( 'ess_feed_website' ) ) )
					{
						$newEvent->addPeople( 'author', array(
							'name' 	=> self::_g( 'ess_feed_title' 	),
							'uri' 	=> self::_g( 'ess_feed_website' )
						) );
					}

					if ( strlen( $EM_Event->post_excerpt ) > 0 )
					{
						$newEvent->addPeople( 'attendee', array(
							'name' 			=> __( 'Required' ),
							'minpeople' 	=> 0,
							'maxpeople'		=> 0,
							'minage'		=> 0,
							'restriction'	=> esc_html( $EM_Event->post_excerpt )
						) );
					}
				}



				// ====== MEDIA ============
				// -- IMAGES -----
				if ( get_option( 'ess_feed_export_images' ) )
				{
					$IMG_ = array();

					$media_url = $EM_Event->get_image_url();
					if ( FeedValidator::isValidURL( $media_url ) )
						array_push( $IMG_, array( 'name' => __('Main Image', 'em-ess'),  'uri' => $media_url ) );

					$images_ = ESS_Images::get( $EM_Event->post_id );
					if ( count( $images_ ) > 0 && $images_ != NULL )
					{
						foreach ( $images_ as $i => $img_ )
							array_push( $IMG_, array( 'name' => ( ( strlen( $img_['name'] ) > 0 )? $img_['name'] : sprintf( __('Image %d', 'em-ess'), $i ) ),  'uri' => $img_['uri'] ) );
					}

					if ( count( $IMG_ ) > 0 && $IMG_ != NULL )
					{
						$IMG_ = array_map( "unserialize", array_unique( array_map( "serialize", $IMG_ ) ) );
						$duplicates_ = array();
						foreach ( $IMG_ as $i => $img_ )
						{
							if ( !in_array( $img_['uri'], $duplicates_ ) )
								$newEvent->addMedia( 'image', array('name' => ( ( strlen( $img_['name'] ) > 0 )? sprintf( __('Image %d', 'em-ess'), $i ) : $img_['name'] ),  'uri' => $img_['uri'] ) );
							array_push( $duplicates_, $img_['uri'] );
						}
					}
				}

				// -- SOUNDS -----
				if ( get_option( 'ess_feed_export_sounds' ) )
				{
					// TODO:...
				}

				// -- VIDEOS -----
				if ( get_option( 'ess_feed_export_videos' ) )
				{
					// TODO:...
				}

				// -- WEBSITES -----
				if ( FeedValidator::isValidURL( self::_g( 'ess_feed_website' ) ) ) {
					$newEvent->addMedia( 'website', array('name' => __('The website', 'em-ess'),  'uri' => self::_g( 'ess_feed_website' ) ) );
                }


				$essFeed->addItem( $newEvent );

				$count++;
			}

			// -- We've reached the limit of event per feed, or showing one event only
			if ( !empty( $event_id ) || intval( $event_id ) <= 0 ||
				 ( get_option( 'ess_feed_limit', $limit ) != 0 && $count > get_option( 'ess_feed_limit', $limit ) ) ||
				 ( $count <= get_option( 'ess_feed_limit', $limit ) && intval( $page ) <= 1 )
			)
			{
			    break;
			}
			else
			{
			    //-- Go to the next page of results
			    $page++;
				self::output( $event_id, $page, $is_download, $is_push );
			}
		}

	  	$essFeed->IS_DOWNLOAD	= ( $is_download === TRUE )? TRUE : FALSE;
		$essFeed->AUTO_PUSH 	= ( get_option( 'ess_feed_push', TRUE ) && $is_push === TRUE ) ? TRUE : FALSE;

		//var_dump( $essFeed );die;
		$essFeed->genarateFeed();
	}

	private static function _g( $name, $default='' )
	{
		return ESS_Database::get_option( $name, $default );
	}

	/**
	 * No more required, correction of the issue 7
	 *
	 * @see  https://github.com/essfeed/wordpress-events-manager-ess/issues/7
	 * @todo DELETE this method
	 *
	private static function unhtmlentities( $string )
	{
	   	// -- Replace numeric entities
	   	$string = preg_replace('~&#x([0-9a-f]+);~ei', 'chr(hexdec("\\1"))', $string );
	   	$string = preg_replace('~&#([0-9]+);~e', 'chr(\\1)', $string );

		$trans_tbl = "";

	   	// -- Replace literal entities
	   	if ( function_exists( 'get_html_translation_table' ) )
	   	{
	   		$trans_tbl = get_html_translation_table( HTML_ENTITIES );
	   		$trans_tbl = array_flip( $trans_tbl );
		}

	   return strtr( $string, $trans_tbl );
	}
	*/

}