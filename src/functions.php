<?php

namespace earnjam;

function index_stats( $start, $end ) {

	echo "Getting tickets...\n";

	// Get the tickets opened in the time period
	$tickets = get_tickets_in_timeframe( $start, $end );

	// Loop through and reset their values to original and index in temp index
	foreach ( $tickets as $raw_ticket ) {

		echo 'Resetting ticket #' . $raw_ticket['_id'] . "\n";

		$ticket = $raw_ticket['_source'];

		$update_types = array(
			'type'      => 'previous',
			'milestone' => 'previous',
			'keywords'  => 'previous_terms',
			'status'    => 'previous',
			'component' => 'previous',
		);

		// Loop through all the updates
		foreach ( $ticket['updates'] as $update ) {

			// Make sure we haven't already reset this type of update to original
			if ( array_key_exists( $update['update_type'], $update_types ) ) {

				$type = $update['update_type'];

				if ( $type === 'type' ) {
					$ticket['ticket_type'] = $update[ $update_types[ $type ] ];
				} else {
					$ticket[ $type ] = $update[ $update_types[ $type ] ];
				}

				// Reset the original value
				$ticket[ $type ] = $update[ $update_types[ $type ] ];

				// Make sure we don't do this update type again
				unset( $update_types[ $update['update_type'] ] );
			}
		}

		unset( $ticket['updates'] );

		echo 'Indexing original ticket #' . $raw_ticket['_id'] . "\n";
		index_ticket( $raw_ticket['_id'], $ticket );

	}

	echo "=========\nGetting Updates\n";

	// Get all ticket updates during time period
	$updates = get_updates_in_timeframe( $start, $end );

	// Loop through and apply the updates to tickets
	foreach ( $updates as $update ) {

		$id = $update['_id'];
		echo "Applying update to ticket #$id\n";

		$ticket = get_ticket( $id );

		$update = $update['_source'];

		$ticket[ $update['update_type'] ] = ( $update['update_type'] === 'keywords' ) ? $update['new_terms'] : $update['new'];

		echo "Re-indexing ticket #$id\n";

		index_ticket( $id, $ticket );

	}

	echo "=========\nPreparing to index stats\n==========\n";

	// Gather the stats and index them
	index_data( $start );

}


function get_tickets_in_timeframe( $start, $end ) {
	global $hub_es;

	$params   = array(
		'index' => 'wptrac',
		'type'  => '_doc',
		'body'  => array(
			'_source' => array(
				'status',
				'ticket_type',
				'milestone',
				'component',
				'keywords',
				'updates',
			),
			'size'    => 1000,
			'sort'    => array(
				'created' => array(
					'order' => 'asc',
				),
			),
			'query'   => array(
				'range' => array(
					'created' => array(
						'gt'  => $start,
						'lte' => $end,
					),
				),
			),
		),
	);
	$response = $hub_es->search( $params );

	$tickets = $response['hits']['hits'];

	return $tickets;
}

function index_ticket( $id, $ticket ) {
	global $loc_es;

	$params = array(
		'index' => 'trac',
		'type'  => '_doc',
		'id'    => $id,
		'body'  => $ticket,
	);

	$response = $loc_es->index( $params );

}

function get_ticket( $id ) {
	global $loc_es;

	$params = array(
		'index' => 'trac',
		'type'  => '_doc',
		'id'    => $id,
	);

	$ticket = $loc_es->get( $params );
	return $ticket['_source'];
}


function get_updates_in_timeframe( $start, $end, $offset = 0 ) {
	global $hub_es;

	$updates = array();
	$params  = array(
		'index' => 'wptrac',
		'type'  => '_doc',
		'body'  => array(
			'size' => 0,
			'aggs' => array(
				'updates' => array(
					'nested' => array(
						'path' => 'updates',
					),
					'aggs'   => array(
						'filterUpdates' => array(
							'filter' => array(
								'bool' => array(
									'filter' => array(
										array(
											'range' => array(
												'updates.time' => array(
													'gt'  => $start,
													'lte' => $end,
												),
											),
										),
										array(
											'terms' => array(
												'updates.update_type' => array(
													'type',
													'milestone',
													'keywords',
													'status',
													'component',
												),
											),
										),
									),
								),
							),
							'aggs'   => array(
								'rawData' => array(
									'top_hits' => array(
										'from' => $offset,
										'size' => 100,
										'sort' => array(
											'updates.time' => array(
												'order' => 'asc',
											),
										),
									),
								),
							),
						),
					),
				),
			),
		),
	);

	$response = $hub_es->search( $params );

	$count = $response['aggregations']['updates']['filterUpdates']['doc_count'];
	if ( $count > 100 && $count > $offset + 100 ) {
		$updates = get_updates_in_timeframe( $start, $end, $offset + 100 );
	}
	$updates = array_merge( $updates, $response['aggregations']['updates']['filterUpdates']['rawData']['hits']['hits'] );
	return $updates;
}

function index_data( $start ) {
	global $loc_es;

	$match_all = array(
		'index' => 'trac',
		'type'  => '_doc',
		'body'  => array(
			'size'  => 0,
			'query' => array(
				'match_all' => new \stdClass(),
			),
		),
	);

	$all = $loc_es->search( $match_all );

	$body   = get_data_query();
	$params = array(
		'index' => 'trac',
		'type'  => '_doc',
		'body'  => $body,
	);
	$open   = $loc_es->search( $params );
	$aggs   = $open['aggregations'];

	$doc = array(
		'time'        => $start,
		'total'       => $all['hits']['total'],
		'open'        => $open['hits']['total'],
		'ticket_type' => extract_ticket_types( $aggs['ticket_type'] ),
		'keyword'     => extract_keywords( $aggs['keyword'] ),
		'milestone'   => extract_milestones( $aggs['milestone'] ),
		'component'   => extract_components( $aggs['component'] ),
	);

	$id      = gmdate( 'Y-m-d', $start / 1000 );
	$payload = array(
		'index' => 'trac-data',
		'type'  => '_doc',
		'id'    => $id,
		'body'  => $doc,
	);

	$response = $loc_es->index( $payload );

	echo "=========\nIndexed $id\n=========\n";

}

