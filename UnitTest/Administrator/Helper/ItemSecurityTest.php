<?php
/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\ARS\UnitTest\Administrator\Helper;

defined('_JEXEC') or die;

use Akeeba\ARS\UnitTest\Stubs\ScriptedHttpClient;
use Akeeba\ARS\UnitTest\Stubs\ScriptedHttpResponse;
use Akeeba\Component\ARS\Administrator\Helper\ItemSecurity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Regression coverage for the security-audit fixes shared by:
 *  - ItemTable::onBeforeCheck()               (blind SSRF on save; write-time filename screen)
 *  - ItemModel::preDownloadCheck()/downloadFileItem()  (path traversal on the public download)
 *  - ItemModel::downloadLinkItem()             (non-blind SSRF proxy)
 *  - ItemsController::getFileNameToDelete()    (path traversal on the JSON:API delete)
 *
 * @since  __DEPLOY_VERSION__
 */
#[CoversClass(ItemSecurity::class)]
#[Group('Helper')]
class ItemSecurityTest extends TestCase
{
	/** @var string Root of a throwaway fixture tree created fresh for every test. */
	private string $root;

	protected function setUp(): void
	{
		$this->root = sys_get_temp_dir() . '/ars-item-security-test-' . bin2hex(random_bytes(8));

		mkdir($this->root . '/category/sub', 0777, true);
		mkdir($this->root . '/outside', 0777, true);

		file_put_contents($this->root . '/category/legit.zip', 'legit');
		file_put_contents($this->root . '/category/sub/nested.zip', 'nested');
		file_put_contents($this->root . '/outside/secret.zip', 'secret');
	}

	protected function tearDown(): void
	{
		$this->removeTree($this->root);

		// Every test that calls setHttpClientFactoryForTesting() MUST undo it -- it is deliberately
		// global, mutable state (see that method's own docblock) that would otherwise silently leak
		// into whichever test happens to run next.
		ItemSecurity::setHttpClientFactoryForTesting(null);

		parent::tearDown();
	}

	private function invokePrivateStatic(string $method, array $args = [])
	{
		$ref = new ReflectionMethod(ItemSecurity::class, $method);

		if (version_compare(PHP_VERSION, '8.1.0', 'lt'))
		{
			$ref->setAccessible(true);
		}

		return $ref->invoke(null, ...$args);
	}

	private function removeTree(string $dir): void
	{
		if (!is_dir($dir) && !is_link($dir))
		{
			return;
		}

		if (is_link($dir))
		{
			unlink($dir);

			return;
		}

		foreach (scandir($dir) ?: [] as $entry)
		{
			if ($entry === '.' || $entry === '..')
			{
				continue;
			}

			$path = $dir . '/' . $entry;

			is_dir($path) && !is_link($path) ? $this->removeTree($path) : unlink($path);
		}

		rmdir($dir);
	}

	// -----------------------------------------------------------------------------------------------------------
	// resolveContainedFile(): Findings 4 & 5 -- the ONE containment check shared by the read and delete paths.
	// -----------------------------------------------------------------------------------------------------------

	public function testAcceptsAFileDirectlyInsideTheBaseDirectory(): void
	{
		$resolved = ItemSecurity::resolveContainedFile($this->root . '/category', 'legit.zip');

		$this->assertSame(realpath($this->root . '/category/legit.zip'), $resolved);
	}

	public function testAcceptsAFileInANestedSubdirectory(): void
	{
		// The item-filename picker (ItemsModel::getFilesOptions()) recurses into sub-directories,
		// so a legitimate filename value can itself contain sub-directory segments. Containment must
		// allow any DESCENDANT, not only an immediate child.
		$resolved = ItemSecurity::resolveContainedFile($this->root . '/category', 'sub/nested.zip');

		$this->assertSame(realpath($this->root . '/category/sub/nested.zip'), $resolved);
	}

	public function testRejectsATraversalFilenameThatEscapesToARealFileOutsideTheBaseDirectory(): void
	{
		// The critical case: '../outside/secret.zip' resolves, via plain realpath(), to a file that
		// genuinely EXISTS. The containment boundary -- not file existence -- is what must reject it.
		$this->assertNull(ItemSecurity::resolveContainedFile($this->root . '/category', '../outside/secret.zip'));
	}

