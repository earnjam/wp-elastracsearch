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
	 * @param int $id ID of the ticket to index
	 * @return mixed Associative array containing a decoded version of the JSON that Elasticsearch returns or false on invalid ticket
	 */
	function index( $id ) {

		$data = $this->get_ticket_data( $id );

		if ( empty( $data ) ) {
			return false;
		}

		$params = array(
			'index' => $this->index,
			'type'  => $this->type,
			'id'    => $id,
			'body'  => $data,
		);

		return $this->client->index( $params );
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
	 * @param int $id Track ticket ID number
	 * @return mixed Associative array of ticket data or false if no data
	 */
	function get_ticket_data( $id ) {

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
		$data['summary']     = utf8_encode( $ticket['summary'] );
		$data['reporter']    = $ticket['reporter'];
		$data['owner']       = $ticket['owner'];
		$data['milestone']   = $ticket['milestone'];
		$data['priority']    = $ticket['priority'];
		$data['severity']    = $ticket['severity'];
		$data['version']     = $ticket['version'];
		$data['component']   = $ticket['component'];
		$data['keywords']    = ( ! empty( $ticket['keywords'] ) ) ? explode( ' ', $ticket['keywords'] ) : array();
		$data['focuses']     = ( ! empty( $ticket['focuses'] ) ) ? explode( ' ', $ticket['focuses'] ) : array();
		$data['description'] = utf8_encode( $ticket['description'] );
		$data['cc']          = $ticket['cc'];
		$data['resolution']  = $ticket['resolution'];

		$updates = $this->trac->getTicketChangelog( (string) $id );

		if ( ! empty( $updates ) ) {
			foreach ( $updates as $item ) {
				$update = $this->parse_update( $id, $item );
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
	 * @param int $id The ID of the ticket to parse
	 * @param array $item Array data about an individual ticket update
	 *
	 * @return array Associative array of parsed update data
	 */
	function parse_update( $id, $item ) {

		$data['time']        = $item[0]->timestamp * 1000;
		$data['user']        = $item[1];
		$data['update_type'] = $item[2];

		switch ( $item[2] ) {

			case 'attachment':
				$filename     = utf8_encode( $item[4] );
				$data['link'] = "https://core.trac.wordpress.org/attachment/ticket/$id/$filename";
				break;

			case 'comment':
				$data['comment'] = utf8_encode( $item[4] );
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

			case 'keywords':
			case 'focuses':
				$data['previous'] = explode( ' ', utf8_encode( $item[3] ) );
				$data['new']      = explode( ' ', utf8_encode( $item[4] ) );
				break;

			default:
				$data['previous'] = utf8_encode( $item[3] );
				$data['new']      = utf8_encode( $item[4] );
		}

		return $data;
	}

}
