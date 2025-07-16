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

// Default: Not found
http_response_code( 404 );
echo json_encode( [ 'error' => 'Not found' ] );