	public function testRejectsDeepTraversalToAFileOutsideTheBaseDirectory(): void
	{
		$this->assertNull(
			ItemSecurity::resolveContainedFile($this->root . '/category', '../../../../../../../../etc/passwd')
		);
	}

	public function testRejectsASymlinkInsideTheBaseDirectoryThatPointsOutsideIt(): void
	{
		// Path::check() (the string-only primitive item.xml's category `directory` field already uses)
		// would NOT catch this -- it never calls realpath() and so never resolves the symlink. This is
		// exactly the gap resolveContainedFile()'s realpath()-based canonicalisation closes.
		if (!@symlink($this->root . '/outside', $this->root . '/category/escape'))
		{
			$this->markTestSkipped('This filesystem/user cannot create symlinks.');
		}

		$this->assertNull(ItemSecurity::resolveContainedFile($this->root . '/category', 'escape/secret.zip'));
	}

	public function testFailsSafeWhenTheBaseDirectoryDoesNotExist(): void
	{
		$this->assertNull(ItemSecurity::resolveContainedFile($this->root . '/does-not-exist', 'legit.zip'));
	}

	public function testFailsSafeWhenTheTargetFileDoesNotExist(): void
	{
		$this->assertNull(ItemSecurity::resolveContainedFile($this->root . '/category', 'no-such-file.zip'));
	}

	public function testFailsSafeOnAnEmptyFilename(): void
	{
		$this->assertNull(ItemSecurity::resolveContainedFile($this->root . '/category', ''));
	}

	public function testFailsSafeOnAWhitespaceOnlyFilename(): void
	{
		$this->assertNull(ItemSecurity::resolveContainedFile($this->root . '/category', '   '));
	}

	public function testCanResolveToADirectoryInsideTheBase(): void
	{
		// Documents the contract: this method only checks containment, not "is a file". Every one of
		// its three callers additionally applies its own is_file() check on the result, exactly as
		// they did before this helper existed.
		$resolved = ItemSecurity::resolveContainedFile($this->root . '/category', 'sub');

		$this->assertSame(realpath($this->root . '/category/sub'), $resolved);
		$this->assertTrue(is_dir($resolved));
	}

	// -----------------------------------------------------------------------------------------------------------
	// isSyntacticallySafeFilename(): write-time defense in depth in ItemTable::onBeforeCheck().
	// -----------------------------------------------------------------------------------------------------------

	public static function unsafeFilenameProvider(): array
	{
		return [
			'parent traversal'                    => ['../../../../etc/passwd'],
			'traversal buried mid-string'          => ['sub/../../secret.zip'],
			'leading slash (absolute POSIX path)'  => ['/etc/passwd'],
			'leading backslash'                    => ['\\Windows\\win.ini'],
			'Windows drive-letter absolute path'   => ['C:\\Windows\\win.ini'],
			'Windows drive-letter, forward slash'  => ['C:/Windows/win.ini'],
			'php:// stream wrapper'                => ['php://filter/resource=index.php'],
			'phar:// stream wrapper'                => ['phar://evil.phar/payload.php'],
			'data:// stream wrapper'                => ['data://text/plain;base64,SGVsbG8='],
			'null byte'                             => ["legit.zip\0.jpg"],
			'empty string'                          => [''],
			'whitespace only'                       => ['   '],
		];
	}

	#[DataProvider('unsafeFilenameProvider')]
	public function testRejectsUnsafeFilenameShapes(string $filename): void
	{
		$this->assertFalse(ItemSecurity::isSyntacticallySafeFilename($filename));
	}

	public static function safeFilenameProvider(): array
	{
		return [
			'simple filename'          => ['package-1.2.3.zip'],
			'nested subdirectory'      => ['1.2.3/package.zip'],
			'filename with spaces'     => ['My Package 1.0.zip'],
			'dot in the middle only'   => ['my.package.tar.gz'],
			// Regression guard: a '..' SUBSTRING that is not a '..' PATH SEGMENT is not traversal.
			// BleedingedgeModel::scanCategory() builds item.filename from real, on-disk directory
			// listings (a version folder name, a real file name), never admin-typed input, so a
			// coincidental two-dot substring inside one segment is a real name to accept, not a
			// traversal attempt to reject.
			'two-dot substring inside a single segment, not a traversal segment' => ['1.0..1/release..final.zip'],
		];
	}

