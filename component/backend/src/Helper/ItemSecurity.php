<?php
/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\ARS\Administrator\Helper;

defined('_JEXEC') || die;

use Joomla\Http\HttpFactory;

/**
 * Shared security primitives for the two attacker-influenced Item fields (`item.xml`'s `filename`
 * and `url`) that reach filesystem and network sinks.
 *
 * Both `filename` and `url` are set by any backend/API user holding `core.create`/`core.edit` on
 * the item's category, and `item.xml` applies no server-enforced validation to either of them --
 * `filename`'s `<option>` list is a UI convenience only, populated from a directory scan, never
 * re-checked server-side; `url`'s only Joomla-native validation is `validate="url"`, which accepts
 * any syntactically well-formed URL, `http://169.254.169.254/` included.
 *
 * `CategoryTable::onBeforeCheck()` already has the right IDEA for the `directory` field -- canonicalise,
 * then verify containment against a known root -- via `validate="filePath"` + `Joomla\Filesystem\Path::check()`.
 * That method is deliberately NOT reused here: `Path::check()` is a purely string-based check (it
 * rejects a literal `..` substring and compares a slash-normalised prefix) with no `realpath()` call, so
 * it does not resolve symlinks. That is an acceptable trade-off for a category `directory`, which an
 * administrator sets once, at category-creation time. `filename`, by contrast, is read (and, via the
 * JSON:API, deleted) on every request by a potentially lower-privileged per-category editor, so this
 * class canonicalises with `realpath()` instead, closing the symlink-escape gap `Path::check()` leaves
 * open, while keeping the exact same underlying idea: canonicalise, then verify containment against a
 * known root.
 *
 * @since  __DEPLOY_VERSION__
 */
final class ItemSecurity
{
	/**
	 * Every known /96 IPv6 prefix whose remaining 4 bytes are a literal, directly-embedded IPv4
	 * address (as opposed to RFC 6052's bit-interleaved encoding for prefixes shorter than /96, which
	 * {@see isTunneledUnsafeAddress()} deliberately does not attempt to decode -- see that method's
	 * docblock), AND whose whole range is not already rejected by `FILTER_FLAG_GLOBAL_RANGE` before an
	 * embedded address is ever examined. Confirmed empirically (PHP 8.3-8.5) so this list stays exactly
	 * as long as it needs to be, not "every /96 prefix that exists":
	 *   - RFC 6052 NAT64 Well-Known Prefix, `64:ff9b::/96` -- PASSES `filter_var()`/GLOBAL_RANGE
	 *     unchanged, so it needs this unwrap-and-recheck. A documented SSRF-cheat-sheet entry.
	 *   - The deprecated IPv4-compatible prefix, `::/96` (RFC 4291) -- ALSO passes `filter_var()`/
	 *     GLOBAL_RANGE unchanged (confirmed: `::a9fe:a9fe` validates as an unrestricted address), so it
	 *     needs the same unwrap-and-recheck.
	 *
	 * Deliberately NOT listed: the IPv4-mapped prefix, `::ffff:0:0/96` (e.g. `::ffff:169.254.169.254`).
	 * Confirmed empirically that `FILTER_FLAG_GLOBAL_RANGE` already rejects this ENTIRE range
	 * unconditionally, before any embedded address is examined -- `::ffff:8.8.8.8` (a real public
	 * address) fails `filter_var()` exactly the same way `::ffff:169.254.169.254` does. Adding this
	 * prefix here would be dead code: `isTunneledUnsafeAddress()` is only ever reached for an `$ip`
	 * that already passed the `filter_var()` gate in {@see isUnsafeIpAddress()}, so this prefix can
	 * never reach this method in the first place.
	 *
	 * @since __DEPLOY_VERSION__
	 */
	private const IPV4_EMBEDDING_PREFIXES = [
		"\x00\x64\xff\x9b\x00\x00\x00\x00\x00\x00\x00\x00", // 64:ff9b::/96 (NAT64 Well-Known Prefix)
		"\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00", // ::/96 (deprecated IPv4-compatible)
	];