function extract_ticket_types( $data ) {
	return $data['buckets'];
}

function extract_keywords( $data ) {
	$keywords = array();
	foreach ( $data['buckets'] as $keyword ) {
		$types      = extract_ticket_types( $keyword['ticket_type'] );
		$keywords[] = array(
			'key'         => $keyword['key'],
			'doc_count'   => $keyword['doc_count'],
			'ticket_type' => $types,
		);
	}
	return $keywords;
}

function extract_milestones( $data ) {
	$milestones = array();
	foreach ( $data['buckets'] as $milestone ) {
		$types        = extract_ticket_types( $milestone['ticket_type'] );
		$keywords     = extract_keywords( $milestone['keyword'] );
		$milestones[] = array(
			'key'         => $milestone['key'],
			'doc_count'   => $milestone['doc_count'],
			'ticket_type' => $types,
			'keyword'     => $keywords,
		);
	}
	return $milestones;
}

function extract_components( $data ) {
	$components = array();
	foreach ( $data['buckets'] as $component ) {
		$types        = extract_ticket_types( $component['ticket_type'] );
		$keywords     = extract_keywords( $component['keyword'] );
		$milestones   = extract_milestones( $component['milestone'] );
		$components[] = array(
			'key'         => $component['key'],
			'doc_count'   => $component['doc_count'],
			'ticket_type' => $types,
			'keyword'     => $keywords,
			'milestone'   => $milestones,
		);
	}
}

function recursive_extractor( $data ) {

	// Call all the way to the bottom
	if ( isset( $data['buckets'] ) ) {
		$children = recursive_extractor( $data['buckets'] );
	}

	foreach ( $data['buckets'] as $item ) {

	}
}

function get_data_query() {
	return array(
		'query' => array(
			'bool' => array(
				'filter' => array(
					'terms' => array(
						'status' => array(
							'new',
							'assigned',
							'reopened',
							'reviewing',
							'accepted',
						),
					),
				),
			),
		),
		'size'  => 0,
		'aggs'  => array(
			'ticket_type' => array(
				'terms' => array(
					'field' => 'ticket_type',
				),
			),
			'keyword'     => array(
				'terms' => array(
					'field'   => 'keywords',
					'include' => array(
						'has-patch',
						'needs-refresh',
						'needs-patch',
						'needs-unit-tests',
						'reporter-feedback',
						'dev-feedback',
						'close',
						'commit',
						'good-first-bug',
					),
				),
				'aggs'  => array(
					'ticket_type' => array(
						'terms' => array(
							'field' => 'ticket_type',
						),
					),
				),
			),
			'milestone'   => array(
				'terms' => array(
					'field' => 'milestone',
					'size'  => 100,
					'order' => array(
						'_term' => 'desc',
					),
				),
				'aggs'  => array(
					'ticket_type' => array(
						'terms' => array(
							'field' => 'ticket_type',
						),
					),
					'keyword'     => array(
						'terms' => array(
							'field'   => 'keywords',
							'include' => array(
								'has-patch',
								'needs-refresh',
								'needs-patch',
								'needs-unit-tests',
								'reporter-feedback',
								'dev-feedback',
								'close',
								'commit',
								'good-first-bugs',
							),
						),
						'aggs'  => array(
							'ticket_type' => array(
								'terms' => array(
									'field' => 'ticket_type',
								),
							),
						),
					),
				),
			),
			'component'   => array(
				'terms' => array(
					'field' => 'component',
					'size'  => 100,
					'order' => array(
						'_term' => 'asc',
					),
				),
				'aggs'  => array(
					'ticket_type' => array(
						'terms' => array(
							'field' => 'ticket_type',
						),
					),
					'keyword'     => array(
						'terms' => array(
							'field'   => 'keywords',
							'include' => array(
								'has-patch',
								'needs-refresh',
								'needs-patch',
								'needs-unit-tests',
								'reporter-feedback',
								'dev-feedback',
								'close',
								'commit',
								'good-first-bugs',
							),
						),
						'aggs'  => array(
							'ticket_type' => array(
								'terms' => array(
									'field' => 'ticket_type',
								),
							),
						),
					),
					'milestone'   => array(
						'terms' => array(
							'field' => 'milestone',
							'size'  => 100,
							'order' => array(
								'_term' => 'desc',
							),
						),
						'aggs'  => array(
							'ticket_type' => array(
								'terms' => array(
									'field' => 'ticket_type',
								),
							),
							'keyword'     => array(
								'terms' => array(
									'field'   => 'keywords',
									'include' => array(
										'has-patch',
										'needs-refresh',
										'needs-patch',
										'needs-unit-tests',
										'reporter-feedback',
										'dev-feedback',
										'close',
										'commit',
										'good-first-bugs',
									),
								),
								'aggs'  => array(
									'ticket_type' => array(
										'terms' => array(
											'field' => 'ticket_type',
										),
									),
								),
							),
						),
					),
				),
			),
		),
	);
}