	#[DataProvider('safeFilenameProvider')]
	public function testAcceptsSafeFilenameShapes(string $filename): void
	{
		$this->assertTrue(ItemSecurity::isSyntacticallySafeFilename($filename));
	}

	// -----------------------------------------------------------------------------------------------------------
	// isSafeUrl(): Findings 1 & 2 -- the ONE SSRF guard shared by the save-time and download-time fetches.
	// -----------------------------------------------------------------------------------------------------------

	public static function unsafeUrlProvider(): array
	{
		return [
			'non-http(s) scheme (file://)'      => ['file:///etc/passwd'],
			'non-http(s) scheme (ftp://)'       => ['ftp://example.com/file.zip'],
			'IPv4 loopback'                      => ['http://127.0.0.1/'],
			'IPv4 link-local / cloud metadata'  => ['http://169.254.169.254/latest/meta-data/'],
			'IPv4 unspecified'                   => ['http://0.0.0.0/'],
			'IPv4 private (10/8)'                => ['http://10.1.2.3/'],
			'IPv4 private (172.16/12)'           => ['https://172.16.5.5/'],
			'IPv4 private (192.168/16)'          => ['https://192.168.1.1/'],
			'IPv4 multicast, low end'             => ['http://224.0.0.1/'],
			'IPv4 multicast, high end'            => ['http://239.255.255.250/'],
			'IPv6 loopback'                       => ['http://[::1]/'],
			'IPv6 unspecified'                    => ['http://[::]/'],
			'IPv6 link-local'                     => ['http://[fe80::1]/'],
			'IPv6 multicast'                      => ['http://[ff02::1]/'],
			'empty string'                        => [''],
			'no scheme'                            => ['example.com/file.zip'],
			'no host'                              => ['http:///path'],
		];
	}

	#[DataProvider('unsafeUrlProvider')]
	public function testRejectsUnsafeUrlsByLiteralIpAlone(string $url): void
	{
		// None of these need a resolver at all -- every host above is already an IP literal (or the
		// URL is malformed before host resolution would even matter).
		$this->assertFalse(ItemSecurity::isSafeUrl($url));
	}

	public function testAcceptsAPublicIpLiteralOverHttps(): void
	{
		// A direct IP literal needs no DNS resolution at all, so this exercises the full accept path
		// without any network dependency.
		$this->assertTrue(ItemSecurity::isSafeUrl('https://8.8.8.8/updates/package.zip'));
	}

	public function testAcceptsAPublicIpLiteralOverPlainHttp(): void
	{
		$this->assertTrue(ItemSecurity::isSafeUrl('http://8.8.8.8/updates/package.zip'));
	}

	/**
	 * Regression test: parse_url() returns a bracketed IPv6 host ('[2001:...]'), which
	 * filter_var(..., FILTER_VALIDATE_IP) rejects outright unless the brackets are stripped first.
	 * Before that fix every IPv6 literal -- safe or not -- fell through to "resolve as a hostname",
	 * found nothing, and was refused for the wrong reason. This is the one test that actually
	 * exercises the IPv6-literal branch instead of merely hitting that same (correct, but
	 * coincidental) fail-safe fallback.
	 */
	public function testAcceptsAPublicIpv6LiteralOverHttps(): void
	{
		$this->assertTrue(ItemSecurity::isSafeUrl('https://[2001:4860:4860::8888]/updates/package.zip'));
	}

	public static function ipv4MappedIpv6Provider(): array
	{
		return [
			'maps to loopback'         => ['http://[::ffff:127.0.0.1]/'],
			'maps to cloud metadata'   => ['http://[::ffff:169.254.169.254]/'],
			'maps to a private range'  => ['http://[::ffff:10.0.0.1]/'],
			// Even a PUBLIC embedded IPv4 address is rejected: PHP's filter_var() classifies the
			// whole ::ffff:0:0/96 range as reserved regardless of what it maps to. Documented here as
			// a (safe-direction) false positive, not a gap -- it fails closed, never open.
			'maps to a public address' => ['http://[::ffff:8.8.8.8]/'],
		];
	}

	#[DataProvider('ipv4MappedIpv6Provider')]
	public function testRejectsIpv4MappedIpv6Literals(string $url): void
	{
		$this->assertFalse(ItemSecurity::isSafeUrl($url));
	}