	/**
	 * Resolves `$relativePath` against `$baseDirectory` and returns the result ONLY if it is
	 * actually contained within `$baseDirectory` once both are canonicalised with `realpath()`.
	 *
	 * This is the ONE containment check reused verbatim by every place `item.filename` reaches a
	 * filesystem read or delete sink -- `ItemModel::preDownloadCheck()`, `ItemModel::downloadFileItem()`
	 * (frontend read) and `ItemsController::getFileNameToDelete()` (JSON:API delete) -- so the
	 * definition of "safe" cannot drift between the read and the delete path. `ItemTable::onBeforeCheck()`
	 * also applies it before the incidental file-hashing read it performs on save, closing the same
	 * class of bug at a fourth, related call site.
	 *
	 * Containment allows any DESCENDANT of `$baseDirectory`, not only an immediate child: the item
	 * filename picker (`ItemsModel::getFilesOptions()`) recurses into sub-directories of the category
	 * folder, so a legitimate `filename` value can itself contain sub-directory segments (e.g.
	 * `1.2.3/package.zip`). What must never be allowed is ESCAPING `$baseDirectory` altogether.
	 *
	 * Fails safe: a missing/unreadable `$baseDirectory`, an empty `$relativePath`, a missing/
	 * unreadable resolved target, or a resolved target outside `$baseDirectory` (via `..`, an
	 * absolute path, a symlink pointing outside it, etc.) all return NULL. This method does not
	 * distinguish files from directories -- callers that need only a file should still confirm that
	 * with their own `is_file()` check on the returned path, exactly as they did before this check
	 * existed.
	 *
	 * @param   string  $baseDirectory  The category's own directory. May be relative (the three
	 *                                   existing call sites already resolve it against JPATH_ROOT
	 *                                   before calling this) or absolute.
	 * @param   string  $relativePath   The untrusted, attacker-influenced `item.filename` value.
	 *
	 * @return  string|null  The canonical absolute path, or NULL if it is missing or escapes
	 *                        `$baseDirectory`.
	 * @since   __DEPLOY_VERSION__
	 */
	public static function resolveContainedFile(string $baseDirectory, string $relativePath): ?string
	{
		$relativePath = trim($relativePath);

		if ($relativePath === '')
		{
			return null;
		}

		// realpath() throws a ValueError (a PHP \Error, NOT an \Exception) on PHP 8+ when its argument
		// contains an embedded NUL byte -- @ suppresses warnings/notices, but never a thrown Error, so
		// without this try/catch(\Throwable) that specific shape would escape every caller's own
		// catch (\Exception $e) block as an uncaught fatal instead of the documented "fails safe,
		// returns NULL". A NUL byte can never reach here from a freshly-saved item (the write-time
		// isSyntacticallySafeFilename() screen already rejects it), but an item persisted before that
		// screen existed, or a filename mutated directly in the database, still could.
		try
		{
			$realBase = @realpath($baseDirectory);
		}
		catch (\Throwable $e)
		{
			return null;
		}

		if ($realBase === false)
		{
			return null;
		}

		try
		{
			$realTarget = @realpath(rtrim($realBase, '/\\') . '/' . $relativePath);
		}
		catch (\Throwable $e)
		{
			return null;
		}

		if ($realTarget === false)
		{
			return null;
		}

		$realBaseWithSeparator = rtrim($realBase, '/\\') . DIRECTORY_SEPARATOR;

		if (!str_starts_with($realTarget, $realBaseWithSeparator))
		{
			return null;
		}

		return $realTarget;
	}

