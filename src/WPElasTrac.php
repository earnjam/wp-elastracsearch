<?php
/**
 * A class for working with data between WordPress Trac and an Elasticsearch cluster
 */

namespace earnjam;
use TracRPC\TracRPC;
use Elasticsearch\ClientBuilder;

class WPElasTrac {

	public $trac;

	public $client;

	public $index;

	public $type;

	/**
	 * Connects to Trac via RPC and sets up variables required for working with data
	 *
	 * @param array $config Optional list of configuration options
	 */
	function __construct( $config = array() ) {

		if ( empty( $config ) ) {
			$config = include dirname( dirname( __FILE__ ) ) . '/config.php';
		}

		$trac_params = array(
			'username'     => $config['username'],
			'password'     => $config['password'],
			'multiCall'    => false,
			'json_decode'  => false,
			'content-type' => 'xml',
		);

		$this->trac   = new TracRPC( 'https://core.trac.wordpress.org/login/xmlrpc', $trac_params );
		$this->client = ClientBuilder::create()->setHosts( $config['hosts'] )->build();
		$this->index  = $config['index'] ?? 'wptrac';
		$this->type   = $config['type'] ?? '_doc';
	}


	/**
	 * Indexes a specific Trac ticket into the ES cluster
	 *
	 * @param int  $id     ID of the ticket to index
	 * @param bool $encode Whether or not to run utf8_encode on the open-entry fields
	 *
	 * @return mixed Associative array containing a decoded version of the JSON that Elasticsearch returns or false on invalid ticket
	 */
	function index( $id, $encode = false ) {

		$data = $this->get_ticket_data( $id, $encode );

		if ( empty( $data ) ) {
			return false;
		}

		$params = array(
			'index' => $this->index,
			'type'  => $this->type,
			'id'    => $id,
			'body'  => $data,
		);

		try {
			// Try once to index the ticket like normal
			return $this->client->index( $params );
		} catch ( \Exception $e ) {
			// If we error out, then try it again with utf8_encode() on the open entry fields
			if ( ! $encode ) {
				$response = $this->index( $id, true );
				return $response;
			} else {
				// If that failed too, then say which ticket failed.
				echo "Failed indexing ticket #$id";
				die();
			}
		}

	}


	/**
	 * Delete a specific ticket from the ES cluster
	 *
	 * @return array Confirmation of ticket deletion
	 */
	function delete( $id ) {

		$params = array(
			'index' => $this->index,
			'type'  => $this->type,
			'id'    => $id,
		);

		return $this->client->delete( $params );
	}


	/**
	 * Gets ticket details from a specific ID
	 *
	 * @param int  $id     Track ticket ID number
	 * @param bool $encode Whether or not to run utf8_encode on the open-entry fields
	 *
	 * @return mixed Associative array of ticket data or false if no data
	 */
	function get_ticket_data( $id, $encode = false ) {

		$ticket = $this->trac->getTicket( (string) $id );

		// No ticket data, so short-circuit
		if ( empty( $ticket[3] ) ) {
			return false;
		}

		$ticket              = $ticket[3];
		$data                = array();
		$data['link']        = "https://core.trac.wordpress.org/ticket/$id";
		$data['status']      = $ticket['status'];
		$data['ticket_type'] = $ticket['type'];
		$data['created']     = $ticket['time']->timestamp * 1000;
		$data['updated']     = $ticket['changetime']->timestamp * 1000;
		$data['summary']     = ( $encode ) ? utf8_encode( $ticket['summary'] ) : $ticket['summary'];
		$data['description'] = ( $encode ) ? utf8_encode( $ticket['description'] ) : $ticket['description'];
		$data['reporter']    = $ticket['reporter'];
		$data['owner']       = $ticket['owner'];
		$data['milestone']   = $ticket['milestone'];
		$data['priority']    = $ticket['priority'];
		$data['severity']    = $ticket['severity'];
		$data['version']     = $ticket['version'];
		$data['component']   = $ticket['component'];
		$data['keywords']    = ( ! empty( $ticket['keywords'] ) ) ? $this->parse_terms( $ticket['keywords'] ) : array();
		$data['focuses']     = ( ! empty( $ticket['focuses'] ) ) ? $this->parse_terms( $ticket['focuses'] ) : array();
		$data['cc']          = $ticket['cc'];
		$data['resolution']  = $ticket['resolution'];

		$updates = $this->trac->getTicketChangelog( (string) $id );

		if ( ! empty( $updates ) ) {
			foreach ( $updates as $item ) {
				$update = $this->parse_update( $id, $item, $encode );
				// Don't index cc because it can leak emails
				if ( 'cc' !== $update['update_type'] ) {
					$data['updates'][] = $update;
				}
			}
		}

		return $data;
	}


	/**
	 * Parse raw ticket update data to prepare for indexing
	 *
	 * Ticket updates are returned as an simple array and can be better indexed by
	 * assigning keys and doing a bit of processing on them
	 *
	 * @param int   $id     The ID of the ticket to parse
	 * @param array $item   Array data about an individual ticket update
	 * @param bool  $encode Whether or not to run utf8_encode on the open-entry fields
	 *
	 * @return array Associative array of parsed update data
	 */
	function parse_update( $id, $item, $encode = false ) {

		$data['time']        = $item[0]->timestamp * 1000;
		$data['user']        = $item[1];
		$data['update_type'] = $item[2];

		switch ( $item[2] ) {

			case 'attachment':
				$filename     = ( $encode ) ? utf8_encode( $item[4] ) : $item[4];
				$data['link'] = "https://core.trac.wordpress.org/attachment/ticket/$id/$filename";
				break;

			case 'comment':
				$data['comment'] = ( $encode ) ? utf8_encode( $item[4] ) : $item[4];
				$comment_info    = explode( '.', $item[3] );

				if ( count( $comment_info ) > 1 ) {
					$data['comment_id']  = $comment_info[1];
					$data['replying_to'] = ( is_numeric( $comment_info[0] ) ) ? $comment_info[0] : 0;
				} elseif ( ! empty( $comment_info[0] ) ) {
					$data['comment_id'] = $comment_info[0];
				}

				if ( ! empty( $data['comment_id'] ) ) {
					$data['link'] = 'https://core.trac.wordpress.org/ticket/' . $id . '#comment:' . $data['comment_id'];
				}

				break;

			// Break up keywords and focuses strings into arrays
			case 'keywords':
			case 'focuses':
				$data['previous_terms'] = $this->parse_terms( $item[3] );
				$data['new_terms']      = $this->parse_terms( $item[4] );
				break;

			default:
				$data['previous'] = ( $encode ) ? utf8_encode( $item[3] ) : $item[3];
				$data['new']      = ( $encode ) ? utf8_encode( $item[4] ) : $item[4];
		}

		return $data;
	}

	/**
	 * Cleans a string of terms
	 *
	 * Keywords and Focuses sometimes appear to be comma separated and
	 * sometimes space separated because who knows...
	 * This should handle either situation.
	 *
	 * @param string $term_string A list of terms as a string
	 *
	 * @return array The cleaned list of terms separated into an array
	 */
	function parse_terms( $terms ) {
		$delimeter = ( strpos( $terms, ',' ) ) ? ',' : ' ';

		return array_map( 'trim', explode( $delimeter, $terms ) );
	}

}