	public function testAcceptsANormalPublicHttpsUrlWhoseHostnameResolvesToAPublicAddress(): void
	{
		// The hostname resolution step is injected so this stays a unit test with zero real DNS
		// traffic, per isSafeUrl()'s $resolver test seam.
		$resolver = fn(string $host): array => $host === 'downloads.example.com' ? ['93.184.216.34'] : [];

		$this->assertTrue(ItemSecurity::isSafeUrl('https://downloads.example.com/package.zip', $resolver));
	}

	public function testRejectsAHostnameThatResolvesToAPrivateAddress(): void
	{
		// Exactly the "resolve the hostname and reject the resolved IP, not just the string" shape
		// the finding calls for: the HOSTNAME itself looks innocuous, only the IP it resolves to is
		// dangerous.
		$resolver = fn(string $host): array => $host === 'internal.example.com' ? ['10.0.0.5'] : [];

		$this->assertFalse(ItemSecurity::isSafeUrl('http://internal.example.com/package.zip', $resolver));
	}

	public function testRejectsAHostnameWhereOnlyOneOfSeveralResolvedAddressesIsUnsafe(): void
	{
		// Multi-homed / round-robin DNS: EVERY resolved address must be safe, not merely one of them.
		$resolver = fn(string $host): array => ['93.184.216.34', '127.0.0.1'];

		$this->assertFalse(ItemSecurity::isSafeUrl('http://multihomed.example.com/package.zip', $resolver));
	}

	public function testFailsSafeWhenTheHostnameCannotBeResolvedAtAll(): void
	{
		$resolver = fn(string $host): array => [];

		$this->assertFalse(ItemSecurity::isSafeUrl('http://does-not-resolve.example.invalid/package.zip', $resolver));
	}

	// -----------------------------------------------------------------------------------------------------------
	// isSafeUrl(): CGNAT (100.64.0.0/10) and IETF Protocol Assignments (192.0.0.0/24) -- the two ranges
	// FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE alone does NOT reject. 100.100.100.200 is Alibaba
	// Cloud's real, documented metadata endpoint -- the direct analogue of 169.254.169.254 -- deliberately
	// placed in this range specifically to evade exactly this class of blocklist.
	// -----------------------------------------------------------------------------------------------------------

	public static function cgnatAndIetfProtocolAssignmentUrlProvider(): array
	{
		return [
			'CGNAT low end'                          => ['http://100.64.0.0/'],
			'Alibaba Cloud metadata endpoint (CGNAT)' => ['http://100.100.100.200/latest/meta-data/'],
			'CGNAT high end'                          => ['http://100.127.255.255/'],
			'IETF Protocol Assignments low end'       => ['http://192.0.0.0/'],
			'IETF Protocol Assignments, in use'       => ['http://192.0.0.8/'],
			'IETF Protocol Assignments high end'      => ['http://192.0.0.255/'],
		];
	}

	#[DataProvider('cgnatAndIetfProtocolAssignmentUrlProvider')]
	public function testRejectsCgnatAndIetfProtocolAssignmentAddresses(string $url): void
	{
		$this->assertFalse(ItemSecurity::isSafeUrl($url));
	}

	public static function justOutsideCgnatUrlProvider(): array
	{
		return [
			'just below the CGNAT range'  => ['http://100.63.255.255/package.zip'],
			'just above the CGNAT range'  => ['http://100.128.0.0/package.zip'],
		];
	}

	#[DataProvider('justOutsideCgnatUrlProvider')]
	public function testAcceptsPublicAddressesJustOutsideTheCgnatRange(string $url): void
	{
		// The fix must not overreach: only 100.64.0.0/10 itself is CGNAT. An address on either side
		// of it is an ordinary public address and must still be accepted.
		$this->assertTrue(ItemSecurity::isSafeUrl($url));
	}

	// -----------------------------------------------------------------------------------------------------------
	// isSafeUrl(): IPv6 NAT64 (RFC 6052's well-known prefix, 64:ff9b::/96) tunnelling an otherwise-unsafe
	// address. Confirmed empirically that PHP's FILTER_FLAG_GLOBAL_RANGE does not look inside this prefix at
	// all, and that this holds regardless of whether the embedded address is written as dotted-quad or as
	// raw hex groups -- both decode to the identical 16 bytes.
	// -----------------------------------------------------------------------------------------------------------