	/**
	 * Cheap, string-only defense-in-depth check on `item.filename` at WRITE time
	 * ({@see \Akeeba\Component\ARS\Administrator\Table\ItemTable::onBeforeCheck()}), rejecting the
	 * classic traversal and stream-wrapper shapes before a category directory is even known.
	 *
	 * This is NOT a replacement for {@see resolveContainedFile()}, which remains the authoritative
	 * containment check applied once a real, on-disk category directory is available -- a filename
	 * that passes this check can still fail that one (e.g. it points at a file that does not exist).
	 * This exists only to reject the cheapest, most obviously malicious inputs as early as possible,
	 * and to give a clear validation error at save time rather than a generic 404 at download time.
	 *
	 * @param   string  $filename
	 *
	 * @return  bool
	 * @since   __DEPLOY_VERSION__
	 */
	public static function isSyntacticallySafeFilename(string $filename): bool
	{
		$filename = trim($filename);

		if ($filename === '' || str_contains($filename, "\0"))
		{
			return false;
		}

		// Parent-directory traversal: a '..' PATH SEGMENT anywhere ('..', '../...', '.../..' or
		// '.../../...'). Deliberately segment-aware, not a bare str_contains($filename, '..') --
		// item.filename is populated from real, machine-scanned filenames (BleedingedgeModel::scanCategory()
		// creates Items from a real directory listing, not admin-typed input), where a two-dot
		// substring can legitimately occur inside a single segment that is NOT a traversal attempt
		// at all (a version folder literally named '1.0..1', a file named 'release..final.zip', ...).
		// Rejecting every '..' substring would silently stop Bleeding Edge from creating an Item for
		// a real file merely because its name happens to contain two consecutive dots.
		foreach (preg_split('#[/\\\\]#', $filename) as $segment)
		{
			if ($segment === '..')
			{
				return false;
			}
		}

		// Absolute POSIX (or UNC-style) path.
		if ($filename[0] === '/' || $filename[0] === '\\')
		{
			return false;
		}

		// Absolute Windows path with a drive letter, e.g. "C:\..." or "C:/...".
		if (preg_match('#^[A-Za-z]:[/\\\\]#', $filename))
		{
			return false;
		}

		// Any stream-wrapper prefix -- php://, phar://, zip://, data://, glob://, expect://, ...
		if (preg_match('#^[A-Za-z][A-Za-z0-9+.\-]*://#', $filename))
		{
			return false;
		}

		return true;
	}

