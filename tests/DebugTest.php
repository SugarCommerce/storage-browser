<?php
declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\StorageBrowser\Client;
use SugarCommerce\S3\Account;
use SugarCommerce\StorageBrowser\Tests\Support\FakeHttp;

/**
 * Debug output: storage's refusal, when asked for, and never a credential.
 */
final class DebugTest extends TestCase {

	/**
	 * What reached the debug callback.
	 *
	 * @var array<int, string>
	 */
	private array $reported = [];

	protected function setUp(): void {
		wp_test_reset();
		FakeHttp::queue( 403, '<Error><Code>SignatureDoesNotMatch</Code><Message>The request signature we calculated does not match</Message></Error>' );
	}

	private function client( bool $debug ): Client {
		$client = new Client( Account::r2( 'abc123' ), 'EXAMPLEKEYIDNOTREAL', 'verysecretsecretkey', false, 3600, $debug );

		$client->set_debug_callback(
			function ( string $title, $data ): void {
				$this->reported[] = $title . ' ' . print_r( $data, true );
			}
		);

		return $client;
	}

	public function test_a_refusal_is_reported_when_debugging(): void {
		$this->client( true )->get_objects( 'test-bucket' );

		$this->assertNotEmpty( $this->reported );
		$this->assertStringContainsString( 'SignatureDoesNotMatch', implode( "\n", $this->reported ) );
	}

	public function test_nothing_is_reported_otherwise(): void {
		$this->client( false )->get_objects( 'test-bucket' );

		$this->assertSame( [], $this->reported );
	}

	public function test_debug_can_be_turned_on_later(): void {
		$this->client( false )->set_debug( true )->get_objects( 'test-bucket' );

		$this->assertNotEmpty( $this->reported );
	}

	/**
	 * The signature and the keys are never in what is reported.
	 */
	public function test_no_credential_reaches_the_output(): void {
		$this->client( true )->get_objects( 'test-bucket' );

		$signature = (string) preg_replace( '/^.*Signature=/', '', (string) FakeHttp::last()['args']['headers']['Authorization'] );
		$output    = implode( "\n", $this->reported );

		$this->assertNotSame( '', $signature );
		$this->assertStringNotContainsString( $signature, $output );
		$this->assertStringNotContainsString( 'verysecretsecretkey', $output );
		$this->assertStringNotContainsString( 'EXAMPLEKEYIDNOTREAL', $output );
	}
}