	public static function nat64TunnelledUnsafeAddressUrlProvider(): array
	{
		return [
			'metadata address, dotted-quad embedding' => ['http://[64:ff9b::169.254.169.254]/latest/meta-data/'],
			'metadata address, raw-hex embedding'     => ['http://[64:ff9b::a9fe:a9fe]/latest/meta-data/'],
			'loopback, raw-hex embedding'             => ['http://[64:ff9b::7f00:1]/'],
			'RFC1918 private range, raw-hex embedding' => ['http://[64:ff9b::a00:1]/'], // 10.0.0.1
		];
	}

	#[DataProvider('nat64TunnelledUnsafeAddressUrlProvider')]
	public function testRejectsNat64AddressesTunnellingAnUnsafeIpv4Address(string $url): void
	{
		$this->assertFalse(ItemSecurity::isSafeUrl($url));
	}

	// -----------------------------------------------------------------------------------------------------------
	// isSafeUrl(): the deprecated IPv4-compatible IPv6 prefix (::/96) tunnelling an otherwise-unsafe address.
	// Confirmed empirically that, exactly like the NAT64 prefix above, FILTER_FLAG_GLOBAL_RANGE does not reject
	// this range at all -- ::a9fe:a9fe validates as an unrestricted address unless explicitly unwrapped and
	// re-checked. This is distinct from the IPv4-MAPPED prefix (::ffff:0:0/96), which FILTER_FLAG_GLOBAL_RANGE
	// already rejects wholesale regardless of what it embeds (see testRejectsAllIpv4MappedAddressesRegardlessOfWhatTheyEmbed
	// below) and therefore needs no unwrap-and-recheck step of its own.
	// -----------------------------------------------------------------------------------------------------------

	public static function ipv4CompatibleTunnelledUnsafeAddressUrlProvider(): array
	{
		return [
			'metadata address, dotted-quad embedding' => ['http://[::169.254.169.254]/latest/meta-data/'],
			'metadata address, raw-hex embedding'     => ['http://[::a9fe:a9fe]/latest/meta-data/'],
			'loopback, raw-hex embedding'             => ['http://[::7f00:1]/'],
			'RFC1918 private range, raw-hex embedding' => ['http://[::a00:1]/'], // 10.0.0.1
		];
	}

	#[DataProvider('ipv4CompatibleTunnelledUnsafeAddressUrlProvider')]
	public function testRejectsIpv4CompatibleAddressesTunnellingAnUnsafeIpv4Address(string $url): void
	{
		$this->assertFalse(ItemSecurity::isSafeUrl($url));
	}

	public function testAcceptsAnIpv4CompatibleAddressEmbeddingAnOrdinaryPublicAddress(): void
	{
		// The fix must not overreach: an IPv4-compatible-encoded PUBLIC address must still be accepted --
		// only the embedded-unsafe-address case is rejected. Raw-hex embedding, not dotted-quad: confirmed
		// empirically that PHP's filter_var() asymmetrically rejects the dotted-quad SYNTAX of this prefix
		// outright regardless of content (e.g. '::8.8.8.8' fails filter_var() even though 8.8.8.8 is public)
		// while passing the byte-identical raw-hex form ('::0808:0808') through unchanged -- a pre-existing
		// PHP quirk this fix neither causes nor can influence, and safe-direction (over-blocks one syntax,
		// under-blocks nothing), so it is not tested here. This test uses the hex-group form specifically
		// because that is the form that actually reaches isTunneledUnsafeAddress()'s unwrap-and-recheck.
		$this->assertTrue(ItemSecurity::isSafeUrl('http://[::0808:0808]/'));
	}

	public function testRejectsAllIpv4MappedAddressesRegardlessOfWhatTheyEmbed(): void
	{
		// Unlike ::/96 and 64:ff9b::/96 above, PHP's FILTER_FLAG_GLOBAL_RANGE rejects the entire
		// ::ffff:0:0/96 (IPv4-mapped) range unconditionally -- confirmed empirically that this holds even
		// for an address that maps a genuinely public IPv4 address, not just an unsafe one. isSafeUrl()
		// deliberately does NOT special-case this prefix in IPV4_EMBEDDING_PREFIXES because doing so would
		// be dead code: the wholesale rejection below already happens before isTunneledUnsafeAddress() is
		// ever reached.
		$this->assertFalse(ItemSecurity::isSafeUrl('http://[::ffff:169.254.169.254]/')); // embeds unsafe
		$this->assertFalse(ItemSecurity::isSafeUrl('http://[::ffff:8.8.8.8]/')); // embeds a public address
	}

