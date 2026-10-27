<?php
/**
 * REST API keys: issuing one (POST /api-keys called a repository method that did not exist and
 * wrote an 8-character truncated key into CHAR(7)), fetching one (GET /api-keys/{id} called a
 * missing find()), scopes, revoking, and a key acting only on wplm/v1 routes.
 *
 * @package WPLM\Tests
 */

namespace WPLM\Tests\Integration\Rest;

use WPLM\Repositories\ApiKeyRepository;
use WPLM\Rest\Auth\BasicAuth;
use WPLM\Services\ApiKeyService;
use WPLM\Tests\TestCase;

class ApiKeysTest extends TestCase {

	private int $admin;

	/** The request URI the test bootstrap set, put back after each test. */
	private ?string $request_uri;

	public function set_up(): void {
		parent::set_up();
		$this->admin       = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->request_uri = $_SERVER['REQUEST_URI'] ?? null;
	}

	public function tear_down(): void {
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_GET['rest_route'] );
		if ( null === $this->request_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->request_uri;
		}
		( new \ReflectionProperty( BasicAuth::class, 'current_api_key' ) )->setValue( null, null );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function service(): ApiKeyService {
		return $this->make( ApiKeyService::class );
	}

	/** @return array{id:int, consumer_key:string, consumer_secret:string} */
	private function issue( string $permissions ): array {
		$result = $this->service()->create( $this->admin, 'Test key', $permissions );
		$this->assertTrue( $result['ok'], $result['message'] );
		return $result['key'];
	}

	/** Authenticate as an HTTP request to $route signed with the key would, and return the user. */
	private function sign_in( array $key, string $route = '/wplm/v1/licenses' ) {
		$_SERVER['REQUEST_URI']        = '/wp-json' . $route;
		$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode( $key['consumer_key'] . ':' . $key['consumer_secret'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$user = ( new BasicAuth( $this->make( ApiKeyRepository::class ) ) )->authenticate( false );
		wp_set_current_user( $user instanceof \WP_User ? $user->ID : 0 );
		return $user;
	}

	private function request( string $method, string $route, array $body = array() ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, $route );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		return rest_do_request( $request );
	}

	public function test_a_write_key_issues_a_new_key_over_rest(): void {
		$this->sign_in( $this->issue( 'read_write' ), '/wplm/v1/api-keys' );

		$response = $this->request( 'POST', '/wplm/v1/api-keys', array( 'description' => 'Billing server', 'permissions' => 'read' ) );

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data()['data'];
		$this->assertMatchesRegularExpression( '/^ck_[0-9a-f]{40}$/', $data['consumer_key'] );
		$this->assertMatchesRegularExpression( '/^cs_[0-9a-f]{40}$/', $data['consumer_secret'] );
		$this->assertSame( $this->admin, $data['user_id'] );

		$stored = $this->make( ApiKeyRepository::class )->find( $data['id'] );
		$this->assertSame( substr( $data['consumer_key'], -7 ), $stored->truncated_key );
		$this->assertSame( hash( 'sha256', $data['consumer_secret'] ), $stored->consumer_secret, 'Only the hash is stored.' );
	}

	public function test_one_key_can_be_fetched_by_id(): void {
		$key = $this->issue( 'read' );
		$this->sign_in( $key, '/wplm/v1/api-keys/' . $key['id'] );

		$response = $this->request( 'GET', '/wplm/v1/api-keys/' . $key['id'] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Test key', $response->get_data()['data']['description'] );
		$this->assertArrayNotHasKey( 'consumer_secret', $response->get_data()['data'] );
		$this->assertSame( 404, $this->request( 'GET', '/wplm/v1/api-keys/999999' )->get_status() );
	}

	public function test_a_read_key_cannot_issue_keys(): void {
		$this->sign_in( $this->issue( 'read' ), '/wplm/v1/api-keys' );

		$response = $this->request( 'POST', '/wplm/v1/api-keys', array( 'description' => 'x', 'permissions' => 'read_write' ) );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_a_revoked_key_no_longer_signs_in(): void {
		$key = $this->issue( 'read_write' );
		$this->assertInstanceOf( \WP_User::class, $this->sign_in( $key ) );

		$this->assertTrue( $this->service()->revoke( $key['id'] )['ok'] );

		$this->assertNull( $this->sign_in( $key ) );
		$this->assertFalse( $this->service()->revoke( $key['id'] )['ok'], 'Revoking twice says the key is gone.' );
	}

	public function test_a_key_acts_only_on_wplm_routes(): void {
		$key = $this->issue( 'read_write' );

		$this->assertSame( $this->admin, $this->sign_in( $key, '/wplm/v1/licenses' )->ID );
		$this->assertFalse( $this->sign_in( $key, '/wp/v2/users/me' ), 'Not signed in as the owner on wp/v2.' );
		$this->assertFalse( $this->sign_in( $key, '/wplm/v10/x' ), 'Another namespace that merely starts the same.' );

		unset( $_SERVER['REQUEST_URI'] );
		$_GET['rest_route'] = '/wplm/v1/licenses';
		$this->assertInstanceOf( \WP_User::class, ( new BasicAuth( $this->make( ApiKeyRepository::class ) ) )->authenticate( false ), 'Plain permalinks.' );
		$_GET['rest_route'] = '/wp/v2/users';
		$this->assertFalse( ( new BasicAuth( $this->make( ApiKeyRepository::class ) ) )->authenticate( false ) );
	}

	public function test_bad_input_is_refused_and_writes_nothing(): void {
		$before = count( $this->make( ApiKeyRepository::class )->get_all() );

		$this->assertFalse( $this->service()->create( $this->admin, 'x', 'admin' )['ok'] );
		$this->assertFalse( $this->service()->create( 999999, 'x', 'read' )['ok'] );

		$this->assertCount( $before, $this->make( ApiKeyRepository::class )->get_all() );
	}
}