	/**
	 * True IFF `$url` is safe for ARS to fetch server-side: `http`/`https` only, with EVERY IP
	 * address it (or its hostname, once resolved) refers to being a public, routable address --
	 * none in a loopback, private, link-local, unspecified or multicast range, IPv4 or IPv6 alike.
	 *
	 * This is the ONE check shared, unmodified, by both SSRF-affected sinks:
	 * `ItemTable::onBeforeCheck()` (blind SSRF: every save of a link item, or a file item whose
	 * hashes are being (re)computed, fetches `item.url`) and `ItemModel::downloadLinkItem()`
	 * (non-blind SSRF: `url_dl=proxy` fetches `item.url` and streams the full response back to an
	 * anonymous visitor). A URL judged safe at save time and the same URL judged safe immediately
	 * before being fetched for a download can never disagree, because both call this.
	 *
	 * Both of those sinks ALSO both fetch through {@see fetchUrlFollowingOnlySafeRedirects()}, the
	 * ONE fetch mechanism shared between them, which re-validates every redirect hop against this
	 * SAME function before following it. That is a deliberate departure from `ItemTable::onBeforeCheck()`'s
	 * previous implementation, which called Joomla core's `InstallerHelper::downloadPackage()` --
	 * confirmed (by reading `InstallerHelper::downloadPackage()` and the `Joomla\Http\Transport\Curl`
	 * transport it uses) to follow EVERY HTTP redirect status (301/302/303/307/308) transparently
	 * inside a single `curl_exec()` call, via `CURLOPT_FOLLOWLOCATION`, with no hook for calling code
	 * to see, let alone re-validate, any hop. A URL safe at validation time but redirecting to an
	 * internal one was therefore not covered there, even though `isSafeUrl()` itself was already
	 * correct -- the gap was entirely in what happened AFTER validation, invisible to this function.
	 *
	 * What is NOT closed by either caller, because it is inherent to validating a hostname's IP up
	 * front at all: a DNS answer that changes between this function's own lookup and the transport's
	 * later, independent lookup for the exact same hostname (DNS rebinding). That window exists once
	 * PER HOP of `fetchUrlFollowingOnlySafeRedirects()`'s redirect loop, in both callers alike.
	 *
	 * @param   string         $url       The URL to validate.
	 * @param   callable|null  $resolver  Test seam: `fn(string $host): string[]` resolving a hostname
	 *                                     to zero or more IP address strings. Production callers
	 *                                     always omit this and get {@see resolveHostToIps()}.
	 *
	 * @return  bool
	 * @since   __DEPLOY_VERSION__
	 */
	public static function isSafeUrl(string $url, ?callable $resolver = null): bool
	{
		$url = trim($url);

		if ($url === '')
		{
			return false;
		}

		$parts = parse_url($url);

		if ($parts === false || empty($parts['host']) || empty($parts['scheme']))
		{
			return false;
		}

		if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true))
		{
			return false;
		}

		// parse_url() returns an IPv6 host WITH its brackets, e.g. '[::1]' for 'http://[::1]/' --
		// filter_var(..., FILTER_VALIDATE_IP) rejects that string outright, so every IPv6 literal
		// would otherwise silently fall through to the "resolve as a hostname" branch below, resolve
		// to nothing, and get refused for the wrong reason (unresolvable host, not "is unsafe").
		// Stripping brackets is safe unconditionally: '[' and ']' never appear in a bare hostname or
		// IPv4 literal, only around an IPv6 one.
		$host = trim($parts['host'], '[]');

		if (filter_var($host, FILTER_VALIDATE_IP) !== false)
		{
			$ips = [$host];
		}
		else
		{
			$resolver ??= [self::class, 'resolveHostToIps'];
			$ips        = $resolver($host) ?: [];
		}

		// Could not resolve the host to anything at all -- fail safe, refuse it, rather than let an
		// unresolvable host fall through as "not proven unsafe".
		if (empty($ips))
		{
			return false;
		}

		foreach ($ips as $ip)
		{
			if (self::isUnsafeIpAddress($ip))
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Test seam ONLY. When non-null, {@see fetchUrlFollowingOnlySafeRedirects()} calls this instead of
	 * constructing a real `Joomla\Http\HttpFactory` client -- see {@see setHttpClientFactoryForTesting()}.
	 *
	 * @var callable|null
	 * @since __DEPLOY_VERSION__
	 */
	private static $httpClientFactoryForTesting = null;

	/**
	 * FOR TESTS ONLY. Overrides the HTTP client {@see fetchUrlFollowingOnlySafeRedirects()} uses, so a
	 * test can exercise the REAL redirect-following/re-validation loop -- including through a caller
	 * that has no parameter of its own to inject one, such as `ItemTable::onBeforeCheck()` -- without
	 * ever loading `Joomla\Http\HttpFactory` or touching a real network. The unit suite boots with
	 * zero Composer dependencies by design (see `UnitTest/bootstrap.php`'s own docblock) and
	 * `Joomla\Http\*` is deliberately NOT among `joomla-stubs.php`'s stubs, so any test that reached the
	 * real client at all would fail with "Class ... not found" before this even mattered.
	 *
	 * `$factory` is a `fn(): object` returning anything with a `get(string $url): object` method whose
	 * return value itself exposes `getStatusCode()`, `getHeaderLine(string $name)`, `getBody()` and
	 * `getHeaders()` -- the same shape `Joomla\Http\Response` exposes.
	 *
	 * This is deliberately global, mutable state, unlike every other test seam in this class (compare
	 * {@see isSafeUrl()}'s `$resolver` parameter) -- the ONE place that could not be a parameter instead,
	 * because `ItemTable::onBeforeCheck()` (a Table lifecycle hook with a fixed signature) has nowhere to
	 * receive one. A test that calls this MUST reset it to `null` in its own `tearDown()`, or it will
	 * silently affect every later test that happens to reach this method.
	 *
	 * @param   callable|null  $factory
	 *
	 * @return  void
	 * @since   __DEPLOY_VERSION__
	 */
	public static function setHttpClientFactoryForTesting(?callable $factory): void
	{
		self::$httpClientFactoryForTesting = $factory;
	}

	/**
	 * Fetches `$url`, following HTTP redirects manually so EVERY hop is re-validated with
	 * {@see isSafeUrl()} before it is followed.
	 *
	 * The ONE fetch mechanism shared by both SSRF-affected sinks -- `ItemModel::downloadLinkItem()`
	 * (the `url_dl=proxy` download path) and `ItemTable::onBeforeCheck()` (the save-time
	 * checksum-computation fetch, which used to call Joomla core's `InstallerHelper::downloadPackage()`
	 * instead; see {@see isSafeUrl()}'s docblock for why that was insufficient). A bare
	 * `'follow_location' => 1` transport option (what this method replaces at both call sites) has
	 * curl/the PHP stream wrapper follow a redirect entirely internally, with no chance for ARS to
	 * see, let alone reject, the address it actually lands on -- so a caller's pre-fetch validation of
	 * `item.url` could be satisfied by a URL that immediately redirects to
	 * `http://169.254.169.254/latest/meta-data/`, and the internal response would still be fetched (and,
	 * for `ItemModel`'s non-blind sink, streamed back to the requesting guest).
	 *
	 * Living in this ONE place, rather than as two private copies in `ItemTable` and `ItemModel`, is
	 * deliberate: those two classes already had `isSafeUrl()` itself drift apart from its
	 * redirect-revalidation almost the way this method's own predecessor did last round (see Finding 1
	 * vs. Finding 2 in the security-audit history) -- a second hand-written copy of this loop is exactly
	 * the kind of duplication that class of bug grows back from.
	 *
	 * `$url` itself is assumed already validated by the caller (both callers do this immediately before
	 * calling here); every hop AFTER the first is validated here.
	 *
	 * @param   string  $url      The already-validated URL to fetch.
	 * @param   int     $maxHops  Maximum number of redirects to follow before giving up.
	 *
	 * @return  object  {body: string, statusCode: int, headers: array}. A too-deep, unresolvable, or
	 *                  unsafe redirect chain yields a synthetic non-200 response, exactly like a real
	 *                  failed fetch, so a caller that already treats any non-200 `statusCode` as
	 *                  "unavailable" needs no special-casing for this outcome.
	 * @since   __DEPLOY_VERSION__
	 */
	public static function fetchUrlFollowingOnlySafeRedirects(string $url, int $maxHops = 5): object
	{
		// We cannot serialise a PSR-7 Response object, hence the (object) conversion below.
		$http = self::$httpClientFactoryForTesting !== null
			? (self::$httpClientFactoryForTesting)()
			: (new HttpFactory())->getHttp(['follow_location' => false], ['curl', 'stream']);

		for ($hop = 0; $hop <= $maxHops; $hop++)
		{
			$response = $http->get($url);
			$status   = $response->getStatusCode();

			if (!in_array($status, [301, 302, 303, 307, 308], true))
			{
				return (object) [
					'body'       => (string) $response->getBody() ?? '',
					'statusCode' => $status,
					'headers'    => $response->getHeaders() ?? [],
				];
			}

			$location = trim($response->getHeaderLine('Location'));
			$next     = $location === '' ? null : self::resolveRedirectLocation($url, $location);

			if ($next === null || !self::isSafeUrl($next))
			{
				break;
			}

			$url = $next;
		}

		return (object) ['body' => '', 'statusCode' => 0, 'headers' => []];
	}

	/**
	 * Resolves a `Location` header value against the URL that produced it. Only absolute
	 * (`http(s)://...`) and root-relative (`/...`) forms are supported -- deliberately: real-world
	 * redirect chains (GitHub release assets to a CDN, for instance) are always one of these two
	 * shapes, and refusing anything else (document-relative `foo/bar`, protocol-relative `//host/...`,
	 * ...) is a strictly safer default than guessing at a resolution scheme nothing here needs.
	 *
	 * @param   string  $baseUrl   The URL the `Location` header came from.
	 * @param   string  $location  The raw `Location` header value.
	 *
	 * @return  string|null  The resolved absolute URL, or NULL if it cannot be resolved this way.
	 * @since   __DEPLOY_VERSION__
	 */
	private static function resolveRedirectLocation(string $baseUrl, string $location): ?string
	{
		if (preg_match('#^https?://#i', $location))
		{
			return $location;
		}

		// Root-relative ('/path'), but NOT protocol-relative ('//host/path' -- a leading '//' is a
		// different host entirely, not a path on the current one, and must be refused rather than
		// mangled into 'scheme://currenthost//host/path' as a naive "starts with /" check would.
		$isRootRelative = isset($location[0]) && $location[0] === '/' && ($location[1] ?? '') !== '/';

		if (!$isRootRelative)
		{
			return null;
		}

		$parts = parse_url($baseUrl);

		if (empty($parts['scheme']) || empty($parts['host']))
		{
			return null;
		}

		$port = isset($parts['port']) ? ':' . $parts['port'] : '';

		return $parts['scheme'] . '://' . $parts['host'] . $port . $location;
	}

	/**
	 * True IFF `$ip` (already known to be a syntactically valid IPv4 or IPv6 address) is in ANY range
	 * {@see isSafeUrl()} promises to reject.
	 *
	 * @param   string  $ip
	 *
	 * @return  bool
	 * @since   __DEPLOY_VERSION__
	 */
	private static function isUnsafeIpAddress(string $ip): bool
	{
		// FILTER_FLAG_GLOBAL_RANGE, on top of the pre-existing NO_PRIV_RANGE | NO_RES_RANGE, is what
		// closes 100.64.0.0/10 (RFC 6598 Shared Address Space / CGNAT -- includes 100.100.100.200,
		// Alibaba Cloud's real metadata endpoint, the direct analogue of 169.254.169.254) and
		// 192.0.0.0/24 (IETF Protocol Assignments). Confirmed empirically (PHP 8.5) that
		// NO_PRIV_RANGE | NO_RES_RANGE alone passes both of those, and adding GLOBAL_RANGE rejects
		// both while continuing to accept ordinary public addresses (8.8.8.8, 2001:4860:4860::8888).
		// As a side effect, GLOBAL_RANGE also rejects the ENTIRE 2002::/16 6to4 range unconditionally,
		// regardless of what IPv4 address is embedded in it -- verified empirically, no separate
		// handling needed for that one.
		if (filter_var(
				$ip,
				FILTER_VALIDATE_IP,
				FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE
			) === false)
		{
			return true;
		}

		// Confirmed empirically (PHP 8.4): FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		// does NOT reject multicast -- 224.0.0.1 and ff02::1 both pass filter_var() with those
		// flags. Multicast is explicitly one of the ranges this method promises to reject, so it
		// needs its own check on top of the filter_var() flags.
		if (self::isMulticastAddress($ip))
		{
			return true;
		}

		// Unlike 6to4, NAT64 (RFC 6052's well-known prefix, 64:ff9b::/96) is NOT rejected wholesale by
		// GLOBAL_RANGE -- confirmed empirically that 64:ff9b::1 and 64:ff9b::a9fe:a9fe (169.254.169.254
		// hex-encoded) both still PASS every filter_var() flag combination above. A NAT64 address is
		// just an IPv6 wrapper around a real IPv4 target, so the only correct check is to unwrap it and
		// re-apply this SAME check to the embedded IPv4 address, exactly as if the caller had used that
		// address directly.
		if (self::isTunneledUnsafeAddress($ip))
		{
			return true;
		}

		return false;
	}

	/**
	 * True IFF `$ip` is an IPv6 address using one of {@see IPV4_EMBEDDING_PREFIXES} whose embedded
	 * IPv4 address is itself unsafe per {@see isUnsafeIpAddress()}.
	 *
	 * This exists because each of those prefixes carries a real IPv4 address in its low 32 bits, and
	 * PHP's `filter_var()` range flags do not look inside either of them -- confirmed empirically that
	 * `64:ff9b::a9fe:a9fe` and `::a9fe:a9fe` (169.254.169.254, embedded two different ways) both pass
	 * FILTER_FLAG_GLOBAL_RANGE unchanged. Checking on the 16-byte binary form (via `inet_pton()`)
	 * rather than the input string means it makes no difference whether the attacker writes the
	 * embedded address as dotted-quad (`64:ff9b::10.0.0.1`) or as raw hex groups (`64:ff9b::a00:1`) --
	 * both decode to the identical bytes and are treated identically.
	 *
	 * Known residual, deliberately NOT covered here (confirmed empirically, not fixed): RFC 8215's
	 * Local-Use NAT64 prefix, `64:ff9b:1::/48`. Unlike the two /96 prefixes above, where the embedded
	 * IPv4 address is simply the last 4 bytes, RFC 6052 section 2.2 defines a bit-INTERLEAVED encoding
	 * for every prefix shorter than /96 (a reserved "u" octet is spliced in among the address bits, at
	 * a different byte offset for each of the /32, /40, /48, /56, /64 prefix lengths) -- decoding it
	 * correctly is materially more involved than a `substr()` comparison, and getting the bit offsets
	 * wrong would be worse than not attempting it (a confidently-wrong check is harder to notice than
	 * an absent one). This prefix is also a specific, enterprise-only NAT64 deployment choice, not a
	 * standard SSRF-cheat-sheet entry like the three above, and empirical routing tests (both macOS and
	 * a Linux container with verified-working IPv6) found it -- like the other rare transition
	 * mechanisms -- unroutable on both representative deployment targets. GLOBAL_RANGE does, by
	 * contrast, already reject Teredo (`2001::/32`) and 6to4 (`2002::/16`) wholesale, regardless of
	 * what they encode, since those are rejected as whole ranges rather than needing decode-and-recurse.
	 *
	 * @param   string  $ip
	 *
	 * @return  bool
	 * @since   __DEPLOY_VERSION__
	 */
	private static function isTunneledUnsafeAddress(string $ip): bool
	{
		$binary = @inet_pton($ip);

		// Not a (16-byte) IPv6 address at all -- nothing to unwrap.
		if ($binary === false || strlen($binary) !== 16)
		{
			return false;
		}

		$prefix = substr($binary, 0, 12);

		if (!in_array($prefix, self::IPV4_EMBEDDING_PREFIXES, true))
		{
			return false;
		}

		$embeddedIp = @inet_ntop(substr($binary, 12, 4));

		return $embeddedIp !== false && self::isUnsafeIpAddress($embeddedIp);
	}

	/**
	 * True IFF `$ip` (already known to be a syntactically valid IPv4 or IPv6 address) is in a
	 * multicast range: `224.0.0.0/4` for IPv4, `ff00::/8` for IPv6. See {@see isSafeUrl()}'s
	 * docblock for why this exists alongside, not instead of, `filter_var()`'s own range flags.
	 *
	 * @param   string  $ip
	 *
	 * @return  bool
	 * @since   __DEPLOY_VERSION__
	 */
	private static function isMulticastAddress(string $ip): bool
	{
		$binary = @inet_pton($ip);

		if ($binary === false)
		{
			// Should not happen -- the caller already validated $ip with filter_var(). Fail safe.
			return true;
		}

		$firstByte = ord($binary[0]);

		// 4 raw bytes = IPv4 (224-239 = 224.0.0.0/4); 16 raw bytes = IPv6 (0xff = ff00::/8).
		return strlen($binary) === 4
			? ($firstByte >= 224 && $firstByte <= 239)
			: ($firstByte === 0xFF);
	}

	/**
	 * Default hostname resolver for {@see isSafeUrl()}: every IPv4 and IPv6 address DNS returns
	 * for `$host`.
	 *
	 * @param   string  $host
	 *
	 * @return  string[]
	 * @since   __DEPLOY_VERSION__
	 */
	public static function resolveHostToIps(string $host): array
	{
		$ips = [];

		$ipv4 = @gethostbyname($host);

		// gethostbyname() returns the input unchanged on failure -- only trust an answer that both
		// differs from $host and is actually a valid IPv4 address.
		if ($ipv4 !== false && $ipv4 !== $host && filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
		{
			$ips[] = $ipv4;
		}

		if (function_exists('dns_get_record'))
		{
			$records = @dns_get_record($host, DNS_A + DNS_AAAA) ?: [];

			foreach ($records as $record)
			{
				$ip = $record['ip'] ?? $record['ipv6'] ?? null;

				if (!empty($ip))
				{
					$ips[] = $ip;
				}
			}
		}

		return array_values(array_unique($ips));
	}
}