	public function testDoesNotAttemptToDecodeTheRfc8215LocalUseNat64PrefixByDesign(): void
	{
		// 64:ff9b:1::/48 is a DELIBERATE, documented residual (see isTunneledUnsafeAddress()'s docblock):
		// unlike the /96 prefixes above, RFC 6052 defines a bit-interleaved embedding for prefixes shorter
		// than /96, which this codebase deliberately does not attempt to decode rather than risk a
		// confidently-wrong implementation. This test pins that documented behaviour so a future change
		// doesn't silently start (mis-)decoding it without a deliberate decision to do so.
		$this->assertTrue(ItemSecurity::isSafeUrl('http://[64:ff9b:1::a9fe:a9fe]/'));
	}

	// -----------------------------------------------------------------------------------------------------------
	// resolveContainedFile(): the secondary robustness gap -- realpath() throws a ValueError (a PHP \Error,
	// NOT an \Exception) on an embedded NUL byte on PHP 8+, which used to escape this method entirely as an
	// uncaught fatal instead of the documented "fails safe, returns NULL".
	// -----------------------------------------------------------------------------------------------------------

	public function testResolveContainedFileFailsSafeRatherThanFatalOnANulByteInTheRelativePath(): void
	{
		$this->assertNull(ItemSecurity::resolveContainedFile($this->root . '/category', "legit.zip\0.jpg"));
	}

	public function testResolveContainedFileFailsSafeRatherThanFatalOnANulByteInTheBaseDirectory(): void
	{
		$this->assertNull(ItemSecurity::resolveContainedFile($this->root . "/category\0evil", 'legit.zip'));
	}

	// -----------------------------------------------------------------------------------------------------------
	// fetchUrlFollowingOnlySafeRedirects(): the ONE fetch mechanism shared by ItemModel::downloadLinkItem()
	// and ItemTable::onBeforeCheck(). Exercised here via ScriptedHttpClient, ItemSecurity's test-only seam for
	// the real Joomla\Http client (not installed in the unit test environment -- see
	// ItemSecurity::setHttpClientFactoryForTesting()'s own docblock).
	// -----------------------------------------------------------------------------------------------------------

	public function testFetchReturnsTheResponseDirectlyWhenThereIsNoRedirectAtAll(): void
	{
		$client = new ScriptedHttpClient([
			new ScriptedHttpResponse(200, 'package bytes', ['Content-Type' => 'application/zip']),
		]);
		ItemSecurity::setHttpClientFactoryForTesting(fn() => $client);

		// An IP literal, deliberately: the redirect-following loop revalidates every hop's target with
		// isSafeUrl(), which performs REAL DNS resolution for a hostname it hasn't been given a resolver
		// for. This suite runs with no network access assumed, so every URL a *redirect hop* in these
		// tests points at must be an address isSafeUrl() can judge without resolving anything.
		$response = ItemSecurity::fetchUrlFollowingOnlySafeRedirects('https://8.8.8.8/package.zip');

		$this->assertSame(200, $response->statusCode);
		$this->assertSame('package bytes', $response->body);
		$this->assertSame(['https://8.8.8.8/package.zip'], $client->requestedUrls);
	}

	/**
	 * The ordinary, legitimate case this fix must not break: a public URL redirecting to ANOTHER public
	 * URL (e.g. GitHub release assets redirecting to a CDN) must still resolve to the final content.
	 */
	public function testFetchFollowsARedirectToAnotherSafePublicAddress(): void
	{
		$client = new ScriptedHttpClient([
			new ScriptedHttpResponse(302, '', ['Location' => 'https://1.1.1.1/package.zip']),
			new ScriptedHttpResponse(200, 'final bytes'),
		]);
		ItemSecurity::setHttpClientFactoryForTesting(fn() => $client);

		$response = ItemSecurity::fetchUrlFollowingOnlySafeRedirects('https://8.8.8.8/download');

		$this->assertSame(200, $response->statusCode);
		$this->assertSame('final bytes', $response->body);
		$this->assertSame(
			['https://8.8.8.8/download', 'https://1.1.1.1/package.zip'],
			$client->requestedUrls
		);
	}

