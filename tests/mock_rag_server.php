<?php
// mock_rag_server.php
// Simple mock RAG backend for local testing

header( 'Content-Type: application/json' );

function random_id( $length = 16 ) {
	return bin2hex( random_bytes( $length / 2 ) );
}

function random_answer() {
	$answers = [
		'This is a mock answer from the RAG backend.',
		'Here is a random response for your question.',
		'The answer is 42.',
		'Sorry, I am just a mock server!',
		'This is a test response. Have a nice day!'
	];
	return $answers[array_rand( $answers )];
}

$uri = $_SERVER['REQUEST_URI'];
$method = $_SERVER['REQUEST_METHOD'];

if ( preg_match( '#/search$#', $uri ) && $method === 'POST' ) {
	$input = json_decode( file_get_contents( 'php://input' ) );
	$response = [
		'gpt_result' => random_answer(),
		'docs' => [
			[ 'title' => 'Mock Document 1', 'url' => 'https://example.com/doc1' ],
			[ 'title' => 'Mock Document 2', 'url' => 'https://example.com/doc2' ]
		],
		'conversation_id' => random_id(),
	];
	echo json_encode( $response );
	exit;
}

if ( preg_match( '#/rating$#', $uri ) && $method === 'POST' ) {
	http_response_code( 200 );
	echo json_encode( [ 'status' => 'ok' ] );
	exit;
}

if ( preg_match( '#/get_config_metadata$#', $uri ) && $method === 'POST' ) {
	$response = [
		'available_models' => [
			'gpt-3.5-turbo' => [ 'supports_temperature' => true ],
			'gpt-4o-mini' => [ 'supports_temperature' => true ],
			'gpt-4o' => [ 'supports_temperature' => true ],
			'gpt-4.5-preview' => [ 'supports_temperature' => true ],
			'gpt-o1' => [ 'supports_temperature' => false ],
			'gpt-o3-mini' => [ 'supports_temperature' => false ],
			'gemini-2.5-flash' => [ 'supports_temperature' => true ],
			'gemini-2.5-pro' => [ 'supports_temperature' => true ]
		],
		'temperature_options' => [ 0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7, 0.8, 0.9 ],
		'num_of_pages_options' => [ 1, 2, 3, 4, 5 ],
		'config_field_types' => [
			'model' => 'string',
			'temperature' => 'float',
			'num_of_pages' => 'integer',
			'system_prompt' => 'string',
			'user_prompt' => 'string',
			'banned_fields' => 'string'
		]
	];
	echo json_encode( $response );
	exit;
}

// Default: Not found
http_response_code( 404 );
echo json_encode( [ 'error' => 'Not found' ] );