	/**
	 * Gap 1, the main fix this round closes: a URL that is safe at validation time but redirects to an
	 * internal/private address must have that address REJECTED, not silently fetched. Critically, this
	 * asserts the internal target is never even REQUESTED -- proving the redirect is intercepted before
	 * being followed, not merely that its response is discarded afterwards.
	 */
	public function testFetchRejectsARedirectToAPrivateAddressWithoutEverRequestingIt(): void
	{
		$client = new ScriptedHttpClient([
			new ScriptedHttpResponse(302, '', ['Location' => 'http://10.0.0.5/secret']),
			// If this second response were ever consulted, the test below would still pass -- the real
			// assertion is that requestedUrls stays at length 1, i.e. get() is never called a second time.
			new ScriptedHttpResponse(200, 'internal service response'),
		]);
		ItemSecurity::setHttpClientFactoryForTesting(fn() => $client);

		$response = ItemSecurity::fetchUrlFollowingOnlySafeRedirects('https://example.com/download');

		$this->assertSame(0, $response->statusCode, 'An unsafe redirect must yield the synthetic failure response.');
		$this->assertSame('', $response->body);
		$this->assertSame(
			['https://example.com/download'],
			$client->requestedUrls,
			'The private redirect target must never actually be requested.'
		);
	}

	/**
	 * Same shape as the previous test, but with the classic cloud-metadata address.
	 */
	public function testFetchRejectsARedirectToCloudMetadataWithoutEverRequestingIt(): void
	{
		$client = new ScriptedHttpClient([
			new ScriptedHttpResponse(302, '', ['Location' => 'http://169.254.169.254/latest/meta-data/']),
		]);
		ItemSecurity::setHttpClientFactoryForTesting(fn() => $client);

		$response = ItemSecurity::fetchUrlFollowingOnlySafeRedirects('https://example.com/download');

		$this->assertSame(0, $response->statusCode);
		$this->assertSame(['https://example.com/download'], $client->requestedUrls);
	}

	/**
	 * Same shape again, but with a NAT64-tunnelled (hex-encoded) redirect target -- confirming Gap 2's
	 * fix and Gap 1's fix compose correctly: a redirect hop is rejected via the SAME isSafeUrl() call
	 * regardless of which of isSafeUrl()'s own checks is the one that catches it.
	 */
	public function testFetchRejectsARedirectToATunnelledNat64AddressWithoutEverRequestingIt(): void
	{
		$client = new ScriptedHttpClient([
			new ScriptedHttpResponse(302, '', ['Location' => 'http://[64:ff9b::a9fe:a9fe]/latest/meta-data/']),
		]);
		ItemSecurity::setHttpClientFactoryForTesting(fn() => $client);

		$response = ItemSecurity::fetchUrlFollowingOnlySafeRedirects('https://example.com/download');

		$this->assertSame(0, $response->statusCode);
		$this->assertSame(['https://example.com/download'], $client->requestedUrls);
	}

	public function testFetchGivesUpAfterTooManyRedirects(): void
	{
		// Safe target, deliberately: this test is about the hop-count limit, not safety rejection, so
		// every hop must redirect to an address isSafeUrl() accepts without needing DNS.
		$client = new ScriptedHttpClient([
			new ScriptedHttpResponse(302, '', ['Location' => 'https://8.8.8.8/hop']),
		]);
		ItemSecurity::setHttpClientFactoryForTesting(fn() => $client);

		$response = ItemSecurity::fetchUrlFollowingOnlySafeRedirects('https://8.8.8.8/start', 2);

		$this->assertSame(0, $response->statusCode);
		// maxHops=2 means at most 3 requests total (hop 0, 1 and 2), each of which is again a redirect,
		// so the loop exhausts itself rather than looping forever.
		$this->assertCount(3, $client->requestedUrls);
	}

	// -----------------------------------------------------------------------------------------------------------
	// resolveRedirectLocation(): the pure, no-I/O half of fetchUrlFollowingOnlySafeRedirects(), moved here
	// (from UnitTest/Site/Model/ItemModelLinkSecurityTest.php, which used to reflect into
	// ItemModel::resolveRedirectLocation()) now that the shared fetch mechanism, and this private helper of
	// it, live in ItemSecurity instead.
	// -----------------------------------------------------------------------------------------------------------

	public function testResolveRedirectLocationReturnsAnAbsoluteLocationAsIs(): void
	{
		$resolved = $this->invokePrivateStatic('resolveRedirectLocation', [
			'https://example.com/download',
			'https://cdn.example.net/package.zip',
		]);

		$this->assertSame('https://cdn.example.net/package.zip', $resolved);
	}

	public function testResolveRedirectLocationResolvesARootRelativeLocationAgainstTheBaseUrlsSchemeAndHost(): void
	{
		$resolved = $this->invokePrivateStatic('resolveRedirectLocation', [
			'https://example.com/download?id=1',
			'/packages/package.zip',
		]);

		$this->assertSame('https://example.com/packages/package.zip', $resolved);
	}

	public function testResolveRedirectLocationKeepsTheBaseUrlsNonDefaultPort(): void
	{
		$resolved = $this->invokePrivateStatic('resolveRedirectLocation', [
			'https://example.com:8443/download',
			'/packages/package.zip',
		]);

		$this->assertSame('https://example.com:8443/packages/package.zip', $resolved);
	}

	public static function unsupportedRedirectLocationProvider(): array
	{
		return [
			'document-relative (no leading slash)' => ['https://example.com/a/b', 'package.zip'],
			'protocol-relative'                     => ['https://example.com/a', '//cdn.example.net/package.zip'],
			'empty string'                          => ['https://example.com/a', ''],
		];
	}

	#[DataProvider('unsupportedRedirectLocationProvider')]
	public function testResolveRedirectLocationRefusesUnsupportedShapesRatherThanGuessingAtThem(string $baseUrl, string $location): void
	{
		$this->assertNull($this->invokePrivateStatic('resolveRedirectLocation', [$baseUrl, $location]));
	}

	public function testResolveRedirectLocationRefusesARootRelativeLocationWhenTheBaseUrlHasNoHost(): void
	{
		$this->assertNull($this->invokePrivateStatic('resolveRedirectLocation', ['not-a-url', '/package.zip']));
	}

	// -----------------------------------------------------------------------------------------------
	// isSafeRedirectTarget(): stored XSS via redirect_unauth / no_access_url (Category/Release/Item/
	// Autodescription frontend templates, and the {arslatest} content-plugin tag's own sibling fix).
	// -----------------------------------------------------------------------------------------------

	public static function unsafeRedirectTargetProvider(): array
	{
		return [
			'javascript: scheme'                => ['javascript:alert(document.cookie)'],
			'javascript: scheme, mixed case'    => ['JavaScript:alert(1)'],
			'data: scheme'                      => ['data:text/html,<script>alert(1)</script>'],
			'vbscript: scheme'                  => ['vbscript:msgbox(1)'],
			'protocol-relative URL'             => ['//evil.example/phish'],
			'empty string'                      => [''],
			'whitespace only'                   => ['   '],
			'tab inside the scheme'             => ["java\tscript:alert(1)"],
			'newline inside the scheme'         => ["java\nscript:alert(1)"],
			'leading C0 control character'      => ["\x01javascript:alert(1)"],
		];
	}

	#[DataProvider('unsafeRedirectTargetProvider')]
	public function testIsSafeRedirectTargetRejectsDangerousValues(string $url): void
	{
		$this->assertFalse(ItemSecurity::isSafeRedirectTarget($url));
	}

	public static function safeRedirectTargetProvider(): array
	{
		return [
			'plain relative Joomla route' => ['index.php?option=com_ars&view=items&release_id=5'],
			'bare relative path'          => ['some/page'],
			'http absolute URL'           => ['http://example.com/path'],
			'https absolute URL'          => ['https://example.com/path'],
			'https URL with a query string containing a colon' => ['https://example.com/path?a=b:c'],
		];
	}

	#[DataProvider('safeRedirectTargetProvider')]
	public function testIsSafeRedirectTargetAcceptsOrdinaryValues(string $url): void
	{
		$this->assertTrue(ItemSecurity::isSafeRedirectTarget($url));
	}
}
