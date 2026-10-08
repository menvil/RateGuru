<?php

use App\Actions\Settings\UpdateProjectLocaleSettingsAction;
use App\Actions\Translations\ReadProjectTranslationGenerationAction;
use App\Actions\Translations\StartProjectTranslationGenerationAction;
use App\Enums\MediaResizeMode;
use App\Enums\MediaVariantName;
use App\Exceptions\Translations\CannotGenerateTranslationsException;
use App\Jobs\Translations\GenerateProjectTranslationChunkJob;
use App\Models\Category;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Models\Post;
use App\Models\ProjectSettings;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\User;
use App\Services\Media\MediaVariantSpecification;
use App\Services\Media\NormalizedImage;
use App\Support\Import\Dns\HostResolver;
use App\Support\Import\ImportFetchPolicy;
use App\Support\Import\ImportHttpTransport;
use App\Support\Import\ImportTransportResponse;
use App\Support\Import\ResolvedImportTarget;
use App\Support\Locale\LocaleManager;
use App\Support\Settings\PresetSettingsBuilder;
use App\Support\Settings\ProjectSettingsManager;
use App\Support\TranslationEngine\Contracts\TranslationProvider;
use App\Support\TranslationEngine\Data\TranslationBatchRequest;
use App\Support\TranslationEngine\Data\TranslationItem;
use App\Support\TranslationEngine\Data\TranslationProviderCall;
use App\Support\TranslationEngine\Data\TranslationProviderLimits;
use App\Support\TranslationEngine\Data\TranslationProviderResponse;
use App\Support\TranslationEngine\Enums\TranslationDataClassification;
use App\Support\TranslationEngine\Enums\TranslationErrorCode;
use App\Support\TranslationEngine\Exceptions\TranslationProviderException;
use App\Support\Translations\TranslationCatalogInspector;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Nightwatch\Events\IngestingEvents as NightwatchIngestingEvents;
use Laravel\Socialite\Two\User as SocialiteUser;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\AssertionFailedError;
use Sentry\ClientBuilder as SentryClientBuilder;
use Sentry\Event as SentryEvent;
use Sentry\EventType as SentryEventType;
use Sentry\Laravel\Integration as SentryLaravelIntegration;
use Sentry\State\HubInterface as SentryHubInterface;
use Sentry\Transport\Result as SentryTransportResult;
use Sentry\Transport\ResultStatus as SentryResultStatus;
use Sentry\Transport\TransportInterface as SentryTransportInterface;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Browser');

// Feature tests assert on the HTML the server renders, never on the compiled
// CSS and JavaScript it links to, so they render the page without Vite: no
// manifest has to be built first, and their CI jobs need not wait for one. The
// Browser suite is the one that loads the built assets in a real browser, and
// it still renders through Vite.
pest()->beforeEach(function (): void {
    $this->withoutVite();
})->in('Feature');

// A Browser test fails on any JavaScript error any page it opened raised,
// whether or not it asserts on that page: see watchBrowserJavaScriptErrors().
// The server the browser talks to is kept from corrupting a reused connection:
// see keepBodilessResponsesOffKeepAliveConnections().
pest()->beforeEach(function (): void {
    keepBodilessResponsesOffKeepAliveConnections($this->app);
    $this->browserJavaScriptErrors = watchBrowserJavaScriptErrors($this->app);
})->afterEach(function (): void {
    assertNoBrowserJavaScriptErrors($this->browserJavaScriptErrors);
})->in('Browser');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * A HostResolver test double that never touches real DNS — shared by every
 * Import test file that needs UrlImportValidator to resolve a hostname to a
 * specific IP without depending on that hostname actually existing on the
 * public internet. See the block comment above the image-marker helpers for
 * why a bare function/class needed by more than one test file has to live
 * here rather than in any single one of them (Pest's --parallel runner
 * assigns whole files to separate workers).
 */
final class FakeHostResolver implements HostResolver
{
    /**
     * @param  array<string, list<string>>  $hostToIps
     */
    public function __construct(private readonly array $hostToIps) {}

    public function resolve(string $host): array
    {
        return $this->hostToIps[$host] ?? [];
    }
}

/**
 * Binds a FakeHostResolver so UrlImportValidator (and everything built on
 * it — SafeImportHttpClient, the import adapters/actions/Livewire
 * components) never performs a real DNS lookup in tests. Defaults cover the
 * handful of hostnames the wider Import test suite fetches through
 * Http::fake(); pass $extraHostToIps to add or override entries for a
 * specific test.
 *
 * @param  array<string, list<string>>  $extraHostToIps
 */
function bindFakeHostResolver(array $extraHostToIps = []): void
{
    $defaults = [
        'example.com' => ['93.184.216.34'],
        'cdn.example.com' => ['93.184.216.35'],
        'www.instagram.com' => ['157.240.2.174'],
    ];

    app()->instance(HostResolver::class, new FakeHostResolver($extraHostToIps + $defaults));
}

/**
 * An ImportHttpTransport test double that returns a scripted sequence of
 * responses (one per call to get()) and records every ResolvedImportTarget
 * it was actually invoked with — shared by every SafeImportHttpClient-level
 * test that needs precise, per-hop control over what the "network" returns
 * without going through Http::fake() (which bypasses PinnedImportHttpTransport
 * entirely and can't simulate hop-by-hop transport behavior). A scripted
 * entry may be an ImportTransportResponse, or a Closure(ResolvedImportTarget,
 * ImportFetchPolicy): ImportTransportResponse for a hop that needs to throw
 * (e.g. simulating a connect timeout on one specific hop).
 */
final class ScriptedImportHttpTransport implements ImportHttpTransport
{
    /** @var list<ResolvedImportTarget> */
    public array $calls = [];

    /**
     * @param  list<ImportTransportResponse|Closure>  $responses
     */
    public function __construct(private array $responses) {}

    public function get(ResolvedImportTarget $target, ImportFetchPolicy $policy): ImportTransportResponse
    {
        $this->calls[] = $target;

        $next = array_shift($this->responses);

        if ($next === null) {
            throw new RuntimeException('ScriptedImportHttpTransport: no more scripted responses.');
        }

        if ($next instanceof Closure) {
            return $next($target, $policy);
        }

        return $next;
    }
}

/**
 * The single committed source of truth for which infrastructure CLIs must
 * stay executable — also read (independently, at runtime) by both
 * deploy-staging.yml and release.yml, so the allowlist enforced by
 * InfrastructureScriptExecutableModesTest, DeployStagingWorkflowTest and
 * ProductionReleaseWorkflowTest can never drift from what the artifact-build
 * workflows actually verify.
 *
 * @return list<string>
 */
function requiredCliManifestNames(): array
{
    $manifestPath = base_path('infrastructure/config/required-clis.txt');
    $contents = file_get_contents($manifestPath);

    if ($contents === false) {
        throw new RuntimeException("could not read the required-CLI manifest: {$manifestPath}");
    }

    $lines = preg_split('/\R/', $contents);

    return array_values(array_filter(array_map('trim', $lines), fn (string $line): bool => $line !== ''));
}

/*
|--------------------------------------------------------------------------
| Scope guards: what a change added, and what it deliberately did not
|--------------------------------------------------------------------------
|
| A scope guard answers two questions about one body of work: does the end
| state look the way it should, and did this branch stay inside its own
| boundary. The second half needs a diff, so these helpers resolve the
| revision a branch is measured against and read files as of it.
|
| They live here, once, because every guard needs the identical answer.
| Copies of them drifted apart across three separate guard files before this.
*/

/**
 * Every operational file a rejected architecture could sneak back into.
 *
 * @return list<string>
 */
function operationalFiles(): array
{
    $configFiles = [];

    $tree = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('infrastructure/config'), FilesystemIterator::SKIP_DOTS),
    );

    foreach ($tree as $entry) {
        if ($entry->isFile()) {
            $configFiles[] = $entry->getPathname();
        }
    }

    return array_values(array_filter(array_merge(
        glob(base_path('.github/workflows/*.yml')) ?: [],
        glob(base_path('.github/actions/*/action.yml')) ?: [],
        glob(base_path('infrastructure/scripts/*')) ?: [],
        $configFiles,
    ), 'is_file'));
}

/**
 * Is this run measuring a `develop → main` promotion?
 *
 * Every diff-based scope guard asks "what did THIS change touch", and answers it
 * by diffing against the base. That question only has a meaning when the base is
 * the branch the work was cut from. A promotion into `main` compares against a
 * branch hundreds of commits behind, so the "change" becomes the whole history
 * of develop and the guards report everything as newly touched — which is a
 * property of the comparison, not a defect in the code.
 *
 * Those guards already skip when there is no base at all (a push event). A
 * promotion is the same situation for the same reason, so it resolves to no base
 * and they skip identically, rather than each guard needing to know about it.
 */
function branchIsPromotionToMain(): bool
{
    return getenv('GITHUB_BASE_REF') === 'main';
}

/**
 * The revision this branch is measured against: the pull request's own base
 * commit in CI, `origin/develop` locally, or null when neither is available —
 * which a promotion into `main` also resolves to, see above.
 */
function branchBaseRevision(): ?string
{
    if (branchIsPromotionToMain()) {
        return null;
    }

    $baseSha = getenv('BASE_SHA');

    if (is_string($baseSha) && $baseSha !== '' && gitSucceeds(['cat-file', '-e', $baseSha.'^{commit}'])) {
        return $baseSha;
    }

    return gitSucceeds(['rev-parse', '--verify', 'origin/develop']) ? 'origin/develop' : null;
}

/**
 * @param  list<string>  $arguments
 */
function gitSucceeds(array $arguments): bool
{
    // Every argument is escaped individually: BASE_SHA is an environment
    // value and this runs through a shell.
    $command = 'cd '.escapeshellarg(base_path()).' && git '
        .implode(' ', array_map('escapeshellarg', $arguments))
        .' >/dev/null 2>&1; echo $?';

    return trim((string) shell_exec($command)) === '0';
}

/** @return list<string> */
function branchChangedFiles(): array
{
    $baseline = trim((string) shell_exec(
        'cd '.escapeshellarg(base_path()).' && git diff --name-only '
            .escapeshellarg((string) branchBaseRevision()).' HEAD 2>/dev/null'
    ));

    return $baseline === '' ? [] : explode("\n", $baseline);
}

/**
 * The changed files whose CODE actually changed — a file this branch touched
 * only in comments is not in the list.
 *
 * The "these files stay untouched" guards exist to protect behaviour: a script
 * accepted on real hardware must not be quietly edited by a later slice. What
 * they are not for is freezing prose. A repository-wide comment cleanup
 * legitimately rewrites a line in `backup` or `common` without changing a
 * single thing either one does, and a guard that fires on that is measuring
 * bytes where it means to measure behaviour — which trains people to widen it,
 * and then it stops catching the real edit too.
 *
 * @return list<string>
 */
function branchChangedCodeFiles(): array
{
    return array_values(array_filter(
        branchChangedFiles(),
        static function (string $path): bool {
            $diff = branchFileDiff($path);
            $changed = array_merge($diff['added'], $diff['removed']);

            return $changed !== [] && sourceCodeLines($changed) !== [];
        },
    ));
}

/**
 * The lines that are not blank and not a whole-line comment.
 *
 * Deliberately only `#` and `//`. A leading `*` is a comment continuation in
 * PHP but a `case` arm in shell, and every file these guards protect is a
 * shell script or a `#`-commented config — so treating `*` as prose here would
 * hide exactly the kind of change they exist to catch.
 *
 * @param  list<string>  $lines
 * @return list<string>
 */
function sourceCodeLines(array $lines): array
{
    return array_values(array_filter(
        array_map('trim', $lines),
        static fn (string $line): bool => $line !== ''
            && ! str_starts_with($line, '#')
            && ! str_starts_with($line, '//'),
    ));
}

/**
 * The lines this branch adds to, and removes from, one file.
 *
 * `-U0` so the hunks carry no context: every `+` really is an addition. This is
 * what lets a scope guard say "exactly this much changed, and nothing else"
 * about a file it deliberately touches, instead of the blunter "this file did
 * not change at all".
 *
 * @return array{added: list<string>, removed: list<string>}
 */
function branchFileDiff(string $path): array
{
    $diff = (string) shell_exec(
        'cd '.escapeshellarg(base_path()).' && git diff -U0 '
            .escapeshellarg((string) branchBaseRevision()).' HEAD -- '.escapeshellarg($path).' 2>/dev/null'
    );

    $added = [];
    $removed = [];

    foreach (explode("\n", $diff) as $line) {
        if (str_starts_with($line, '+++') || str_starts_with($line, '---')) {
            continue;
        }

        if (str_starts_with($line, '+')) {
            $added[] = mb_substr($line, 1);
        } elseif (str_starts_with($line, '-')) {
            $removed[] = mb_substr($line, 1);
        }
    }

    return ['added' => $added, 'removed' => $removed];
}

/**
 * One file as this branch has it committed.
 *
 * These guards describe the branch, not the working tree — that is what a diff
 * against the base measures, and what CI reviews. Reading the file from HEAD
 * too keeps both halves of an assertion talking about the same thing, instead
 * of comparing a committed diff against uncommitted edits.
 */
function committedFile(string $path): string
{
    return (string) shell_exec(
        'cd '.escapeshellarg(base_path()).' && git show HEAD:'.escapeshellarg($path).' 2>/dev/null'
    );
}

/**
 * One file as the base revision has it, so a diff-bounded guard can say where a
 * REMOVED line used to live, not only where an added one landed.
 */
function baseRevisionFile(string $path): string
{
    return (string) shell_exec(
        'cd '.escapeshellarg(base_path()).' && git show '
            .escapeshellarg((string) branchBaseRevision().':'.$path).' 2>/dev/null'
    );
}

/**
 * The body of one shell function, so an ordering assertion is about what
 * actually runs rather than about where things happen to be declared. Every one
 * of these scripts defines its helpers above its pipeline, so a whole-file
 * position comparison would routinely say the opposite of the truth.
 */
function shellFunctionBody(string $source, string $name): string
{
    $start = mb_strpos($source, "\n{$name}() {\n");

    expect($start)->not->toBeFalse("{$name} is not defined");

    $end = mb_strpos($source, "\n}\n", $start);

    expect($end)->not->toBeFalse("{$name} has no closing brace");

    return mb_substr($source, $start, $end - $start);
}

/**
 * One source file with its comment and blank lines removed, so a
 * forbidden-construct scan reasons about code rather than about prose.
 *
 * Every guard that greps a script, a wrapper, an action or a workflow for
 * something it must never contain needs this, and for one specific reason: the
 * files most likely to be scanned for `eval` / `bash -c` / a legacy flag are
 * exactly the files that legitimately DOCUMENT not using it. A naive whole-file
 * grep turns "no eval, no bash -c, no string-built command" in a header comment
 * into a violation — the real incident
 * install-target-perimeter's own verify_wrapper_static_contract was hardened
 * against, and one that has since been rediscovered in four separate test
 * files.
 *
 * `#` is the comment marker in every format this is used on: shell scripts,
 * YAML actions and YAML workflows alike.
 */
function executableSourceLines(string $source): string
{
    return implode("\n", array_filter(
        preg_split('/\R/', $source),
        static fn (string $line): bool => $line !== '' && ! str_starts_with(ltrim($line), '#'),
    ));
}

/**
 * The sourced libraries under infrastructure/scripts: never executed
 * directly, so they must stay non-executable. Kept here beside
 * requiredCliManifestNames() so the CLI allowlist and the library exemption
 * have one definition between every test that reasons about either.
 *
 * @return list<string>
 */
function sourcedLibraryNames(): array
{
    return ['common', 'restore-common', 'smtp-submission'];
}

/**
 * Scripts under infrastructure/scripts/ that are REPOSITORY tooling: run from a
 * checkout by a developer or by CI, and deliberately never installed onto a host.
 *
 * The third category, and it exists because the first two could not honestly hold
 * one. A required CLI is installed on every host; a sourced library is installed
 * and read by those CLIs. `render-environment-templates` is neither — and its
 * absence from a host is a SAFETY property, not an omission: it generates the
 * committed environment templates, and a generator reachable on a host would be a
 * way for tooling to write a target's canonical shared/.env, which is the
 * operator's to own.
 *
 * `mail-routing` is the second, for a plainer reason: it validates the reviewed
 * mail routing policy and renders the plan the mail gateway follows. It
 * describes and installs nothing; the gateway installer runs it from the same
 * temporary bundle it arrived in, never from an installed copy.
 *
 * `mail-identity` is the third, for the same reason: it judges the reviewed host
 * and target mail identity, the DKIM keys it names, and public DNS against both.
 * It installs nothing, and runs from a trusted bundle or checkout.
 *
 * `activate-mail-outbound` and `send-mail-canary` run only from a trusted bundle
 * uploaded by their own workflows: the activation copies that bundle's whole
 * infrastructure/ tree to judge the pre-activation state with it, and both
 * compose mail-routing and mail-identity, which a host never has installed.
 *
 * So a script listed here must stay out of required-clis.txt and out of the
 * operational bundle, and the guards that inventory infrastructure/scripts/ know
 * to expect exactly that rather than reporting it as unclassified.
 *
 * @return list<string>
 */
function repositoryOnlyScriptNames(): array
{
    return ['activate-mail-outbound', 'mail-identity', 'mail-routing', 'render-environment-templates', 'send-mail-canary', 'verify-infrastructure'];
}

/**
 * A correctly normalized release-tree fixture: every manifested CLI present
 * and executable, every sourced library present, readable and non-executable,
 * the manifest itself copied verbatim from the real committed one. Shared by
 * InfrastructureScriptExecutableModesTest (testing verify-required-clis and
 * deploy directly) and DeployStagingWorkflowTest/ProductionReleaseWorkflowTest
 * (testing that each workflow correctly delegates to it).
 */
function releaseCliFixture(array $cliNames): string
{
    $root = sys_get_temp_dir().'/release-cli-exec-check-'.uniqid('', true);

    mkdir($root.'/infrastructure/scripts', 0o755, true);
    mkdir($root.'/infrastructure/config', 0o755, true);
    copy(base_path('infrastructure/config/required-clis.txt'), $root.'/infrastructure/config/required-clis.txt');

    foreach ($cliNames as $name) {
        file_put_contents($root.'/infrastructure/scripts/'.$name, "#!/usr/bin/env bash\n");
        chmod($root.'/infrastructure/scripts/'.$name, 0o755);
    }

    foreach (sourcedLibraryNames() as $library) {
        file_put_contents($root.'/infrastructure/scripts/'.$library, "#!/usr/bin/env bash\n");
        chmod($root.'/infrastructure/scripts/'.$library, 0o644);
    }

    return $root;
}

/**
 * An in-memory Sentry transport: every event the SDK decides to send lands in
 * $events instead of on the network. Shared by every Sentry test file (see the
 * block comment above the image-marker helpers for why a helper used by more
 * than one test file has to live here rather than in any single one of them).
 */
final class RecordingSentryTransport implements SentryTransportInterface
{
    /** @var list<SentryEvent> */
    public array $events = [];

    public function send(SentryEvent $event): SentryTransportResult
    {
        $this->events[] = $event;

        return new SentryTransportResult(SentryResultStatus::success(), $event);
    }

    public function close(?int $timeout = null): SentryTransportResult
    {
        return new SentryTransportResult(SentryResultStatus::success());
    }

    /** @return list<SentryEvent> */
    public function errorEvents(): array
    {
        return array_values(array_filter(
            $this->events,
            static fn (SentryEvent $event): bool => $event->getType() === SentryEventType::event(),
        ));
    }
}

/**
 * Rebuilds the Sentry client the application is already using, from the
 * application's own config/sentry.php, with only the transport replaced — so
 * tests exercise our real options (release, environment, sampling, PII, SQL
 * bindings) and our real scope, and never open a socket to sentry.io.
 *
 * The hub instance itself is kept and only re-bound to the new client, so the
 * scope App\Providers\ObservabilityServiceProvider configured at boot (the
 * deployment_target/commit tags) survives.
 *
 * @param  array<string, mixed>  $config  config overrides applied before the client is built
 */
function fakeSentryTransport(array $config = []): RecordingSentryTransport
{
    config(array_merge(['sentry.dsn' => 'https://recorder@sentry.invalid/1'], $config));

    $transport = new RecordingSentryTransport;

    /** @var SentryClientBuilder $builder */
    $builder = app(SentryClientBuilder::class);
    $builder->setTransport($transport);

    // The PHP SDK's default integrations install process-global error and
    // exception handlers. The Laravel service provider strips exactly those in
    // production; here we build without them entirely and add back only the
    // Laravel integration that shapes events, so a test client can never take
    // over PHPUnit's own handlers.
    $builder->getOptions()->setDefaultIntegrations(false);
    $builder->getOptions()->setIntegrations([new SentryLaravelIntegration]);

    app(SentryHubInterface::class)->bindClient($builder->getClient());

    return $transport;
}

/**
 * Captures the records Nightwatch is about to transmit, and stops it.
 *
 * `IngestingEvents` is the package's own public event, dispatched through
 * `Event::until()` with the exact serialized payloads immediately before they
 * are written to the agent socket — and a listener returning `false` halts
 * that write. So this both reads what would really be sent (not a
 * reconstruction of it) and guarantees a test never opens a socket, needs an
 * agent, or consumes account quota.
 *
 * Shared by every Nightwatch ingest test (see the block comment above the
 * image-marker helpers for why a helper used by more than one test file has to
 * live here rather than in any single one of them).
 */
final class RecordingNightwatchIngest
{
    /** @var list<array<string, mixed>> */
    public array $records = [];

    public function __invoke(NightwatchIngestingEvents $event): bool
    {
        foreach ($event->records as $record) {
            $this->records[] = $record;
        }

        return false;
    }

    /**
     * The records exactly as they would go on the wire.
     *
     * Nightwatch defers some fields (the user ID, for one) behind a
     * JsonSerializable LazyValue that only resolves during encoding, so the
     * raw array is not yet what would be sent. Round-tripping through
     * json_encode reproduces `Payload::json`'s own step and is therefore the
     * only faithful thing to assert against.
     *
     * @return list<array<string, mixed>>
     */
    public function wire(): array
    {
        return json_decode($this->encoded(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return list<array<string, mixed>> */
    public function ofType(string $type): array
    {
        return array_values(array_filter(
            $this->wire(),
            static fn (array $record): bool => ($record['t'] ?? null) === $type,
        ));
    }

    /** The whole capture as one string, for "this value appears nowhere" assertions. */
    public function encoded(): string
    {
        return (string) json_encode(
            $this->records,
            JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}

/**
 * Registers the recorder above and returns it. Nightwatch flushes its buffer
 * on `Nightwatch::digest()`, which is the public way to make a test's events
 * arrive at a deterministic point.
 */
function captureNightwatchIngest(): RecordingNightwatchIngest
{
    $recorder = new RecordingNightwatchIngest;

    Event::listen(NightwatchIngestingEvents::class, $recorder);

    return $recorder;
}

/**
 * The executable source of a PHP file with every comment removed — shared by
 * the observability guards that assert a file never shells out to Git and
 * never special-cases a deployment target. Those files document both rules at
 * length, and prose about a rule must never be mistaken for a breach of it.
 * (See the block comment above the image-marker helpers for why a helper used
 * by more than one test file has to live here.)
 */
function phpSourceWithoutComments(string $relativePath): string
{
    return collect(token_get_all(File::get(base_path($relativePath))))
        ->reject(fn (array|string $token): bool => is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true))
        ->map(fn (array|string $token): string => is_array($token) ? $token[1] : $token)
        ->implode('');
}

/**
 * A minimal NormalizedImage fixture shared by every Media test that needs
 * one to hand to MediaStorage::storeNormalized() without running a real
 * image through GdImageIngestor first.
 */
function normalizedFixture(string $bytes = 'normalized-jpeg-bytes'): NormalizedImage
{
    return new NormalizedImage(
        bytes: $bytes,
        mimeType: 'image/jpeg',
        extension: 'jpg',
        byteSize: strlen($bytes),
        width: 800,
        height: 600,
    );
}

/**
 * Create two configurable Rating Groups used by feed filter tests.
 */
function seedFeedFilterGroups(): void
{
    $type = RatingGroup::factory()->create(['key' => 'type', 'sort_order' => 10]);
    RatingOption::factory()->create(['rating_group_id' => $type->id, 'key' => 'type_a', 'sort_order' => 10]);
    RatingOption::factory()->create(['rating_group_id' => $type->id, 'key' => 'type_b', 'sort_order' => 20]);

    $attribute = RatingGroup::factory()->create(['key' => 'attribute', 'sort_order' => 20]);
    RatingOption::factory()->create(['rating_group_id' => $attribute->id, 'key' => 'attribute_a', 'sort_order' => 10]);
    RatingOption::factory()->create(['rating_group_id' => $attribute->id, 'key' => 'attribute_b', 'sort_order' => 20]);
    RatingOption::factory()->create(['rating_group_id' => $attribute->id, 'key' => 'attribute_c', 'sort_order' => 30]);
    RatingOption::factory()->create(['rating_group_id' => $attribute->id, 'key' => 'attribute_d', 'sort_order' => 40]);
    RatingOption::factory()->create(['rating_group_id' => $attribute->id, 'key' => 'attribute_other', 'sort_order' => 50]);
}

/**
 * The following image-marker helpers are shared across multiple Media test
 * files (GdImageIngestorTest, GdImageVariantProcessorTest, and everything
 * that generates fixture bytes for MediaVariantGenerator/Writer/Job/Command
 * tests). They live here — not as bare functions inside any one of those
 * files — because Pest's parallel runner (`--parallel`, used in CI) assigns
 * whole test files to separate worker processes; a bare function declared in
 * file A is not visible to file B when they land in different workers, which
 * only surfaces as an "undefined function" failure under --parallel, not
 * under a normal sequential run. Everything in this file, by contrast, is
 * part of Pest's own bootstrap and is loaded by every worker.
 */

/**
 * Distinct marker colors in each corner: TL=red, TR=green, BL=blue,
 * BR=white — lets orientation tests assert on physical pixel positions, not
 * just reported dimensions.
 */
function makeMarkerImage(int $width, int $height): GdImage
{
    $im = imagecreatetruecolor($width, $height);
    imagefill($im, 0, 0, imagecolorallocate($im, 0, 0, 0));
    imagesetpixel($im, 0, 0, imagecolorallocate($im, 255, 0, 0));
    imagesetpixel($im, $width - 1, 0, imagecolorallocate($im, 0, 255, 0));
    imagesetpixel($im, 0, $height - 1, imagecolorallocate($im, 0, 0, 255));
    imagesetpixel($im, $width - 1, $height - 1, imagecolorallocate($im, 255, 255, 255));

    return $im;
}

function markerCornerColor(GdImage $im, int $x, int $y): string
{
    $rgb = imagecolorsforindex($im, imagecolorat($im, $x, $y));

    return match (true) {
        $rgb['red'] > 200 && $rgb['green'] < 50 && $rgb['blue'] < 50 => 'RED',
        $rgb['green'] > 200 && $rgb['red'] < 50 && $rgb['blue'] < 50 => 'GREEN',
        $rgb['blue'] > 200 && $rgb['red'] < 50 && $rgb['green'] < 50 => 'BLUE',
        $rgb['red'] > 200 && $rgb['green'] > 200 && $rgb['blue'] > 200 => 'WHITE',
        default => 'OTHER',
    };
}

/** @return array{tl: string, tr: string, bl: string, br: string, width: int, height: int} */
function markerCorners(string $bytes): array
{
    $im = imagecreatefromstring($bytes);
    $w = imagesx($im);
    $h = imagesy($im);

    return [
        'tl' => markerCornerColor($im, 0, 0),
        'tr' => markerCornerColor($im, $w - 1, 0),
        'bl' => markerCornerColor($im, 0, $h - 1),
        'br' => markerCornerColor($im, $w - 1, $h - 1),
        'width' => $w,
        'height' => $h,
    ];
}

function jpegMarkerBytes(int $width = 20, int $height = 10, int $quality = 90): string
{
    $im = makeMarkerImage($width, $height);
    ob_start();
    imagejpeg($im, null, $quality);

    return ob_get_clean();
}

/** @param 'png'|'webp' $format */
function markerBytesWithAlpha(string $format, int $width = 20, int $height = 10): string
{
    $im = imagecreatetruecolor($width, $height);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagesetpixel($im, 0, 0, imagecolorallocatealpha($im, 255, 0, 0, 0));
    imagesetpixel($im, $width - 1, 0, imagecolorallocatealpha($im, 0, 255, 0, 0));
    imagesetpixel($im, 0, $height - 1, imagecolorallocatealpha($im, 0, 0, 255, 0));
    ob_start();

    match ($format) {
        'png' => imagepng($im),
        'webp' => imagewebp($im),
    };

    return ob_get_clean();
}

/**
 * Solid corner BLOCKS (not single pixels, unlike makeMarkerImage() above) —
 * a single marker pixel gets diluted below markerCornerColor()'s detection
 * threshold once imagecopyresampled()'s interpolation and JPEG's lossy
 * compression both apply, which never happens to makeMarkerImage()'s own
 * pixel-preserving flip/rotate use in GdImageIngestorTest. Block size scales
 * with the image so it survives even an aggressive downscale (e.g.
 * 4000x500 -> 640x80, ~0.16x, exercised in GdImageVariantProcessorTest).
 */
function containResizeMarkerBytes(int $width, int $height): string
{
    return solidCornerBlockJpeg($width, $height, 0, 0, $width, $height);
}

/**
 * Places the four marker color blocks at the region that will become a
 * CoverSquare crop's four corners, rather than the source image's own
 * absolute corners — lets a crop+resize be verified with the same
 * markerCorners() reader above.
 */
function coverSquareCropMarkerBytes(int $width, int $height): string
{
    $cropSize = min($width, $height);
    $cropX = intdiv($width - $cropSize, 2);
    $cropY = intdiv($height - $cropSize, 2);

    return solidCornerBlockJpeg($width, $height, $cropX, $cropY, $cropSize, $cropSize);
}

/**
 * Generalizes coverSquareCropMarkerBytes() to an arbitrary (non-square)
 * target aspect ratio — places the four marker color blocks at the region
 * that will become a Cover crop's four corners for the given targetWidth /
 * targetHeight ratio, mirroring GdImageVariantProcessor::planCover()'s own
 * math so a test failure here means the two have diverged.
 */
function coverCropMarkerBytes(int $width, int $height, int $targetWidth, int $targetHeight): string
{
    $targetRatio = $targetWidth / $targetHeight;
    $srcRatio = $width / $height;

    if ($srcRatio > $targetRatio) {
        $cropHeight = $height;
        $cropWidth = (int) round($height * $targetRatio);
    } else {
        $cropWidth = $width;
        $cropHeight = (int) round($width / $targetRatio);
    }

    $cropX = intdiv($width - $cropWidth, 2);
    $cropY = intdiv($height - $cropHeight, 2);

    return solidCornerBlockJpeg($width, $height, $cropX, $cropY, $cropWidth, $cropHeight);
}

function solidCornerBlockJpeg(int $width, int $height, int $regionX, int $regionY, int $regionW, int $regionH): string
{
    $blockW = max(4, (int) round($regionW * 0.15));
    $blockH = max(4, (int) round($regionH * 0.15));

    $im = imagecreatetruecolor($width, $height);
    imagefill($im, 0, 0, imagecolorallocate($im, 0, 0, 0));

    $red = imagecolorallocate($im, 255, 0, 0);
    $green = imagecolorallocate($im, 0, 255, 0);
    $blue = imagecolorallocate($im, 0, 0, 255);
    $white = imagecolorallocate($im, 255, 255, 255);

    imagefilledrectangle($im, $regionX, $regionY, $regionX + $blockW - 1, $regionY + $blockH - 1, $red);
    imagefilledrectangle($im, $regionX + $regionW - $blockW, $regionY, $regionX + $regionW - 1, $regionY + $blockH - 1, $green);
    imagefilledrectangle($im, $regionX, $regionY + $regionH - $blockH, $regionX + $blockW - 1, $regionY + $regionH - 1, $blue);
    imagefilledrectangle($im, $regionX + $regionW - $blockW, $regionY + $regionH - $blockH, $regionX + $regionW - 1, $regionY + $regionH - 1, $white);

    ob_start();
    imagejpeg($im, null, 90);

    return ob_get_clean();
}

function variantSpec(
    MediaVariantName $name = MediaVariantName::PostFeed640,
    int $maxWidth = 640,
    int $maxHeight = 1280,
    MediaResizeMode $mode = MediaResizeMode::Contain,
    int $quality = 82,
    ?string $outputMimeType = null,
): MediaVariantSpecification {
    return new MediaVariantSpecification($name, $maxWidth, $maxHeight, $mode, $quality, $outputMimeType);
}

/**
 * A soft-deleted MediaAsset, with a master file and two variant files all
 * physically present, whose deleted_at is 19 days in the past — past the
 * 7-day default purge grace period and safely purgeable. Shared by
 * MediaLifecycleServicePurgeTest and MediaPurgeCommandTest (see the
 * block comment above image-marker helpers for why a shared bare function
 * used by more than one test file must live here, not in either file).
 * Requires the caller to have already called Storage::fake('public').
 *
 * Leaves Carbon test time frozen at 2026-01-20 12:00:00 when it returns —
 * callers must reset it themselves (e.g. `afterEach(fn () =>
 * Carbon::setTestNow())`) rather than assuming "now" is real wall-clock
 * time for the rest of the test.
 */
function createPurgeableAsset(): MediaAsset
{
    Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00'));
    $asset = MediaAsset::factory()->postImage()->create(['path' => 'posts/master.jpg']);
    Storage::disk('public')->put($asset->path, 'master-bytes');

    foreach ([MediaVariantName::PostFeed640, MediaVariantName::PostDetail1920] as $name) {
        $variant = MediaVariant::factory()->named($name)->create([
            'media_asset_id' => $asset->id,
            'disk' => 'public',
            'path' => "posts/master/{$name->value}.jpg",
        ]);
        Storage::disk('public')->put($variant->path, "{$name->value}-bytes");
    }

    $asset->delete();
    Carbon::setTestNow(Carbon::parse('2026-01-20 12:00:00')); // 19 days later, past the 7-day default grace

    return $asset->fresh();
}

/**
 * Builds a failed_jobs row in Laravel's real payload shape — the "command"
 * field is genuine serialize() output (never unserialize()'d by anything
 * under test), so these fixtures exercise FailedMediaJobReader's actual
 * regex-based extraction the same way a real queue worker failure would
 * populate the table. Shared by FailedMediaJobReaderTest and
 * MediaAuditServiceTest (see the block comment above image-marker helpers
 * for why a shared bare function used by more than one test file must live
 * here, not in either file).
 */
function insertFailedJobRow(string $jobClass, ?string $command, string $exception = "RuntimeException: boom\n#0 somewhere", ?string $failedAt = null): string
{
    $uuid = (string) Str::uuid();

    $payload = [
        'uuid' => $uuid,
        'displayName' => $jobClass,
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'maxTries' => 3,
        'data' => [
            'commandName' => $jobClass,
            'command' => $command,
        ],
    ];

    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'sync',
        'queue' => 'default',
        'payload' => json_encode($payload),
        'exception' => $exception,
        'failed_at' => $failedAt ?? now(),
    ]);

    return $uuid;
}

/**
 * Shared by MediaAspectRatioBrowserTest and ResponsiveMediaBrowserTest — see
 * the block comment above for why cross-file browser-test helpers live here
 * rather than as a bare function in either file.
 *
 * Several contexts (drawer, fullscreen, non-first feed cards) render
 * loading="lazy". Reading naturalWidth/naturalHeight before the browser has
 * actually decoded the image would yield 0/0 → a NaN ratio that fails the
 * assertion regardless of fit, nondeterministically depending on load
 * timing (worse under CI's --parallel, where workers compete for CPU).
 * `complete` can turn true slightly before pixel decoding has actually
 * finished, so each poll also awaits image.decode() itself (Pest's
 * $page->script() awaits a returned Promise, same as Playwright's
 * page.evaluate) rather than trusting the synchronous flags alone.
 */
function waitForImageLoaded(mixed $page, string $selector, float $timeoutSeconds = 5.0): void
{
    $deadline = microtime(true) + $timeoutSeconds;

    while (microtime(true) < $deadline) {
        $loaded = $page->script(<<<JS
            (async () => {
                const img = document.querySelector('{$selector}');
                if (!img || !img.complete || img.naturalWidth === 0) {
                    return false;
                }
                try {
                    await img.decode();
                } catch (e) {
                    return false;
                }
                return true;
            })()
        JS);

        if ($loaded) {
            return;
        }

        // As short as waitForScript()'s, and on the event loop like it: the
        // image is served by this same process.
        browserTestPause(0.025);
    }

    throw new RuntimeException("Image [{$selector}] did not finish loading within {$timeoutSeconds}s.");
}

/**
 * Compares the rendered <img> box ratio against the image's own natural
 * ratio. object-fit: cover would force the box toward the *container's*
 * ratio instead, so a mismatch here is exactly what would catch a
 * regression back to cropping.
 *
 * @return array{naturalWidth: int, naturalHeight: int, width: float, height: float, ratioDiff: float}
 */
function imageFitGeometry(mixed $page, string $selector): array
{
    waitForImageLoaded($page, $selector);

    $geometry = $page->script(<<<JS
        (() => {
            const img = document.querySelector('{$selector}');
            const rect = img.getBoundingClientRect();
            return {
                naturalWidth: img.naturalWidth,
                naturalHeight: img.naturalHeight,
                width: rect.width,
                height: rect.height,
            };
        })()
    JS);

    $naturalRatio = $geometry['naturalWidth'] / $geometry['naturalHeight'];
    $renderedRatio = $geometry['width'] / $geometry['height'];

    $geometry['ratioDiff'] = abs($naturalRatio - $renderedRatio);

    return $geometry;
}

/**
 * Waits until the page is laid out at the size resize() asked for, loaded and
 * with its fonts in — the state a layout measurement after a resize needs.
 * The new size is in place by the time resize() returns; a font still on its
 * way would change how wide the text is.
 */
function waitForViewportSize(mixed $page, int $width, int $height): void
{
    waitForScript($page, "window.innerWidth === {$width} && window.innerHeight === {$height} && document.readyState === 'complete' && document.fonts.status === 'loaded'");
}

/**
 * Waits until the page has the sliding post-detail panel a selected post
 * opens in below the desktop breakpoint: overlay mode's panel, or the one
 * split view loads lazily, in a request of its own after the page. Until that
 * one has arrived it ignores a selected post, so the post never opens.
 */
function waitForPostDetailOverlay(mixed $page): void
{
    waitForScript($page, 'document.querySelector(\'[data-testid="post-detail-overlay"]\') !== null');
}

/**
 * Waits until the post-detail panel is open and has stopped sliding in — the
 * state its geometry is measured in.
 */
function waitForPostDetailOverlayOpen(mixed $page): void
{
    waitForScript($page, <<<'JS'
        (() => {
            const panel = document.querySelector('[data-testid="post-detail-overlay"]');

            return Boolean(panel)
                && panel.classList.contains('translate-x-0')
                && panel.getAnimations().every((animation) => animation.playState === 'finished');
        })()
    JS);
}

/*
|--------------------------------------------------------------------------
| Restore Target Data — Restore Target Data harness
|--------------------------------------------------------------------------
|
| Shared by FetchBackupTest, VerifyBackupTest, RestoreDatabaseTest,
| RestoreStorageTest, RestoreServerPrimitivesScopeTest and the four
| restore-target files (RestoreTargetTest, RestoreTargetRuntimeQuiesceTest,
| RestoreTargetCodeAlignmentHoldTest, RestoreTargetInspectTest) — files that
| all need the same scratch target tree, the same parity registry, the same
| backup fixtures and the same self-contained stub host tooling. A helper used
| by more than one test file has to live here rather than in any single one of
| them (see the block comment above the image-marker helpers).
|
| Every test below executes the REAL shipped scripts under
| infrastructure/scripts — never a reimplementation of their logic — with each
| host dependency supplied through the gated RATEGURU_* test-override
| contract, exactly the way BackupTest/RestoreTest already do.
*/

/** The immutable release identity the fixture target is "serving". */
const FIXTURE_RELEASE = 'v1.4.0-20260101-000000-a81d7f2';

const FIXTURE_SOURCE_SHA = 'a81d7f2c3b4a5968778899aabbccddeeff001122';

/** A different, equally valid release/commit pair, for code-mismatch tests. */
const FIXTURE_OTHER_RELEASE = 'v1.5.0-20260202-000000-b92e8a3';

const FIXTURE_OTHER_SOURCE_SHA = 'b92e8a3d4c5a6a79889900bbccddeeff11223344';

function restoreScratchDir(): string
{
    $dir = sys_get_temp_dir().'/rateguru-restore-'.uniqid('', true).'-'.getmypid();

    foreach (['', '/bin', '/pg'] as $sub) {
        expect(@mkdir($dir.$sub, 0o755, true))->toBeTrue("could not create scratch directory: {$dir}{$sub}");
    }

    return $dir;
}

/**
 * A fresh, uniquely named directory under the system temp directory, holding
 * the given subdirectories ('' is the directory itself). The test owns it and
 * removes it with removeScratchDir().
 *
 * @param  list<string>  $subdirectories
 */
function makeScratchDir(string $prefix, array $subdirectories = [''], int $mode = 0o755): string
{
    $dir = sys_get_temp_dir().'/'.$prefix.'-'.bin2hex(random_bytes(6));

    foreach ($subdirectories as $sub) {
        expect(@mkdir($dir.$sub, $mode, true))->toBeTrue("could not create scratch directory: {$dir}{$sub}");
    }

    return $dir;
}

function removeScratchDir(string $dir): void
{
    exec('rm -rf '.escapeshellarg($dir));
}

/**
 * Whether this test process runs as root — its effective uid, which is what a
 * script's root gate reads. getmyuid() is not that: it is the owner of the
 * running PHP file.
 */
function testProcessIsRoot(): bool
{
    return posix_geteuid() === 0;
}

/**
 * Copies a prepared scratch directory into another one, as though the
 * preparation had been run there.
 *
 * Preparing a simulated host is often most of what a test costs — a whole
 * provision, restore or recovery run — while the state it leaves never varies.
 * A test file can build it once into a template and give every test a copy.
 * But the simulated hosts record absolute paths: ownership and type tables, the
 * stubs' logs, release links, generated configuration. So every text file and
 * every link that names $template is rewritten to name $scratch, and then the
 * copy is checked. A file or link that still named $template would let a test
 * read or write the template instead of its own host, so that fails the test.
 * The path is also matched as JSON writes it (`\/tmp\/…`): registries and
 * reports built with json_encode() carry it escaped.
 */
function copyScratchTemplate(string $template, string $scratch): void
{
    exec('cp -a '.escapeshellarg($template.'/.').' '.escapeshellarg($scratch).' 2>&1', $output, $status);
    expect($status)->toBe(0, "could not copy {$template} into {$scratch}: ".implode("\n", $output));

    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($scratch, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    $forms = [$template => $scratch, str_replace('/', '\\/', $template) => str_replace('/', '\\/', $scratch)];

    $namesTemplate = function (string $path) use ($template, $forms): bool {
        if (is_link($path)) {
            return str_starts_with((string) readlink($path), $template);
        }

        if (! is_file($path)) {
            return false;
        }

        $contents = (string) file_get_contents($path);

        foreach (array_keys($forms) as $form) {
            if (str_contains($contents, $form)) {
                return true;
            }
        }

        return false;
    };

    foreach ($entries as $entry) {
        $path = $entry->getPathname();

        if (! $namesTemplate($path)) {
            continue;
        }

        if (is_link($path)) {
            $target = (string) readlink($path);
            unlink($path);
            symlink($scratch.substr($target, strlen($template)), $path);
        } else {
            file_put_contents($path, strtr((string) file_get_contents($path), $forms));
        }
    }

    $stale = [];

    foreach ($entries as $entry) {
        if ($namesTemplate($entry->getPathname())) {
            $stale[] = $entry->getPathname();
        }
    }

    expect($stale)->toBe([], "the copy still names the template it came from ({$template})");
}

function infraScript(string $name): string
{
    return base_path('infrastructure/scripts/'.$name);
}

/**
 * A copy of a shipped script with two — and only two — mechanical rewrites,
 * so the real pipeline runs end to end without being root:
 *
 *   * every root-only `install -o root -g root` uses this process's own
 *     uid/gid instead;
 *   * `require_root` becomes a no-op, but ONLY when RGTEST_BYPASS_ROOT=true is
 *     explicitly set in the environment.
 *
 * Every line of restore logic under test is byte-identical to what ships. The
 * production root gate itself is proven separately, against the unpatched
 * script, by the "requires root" test in each file.
 */
function patchedInfraScript(string $scratch, string $name): string
{
    $path = $scratch.'/patched-'.$name;

    if (file_exists($path)) {
        return $path;
    }

    $source = File::get(infraScript($name));
    $source = str_replace('-o root', '-o '.getmyuid(), $source);
    $source = str_replace('-g root', '-g '.getmygid(), $source);

    // Anchored on the LAST library the script sources, so this works for the
    // restore primitives (common + restore-common) and for a script that
    // sources common alone — require_root only exists once the library
    // defining it has been loaded.
    $source = preg_replace(
        '/^(source "\$\{[A-Z_]*COMMON_FILE\}"\n)(?![\s\S]*^source "\$\{[A-Z_]*COMMON_FILE\}"\n)/m',
        "$1\nif [[ \"\${RGTEST_BYPASS_ROOT:-false}\" == true ]]; then require_root() { :; }; fi\n",
        $source,
        1,
    );

    file_put_contents($path, $source);
    chmod($path, 0o755);

    return $path;
}

function writeExecutable(string $path, string $body): string
{
    file_put_contents($path, $body);
    chmod($path, 0o755);

    return $path;
}

/**
 * A wrapper around one of the simulated host's executables that runs it
 * unchanged and then, when one of its arguments is exactly $argument (on every
 * call when $argument is null), runs the Bash in $hook.
 *
 * A failure handler is only exercised by a failure that lands at the right
 * moment, and the moment is usually "after this step and before that one":
 * a cron.d that stops accepting the scheduler entry once it has been moved
 * out, a guard that can no longer be re-labelled once the data is staged. The
 * step the moment follows is nearly always a call to one of these tools, so
 * the hook rides on that call instead of on a modified copy of the script
 * under test.
 *
 * The wrapper exits with the wrapped executable's own status, so the tool
 * still succeeds or fails exactly as the test configured it. Written beside
 * the executable it wraps, as <name>-hooked.
 */
function executableWithHook(string $executable, ?string $argument, string $hook): string
{
    $match = $argument === null
        ? 'matched=true'
        : 'matched=false; for argument in "$@"; do [[ "${argument}" == '.escapeshellarg($argument).' ]] && matched=true; done';

    return writeExecutable($executable.'-hooked', implode("\n", [
        '#!/usr/bin/env bash',
        escapeshellarg($executable).' "$@"',
        'status=$?',
        $match,
        'if [[ "${matched}" == true ]]; then',
        $hook,
        'fi',
        'exit "${status}"',
        '',
    ]));
}

/**
 * A host-global deployment.conf pointing PHP_BIN at the scratch php stub.
 * common validates and sources this file; it is never the installed default
 * path, so no root ownership is demanded of it.
 */
function deploymentConfFixture(string $scratch): string
{
    $path = $scratch.'/deployment.conf';

    file_put_contents($path, implode("\n", [
        "RELEASE_ID_REGEX='^v[0-9]+\\.[0-9]+\\.[0-9]+-[0-9]{8}-[0-9]{6}-[0-9a-f]{7,40}\$'",
        'PHP_BIN='.$scratch.'/bin/php',
        'PHP_FPM_SERVICE=php-noop-fpm',
        '',
    ]));

    return $path;
}

/**
 * A registry declaring one fully valid, lifecycle=active `parity-target`
 * whose paths all live inside the scratch tree, plus the patched `targets`
 * validator that accepts it (the shipped validator only allows staging-main
 * to be active).
 *
 * @return array{0: string, 1: string} [registryPath, targetsCliPath]
 */
function parityRegistryFixture(string $scratch, array $options = []): array
{
    // Memoised per scratch directory and option set. Every infrastructure
    // script invocation resolves this fixture, and a full restore or recovery
    // makes six or seven of them — so re-writing both files and re-running the
    // registry validator on each call was paying for the same answer dozens of
    // times per test.
    static $cache = [];

    $key = $scratch.'|'.md5(serialize($options));

    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $account = trim((string) shell_exec('id -un'));
    $group = trim((string) shell_exec('id -gn'));

    $patched = File::get(base_path('infrastructure/scripts/targets'));
    $patched = str_replace('ACTIVE_ALLOWLIST="staging-main"', 'ACTIVE_ALLOWLIST="parity-target"', $patched);
    $patched = str_replace('elif [[ "${application_root}" != /home/www/rateguru/* ]]; then', 'elif false; then', $patched);
    $patched = str_replace('elif [[ "${incoming}" != /home/* ]]; then', 'elif false; then', $patched);
    $patched = str_replace('if [[ "${code_group}" == "${runtime_group}" ]]; then', 'if false; then', $patched);
    $patched = str_replace('if [[ "${code_group}" == "${runtime_user}" ]]; then', 'if false; then', $patched);

    $targetsPath = writeExecutable($scratch.'/parity-targets', $patched);

    $registry = [
        'schema_version' => 1,
        'targets' => [
            'parity-target' => [
                'id' => 'parity-target',
                'lifecycle' => $options['lifecycle'] ?? 'active',
                'environment_class' => 'staging',
                'application_root' => $scratch.'/target',
                'runtime_user' => $account,
                'runtime_group' => $group,
                'deploy_user' => 'parity-deploy',
                'code_group' => $group,
                'incoming_artifacts' => $scratch.'/incoming',
                'release_retention' => 5,
                'database' => [
                    'name' => $options['database'] ?? 'parity_db',
                    'application_role' => $options['role'] ?? 'parity_app',
                ],
                'health' => ['url' => 'http://127.0.0.1/', 'host_header' => 'parity.internal'],
                'public_hostnames' => ['parity.example'],
                'backup' => [
                    'namespace' => $options['namespace'] ?? 'parity',
                    'local_retention_days' => 14,
                    'offsite_retention_days' => 30,
                    'minimum_retained_backups' => 2,
                ],
                'php_fpm' => ['pool' => 'parity-pool', 'socket' => '/run/php/parity.sock'],
                'supervisor' => ['program' => 'parity-queue', 'queue' => 'parity'],
                'scheduler' => ['name' => 'parity-scheduler'],
                'nginx' => ['site_name' => 'parity-site', 'internal_hostname' => 'parity.internal'],
                'environment_template' => 'infrastructure/templates/environment/staging.env.example',
            ],
            'planned-target' => [
                'id' => 'planned-target',
                'lifecycle' => 'planned',
                'environment_class' => 'production',
                'application_root' => $scratch.'/planned',
                // Distinct identities from parity-target: the registry
                // validator rejects two targets sharing a runtime user,
                // runtime group or code group. Nothing ever uses these — a
                // planned target is rejected before any identity is read.
                'runtime_user' => 'planned-runtime',
                'runtime_group' => 'planned-runtime',
                'deploy_user' => 'planned-deploy',
                'code_group' => 'planned-code',
                'incoming_artifacts' => $scratch.'/planned-incoming',
                'release_retention' => 5,
                'database' => ['name' => 'planned_db', 'application_role' => 'planned_app'],
                'health' => ['url' => 'http://127.0.0.1/', 'host_header' => 'planned.internal'],
                'public_hostnames' => ['planned.example'],
                'backup' => [
                    'namespace' => 'planned',
                    'local_retention_days' => 14,
                    'offsite_retention_days' => 30,
                    'minimum_retained_backups' => 2,
                ],
                'php_fpm' => ['pool' => 'planned-pool', 'socket' => '/run/php/planned.sock'],
                'supervisor' => ['program' => 'planned-queue', 'queue' => 'planned'],
                'scheduler' => ['name' => 'planned-scheduler'],
                'nginx' => ['site_name' => 'planned-site', 'internal_hostname' => 'planned.internal'],
                'environment_template' => 'infrastructure/templates/environment/tits-guru.env.example',
            ],
        ],
    ];

    $registryPath = $scratch.'/registry.json';
    file_put_contents($registryPath, json_encode($registry, JSON_PRETTY_PRINT));

    exec(escapeshellarg($targetsPath).' validate --file '.escapeshellarg($registryPath).' 2>&1', $out, $exit);
    expect($exit)->toBe(0, "parity registry fixture failed validation:\n".implode("\n", $out));

    return $cache[$key] = [$registryPath, $targetsPath];
}

/**
 * A `KEY=VALUE` file — an environment template, or a service's env file — as an
 * ordered map, ignoring blank and commented lines. Values are returned verbatim
 * (trailing CR stripped), quotes included.
 *
 * @return array<string, string>
 */
function envFileValues(string $path): array
{
    $full = base_path($path);

    expect(File::exists($full))->toBeTrue("missing env file: {$path}");

    $out = [];

    foreach (preg_split('/\R/', File::get($full)) as $line) {
        $trimmed = trim($line);

        if ($trimmed === '' || str_starts_with($trimmed, '#') || ! str_contains($trimmed, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $trimmed, 2);
        $out[trim($key)] = rtrim($value, "\r");
    }

    return $out;
}

/*
|--------------------------------------------------------------------------
| The staging mail-capture slice on a simulated host
|--------------------------------------------------------------------------
|
| install-mail-capture, status-mail-capture and verify-mail-capture ask the
| host the same questions — systemctl, ss, the two HTTP APIs, journalctl — so
| their tests answer them from one set of stubs on PATH. Every answer comes
| from a plain file in the workspace's state directory, every state-changing
| call writes those files back, and every call is logged to state/calls.
*/

/**
 * A throwaway workspace: command stubs in bin/, the files that drive them in
 * state/. The test owns it and removes it with removeScratchDir($root).
 *
 * The stubs and the files they read:
 *
 *   systemctl   <unit>.active / .sub / .enabled / .nrestarts / .result /
 *               .exec_status; fail_<verb>_<unit> makes that verb fail;
 *               <unit>.nrestarts_step makes NRestarts climb on every read (a
 *               restart loop); <unit>.stops lists units that go down with it.
 *   ss          listeners — one "host:port" per line.
 *   curl        apis — an API answers while one of its endpoints is listed;
 *               messages-<port> — "<id> <subject>" per stored message;
 *               messages-body-<port> — a raw body for GET /api/v1/messages;
 *               downloads/<archive> — what a release download receives.
 *   nginx       nginx_invalid makes `nginx -t` fail.
 *   journalctl  journal-<unit> — what the unit's journal holds.
 *   sleep       nothing: the scripts' bounded waits run without waiting.
 *
 * @return array{root:string, bin:string, state:string}
 */
function mailCaptureStubWorkspace(): array
{
    $root = makeScratchDir('mail-capture-host', ['', '/bin', '/state', '/state/downloads'], 0o700);
    $bin = $root.'/bin';
    $state = $root.'/state';

    $systemctl = <<<'SH'
#!/usr/bin/env bash
set -uo pipefail
state_dir="${STUB_STATE_DIR}"
printf '%s\n' "systemctl $*" >>"${state_dir}/calls"
read_state() { cat "${state_dir}/$1" 2>/dev/null || printf '%s' "$2"; }

cmd="${1:-}"
shift || true

unit="" quiet=false
for arg in "$@"; do
    case "${arg}" in
        --quiet) quiet=true ;;
        --*) ;;
        *) [[ -n "${unit}" ]] || unit="${arg}" ;;
    esac
done

case "${cmd}" in
    show)
        prop=""
        for arg in "$@"; do
            case "${arg}" in --property=*) prop="${arg#--property=}" ;; esac
        done
        case "${prop}" in
            ActiveState) read_state "${unit}.active" inactive ;;
            SubState) read_state "${unit}.sub" dead ;;
            Result) read_state "${unit}.result" success ;;
            ExecMainStatus) read_state "${unit}.exec_status" 0 ;;
            NRestarts)
                count="$(read_state "${unit}.nrestarts" 0)"
                # A ".nrestarts_step" marker makes the counter climb on every
                # read: that is a service restarting under the caller.
                if [[ -f "${state_dir}/${unit}.nrestarts_step" ]]; then
                    printf '%s' "$((count + 1))" >"${state_dir}/${unit}.nrestarts"
                fi
                printf '%s' "${count}"
                ;;
            *) printf '' ;;
        esac
        printf '\n'
        ;;
    is-enabled)
        current="$(read_state "${unit}.enabled" not-found)"
        [[ "${quiet}" == true ]] || printf '%s\n' "${current}"
        [[ "${current}" == enabled || "${current}" == enabled-runtime ]] || exit 1
        ;;
    is-active)
        current="$(read_state "${unit}.active" inactive)"
        [[ "${quiet}" == true ]] || printf '%s\n' "${current}"
        [[ "${current}" == active ]] || exit 3
        ;;
    enable)
        [[ ! -f "${state_dir}/fail_enable_${unit}" ]] || exit 1
        printf 'enabled' >"${state_dir}/${unit}.enabled"
        ;;
    disable)
        [[ ! -f "${state_dir}/fail_disable_${unit}" ]] || exit 1
        printf 'disabled' >"${state_dir}/${unit}.enabled"
        ;;
    mask)
        printf 'masked' >"${state_dir}/${unit}.enabled"
        ;;
    restart|start)
        if [[ -f "${state_dir}/fail_restart_${unit}" ]]; then
            printf 'failed' >"${state_dir}/${unit}.active"
            printf 'failed' >"${state_dir}/${unit}.sub"
            exit 1
        fi
        printf 'active' >"${state_dir}/${unit}.active"
        printf 'running' >"${state_dir}/${unit}.sub"
        ;;
    stop)
        [[ ! -f "${state_dir}/fail_stop_${unit}" ]] || exit 1
        for stopped in "${unit}" $(cat "${state_dir}/${unit}.stops" 2>/dev/null); do
            printf 'inactive' >"${state_dir}/${stopped}.active"
            printf 'dead' >"${state_dir}/${stopped}.sub"
        done
        ;;
    reload)
        [[ ! -f "${state_dir}/fail_reload_${unit}" ]] || exit 1
        ;;
    *) ;;
esac
exit 0
SH;

    $ss = <<<'SH'
#!/usr/bin/env bash
set -uo pipefail
while read -r hostport; do
    [[ -n "${hostport}" ]] || continue
    printf 'LISTEN 0 4096 %s 0.0.0.0:*\n' "${hostport}"
done <"${STUB_STATE_DIR}/listeners"
exit 0
SH;

    // Mailpit (8025) names a message's identifier "ID" and deletes by
    // {"IDs": [...]}; Mailtrap Local (3550) uses "id" and {"ids": [...]}. The
    // stub keeps the two dialects apart, so a cleanup that used the wrong key
    // would leave its messages behind.
    $curl = <<<'SH'
#!/usr/bin/env bash
set -uo pipefail
state_dir="${STUB_STATE_DIR}"
printf '%s\n' "curl $*" >>"${state_dir}/calls"

url="" output="" method="GET" data="" previous=""
for arg in "$@"; do
    case "${previous}" in
        --output) output="${arg}" ;;
        -X) method="${arg}" ;;
        -d) data="${arg}" ;;
    esac
    case "${arg}" in http://*|https://*) url="${arg}" ;; esac
    previous="${arg}"
done

if [[ -n "${output}" ]]; then
    served="${state_dir}/downloads/${url##*/}"
    [[ -f "${served}" ]] || exit 22
    cp "${served}" "${output}"
    exit 0
fi

base="${url%%/api/*}"
grep -Fq "${base}/" "${state_dir}/apis" 2>/dev/null || exit 7

port="${base##*:}"
store="${state_dir}/messages-${port}"
id_key="id"
[[ "${port}" != 8025 ]] || id_key="ID"

case "${method} ${url#"${base}"}" in
    "GET /api/v1/search?query="*)
        token="${url#*query=}"
        separator=""
        printf '{"messages":['
        if [[ -f "${store}" ]]; then
            while read -r id subject; do
                [[ "${subject}" == "${token}" ]] || continue
                printf '%s{"%s":"%s"}' "${separator}" "${id_key}" "${id}"
                separator=","
            done <"${store}"
        fi
        printf ']}\n'
        ;;
    "GET /api/v1/messages")
        if [[ -f "${state_dir}/messages-body-${port}" ]]; then
            cat "${state_dir}/messages-body-${port}"
            exit 0
        fi
        count=0
        [[ ! -f "${store}" ]] || count="$(wc -l <"${store}")"
        printf '{"messages_count":%d,"total":%d}\n' "${count}" "${count}"
        ;;
    "DELETE /api/v1/messages")
        printf '%s %s\n' "${port}" "${data}" >>"${state_dir}/deletions"
        ids=" $(jq -r ".${id_key}s[]?" <<<"${data}" 2>/dev/null | tr '\n' ' ') "
        if [[ -f "${store}" ]]; then
            while read -r id subject; do
                [[ "${ids}" == *" ${id} "* ]] || printf '%s %s\n' "${id}" "${subject}"
            done <"${store}" >"${store}.kept"
            mv "${store}.kept" "${store}"
        fi
        ;;
    *)
        printf '{}\n'
        ;;
esac
exit 0
SH;

    $nginx = <<<'SH'
#!/usr/bin/env bash
set -uo pipefail
printf '%s\n' "nginx $*" >>"${STUB_STATE_DIR}/calls"
if [[ -f "${STUB_STATE_DIR}/nginx_invalid" ]]; then
    printf 'nginx: configuration file test failed\n' >&2
    exit 1
fi
printf 'nginx: configuration file test is successful\n'
exit 0
SH;

    $journalctl = <<<'SH'
#!/usr/bin/env bash
set -uo pipefail
printf '%s\n' "journalctl $*" >>"${STUB_STATE_DIR}/calls"
unit="" previous=""
for arg in "$@"; do
    [[ "${previous}" != -u ]] || unit="${arg}"
    previous="${arg}"
done
cat "${STUB_STATE_DIR}/journal-${unit}" 2>/dev/null
exit 0
SH;

    $sleep = <<<'SH'
#!/usr/bin/env bash
printf '%s\n' "sleep $*" >>"${STUB_STATE_DIR}/calls"
exit 0
SH;

    foreach (['systemctl' => $systemctl, 'ss' => $ss, 'curl' => $curl, 'nginx' => $nginx, 'journalctl' => $journalctl, 'sleep' => $sleep] as $name => $body) {
        writeExecutable($bin.'/'.$name, rtrim($body, "\n")."\n");
    }

    foreach (['calls', 'listeners', 'apis'] as $file) {
        file_put_contents($state.'/'.$file, '');
    }

    return ['root' => $root, 'bin' => $bin, 'state' => $state];
}

/**
 * All four loopback listeners up and both HTTP APIs answering — what two
 * serving services look like from outside, whatever systemd says.
 */
function mailCaptureServingEndpoints(string $state): void
{
    file_put_contents(
        $state.'/listeners',
        "127.0.0.2:3535\n127.0.0.1:3550\n127.0.0.1:1025\n127.0.0.1:8025\n",
    );
    file_put_contents(
        $state.'/apis',
        "http://127.0.0.1:3550/api/v1/version\nhttp://127.0.0.1:8025/api/v1/info\n",
    );
}

/**
 * Record the state of two enabled, active, serving mail-capture services.
 */
function mailCaptureHealthyState(string $state): void
{
    foreach (['staging-mailtrap-local.service', 'staging-mailpit.service'] as $unit) {
        file_put_contents($state.'/'.$unit.'.active', 'active');
        file_put_contents($state.'/'.$unit.'.sub', 'running');
        file_put_contents($state.'/'.$unit.'.enabled', 'enabled');
        file_put_contents($state.'/'.$unit.'.nrestarts', '0');
    }

    mailCaptureServingEndpoints($state);
}

/**
 * Run a command with the workspace's stubs first on PATH.
 *
 * @param  list<string>  $command
 * @param  array<string, string>  $env
 * @return array{exit:int, output:string}
 */
function mailCaptureRun(array $workspace, array $command, array $env = []): array
{
    $environment = array_merge(getenv(), [
        'STUB_STATE_DIR' => $workspace['state'],
        'PATH' => $workspace['bin'].':'.(getenv('PATH') ?: '/usr/bin:/bin'),
        // Deterministic stubs: the bounded waits only need to be non-zero.
        'MAIL_CAPTURE_RUNTIME_WAIT' => '1',
        'MAIL_CAPTURE_STABILITY_WAIT' => '1',
    ], $env);

    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $workspace['root'], $environment);
    expect($process)->not->toBeFalse('could not start '.implode(' ', $command));

    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return ['exit' => proc_close($process), 'output' => rtrim($output, "\n")];
}

/** Every stubbed command the workspace has seen, in order. */
function mailCaptureCalls(array $workspace): array
{
    return array_values(array_filter(explode("\n", (string) file_get_contents($workspace['state'].'/calls'))));
}

/**
 * status-mail-capture against the workspace's stubs, reading the host files
 * from $fsRoot through the gated seam.
 *
 * @return array{exit:int, output:string}
 */
function mailCaptureStatus(array $workspace, string $fsRoot): array
{
    return mailCaptureRun($workspace, ['bash', infraScript('status-mail-capture')], [
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_MAILCAPTURE_FS_ROOT' => $fsRoot,
    ]);
}

/*
|--------------------------------------------------------------------------
| The mail routing CLI, as its tests and the mail gateway's tests drive it
|--------------------------------------------------------------------------
|
| MailRoutingPolicyTest proves the policy; MailGatewayTest renders Postfix
| from the plans it produces. Both run the shipped mail-routing script the
| same way, with the same synthetic demo-shop target, so the runner lives
| here once.
*/

function mailRoutingScript(): string
{
    return base_path('infrastructure/scripts/mail-routing');
}

/**
 * A synthetic production brand's policy. Its identity, its domains and its port
 * appear nowhere in the shipped implementation or the committed configuration.
 *
 * @return array<string, mixed>
 */
function mailRoutingDemoShopPolicy(): array
{
    return [
        'submission' => ['host' => '127.0.0.1', 'port' => 2599],
        'delivery_mode' => 'held',
        'mail_domain' => 'demo-shop.example',
        'default_from' => 'hello@demo-shop.example',
        'bounce_domain' => 'bounce.demo-shop.example',
        'reply_domain' => 'reply.demo-shop.example',
    ];
}

/**
 * The synthetic production brand's policy, delivered outbound by direct SMTP:
 * the same reviewed identity as its held policy, plus its transport kind.
 *
 * @return array<string, mixed>
 */
function mailRoutingDemoShopOutboundPolicy(): array
{
    return [
        ...mailRoutingDemoShopPolicy(),
        'delivery_mode' => 'outbound',
        'outbound' => ['kind' => 'direct'],
    ];
}

/*
|--------------------------------------------------------------------------
| The mail identity CLI and the material it judges
|--------------------------------------------------------------------------
|
| MailIdentityTest proves infrastructure/scripts/mail-identity itself;
| InstallTargetPrerequisitesTest installs the DKIM keys it judges, and
| ConfigureRateGuruTargetActionTest stages them. All three need the same real
| keys and the same synthetic demo-shop identity, so they live here once.
*/

function mailIdentityScript(): string
{
    return base_path('infrastructure/scripts/mail-identity');
}

/**
 * A real key of one kind, generated once per test process by OpenSSL itself, so
 * every verdict is checked against genuine material rather than a shape a test
 * invented. Kinds: rsa2048, rsa2048-pkcs1, rsa3072, rsa1024, ec, ed25519,
 * encrypted, public, certificate, key-and-certificate, junk.
 */
function mailIdentityKey(string $kind): string
{
    static $dir = null;

    if ($dir === null) {
        // Removed when the test process ends, with every key made into it.
        $dir = makeScratchDir('mail-identity-keys', [''], 0o700);
        register_shutdown_function(static fn () => removeScratchDir($dir));
    }

    $path = "{$dir}/{$kind}.pem";

    if (is_file($path)) {
        return $path;
    }

    $openssl = static function (string $arguments) use ($kind): void {
        exec('openssl '.$arguments.' 2>&1', $output, $status);
        expect($status)->toBe(0, "openssl could not make the {$kind} test key:\n".implode("\n", $output));
    };

    $rsa = static fn (int $bits, string $to) => $openssl('genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:'.$bits.' -out '.escapeshellarg($to));

    match ($kind) {
        'rsa2048' => $rsa(2048, $path),
        'rsa3072' => $rsa(3072, $path),
        'rsa1024' => $rsa(1024, $path),
        'rsa2048-pkcs1' => $openssl('rsa -in '.escapeshellarg(mailIdentityKey('rsa2048')).' -traditional -out '.escapeshellarg($path)),
        'ec' => $openssl('genpkey -algorithm EC -pkeyopt ec_paramgen_curve:P-256 -out '.escapeshellarg($path)),
        'ed25519' => $openssl('genpkey -algorithm ED25519 -out '.escapeshellarg($path)),
        'encrypted' => $openssl('genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -aes256 -pass pass:a-passphrase -out '.escapeshellarg($path)),
        'public' => $openssl('pkey -in '.escapeshellarg(mailIdentityKey('rsa2048')).' -pubout -out '.escapeshellarg($path)),
        'certificate' => $openssl('req -x509 -key '.escapeshellarg(mailIdentityKey('rsa2048')).' -subj /CN=mail-identity-test -days 1 -out '.escapeshellarg($path)),
        'key-and-certificate' => file_put_contents($path, file_get_contents(mailIdentityKey('rsa2048')).file_get_contents(mailIdentityKey('certificate'))),
        'junk' => file_put_contents($path, "this is not a key\n"),
    };

    chmod($path, 0o600);

    return $path;
}

/**
 * The base64 SubjectPublicKeyInfo of a private key, derived by PHP's own
 * OpenSSL binding — an independent witness for what mail-identity derives.
 */
function mailIdentityPublicKey(string $privateKeyPath): string
{
    $key = openssl_pkey_get_private((string) file_get_contents($privateKeyPath));
    expect($key)->not->toBeFalse('PHP could not read the test key');

    $pem = openssl_pkey_get_details($key)['key'];

    return preg_replace('/-----[^-]+-----|\s+/', '', $pem);
}

function mailIdentityScratch(): string
{
    return makeScratchDir('mail-identity', ['', '/bin', '/fs', '/dns', '/config']);
}

/**
 * Stubs for dig and ip, and the environment that points mail-identity at them
 * and at the scratch filesystem root.
 *
 * $answers maps "TYPE name" to a list of rdata strings exactly as dig prints
 * them, or to ['exit' => N] for a resolver that never answered, or to
 * ['status' => 'SERVFAIL', ...rdata] for one that answered with a failure. A
 * name with no entry is NXDOMAIN. $ipv4 is the source address of this host's
 * route to the Internet; null means the host has no route at all.
 *
 * Such an answer is public DNS: both public resolvers, 1.1.1.1 and 8.8.8.8,
 * give it — and so does the host's own resolver, the one a query without an
 * `@server` reaches. "@RESOLVER TYPE name" replaces the answer of that one
 * resolver alone, where RESOLVER is 1.1.1.1, 8.8.8.8 or `default` for the
 * host's own; "@RESOLVER *" answers every question put to it. Any other
 * `@server` is refused, and every query is logged to dns/queries.log as
 * "RESOLVER TYPE name". With STUB_DIG_IGNORES_SERVER set, dig drops the
 * `@server` it was given and asks the host's own resolver, the way code that
 * never named one would.
 *
 * The same environment stands in for the signing verifier readiness asks
 * (verify-mail-signing --read-only): on this simulated host no signer is
 * installed, so it refuses, until mailIdentitySigningVerdict() says otherwise.
 *
 * @param  array<string, list<string>|array<string, mixed>>  $answers
 * @return array<string, string>
 */
function mailIdentityDnsHost(string $scratch, array $answers, ?string $ipv4 = '203.0.113.10'): array
{
    // Each call states the whole DNS: no answer from an earlier call survives
    // it. The query log does.
    foreach (glob("{$scratch}/dns/*") ?: [] as $earlier) {
        if (basename($earlier) !== 'queries.log') {
            is_dir($earlier) ? File::deleteDirectory($earlier) : unlink($earlier);
        }
    }

    foreach ($answers as $question => $answer) {
        $dir = "{$scratch}/dns";

        if (str_starts_with($question, '@')) {
            [$resolver, $question] = explode(' ', substr($question, 1), 2);
            expect(['1.1.1.1', '8.8.8.8', 'default'])->toContain($resolver);

            $dir .= "/{$resolver}";
            @mkdir($dir, 0o755, true);
        }

        [$type, $name] = explode(' ', $question, 2) + [1 => ''];

        $lines = isset($answer['exit'])
            ? ["exit {$answer['exit']}"]
            : ['status '.($answer['status'] ?? 'NOERROR'), ...array_values(array_filter($answer, 'is_int', ARRAY_FILTER_USE_KEY))];

        file_put_contents($dir.'/'.($type === '*' ? '_every_question' : "{$type}_{$name}"), implode("\n", $lines)."\n");
    }

    file_put_contents($scratch.'/bin/dig', <<<'STUB'
        #!/bin/bash
        type="${@: -2:1}"
        name="${@: -1}"
        resolver=default
        for argument in "$@"; do
            case "${argument}" in
                @*)
                    [[ "${resolver}" == default ]] || { echo ';; stub dig: more than one @server' >&2; exit 64; }
                    resolver="${argument#@}"
                    ;;
            esac
        done
        [[ -z "${STUB_DIG_IGNORES_SERVER:-}" ]] || resolver=default
        printf '%s %s %s\n' "${resolver}" "${type}" "${name}" >> "${STUB_DNS}/queries.log"
        case "${resolver}" in
            1.1.1.1|8.8.8.8|default) ;;
            *) echo ";; stub dig: no such resolver in this test: ${resolver}" >&2; exit 64 ;;
        esac
        answer="${STUB_DNS}/${type}_${name}"
        [[ ! -f "${STUB_DNS}/${resolver}/${type}_${name}" ]] || answer="${STUB_DNS}/${resolver}/${type}_${name}"
        [[ ! -f "${STUB_DNS}/${resolver}/_every_question" ]] || answer="${STUB_DNS}/${resolver}/_every_question"
        status=NXDOMAIN
        if [[ -f "${answer}" ]]; then
            first="$(head -n 1 "${answer}")"
            case "${first}" in
                exit\ *) echo ';; connection timed out; no servers could be reached'; exit "${first#exit }" ;;
                status\ *) status="${first#status }" ;;
            esac
        fi
        printf ';; Got answer:\n;; ->>HEADER<<- opcode: QUERY, status: %s, id: 4242\n;; flags: qr rd ra; QUERY: 1, ANSWER: 1, AUTHORITY: 0, ADDITIONAL: 1\n\n;; OPT PSEUDOSECTION:\n; EDNS: version: 0, flags:; udp: 1232\n;; ANSWER SECTION:\n' "${status}"
        if [[ -f "${answer}" ]]; then
            tail -n +2 "${answer}" | while IFS= read -r rdata; do
                [[ -n "${rdata}" ]] && printf '%s.\t300\tIN\t%s\t%s\n' "${name}" "${type}" "${rdata}"
            done
        fi
        exit 0
        STUB."\n");

    $route = $ipv4 === null
        ? "#!/bin/bash\necho 'RTNETLINK answers: Network is unreachable' >&2\nexit 2\n"
        : "#!/bin/bash\nprintf '1.1.1.1 via 203.0.113.1 dev eth0 src %s uid 0\\n    cache\\n' '{$ipv4}'\n";
    file_put_contents($scratch.'/bin/ip', $route);

    file_put_contents($scratch.'/bin/verify-mail-signing', <<<'STUB'
        #!/bin/bash
        printf '%s\n' "$*" >> "${STUB_SIGNING}/calls.log"
        if [[ "$(cat "${STUB_SIGNING}/verdict" 2>/dev/null)" == pass ]]; then
            echo "  PASS the signer and the gateway's wiring of this target (simulated)"
            exit 0
        fi
        echo "  FAIL the signer: install-mail-signing --verify --target ${3:-} (exit 1) — no signer on this simulated host"
        exit 1
        STUB."\n");

    @mkdir($scratch.'/signing', 0o755, true);

    chmod($scratch.'/bin/dig', 0o755);
    chmod($scratch.'/bin/ip', 0o755);
    chmod($scratch.'/bin/verify-mail-signing', 0o755);

    return [
        'RATEGURU_MAILIDENTITY_DIG_BIN' => $scratch.'/bin/dig',
        'RATEGURU_MAILIDENTITY_IP_BIN' => $scratch.'/bin/ip',
        'RATEGURU_MAILIDENTITY_FS_ROOT' => $scratch.'/fs',
        'RATEGURU_MAILIDENTITY_SIGNING_VERIFIER_BIN' => $scratch.'/bin/verify-mail-signing',
        'STUB_DNS' => $scratch.'/dns',
        'STUB_SIGNING' => $scratch.'/signing',
    ];
}

/**
 * What the simulated signing verifier of mailIdentityDnsHost() answers: pass
 * when a signer and its wiring would verify, refuse otherwise.
 */
function mailIdentitySigningVerdict(string $scratch, bool $passes): void
{
    @mkdir($scratch.'/signing', 0o755, true);
    file_put_contents($scratch.'/signing/verdict', $passes ? "pass\n" : "fail\n");
}

/**
 * Every call the simulated signing verifier received, as its argument list.
 *
 * @return list<string>
 */
function mailIdentitySigningCalls(string $scratch): array
{
    return array_values(array_filter(explode("\n", (string) @file_get_contents($scratch.'/signing/calls.log'))));
}

/**
 * Every query the dig stub of mailIdentityDnsHost() received, in order, as
 * "RESOLVER TYPE name".
 *
 * @return list<string>
 */
function mailIdentityDnsQueries(string $scratch): array
{
    return array_values(array_filter(explode("\n", (string) @file_get_contents($scratch.'/dns/queries.log'))));
}

/** Install KIND as TARGET's key for SELECTOR under the scratch root. */
function mailIdentityInstallKey(string $scratch, string $target, string $selector, string $kind = 'rsa2048'): string
{
    $dir = "{$scratch}/fs/etc/opendkim/keys/{$target}";
    @mkdir($dir, 0o700, true);
    copy(mailIdentityKey($kind), "{$dir}/{$selector}.private");
    chmod("{$dir}/{$selector}.private", 0o600);

    return "{$dir}/{$selector}.private";
}

/**
 * The DNS a correctly published tits.guru answers, with the DKIM value split
 * into the chunks a DNS provider serves a long TXT record in.
 *
 * @return array<string, list<string>>
 */
function mailIdentityGoodDns(string $publicKey, string $ipv4 = '203.0.113.10', string $domain = 'tits.guru', string $selector = 'rg1', string $mta = 'mta1.tits.guru'): array
{
    $octets = explode('.', $ipv4);
    $chunks = str_split('v=DKIM1; k=rsa; p='.$publicKey, 200);

    return [
        "A {$mta}" => [$ipv4],
        'PTR '.implode('.', array_reverse($octets)).'.in-addr.arpa' => ["{$mta}."],
        "TXT {$domain}" => ["\"v=spf1 ip4:{$ipv4} -all\"", '"site-verification=abc123"'],
        "TXT {$selector}._domainkey.{$domain}" => ['"'.implode('" "', $chunks).'"'],
        "TXT _dmarc.{$domain}" => ['"v=DMARC1; p=none; adkim=s; aspf=s"'],
    ];
}

/** The base64 body lines of a PEM file — what must never appear in any output. */
function mailIdentityKeyBodyLines(string $path): array
{
    return array_values(array_filter(
        preg_split('/\R/', (string) file_get_contents($path)),
        static fn (string $line): bool => $line !== '' && ! str_starts_with($line, '-----'),
    ));
}

/**
 * No line of the key file reached OUTPUT. The public modulus is the one part a
 * private key shares with its public key — and a PKCS#1 key encodes it at the
 * same base64 alignment as the published DKIM value — so a line that is part of
 * the public key is public, and is skipped.
 */
function expectNoKeyMaterial(string $output, string $keyPath): void
{
    $public = openssl_pkey_get_private((string) file_get_contents($keyPath)) === false
        ? ''
        : mailIdentityPublicKey($keyPath);

    foreach (mailIdentityKeyBodyLines($keyPath) as $line) {
        $probe = substr($line, 0, 24);

        if ($public !== '' && str_contains($public, $probe)) {
            continue;
        }

        expect(str_contains($output, $probe))->toBeFalse('private key material reached the output');
    }

    expect($output)->not->toContain('PRIVATE KEY');
}

/**
 * The four reviewed configuration files mail identity is judged from, written
 * into DIR: the committed ones, plus — unless `demo-shop` is false — the
 * synthetic demo-shop target in the registry, in mail routing (`mode` held or
 * outbound), and in mail identity (`identity`, or none when null). `policy`
 * ({routing, outbound}, such as mailPreActivationPolicy()) stands in for the
 * committed routing policy and host contract; `outbound` replaces the host
 * contract. Returns the matching FILES arguments.
 *
 * @param  array{demo-shop?: bool, mode?: string, identity?: array<string, mixed>|null, policy?: array{routing: array<string, mixed>, outbound: array<string, mixed>}, outbound?: array<string, mixed>, registry?: array<string, mixed>}  $options
 * @return list<string>
 */
function mailIdentityFixtureConfig(string $dir, array $options = []): array
{
    @mkdir($dir, 0o755, true);

    $read = static fn (string $name): array => json_decode(File::get(base_path("infrastructure/config/{$name}")), true, 512, JSON_THROW_ON_ERROR);

    $registry = $options['registry'] ?? $read('deployment-targets.json');
    $routing = $options['policy']['routing'] ?? $read('mail-routing.json');
    $identity = $read('mail-identity.json');
    $outbound = $options['outbound'] ?? $options['policy']['outbound'] ?? $read('mail-outbound.json');

    if ($options['demo-shop'] ?? true) {
        if (! isset($options['registry'])) {
            $registry = mailRoutingDemoShopRegistry();
        }

        $routing['targets']['demo-shop'] = ($options['mode'] ?? 'held') === 'outbound'
            ? mailRoutingDemoShopOutboundPolicy()
            : mailRoutingDemoShopPolicy();

        $demoIdentity = array_key_exists('identity', $options) ? $options['identity'] : mailIdentityDemoShopIdentity();

        if ($demoIdentity !== null) {
            $identity['targets']['demo-shop'] = $demoIdentity;
        }
    }

    foreach (['deployment-targets' => $registry, 'mail-routing' => $routing, 'mail-identity' => $identity, 'mail-outbound' => $outbound] as $name => $data) {
        file_put_contents("{$dir}/{$name}.json", mailRoutingJson($data));
    }

    return [
        '--identity', "{$dir}/mail-identity.json",
        '--routing', "{$dir}/mail-routing.json",
        '--registry', "{$dir}/deployment-targets.json",
        '--outbound', "{$dir}/mail-outbound.json",
    ];
}

/** @return array<string, mixed> */
function mailIdentityDemoShopIdentity(): array
{
    return [
        'dkim' => ['selector' => 'shop2026', 'algorithm' => 'rsa-sha256', 'minimum_key_bits' => 2048],
        'dmarc' => ['policy' => 'none', 'adkim' => 'strict', 'aspf' => 'strict'],
    ];
}

/**
 * Run the shipped mail-identity CLI with the test overrides on. stdout and
 * stderr are kept apart: a verdict must never smuggle key material into either.
 *
 * @param  list<string>  $arguments
 * @param  array<string, string>  $environment
 * @return array{status: int, stdout: string, stderr: string}
 */
function mailIdentityRun(array $arguments, array $environment = []): array
{
    $process = proc_open(
        ['bash', mailIdentityScript(), ...$arguments],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        [
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'HOME' => sys_get_temp_dir(),
            'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
            ...$environment,
        ],
    );

    expect($process)->not->toBeFalse('could not start mail-identity');

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['status' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

/**
 * Two synthetic production brands delivered outbound by direct SMTP, beside the
 * committed targets — tits-guru among them, outbound too: demo-shop on 2599
 * and demo-books on 2598, each with its own registry entry and its own
 * identity. Neither appears in any committed file or in the implementation.
 *
 * @return array{policy: array<string, mixed>, registry: array<string, mixed>}
 */
function mailRoutingTwoOutboundTargets(): array
{
    $rename = static fn (array $data): array => json_decode(
        str_replace(['demo-shop', 'demo_shop'], ['demo-books', 'demo_books'], json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $registry = mailRoutingDemoShopRegistry();
    $registry['targets']['demo-books'] = $rename(provisionDemoTarget());

    $policy = json_decode(File::get(base_path('infrastructure/config/mail-routing.json')), true, 512, JSON_THROW_ON_ERROR);
    $policy['targets']['demo-shop'] = mailRoutingDemoShopOutboundPolicy();
    $policy['targets']['demo-books'] = $rename(mailRoutingDemoShopOutboundPolicy());
    $policy['targets']['demo-books']['submission']['port'] = 2598;

    return ['policy' => $policy, 'registry' => $registry];
}

/**
 * The committed registry plus the synthetic demo-shop target.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function mailRoutingDemoShopRegistry(array $overrides = []): array
{
    return json_decode(provisionRegistryJson($overrides), true, 512, JSON_THROW_ON_ERROR);
}

function mailRoutingJson(array $data): string
{
    return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
}

/**
 * Run the shipped CLI. A policy or registry given here is written to a scratch
 * file and passed with --file / --registry; one left null is the committed
 * file, reached through the script's own defaults. A string policy is written
 * verbatim, for documents that are not a valid policy to begin with.
 *
 * stdout and stderr are kept apart: a refusal must print nothing on stdout,
 * and a plan must be nothing but JSON.
 *
 * @param  list<string>  $arguments
 * @param  array<string, mixed>|string|null  $policy
 * @param  array<string, mixed>|null  $registry
 * @return array{status: int, stdout: string, stderr: string}
 */
function mailRoutingRun(array $arguments, array|string|null $policy = null, ?array $registry = null, ?string $script = null): array
{
    $scratch = sys_get_temp_dir().'/mail-routing-'.bin2hex(random_bytes(6));

    expect(@mkdir($scratch, 0o755, true))->toBeTrue("could not create scratch directory: {$scratch}");

    try {
        if ($policy !== null) {
            file_put_contents($scratch.'/mail-routing.json', is_string($policy) ? $policy : mailRoutingJson($policy));
            $arguments = [...$arguments, '--file', $scratch.'/mail-routing.json'];
        }

        if ($registry !== null) {
            file_put_contents($scratch.'/deployment-targets.json', mailRoutingJson($registry));
            $arguments = [...$arguments, '--registry', $scratch.'/deployment-targets.json'];
        }

        $process = proc_open(
            ['bash', $script ?? mailRoutingScript(), ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $scratch,
            ['PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'HOME' => $scratch],
        );

        expect($process)->not->toBeFalse('could not start mail-routing');

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['status' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
}

/**
 * The rendered plan, decoded — after proving the render succeeded cleanly.
 *
 * @param  array<string, mixed>|string|null  $policy
 * @param  array<string, mixed>|null  $registry
 * @return array<string, mixed>
 */
function mailRoutingPlan(array|string|null $policy = null, ?array $registry = null): array
{
    return json_decode(mailRoutingPlanJson($policy, $registry), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * The rendered plan exactly as the CLI printed it on stdout — after proving the
 * render succeeded and wrote nothing on stderr, so a diagnostic can never end
 * up inside a plan file a test hands on.
 *
 * @param  array<string, mixed>|string|null  $policy
 * @param  array<string, mixed>|null  $registry
 */
function mailRoutingPlanJson(array|string|null $policy = null, ?array $registry = null): string
{
    $run = mailRoutingRun(['render-plan'], $policy, $registry);

    expect($run['status'])->toBe(0, "render-plan failed:\n".$run['stderr']);
    expect($run['stderr'])->toBe('', 'render-plan wrote diagnostics on success');

    return $run['stdout'];
}

/*
|--------------------------------------------------------------------------
| The simulated mail gateway host
|--------------------------------------------------------------------------
|
| install-mail-gateway runs for real against FS_ROOT and stubs for its
| package manager, systemd, ss and Postfix's own tools. MailGatewayTest
| proves the installer on it; MailOutboundActivationTest and MailCanaryTest
| run the activation and the canary against the same real installer on it.
| The postconf stub reads back what the rendered files say; it is a test
| double, not Postfix.
*/

function mailGatewayScript(string $name = 'install-mail-gateway'): string
{
    return base_path('infrastructure/scripts/'.$name);
}

function mailGatewayScratch(): string
{
    $dir = sys_get_temp_dir().'/mail-gateway-'.bin2hex(random_bytes(6));

    foreach (['', '/bin', '/fs', '/log', '/state', '/toggles'] as $sub) {
        expect(@mkdir($dir.$sub, 0o755, true))->toBeTrue("could not create {$dir}{$sub}");
    }

    return $dir;
}

/**
 * A simulated host and the stubs that stand in for its package manager,
 * systemd, ss and Postfix's own tools.
 *
 * Options:
 *   package        'absent' (default) | 'installed' | 'partial'
 *   marker         null (default) | 'installing' | 'installed'
 *   postfixDir     true to create /etc/postfix with no package (an unmanaged leftover)
 *   otherMta       a package name providing mail-transport-agent
 *   ownPolicyRc    true to give the host its own /usr/sbin/policy-rc.d
 *   listeners      extra TCP listeners on the host (default: Mailpit's 127.0.0.1:1025)
 *   policy         a mail routing policy (default: the pre-activation one,
 *                  mailPreActivationPolicy() — tits-guru held; null: none, so
 *                  the installer reads its own bundle's)
 *   registry       a deployment registry instead of the committed one
 *   outbound       a host outbound contract (default: the pre-activation one —
 *                  direct delivery disabled; null: its own bundle's)
 *   identity       a mail identity contract instead of the committed one
 *   signer         false to leave the DKIM signer's endpoint unheard on the host
 *
 * The signer's endpoint is the one the real install-mail-signing prints, and by
 * default something listens on it, as on a host where the signer is installed.
 *
 * Its bundle requests the pre-activation documents unless told otherwise: a
 * gateway's first recorded policy is always the inert one, and only
 * activate-mail-outbound crosses from it to the committed request
 * (mailCommittedPolicy()), which a test passes explicitly.
 *
 * @return array{scratch: string, fs: string, env: array<string, string>}
 */
function mailGatewayHost(array $options = []): array
{
    if (! array_key_exists('policy', $options)) {
        $options['policy'] = mailPreActivationPolicy()['routing'];
    }

    if (! array_key_exists('outbound', $options)) {
        $options['outbound'] = mailPreActivationPolicy()['outbound'];
    }

    $scratch = mailGatewayScratch();
    $fs = $scratch.'/fs';
    $state = $scratch.'/state';

    $package = $options['package'] ?? 'absent';
    if ($package === 'installed') {
        file_put_contents($state.'/pkg-status', 'install ok installed');
        file_put_contents($state.'/pkg-version', '3.6.4-1ubuntu1.4');
        touch($state.'/units-exist');
        touch($state.'/postfix.service.enabled');
        @mkdir($fs.'/etc/postfix', 0o755, true);
        file_put_contents($fs.'/etc/postfix/main.cf', "# a Postfix main.cf\n");
        file_put_contents($fs.'/etc/postfix/master.cf', "smtp      inet  n       -       y       -       -       smtpd\n");
    } elseif ($package === 'partial') {
        file_put_contents($state.'/pkg-status', 'install ok half-configured');
    }

    if (($options['marker'] ?? null) !== null) {
        @mkdir($fs.'/var/lib/rateguru-mail-gateway', 0o755, true);
        file_put_contents($fs.'/var/lib/rateguru-mail-gateway/ownership', "owner=rateguru\ncomponent=mail-gateway\nstate={$options['marker']}\n");
    }

    if ($options['postfixDir'] ?? false) {
        @mkdir($fs.'/etc/postfix', 0o755, true);
        file_put_contents($fs.'/etc/postfix/main.cf', "# somebody else's\n");
    }

    $packages = "bash\tinstall ok installed\t\n";
    if (isset($options['otherMta'])) {
        $packages .= "{$options['otherMta']}\tinstall ok installed\tmail-transport-agent\n";
    }
    file_put_contents($state.'/packages.tsv', $packages);

    if ($options['ownPolicyRc'] ?? false) {
        @mkdir($fs.'/usr/sbin', 0o755, true);
        file_put_contents($fs.'/usr/sbin/policy-rc.d', "#!/bin/sh\n# the host's own\nexit 101\n");
        chmod($fs.'/usr/sbin/policy-rc.d', 0o755);
    }

    $listeners = $options['listeners'] ?? ['127.0.0.1:1025'];
    if ($options['signer'] ?? true) {
        $listeners[] = '127.0.0.1:8891';
    }
    file_put_contents($state.'/listeners', implode("\n", $listeners)."\n");

    $stubs = [
        'dpkg-query' => <<<'STUB'
            #!/bin/bash
            if [[ "$*" == *'${Package}'* ]]; then cat "${STUB_STATE}/packages.tsv"; exit 0; fi
            if [[ "$*" == *'${Version}'* ]]; then cat "${STUB_STATE}/pkg-version" 2>/dev/null; exit 0; fi
            if [[ -f "${STUB_STATE}/pkg-status" ]]; then cat "${STUB_STATE}/pkg-status"; exit 0; fi
            echo "dpkg-query: no packages found matching postfix" >&2
            exit 1
            STUB,
        'apt-get' => <<<'STUB'
            #!/bin/bash
            printf 'apt-get %s [DEBIAN_FRONTEND=%s]\n' "$*" "${DEBIAN_FRONTEND:-}" >> "${STUB_LOG}/mutations.log"
            [[ "$1" == install ]] || exit 0
            policy="${STUB_FS}/usr/sbin/policy-rc.d"
            if [[ -f "${policy}" ]] && grep -q 'rateguru-mail-gateway' "${policy}" && grep -qx 'exit 101' "${policy}"; then
                echo "service starts suppressed during install" >> "${STUB_LOG}/apt-suppression.log"
            else
                echo "service starts NOT suppressed during install" >> "${STUB_LOG}/apt-suppression.log"
            fi
            [[ -e "${STUB_TOGGLES}/apt-fail" ]] && exit 100
            printf 'install ok installed' > "${STUB_STATE}/pkg-status"
            printf '3.6.4-1ubuntu1.4' > "${STUB_STATE}/pkg-version"
            # Preseeded "No configuration": the package installs its master.cf
            # and writes no main.cf of its own.
            mkdir -p "${STUB_FS}/etc/postfix"
            printf 'smtp      inet  n       -       y       -       -       smtpd\n' > "${STUB_FS}/etc/postfix/master.cf"
            touch "${STUB_STATE}/units-exist" "${STUB_STATE}/postfix.service.enabled"
            STUB,
        'dpkg' => <<<'STUB'
            #!/bin/bash
            printf 'dpkg %s\n' "$*" >> "${STUB_LOG}/mutations.log"
            STUB,
        'debconf-set-selections' => <<<'STUB'
            #!/bin/bash
            echo "debconf-set-selections" >> "${STUB_LOG}/mutations.log"
            cat >> "${STUB_LOG}/debconf.log"
            STUB,
        'systemctl' => <<<'STUB'
            #!/bin/bash
            S="${STUB_STATE}"
            case "$1" in
                is-enabled|show) printf 'systemctl %s\n' "$*" >> "${STUB_LOG}/reads.log" ;;
                *) printf 'systemctl %s\n' "$*" >> "${STUB_LOG}/mutations.log" ;;
            esac
            case "$1" in
                is-enabled)
                    [[ -e "${S}/units-exist" ]] || { echo "Failed to get unit file state for $2" >&2; exit 1; }
                    if [[ -e "${S}/$2.enabled" ]]; then echo enabled; exit 0; fi
                    echo disabled; exit 1 ;;
                show)
                    case "$3" in
                        --property=ActiveState) [[ -e "${S}/$2.active" ]] && echo active || echo inactive ;;
                        --property=SubState) [[ -e "${S}/$2.active" ]] && echo running || echo dead ;;
                        --property=NRestarts) cat "${S}/$2.nrestarts" 2>/dev/null || echo 0 ;;
                    esac
                    exit 0 ;;
                enable) touch "${S}/$2.enabled" ;;
                disable) rm -f "${S}/$2.enabled" ;;
                mask) touch "${S}/$2.masked" ;;
                start|restart)
                    [[ -e "${STUB_TOGGLES}/start-fail" ]] && exit 1
                    touch "${S}/$2.active" ;;
                stop) rm -f "${S}/$2.active" ;;
                reload) [[ -e "${S}/$2.active" ]] || exit 1 ;;
            esac
            exit 0
            STUB,
        'ss' => <<<'STUB'
            #!/bin/bash
            sed '/^$/d; s/^/LISTEN 0 100 /; s/$/ 0.0.0.0:*/' "${STUB_STATE}/listeners"
            if [[ -e "${STUB_STATE}/postfix@-.service.active" && -f "${STUB_FS}/etc/postfix/master.cf" ]]; then
                awk '/^[^#[:space:]]/ && $2 == "inet" { print "LISTEN 0 100 " $1 " 0.0.0.0:*" }' "${STUB_FS}/etc/postfix/master.cf"
            fi
            STUB,
        // A test double that reads the files back, not Postfix.
        'postconf' => <<<'STUB'
            #!/bin/bash
            printf 'postconf %s\n' "$*" >> "${STUB_LOG}/reads.log"
            dir="${STUB_FS}/etc/postfix"
            if [[ "$1" == -c ]]; then dir="$2"; shift 2; fi
            case "$1" in
                -n)
                    [[ -e "${STUB_TOGGLES}/postconf-warn" ]] && echo "postconf: warning: simulated unused parameter" >&2
                    exit 0 ;;
                -M)
                    [[ -e "${STUB_TOGGLES}/postconf-fatal" ]] && { echo "postconf: fatal: bad field count" >&2; exit 1; }
                    awk '/^[^#[:space:]]/ { print $1, $2, $3, $4, $5, $6, $7, $8 }' "${dir}/master.cf"
                    exit 0 ;;
                -h)
                    awk -v k="$2" 'index($0, k " = ") == 1 { v = substr($0, length(k) + 4) } $0 == k " =" { v = "" } END { print v }' "${dir}/main.cf"
                    exit 0 ;;
                -P)
                    spec="$2"; svc="${spec%%/*}"; rest="${spec#*/}"; type="${rest%%/*}"; param="${rest#*/}"
                    awk -v s="${svc}" -v t="${type}" -v p="${param}" -v spec="${spec}" '
                        /^[^#[:space:]]/ { inside = ($1 == s && $2 == t); next }
                        inside && $1 == "-o" && index($2, p "=") == 1 { print spec " = " substr($2, length(p) + 2) }
                    ' "${dir}/master.cf"
                    exit 0 ;;
            esac
            STUB,
        'postfix' => <<<'STUB'
            #!/bin/bash
            printf 'postfix %s\n' "$*" >> "${STUB_LOG}/reads.log"
            [[ -e "${STUB_TOGGLES}/postfix-check-fail" ]] && { echo "postfix: fatal: simulated" >&2; exit 1; }
            exit 0
            STUB,
        // A regexp-table lookup the way postmap -q answers one: the result of
        // the first pattern that matches, case-insensitive unless the pattern
        // carries the i flag. A test double, not Postfix.
        'postmap' => <<<'STUB'
            #!/bin/bash
            [[ "$1" == -c ]] && shift 2
            [[ "$1" == -q && "${3:-}" == regexp:* ]] || exit 64
            key="$2"; table="${3#regexp:}"
            [[ -f "${table}" ]] || { echo "postmap: fatal: open ${table}: No such file or directory" >&2; exit 1; }
            while IFS= read -r line; do
                [[ -z "${line}" || "${line}" == \#* ]] && continue
                delim="${line:0:1}"; rest="${line:1}"
                pattern="${rest%%"${delim}"*}"; after="${rest#*"${delim}"}"
                flags="${after%% *}"; result="${after#* }"
                if [[ "${flags}" == *i* ]]; then shopt -u nocasematch; else shopt -s nocasematch; fi
                if [[ "${key}" =~ ${pattern} ]]; then printf '%s\n' "${result}"; exit 0; fi
            done < "${table}"
            exit 1
            STUB,
    ];

    foreach ($stubs as $name => $body) {
        file_put_contents($scratch.'/bin/'.$name, $body."\n");
        chmod($scratch.'/bin/'.$name, 0o755);
    }

    $env = [
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'HOME' => $scratch,
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_MAILGW_EUID' => '0',
        'RATEGURU_MAILGW_FS_ROOT' => $fs,
        'RATEGURU_MAILGW_FILE_OWNER' => trim((string) shell_exec('id -un')),
        'RATEGURU_MAILGW_FILE_GROUP' => trim((string) shell_exec('id -gn')),
        'RATEGURU_MAILGW_RUNTIME_WAIT' => '1',
        'RATEGURU_MAILGW_STABILITY_WAIT' => '1',
        'RATEGURU_MAILGW_SYSTEMCTL_BIN' => $scratch.'/bin/systemctl',
        'RATEGURU_MAILGW_APT_GET_BIN' => $scratch.'/bin/apt-get',
        'RATEGURU_MAILGW_DPKG_BIN' => $scratch.'/bin/dpkg',
        'RATEGURU_MAILGW_DPKG_QUERY_BIN' => $scratch.'/bin/dpkg-query',
        'RATEGURU_MAILGW_DEBCONF_SET_SELECTIONS_BIN' => $scratch.'/bin/debconf-set-selections',
        'RATEGURU_MAILGW_POSTCONF_BIN' => $scratch.'/bin/postconf',
        'RATEGURU_MAILGW_POSTFIX_BIN' => $scratch.'/bin/postfix',
        'RATEGURU_MAILGW_POSTMAP_BIN' => $scratch.'/bin/postmap',
        'RATEGURU_MAILGW_SS_BIN' => $scratch.'/bin/ss',
        'STUB_STATE' => $state,
        'STUB_LOG' => $scratch.'/log',
        'STUB_TOGGLES' => $scratch.'/toggles',
        'STUB_FS' => $fs,
    ];

    foreach (['policy' => 'RATEGURU_MAILGW_POLICY_FILE', 'registry' => 'RATEGURU_MAILGW_REGISTRY_FILE', 'outbound' => 'RATEGURU_MAILGW_OUTBOUND_FILE', 'identity' => 'RATEGURU_MAILGW_IDENTITY_FILE'] as $option => $variable) {
        if (isset($options[$option])) {
            file_put_contents($scratch."/{$option}.json", mailRoutingJson($options[$option]));
            $env[$variable] = $scratch."/{$option}.json";
        }
    }

    return ['scratch' => $scratch, 'fs' => $fs, 'env' => $env];
}

/** @return array{0: int, 1: string} */
function mailGatewayRun(array $host, string $mode, array $env = [], string $script = 'install-mail-gateway'): array
{
    $process = proc_open(
        ['bash', mailGatewayScript($script), $mode],
        [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
        $pipes,
        $host['scratch'],
        [...$host['env'], ...$env],
    );

    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return [proc_close($process), $output];
}

function mailGatewayLog(array $host, string $name): string
{
    $path = $host['scratch'].'/log/'.$name;

    return is_file($path) ? (string) file_get_contents($path) : '';
}

/**
 * Every path and its content under the simulated host, so a mode that must not
 * mutate can be proved not to have.
 *
 * @return array<string, string>
 */
function mailGatewayTree(array $host): array
{
    $tree = [];

    foreach (File::allFiles($host['fs'], true) as $file) {
        $tree[$file->getRelativePathname()] = hash_file('sha256', $file->getPathname());
    }

    ksort($tree);

    return $tree;
}

function mailGatewayCleanup(array $host): void
{
    exec('rm -rf '.escapeshellarg($host['scratch']));
}

/**
 * install-mail-gateway --policy-digest on the simulated host: the SHA-256 of
 * the policy SCRIPT's bundle requests, of the recorded one, and where an
 * authorization belongs.
 *
 * @return array{requested: array{plan: string, outbound: string}, recorded: ?array{plan: string, outbound: string}, authorization: string}
 */
function mailGatewayPolicyDigest(array $host, array $env = [], ?string $script = null): array
{
    $process = proc_open(
        ['bash', $script ?? mailGatewayScript(), '--policy-digest'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $host['scratch'],
        [...$host['env'], ...$env],
    );
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0, "install-mail-gateway --policy-digest failed:\n{$stderr}");

    return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
}

/**
 * Write the one-use transition authorization activate-mail-outbound writes:
 * DIRECTION of TARGET, from the policy the host recorded to the one SCRIPT's
 * bundle requests — root-only, as the installer requires. $changes replaces
 * fields of the document, to forge a wrong one.
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed> the document written
 */
function mailGatewayAuthorize(array $host, string $direction, string $target, array $changes = [], array $env = [], ?string $script = null): array
{
    $digest = mailGatewayPolicyDigest($host, $env, $script);
    expect($digest['recorded'])->not->toBeNull('there is no recorded policy to authorize a transition from');

    $now = time();
    $document = array_replace_recursive([
        'kind' => 'rateguru-mail-gateway-transition-authorization',
        'schema_version' => 1,
        'target' => $target,
        'direction' => $direction,
        'from' => $digest['recorded'],
        'to' => $digest['requested'],
        'nonce' => bin2hex(random_bytes(16)),
        'created_at' => $now,
        'expires_at' => $now + 900,
    ], $changes);

    file_put_contents($digest['authorization'], json_encode($document, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    chmod($digest['authorization'], 0o600);

    return $document;
}

/*
|--------------------------------------------------------------------------
| The fake mail gateway: one loopback SMTP listener and its queue
|--------------------------------------------------------------------------
|
| MailSigningTest submits the signing acceptance's probes to it, and
| MailOutboundActivationTest the canary. It behaves like the gateway's signed
| listener: a From outside tits.guru is refused at the end of the data, and an
| accepted message gets a queue ID, its headers (with the `signature` toggle
| prepended) and a queue entry the stubbed postqueue, postcat and postsuper
| see. Toggles are files under STATE/toggles: refuse-rcpt, accept-foreign,
| tempfail-foreign, signature, queue (the queue an accepted message lands in),
| no-queue-id, and delivery — sent, bounced or expired (logged for its queue
| ID and gone from the queue), deferred (logged and left in the deferred
| queue) or none (left in the active queue, nothing logged). The log lines go
| to STATE/journal, recipient included, as Postfix's own do.
*/

function mailGatewayFakeListenerSource(): string
{
    return <<<'PHP'
        <?php
        $state = $argv[1];
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($server === false) { fwrite(STDERR, $error); exit(1); }
        echo parse_url('tcp://'.stream_socket_get_name($server, false), PHP_URL_PORT), "\n";
        fflush(STDOUT);
        stream_set_blocking(STDIN, false);
        $toggle = static fn (string $name): ?string => is_file("{$state}/toggles/{$name}") ? (string) file_get_contents("{$state}/toggles/{$name}") : null;

        while (true) {
            $read = [$server, STDIN]; $write = null; $except = null;
            if (@stream_select($read, $write, $except, 1) === false) { break; }
            if (in_array(STDIN, $read, true) && feof(STDIN)) { break; }
            if (! in_array($server, $read, true)) { if (feof(STDIN)) { break; } continue; }

            $client = @stream_socket_accept($server, 5);
            if ($client === false) { continue; }
            $say = static function (string $line) use ($client): void { fwrite($client, $line."\r\n"); };
            $log = static fn (string $line) => file_put_contents("{$state}/smtp.log", $line."\n", FILE_APPEND);

            $say('220 mail-gateway.rateguru.invalid ESMTP');
            $rcpt = '';
            while (($line = fgets($client)) !== false) {
                $line = rtrim($line, "\r\n");
                $log($line);
                $verb = strtoupper(substr($line, 0, 4));
                if ($verb === 'EHLO') { $say('250-mail-gateway.rateguru.invalid'); $say('250 8BITMIME'); }
                elseif ($verb === 'MAIL') { $say('250 2.1.0 Ok'); }
                elseif ($verb === 'RCPT') {
                    if ($toggle('refuse-rcpt') !== null) { $say('554 5.7.1 refused'); continue; }
                    preg_match('/<([^>]*)>/', $line, $m); $rcpt = $m[1] ?? ''; $say('250 2.1.5 Ok');
                }
                elseif ($verb === 'DATA') {
                    $say('354 End data with <CR><LF>.<CR><LF>');
                    $message = '';
                    while (($data = fgets($client)) !== false) {
                        if (rtrim($data, "\r\n") === '.') { break; }
                        $message .= $data;
                    }
                    $headers = substr($message, 0, (int) strpos($message, "\r\n\r\n"));
                    preg_match('/^From:(.*)$/mi', str_replace("\r", '', $headers), $from);
                    $ours = preg_match('/^\s*(?:"[^"<>@,]*"\s*|[^"<>@,]+)?<?[A-Za-z0-9._%+-]+@tits\.guru>?\s*$/i', $from[1] ?? '') === 1;
                    if (! $ours && $toggle('accept-foreign') === null) {
                        $say($toggle('tempfail-foreign') !== null ? '451 4.7.1 Service unavailable - try again later' : '550 5.7.1 RateGuru mail gateway: the From header must be exactly one address in the reviewed sender domain');
                        continue;
                    }
                    $id = strtoupper(bin2hex(random_bytes(5)));
                    $signature = $toggle('signature');
                    file_put_contents("{$state}/headers-{$id}", ($signature !== null ? $signature : '').str_replace("\r\n", "\n", $headers)."\n");
                    $queue = trim($toggle('queue') ?? 'hold');
                    $delivery = $toggle('delivery') === null ? null : trim((string) $toggle('delivery'));
                    if ($delivery !== null && $delivery !== 'none') {
                        // What the gateway's own smtp client logs for this queue ID, the
                        // recipient included — exactly what a canary must never repeat.
                        $dsn = ['sent' => '2.0.0', 'bounced' => '5.1.1', 'deferred' => '4.4.1', 'expired' => '4.4.1'][$delivery] ?? '';
                        file_put_contents("{$state}/journal", "{$id}: to=<{$rcpt}>, relay=mx.receiver.example[192.0.2.25]:25, delay=0.4, delays=0.1/0/0.2/0.1, dsn={$dsn}, status={$delivery} (simulated reply naming <{$rcpt}>)\n", FILE_APPEND);
                        $queue = $delivery === 'deferred' ? 'deferred' : null;
                    } elseif ($delivery === 'none') {
                        $queue = 'active';
                    }
                    if ($queue !== null) {
                        file_put_contents("{$state}/queue", "{$id}\t{$queue}\t{$rcpt}\n", FILE_APPEND);
                    }
                    $say($toggle('no-queue-id') !== null ? '250 2.0.0 Ok' : "250 2.0.0 Ok: queued as {$id}");
                }
                elseif ($verb === 'QUIT') { $say('221 2.0.0 Bye'); break; }
                else { $say('502 5.5.2 Error'); }
            }
            fclose($client);
        }
        PHP;
}

/**
 * Start the fake listener on a free loopback port, its state in STATE.
 *
 * @return array{server: resource, pipes: array<int, resource>, port: int}
 */
function mailGatewayStartFakeListener(string $scratch, string $state): array
{
    @mkdir($state.'/toggles', 0o755, true);
    file_put_contents($scratch.'/fake-postfix.php', mailGatewayFakeListenerSource());

    $server = proc_open([PHP_BINARY, $scratch.'/fake-postfix.php', $state], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $port = (int) trim((string) fgets($pipes[1]));
    expect($port)->toBeGreaterThan(0);

    return ['server' => $server, 'pipes' => $pipes, 'port' => $port];
}

/** @param  array{server: resource, pipes: array<int, resource>}  $listener */
function mailGatewayStopFakeListener(array $listener): void
{
    fclose($listener['pipes'][0]);
    proc_terminate($listener['server']);
    proc_close($listener['server']);
}

/**
 * postqueue, postcat and postsuper stubs in BIN that see only the fake queue
 * in STUB_STATE/queue ("ID<TAB>QUEUE<TAB>RECIPIENT" per line) and log every
 * call to STUB_LOG/queue.log. postqueue cannot read the queue — it fails, as
 * when the mail system is down — while STUB_STATE/postqueue-fails exists, or
 * STUB_STATE/postqueue-fails-after-delete once postsuper has deleted anything.
 */
function mailGatewayFakeQueueTools(string $bin): void
{
    $tools = [
        'postqueue' => <<<'STUB'
            #!/bin/bash
            printf 'postqueue %s\n' "$*" >> "${STUB_LOG}/queue.log"
            [[ "$1" == -j ]] || exit 1
            if [[ -e "${STUB_STATE}/postqueue-fails" ]] || { [[ -e "${STUB_STATE}/postqueue-fails-after-delete" ]] && [[ -e "${STUB_STATE}/deleted" ]]; }; then
                echo 'postqueue: fatal: Queue report unavailable - mail system is down' >&2
                exit 69
            fi
            while IFS=$'\t' read -r id queue rcpt; do
                [[ -n "${id}" ]] || continue
                printf '{"queue_name": "%s", "queue_id": "%s", "sender": "", "recipients": [{"address": "%s"}]}\n' "${queue}" "${id}" "${rcpt}"
            done < "${STUB_STATE}/queue"
            STUB,
        'postcat' => <<<'STUB'
            #!/bin/bash
            printf 'postcat %s\n' "$*" >> "${STUB_LOG}/queue.log"
            [[ "$1" == -h && "$2" == -q && -n "${3:-}" ]] || exit 1
            cat "${STUB_STATE}/headers-$3" 2>/dev/null || exit 1
            STUB,
        'postsuper' => <<<'STUB'
            #!/bin/bash
            printf 'postsuper %s\n' "$*" >> "${STUB_LOG}/queue.log"
            [[ "$1" == -d && -n "${2:-}" ]] || exit 1
            touch "${STUB_STATE}/deleted"
            awk -F'\t' -v id="$2" -v queue="${3:-}" '!($1 == id && (queue == "" || $2 == queue))' "${STUB_STATE}/queue" > "${STUB_STATE}/queue.next" \
                && mv "${STUB_STATE}/queue.next" "${STUB_STATE}/queue"
            STUB,
    ];

    foreach ($tools as $name => $body) {
        file_put_contents($bin.'/'.$name, $body."\n");
        chmod($bin.'/'.$name, 0o755);
    }
}

/*
|--------------------------------------------------------------------------
| The trusted bundle an activation runs from, and the host it activates
|--------------------------------------------------------------------------
|
| activate-mail-outbound copies the trusted infrastructure/ tree it runs from,
| so its tests run it from a bundle of their own: scratch/bundle holds the
| REAL activate-mail-outbound, send-mail-canary, smtp-submission,
| mail-routing, mail-identity, targets and install-mail-gateway (as
| install-mail-gateway.real, behind a thin wrapper that logs each call and
| can make one fail), with the four reviewed documents, and stubs for the
| signer's installer and the signing verifier. Every copy of the bundle the
| activation makes carries the same tools, so each judges its own documents.
|
| The host is mailGatewayHost()'s simulated Postfix host, on which the real
| gateway records its applied policy, refuses the activation boundary and
| consumes the activation's authorizations. mail-identity's DNS, address and
| key are mailIdentityDnsHost()'s, with tits-guru's real key and correct
| public DNS. host/calls.log is every gateway, signer and signing-verifier
| call, with the tits-guru delivery mode of the bundle it came from.
|
| Toggles (files under host/toggles): gateway-apply-fails-MODE (the installer
| refuses, changing nothing), gateway-apply-breaks-MODE (it installs, then
| fails), gateway-verify-fails-MODE, leak-direct-route (staging's listener is
| given tits-guru's direct route after an outbound apply), signer-down,
| readonly-signing-fails (verify-mail-signing --read-only refuses, so
| readiness does), e2e-foreign-accepted, e2e-exits-nonzero (the acceptance
| prints a passing result but exits 1).
*/

/**
 * The wrapper around the real gateway, and the stubs of the signer's installer
 * and the signing verifier, by name.
 *
 * @return array<string, string>
 */
function mailActivationOwnerStubs(): array
{
    return [
        'install-mail-gateway' => <<<'STUB'
            #!/bin/bash
            here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
            mode="$(jq -r '.targets["tits-guru"].delivery_mode' "${here}/../config/mail-routing.json")"
            printf 'install-mail-gateway %s [%s]\n' "$*" "${mode}" >> "${STUB_HOST}/calls.log"
            toggle() { [[ -f "${STUB_HOST}/toggles/$1" ]]; }
            case "${1:-}" in
                --apply)
                    if toggle "gateway-apply-fails-${mode}"; then
                        echo "  CONFLICT the installer refused (simulated) — nothing was changed"
                        exit 1
                    fi
                    bash "${here}/install-mail-gateway.real" "$@"
                    status=$?
                    if (( status == 0 )) && [[ "${mode}" == outbound ]] && toggle leak-direct-route; then
                        sed -e 's#content_filter=rateguru-capture-staging-main:\[127\.0\.0\.1\]:1025#content_filter=rateguru-outbound-tits-guru:#' \
                            "${STUB_FS}/etc/postfix/master.cf" > "${STUB_FS}/etc/postfix/master.cf.next" \
                            && mv "${STUB_FS}/etc/postfix/master.cf.next" "${STUB_FS}/etc/postfix/master.cf"
                    fi
                    if (( status == 0 )) && toggle "gateway-apply-breaks-${mode}"; then
                        echo "  ERROR the installer failed after installing (simulated)"
                        exit 1
                    fi
                    exit "${status}"
                    ;;
                --verify)
                    if toggle "gateway-verify-fails-${mode}"; then
                        echo "  DRIFT the gateway does not verify (simulated)"
                        exit 1
                    fi
                    ;;
            esac
            exec bash "${here}/install-mail-gateway.real" "$@"
            STUB,
        'install-mail-signing' => <<<'STUB'
            #!/bin/bash
            [[ "${1:-}" == --milter-endpoint ]] || printf 'install-mail-signing %s\n' "$*" >> "${STUB_HOST}/calls.log"
            case "${1:-}" in
                --milter-endpoint) echo 'inet:127.0.0.1:8891'; exit 0 ;;
                --verify)
                    if [[ -f "${STUB_HOST}/toggles/signer-down" ]]; then
                        echo "  FAIL opendkim.service is not running (simulated)"
                        exit 1
                    fi
                    echo "  PASS the signer runs on 127.0.0.1:8891 and reads the key (simulated)"
                    exit 0
                    ;;
            esac
            exit 64
            STUB,
        'verify-mail-signing' => <<<'STUB'
            #!/bin/bash
            here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
            mode="$(jq -r '.targets["tits-guru"].delivery_mode' "${here}/../config/mail-routing.json")"
            printf 'verify-mail-signing %s [%s]\n' "$*" "${mode}" >> "${STUB_HOST}/calls.log"
            toggle() { [[ -f "${STUB_HOST}/toggles/$1" ]]; }
            refuse() {
                echo "  FAIL $1"
                printf 'RATEGURU_MAIL_SIGNING_RESULT={"target":"%s","mode":"e2e","status":"fail","foreign_from_rejected":%s,"queue_id":null,"held":false,"removed":false,"signature":null}\n' "${3:-}" "$2"
                exit 1
            }
            case "${1:-}" in
                --read-only)
                    "${here}/install-mail-gateway" --verify >/dev/null 2>&1 || { echo "  FAIL the gateway's wiring (simulated)"; exit 1; }
                    toggle signer-down && { echo "  FAIL the signer is not running (simulated)"; exit 1; }
                    toggle readonly-signing-fails && { echo "  FAIL the signer cannot read the key (simulated)"; exit 1; }
                    echo "  PASS the signer and the gateway's wiring (simulated)"
                    exit 0
                    ;;
                --e2e)
                    target="${3:-}"
                    [[ "${mode}" == held ]] || refuse "${target}'s mail is ${mode}, not held — nothing was submitted" false "${target}"
                    "${here}/install-mail-gateway" --verify >/dev/null 2>&1 || refuse "the read-only signing contract does not hold — nothing was submitted" false "${target}"
                    toggle e2e-foreign-accepted && refuse "the held listener ACCEPTED a message whose From is outside tits.guru (simulated)" false "${target}"
                    echo "  PASS a foreign From refused; the probe signed, held and removed (simulated)"
                    printf 'RATEGURU_MAIL_SIGNING_RESULT={"target":"%s","mode":"e2e","status":"pass","foreign_from_rejected":true,"queue_id":"E2E0000001","held":true,"removed":true,"signature":{"d":"tits.guru","s":"rg1","a":"rsa-sha256"}}\n' "${target}"
                    toggle e2e-exits-nonzero && exit 1
                    exit 0
                    ;;
            esac
            exit 64
            STUB,
    ];
}

/**
 * The mail routing policy and host outbound contract the repository commits,
 * as arrays — what every bundle built from it requests.
 *
 * @return array{routing: array<string, mixed>, outbound: array<string, mixed>}
 */
function mailCommittedPolicy(): array
{
    return [
        'routing' => json_decode(File::get(base_path('infrastructure/config/mail-routing.json')), true, 512, JSON_THROW_ON_ERROR),
        'outbound' => json_decode(File::get(base_path('infrastructure/config/mail-outbound.json')), true, 512, JSON_THROW_ON_ERROR),
    ];
}

/**
 * The routing policy and host contract that request tits-guru's activation:
 * the committed ones, tits-guru's submission moved to PORT when one is given.
 *
 * @return array{routing: array<string, mixed>, outbound: array<string, mixed>}
 */
function mailActivationRequest(?int $port = null): array
{
    $request = mailCommittedPolicy();

    if ($port !== null) {
        $request['routing']['targets']['tits-guru']['submission']['port'] = $port;
    }

    return $request;
}

/**
 * The pre-activation state: the activation request with exactly its three
 * changes undone — tits-guru held, no outbound route, direct delivery disabled
 * — and every other field as requested. It is what a host recorded before
 * activate-mail-outbound crossed it, and what a held simulated host starts
 * from; never a second copy of the production documents.
 *
 * @return array{routing: array<string, mixed>, outbound: array<string, mixed>}
 */
function mailPreActivationPolicy(?int $port = null): array
{
    $policy = mailActivationRequest($port);
    $policy['routing']['targets']['tits-guru']['delivery_mode'] = 'held';
    unset($policy['routing']['targets']['tits-guru']['outbound']);
    $policy['outbound']['direct']['enabled'] = false;

    return $policy;
}

/**
 * A scratch checkout of the infrastructure tree under SCRATCH whose mail
 * routing policy and host contract are the pre-activation ones — the
 * repository before tits-guru's activation was requested. Returns its root.
 */
function mailPreActivationCheckout(string $scratch): string
{
    $repo = provisionRepo($scratch, File::get(base_path('infrastructure/config/deployment-targets.json')));
    $policy = mailPreActivationPolicy();

    file_put_contents($repo.'/infrastructure/config/mail-routing.json', mailRoutingJson($policy['routing']));
    file_put_contents($repo.'/infrastructure/config/mail-outbound.json', mailRoutingJson($policy['outbound']));

    return $repo;
}

/**
 * Write a bundle of the real tools and the stubs into ROOT, with DOCUMENTS.
 *
 * @param  array<string, array<string, mixed>>  $documents
 */
function mailActivationBundle(string $root, array $documents): void
{
    @mkdir($root.'/infrastructure/scripts', 0o755, true);
    @mkdir($root.'/infrastructure/config', 0o755, true);

    foreach (['activate-mail-outbound', 'send-mail-canary', 'smtp-submission', 'mail-routing', 'mail-identity', 'targets', 'install-mail-gateway'] as $name) {
        $copy = $name === 'install-mail-gateway' ? 'install-mail-gateway.real' : $name;
        copy(base_path('infrastructure/scripts/'.$name), "{$root}/infrastructure/scripts/{$copy}");
        chmod("{$root}/infrastructure/scripts/{$copy}", $name === 'smtp-submission' ? 0o644 : 0o755);
    }

    foreach (mailActivationOwnerStubs() as $name => $body) {
        file_put_contents("{$root}/infrastructure/scripts/{$name}", $body."\n");
        chmod("{$root}/infrastructure/scripts/{$name}", 0o755);
    }

    foreach ($documents as $name => $data) {
        file_put_contents("{$root}/infrastructure/config/{$name}", mailRoutingJson($data));
    }
}

/**
 * A trusted bundle and the simulated host it activates.
 *
 * Options:
 *   requested  true (default): the bundle holds the committed documents, which
 *              request tits-guru's activation; false: it holds the
 *              pre-activation documents (mailPreActivationPolicy()), which
 *              request none
 *   routing    a routing policy for the bundle instead
 *   outbound   a host contract for the bundle instead
 *   registry   a registry for the bundle instead
 *   installed  'held' (default): the host's gateway was applied from the
 *              pre-activation documents, and records them; 'outbound': then
 *              activated to the bundle's request through an authorization; or
 *              an array {routing, outbound} it was applied from instead
 *   listeners  the host's other TCP listeners (default: 127.0.0.1:1025)
 *   port       tits-guru's submission port, everywhere
 *   toggles    list of toggle names
 *   queue      lines already in the queue, "ID<TAB>QUEUE<TAB>RECIPIENT"
 *   dns        DNS answers instead of the correct ones
 *   listener   true: tits-guru's submission endpoint is a running fake gateway
 *              listener (mailGatewayStartFakeListener) whose state is the
 *              host's — what a canary is sent to
 *
 * Stop it with mailActivationCleanup().
 *
 * @return array{scratch: string, bundle: string, host: string, state: string, log: string, fs: string, key: string, env: array<string, string>, listener: ?array}
 */
function mailActivationHost(array $options = []): array
{
    // Every bundle's gateway reads that bundle's own documents.
    $gateway = mailGatewayHost(['listeners' => $options['listeners'] ?? ['127.0.0.1:1025'], 'policy' => null, 'outbound' => null]);
    $scratch = $gateway['scratch'];
    $fs = $gateway['fs'];
    $state = $scratch.'/state';

    foreach (['/host/toggles', '/run', '/capsules', '/tmp', '/dns', '/no-wait'] as $sub) {
        @mkdir($scratch.$sub, 0o755, true);
    }

    $listener = null;
    if ($options['listener'] ?? false) {
        $listener = mailGatewayStartFakeListener($scratch, $state);
        $options['port'] = $listener['port'];
    }

    $port = $options['port'] ?? null;
    $committed = static fn (string $name): array => json_decode(File::get(base_path("infrastructure/config/{$name}")), true, 512, JSON_THROW_ON_ERROR);
    $preActivation = mailPreActivationPolicy($port);

    $request = ($options['requested'] ?? true) ? mailActivationRequest($port) : $preActivation;
    $documents = [
        'deployment-targets.json' => $options['registry'] ?? $committed('deployment-targets.json'),
        'mail-routing.json' => $options['routing'] ?? $request['routing'],
        'mail-outbound.json' => $options['outbound'] ?? $request['outbound'],
        'mail-identity.json' => $committed('mail-identity.json'),
    ];
    mailActivationBundle($scratch.'/bundle', $documents);

    file_put_contents($state.'/queue', implode('', array_map(static fn (string $line): string => $line."\n", $options['queue'] ?? ["FOREIGN0001\tdeferred\tsomeone@example.net"])));
    mailGatewayFakeQueueTools($scratch.'/bin');
    file_put_contents($scratch.'/bin/journalctl', <<<'STUB'
        #!/bin/bash
        printf 'journalctl %s\n' "$*" >> "${STUB_LOG}/queue.log"
        cat "${STUB_STATE}/journal" 2>/dev/null
        exit 0
        STUB."\n");
    chmod($scratch.'/bin/journalctl', 0o755);
    file_put_contents($scratch.'/no-wait/sleep', "#!/bin/sh\nexit 0\n");
    chmod($scratch.'/no-wait/sleep', 0o755);

    $key = mailIdentityInstallKey($scratch, 'tits-guru', 'rg1');
    $dns = mailIdentityDnsHost($scratch, $options['dns'] ?? mailIdentityGoodDns(mailIdentityPublicKey($key)));
    // Readiness asks the signing verifier of the bundle it runs from.
    unset($dns['RATEGURU_MAILIDENTITY_SIGNING_VERIFIER_BIN']);

    $env = [
        ...$gateway['env'],
        'PATH' => $scratch.'/no-wait:'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'TMPDIR' => $scratch.'/tmp',
        'RATEGURU_MAILACTIVATE_EUID' => '0',
        'RATEGURU_MAILACTIVATE_RUN_ROOT' => $scratch.'/run',
        'RATEGURU_MAILACTIVATE_STATE_ROOT' => $scratch.'/capsules',
        'RATEGURU_MAILACTIVATE_POSTQUEUE_BIN' => $scratch.'/bin/postqueue',
        'RATEGURU_MAILACTIVATE_POSTCONF_BIN' => $scratch.'/bin/postconf',
        'RATEGURU_MAILACTIVATE_SS_BIN' => $scratch.'/bin/ss',
        'RATEGURU_MAILCANARY_EUID' => '0',
        'RATEGURU_MAILCANARY_FILE_OWNER_UID' => (string) posix_getuid(),
        'RATEGURU_MAILCANARY_POSTQUEUE_BIN' => $scratch.'/bin/postqueue',
        'RATEGURU_MAILCANARY_POSTSUPER_BIN' => $scratch.'/bin/postsuper',
        'RATEGURU_MAILCANARY_JOURNALCTL_BIN' => $scratch.'/bin/journalctl',
        'RATEGURU_MAILCANARY_DELIVERY_WINDOW' => '3',
        'RATEGURU_MAILCANARY_POLL_INTERVAL' => '1',
        'STUB_HOST' => $scratch.'/host',
        ...$dns,
    ];

    $host = [
        'scratch' => $scratch, 'bundle' => $scratch.'/bundle', 'host' => $scratch.'/host', 'state' => $state,
        'log' => $scratch.'/log', 'fs' => $fs, 'key' => $key, 'env' => $env, 'listener' => $listener,
    ];

    // The gateway the host starts with, applied by the real installer from a
    // bundle of its own — and, for an activated host, crossed to the request
    // with the authorization activate-mail-outbound would write.
    $installed = $options['installed'] ?? 'held';
    $start = is_array($installed) ? $installed : $preActivation;
    $apply = static function (array $docs) use ($host, $scratch, $documents): void {
        $root = $scratch.'/installed';
        File::deleteDirectory($root);
        mailActivationBundle($root, [...$documents, 'mail-routing.json' => $docs['routing'], 'mail-outbound.json' => $docs['outbound']]);
        [$status, $output] = mailActivationRun(['bundle' => $root] + $host, ['--apply'], [], 'install-mail-gateway');
        expect($status)->toBe(0, "the simulated host could not be given its starting gateway:\n{$output}");
    };

    $apply($start);

    if ($installed === 'outbound') {
        mailGatewayAuthorize($host, 'activate', 'tits-guru', [], [], $scratch.'/bundle/infrastructure/scripts/install-mail-gateway.real');
        $apply(['routing' => $documents['mail-routing.json'], 'outbound' => $documents['mail-outbound.json']]);
    }

    File::deleteDirectory($scratch.'/installed');
    @unlink($host['host'].'/calls.log');
    file_put_contents($host['log'].'/mutations.log', '');

    // Only now: a toggle describes the host from here on, not how it was set up.
    foreach ($options['toggles'] ?? [] as $toggle) {
        touch("{$host['host']}/toggles/{$toggle}");
    }

    return $host;
}

function mailActivationCleanup(array $host): void
{
    if ($host['listener'] !== null) {
        mailGatewayStopFakeListener($host['listener']);
    }

    removeScratchDir($host['scratch']);
}

/** A recipient file holding CONTENT, readable by its owner alone. */
function mailCanaryRecipientFile(array $host, string $content, int $mode = 0o600): string
{
    $path = $host['scratch'].'/recipient';
    file_put_contents($path, $content);
    chmod($path, $mode);

    return $path;
}

/** The machine-readable result line a canary printed, or null. */
function mailCanaryResult(string $output): ?array
{
    return preg_match_all('/^RATEGURU_MAIL_CANARY_RESULT=(\{.*\})$/m', $output, $m) === 1 ? json_decode($m[1][0], true) : null;
}

/**
 * Run one of the bundle's scripts against the simulated host.
 *
 * @return array{0: int, 1: string}
 */
function mailActivationRun(array $host, array $arguments, array $env = [], string $script = 'activate-mail-outbound'): array
{
    $process = proc_open(
        ['bash', "{$host['bundle']}/infrastructure/scripts/{$script}", ...$arguments],
        [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
        $pipes,
        $host['scratch'],
        [...$host['env'], ...$env],
    );

    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return [proc_close($process), $output];
}

/** The machine-readable result line an activation printed, or null. */
function mailActivationResult(string $output): ?array
{
    return preg_match_all('/^RATEGURU_MAIL_OUTBOUND_ACTIVATION_RESULT=(\{.*\})$/m', $output, $m) === 1 ? json_decode($m[1][0], true) : null;
}

/**
 * Every owner call the simulated host received, in order.
 *
 * @return list<string>
 */
function mailActivationCalls(array $host): array
{
    return array_values(array_filter(explode("\n", (string) @file_get_contents($host['host'].'/calls.log'))));
}

/** The tits-guru delivery mode the simulated host's gateway records as applied. */
function mailActivationInstalledMode(array $host): string
{
    $plan = json_decode((string) file_get_contents($host['fs'].'/var/lib/rateguru-mail-gateway/applied-plan.json'), true);

    return collect($plan['listeners'])->firstWhere('identity', 'tits-guru')['delivery_mode'];
}

/** The capsule directory of tits-guru's activation on the simulated host. */
function mailActivationCapsule(array $host): string
{
    return $host['scratch'].'/capsules/tits-guru';
}

/**
 * The keys a template declares, in FILE ORDER.
 *
 * Section comments and the blank lines between them are layout, not content,
 * so they are dropped — a template may be grouped and annotated freely. What
 * survives is the ordered list of variables, which is the thing a reviewer
 * actually has to read.
 */
function environmentTemplateKeys(string $path): array
{
    return collect(preg_split('/\R/', File::get(base_path($path))))
        ->map(fn (string $line): string => ltrim($line))
        ->reject(fn (string $line): bool => $line === '' || str_starts_with($line, '#'))
        ->filter(fn (string $line): bool => str_contains($line, '='))
        ->map(fn (string $line): string => rtrim((string) strstr($line, '=', true)))
        ->values()
        ->all();
}

/**
 * A runtime .env that satisfies the environment contract: every key the
 * committed template declares, with the given values substituted in.
 *
 * Built FROM the template rather than from a list kept here, which is the whole
 * point — deploy and configure refuse when a key the template declares is
 * absent from the runtime file, so a fixture carrying its own hand-written key
 * list would start failing the moment a key is added and would teach whoever
 * fixed it to copy the list again. There is one list of keys and it is the
 * template.
 *
 * @param  array<string, string>  $values  keys to give a concrete value
 */
function contractSatisfyingEnvironment(array $values = [], string $template = 'staging'): string
{
    $path = base_path("infrastructure/templates/environment/{$template}.env.example");

    $lines = [];

    foreach (preg_split('/\R/', (string) file_get_contents($path)) as $line) {
        $trimmed = ltrim($line);

        if ($trimmed === '' || str_starts_with($trimmed, '#') || ! str_contains($trimmed, '=')) {
            continue;
        }

        $key = rtrim(strstr($trimmed, '=', true) ?: '');

        $lines[] = $key.'='.($values[$key] ?? substr(strstr($trimmed, '=') ?: '=', 1));
    }

    return implode("\n", $lines)."\n";
}

/**
 * The scratch target tree a live restore acts on: an immutable release under
 * releases/, a current symlink, shared/.env, the shared storage layout deploy
 * itself creates, and the lock/deployment directories.
 */
function targetTreeFixture(string $scratch, array $options = []): string
{
    $root = $scratch.'/target';
    $release = $options['release'] ?? FIXTURE_RELEASE;
    $sourceSha = $options['source_sha'] ?? FIXTURE_SOURCE_SHA;

    mkdir($root.'/releases/'.$release, 0o755, true);
    mkdir($root.'/shared/storage/app/public', 0o755, true);
    mkdir($root.'/shared/storage/framework', 0o755, true);
    mkdir($root.'/locks', 0o755, true);
    mkdir($root.'/deployments', 0o755, true);
    mkdir($root.'/incoming', 0o755, true);

    file_put_contents(
        $root.'/releases/'.$release.'/release.json',
        json_encode(['project' => 'rateguru', 'release' => $release, 'source_sha' => $sourceSha], JSON_PRETTY_PRINT),
    );
    file_put_contents($root.'/releases/'.$release.'/artisan', "<?php\n");

    if (($options['current'] ?? true) === true) {
        symlink($root.'/releases/'.$release, $root.'/current');
    }

    file_put_contents($root.'/shared/.env', contractSatisfyingEnvironment([
        'APP_ENV' => 'staging',
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '5432',
        'DB_DATABASE' => $options['database'] ?? 'parity_db',
        'DB_USERNAME' => $options['role'] ?? 'parity_app',
        'DB_PASSWORD' => 's3cr3t-not-logged',
    ]));

    file_put_contents($root.'/shared/storage/app/live-marker.txt', "live\n");
    file_put_contents($root.'/shared/storage/app/public/live-public.txt', "live public\n");

    return $root;
}

/**
 * The host-scope logical names the committed vhosts derive for a target whose
 * site is the staging one — the vocabulary a schema 3 backup's recovery
 * material is written in, in the order install-target-prerequisites lists it.
 *
 * @return list<string>
 */
/**
 * Runs a bash body with `common` sourced, the way every operational script
 * has it: the deployment.conf template and the committed registry stand in
 * for the installed ones, and test overrides are enabled. `fail` exits the
 * shell it runs in, so a refusal is observed from a subshell: `( fn ) || …`.
 *
 * @return array{0: int, 1: string}
 */
function commonFunctionHarness(string $scratch, string $body, array $env = []): array
{
    $harness = $scratch.'/common-harness-'.uniqid('', true).'.sh';
    file_put_contents($harness, "set -Eeuo pipefail\nsource ".escapeshellarg(base_path('infrastructure/scripts/common'))."\n".$body."\n");

    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(['bash', $harness], $descriptors, $pipes, null, array_merge([
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'HOME' => getenv('HOME') ?: '/tmp',
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_DEPLOYMENT_CONF_FILE' => base_path('infrastructure/templates/deployment.conf.example'),
        'RATEGURU_TARGET_REGISTRY_FILE' => base_path('infrastructure/config/deployment-targets.json'),
        'RATEGURU_TARGETS_CLI' => base_path('infrastructure/scripts/targets'),
    ], $env));

    expect($process)->not->toBeFalse();

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return [proc_close($process), $output];
}

function recoveryMaterialNames(): array
{
    return [
        'basic-auth',
        'tls-certificate',
        'tls-private-key',
        'nginx-tls-options',
        'tls-dhparams',
        'mail-tls-certificate',
        'mail-tls-private-key',
    ];
}

/**
 * The default recovery material of a schema 3 fixture: every host-scope
 * logical name, with deliberately unlike-real content that a test can prove
 * never leaks into a log.
 *
 * @return array<string, string>
 */
function recoveryMaterialMembers(): array
{
    $members = [];

    foreach (recoveryMaterialNames() as $name) {
        $members[$name] = "material-{$name}-never-logged\n";
    }

    return $members;
}

/**
 * Builds recovery-material.tar.gz at $path exactly the way backup writes it:
 * named top-level regular files from a fixed directory, no directory entry,
 * no path prefix, no link.
 *
 * @param  array<string, string>  $members  logical name => content
 */
function buildRecoveryMaterialArchive(string $path, array $members): void
{
    $stage = $path.'.material-src';
    mkdir($stage, 0o700, true);

    $names = array_keys($members);
    sort($names);

    foreach ($members as $name => $content) {
        file_put_contents($stage.'/'.$name, $content);
        chmod($stage.'/'.$name, 0o600);
    }

    $quoted = implode(' ', array_map('escapeshellarg', $names));

    exec('tar -C '.escapeshellarg($stage).' -czf '.escapeshellarg($path).' -- '.$quoted.' 2>&1', $out, $exit);
    exec('rm -rf '.escapeshellarg($stage));

    expect($exit)->toBe(0, "could not build the recovery material archive:\n".implode("\n", $out));
}

/**
 * The bytes of a recovery material archive built WRONG on purpose, so a test
 * can prove the archive-as-data rules: 'link' (a symbolic link named $name),
 * 'nested' (sub/$name), 'traversal' (../$name, kept with -P), 'directory'
 * (a directory entry), 'hardlink' ($name plus a hard link to it), 'fifo'
 * (a FIFO named $name), 'duplicate' ($name listed twice), 'empty'.
 */
function recoveryMaterialArchiveBytes(string $shape, string $name = 'basic-auth', array $others = []): string
{
    $stage = sys_get_temp_dir().'/recovery-material-shape-'.uniqid('', true);
    mkdir($stage.'/sub', 0o700, true);
    $archive = $stage.'.tar.gz';
    $quoted = escapeshellarg($name);

    // Plain regular members beside the wrong one, so a judge that checks the
    // vocabulary first still reaches the shape under test.
    $prelude = '';
    $more = '';

    foreach ($others as $other) {
        $prelude .= 'printf x > '.escapeshellarg($stage.'/'.$other).' && ';
        $more .= ' '.escapeshellarg($other);
    }

    $command = $prelude.match ($shape) {
        'link' => 'ln -s /etc/hosts '.escapeshellarg($stage.'/'.$name).' && tar -C '.escapeshellarg($stage).' -czf '.escapeshellarg($archive).' -- '.$quoted.$more,
        'nested' => 'printf x > '.escapeshellarg($stage.'/sub/'.$name).' && tar -C '.escapeshellarg($stage).' -czf '.escapeshellarg($archive).' -- sub/'.$name.$more,
        'traversal' => 'printf x > '.escapeshellarg($stage.'/'.$name).' && tar -P -C '.escapeshellarg($stage.'/sub').' -czf '.escapeshellarg($archive).' -- ../'.$name.str_replace(" '", " '../", $more),
        'directory' => 'printf x > '.escapeshellarg($stage.'/sub/'.$name).' && tar -C '.escapeshellarg($stage).' -czf '.escapeshellarg($archive).' -- sub'.$more,
        'hardlink' => 'printf x > '.escapeshellarg($stage.'/'.$name).' && ln '.escapeshellarg($stage.'/'.$name).' '.escapeshellarg($stage.'/tls-dhparams').' && tar -C '.escapeshellarg($stage).' -czf '.escapeshellarg($archive).' -- '.$quoted.' tls-dhparams'.$more,
        'fifo' => 'mkfifo '.escapeshellarg($stage.'/'.$name).' && tar -C '.escapeshellarg($stage).' -czf '.escapeshellarg($archive).' -- '.$quoted.$more,
        'duplicate' => 'printf x > '.escapeshellarg($stage.'/'.$name).' && tar -C '.escapeshellarg($stage).' -czf '.escapeshellarg($archive).' -- '.$quoted.' '.$quoted.$more,
        'empty' => 'tar -czf '.escapeshellarg($archive).' -T /dev/null',
        default => throw new InvalidArgumentException("unknown archive shape: {$shape}"),
    };

    exec($command.' 2>&1', $out, $exit);
    expect($exit)->toBe(0, "could not build the {$shape} archive:\n".implode("\n", $out));

    $bytes = file_get_contents($archive);
    exec('rm -rf '.escapeshellarg($stage).' '.escapeshellarg($archive));

    return $bytes;
}

/**
 * Writes recovery-material.tar.gz into $dir the way a schema 3 backup fixture
 * carries it, from the same options every fixture builder accepts:
 * `omit_recovery_material` suppresses it, `recovery_material_bytes` wins over
 * `recovery_material` (a logical name => content map), and the default is
 * every host-scope name. Returns whether the archive was written, so the
 * caller checksums it exactly when it exists.
 */
function maybeWriteRecoveryMaterial(string $dir, array $options): bool
{
    if (! empty($options['omit_recovery_material'])) {
        return false;
    }

    if (array_key_exists('recovery_material_bytes', $options)) {
        file_put_contents($dir.'/recovery-material.tar.gz', $options['recovery_material_bytes']);

        return true;
    }

    buildRecoveryMaterialArchive($dir.'/recovery-material.tar.gz', $options['recovery_material'] ?? recoveryMaterialMembers());

    return true;
}

/**
 * Everything the REAL install-target-prerequisites needs to capture, list or
 * judge parity-target's recovery material without a real host: a scratch
 * checkout carrying the parity registry and the committed vhosts under the
 * target's own site name, and a scratch filesystem root where every
 * host-scope prerequisite is present with the mode the table declares.
 *
 * Used by the backup, restore-test, verify-backup and recovery suites, so the
 * vocabulary those scripts capture in and check against is the shipped
 * installer's own — never a stub's idea of it.
 *
 * @return array<string, string>
 */
function recoveryMaterialPrerequisitesEnv(string $scratch, string $registryPath, string $targetsPath): array
{
    $repoRoot = $scratch.'/prereq-checkout';

    if (! is_dir($repoRoot.'/infrastructure/config/nginx')) {
        mkdir($repoRoot.'/infrastructure/config/nginx', 0o755, true);
        copy(base_path('infrastructure/config/nginx/rateguru-staging'), $repoRoot.'/infrastructure/config/nginx/parity-site');

        foreach (['mailpit-staging', 'mailtrap-local-staging'] as $vhost) {
            copy(base_path('infrastructure/config/nginx/'.$vhost), $repoRoot.'/infrastructure/config/nginx/'.$vhost);
        }
    }

    // Refreshed on every call: a later call with different registry options
    // must not keep judging against the first registry it copied.
    copy($registryPath, $repoRoot.'/infrastructure/config/deployment-targets.json');

    $fsRoot = $scratch.'/prereq-host';

    if (! is_dir($fsRoot)) {
        recoveryMaterialHostFixture($fsRoot);
    }

    return [
        'RATEGURU_PREREQUISITES_BIN' => infraScript('install-target-prerequisites'),
        'RATEGURU_TARGETPREREQ_REPO_ROOT' => $repoRoot,
        'RATEGURU_TARGETPREREQ_TARGETS_CLI_BIN' => $targetsPath,
        'RATEGURU_TARGETPREREQ_EUID' => '0',
        'RATEGURU_TARGETPREREQ_FS_ROOT' => $fsRoot,
        'RATEGURU_TARGETPREREQ_ENFORCE_OWNERSHIP' => 'false',
    ];
}

/**
 * A scratch filesystem root holding every host-scope prerequisite the
 * committed staging vhosts reference, as plain regular files with the mode
 * install-target-prerequisites declares — what a live host looks like to
 * `--capture`.
 *
 * @return array<string, string> logical name => the content planted for it
 */
function recoveryMaterialHostFixture(string $fsRoot): array
{
    $destinations = [
        'basic-auth' => ['/etc/nginx/rateguru-staging.htpasswd', 0o640],
        'tls-certificate' => ['/etc/letsencrypt/live/rateguru.staging.myprojects.pp.ua/fullchain.pem', 0o644],
        'tls-private-key' => ['/etc/letsencrypt/live/rateguru.staging.myprojects.pp.ua/privkey.pem', 0o600],
        'nginx-tls-options' => ['/etc/letsencrypt/options-ssl-nginx.conf', 0o644],
        'tls-dhparams' => ['/etc/letsencrypt/ssl-dhparams.pem', 0o644],
        'mail-tls-certificate' => ['/etc/letsencrypt/live/staging-mail-capture/fullchain.pem', 0o644],
        'mail-tls-private-key' => ['/etc/letsencrypt/live/staging-mail-capture/privkey.pem', 0o600],
    ];

    $planted = [];

    foreach ($destinations as $name => [$destination, $mode]) {
        $path = $fsRoot.$destination;

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0o755, true);
        }

        $planted[$name] = "host-{$name}-never-logged\n";
        file_put_contents($path, $planted[$name]);
        chmod($path, $mode);
    }

    return $planted;
}

/**
 * A real, on-disk backup directory in exactly the shape
 * infrastructure/scripts/backup produces: a genuine gzip storage archive, the
 * checksummed files of its schema, and a genuine SHA256SUMS computed with real
 * sha256sum, so every checksum check downstream is a real check.
 *
 * Schema 2 (seven files) by default. `'schema' => 3`, or an explicit schema 3
 * manifest, adds recovery-material.tar.gz — built from `recovery_material`
 * (logical name => content, defaulting to every host-scope name), or from raw
 * `recovery_material_bytes` for a malformed archive — and checksums it in the
 * position backup writes it. `omit_recovery_material` builds a schema 3
 * manifest whose archive is missing, for the refusal that must catch it.
 */
function buildBackupFixture(string $namespaceRoot, string $timestamp, array $options = []): string
{
    $dir = $namespaceRoot.'/'.$timestamp;
    mkdir($dir, 0o755, true);

    file_put_contents($dir.'/database.dump', $options['dump'] ?? "FAKE-PG-CUSTOM-DUMP\n");

    $stage = $dir.'.src';
    mkdir($stage.'/app/public', 0o755, true);
    file_put_contents($stage.'/app/restored-marker.txt', "restored\n");
    file_put_contents($stage.'/app/public/restored-public.txt', "restored public\n");

    if (isset($options['archive_builder'])) {
        $options['archive_builder']($stage);
    }

    exec('tar -C '.escapeshellarg($stage).' -czf '.escapeshellarg($dir.'/storage-app.tar.gz').' '.($options['archive_member'] ?? 'app').' 2>&1');
    exec('rm -rf '.escapeshellarg($stage));

    if (isset($options['storage_archive_bytes'])) {
        file_put_contents($dir.'/storage-app.tar.gz', $options['storage_archive_bytes']);
    }

    // A live restore never applies this file, so its default content is
    // deliberately unlike any real .env. A host recovery COMPARES it against
    // the prepared host's own shared/.env and refuses on a difference, so that
    // fixture passes the identical bytes through this option.
    file_put_contents(
        $dir.'/environment.env',
        $options['environment'] ?? "APP_ENV=staging\nDB_PASSWORD=from-backup-never-applied\n",
    );
    file_put_contents($dir.'/server-configuration.tar.gz', "fake server configuration snapshot\n");

    $releaseJson = array_key_exists('release_json', $options)
        ? $options['release_json']
        : ['project' => 'rateguru', 'release' => FIXTURE_RELEASE, 'source_sha' => FIXTURE_SOURCE_SHA];

    file_put_contents(
        $dir.'/release.json',
        is_string($releaseJson) ? $releaseJson : json_encode($releaseJson, JSON_PRETTY_PRINT),
    );

    $schema3 = ($options['schema'] ?? null) === 3;

    $manifest = array_key_exists('manifest', $options)
        ? $options['manifest']
        : backupManifestFixture($schema3 ? ['manifest_schema_version' => 3] : []);

    if (is_array($manifest) && ($manifest['manifest_schema_version'] ?? null) === 3) {
        $schema3 = true;
    }

    if ($manifest !== null) {
        file_put_contents($dir.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
    }

    $files = ['database.dump', 'storage-app.tar.gz', 'environment.env', 'release.json', 'server-configuration.tar.gz'];

    if ($schema3 && maybeWriteRecoveryMaterial($dir, $options)) {
        $files[] = 'recovery-material.tar.gz';
    }

    if ($manifest !== null) {
        $files[] = 'manifest.json';
    }

    $lines = [];
    foreach ($files as $file) {
        $lines[] = hash_file('sha256', $dir.'/'.$file).'  '.$file;
    }

    foreach ($options['extra_sha_lines'] ?? [] as $extra) {
        $lines[] = $extra;
    }

    file_put_contents($dir.'/SHA256SUMS', implode("\n", $lines)."\n");

    if (! empty($options['corrupt_after_checksum'])) {
        file_put_contents($dir.'/database.dump', "TAMPERED\n");
    }

    return $dir;
}

/** @return array<string, mixed> */
function backupManifestFixture(array $overrides = []): array
{
    return array_merge([
        'manifest_schema_version' => 2,
        'project' => 'rateguru',
        'selector' => 'target',
        'target' => 'parity-target',
        'environment' => 'staging',
        'backup_namespace' => 'parity',
        'created_at' => '2026-01-01T00:00:00Z',
        'hostname' => 'test-host',
        'database' => 'parity_db',
        'release' => FIXTURE_RELEASE,
        'postgres_version' => 'pg_dump (PostgreSQL) 18.4',
        'php_version' => '8.5.0',
    ], $overrides);
}

/**
 * A file-backed fake PostgreSQL: one file per database under $scratch/pg/db,
 * holding "<owner> <allowconn>". The psql/createdb/dropdb/pg_restore stubs
 * below read and mutate it, so a rename swap, a connection barrier and a drop
 * are all genuinely observable across separate script invocations — which is
 * what makes the activation/compensation tests real rather than rigged.
 */
function installFakePostgres(string $scratch, array $options = []): void
{
    $catalog = $scratch.'/pg/db';
    @mkdir($catalog, 0o755, true);

    foreach ($options['databases'] ?? ['parity_db' => 'parity_app'] as $name => $owner) {
        file_put_contents($catalog.'/'.$name, $owner." t\n");
    }

    writeExecutable($scratch.'/bin/runuser', "#!/usr/bin/env bash\nshift 2; shift\nexec \"\$@\"\n");

    writeExecutable($scratch.'/bin/psql', <<<'BASH'
#!/usr/bin/env bash
set -uo pipefail
catalog="${RGTEST_PG_CATALOG}"
printf '%s\n' "psql $*" >> "${RGTEST_PSQL_LOG}"

cmd=""
for arg in "$@"; do
    case "${arg}" in
        --command=*) cmd="${arg#--command=}" ;;
    esac
done

db_owner() { [[ -f "${catalog}/$1" ]] && awk '{print $1}' "${catalog}/$1"; }
db_allow() { [[ -f "${catalog}/$1" ]] && awk '{print $2}' "${catalog}/$1"; }

if [[ "${cmd}" =~ SELECT\ 1\ FROM\ pg_database\ WHERE\ datname\ =\ \'([a-z0-9_]+)\' ]]; then
    [[ -f "${catalog}/${BASH_REMATCH[1]}" ]] && printf '1\n'
    exit 0
fi

if [[ "${cmd}" =~ pg_get_userbyid\(datdba\)\ FROM\ pg_database\ WHERE\ datname\ =\ \'([a-z0-9_]+)\' ]]; then
    db_owner "${BASH_REMATCH[1]}"
    exit 0
fi

if [[ "${cmd}" =~ SELECT\ datallowconn\ FROM\ pg_database\ WHERE\ datname\ =\ \'([a-z0-9_]+)\' ]]; then
    db_allow "${BASH_REMATCH[1]}"
    exit 0
fi

if [[ "${cmd}" =~ SELECT\ 1\ FROM\ pg_roles\ WHERE\ rolname\ =\ \'([a-z0-9_]+)\' ]]; then
    grep -qx "${BASH_REMATCH[1]}" "${RGTEST_PG_ROLES}" && printf '1\n'
    exit 0
fi

if [[ "${cmd}" == *"rolcanlogin"* ]]; then
    printf '%s\n' "${RGTEST_ROLE_CANLOGIN:-t}"
    exit 0
fi

if [[ "${cmd}" == *"rolsuper"* ]]; then
    printf '%s\n' "${RGTEST_ROLE_ELEVATED:-}"
    exit 0
fi

if [[ "${cmd}" =~ ALTER\ DATABASE\ \"([a-z0-9_]+)\"\ WITH\ ALLOW_CONNECTIONS\ (true|false) ]]; then
    name="${BASH_REMATCH[1]}"
    value="${BASH_REMATCH[2]}"
    [[ -f "${catalog}/${name}" ]] || { printf 'ERROR: no such database %s\n' "${name}" >&2; exit 1; }
    flag=t
    [[ "${value}" == false ]] && flag=f
    printf '%s %s\n' "$(db_owner "${name}")" "${flag}" > "${catalog}/${name}"
    exit 0
fi

# The row counts a database holds follow it through a rename, exactly as
# they do in PostgreSQL. Without this the counts would be keyed to a name
# rather than to a database, and the staged swap — whose whole point is that
# one name comes to mean a different database — would be unobservable.
move_counts() {
    local from="$1" to="$2" dir
    for dir in "${RGTEST_PG_TABLE_COUNTS:-}" "${RGTEST_PG_MIGRATION_COUNTS:-}"; do
        [[ -n "${dir}" ]] || continue
        [[ -f "${dir}/${from}" ]] || continue
        mv "${dir}/${from}" "${dir}/${to}"
    done
}

if [[ "${cmd}" =~ ALTER\ DATABASE\ \"([a-z0-9_]+)\"\ RENAME\ TO\ \"([a-z0-9_]+)\" ]]; then
    from="${BASH_REMATCH[1]}"
    to="${BASH_REMATCH[2]}"
    if [[ -n "${RGTEST_RENAME_FAIL:-}" ]] && [[ "${RGTEST_RENAME_FAIL}" == "${from}->${to}" ]]; then
        printf 'ERROR: injected rename failure\n' >&2
        exit 1
    fi
    if [[ -n "${RGTEST_RENAME_FAIL_TO_PREFIX:-}" ]] && [[ "${to}" == "${RGTEST_RENAME_FAIL_TO_PREFIX}"* ]]; then
        printf 'ERROR: injected rename failure\n' >&2
        exit 1
    fi
    [[ -f "${catalog}/${from}" ]] || { printf 'ERROR: no such database %s\n' "${from}" >&2; exit 1; }
    [[ ! -f "${catalog}/${to}" ]] || { printf 'ERROR: database %s already exists\n' "${to}" >&2; exit 1; }
    mv "${catalog}/${from}" "${catalog}/${to}"
    move_counts "${from}" "${to}"
    exit 0
fi

if [[ "${cmd}" == *"pg_terminate_backend"* ]]; then
    exit 0
fi

queried_database() {
    local arg
    for arg in "$@"; do
        case "${arg}" in --dbname=*) printf '%s' "${arg#--dbname=}" ;; esac
    done
}

# A per-database count file wins over the flat default when one exists, so a
# fixture can hold an EMPTY prepared database and a populated restored one at
# the same time. With neither directory set, behaviour is exactly the flat
# default it always was.
count_for() {
    local dir="$1" fallback="$2" database
    database="$(queried_database "${@:3}")"

    if [[ -n "${dir}" ]] && [[ -n "${database}" ]] && [[ -f "${dir}/${database}" ]]; then
        cat "${dir}/${database}"

        return 0
    fi

    printf '%s\n' "${fallback}"
}

if [[ "${cmd}" == *"information_schema.tables"* ]]; then
    count_for "${RGTEST_PG_TABLE_COUNTS:-}" "${RGTEST_TABLE_COUNT:-42}" "$@"
    exit 0
fi

if [[ "${cmd}" == *"public.migrations"* ]]; then
    count_for "${RGTEST_PG_MIGRATION_COUNTS:-}" "${RGTEST_MIGRATION_COUNT:-17}" "$@"
    exit 0
fi

if [[ "${cmd}" == "SELECT 1;" ]]; then
    printf '1\n'
    exit 0
fi

printf 'ERROR: unhandled SQL in fake psql: %s\n' "${cmd}" >&2
exit 1
BASH);

    writeExecutable($scratch.'/bin/createdb', <<<'BASH'
#!/usr/bin/env bash
set -uo pipefail
printf '%s\n' "createdb $*" >> "${RGTEST_CREATEDB_LOG}"
[[ "${RGTEST_CREATEDB_EXIT:-0}" == 0 ]] || exit "${RGTEST_CREATEDB_EXIT}"
owner=""
name=""
for arg in "$@"; do
    case "${arg}" in
        --owner=*) owner="${arg#--owner=}" ;;
        --template=*) ;;
        -*) ;;
        *) name="${arg}" ;;
    esac
done
[[ -n "${name}" ]] || exit 1
[[ ! -f "${RGTEST_PG_CATALOG}/${name}" ]] || exit 1
printf '%s t\n' "${owner}" > "${RGTEST_PG_CATALOG}/${name}"
BASH);

    writeExecutable($scratch.'/bin/dropdb', <<<'BASH'
#!/usr/bin/env bash
set -uo pipefail
printf '%s\n' "dropdb $*" >> "${RGTEST_DROPDB_LOG}"
for arg in "$@"; do
    case "${arg}" in
        -*) ;;
        *) rm -f "${RGTEST_PG_CATALOG}/${arg}" ;;
    esac
done
BASH);

    writeExecutable($scratch.'/bin/pg_restore', <<<'BASH'
#!/usr/bin/env bash
set -uo pipefail
printf '%s\n' "pg_restore $*" >> "${RGTEST_PG_RESTORE_LOG}"
if [[ -n "${PGPASSWORD:-}" ]]; then
    printf 'pgpassword-present\n' >> "${RGTEST_PG_RESTORE_LOG}"
fi
cat >/dev/null

status="${RGTEST_PG_RESTORE_EXIT:-0}"

# A successful restore gives the target database the row counts the dump
# carried, so "the staged database holds the backup's data" is observable
# rather than assumed. Only when the fixture asked for per-database counts.
if [[ "${status}" == 0 ]]; then
    database=""
    for arg in "$@"; do
        case "${arg}" in --dbname=*) database="${arg#--dbname=}" ;; esac
    done

    if [[ -n "${database}" ]]; then
        [[ -z "${RGTEST_PG_TABLE_COUNTS:-}" ]] \
            || printf '%s\n' "${RGTEST_RESTORED_TABLE_COUNT:-42}" > "${RGTEST_PG_TABLE_COUNTS}/${database}"
        [[ -z "${RGTEST_PG_MIGRATION_COUNTS:-}" ]] \
            || printf '%s\n' "${RGTEST_RESTORED_MIGRATION_COUNT:-17}" > "${RGTEST_PG_MIGRATION_COUNTS}/${database}"
    fi
fi

exit "${status}"
BASH);

    file_put_contents($scratch.'/pg/roles', implode("\n", $options['roles'] ?? ['parity_app'])."\n");

    foreach (['psql', 'createdb', 'dropdb', 'pg_restore'] as $log) {
        touch($scratch.'/'.$log.'.log');
    }
}

/** @return array<string, string> */
function fakePostgresEnv(string $scratch): array
{
    return [
        'RGTEST_PG_CATALOG' => $scratch.'/pg/db',
        'RGTEST_PG_ROLES' => $scratch.'/pg/roles',
        'RGTEST_PSQL_LOG' => $scratch.'/psql.log',
        'RGTEST_CREATEDB_LOG' => $scratch.'/createdb.log',
        'RGTEST_DROPDB_LOG' => $scratch.'/dropdb.log',
        'RGTEST_PG_RESTORE_LOG' => $scratch.'/pg_restore.log',
        'RATEGURU_CREATEDB_BIN' => $scratch.'/bin/createdb',
        'RATEGURU_DROPDB_BIN' => $scratch.'/bin/dropdb',
        'RATEGURU_PG_RESTORE_BIN' => $scratch.'/bin/pg_restore',
        'RATEGURU_PSQL_BIN' => $scratch.'/bin/psql',
        'RATEGURU_RESTORE_RUNUSER_BIN' => $scratch.'/bin/runuser',
    ];
}

/** The baseline environment every Restore Target Data script invocation needs. */
function infraScriptEnv(string $scratch, string $registryPath, string $targetsPath, array $overrides = []): array
{
    return array_merge([
        'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'HOME' => getenv('HOME') ?: '/tmp',
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_COMMON_FILE' => infraScript('common'),
        // The patched copy: restore-common's own workspace/history creation
        // uses `install -o root -g root`, which needs real root. Only that is
        // rewritten; every line of logic under test is byte-identical.
        'RATEGURU_RESTORE_COMMON_FILE' => patchedInfraScript($scratch, 'restore-common'),
        'RATEGURU_DEPLOYMENT_CONF_FILE' => deploymentConfFixture($scratch),
        'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
        'RATEGURU_TARGETS_CLI' => $targetsPath,
        'RATEGURU_BACKUP_BASE' => $scratch.'/backups',
        'RATEGURU_RUN_ROOT' => $scratch.'/run',
        'RATEGURU_RESTORE_HISTORY_ROOT' => $scratch.'/restores',
        'RATEGURU_RESTORE_CRON_D_ROOT' => $scratch.'/cron.d',
        'RATEGURU_RESTORE_WEB_GROUP' => trim((string) shell_exec('id -gn')),
        'RGTEST_BYPASS_ROOT' => 'true',
    ], $overrides);
}

/**
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function runInfraScript(string $scriptPath, array $arguments, array $env): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(array_merge(['bash', $scriptPath], $arguments), $descriptors, $pipes, null, $env);

    expect($process)->not->toBeFalse('could not start the script under test');

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return [proc_close($process), $output];
}

/**
 * The GitHub Environment values one recovery workflow actually reads, by kind.
 *
 * Derived from the workflow source rather than restated, so a value that is
 * added, removed or moved between a variable and a secret is a change every
 * test and every document that names the set has to answer for.
 *
 * @return array{vars: list<string>, secrets: list<string>, all: list<string>}
 */
function recoveryValuesRead(string $workflow): array
{
    $source = File::get(base_path('.github/workflows/'.$workflow));

    $byKind = static function (string $kind) use ($source): array {
        preg_match_all('/\b'.$kind.'\.((?:RECOVERY|DEPLOY)_[A-Z_]+)\b/', $source, $matches);

        $names = array_values(array_unique($matches[1]));
        sort($names);

        return $names;
    };

    $vars = $byKind('vars');
    $secrets = $byKind('secrets');

    $all = array_values(array_unique([...$vars, ...$secrets]));
    sort($all);

    return ['vars' => $vars, 'secrets' => $secrets, 'all' => $all];
}

/**
 * One composite action step's `run:` body, executed for real.
 *
 * A transport step is ordinary Bash under `set -Eeuo pipefail`, and the way it
 * treats a remote failure — what it captures, what it prints, what it exits
 * with — is behaviour, not text. Running the step against a stub `ssh` is the
 * only way to prove it, and it is exactly how the diagnostics of a failed
 * remote invocation got lost once already.
 *
 * @param  array<string, string>  $env
 * @return array{exit: int, output: string}
 */
function runActionStep(string $actionPath, string $stepName, array $env): array
{
    // The steps are written for the ubuntu-24.04 runner and use Bash 4+ parameter
    // expansion (${array[@]@Q}). macOS ships Bash 3.2 as /bin/bash, which
    // cannot execute them faithfully — skipping is honest there; CI runs it.
    exec('bash -c \'echo "${BASH_VERSINFO[0]}"\' 2>/dev/null', $probe, $probeStatus);

    if ($probeStatus !== 0 || (int) ($probe[0] ?? 0) < 4) {
        test()->markTestSkipped('needs Bash 4+; this host offers '.($probe[0] ?? 'no bash'));
    }

    $action = Yaml::parseFile(base_path($actionPath));

    $step = collect($action['runs']['steps'] ?? [])
        ->first(static fn (array $candidate): bool => ($candidate['name'] ?? '') === $stepName);

    expect($step)->not->toBeNull("{$actionPath} has no step named {$stepName}");

    // GitHub defines every variable a step declares under `env:`, including
    // the ones whose value is empty — an unset optional input is empty, not
    // absent, and a step reading it under `set -u` depends on that. PHP's
    // proc_open drops an empty value entirely, so those are declared in the
    // script instead of being passed through the process environment.
    $empty = array_filter($env, static fn (string $value): bool => $value === '');

    $preamble = implode('', array_map(
        static fn (string $name): string => 'export '.$name."=''\n",
        array_keys($empty),
    ));

    $script = tempnam(sys_get_temp_dir(), 'rateguru-action-step-');
    file_put_contents($script, "#!/usr/bin/env bash\n".$preamble.($step['run'] ?? ''));

    [$exit, $output] = runInfraScript($script, [], array_diff_key($env, $empty));

    unlink($script);

    return ['exit' => $exit, 'output' => $output];
}

/**
 * The `ssh` a transport step meets in these tests: it records its argv, writes
 * whatever the case needs to stdout and stderr, and exits with the status the
 * case needs. Nothing connects anywhere.
 */
function sshStub(string $scratch): string
{
    @mkdir($scratch.'/bin', 0o755, true);
    touch($scratch.'/ssh.log');

    foreach (['ssh', 'scp'] as $name) {
        writeExecutable($scratch.'/bin/'.$name, <<<'BASH'
#!/usr/bin/env bash
set -uo pipefail
printf '%s\n' "$(basename -- "$0") $*" >> "${RGTEST_SSH_LOG}"

[[ -z "${RGTEST_SSH_STDOUT:-}" ]] || printf '%s\n' "${RGTEST_SSH_STDOUT}"
[[ -z "${RGTEST_SSH_STDERR:-}" ]] || printf '%s\n' "${RGTEST_SSH_STDERR}" >&2

exit "${RGTEST_SSH_EXIT:-0}"
BASH);
    }

    return $scratch.'/bin/ssh';
}

/**
 * The runner-side environment a transport step runs in: the GitHub files it
 * appends to, a scratch RUNNER_TEMP, and a PATH whose ssh is the stub.
 *
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function actionStepEnv(string $scratch, array $overrides = []): array
{
    sshStub($scratch);

    foreach (['github-output', 'github-step-summary'] as $file) {
        touch($scratch.'/'.$file);
    }

    return array_merge([
        'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'HOME' => getenv('HOME') ?: '/tmp',
        'RUNNER_TEMP' => $scratch,
        'GITHUB_OUTPUT' => $scratch.'/github-output',
        'GITHUB_STEP_SUMMARY' => $scratch.'/github-step-summary',
        'RGTEST_SSH_LOG' => $scratch.'/ssh.log',
    ], $overrides);
}

/**
 * Sources a script (so its functions exist without main() running) and
 * executes an arbitrary body against them — the technique BackupTest and
 * RestoreTest already use for coverage that must bypass require_root.
 *
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function runInfraHarness(string $scratch, string $scriptPath, string $body, array $env): array
{
    $harness = $scratch.'/harness-'.uniqid('', true).'.sh';
    file_put_contents($harness, "set -Eeuo pipefail\nsource ".escapeshellarg($scriptPath)."\n".$body."\n");

    return runInfraScript($harness, [], $env);
}

/** Extracts every operation ID a script printed, newest last. */
function operationIdsIn(string $output): array
{
    preg_match_all('/\b(\d{8}-\d{6}-[0-9a-f]{6})\b/', $output, $matches);

    return array_values(array_unique($matches[1]));
}

/**
 * A restore operation workspace with a state document in a chosen phase, and
 * a real staged backup inside it — the exact shape restore-target hands the
 * restore-database/restore-storage primitives.
 *
 * @param  array<string, string>  $state
 */
function restoreWorkspaceFixture(string $scratch, string $operationId, array $state = [], array $backupOptions = []): string
{
    $workspace = $scratch.'/run/restores/parity-target/'.$operationId;
    mkdir($workspace.'/selected-backup', 0o700, true);
    chmod($workspace, 0o700);

    $backup = buildBackupFixture($scratch.'/source-'.$operationId, '20260115-120000', $backupOptions);

    foreach (scandir($backup) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        copy($backup.'/'.$entry, $workspace.'/selected-backup/'.$entry);
    }

    file_put_contents($workspace.'/state.json', json_encode(array_merge([
        'operation_id' => $operationId,
        'target' => 'parity-target',
        'environment' => 'staging',
        'backup_namespace' => 'parity',
        'source' => 'local',
        'backup' => '20260115-120000',
        'status' => 'running',
        'phase' => 'backup-verified',
    ], $state), JSON_PRETTY_PRINT));
    chmod($workspace.'/state.json', 0o600);

    return $workspace;
}

/** @return array<string, mixed> */
function restoreOperationState(string $workspace): array
{
    return json_decode(File::get($workspace.'/state.json'), true);
}

/** The database names restore-database derives for a given operation. */
function stagedDatabaseName(string $operationId): string
{
    return 'rateguru_rst_parity_'.str_replace('-', '_', $operationId);
}

function preRestoreDatabaseName(string $operationId): string
{
    return 'rateguru_pre_parity_'.str_replace('-', '_', $operationId);
}

/** Every database the fake catalog currently holds. */
function fakePostgresDatabases(string $scratch): array
{
    $entries = array_values(array_diff(scandir($scratch.'/pg/db'), ['.', '..']));
    sort($entries);

    return $entries;
}

/** Advances an operation's recorded phase, the way restore-target does. */
function setRestoreOperationPhase(string $workspace, string $phase): void
{
    $state = restoreOperationState($workspace);
    $state['phase'] = $phase;

    file_put_contents($workspace.'/state.json', json_encode($state, JSON_PRETTY_PRINT));
}

/*
|--------------------------------------------------------------------------
| restore-target harness
|--------------------------------------------------------------------------
|
| The fixture, the run and the observers the four restore-target test files
| share: RestoreTargetTest (the whole restore), RestoreTargetRuntimeQuiesceTest,
| RestoreTargetCodeAlignmentHoldTest and RestoreTargetInspectTest.
*/

function restoreTargetScript(): string
{
    return base_path('infrastructure/scripts/restore-target');
}

/**
 * The full fixture: target tree, fake catalog, cron entry, local backup to
 * restore from, and the emergency-backup template the `backup` stub copies.
 *
 * @return array<string, string>
 */
function restoreTargetFixture(string $scratch, array $options = []): array
{
    targetTreeFixture($scratch, [
        'source_sha' => $options['current_source_sha'] ?? FIXTURE_SOURCE_SHA,
        'release' => $options['current_release'] ?? FIXTURE_RELEASE,
    ]);
    installFakePostgres($scratch, $options['postgres'] ?? []);
    installTargetRuntimeStubs($scratch);

    // The backup being restored from, and a byte-identical template the
    // emergency `backup` stub copies into place as the new latest backup.
    buildBackupFixture($scratch.'/backups/parity', '20260115-120000', $options['backup'] ?? []);
    buildBackupFixture($scratch.'/emergency-src', '20260116-090000');
    exec('mv '.escapeshellarg($scratch.'/emergency-src/20260116-090000').' '.escapeshellarg($scratch.'/emergency-template'));

    if (($options['scheduler'] ?? true) === true) {
        mkdir($scratch.'/cron.d', 0o755, true);
        file_put_contents(
            $scratch.'/cron.d/parity-scheduler',
            "* * * * * runtime cd /target/current && php artisan schedule:run\n",
        );
    }

    [$registryPath, $targetsPath] = parityRegistryFixture($scratch);

    return ['registry' => $registryPath, 'targets' => $targetsPath];
}

/**
 * @return array{exit: int, output: string}
 */
function restoreTargetRun(string $scratch, array $arguments, array $envOverrides = []): array
{
    [$registryPath, $targetsPath] = parityRegistryFixture($scratch);

    $env = infraScriptEnv($scratch, $registryPath, $targetsPath, array_merge(
        fakePostgresEnv($scratch),
        targetRuntimeEnv($scratch),
        [
            'RATEGURU_RESTORE_FETCH_BACKUP_BIN' => patchedInfraScript($scratch, 'fetch-backup'),
            'RATEGURU_RESTORE_VERIFY_BACKUP_BIN' => patchedInfraScript($scratch, 'verify-backup'),
            'RATEGURU_RESTORE_DATABASE_BIN' => patchedInfraScript($scratch, 'restore-database'),
            'RATEGURU_RESTORE_STORAGE_BIN' => patchedInfraScript($scratch, 'restore-storage'),
        ],
        $envOverrides,
    ));

    [$exit, $output] = runInfraScript(patchedInfraScript($scratch, 'restore-target'), $arguments, $env);

    return ['exit' => $exit, 'output' => $output];
}

function restoreTargetApply(string $scratch, array $envOverrides = []): array
{
    return restoreTargetRun($scratch, [
        '--apply', '--target', 'parity-target', '--source', 'local', '--backup', '20260115-120000',
    ], $envOverrides);
}

/** @return list<array<string, mixed>> */
function restoreTargetHistory(string $scratch): array
{
    $path = $scratch.'/restores/restore-history.jsonl';

    if (! is_file($path)) {
        return [];
    }

    return array_map(
        static fn (string $line): array => json_decode($line, true),
        array_values(array_filter(preg_split('/\R/', trim(File::get($path))))),
    );
}

function restoreTargetStorage(string $scratch): string
{
    return $scratch.'/target/shared/storage';
}

function restoreTargetMaintenanceActive(string $scratch): bool
{
    return is_file(restoreTargetStorage($scratch).'/framework/down');
}

function restoreTargetQueueState(string $scratch): string
{
    return trim(File::get($scratch.'/supervisor-state'));
}

function restoreTargetSchedulerPresent(string $scratch): bool
{
    return is_file($scratch.'/cron.d/parity-scheduler');
}

/** Runs an apply that ends held, and returns its operation ID. */
function restoreTargetHeldOperation(string $scratch): string
{
    $result = restoreTargetApply($scratch);
    expect($result['exit'])->toBe(0, $result['output']);
    expect($result['output'])->toContain('CODE ALIGNMENT: REQUIRED');

    $operations = operationIdsIn($result['output']);
    expect($operations)->toHaveCount(1);

    return $operations[0];
}

/**
 * A target whose code does not match the backup, restored from it and held for
 * code alignment in $scratch — the state every test of the hold, of --resume
 * and of --inspect starts from — and the operation that holds it.
 *
 * Getting there is a whole --apply: stage, verify, quiesce, the emergency
 * backup, both swaps. That is 1.3–2 s, most of what each of those tests costs,
 * and its result never varies. So a worker holds a target once, into a
 * template of its own, and every later call copies the template into the
 * test's scratch directory: about 40 ms, the registry included.
 *
 * copyScratchTemplate() rewrites the absolute paths the operation recorded and
 * proves none still names the template. `cp -a` keeps the hard links between
 * the staged backup and the backup it was staged from, inside each copy and
 * never across into the template. The registry is then written again, exactly
 * as restoreTargetFixture() writes it, so the copy holds what a fresh fixture
 * would rather than a rewritten one.
 * Every copy carries the same operation ID and the template's timestamps;
 * nothing compares either to the clock.
 */
function restoreTargetHeldForCodeAlignment(string $scratch): string
{
    static $template = null;

    if ($template === null) {
        $directory = restoreScratchDir();
        register_shutdown_function(fn () => removeScratchDir($directory));

        restoreTargetFixture($directory, [
            'current_release' => FIXTURE_OTHER_RELEASE,
            'current_source_sha' => FIXTURE_OTHER_SOURCE_SHA,
        ]);

        $template = [$directory, restoreTargetHeldOperation($directory)];
    }

    [$directory, $operation] = $template;

    copyScratchTemplate($directory, $scratch);
    parityRegistryFixture($scratch);

    return $operation;
}

/** Deploys the aligned release, the way the controlled alignment deploy would. */
function restoreTargetAlignCode(string $scratch): void
{
    $aligned = $scratch.'/target/releases/'.FIXTURE_RELEASE;
    mkdir($aligned, 0o755, true);
    file_put_contents($aligned.'/artisan', "<?php\n");
    file_put_contents(
        $aligned.'/release.json',
        json_encode(['project' => 'rateguru', 'release' => FIXTURE_RELEASE, 'source_sha' => FIXTURE_SOURCE_SHA]),
    );

    unlink($scratch.'/target/current');
    symlink($aligned, $scratch.'/target/current');
}

/** The marker restore-target writes for a held target. */
function restoreGuardFile(string $scratch): string
{
    return $scratch.'/run/restores/parity-target/restore-guard';
}

/**
 * Builds a tar.gz from an explicit entry spec, so an archive containing a
 * symlink, hardlink, device node, FIFO, absolute path or `..` component can
 * be constructed exactly — none of which a filesystem-based `tar -c` can
 * reliably produce on every platform this suite runs on.
 *
 * @param  list<array{name: string, type: string, link?: string}>  $entries
 */
function buildArchiveFixture(string $path, array $entries): void
{
    $python = <<<'PY'
import io, json, sys, tarfile

spec = json.loads(sys.argv[2])

with tarfile.open(sys.argv[1], "w:gz") as tf:
    for entry in spec:
        info = tarfile.TarInfo(entry["name"])
        kind = entry["type"]

        if kind == "dir":
            info.type = tarfile.DIRTYPE
            info.mode = 0o755
        elif kind == "file":
            info.type = tarfile.REGTYPE
            info.mode = 0o644
            info.size = 1
        elif kind == "symlink":
            info.type = tarfile.SYMTYPE
            info.linkname = entry.get("link", "/etc/passwd")
        elif kind == "hardlink":
            info.type = tarfile.LNKTYPE
            info.linkname = entry.get("link", "app/regular.txt")
        elif kind == "fifo":
            info.type = tarfile.FIFOTYPE
        elif kind == "chardev":
            info.type = tarfile.CHRTYPE
            info.devmajor, info.devminor = 1, 3
        elif kind == "blockdev":
            info.type = tarfile.BLKTYPE
            info.devmajor, info.devminor = 8, 0
        else:
            raise SystemExit("unknown entry type: " + kind)

        if info.type == tarfile.REGTYPE:
            tf.addfile(info, io.BytesIO(b"x"))
        else:
            tf.addfile(info)
PY;

    $script = sys_get_temp_dir().'/rateguru-archive-'.uniqid('', true).'.py';
    file_put_contents($script, $python);

    exec(
        'python3 '.escapeshellarg($script).' '.escapeshellarg($path).' '.escapeshellarg(json_encode($entries)).' 2>&1',
        $output,
        $exit,
    );

    unlink($script);

    expect($exit)->toBe(0, "could not build the archive fixture:\n".implode("\n", $output));
}

/**
 * The target-runtime stubs a full restore-target run needs: this target's own
 * Supervisor program, its Laravel maintenance mode, the existing `backup` and
 * `restore-test` implementations it reuses for the emergency backup, the
 * health check, and pgrep for the scheduler barrier.
 *
 * Each records what it was asked to do, so a test can assert BOTH the runtime
 * state that resulted and the fact that nothing global was ever touched.
 */
function installTargetRuntimeStubs(string $scratch): void
{
    writeExecutable($scratch.'/bin/supervisorctl', <<<'BASH'
#!/usr/bin/env bash
set -uo pipefail
printf '%s\n' "supervisorctl $*" >> "${RGTEST_SUPERVISOR_LOG}"

action="${1:-}"
group="${2:-}"

# Supervisor's own status exit codes, from supervisor 4.2.1
# (supervisorctl.py LSBStatusExitStatuses, states.py STOPPED_STATES):
#
#   0  every matched process is in a running-ish state
#   3  at least one matched process is STOPPED, EXITED, FATAL or UNKNOWN
#   4  upcheck() failed, or a name matched nothing
#
# Modelling this faithfully is the point: the stub used to exit 0 for every
# status, which is why a real staging restore — where a correctly STOPPED queue
# reports rc 3 — was not caught here first.
supervisor_status_rc() {
    local rc=0 state

    for state in "$@"; do
        case "${state}" in
            STOPPED|EXITED|FATAL|UNKNOWN) rc=3 ;;
        esac
    done

    printf '%s\n' "${rc}"
}

# The PRE_DEPLOY shape a prepared, never-deployed host is in: the program
# configuration is installed, and its group has not been added to the running
# Supervisor because `supervisorctl update` was deferred. supervisorctl answers
# every request about such a group on STDOUT with exit 4, and `update` is what
# adds it (starting it, because the committed program sets autostart=true).
group_absent() {
    [[ -n "${RGTEST_SUPERVISOR_GROUP_ABSENT:-}" ]] && [[ -e "${RGTEST_SUPERVISOR_GROUP_ABSENT}" ]]
}

no_such_group() {
    printf '%s: ERROR (no such group)\n' "${group%:*}"
    exit 4
}

case "${action}" in
    reread)
        exit "${RGTEST_SUPERVISOR_REREAD_EXIT:-0}"
        ;;
    update)
        [[ "${RGTEST_SUPERVISOR_UPDATE_EXIT:-0}" == 0 ]] || exit "${RGTEST_SUPERVISOR_UPDATE_EXIT}"

        if group_absent; then
            rm -f "${RGTEST_SUPERVISOR_GROUP_ABSENT}"
            printf '%s\n' "${RGTEST_SUPERVISOR_UPDATE_STATE:-RUNNING}" > "${RGTEST_SUPERVISOR_STATE}"
        fi
        ;;
    status)
        group_absent && no_such_group

        # An observation failure that is NOT a process state: supervisord
        # unreachable, or the group unknown. do_status overrides the exit
        # status to 4 for both.
        if [[ -n "${RGTEST_SUPERVISOR_STATUS_FAILURE:-}" ]]; then
            printf '%s\n' "${RGTEST_SUPERVISOR_STATUS_FAILURE}" >&2
            exit "${RGTEST_SUPERVISOR_STATUS_FAILURE_RC:-4}"
        fi

        # Arbitrary stdout, for the malformed / wrong-group cases.
        if [[ -n "${RGTEST_SUPERVISOR_STATUS_STDOUT:-}" ]]; then
            printf '%s\n' "${RGTEST_SUPERVISOR_STATUS_STDOUT}"
            exit "${RGTEST_SUPERVISOR_STATUS_RC:-0}"
        fi

        state="$(cat "${RGTEST_SUPERVISOR_STATE}")"

        # A worker that comes back AFTER it was stopped. Without a way to model
        # it, "something started the queue between the hold and the final
        # proof" — the exact hazard a last-moment runtime proof exists for — is
        # untestable.
        #
        # Anchored on the stop rather than on a raw call count, so a test does
        # not have to know how many times an operation happens to read the
        # group: RGTEST_SUPERVISOR_FLIP_AFTER_STOP=1 means the FIRST read after
        # the stop still sees it stopped (the confirmation the stop itself
        # waits for) and every read after that sees it back. Unset, nothing
        # changes.
        if [[ -n "${RGTEST_SUPERVISOR_FLIP_AFTER_STOP:-}" ]] \
            && [[ -f "${RGTEST_SUPERVISOR_STATE}.stopped" ]]
        then
            observed=$(( $(cat "${RGTEST_SUPERVISOR_STATE}.stopped") + 1 ))
            printf '%s\n' "${observed}" > "${RGTEST_SUPERVISOR_STATE}.stopped"

            if (( observed > RGTEST_SUPERVISOR_FLIP_AFTER_STOP )); then
                state="${RGTEST_SUPERVISOR_FLIP_STATE:-RUNNING}"
            fi
        fi

        printf '%-40s %s   pid 4242, uptime 0:10:00\n' "${group%:*}:${group%:*}_00" "${state}"

        # A second process in the same group, so a MIXED group (one RUNNING,
        # one FATAL) can be exercised the way a real crash-looping worker
        # presents. Empty means a single-process group.
        second="$(cat "${RGTEST_SUPERVISOR_SECOND_STATE}" 2>/dev/null || true)"
        if [[ -n "${second}" ]]; then
            printf '%-40s %s   pid 4243, uptime 0:00:01\n' "${group%:*}:${group%:*}_01" "${second}"
        fi

        exit "$(supervisor_status_rc "${state}" ${second:+"${second}"})"
        ;;
    stop)
        group_absent && no_such_group

        # supervisorctl stop takes the whole group down, second process included.
        # RGTEST_SUPERVISOR_STOP_STATE models a stop that TOOK EFFECT but landed
        # somewhere other than STOPPED — the state a confirmation timeout sees.
        printf '%s\n' "${RGTEST_SUPERVISOR_STOP_STATE:-STOPPED}" > "${RGTEST_SUPERVISOR_STATE}"
        # Opens the post-stop observation window RGTEST_SUPERVISOR_FLIP_AFTER_STOP counts in.
        printf '0\n' > "${RGTEST_SUPERVISOR_STATE}.stopped"
        [[ -z "$(cat "${RGTEST_SUPERVISOR_SECOND_STATE}" 2>/dev/null || true)" ]] \
            || printf '%s\n' "${RGTEST_SUPERVISOR_STOP_STATE:-STOPPED}" > "${RGTEST_SUPERVISOR_SECOND_STATE}"
        ;;
    start)
        group_absent && no_such_group

        # RGTEST_SUPERVISOR_START_STATE models a start that TOOK EFFECT but has
        # not reached RUNNING — STARTING, or a worker crash-looping in BACKOFF.
        printf '%s\n' "${RGTEST_SUPERVISOR_START_STATE:-RUNNING}" > "${RGTEST_SUPERVISOR_STATE}"
        [[ -z "$(cat "${RGTEST_SUPERVISOR_SECOND_STATE}" 2>/dev/null || true)" ]] \
            || printf '%s\n' "${RGTEST_SUPERVISOR_START_STATE:-RUNNING}" > "${RGTEST_SUPERVISOR_SECOND_STATE}"
        ;;
    *)
        exit 1
        ;;
esac
BASH);

    writeExecutable($scratch.'/bin/php', <<<'BASH'
#!/usr/bin/env bash
set -uo pipefail
printf '%s\n' "php $*" >> "${RGTEST_PHP_LOG}"

case "${2:-}" in
    down)
        [[ "${RGTEST_ARTISAN_DOWN_EXIT:-0}" == 0 ]] || exit "${RGTEST_ARTISAN_DOWN_EXIT}"
        printf '{"time":0}\n' > "${RGTEST_MAINTENANCE_FLAG}"
        ;;
    up)
        [[ "${RGTEST_ARTISAN_UP_EXIT:-0}" == 0 ]] || exit "${RGTEST_ARTISAN_UP_EXIT}"
        # RGTEST_ARTISAN_UP_INEFFECTIVE models `artisan up` reporting success
        # while the target stays down.
        [[ -n "${RGTEST_ARTISAN_UP_INEFFECTIVE:-}" ]] || rm -f "${RGTEST_MAINTENANCE_FLAG}"
        ;;
    schedule:interrupt)
        exit "${RGTEST_SCHEDULE_INTERRUPT_EXIT:-0}"
        ;;
    *)
        ;;
esac
BASH);

    writeExecutable($scratch.'/bin/backup-stub', <<<'BASH'
#!/usr/bin/env bash
set -uo pipefail
printf '%s\n' "backup $*" >> "${RGTEST_BACKUP_LOG}"
[[ "${RGTEST_BACKUP_EXIT:-0}" == 0 ]] || exit "${RGTEST_BACKUP_EXIT}"

mkdir -p "${RGTEST_BACKUP_NAMESPACE_ROOT}"

# "none" is the explicit "this backup run produced nothing" case: an empty
# environment value cannot express it, since a shell default would take over.
ids="${RGTEST_EMERGENCY_BACKUP_IDS:-20260116-090000}"

if [[ "${ids}" != none ]]; then
    for stamp in ${ids}; do
        cp -a "${RGTEST_BACKUP_TEMPLATE}" "${RGTEST_BACKUP_NAMESPACE_ROOT}/${stamp}"
    done
fi
BASH);

    writeExecutable($scratch.'/bin/restore-test-stub', <<<'BASH'
#!/usr/bin/env bash
set -uo pipefail
printf '%s\n' "restore-test $*" >> "${RGTEST_RESTORE_TEST_LOG}"
exit "${RGTEST_RESTORE_TEST_EXIT:-0}"
BASH);

    writeExecutable($scratch.'/bin/health-check-stub', <<<'BASH'
#!/usr/bin/env bash
set -uo pipefail
printf '%s\n' "health-check $*" >> "${RGTEST_HEALTH_CHECK_LOG}"
exit "${RGTEST_HEALTH_CHECK_EXIT:-0}"
BASH);

    // Nothing is running by default: pgrep exits 1 when no process matches.
    writeExecutable($scratch.'/bin/pgrep', <<<'BASH'
#!/usr/bin/env bash
set -uo pipefail
printf '%s\n' "pgrep $*" >> "${RGTEST_PGREP_LOG}"
exit "${RGTEST_PGREP_EXIT:-1}"
BASH);

    file_put_contents($scratch.'/supervisor-state', "RUNNING\n");
    file_put_contents($scratch.'/supervisor-second-state', '');

    foreach (['supervisor', 'php', 'backup', 'restore-test', 'health-check', 'pgrep'] as $log) {
        touch($scratch.'/'.$log.'.log');
    }
}

/**
 * An rclone stub that serves exactly one fixed remote directory tree from
 * disk, and records every argument vector it was given — so a test can prove
 * the remote path was composed from the registry and the fixed bucket rather
 * than from anything a caller supplied.
 *
 * Shared: fetch-backup's own offsite staging and the host recovery that drives
 * it both need the identical fake remote, and two copies would drift into
 * disagreeing about what a remote path even looks like.
 */
function offsiteRcloneStub(string $scratch): string
{
    return writeExecutable($scratch.'/bin/rclone', <<<'BASH'
#!/usr/bin/env bash
set -uo pipefail
printf '%s\n' "rclone $*" >> "${RGTEST_RCLONE_LOG}"

# rclone --config X copy SOURCE DEST [flags...]
# rclone --config X copyto SOURCE_FILE DEST_FILE [flags...]
source_path=""
dest_path=""
seen_copy=false
copy_verb=""
positional=0
while [[ $# -gt 0 ]]; do
    case "$1" in
        copy|copyto) seen_copy=true; copy_verb="$1"; shift ;;
        --config) shift 2 ;;
        # `--stats 10s` takes a value; every other flag rclone is given here
        # is a bare switch. Counting positionals rather than taking "the last
        # bare token" is what keeps a flag value out of the destination path.
        --stats) shift 2 ;;
        --*) shift ;;
        *)
            if [[ "${seen_copy}" == true ]] && (( positional < 2 )); then
                if (( positional == 0 )); then source_path="$1"; else dest_path="$1"; fi
                positional=$(( positional + 1 ))
            fi
            shift
            ;;
    esac
done

[[ "${seen_copy}" == true ]] || exit 1

# A relative destination means the argument parsing above mistook a flag
# VALUE for a path — which would silently copy a backup into whatever
# directory the test runner happened to be in. Fail loudly instead.
[[ "${dest_path}" == /* ]] || {
    printf 'ERROR: stub refuses a relative destination: %s\n' "${dest_path}" >&2
    exit 1
}

local_source="${RGTEST_REMOTE_ROOT}/${source_path}"

if [[ "${copy_verb}" == copyto ]]; then
    # One object to one local file, exactly as B2 answers copyto: a missing
    # object is an error, never an empty file.
    if [[ ! -f "${local_source}" ]]; then
        printf 'ERROR: remote object not found: %s\n' "${source_path}" >&2
        exit 1
    fi

    cp "${local_source}" "${dest_path}"
    exit $?
fi

if [[ ! -d "${local_source}" ]]; then
    printf 'ERROR: remote directory not found: %s\n' "${source_path}" >&2
    exit 1
fi

cp -a "${local_source}/." "${dest_path}/"
BASH);
}

/*
|--------------------------------------------------------------------------
| Host recovery fixtures
|--------------------------------------------------------------------------
|
| A recovery starts where a restore cannot: on a PREPARED but EMPTY target —
| the PRE_DEPLOY state Prepare Host produces, with no current, no previous, no
| releases, an empty database and a storage root whose `app` tree does not
| exist yet because the host layout leaves Laravel's descendants to the
| deployment pipeline.
*/

/**
 * The environment file a prepared host carries, and — byte for byte — the
 * `environment.env` its backup must contain. The recovery refuses on any
 * difference, so a fixture that let the two drift would exercise the refusal
 * instead of the recovery.
 */
function preparedEnvironmentContents(array $options = []): string
{
    return implode("\n", [
        'APP_ENV=staging',
        'DB_CONNECTION=pgsql',
        'DB_HOST=127.0.0.1',
        'DB_PORT=5432',
        'DB_DATABASE='.($options['database'] ?? 'parity_db'),
        'DB_USERNAME='.($options['role'] ?? 'parity_app'),
        'DB_PASSWORD=s3cr3t-not-logged',
        '',
    ]);
}

/**
 * A prepared, EMPTY replacement target: exactly what prepare-host leaves
 * behind, and nothing a deployment would have added.
 */
function preparedTargetTreeFixture(string $scratch, array $options = []): string
{
    $root = $scratch.'/target';

    mkdir($root.'/releases', 0o755, true);
    mkdir($root.'/shared/storage', 0o755, true);
    mkdir($root.'/locks', 0o755, true);
    mkdir($root.'/deployments', 0o755, true);
    mkdir($root.'/incoming', 0o755, true);

    file_put_contents($root.'/shared/.env', $options['environment'] ?? preparedEnvironmentContents($options));

    // The host layout stops at shared/storage: shared/storage/app is created
    // by the first deployment, and its absence is the normal prepared shape.
    if (($options['storage_app'] ?? false) === true) {
        mkdir($root.'/shared/storage/app', 0o2710, true);
    }

    return $root;
}

/**
 * The prepare-host stub a recovery runs its own prepared-host verification
 * through. Records its argv, and fails on demand so the "this machine is not
 * prepared" refusal can be exercised without a real bootstrap.
 */
function installFakePrepareHost(string $scratch): string
{
    touch($scratch.'/prepare-host.log');

    return writeExecutable($scratch.'/bin/prepare-host-stub', <<<'BASH'
#!/usr/bin/env bash
set -uo pipefail
printf '%s
' "prepare-host $*" >> "${RGTEST_PREPARE_HOST_LOG}"
[[ "${RGTEST_PREPARE_HOST_EXIT:-0}" == 0 ]] || {
    printf 'SLICE bootstrap — FAIL
' >&2
    exit "${RGTEST_PREPARE_HOST_EXIT}"
}
printf 'TARGET PREPARED: YES
'
BASH);
}

/**
 * A real offsite remote for the recovery to download from: one backup, in the
 * exact layout the fixed remote path composes, whose environment.env matches
 * the prepared host's shared/.env byte for byte.
 */
function recoveryOffsiteBackupFixture(string $scratch, string $backupId = '20260115-023000', array $options = []): string
{
    $remoteRoot = $scratch.'/remote/rateguru-b2:rateguru-database-backups/rateguru/parity';
    @mkdir($remoteRoot, 0o755, true);

    // A clean-host recovery requires a schema 3 backup — the one that carries
    // the recovery material Prepare Host was fed — so that is what a recovery
    // fixture is unless a test asks for an older one on purpose.
    return buildBackupFixture($remoteRoot, $backupId, array_merge([
        'environment' => preparedEnvironmentContents(),
        'schema' => 3,
    ], $options));
}

/**
 * Every override a recover-host invocation needs on top of infraScriptEnv:
 * the four backup primitives it drives (patched, so they run without root),
 * the prepare-host verification it delegates to, the fake offsite remote, and
 * the per-database row counts that make "empty" and "restored" distinguishable.
 *
 * @return array<string, string>
 */
function recoveryEnv(string $scratch): array
{
    @mkdir($scratch.'/pg/tables', 0o755, true);
    @mkdir($scratch.'/pg/migrations', 0o755, true);

    [$registryPath, $targetsPath] = parityRegistryFixture($scratch);

    return recoveryMaterialPrerequisitesEnv($scratch, $registryPath, $targetsPath) + [
        'RATEGURU_RECOVERY_HISTORY_ROOT' => $scratch.'/recoveries',
        'RATEGURU_RECOVER_PREPARE_HOST_BIN' => $scratch.'/bin/prepare-host-stub',
        'RATEGURU_RECOVER_SUPERVISOR_CONF_D' => $scratch.'/supervisor-conf.d',
        'RGTEST_PREPARE_HOST_LOG' => $scratch.'/prepare-host.log',

        'RATEGURU_RESTORE_FETCH_BACKUP_BIN' => patchedInfraScript($scratch, 'fetch-backup'),
        'RATEGURU_RESTORE_VERIFY_BACKUP_BIN' => patchedInfraScript($scratch, 'verify-backup'),
        'RATEGURU_RESTORE_DATABASE_BIN' => patchedInfraScript($scratch, 'restore-database'),
        'RATEGURU_RESTORE_STORAGE_BIN' => patchedInfraScript($scratch, 'restore-storage'),

        'RATEGURU_RCLONE_BIN' => $scratch.'/bin/rclone',
        'RATEGURU_RCLONE_CONFIG' => $scratch.'/rclone.conf',
        'RGTEST_RCLONE_LOG' => $scratch.'/rclone.log',
        'RGTEST_REMOTE_ROOT' => $scratch.'/remote',

        'RGTEST_PG_TABLE_COUNTS' => $scratch.'/pg/tables',
        'RGTEST_PG_MIGRATION_COUNTS' => $scratch.'/pg/migrations',
    ];
}

/** The recovery guard document, or null when the target carries none. */
function recoveryGuard(string $scratch): ?array
{
    $path = $scratch.'/run/recoveries/parity-target/recovery-guard';

    return File::exists($path) ? json_decode(File::get($path), true) : null;
}

/** @return array<string, mixed> */
function recoveryOperationState(string $scratch, string $operationId): array
{
    return json_decode(File::get($scratch.'/run/recoveries/parity-target/'.$operationId.'/state.json'), true);
}

/** @return array<string, string> */
function targetRuntimeEnv(string $scratch): array
{
    return [
        'RGTEST_SUPERVISOR_LOG' => $scratch.'/supervisor.log',
        'RGTEST_SUPERVISOR_STATE' => $scratch.'/supervisor-state',
        'RGTEST_SUPERVISOR_SECOND_STATE' => $scratch.'/supervisor-second-state',
        // Its EXISTENCE means "this group is not loaded in the running
        // Supervisor"; no file, no change to any other test.
        'RGTEST_SUPERVISOR_GROUP_ABSENT' => $scratch.'/supervisor-group-absent',
        'RGTEST_PHP_LOG' => $scratch.'/php.log',
        'RGTEST_MAINTENANCE_FLAG' => $scratch.'/target/shared/storage/framework/down',
        'RGTEST_BACKUP_LOG' => $scratch.'/backup.log',
        'RGTEST_BACKUP_TEMPLATE' => $scratch.'/emergency-template',
        'RGTEST_BACKUP_NAMESPACE_ROOT' => $scratch.'/backups/parity',
        'RGTEST_RESTORE_TEST_LOG' => $scratch.'/restore-test.log',
        'RGTEST_HEALTH_CHECK_LOG' => $scratch.'/health-check.log',
        'RGTEST_PGREP_LOG' => $scratch.'/pgrep.log',
        'RATEGURU_RESTORE_PGREP_BIN' => $scratch.'/bin/pgrep',
        'RATEGURU_RESTORE_SUPERVISORCTL_BIN' => $scratch.'/bin/supervisorctl',
        'RATEGURU_RESTORE_BACKUP_BIN' => $scratch.'/bin/backup-stub',
        'RATEGURU_RESTORE_RESTORE_TEST_BIN' => $scratch.'/bin/restore-test-stub',
        'RATEGURU_RESTORE_HEALTH_CHECK_BIN' => $scratch.'/bin/health-check-stub',
        'RATEGURU_RESTORE_QUEUE_WAIT_ATTEMPTS' => '3',
        'RATEGURU_RESTORE_QUEUE_RETRY_DELAY' => '0',
        'RATEGURU_RESTORE_SCHEDULER_WAIT_ATTEMPTS' => '3',
        'RATEGURU_RESTORE_SCHEDULER_RETRY_DELAY' => '0',
    ];
}

// =============================================================================
// GitHub Actions job gating
// =============================================================================

/**
 * Tokenize one GitHub Actions expression.
 *
 * @return list<array{kind: string, value: string}>
 */
function githubExpressionTokens(string $expression): array
{
    $source = trim($expression);

    if (preg_match('/^\$\{\{(.*)\}\}$/s', $source, $matches) === 1) {
        $source = trim($matches[1]);
    }

    $tokens = [];
    $length = strlen($source);
    $offset = 0;

    while ($offset < $length) {
        $character = $source[$offset];

        if (ctype_space($character)) {
            $offset++;

            continue;
        }

        // Single-quoted string, with '' as the escape for a literal quote.
        if ($character === "'") {
            $offset++;
            $literal = '';

            while ($offset < $length) {
                if ($source[$offset] === "'") {
                    if (($source[$offset + 1] ?? '') === "'") {
                        $literal .= "'";
                        $offset += 2;

                        continue;
                    }

                    $offset++;
                    break;
                }

                $literal .= $source[$offset];
                $offset++;
            }

            $tokens[] = ['kind' => 'string', 'value' => $literal];

            continue;
        }

        foreach (['&&', '||', '==', '!='] as $operator) {
            if (substr($source, $offset, 2) === $operator) {
                $tokens[] = ['kind' => 'operator', 'value' => $operator];
                $offset += 2;

                continue 2;
            }
        }

        if ($character === '!' || $character === '(' || $character === ')') {
            $tokens[] = ['kind' => 'operator', 'value' => $character];
            $offset++;

            continue;
        }

        // A path or a function name. Job identifiers carry hyphens, so a
        // hyphen is part of a name here and never a minus: these expressions
        // do no arithmetic.
        if (preg_match('/[A-Za-z_][A-Za-z0-9_.\-]*/A', $source, $matches, 0, $offset) === 1) {
            $tokens[] = ['kind' => 'name', 'value' => $matches[0]];
            $offset += strlen($matches[0]);

            continue;
        }

        throw new RuntimeException("unsupported character '{$character}' at offset {$offset} of: {$expression}");
    }

    return $tokens;
}

/**
 * Evaluate one GitHub Actions expression against a context, and return the
 * value it produces — a string, or a bool for the status functions.
 *
 * The subset is the one job gates are written in: `&&`, `||`, `!`, `==`, `!=`,
 * parentheses, single-quoted strings, the four status functions, and property
 * paths under `needs`. `&&` and `||` return an OPERAND rather than a boolean,
 * exactly as GitHub does, because that is what makes an empty-string operand
 * behave the way it does in a real run.
 *
 * @param  array{needs?: array<string, array{result?: string, outputs?: array<string, string>}>, always?: bool, cancelled?: bool, success?: bool, failure?: bool}  $context
 */
function githubExpressionValue(string $expression, array $context): bool|string
{
    $tokens = githubExpressionTokens($expression);
    $position = 0;

    $peek = static function () use (&$tokens, &$position): ?array {
        return $tokens[$position] ?? null;
    };

    $truthy = static function (bool|string $value): bool {
        // GitHub coerces a string to a boolean by emptiness, and the empty
        // string is exactly what an undeclared `needs.<job>` produces.
        return is_bool($value) ? $value : $value !== '';
    };

    $parseOr = null;

    $parsePrimary = function () use (&$peek, &$position, &$parseOr, $context, $expression): bool|string {
        $token = $peek();

        if ($token === null) {
            throw new RuntimeException("expression ends early: {$expression}");
        }

        if ($token['kind'] === 'operator' && $token['value'] === '(') {
            $position++;
            $value = $parseOr();

            $closing = $peek();

            if ($closing === null || $closing['value'] !== ')') {
                throw new RuntimeException("unbalanced parentheses in: {$expression}");
            }

            $position++;

            return $value;
        }

        if ($token['kind'] === 'string') {
            $position++;

            return $token['value'];
        }

        if ($token['kind'] !== 'name') {
            throw new RuntimeException("unexpected '{$token['value']}' in: {$expression}");
        }

        $position++;
        $name = $token['value'];

        $next = $peek();

        if ($next !== null && $next['kind'] === 'operator' && $next['value'] === '(') {
            $position++;

            $closing = $peek();

            if ($closing === null || $closing['value'] !== ')') {
                throw new RuntimeException("only zero-argument functions are supported: {$expression}");
            }

            $position++;

            if (! array_key_exists($name, $context)) {
                throw new RuntimeException("the scenario does not say what {$name}() is: {$expression}");
            }

            return (bool) $context[$name];
        }

        // `needs.<job>.result` and `needs.<job>.outputs.<name>`. Anything a
        // scenario has no value for is the empty string, which is what GitHub
        // produces for an unset output and for a job that is not needed.
        $path = explode('.', $name);

        $value = $context;

        foreach ($path as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return '';
            }

            $value = $value[$segment];
        }

        return is_array($value) ? '' : (string) $value;
    };

    $parseUnary = function () use (&$peek, &$position, &$parseUnary, $parsePrimary, $truthy): bool|string {
        $token = $peek();

        if ($token !== null && $token['kind'] === 'operator' && $token['value'] === '!') {
            $position++;

            return ! $truthy($parseUnary());
        }

        return $parsePrimary();
    };

    $parseComparison = function () use (&$peek, &$position, $parseUnary): bool|string {
        $left = $parseUnary();

        $token = $peek();

        if ($token !== null && $token['kind'] === 'operator' && in_array($token['value'], ['==', '!='], true)) {
            $position++;
            $right = $parseUnary();

            $equal = is_bool($left) || is_bool($right)
                ? $left === $right
                : (string) $left === (string) $right;

            return $token['value'] === '==' ? $equal : ! $equal;
        }

        return $left;
    };

    $parseAnd = function () use (&$peek, &$position, $parseComparison, $truthy): bool|string {
        $value = $parseComparison();

        while (($token = $peek()) !== null && $token['kind'] === 'operator' && $token['value'] === '&&') {
            $position++;
            $right = $parseComparison();

            // GitHub returns the first falsy operand, or the last one.
            $value = $truthy($value) ? $right : $value;
        }

        return $value;
    };

    $parseOr = function () use (&$peek, &$position, $parseAnd, $truthy): bool|string {
        $value = $parseAnd();

        while (($token = $peek()) !== null && $token['kind'] === 'operator' && $token['value'] === '||') {
            $position++;
            $right = $parseAnd();

            $value = $truthy($value) ? $value : $right;
        }

        return $value;
    };

    $result = $parseOr();

    if ($position !== count($tokens)) {
        throw new RuntimeException("trailing input in: {$expression}");
    }

    return $result;
}

/**
 * Every job a workflow job transitively depends on.
 *
 * @param  array<string, array>  $jobs
 * @return list<string>
 */
function githubJobAncestors(array $jobs, string $job): array
{
    $ancestors = [];
    $queue = (array) data_get($jobs, $job.'.needs', []);

    while ($queue !== []) {
        $name = array_shift($queue);

        if (in_array($name, $ancestors, true)) {
            continue;
        }

        $ancestors[] = $name;

        foreach ((array) data_get($jobs, $name.'.needs', []) as $parent) {
            $queue[] = $parent;
        }
    }

    return $ancestors;
}

/**
 * Run a workflow's job graph on paper and report what each job's `result`
 * would be.
 *
 * The rule this models is the one that is easy to get wrong and expensive to
 * discover in production: a job with no `if:` carries GitHub's implicit
 * `success()`, and at job level that is not "the jobs I need succeeded" — it
 * is "no job anywhere in my ancestry failed or was skipped". A legitimately
 * skipped stage therefore withholds every unconditioned job downstream of it,
 * however many successful jobs stand in between. A job that names its own
 * condition is judged by that condition alone.
 *
 * @param  array  $workflow  the parsed workflow
 * @param  array<string, string>  $outcomes  what a job reports IF it runs; success by default
 * @param  array<string, array<string, string>>  $outputs  outputs by job, for the gates that read them
 * @return array<string, string> each job's result: success, failure, cancelled or skipped
 */
function githubWorkflowJobResults(array $workflow, array $outcomes = [], array $outputs = [], bool $runCancelled = false): array
{
    $jobs = (array) data_get($workflow, 'jobs', []);

    $results = [];
    $pending = array_keys($jobs);

    while ($pending !== []) {
        $progressed = false;

        foreach ($pending as $index => $name) {
            $needs = (array) data_get($jobs, $name.'.needs', []);

            foreach ($needs as $dependency) {
                if (! array_key_exists($dependency, $results)) {
                    continue 2;
                }
            }

            unset($pending[$index]);
            $progressed = true;

            $ancestors = githubJobAncestors($jobs, $name);

            $ancestorsSucceeded = true;
            $ancestorFailed = false;

            foreach ($ancestors as $ancestor) {
                if (($results[$ancestor] ?? '') !== 'success') {
                    $ancestorsSucceeded = false;
                }

                if (($results[$ancestor] ?? '') === 'failure') {
                    $ancestorFailed = true;
                }
            }

            $condition = data_get($jobs, $name.'.if');

            if ($condition === null) {
                $results[$name] = $ancestorsSucceeded ? ($outcomes[$name] ?? 'success') : 'skipped';

                continue;
            }

            $needsContext = [];

            foreach ($needs as $dependency) {
                $result = $results[$dependency] ?? '';

                $needsContext[$dependency] = [
                    'result' => $result,
                    // A job that did not run published nothing. Handing a
                    // skipped job's declared outputs to a downstream gate
                    // would let a scenario pass on a value GitHub would have
                    // delivered as the empty string.
                    'outputs' => $result === 'success' ? ($outputs[$dependency] ?? []) : [],
                ];
            }

            $runs = githubExpressionValue((string) $condition, [
                'needs' => $needsContext,
                'always' => true,
                'cancelled' => $runCancelled,
                'success' => $ancestorsSucceeded,
                'failure' => $ancestorFailed,
            ]);

            $results[$name] = (is_bool($runs) ? $runs : $runs !== '')
                ? ($outcomes[$name] ?? 'success')
                : 'skipped';
        }

        if (! $progressed) {
            throw new RuntimeException('the workflow job graph has a cycle: '.implode(', ', $pending));
        }
    }

    return $results;
}

/**
 * One recovery workflow, parsed and raw.
 *
 * Shared because two files ask about the same two documents from opposite
 * directions: one asserts the policy those workflows implement, the other
 * states the disaster-recovery contract they are one surface of.
 *
 * @return array{0: array, 1: string}
 */
function recoverWorkflow(string $file): array
{
    $path = base_path(".github/workflows/{$file}");

    expect(File::exists($path))->toBeTrue("{$file} is missing");

    $source = File::get($path);

    return [Yaml::parse($source), $source];
}

/**
 * A Socialite user shaped like what the Google or Facebook provider returns,
 * for Socialite::fake(). Every attribute a test does not name gets a stable
 * default, and extra keys (Google's `email_verified` and `hd`) land in the
 * raw provider payload exactly where Socialite puts them.
 *
 * @param  array<string, mixed>  $attributes
 */
function fakeSocialiteUser(array $attributes = []): SocialiteUser
{
    return SocialiteUser::fake(array_merge([
        'id' => 'provider-user-1',
        'nickname' => null,
        'name' => 'Ivan Moroz',
        'email' => 'ivan@example.com',
        'avatar' => null,
    ], $attributes));
}

/** Where every connect and disconnect lands: the profile's Connected accounts card. */
function connectedAccountsUrl(): string
{
    return route('profile.edit').'#connected-accounts';
}

/**
 * Signs in as $user and presses Connect for $provider on the Connected
 * accounts card — the only start that lets a later callback attach an
 * identity to a signed-in account. Returns the test case for the callback.
 */
function startConnectingProvider(User $user, string $provider): TestCase
{
    $test = test()->actingAs($user);
    $test->post(route('profile.connected-accounts.store', ['provider' => $provider]))->assertRedirect();

    return $test;
}

/**
 * The callback URL a provider redirects back to, carrying the query a
 * completed consent produces. Override or add parameters through $query —
 * an OAuth `error`, or `code => null` for a callback without a code.
 *
 * @param  array<string, string|null>  $query
 */
function socialCallbackUrl(string $provider, array $query = []): string
{
    $query = array_filter(
        array_merge(['code' => 'fake-authorization-code', 'state' => 'fake-state'], $query),
        static fn (?string $value): bool => $value !== null,
    );

    return '/auth/'.$provider.'/callback?'.http_build_query($query);
}

/**
 * The marker fields the authentication modal adds to a login or registration
 * post — and to a provider link — so a test can act "from the modal, opened
 * on this page".
 *
 * @return array{_auth_surface: string, _auth_mode: string, _auth_return_to: string}
 */
function authModalFields(string $mode, string $returnTo = '/'): array
{
    return [
        '_auth_surface' => 'modal',
        '_auth_mode' => $mode,
        '_auth_return_to' => $returnTo,
    ];
}

/**
 * The authentication modal as the page rendered it, for assertions that must
 * not be satisfied by markup elsewhere on the page.
 */
function authModalElement(string $html): DOMElement
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

    $node = (new DOMXPath($document))->query('//*[@data-testid="auth-modal-root"]')->item(0);

    expect($node)->toBeInstanceOf(DOMElement::class, 'the page rendered no authentication modal');

    return $node;
}

/**
 * Asserts that a Livewire component refuses to mount because the model it
 * was asked for does not exist for this visitor.
 *
 * What a refusal looks like depends on Livewire's test harness, not on the
 * component: it reports a missing model as a 404 response, where releases
 * before 4.4.7 let the ModelNotFoundException itself through. Both are the
 * same refusal, and a visitor sees a 404 page either way.
 *
 * @param  class-string  $component
 * @param  array<string, mixed>  $parameters
 */
function expectLivewireModelNotFound(string $component, array $parameters): void
{
    try {
        Livewire::test($component, $parameters)->assertNotFound();
    } catch (ModelNotFoundException $exception) {
        expect($exception)->toBeInstanceOf(ModelNotFoundException::class);
    }
}

/**
 * Waits for the page to reach a state instead of guessing how long that takes.
 *
 * A fixed pause is a bet on the speed of the machine: fine on a laptop, lost
 * on a CI runner that is busy with the rest of the suite. This polls the
 * expression until it evaluates to the expected value, and fails with the
 * last value it saw when the time runs out.
 */
function waitForScript(mixed $page, string $expression, mixed $expected = true, float $timeoutSeconds = 5.0): void
{
    $deadline = microtime(true) + $timeoutSeconds;

    do {
        $actual = $page->script($expression);

        if ($actual === $expected) {
            break;
        }

        // Short, because every wait ends up to one interval after the state is
        // reached, and a suite waits a few hundred times.
        browserTestPause(0.025);
    } while (microtime(true) < $deadline);

    expect($actual)->toBe($expected, "[{$expression}] did not become ".var_export($expected, true)." within {$timeoutSeconds}s");
}

/**
 * Runs $assertions until they pass, and lets their last failure through once
 * $timeoutSeconds have gone by — waitForScript() for a state that is easier to
 * say in PHP than in one JavaScript expression.
 *
 * Only for a state the step before produces. An assertion that already held
 * before that step passes at once, before the step has taken effect: what it
 * waits for must be something the page did not show until then.
 */
function eventually(callable $assertions, float $timeoutSeconds = 5.0): mixed
{
    $deadline = microtime(true) + $timeoutSeconds;

    while (true) {
        try {
            return $assertions();
        } catch (AssertionFailedError $failure) {
            if (microtime(true) >= $deadline) {
                throw $failure;
            }

            browserTestPause(0.025);
        }
    }
}

/**
 * Sends every response that cannot have a body (204, 304) with
 * "Connection: close", for the rest of the test.
 *
 * The browser plugin serves the application through amphp/http-server. Its
 * HTTP/1.1 driver frames any response without a Content-Length as chunked,
 * one that cannot have a body included, and writes the terminating chunk after
 * the headers. Chromium takes such a response as complete at its headers and
 * puts the connection back in its pool. When it sends the next request on that
 * connection before those five bytes arrive, the next response reads as
 * starting with them: the navigation ends on Chromium's error page with
 * net::ERR_INVALID_HTTP_RESPONSE, although the server answered it. A response
 * that closes its connection is never chunked, and its connection is never
 * reused.
 *
 * The defect is https://github.com/amphp/http-server/issues/393. Once the
 * installed amphp/http-server no longer chunk-encodes these responses, this
 * can go.
 */
function keepBodilessResponsesOffKeepAliveConnections(Application $app): void
{
    $app['events']->listen(RequestHandled::class, function (RequestHandled $event): void {
        if (in_array($event->response->getStatusCode(), [204, 304], true)) {
            $event->response->headers->set('Connection', 'close');
        }
    });
}

/**
 * Collects every uncaught JavaScript error, unhandled promise rejection and
 * console.error() call of any page served to the browser, for the rest of the
 * test. Alpine and Livewire report what they catch through console.error(),
 * and the suite is clean of it, so it counts as a failure as well.
 *
 * The browser plugin's own assertNoJavaScriptErrors() reads one page's record:
 * a navigation starts a new one, a test has to remember to ask, and an
 * unhandled rejection never makes it into the record at all. This works from
 * the server's side instead. Every HTML page the application under test sends
 * — it runs in this process — gets a small reporter at the top of its <head>,
 * which posts each error to a route that exists only for this test.
 *
 * @return ArrayObject<string, array<string, string>>
 */
function watchBrowserJavaScriptErrors(Application $app): ArrayObject
{
    $errors = new ArrayObject;

    // Keyed by the report's own ID: a report resent as a beacon after its fetch
    // was cut short by a navigation may arrive twice, and counts once.
    $app['router']->post('__browser-test/javascript-errors', function (Request $request) use ($errors) {
        $errors[(string) $request->input('id')] = array_map('strval', $request->only(['kind', 'message', 'source', 'page']));

        return response()->noContent();
    });

    $reporter = <<<'HTML'
        <script>
        (() => {
            const endpoint = '/__browser-test/javascript-errors';

            // A report that does not get through is not dropped. A refused or
            // failed fetch is sent again as a beacon, which the browser
            // delivers even while the page unloads; what neither can send is
            // kept on the page, where a debugging session can find it.
            const report = (kind, message, source) => {
                const body = JSON.stringify({ id: crypto.randomUUID(), kind, message: String(message), source: String(source ?? ''), page: location.pathname + location.search });
                const resend = () => {
                    if (! navigator.sendBeacon(endpoint, new Blob([body], { type: 'application/json' }))) {
                        (window.__rgUndeliveredJavaScriptErrors ??= []).push(body);
                    }
                };

                fetch(endpoint, { method: 'POST', keepalive: true, headers: { 'Content-Type': 'application/json' }, body })
                    .then((response) => response.ok || resend(), resend);
            };

            window.addEventListener('error', (event) => report('error', event.message, `${event.filename}:${event.lineno}:${event.colno}`));
            window.addEventListener('unhandledrejection', (event) => report('unhandledrejection', event.reason?.stack ?? event.reason?.message ?? event.reason, ''));

            const consoleError = console.error;
            console.error = (...args) => {
                report('console.error', args.map(String).join(' '), '');
                consoleError.apply(console, args);
            };
        })();
        </script>
        HTML;

    $app['events']->listen(RequestHandled::class, function (RequestHandled $event) use ($reporter): void {
        $response = $event->response;

        if (! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            return;
        }

        // The whole opening tag, attributes included (<head prefix="og: …">),
        // and never <header>.
        $content = (string) $response->getContent();

        if (preg_match('/<head(?=[\s>])[^>]*>/i', $content, $head, PREG_OFFSET_CAPTURE) === 1) {
            $response->setContent(substr_replace($content, $reporter, $head[0][1] + strlen($head[0][0]), 0));
        }
    });

    return $errors;
}

/**
 * Fails the test with every JavaScript error watchBrowserJavaScriptErrors()
 * collected.
 *
 * A report is sent the instant its error happens, but the server only answers
 * while the test lets the event loop run, and the test cannot ask a page what
 * it still has in flight. So this waits until the reports stop coming: at
 * least $quietSeconds with none arriving, and never longer than $maxSeconds.
 *
 * @param  ArrayObject<string, array<string, string>>  $errors
 */
function assertNoBrowserJavaScriptErrors(ArrayObject $errors, float $quietSeconds = 0.075, float $maxSeconds = 1.0): void
{
    $start = microtime(true);
    $seen = count($errors);
    $lastArrival = $start;

    do {
        browserTestPause(0.025);

        if (count($errors) !== $seen) {
            $seen = count($errors);
            $lastArrival = microtime(true);
        }
    } while (microtime(true) - $lastArrival < $quietSeconds && microtime(true) - $start < $maxSeconds);

    $reported = array_map(
        fn (array $error): string => "{$error['kind']} on {$error['page']}: {$error['message']} ({$error['source']})",
        array_values($errors->getArrayCopy()),
    );

    expect($reported)->toBe([], 'the pages this test opened raised JavaScript errors');
}

/**
 * Holds the page for $seconds to prove that $what does NOT happen in that time,
 * and returns the page.
 *
 * The one fixed pause the Browser suite allows (BrowserTestWaitingTest keeps it
 * that way). A state the page reaches can be waited for — waitForScript(),
 * eventually() — but absence has no event: a request not sent, focus not moved,
 * a toast not taken away while it is read. The pause is the window in which it
 * would have shown, so its length belongs in a comment beside the call, and
 * $what names what the test would have seen.
 */
function proveNothingHappensFor(mixed $page, float $seconds, string $what): mixed
{
    if (trim($what) === '') {
        throw new InvalidArgumentException('Say what must not happen during the pause.');
    }

    browserTestPause($seconds);

    return $page;
}

/**
 * Sends the page to $url, as following a link would, and returns once the call
 * that did it has returned.
 *
 * The plugin retries every page call that fails, for up to five seconds. A
 * call whose page navigates away before it returns fails with "Execution
 * context was destroyed", so a navigation started inside that call is started
 * again on every retry: a probe counted 454 requests for the page in those
 * five seconds, and the page never finished loading. navigate() is a call
 * retried the same way. This schedules the navigation for after the call has
 * returned, so it happens exactly once. Wait for the page it lands on.
 */
function navigatePageTo(mixed $page, string $url): mixed
{
    $page->script('() => { setTimeout(() => { location.href = '.json_encode($url).'; }, 0); return true; }');

    return $page;
}

/**
 * Lets $seconds go by without stopping the application under test.
 *
 * The browser plugin serves the application from this same PHP process, on its
 * event loop. usleep() would stop that loop with everything else, so a request
 * the page sent meanwhile — a Livewire update, a save — would wait for the next
 * call into the browser to be answered; a wait that only reads the database
 * would never see it answered at all. Amp's delay() lets the loop, and with it
 * the server, run while this waits.
 */
function browserTestPause(float $seconds): void
{
    \Amp\delay($seconds);
}

/**
 * Resizes the viewport and returns the page once it is laid out at the new
 * size, instead of pausing for long enough to be fairly sure.
 *
 * The window reports the size once the browser has applied it. Whatever
 * listens for it runs in the next rendering frame — resize events and media
 * query listeners come before that frame's animation callbacks — so once two
 * frames have gone by, those listeners have had their turn as well.
 */
function resizeAndSettle(mixed $page, int $width, int $height): mixed
{
    $page = $page->resize($width, $height);

    waitForScript($page, "window.innerWidth === {$width} && window.innerHeight === {$height}");
    $page->script('new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => resolve(true))))');

    return $page;
}

/**
 * The languages the product offers, read from config/locales.php — the one
 * place a language is declared. Every localization test iterates this rather
 * than spelling out a list, so declaring a language puts it through all of
 * them without editing a single test.
 *
 * It reads the file rather than going through config() because Pest collects
 * datasets before the application boots, and `->with(supportedLocales())` is
 * the usual way a test asks for "every language".
 *
 * @return list<string>
 */
function supportedLocales(): array
{
    return array_keys((require dirname(__DIR__).'/config/locales.php')['supported']);
}

/**
 * Every supported language except English, the reference the others are
 * translated from.
 *
 * @return list<string>
 */
function translatedLocales(): array
{
    return array_values(array_diff(supportedLocales(), ['en']));
}

/**
 * The built-in static pages as a project that has translated them stores
 * them: the English the repository ships, and a made-up translation of every
 * field in each of these languages — "[ru] About RateGuru". The repository
 * ships its pages in English alone; their translations are each project's.
 *
 * @param  list<string>  $locales
 * @return array<string, array<string, array<string, string>>>
 */
function staticPagesTranslatedInto(array $locales): array
{
    $pages = [];

    foreach (config('static-pages.defaults') as $page => $byLocale) {
        $pages[$page] = ['en' => $byLocale['en']];

        foreach ($locales as $locale) {
            $pages[$page][$locale] = array_map(fn (string $text): string => "[{$locale}] {$text}", $byLocale['en']);
        }
    }

    return $pages;
}

/**
 * Makes the repository ship a made-up translation — "[ru] General" — of every
 * preset value and static page field in these languages. The repository ships
 * its project content in English alone; this is for what reads repository
 * translations whatever it finds, such as the backfill.
 *
 * @param  list<string>  $locales
 */
function shipRepositoryContentTranslatedInto(array $locales): void
{
    $translate = function (mixed $value) use (&$translate, $locales): mixed {
        if (! is_array($value)) {
            return $value;
        }

        // A value per language: the English the repository ships, then the made-up translations.
        if (array_keys($value) === ['en'] && (is_string($value['en']) || $value['en'] === null)) {
            foreach ($locales as $locale) {
                $value[$locale] = $value['en'] === null ? null : "[{$locale}] {$value['en']}";
            }

            return $value;
        }

        return array_map($translate, $value);
    };

    config([
        'project_presets' => $translate(config('project_presets')),
        'static-pages.defaults' => staticPagesTranslatedInto($locales),
    ]);
}

/**
 * A well-formed language code the product will never offer, for tests about
 * what happens to an unsupported locale. Deliberately not a real language: a
 * real one ("de") is exactly what may be added to config/locales.php next, and
 * the test proving it is refused would then fail for the wrong reason.
 */
function unsupportedLocale(): string
{
    return 'xx';
}

/**
 * Two installed languages other than English, for tests that need distinct
 * roles — one offered, one withheld; a project default that is not the
 * technical fallback. Taken from config/locales.php like everything else, so
 * the tests keep meaning the same thing whichever languages are installed.
 *
 * @return array{0: string, 1: string}
 */
function twoTranslatedLocales(): array
{
    $locales = translatedLocales();

    if (count($locales) < 2) {
        throw new RuntimeException('These tests need at least two installed languages besides English.');
    }

    return [$locales[0], $locales[1]];
}

/**
 * A published post whose image has these generated variants, with the asset
 * and its variants loaded — the shape the presenter is handed in a list.
 *
 * @param  array<string, array{0: int, 1: int}>  $variantDimensionsByName  keyed by MediaVariantName::value
 */
function postWithVariants(array $variantDimensionsByName): Post
{
    $asset = MediaAsset::factory()->postImage()->dimensions(2400, 1600)->create();

    foreach ($variantDimensionsByName as $name => $dimensions) {
        MediaVariant::factory()->named(MediaVariantName::from($name))->create([
            'media_asset_id' => $asset->id,
            'width' => $dimensions[0],
            'height' => $dimensions[1],
        ]);
    }

    return Post::factory()->published()->create(['image_asset_id' => $asset->id])
        ->load('imageAsset.variants');
}

/**
 * Makes the project offer these installed languages through the same action
 * the Languages page uses — so a test cannot set up a state the application
 * itself would refuse. English, the default, is always among them.
 *
 * @param  list<string>  $enabled
 */
function offerLocales(array $enabled): void
{
    app(UpdateProjectLocaleSettingsAction::class)->handle(array_values(array_unique([config('locales.default'), ...$enabled])));
}

/**
 * Makes the project offer every installed language, for a test that puts each
 * one through what a visitor or a reader gets. A project that never chose
 * offers only the languages enabled by default, and a language a release adds
 * is not among them — but what such a test checks is that a language works
 * once it is offered, which has to hold from the day the language is
 * installed, before anyone enables it.
 */
function offerEveryInstalledLocale(): void
{
    offerLocales(supportedLocales());
}

/**
 * Makes the project offer every installed language except these — the usual
 * way a test withholds a language. English, the default, is offered whatever
 * is passed.
 */
function offerEveryInstalledLocaleExcept(string ...$withheld): void
{
    offerLocales(array_values(array_diff(supportedLocales(), $withheld)));
}

/** Request headers for a browser asking for these languages. */
function acceptLanguage(string $header): array
{
    return ['Accept-Language' => $header];
}

/**
 * Request headers for a browser that states no language. Test requests
 * otherwise carry Symfony's default `Accept-Language: en-us,en;q=0.5`, which
 * answers before the project default ever could.
 */
function noBrowserLanguage(): array
{
    return acceptLanguage('');
}

/**
 * Every translatable project setting translated into these languages, as the
 * `{field}_translations` attributes of a settings row.
 *
 * @param  list<string>  $locales
 * @return array<string, array<string, string>>
 */
function projectSettingsTranslationsIn(array $locales): array
{
    $attributes = [];

    foreach (PresetSettingsBuilder::TRANSLATABLE as $field) {
        $attributes["{$field}_translations"] = collect($locales)->mapWithKeys(fn (string $locale): array => [$locale => "{$field} in {$locale}"])->all();
    }

    return $attributes;
}

/**
 * A fresh, empty directory for one test's own language catalogs. The file
 * that uses it removes it again with removeCatalogScratchDirectory($this) in
 * its afterEach.
 */
function catalogScratchDirectory(): string
{
    $root = sys_get_temp_dir().'/rateguru-catalogs-'.uniqid('', true);
    File::ensureDirectoryExists($root);
    test()->catalogScratchDirectory = $root;

    return $root;
}

/**
 * Takes the test case itself: test() hands back a proxy that forwards reads
 * and writes but answers isset() with false, so a check through it would
 * never find the directory.
 */
function removeCatalogScratchDirectory(TestCase $test): void
{
    if (isset($test->catalogScratchDirectory)) {
        File::deleteDirectory($test->catalogScratchDirectory);
    }
}

/**
 * Points the inspector at a copy of the catalogs with one of this language's
 * files removed — a broken release on a server.
 */
function breakCatalogsOf(string $locale): void
{
    $root = catalogScratchDirectory();
    File::copyDirectory(lang_path(), $root);
    File::delete("{$root}/{$locale}/ui.php");

    app()->instance(TranslationCatalogInspector::class, new TranslationCatalogInspector($root));
}

/** The languages the project offers, read afresh. */
function offeredLocales(): array
{
    app(ProjectSettingsManager::class)->flush();

    return app(LocaleManager::class)->enabledCodes();
}

/**
 * The project's settings row with every translatable field — its static pages
 * included — translated into these languages.
 */
function settingsTranslatedInto(array $locales): void
{
    $attributes = projectSettingsTranslationsIn($locales);

    foreach (PresetSettingsBuilder::TRANSLATABLE as $field) {
        $attributes[$field] = "{$field} text";
    }

    $attributes['static_pages'] = staticPagesTranslatedInto(array_values(array_diff($locales, ['en'])));

    ProjectSettings::query()->update(collect($attributes)->map(fn (mixed $value): mixed => is_array($value) ? json_encode($value) : $value)->all());
    app(ProjectSettingsManager::class)->flush();
}

/**
 * Installs made-up languages after the real ones until there are this many,
 * each with a copy of a real translation's catalogs, and one of them broken —
 * the scale the screen has to hold, from a fixture rather than from real
 * catalogs. Returns every installed code in config order.
 *
 * @return list<string>
 */
function installLanguagesUpTo(int $total): array
{
    $root = catalogScratchDirectory();
    File::copyDirectory(lang_path(), $root);
    [$source] = twoTranslatedLocales();
    $supported = config('locales.supported');

    for ($i = 0; count($supported) < $total; $i++) {
        $code = 'x'.chr(97 + intdiv($i, 26)).chr(97 + $i % 26);
        File::copyDirectory("{$root}/{$source}", "{$root}/{$code}");
        $supported[$code] = ['label' => 'Language '.strtoupper($code), 'native' => 'Native '.strtoupper($code), 'flag' => '🏳️', 'enabled_by_default' => false];
    }

    File::delete("{$root}/".array_key_last($supported).'/ui.php');
    config(['locales.supported' => $supported]);
    app()->instance(TranslationCatalogInspector::class, new TranslationCatalogInspector($root));

    return array_keys($supported);
}

/** A category in the project's content that no language translates. */
function untranslatedCategory(string $name = 'Georgian food'): Category
{
    return Category::factory()->create(['slug' => Str::slug($name), 'name' => $name, 'name_translations' => null, 'is_active' => true]);
}

/**
 * Counts, from here on, every Livewire update the page sends — through the
 * fetch Livewire calls, and through the browser's own record of requests, so
 * the proof does not rest on how Livewire happens to send them.
 */
function watchLivewireUpdates(mixed $page): void
{
    $page->script(<<<'JS'
        (() => {
            const uri = document.querySelector('[data-update-uri]').getAttribute('data-update-uri')
            const fetch = window.fetch

            window.livewireUri = uri
            window.livewireFetches = 0
            window.livewireEntriesBefore = performance.getEntriesByType('resource').filter((entry) => entry.name.startsWith(uri)).length
            window.notReloaded = true
            window.fetch = function (input, ...rest) {
                if (String(input?.url ?? input).startsWith(uri)) {
                    window.livewireFetches++
                }

                return fetch.call(this, input, ...rest)
            }
        })()
    JS);
}

/** What the page has sent to Livewire since watchLivewireUpdates(), and whether it is still the same page. */
function livewireUpdatesSinceWatching(mixed $page): array
{
    return $page->script(<<<'JS'
        (() => ({
            fetches: window.livewireFetches ?? null,
            requests: performance.getEntriesByType('resource').filter((entry) => entry.name.startsWith(window.livewireUri)).length - window.livewireEntriesBefore,
            sameDocument: window.notReloaded === true,
        }))()
    JS);
}

/**
 * A rendered Admin v2 screen, queryable: a Livewire component under test, or
 * the HTML of a plain response.
 */
function livewireDom(Testable|string $page): DOMXPath
{
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8"?>'.($page instanceof Testable ? $page->html() : $page));

    return new DOMXPath($dom);
}

/**
 * The outer HTML of the first element an XPath query finds, or null — without
 * Livewire's morph markers and the whitespace between tags, so an assertion
 * can name a button by its exact text.
 */
function livewireFragment(Testable|string $page, string $query): ?string
{
    $node = livewireDom($page)->query($query)->item(0);

    if ($node === null) {
        return null;
    }

    $html = str_replace(['<!--[if BLOCK]><![endif]-->', '<!--[if ENDBLOCK]><![endif]-->'], '', (string) $node->ownerDocument->saveHTML($node));

    return (string) preg_replace(['/>\s+/', '/\s+</'], ['>', '<'], $html);
}

/**
 * Stored values that are not a translation (TranslatableField::isPresent()):
 * completeness counts each as missing, and a visitor gets the fallback for
 * each — the two sides of the same rule, tested with the same values.
 */
dataset('not a translation', [
    'null' => [null],
    'empty' => [''],
    'spaces' => ['   '],
    'tabs and newlines' => ["\t\n"],
    'a number' => [42],
    'a boolean' => [true],
    'a list' => [['Desserts']],
]);

/**
 * The inclusive upper bound each protocol-accepting site declares for a
 * deployment protocol version.
 *
 * Three files accept a protocol version and none can share a runtime constant
 * with the others: `deploy` and `install-target-operations` are separate bash
 * programs (the installer deliberately never sources `common`), and the build is
 * YAML. So the literal is written three times and read back here, because three
 * independent ceilings would be three different contracts — and the one that
 * matters is whichever is lowest, silently.
 *
 * @return array<string, int|null> null where the declaration could not be found
 */
function deploymentProtocolMaxDeclarations(): array
{
    $sites = [
        'deploy' => [
            'infrastructure/scripts/deploy',
            '/^DEPLOYMENT_PROTOCOL_MAX=([0-9]+)$/m',
        ],
        'install-target-operations' => [
            'infrastructure/scripts/install-target-operations',
            '/^DEPLOYMENT_PROTOCOL_MAX=([0-9]+)$/m',
        ],
        'build-rateguru' => [
            '.github/actions/build-rateguru/action.yml',
            '/^\s*protocol_max=([0-9]+)$/m',
        ],
    ];

    $found = [];

    foreach ($sites as $name => [$path, $pattern]) {
        $found[$name] = preg_match($pattern, File::get(base_path($path)), $matches) === 1
            ? (int) $matches[1]
            : null;
    }

    return $found;
}

/**
 * The committed deployment protocol contract, decoded.
 *
 * @return array<string, mixed>
 */
function deploymentProtocolContract(): array
{
    return json_decode(
        File::get(base_path('infrastructure/config/deployment-protocol.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
}

/**
 * The branch each operational workflow's PRIVILEGED TOOLING checkout must come
 * from — the control-plane contract, as a closed map.
 *
 *   develop  integration branch, and staging's source
 *   main     the production control plane
 *
 * Production operational workflows take their tooling from main because the
 * `production-tits-guru` GitHub Environment allows `main` and `v*` only, and
 * deliberately not `develop`. A production workflow pointed at develop simply
 * cannot run — and should not, because privileged production tooling has to be
 * promoted through a develop -> main pull request before it may act on
 * production. Staging stays on develop, which is the whole point of develop.
 *
 * This says nothing about APPLICATION code. A new release reaches production
 * only through a `v*` tag whose commit is contained in main; main's HEAD is
 * never deployed as an application.
 *
 * Closed on purpose: a new operational workflow has to be classified here
 * deliberately, and ProductionControlPlaneTest proves the map covers every
 * operational workflow in the repository and matches the YAML.
 *
 * @return array<string, string>
 */
function trustedToolingRefs(): array
{
    return [
        // Production control plane.
        'configure-tits-guru.yml' => 'main',
        'provision-tits-guru.yml' => 'main',
        // tits-guru's outbound mail: the activation, its initial-launch
        // rollback, and the first real delivery.
        'activate-tits-guru-mail.yml' => 'main',
        'rollback-tits-guru-mail-activation.yml' => 'main',
        'send-tits-guru-mail-canary.yml' => 'main',
        'prepare-production-host.yml' => 'main',
        'repair-production.yml' => 'main',
        'restore-production.yml' => 'main',
        'recover-production.yml' => 'main',
        'rollback-production.yml' => 'main',
        'verify-production-infrastructure.yml' => 'main',
        'verify-production-mail-signing.yml' => 'main',
        // Integration and staging.
        'deploy-staging.yml' => 'develop',
        'prepare-staging-host.yml' => 'develop',
        'repair-staging.yml' => 'develop',
        'restore-staging.yml' => 'develop',
        'recover-staging.yml' => 'develop',
        'rollback-staging.yml' => 'develop',
        'verify-staging-infrastructure.yml' => 'develop',
    ];
}

/**
 * The trusted tooling ref for one workflow, by file name or path.
 */
function trustedToolingRef(string $workflow): string
{
    $name = basename($workflow);
    $refs = trustedToolingRefs();

    // toHaveKey's second argument is an expected VALUE, not a message.
    expect(array_key_exists($name, $refs))
        ->toBeTrue("{$name} is not classified in trustedToolingRefs() — classify it as 'main' (production control plane) or 'develop' (integration and staging)");

    return $refs[$name];
}

/*
|--------------------------------------------------------------------------
| recover-host harness
|--------------------------------------------------------------------------
|
| The script, the run, the composed fixture, the apply and the simulated
| recovery deployment the three recover-host test files share:
| RecoverHostPreconditionsTest (what a recovery refuses before it starts),
| RecoverHostTest (the apply, its hold, its guard and its compensation) and
| RecoverHostResumeTest (--inspect, --resume and --verify); and the applied
| and completed hosts, built once per worker, that the tests whose subject
| comes after the apply start from.
*/

function recoverHostScript(): string
{
    return base_path('infrastructure/scripts/recover-host');
}

/**
 * @return array{exit: int, output: string}
 */
function recoverHostRun(string $scratch, array $arguments, array $envOverrides = []): array
{
    [$registryPath, $targetsPath] = parityRegistryFixture($scratch);

    $env = infraScriptEnv($scratch, $registryPath, $targetsPath, array_merge(
        fakePostgresEnv($scratch),
        targetRuntimeEnv($scratch),
        recoveryEnv($scratch),
        $envOverrides,
    ));

    [$exit, $output] = runInfraScript(patchedInfraScript($scratch, 'recover-host'), $arguments, $env);

    return ['exit' => $exit, 'output' => $output];
}

/**
 * A prepared, EMPTY replacement host with one exact offsite backup waiting for
 * it: the only starting state a recovery accepts.
 */
function recoveryFixture(string $scratch, array $options = []): void
{
    preparedTargetTreeFixture($scratch, $options);
    installFakePostgres($scratch, $options);
    installTargetRuntimeStubs($scratch);
    installFakePrepareHost($scratch);
    offsiteRcloneStub($scratch);

    file_put_contents($scratch.'/rclone.conf', "[rateguru-b2]\ntype = b2\n");
    touch($scratch.'/rclone.log');

    @mkdir($scratch.'/cron.d', 0o755, true);
    file_put_contents($scratch.'/cron.d/parity-scheduler', "* * * * * root true\n");

    // A prepared host's queue program is registered but has no application to
    // run: the committed program keeps autostart=true and crash-loops until a
    // deployment exists. FATAL is what that looks like, and it is not RUNNING.
    file_put_contents($scratch.'/supervisor-state', ($options['queue_state'] ?? 'FATAL')."\n");

    // Prepare Host installs the target's Supervisor program configuration and
    // validates it; whether its GROUP is loaded into the running Supervisor is
    // a separate question, and on a freshly prepared host it is not
    // ('queue_group_absent'), because activation is deferred until a release
    // exists.
    @mkdir($scratch.'/supervisor-conf.d', 0o755, true);

    if (($options['queue_config'] ?? true) === true) {
        file_put_contents($scratch.'/supervisor-conf.d/parity-queue.conf', "[program:parity-queue]\nautostart=true\n");
    }

    if (($options['queue_group_absent'] ?? false) === true) {
        touch($scratch.'/supervisor-group-absent');
    }

    // The prepared database exists and is EMPTY.
    @mkdir($scratch.'/pg/tables', 0o755, true);
    @mkdir($scratch.'/pg/migrations', 0o755, true);
    file_put_contents($scratch.'/pg/tables/parity_db', ($options['prepared_tables'] ?? 0)."\n");
    file_put_contents($scratch.'/pg/migrations/parity_db', "0\n");

    recoveryOffsiteBackupFixture($scratch, $options['backup'] ?? '20260115-023000', $options['backup_options'] ?? []);
}

/**
 * Runs a full --apply against the parity target. The operation ID the run
 * generated is in its output: see recoveryOperationIdIn().
 *
 * @return array{exit: int, output: string}
 */
function recoveryApply(string $scratch, array $envOverrides = [], string $backupId = '20260115-023000'): array
{
    return recoverHostRun($scratch, [
        '--apply', '--target', 'parity-target', '--backup', $backupId,
    ], $envOverrides);
}

/** The operation ID from a run's machine-readable RATEGURU_RECOVER_RESULT line. */
function recoveryOperationIdIn(string $output): string
{
    expect(preg_match('/RATEGURU_RECOVER_RESULT=(\{.*\})/', $output, $matches))
        ->toBe(1, "no machine-readable recovery result in:\n".$output);

    return json_decode($matches[1], true)['operation'];
}

/** Simulates the controlled recovery deployment: a release, and current. */
function deployRecoveredRelease(string $scratch, string $sourceSha = FIXTURE_SOURCE_SHA, string $release = FIXTURE_RELEASE): void
{
    $root = $scratch.'/target';
    mkdir($root.'/releases/'.$release, 0o755, true);

    file_put_contents(
        $root.'/releases/'.$release.'/release.json',
        json_encode(['project' => 'rateguru', 'release' => $release, 'source_sha' => $sourceSha]),
    );

    symlink($root.'/releases/'.$release, $root.'/current');
}

/**
 * recoveryFixture() with $fixtureOptions, then a plain recoveryApply(): the
 * held, awaiting-code host every test of --inspect, --resume and --verify
 * starts from, and the result of the apply that left it there.
 *
 * The apply is a full recover-host --apply, about 1.3 s and most of what each
 * of those tests costs, and the host it leaves never varies. So a worker
 * applies once per option set, into a template of its own, and every later
 * call copies the template into the test's scratch directory: about 15 ms.
 * Only for a test whose apply is setup; a test about the apply runs its own.
 *
 * Every copy carries the operation ID and the timestamps of that one apply.
 * Each test owns its copy, and none compares them with another test's.
 *
 * @return array{exit: int, output: string}
 */
function recoveryApplied(string $scratch, array $fixtureOptions = []): array
{
    static $templates = [];

    $key = serialize($fixtureOptions);

    if (! isset($templates[$key])) {
        $directory = restoreScratchDir();
        register_shutdown_function(fn () => removeScratchDir($directory));

        recoveryFixture($directory, $fixtureOptions);

        $applied = recoveryApply($directory);
        expect($applied['exit'])->toBe(0, $applied['output']);

        $templates[$key] = [$directory, $applied];
    }

    [$directory, $applied] = $templates[$key];

    return copyRecoveryTemplate($directory, $scratch, $applied)[0];
}

/**
 * recoveryApplied(), the controlled recovery deployment, then a plain
 * --resume: the completed recovery every test of --verify, and of what a
 * finished recovery answers, starts from, and the results of the apply and
 * the resume.
 *
 * The resume is another recover-host run, about 0.6 s on top of the apply,
 * and the host it leaves never varies either. So a worker resumes once, from
 * a copy of the applied template, and every later call copies the result.
 *
 * @return array{0: array{exit: int, output: string}, 1: array{exit: int, output: string}} [applied, resumed]
 */
function recoveryResumed(string $scratch): array
{
    static $template = null;

    if ($template === null) {
        $directory = restoreScratchDir();
        register_shutdown_function(fn () => removeScratchDir($directory));

        $applied = recoveryApplied($directory);
        $operation = recoveryOperationIdIn($applied['output']);

        deployRecoveredRelease($directory);

        $resumed = recoverHostRun($directory, ['--resume', '--target', 'parity-target', '--operation', $operation]);
        expect($resumed['exit'])->toBe(0, $resumed['output']);

        $template = [$directory, $applied, $resumed];
    }

    [$directory, $applied, $resumed] = $template;

    return copyRecoveryTemplate($directory, $scratch, $applied, $resumed);
}

/**
 * copyScratchTemplate() for a recovery host, and the results of the runs that
 * built it, re-addressed to $scratch.
 *
 * Resolving the run environment afterwards writes the parity registry and the
 * copy the prerequisites check reads for $scratch, exactly as the next
 * recover-host run would, so the copied host starts in the state a fresh one
 * reaches before its first run.
 *
 * @param  array{exit: int, output: string}  ...$results
 * @return list<array{exit: int, output: string}>
 */
function copyRecoveryTemplate(string $template, string $scratch, array ...$results): array
{
    copyScratchTemplate($template, $scratch);
    recoveryEnv($scratch);

    return array_map(
        fn (array $result): array => [
            'exit' => $result['exit'],
            'output' => str_replace($template, $scratch, $result['output']),
        ],
        $results,
    );
}

/*
|--------------------------------------------------------------------------
| provision-target harness
|--------------------------------------------------------------------------
|
| The simulated host that provision-target and every installer it delegates
| to run against, shared by ProvisionTargetTest (the operation),
| ProvisionTargetPreconditionsTest (what it refuses before mutating anything),
| ProvisionTargetConfigureHandoffTest (what each mode answers once the
| operator has written shared/.env) and ProvisionTargetMalformedEnvTest (a
| shared/.env that is not a regular file):
| the run and its logs; the fixture registry — one active staging target and
| two planned production ones, among them the synthetic `demo-shop` brand;
| the logging stubs that do the real work inside the scratch directory; the
| fixture filesystem with its owner table and its staging neighbour.
*/

function provisionScript(): string
{
    return base_path('infrastructure/scripts/provision-target');
}

function provisionScratchDir(): string
{
    $dir = sys_get_temp_dir().'/provision-target-'.uniqid('', true).'-'.getmypid();

    foreach (['', '/bin', '/fs', '/log', '/svc', '/toggles'] as $sub) {
        expect(@mkdir($dir.$sub, 0o755, true))->toBeTrue("could not create scratch directory: {$dir}{$sub}");
    }

    return $dir;
}

function provisionCleanup(string $dir): void
{
    exec('rm -rf '.escapeshellarg($dir));
}

function provisionWriteStub(string $path, string $content): void
{
    file_put_contents($path, $content);
    chmod($path, 0o755);
}

/**
 * @param  list<string>  $arguments
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function provisionRun(array $arguments, array $env, ?string $script = null): array
{
    // The scratch bundle, not the repository's own copy. provision-target
    // resolves its library, its registry and every installer relative to
    // itself, so running the repository's copy against a fixture registry
    // would test a bundle nobody ships: half this file, half that one. The
    // fixture names its bundle, and this is a harness detail rather than
    // something the script reads, so it never reaches the subprocess.
    $script ??= $env['RATEGURU_PROVISION_BUNDLE_SCRIPT'] ?? provisionScript();
    unset($env['RATEGURU_PROVISION_BUNDLE_SCRIPT']);

    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(
        array_merge(['bash', $script], $arguments),
        $descriptors,
        $pipes,
        null,
        $env,
    );

    expect($process)->not->toBeFalse('could not start the provision-target subprocess');

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return [proc_close($process), $output];
}

function provisionLog(string $scratch, string $name): string
{
    $path = $scratch.'/log/'.$name;

    return is_file($path) ? (string) file_get_contents($path) : '';
}

/**
 * A synthetic production brand whose every identity, path, pool, socket,
 * program, queue, scheduler and site name is unique and appears nowhere in
 * this repository.
 *
 * @return array<string, mixed>
 */
function provisionDemoTarget(): array
{
    return [
        'id' => 'demo-shop',
        'lifecycle' => 'planned',
        'environment_class' => 'production',
        'application_root' => '/home/www/rateguru/production/demo-shop',
        'runtime_user' => 'rateguru-demo-shop',
        'runtime_group' => 'rateguru-demo-shop',
        'deploy_user' => 'deploy-rateguru-demo-shop',
        'code_group' => 'rateguru-demo-shop-code',
        'incoming_artifacts' => '/home/deploy-rateguru-demo-shop/incoming',
        'release_retention' => 10,
        'database' => ['name' => 'rateguru_demo_shop', 'application_role' => 'rateguru_demo_shop_app'],
        'health' => ['url' => 'http://127.0.0.1/', 'host_header' => 'demo-shop.internal'],
        // Present in the registry and deliberately never rendered anywhere:
        // provisioning must not be able to put a target on the public internet.
        'public_hostnames' => ['demo-shop.example'],
        'backup' => [
            'namespace' => 'demo-shop',
            'local_retention_days' => 30,
            'offsite_retention_days' => 90,
            'minimum_retained_backups' => 2,
        ],
        'php_fpm' => ['pool' => 'rateguru-demo-shop', 'socket' => '/run/php/rateguru-demo-shop.sock'],
        'supervisor' => ['program' => 'rateguru-demo-shop-queue', 'queue' => 'rateguru-demo-shop'],
        'scheduler' => ['name' => 'rateguru-demo-shop-scheduler'],
        'nginx' => ['site_name' => 'rateguru-demo-shop', 'internal_hostname' => 'demo-shop.internal'],
        'environment_template' => 'infrastructure/templates/environment/demo-shop.env.example',
    ];
}

/**
 * The fixture registry: the committed one plus demo-shop.
 *
 * @param  array<string, mixed>  $overrides  dot-free key => value applied to demo-shop
 */
function provisionRegistryJson(array $overrides = [], ?string $lifecycle = null): string
{
    $registry = json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true, 512, JSON_THROW_ON_ERROR);

    $demo = provisionDemoTarget();

    foreach ($overrides as $key => $value) {
        $demo[$key] = $value;
    }

    if ($lifecycle !== null) {
        $demo['lifecycle'] = $lifecycle;
    }

    $registry['targets']['demo-shop'] = $demo;

    return json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
}

/**
 * A scratch copy of the whole infrastructure tree, so the shipped orchestrator
 * and the shipped installers run from one trusted bundle whose registry is the
 * fixture's.
 */
function provisionRepo(string $scratch, string $registryJson, bool $widenActiveAllowlist = false): string
{
    $repo = $scratch.'/repo';

    if (! is_dir($repo)) {
        @mkdir($repo, 0o755, true);
        exec('cp -R '.escapeshellarg(base_path('infrastructure')).' '.escapeshellarg($repo.'/infrastructure').' 2>&1', $out, $code);
        expect($code)->toBe(0, 'could not copy the infrastructure tree: '.implode("\n", $out));
    }

    file_put_contents($repo.'/infrastructure/config/deployment-targets.json', $registryJson);

    // The shipped validator allows exactly one named active target. The
    // activation-parity test needs two, so it widens the allowlist in this
    // scratch copy only — the shipped rule is asserted in its own test.
    $targets = $repo.'/infrastructure/scripts/targets';
    $source = File::get(base_path('infrastructure/scripts/targets'));

    if ($widenActiveAllowlist) {
        $source = str_replace(
            '[[ "${lifecycle}" == "active" ]] && [[ "${target_id}" != "${ACTIVE_ALLOWLIST}" ]]',
            'false',
            $source,
        );
        expect($source)->toContain("if false; then\n        problem \"\${target_id}: lifecycle=active is currently allowed");
    }

    file_put_contents($targets, $source);
    chmod($targets, 0o755);

    return $repo;
}

function provisionWriteStubs(string $scratch): void
{
    // stat: type from the real scratch filesystem (with a type-table override
    // so a plain fixture file can present as a socket), owner/group from the
    // fixture ownership table, mode real.
    provisionWriteStub($scratch.'/bin/stat', <<<'STUB'
        #!/bin/bash
        path="${!#}"
        if [[ -L "${path}" ]]; then ftype="symbolic link"
        elif [[ -d "${path}" ]]; then ftype="directory"
        elif [[ -S "${path}" ]]; then ftype="socket"
        elif [[ -f "${path}" ]]; then
            ftype="regular file"
            row_t="$(PATH="${STUB_REAL_PATH}" awk -F'|' -v p="${path}" '$1 == p && $2 == "TYPE" { print $3; exit }' "${STUB_TYPE_TABLE}" 2>/dev/null)"
            [[ -n "${row_t}" ]] && ftype="${row_t}"
        elif [[ -e "${path}" ]]; then ftype="other"
        else exit 1; fi
        mode="$(PATH="${STUB_REAL_PATH}" stat -c '%a' -- "${path}" 2>/dev/null)" \
            || mode="$(PATH="${STUB_REAL_PATH}" stat -f '%Mp%Lp' "${path}" 2>/dev/null)" || exit 1
        mode="$(printf '%o' $(( 8#${mode} )))"
        row="$(PATH="${STUB_REAL_PATH}" awk -F'|' -v p="${path}" '$1 == p && $2 != "TYPE" { print $2 "|" $3; found = 1; exit } END { exit !found }' "${STUB_OWNER_TABLE}" 2>/dev/null)" || row=""
        if [[ -z "${row}" ]]; then
            row="$(PATH="${STUB_REAL_PATH}" stat -c '%U|%G' -- "${path}" 2>/dev/null)" \
                || row="$(PATH="${STUB_REAL_PATH}" stat -f '%Su|%Sg' "${path}" 2>/dev/null)" || exit 1
        fi
        printf '%s|%s|%s\n' "${ftype}" "${row}" "${mode}"
        STUB);

    // chown: records the invocation and upserts the ownership row for exactly
    // the path given — never recursive, exactly like the real tool.
    provisionWriteStub($scratch.'/bin/chown', <<<'STUB'
        #!/bin/bash
        printf 'chown %s\n' "$*" >> "${STUB_LOG}/chown.log"
        owner_group=""; path=""
        for arg in "$@"; do
            case "${arg}" in
                --) ;;
                -*) ;;
                *) if [[ -z "${owner_group}" ]]; then owner_group="${arg}"; else path="${arg}"; fi ;;
            esac
        done
        owner="${owner_group%%:*}"; group="${owner_group##*:}"
        tmp="${STUB_OWNER_TABLE}.tmp"
        PATH="${STUB_REAL_PATH}" awk -F'|' -v p="${path}" '$1 != p' "${STUB_OWNER_TABLE}" > "${tmp}" 2>/dev/null || : > "${tmp}"
        printf '%s|%s|%s\n' "${path}" "${owner}" "${group}" >> "${tmp}"
        PATH="${STUB_REAL_PATH}" mv "${tmp}" "${STUB_OWNER_TABLE}"
        exit 0
        STUB);

    provisionWriteStub($scratch.'/bin/chmod', <<<'STUB'
        #!/bin/bash
        printf 'chmod %s\n' "$*" >> "${STUB_LOG}/chmod.log"
        PATH="${STUB_REAL_PATH}" chmod "$@"
        STUB);

    provisionWriteStub($scratch.'/bin/install', <<<'STUB'
        #!/bin/bash
        printf 'install %s\n' "$*" >> "${STUB_LOG}/install.log"
        PATH="${STUB_REAL_PATH}" install "$@"
        STUB);

    // groupadd/useradd/usermod: mutate the fixture group/passwd files the way
    // the real shadow tools mutate /etc — never deleting, never renumbering.
    provisionWriteStub($scratch.'/bin/groupadd', <<<'STUB'
        #!/bin/bash
        printf 'groupadd %s\n' "$*" >> "${STUB_LOG}/identity.log"
        name="${!#}"
        if PATH="${STUB_REAL_PATH}" grep -q "^${name}:" "${STUB_GROUP_FILE}"; then exit 9; fi
        max="$(PATH="${STUB_REAL_PATH}" awk -F: 'BEGIN { m = 4999 } $3 > m && $3 < 60000 { m = $3 } END { print m }' "${STUB_GROUP_FILE}")"
        printf '%s:x:%s:\n' "${name}" "$((max + 1))" >> "${STUB_GROUP_FILE}"
        exit 0
        STUB);

    provisionWriteStub($scratch.'/bin/useradd', <<<'STUB'
        #!/bin/bash
        printf 'useradd %s\n' "$*" >> "${STUB_LOG}/identity.log"
        login="${!#}"
        gid_name=""; home=""; shell=""; prev=""
        for arg in "$@"; do
            case "${prev}" in
                --gid) gid_name="${arg}" ;;
                --home-dir) home="${arg}" ;;
                --shell) shell="${arg}" ;;
            esac
            prev="${arg}"
        done
        if PATH="${STUB_REAL_PATH}" grep -q "^${login}:" "${STUB_PASSWD_FILE}"; then exit 9; fi
        gid="$(PATH="${STUB_REAL_PATH}" awk -F: -v g="${gid_name}" '$1 == g { print $3; exit }' "${STUB_GROUP_FILE}")"
        [[ -n "${gid}" ]] || exit 6
        max="$(PATH="${STUB_REAL_PATH}" awk -F: 'BEGIN { m = 4999 } $3 > m && $3 < 60000 { m = $3 } END { print m }' "${STUB_PASSWD_FILE}")"
        printf '%s:x:%s:%s::%s:%s\n' "${login}" "$((max + 1))" "${gid}" "${home}" "${shell}" >> "${STUB_PASSWD_FILE}"
        exit 0
        STUB);

    provisionWriteStub($scratch.'/bin/usermod', <<<'STUB'
        #!/bin/bash
        printf 'usermod %s\n' "$*" >> "${STUB_LOG}/identity.log"
        login="${!#}"
        groups=""; prev=""
        for arg in "$@"; do
            if [[ "${prev}" == "--groups" || "${prev}" == "-G" ]]; then groups="${arg}"; fi
            prev="${arg}"
        done
        tmp="${STUB_GROUP_FILE}.tmp"
        PATH="${STUB_REAL_PATH}" awk -F: -v OFS=: -v g="${groups}" -v u="${login}" '
            $1 == g {
                if ($4 == "") { $4 = u }
                else if (index("," $4 ",", "," u ",") == 0) { $4 = $4 "," u }
            }
            { print }
        ' "${STUB_GROUP_FILE}" > "${tmp}"
        PATH="${STUB_REAL_PATH}" mv "${tmp}" "${STUB_GROUP_FILE}"
        exit 0
        STUB);

    // systemctl: stateful. A reload respawns the simulated Nginx workers with
    // whatever supplementary groups www-data holds AT THAT MOMENT, which is
    // exactly why a reload is what fixes stale workers on a real host.
    provisionWriteStub($scratch.'/bin/systemctl', <<<'STUB'
        #!/bin/bash
        printf 'systemctl %s\n' "$*" >> "${STUB_LOG}/systemctl.log"
        respawn_nginx_workers() {
            gids="$(PATH="${STUB_REAL_PATH}" awk -F: -v u=www-data '
                $1 == u { own = $3 }
                ($4 ~ ("(^|,)" u "(,|$)")) { extra = extra " " $3 }
                END { printf "%s%s", own, extra }
            ' "${STUB_GROUP_FILE}")"
            PATH="${STUB_REAL_PATH}" rm -rf "${STUB_FS}/proc"
            : > "${STUB_FS}/nginx-worker-pids.txt"
            for pid in 9001 9002; do
                PATH="${STUB_REAL_PATH}" mkdir -p "${STUB_FS}/proc/${pid}"
                printf 'Name:\tnginx\nGroups:\t%s \n' "${gids}" > "${STUB_FS}/proc/${pid}/status"
                printf '%s\n' "${pid}" >> "${STUB_FS}/nginx-worker-pids.txt"
            done
        }
        # PHP-FPM creates a pool's socket when it loads that pool's configuration,
        # and never before — and drops the socket of a pool whose configuration is
        # gone. The fixture used to place the sockets itself, which is a state no
        # host can be in — a socket for a pool that does not exist yet — and it meant
        # a verification could pass without anything having produced them.
        #
        # So a reload RECONCILES: after it, the sockets under /run/php are exactly
        # the ones the installed pools declare. Creating without removing would have
        # left a socket behind for a pool somebody deleted, which is the same kind of
        # impossible state in the other direction — a verification passing on
        # evidence of a pool that no longer exists.
        #
        # /run/php is PHP-FPM's own directory here, so the reconciliation is scoped
        # to it: table rows for anything outside it are never touched.
        sync_fpm_sockets() {
            local conf listen owner group mode declared socket
            PATH="${STUB_REAL_PATH}" mkdir -p "${STUB_FS}/run/php"
            declared=""
            for conf in "${STUB_FS}"/etc/php/*/fpm/pool.d/*.conf; do
                [[ -f "${conf}" ]] || continue
                listen="$(PATH="${STUB_REAL_PATH}" sed -n 's/^[[:space:]]*listen[[:space:]]*=[[:space:]]*//p' "${conf}" | PATH="${STUB_REAL_PATH}" head -n 1)"
                [[ "${listen}" == /run/php/*.sock ]] || continue
                owner="$(PATH="${STUB_REAL_PATH}" sed -n 's/^[[:space:]]*listen\.owner[[:space:]]*=[[:space:]]*//p' "${conf}" | PATH="${STUB_REAL_PATH}" head -n 1)"
                group="$(PATH="${STUB_REAL_PATH}" sed -n 's/^[[:space:]]*listen\.group[[:space:]]*=[[:space:]]*//p' "${conf}" | PATH="${STUB_REAL_PATH}" head -n 1)"
                mode="$(PATH="${STUB_REAL_PATH}" sed -n 's/^[[:space:]]*listen\.mode[[:space:]]*=[[:space:]]*//p' "${conf}" | PATH="${STUB_REAL_PATH}" head -n 1)"
                PATH="${STUB_REAL_PATH}" touch "${STUB_FS}${listen}"
                PATH="${STUB_REAL_PATH}" chmod "${mode:-0660}" "${STUB_FS}${listen}"
                # The stat stub reads the type from this table for regular files,
                # and the owner from the owner table, so both have to say socket.
                PATH="${STUB_REAL_PATH}" grep -v "^${STUB_FS}${listen}|" "${STUB_TYPE_TABLE}" > "${STUB_TYPE_TABLE}.tmp" 2>/dev/null || : > "${STUB_TYPE_TABLE}.tmp"
                printf '%s|TYPE|socket\n' "${STUB_FS}${listen}" >> "${STUB_TYPE_TABLE}.tmp"
                PATH="${STUB_REAL_PATH}" mv "${STUB_TYPE_TABLE}.tmp" "${STUB_TYPE_TABLE}"
                PATH="${STUB_REAL_PATH}" grep -v "^${STUB_FS}${listen}|" "${STUB_OWNER_TABLE}" > "${STUB_OWNER_TABLE}.tmp" 2>/dev/null || : > "${STUB_OWNER_TABLE}.tmp"
                printf '%s|%s|%s\n' "${STUB_FS}${listen}" "${owner:-www-data}" "${group:-www-data}" >> "${STUB_OWNER_TABLE}.tmp"
                PATH="${STUB_REAL_PATH}" mv "${STUB_OWNER_TABLE}.tmp" "${STUB_OWNER_TABLE}"
                declared="${declared} ${STUB_FS}${listen}"
            done

            # Whatever is left in /run/php that no installed pool declares is the
            # socket of a pool that is gone, and a reload is where PHP-FPM unlinks it.
            for socket in "${STUB_FS}"/run/php/*.sock; do
                [[ -e "${socket}" ]] || continue
                case " ${declared} " in
                    *" ${socket} "*) continue ;;
                esac
                PATH="${STUB_REAL_PATH}" rm -f "${socket}"
                PATH="${STUB_REAL_PATH}" grep -v "^${socket}|" "${STUB_TYPE_TABLE}" > "${STUB_TYPE_TABLE}.tmp" 2>/dev/null || : > "${STUB_TYPE_TABLE}.tmp"
                PATH="${STUB_REAL_PATH}" mv "${STUB_TYPE_TABLE}.tmp" "${STUB_TYPE_TABLE}"
                PATH="${STUB_REAL_PATH}" grep -v "^${socket}|" "${STUB_OWNER_TABLE}" > "${STUB_OWNER_TABLE}.tmp" 2>/dev/null || : > "${STUB_OWNER_TABLE}.tmp"
                PATH="${STUB_REAL_PATH}" mv "${STUB_OWNER_TABLE}.tmp" "${STUB_OWNER_TABLE}"
            done
        }
        cmd=""; unit=""
        for arg in "$@"; do
            case "${arg}" in
                --quiet) ;;
                *) if [[ -z "${cmd}" ]]; then cmd="${arg}"; else unit="${arg}"; fi ;;
            esac
        done
        unit="${unit%.service}"
        case "${cmd}" in
            is-enabled) [[ -e "${STUB_SVC_STATE}/${unit}.enabled" ]] ;;
            is-active)  [[ -e "${STUB_SVC_STATE}/${unit}.active" ]] ;;
            enable)  touch "${STUB_SVC_STATE}/${unit}.enabled" ;;
            disable) rm -f "${STUB_SVC_STATE}/${unit}.enabled" ;;
            start)
                touch "${STUB_SVC_STATE}/${unit}.active"
                if [[ "${unit}" == nginx ]]; then respawn_nginx_workers; fi
                if [[ "${unit}" == *fpm* ]]; then sync_fpm_sockets; fi
                ;;
            stop)
                rm -f "${STUB_SVC_STATE}/${unit}.active"
                if [[ "${unit}" == *fpm* ]]; then PATH="${STUB_REAL_PATH}" rm -f "${STUB_FS}"/run/php/*.sock; fi
                ;;
            reload|restart)
                [[ -e "${STUB_SVC_STATE}/${unit}.active" ]] || exit 1
                # A reload that fails loads nothing, so it creates no socket.
                [[ -e "${STUB_TOGGLES}/${unit}-reload-fail" ]] && exit 1
                if [[ "${unit}" == nginx ]]; then respawn_nginx_workers; fi
                if [[ "${unit}" == *fpm* ]]; then sync_fpm_sockets; fi
                ;;
            *) exit 0 ;;
        esac
        STUB);

    provisionWriteStub($scratch.'/bin/pgrep', <<<'STUB'
        #!/bin/bash
        [[ -s "${STUB_FS}/nginx-worker-pids.txt" ]] || exit 1
        PATH="${STUB_REAL_PATH}" cat "${STUB_FS}/nginx-worker-pids.txt"
        STUB);

    foreach (['nginx' => 'nginx', 'sshd' => 'sshd', 'php-fpm8.5' => 'php-fpm'] as $bin => $log) {
        provisionWriteStub($scratch.'/bin/'.$bin, <<<STUB
            #!/bin/bash
            printf '{$log} %s\\n' "\$*" >> "\${STUB_LOG}/{$log}.log"
            [[ -e "\${STUB_TOGGLES}/{$log}-t-fail" ]] && exit 1
            exit 0
            STUB);
    }

    provisionWriteStub($scratch.'/bin/supervisorctl', <<<'STUB'
        #!/bin/bash
        printf 'supervisorctl %s\n' "$*" >> "${STUB_LOG}/supervisorctl.log"
        case "${1:-}" in
            reread)
                [[ -e "${STUB_TOGGLES}/supervisor-reread-fail" ]] && { echo "ERROR: CANT_REREAD bad config"; exit 0; }
                echo "No config updates to processes"
                ;;
            status)
                echo "${2:-unknown}: ERROR (no such process)"
                exit 1
                ;;
        esac
        exit 0
        STUB);

    // One stub per child installer the services installer coordinates but
    // this operation does not exercise directly. Each answers verify from its
    // own compliance toggle and records every invocation, so a test can prove
    // both what was asked and what was never asked.
    foreach ([
        'runtime-installer', 'operations-installer', 'perimeter-installer',
        'public-storage-installer', 'mail-capture-installer', 'verify-mail-capture',
        'nightwatch-installer', 'mail-signing-installer', 'mail-gateway-installer',
    ] as $child) {
        provisionWriteStub($scratch.'/bin/'.$child, <<<'STUB'
            #!/bin/bash
            me="$(basename "$0")"
            printf '%s %s\n' "${me}" "$*" >> "${STUB_LOG}/children.log"
            case "$*" in
                # The closed allowlist question, answered the way the real
                # installer answers it: staging-main records a deployment
                # marker, and no other target does.
                *--supports-deployment-marker*)
                    [[ "$*" == *"--target staging-main"* ]] && exit 0
                    exit 1
                    ;;
                *--check*)
                    [[ -e "${STUB_TOGGLES}/${me}-check-fail" ]] && exit 1
                    exit 0
                    ;;
                *--apply*)
                    [[ -e "${STUB_TOGGLES}/${me}-apply-fail" ]] && exit 1
                    touch "${STUB_TOGGLES}/${me}-compliant"
                    exit 0
                    ;;
                *)
                    [[ -e "${STUB_TOGGLES}/${me}-compliant" ]] && exit 0
                    exit 1
                    ;;
            esac
            STUB);
    }
}

function provisionOwnerTableAdd(string $scratch, string $physical, string $owner, string $group): void
{
    $table = $scratch.'/fs/owner-table.txt';
    $rows = array_filter(
        explode("\n", (string) @file_get_contents($table)),
        fn (string $row): bool => $row !== '' && ! str_starts_with($row, $physical.'|'),
    );
    $rows[] = "{$physical}|{$owner}|{$group}";
    file_put_contents($table, implode("\n", $rows)."\n");
}

/**
 * @return array<string, array{0: string, 1: string}> physical => [owner, group]
 */
function provisionOwnerTableRows(string $scratch): array
{
    $rows = [];

    foreach (explode("\n", (string) @file_get_contents($scratch.'/fs/owner-table.txt')) as $line) {
        if ($line === '') {
            continue;
        }

        [$path, $owner, $group] = explode('|', $line);
        $rows[$path] = [$owner, $group];
    }

    return $rows;
}

/**
 * The host roots install-bootstrap-host-layout treats as prerequisites in
 * every target-scoped run: logical path => [owner, group, mode].
 *
 * @return array<string, array{0: string, 1: string, 2: int}>
 */
function provisionHostRoots(): array
{
    return [
        '/home/www/rateguru' => ['root', 'root', 0o755],
        '/home/www/rateguru/config' => ['root', 'root', 0o755],
        '/home/www/rateguru/bin' => ['root', 'root', 0o755],
        '/home/www/rateguru/backups' => ['root', 'root', 0o700],
        '/home/www/rateguru/run' => ['root', 'root', 0o700],
        '/var/log/rateguru' => ['root', 'root', 0o750],
    ];
}

/**
 * The active staging neighbour, built exactly as a converged host has it, so
 * "provisioning a new target changed nothing about the live one" is proved
 * against real state rather than against absence.
 */
function provisionBuildStagingNeighbour(string $scratch): void
{
    $fs = $scratch.'/fs';
    $root = $fs.'/home/www/rateguru/staging';

    foreach ([
        '/releases/20240101120000', '/shared/storage/logs', '/shared/storage/app/public',
        '/locks', '/deployments',
    ] as $sub) {
        @mkdir($root.$sub, 0o755, true);
    }

    chmod($root.'/releases', 0o2750);
    chmod($root.'/shared', 0o2770);
    chmod($root.'/shared/storage', 0o2770);
    chmod($root.'/shared/storage/logs', 0o2770);
    chmod($root.'/locks', 0o2750);
    chmod($root.'/deployments', 0o2750);

    file_put_contents($root.'/shared/.env', "APP_KEY=base64:STAGING-ENV-SENTINEL\n");
    file_put_contents($root.'/shared/storage/app/public/upload.jpg', 'STAGING-UPLOAD-SENTINEL');
    file_put_contents($root.'/shared/storage/logs/laravel.log', "STAGING-LOG-SENTINEL\n");
    file_put_contents($root.'/releases/20240101120000/artisan', "<?php // STAGING-RELEASE-SENTINEL\n");
    symlink($root.'/releases/20240101120000', $root.'/current');
    symlink($root.'/releases/20240101120000', $root.'/previous');

    @mkdir($fs.'/home/deploy-rateguru-staging/.ssh', 0o700, true);
    @mkdir($fs.'/home/deploy-rateguru-staging/incoming', 0o750, true);
    file_put_contents($fs.'/home/deploy-rateguru-staging/.ssh/authorized_keys', "ssh-ed25519 AAAA-STAGING-KEY sentinel\n");

    foreach ([
        '/etc/nginx/sites-available/rateguru-staging' => 'infrastructure/config/nginx/rateguru-staging',
        '/etc/php/8.5/fpm/pool.d/rateguru-staging.conf' => 'infrastructure/config/php-fpm/rateguru-staging.conf',
        '/etc/supervisor/conf.d/rateguru-staging-queue.conf' => 'infrastructure/config/supervisor/rateguru-staging-queue.conf',
        '/etc/cron.d/rateguru-staging-scheduler' => 'infrastructure/config/cron/rateguru-staging-scheduler',
    ] as $installed => $committed) {
        copy(base_path($committed), $fs.$installed);
        chmod($fs.$installed, 0o644);
        provisionOwnerTableAdd($scratch, $fs.$installed, 'root', 'root');
    }

    symlink('/etc/nginx/sites-available/rateguru-staging', $fs.'/etc/nginx/sites-enabled/rateguru-staging');

}

/**
 * Build the simulated host and return the environment provision-target and
 * every installer it delegates to run against.
 *
 * Options:
 *   registry:             JSON override for the whole fixture registry
 *   demoOverrides:        dot-free key => value applied to the demo-shop entry
 *   demoLifecycle:        lifecycle override for demo-shop
 *   widenActiveAllowlist: allow more than one active target in the scratch
 *                         validator (activation-parity scenario only)
 *   euid:                 string (default '0')
 *   passwdExtra:          extra fixture /etc/passwd lines
 *   groupExtra:           extra fixture /etc/group lines
 *   omitHostRoots:        list<string> host roots NOT created
 *
 * @param  array<string, mixed>  $options
 * @return array<string, string>
 */
function provisionFixture(string $scratch, array $options = []): array
{
    $fs = $scratch.'/fs';

    $registryJson = $options['registry']
        ?? provisionRegistryJson($options['demoOverrides'] ?? [], $options['demoLifecycle'] ?? null);

    $repo = provisionRepo($scratch, $registryJson, (bool) ($options['widenActiveAllowlist'] ?? false));

    foreach ([
        '/home', '/var/log', '/etc/nginx/sites-available', '/etc/nginx/sites-enabled',
        '/etc/php/8.5/fpm/pool.d', '/etc/supervisor/conf.d', '/etc/cron.d',
        '/etc/ssh/sshd_config.d', '/usr/bin', '/run/php', '/var/backups',
    ] as $sub) {
        @mkdir($fs.$sub, 0o755, true);
    }

    touch($fs.'/usr/bin/php8.5');

    file_put_contents($fs.'/owner-table.txt', '');
    file_put_contents($fs.'/type-table.txt', '');

    $omitted = $options['omitHostRoots'] ?? [];

    foreach (provisionHostRoots() as $logical => [$owner, $group, $mode]) {
        if (in_array($logical, $omitted, true)) {
            continue;
        }

        @mkdir($fs.$logical, 0o755, true);
        chmod($fs.$logical, $mode);
        provisionOwnerTableAdd($scratch, $fs.$logical, $owner, $group);
    }

    // The shared namespace every production target sits in. Host
    // infrastructure: root-owned and traversable, so a runtime user can reach
    // its own tree through it. `legacyNamespace` reproduces the state a real
    // host that predates the multi-target registry is actually in, where this
    // directory was ONE production application's root.
    @mkdir($fs.'/home/www/rateguru/production', 0o755, true);

    if ($options['legacyNamespace'] ?? false) {
        chmod($fs.'/home/www/rateguru/production', 0o2750);
        provisionOwnerTableAdd($scratch, $fs.'/home/www/rateguru/production', 'deploy-rateguru', 'rateguru-production-code');
    } else {
        provisionOwnerTableAdd($scratch, $fs.'/home/www/rateguru/production', 'root', 'root');
    }

    file_put_contents($fs.'/etc-passwd', implode("\n", array_merge([
        'root:x:0:0:root:/root:/bin/bash',
        'www-data:x:33:33::/var/www:/usr/sbin/nologin',
        'postgres:x:110:118::/var/lib/postgresql:/bin/bash',
        'rateguru-staging:x:5001:5001::/home/www/rateguru/staging:/usr/sbin/nologin',
        'deploy-rateguru-staging:x:5002:5002::/home/deploy-rateguru-staging:/bin/bash',
    ], $options['passwdExtra'] ?? []))."\n");

    file_put_contents($fs.'/etc-group', implode("\n", array_merge([
        'root:x:0:',
        'www-data:x:33:',
        'postgres:x:118:',
        'rateguru-staging:x:5001:',
        'deploy-rateguru-staging:x:5002:',
        'rateguru-staging-code:x:5010:rateguru-staging,deploy-rateguru-staging,www-data',
    ], $options['groupExtra'] ?? []))."\n");

    provisionWriteStubs($scratch);
    provisionBuildStagingNeighbour($scratch);

    // The host's installed runtime registry. A prepared host has the same
    // revision the trusted bundle carries; the tests that matter here are the
    // ones that make it differ.
    @mkdir($fs.'/home/www/rateguru/etc', 0o755, true);
    file_put_contents(
        $fs.'/home/www/rateguru/etc/deployment-targets.json',
        $options['installedRegistryJson'] ?? File::get($repo.'/infrastructure/config/deployment-targets.json'),
    );

    // The demo pool's socket, as a running PHP-FPM would present it.

    // Nginx workers that predate every RateGuru code group — the state a real
    // host is in before its first reload.
    @mkdir($fs.'/proc/4101', 0o755, true);
    file_put_contents($fs.'/proc/4101/status', "Name:\tnginx\nGroups:\t33 \n");
    file_put_contents($fs.'/nginx-worker-pids.txt', "4101\n");

    // Every base host service is already enabled and running: provisioning
    // runs on a prepared host and never starts one.
    foreach (['ssh', 'cron', 'nginx', 'postgresql', 'redis-server', 'supervisor', 'php8.5-fpm'] as $unit) {
        touch($scratch.'/svc/'.$unit.'.enabled');
        touch($scratch.'/svc/'.$unit.'.active');
    }

    // The host-wide children a target-scoped run never converges are already
    // compliant, so their state can never be mistaken for something this
    // operation did.
    foreach ([
        'runtime-installer', 'operations-installer', 'perimeter-installer',
        'public-storage-installer', 'mail-capture-installer', 'verify-mail-capture',
        'nightwatch-installer',
    ] as $child) {
        touch($scratch.'/toggles/'.$child.'-compliant');
    }

    $realPath = getenv('PATH') ?: '/usr/bin:/bin';

    $env = [
        'PATH' => $realPath,
        'HOME' => getenv('HOME') ?: '/tmp',
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',

        // The orchestrator, run from the scratch bundle. There is deliberately
        // no seam for the library or the registry it resolves: it reads the
        // `common` and the `config/deployment-targets.json` beside itself, so
        // the bundle under test is the one that decides what provisioning
        // means.
        'RATEGURU_PROVISION_BUNDLE_SCRIPT' => $repo.'/infrastructure/scripts/provision-target',
        'RATEGURU_DEPLOYMENT_CONF_FILE' => base_path('infrastructure/templates/deployment.conf.example'),

        // The host's installed operational bundle, as a prerequisite rather
        // than as an implementation: a verify gate that answers from its own
        // compliance toggle, and the runtime registry it would have installed.
        'RATEGURU_PROVISION_OPERATIONS_BIN' => $scratch.'/bin/operations-installer',
        'RATEGURU_PROVISION_INSTALLED_REGISTRY' => $options['installedRegistry'] ?? $fs.'/home/www/rateguru/etc/deployment-targets.json',
        'RATEGURU_TARGET_REGISTRY_FILE' => $repo.'/infrastructure/config/deployment-targets.json',
        'RATEGURU_TARGETS_CLI' => $repo.'/infrastructure/scripts/targets',
        'RATEGURU_PROVISION_EUID' => $options['euid'] ?? '0',
        // The machine's lock. Targets share a host, so an apply claims it for
        // the whole run; the fixture points it at the simulated host's own
        // run root rather than the real one.
        'RATEGURU_HOST_LOCK_ROOT' => $options['lockRoot'] ?? $fs.'/home/www/rateguru/run',
        'RATEGURU_PROVISION_FS_ROOT' => $fs,
        'RATEGURU_PROVISION_RUNTIME_BIN' => $scratch.'/bin/runtime-installer',
        'RATEGURU_PROVISION_HOSTLAYOUT_BIN' => $repo.'/infrastructure/scripts/install-bootstrap-host-layout',
        'RATEGURU_PROVISION_SERVICES_BIN' => $repo.'/infrastructure/scripts/install-bootstrap-services',

        // The real host-layout installer, against the simulated host.
        'RATEGURU_HOSTLAYOUT_EUID' => '0',
        'RATEGURU_HOSTLAYOUT_FS_ROOT' => $fs,
        'RATEGURU_HOSTLAYOUT_PASSWD_FILE' => $fs.'/etc-passwd',
        'RATEGURU_HOSTLAYOUT_GROUP_FILE' => $fs.'/etc-group',
        'RATEGURU_HOSTLAYOUT_SOURCE_REGISTRY' => $repo.'/infrastructure/config/deployment-targets.json',
        'RATEGURU_HOSTLAYOUT_STAT_BIN' => $scratch.'/bin/stat',
        'RATEGURU_HOSTLAYOUT_INSTALL_BIN' => $scratch.'/bin/install',
        'RATEGURU_HOSTLAYOUT_CHOWN_BIN' => $scratch.'/bin/chown',
        'RATEGURU_HOSTLAYOUT_CHMOD_BIN' => $scratch.'/bin/chmod',
        'RATEGURU_HOSTLAYOUT_GROUPADD_BIN' => $scratch.'/bin/groupadd',
        'RATEGURU_HOSTLAYOUT_USERADD_BIN' => $scratch.'/bin/useradd',
        'RATEGURU_HOSTLAYOUT_USERMOD_BIN' => $scratch.'/bin/usermod',

        // The real services installer, against the same simulated host.
        'RATEGURU_BOOTSTRAPSVC_EUID' => '0',
        'RATEGURU_BOOTSTRAPSVC_FS_ROOT' => $fs,
        'RATEGURU_BOOTSTRAPSVC_PASSWD_FILE' => $fs.'/etc-passwd',
        'RATEGURU_BOOTSTRAPSVC_GROUP_FILE' => $fs.'/etc-group',
        'RATEGURU_BOOTSTRAPSVC_SOURCE_REGISTRY' => $repo.'/infrastructure/config/deployment-targets.json',
        'RATEGURU_BOOTSTRAPSVC_PGREP_BIN' => $scratch.'/bin/pgrep',
        'RATEGURU_BOOTSTRAPSVC_NGINX_WORKER_WAIT_ATTEMPTS' => '2',
        'RATEGURU_BOOTSTRAPSVC_RUNTIME_INSTALLER_BIN' => $scratch.'/bin/runtime-installer',
        'RATEGURU_BOOTSTRAPSVC_HOSTLAYOUT_INSTALLER_BIN' => $repo.'/infrastructure/scripts/install-bootstrap-host-layout',
        'RATEGURU_BOOTSTRAPSVC_OPERATIONS_INSTALLER_BIN' => $scratch.'/bin/operations-installer',
        'RATEGURU_BOOTSTRAPSVC_PERIMETER_INSTALLER_BIN' => $scratch.'/bin/perimeter-installer',
        'RATEGURU_BOOTSTRAPSVC_PUBLIC_STORAGE_INSTALLER_BIN' => $scratch.'/bin/public-storage-installer',
        'RATEGURU_BOOTSTRAPSVC_NIGHTWATCH_INSTALLER_BIN' => $scratch.'/bin/nightwatch-installer',
        'RATEGURU_BOOTSTRAPSVC_MAIL_CAPTURE_INSTALLER_BIN' => $scratch.'/bin/mail-capture-installer',
        'RATEGURU_BOOTSTRAPSVC_VERIFY_MAIL_CAPTURE_BIN' => $scratch.'/bin/verify-mail-capture',
        // Present so that a target-scoped run touching the host-global mail
        // signer or gateway would be recorded, not silently run the real
        // installer.
        'RATEGURU_BOOTSTRAPSVC_MAIL_SIGNING_INSTALLER_BIN' => $scratch.'/bin/mail-signing-installer',
        'RATEGURU_BOOTSTRAPSVC_MAIL_GATEWAY_INSTALLER_BIN' => $scratch.'/bin/mail-gateway-installer',
        'RATEGURU_BOOTSTRAPSVC_SYSTEMCTL_BIN' => $scratch.'/bin/systemctl',
        'RATEGURU_BOOTSTRAPSVC_NGINX_BIN' => $scratch.'/bin/nginx',
        'RATEGURU_BOOTSTRAPSVC_SSHD_BIN' => $scratch.'/bin/sshd',
        'RATEGURU_BOOTSTRAPSVC_PHP_FPM_BIN' => $scratch.'/bin/php-fpm8.5',
        'RATEGURU_BOOTSTRAPSVC_SUPERVISORCTL_BIN' => $scratch.'/bin/supervisorctl',
        'RATEGURU_BOOTSTRAPSVC_STAT_BIN' => $scratch.'/bin/stat',
        'RATEGURU_BOOTSTRAPSVC_INSTALL_BIN' => $scratch.'/bin/install',
        'RATEGURU_BOOTSTRAPSVC_CHOWN_BIN' => $scratch.'/bin/chown',
        'RATEGURU_BOOTSTRAPSVC_CHMOD_BIN' => $scratch.'/bin/chmod',
        'RATEGURU_BOOTSTRAPSVC_SOCKET_WAIT_ATTEMPTS' => '1',
        'RATEGURU_BOOTSTRAPSVC_QUEUE_WAIT_ATTEMPTS' => '1',
        'RATEGURU_BOOTSTRAPSVC_STABILITY_WAIT' => '0',
        'RATEGURU_BOOTSTRAPSVC_RETRY_DELAY' => '0',

        'STUB_LOG' => $scratch.'/log',
        'STUB_REAL_PATH' => $realPath,
        'STUB_OWNER_TABLE' => $fs.'/owner-table.txt',
        'STUB_TYPE_TABLE' => $fs.'/type-table.txt',
        'STUB_PASSWD_FILE' => $fs.'/etc-passwd',
        'STUB_GROUP_FILE' => $fs.'/etc-group',
        'STUB_SVC_STATE' => $scratch.'/svc',
        'STUB_TOGGLES' => $scratch.'/toggles',
        'STUB_FS' => $fs,
    ];

    // The sockets of the pools this host already has, produced the way a host
    // produces them: by loading the installed pool configuration. The base
    // services above are marked active by touching their state files, which does
    // not go through the stub, so the first load is performed explicitly here.
    //
    // It matters that this is a RELOAD and not a `touch`: a fixture that places
    // the sockets itself describes a host that cannot exist — a pool socket with
    // no pool — and lets a post-apply verification pass without anything having
    // created one.
    provisionReloadPhpFpm($env);

    return $env;
}

/**
 * Runs the fixture's own systemctl stub, so service state changes the harness
 * needs go through the same code path a run under test would use.
 *
 * @param  array<string, string>  $env
 */
function provisionReloadPhpFpm(array $env): void
{
    // Into a log of its own, deliberately. Tests read ${STUB_LOG}/systemctl.log to
    // assert what the RUN did — "check mode reloads nothing" among them — and the
    // fixture's own setup is not something the run did. Writing there would make
    // every such assertion fail on the harness rather than on the code.
    $setupLog = $env['STUB_LOG'].'/fixture-setup';
    @mkdir($setupLog, 0o755, true);

    $process = proc_open(
        [$env['RATEGURU_BOOTSTRAPSVC_SYSTEMCTL_BIN'], 'reload', 'php8.5-fpm'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        ['STUB_LOG' => $setupLog] + $env + ['PATH' => $env['STUB_REAL_PATH']],
    );

    if ($process === false) {
        throw new RuntimeException('the fixture could not run its own systemctl stub');
    }

    $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);

    if ($status !== 0) {
        throw new RuntimeException("the fixture's php-fpm reload failed ({$status}): {$output}");
    }
}

/**
 * Everything about `demo-shop` a converged host would have, for the
 * idempotency and drift scenarios that start from an already-provisioned
 * target rather than an empty one.
 */
function provisionSnapshotDemoState(string $scratch): array
{
    $fs = $scratch.'/fs';
    $snapshot = [];

    foreach ([
        '/home/www/rateguru/production/demo-shop',
        '/home/deploy-rateguru-demo-shop',
        '/etc/nginx/sites-available/rateguru-demo-shop',
        '/etc/nginx/sites-enabled/rateguru-demo-shop',
        '/etc/php/8.5/fpm/pool.d/rateguru-demo-shop.conf',
        '/etc/supervisor/conf.d/rateguru-demo-shop-queue.conf',
        '/etc/cron.d/rateguru-demo-shop-scheduler',
    ] as $logical) {
        $snapshot += provisionTreeSnapshot($fs.$logical);
    }

    return $snapshot;
}

/**
 * Content + structure snapshot for mutation-free proofs.
 *
 * @return array<string, string>
 */
function provisionTreeSnapshot(string $path): array
{
    if (! file_exists($path) && ! is_link($path)) {
        return [];
    }

    if (is_link($path)) {
        return [$path => 'link:'.readlink($path)];
    }

    if (is_file($path)) {
        return [$path => md5_file($path).':'.substr(sprintf('%o', fileperms($path)), -4)];
    }

    $snapshot = [$path => 'dir:'.substr(sprintf('%o', fileperms($path)), -4)];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $entry) {
        $entryPath = $entry->getPathname();

        if (is_link($entryPath)) {
            $snapshot[$entryPath] = 'link:'.readlink($entryPath);
        } elseif ($entry->isFile()) {
            $snapshot[$entryPath] = md5_file($entryPath).':'.substr(sprintf('%o', fileperms($entryPath)), -4);
        } else {
            $snapshot[$entryPath] = 'dir:'.substr(sprintf('%o', fileperms($entryPath)), -4);
        }
    }

    ksort($snapshot);

    return $snapshot;
}

/**
 * One counter out of the report's SUMMARY block.
 *
 * Reads the summary rather than counting item lines, because the summary is the
 * number the exit status is derived from — and because an item line's label can
 * appear inside indented child output too.
 */
function provisionSummaryCount(string $output, string $label): int
{
    $summary = substr($output, (int) strrpos($output, 'SUMMARY'));

    expect(preg_match('/^'.preg_quote($label, '/').': (\d+)$/m', $summary, $matches))
        ->toBe(1, "the report must carry a {$label} counter:\n{$summary}");

    return (int) $matches[1];
}

/**
 * `demo-shop` freshly provisioned into $scratch — the state every test of the
 * hand-off to Configure starts from, as does every test of what a provisioned
 * target does next — and the environment that points at it.
 *
 * Getting there is a full `--apply`, which is most of what each of those tests
 * cost, and its result never varies. So a worker provisions it once, into a
 * template of its own, and every later call copies the template into the
 * test's scratch directory: 30 ms instead of 2–3 s.
 *
 * copyScratchTemplate() rewrites the absolute paths the simulated host records
 * and proves none still names the template; the environment is rewritten here.
 *
 * @return array<string, string>
 */
function provisionDemoShopProvisioned(string $scratch): array
{
    static $template = null;

    if ($template === null) {
        $directory = provisionScratchDir();
        $environment = provisionFixture($directory);

        [$exit, $output] = provisionRun(['--apply', '--target', 'demo-shop'], $environment);
        expect($exit)->toBe(0, $output);

        register_shutdown_function(fn () => provisionCleanup($directory));
        $template = [$directory, $environment];
    }

    [$directory, $environment] = $template;

    copyScratchTemplate($directory, $scratch);

    return array_map(
        fn (string $value): string => str_replace($directory, $scratch, $value),
        $environment,
    );
}

/**
 * A provisioned target, then the canonical environment file an operator writes
 * before Configure — the exact state the real run was in.
 */
function provisionWithCanonicalEnv(string $scratch): array
{
    $env = provisionDemoShopProvisioned($scratch);

    $root = $scratch.'/fs/home/www/rateguru/production/demo-shop';
    @mkdir($root.'/shared', 0o755, true);
    file_put_contents($root.'/shared/.env', "APP_KEY=base64:OPERATOR-WROTE-THIS\n");

    return [$env, $root];
}

/**
 * A provisioned target whose shared/.env path is NOT the regular file
 * configure-target requires: the two shapes an operator actually produces by
 * accident, or that a half-finished recovery leaves behind.
 *
 * @return array{0: array<string, string>, 1: string}
 */
function provisionWithMalformedEnv(string $scratch, string $shape): array
{
    $env = provisionDemoShopProvisioned($scratch);

    $root = $scratch.'/fs/home/www/rateguru/production/demo-shop';
    @mkdir($root.'/shared', 0o755, true);

    match ($shape) {
        'directory' => mkdir($root.'/shared/.env', 0o755, true),
        'dangling symlink' => symlink($root.'/shared/.env.that-was-never-created', $root.'/shared/.env'),
    };

    return [$env, $root];
}

/*
|--------------------------------------------------------------------------
| install-target-perimeter renderers
|--------------------------------------------------------------------------
|
| The sudoers rule and the backup cron are both rendered from the registry
| by install-target-perimeter, and both are tested the same way: the
| shipped renderer, sourced, driven by a registry (and, for the cron, a
| schedule file) the test supplies. Running the real implementation is the
| point — a reimplementation here would prove only that two copies agree.
*/

function perimeterRegistry(): array
{
    return json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true, 512, JSON_THROW_ON_ERROR);
}

function perimeterBackupSchedules(): array
{
    return json_decode(File::get(base_path('infrastructure/config/backup-schedules.json')), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * Source install-target-perimeter with its registry and schedule file
 * pointed at the given fixtures, then run $call. Output is stdout and stderr
 * together, so a refusal's message is in it.
 *
 * @return array{0: int, 1: string}
 */
function perimeterRun(string $call, array $registry, ?array $schedules = null, string $prelude = ''): array
{
    $scratch = sys_get_temp_dir().'/perimeter-render-'.uniqid('', true);
    @mkdir($scratch, 0o755, true);

    $encode = fn (array $data): string => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    file_put_contents($scratch.'/registry.json', $encode($registry));
    file_put_contents($scratch.'/backup-schedules.json', $encode($schedules ?? perimeterBackupSchedules()));

    $harness = $scratch.'/render.sh';
    file_put_contents($harness, implode("\n", [
        'set -Eeuo pipefail',
        'source '.escapeshellarg(base_path('infrastructure/scripts/install-target-perimeter')),
        'SRC_REGISTRY='.escapeshellarg($scratch.'/registry.json'),
        'SRC_BACKUP_SCHEDULES='.escapeshellarg($scratch.'/backup-schedules.json'),
        $prelude,
        $call,
        '',
    ]));

    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(['bash', $harness], $descriptors, $pipes, $scratch, [
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'HOME' => getenv('HOME') ?: '/tmp',
    ]);

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $exit = proc_close($process);

    exec('rm -rf '.escapeshellarg($scratch));

    return [$exit, $output];
}

function perimeterRender(array $registry, string $renderer = 'render_sudoers_candidate', ?array $schedules = null): string
{
    return perimeterRun($renderer, $registry, $schedules)[1];
}

/**
 * The operational (runnable) lines of a cron file: everything that is not
 * blank, a comment, or an environment assignment.
 *
 * @return list<string>
 */
function cronOperationalLines(string $cron): array
{
    return array_values(array_filter(
        preg_split('/\R/', $cron),
        fn (string $line): bool => ! preg_match('/^\s*(#|$)|^[A-Za-z_][A-Za-z0-9_]*=/', $line),
    ));
}

/*
|--------------------------------------------------------------------------
| The deploy script, as its tests drive it
|--------------------------------------------------------------------------
|
| DeployTest proves the deployment pipeline; DeployRecoveryFailureTest proves
| what its recovery does when recovery itself goes wrong. Both build the same
| scratch target, artifact and parity registry, stub the same host tools and
| run the shipped script the same way, so the harness lives here once. See
| DeployTest's file comment for how the real script is driven without root.
*/

function deployOpsScript(): string
{
    return base_path('infrastructure/scripts/deploy');
}

function deployOpsCommonFile(): string
{
    return base_path('infrastructure/scripts/common');
}

function deployOpsTargetsCli(): string
{
    return base_path('infrastructure/scripts/targets');
}

function deployOpsRegistryPath(): string
{
    return base_path('infrastructure/config/deployment-targets.json');
}

function deployOpsDeploymentConfPath(): string
{
    return base_path('infrastructure/templates/deployment.conf.example');
}

function deployOpsDeploymentProtocolPath(): string
{
    return base_path('infrastructure/config/deployment-protocol.json');
}

/**
 * What the committed contract says this tooling supports. Read, never pinned to
 * a literal: these tests assert the handshake's behaviour, and raising the
 * protocol is a deliberate separate decision that must not be able to break
 * them by surprise.
 */
function deployOpsSupportedProtocol(): int
{
    return (int) data_get(
        json_decode(File::get(deployOpsDeploymentProtocolPath()), true, 512, JSON_THROW_ON_ERROR),
        'tooling.supported',
    );
}

function deployOpsScratchDir(): string
{
    return makeScratchDir('deploy-ops', ['', '/bin']);
}

function deployOpsCleanup(string $dir): void
{
    removeScratchDir($dir);
}

/**
 * Run a bash script as a real subprocess with an explicit environment (never
 * inherited shell exports). fd 2 is redirected onto fd 1 at the descriptor
 * level so there is only one stream to drain.
 *
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function deployOpsExec(string $scriptPath, array $env): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(['bash', $scriptPath], $descriptors, $pipes, null, $env);

    expect($process)->not->toBeFalse('could not start harness process');

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $exit = proc_close($process);

    return [$exit, $output];
}

/**
 * Sources the real deploy script (BASH_SOURCE[0] != $0 here, so main() never
 * auto-runs — see the file-level docblock above) then runs $body, which can
 * call parse_deploy_args/resolve_target/perform_deploy/main directly.
 *
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function deployOpsRunHarness(string $scratch, string $body, array $env = []): array
{
    $script = "set -Eeuo pipefail\n".'source '.escapeshellarg(deployOpsScript())."\n".$body."\n";
    $harnessPath = $scratch.'/harness.sh';
    file_put_contents($harnessPath, $script);

    $defaultEnv = [
        'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'HOME' => getenv('HOME') ?: '/tmp',
    ];

    return deployOpsExec($harnessPath, array_merge($defaultEnv, $env));
}

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function deployOpsBaseEnv(string $scratch, array $overrides = []): array
{
    return array_merge([
        'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'HOME' => getenv('HOME') ?: '/tmp',
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_COMMON_FILE' => deployOpsCommonFile(),
        'RATEGURU_DEPLOYMENT_CONF_FILE' => deployOpsDeploymentConfPath(),
        'RATEGURU_TARGET_REGISTRY_FILE' => deployOpsRegistryPath(),
        'RATEGURU_TARGETS_CLI' => deployOpsTargetsCli(),
        // The trusted protocol contract an installed bundle would carry. The
        // committed file itself, never a fixture copy: deploy refuses to run
        // without it, and a hand-written stand-in could drift from the contract
        // the installer actually installs.
        'RATEGURU_DEPLOYMENT_PROTOCOL_FILE' => deployOpsDeploymentProtocolPath(),
        // The supervisor-activation wait tuning, shrunk so an
        // activation that will never reach RUNNING fails immediately
        // instead of sleeping through the production retry budget.
        'RATEGURU_DEPLOY_QUEUE_WAIT_ATTEMPTS' => '1',
        'RATEGURU_DEPLOY_QUEUE_RETRY_DELAY' => '0',
    ], $overrides);
}

/**
 * A stub matching health-check's real --target CLI shape closely enough for
 * deploy's own purposes: logs its argv, exits 0 (or 1 to simulate a failed
 * post-switch health check).
 */
function deployOpsHealthCheckStub(string $scratch, string $logFile, bool $fail = false): string
{
    $path = $scratch.'/bin/health-check-stub-'.uniqid('', true);
    $exitCode = $fail ? 1 : 0;
    file_put_contents($path, "#!/usr/bin/env bash\n"
        .'echo "$*" >> '.escapeshellarg($logFile)."\n"
        ."exit {$exitCode}\n");
    chmod($path, 0o755);

    return $path;
}

function deployOpsVerifyRequiredClisStub(string $scratch, string $logFile): string
{
    $path = $scratch.'/bin/verify-required-clis-stub';
    file_put_contents($path, "#!/usr/bin/env bash\n"
        .'echo "$*" >> '.escapeshellarg($logFile)."\n"
        ."exit 0\n");
    chmod($path, 0o755);

    return $path;
}

/**
 * PATH-shadowed stubs for systemctl, runuser and supervisorctl — real
 * fixtures use numeric uid/gid strings as RUNTIME_USER, which real runuser
 * cannot resolve to a real account and does not need to: this stub drops
 * "-u VALUE --" and execs the remaining command directly as the current
 * (test) user.
 *
 * supervisorctl is stateful, mirroring the technique
 * InstallBootstrapServicesTest already established: `status` answers from a
 * queue-running state file, `update`/`start` create it (unless the
 * activation-fail toggle simulates a worker that never reaches RUNNING),
 * `stop` removes it, and every invocation is logged so tests can assert
 * exactly which supervisor commands a deployment ran — or that it ran none.
 * The reread-fail, update-fail and stop-fail toggles make that one command
 * itself fail, the way supervisorctl does when supervisord rejects it.
 */
function deployOpsInstallCoreStubs(string $scratch): void
{
    file_put_contents($scratch.'/bin/systemctl', "#!/usr/bin/env bash\n"
        .'echo "systemctl $*" >> '.escapeshellarg($scratch.'/systemctl.log')."\n"
        ."exit 0\n");
    chmod($scratch.'/bin/systemctl', 0o755);

    file_put_contents($scratch.'/bin/runuser', "#!/usr/bin/env bash\n"
        .'echo "runuser $*" >> '.escapeshellarg($scratch.'/runuser.log')."\n"
        .'shift 2; shift'."\n"
        .'exec "$@"'."\n");
    chmod($scratch.'/bin/runuser', 0o755);

    $stateDir = $scratch.'/supervisor-state';

    if (! is_dir($stateDir)) {
        expect(@mkdir($stateDir, 0o755, true))->toBeTrue("could not create supervisor state directory: {$stateDir}");
    }

    file_put_contents($scratch.'/bin/supervisorctl', "#!/usr/bin/env bash\n"
        .'echo "supervisorctl $*" >> '.escapeshellarg($scratch.'/supervisorctl.log')."\n"
        .'state='.escapeshellarg($stateDir)."\n"
        .<<<'STUB'
        case "${1:-}" in
            status)
                # Group-aware on purpose: answering for any requested group
                # would let deploy query the wrong program (e.g. a
                # hard-coded name) and still look healthy.
                if [[ "${2:-}" != "parity-queue:*" ]]; then
                    echo "${2:-}: ERROR (no such process)"
                    exit 1
                fi
                # status-sequence models real Supervisor state transitions:
                # one line consumed per status call, the final line sticky.
                # This is what reproduces the STARTING race that a single
                # immediate check would fail on.
                if [[ -s "${state}/status-sequence" ]]; then
                    next="$(head -n 1 "${state}/status-sequence")"
                    if (( $(wc -l < "${state}/status-sequence") > 1 )); then
                        tail -n +2 "${state}/status-sequence" > "${state}/seq.tmp"
                        mv "${state}/seq.tmp" "${state}/status-sequence"
                    fi
                    # Exit 0 even for non-RUNNING states, so the caller's own
                    # state classification is what decides — not our exit code.
                    echo "parity-queue:parity-queue_00   ${next}   pid 321, uptime 0:00:01"
                    exit 0
                fi
                if [[ -e "${state}/queue-running" ]]; then
                    # drop-after-status simulates a worker that is RUNNING when
                    # the pre-deploy snapshot reads it and gone by the time the
                    # transition re-checks it.
                    [[ -e "${state}/drop-after-status" ]] && rm -f "${state}/queue-running"
                    echo "parity-queue:parity-queue_00   RUNNING   pid 123, uptime 0:05:00"
                    exit 0
                fi
                echo "parity-queue:*: ERROR (no such process)"
                exit 1
                ;;
            reread)
                if [[ -e "${state}/reread-fail" ]]; then
                    echo "error: <class 'xmlrpc.client.Fault'>, <Fault 92: 'CANT_REREAD'>"
                    exit 2
                fi
                echo "parity-queue: available"
                exit 0
                ;;
            update|start)
                if [[ "${1}" == update ]] && [[ -e "${state}/update-fail" ]]; then
                    echo "ERROR: parity-queue: could not be added"
                    exit 2
                fi
                if [[ ! -e "${state}/activation-fail" ]]; then
                    touch "${state}/queue-running"
                fi
                exit 0
                ;;
            stop)
                if [[ -e "${state}/stop-fail" ]]; then
                    echo "parity-queue:parity-queue_00: ERROR (abnormal termination)"
                    exit 1
                fi
                rm -f "${state}/queue-running"
                exit 0
                ;;
        esac
        exit 0
        STUB."\n");
    chmod($scratch.'/bin/supervisorctl', 0o755);
}

/**
 * The supervisorctl invocations a run performed (empty when the stub was
 * never reached).
 *
 * @return list<string>
 */
function deployOpsSupervisorctlLog(string $scratch): array
{
    $path = $scratch.'/supervisorctl.log';

    if (! is_file($path)) {
        return [];
    }

    return array_values(array_filter(explode("\n", trim((string) file_get_contents($path)))));
}

/**
 * A php stub whose `artisan queue:restart` fails while every other artisan
 * command succeeds — the "restart signal cannot be written" case.
 */
function deployOpsFailingQueueRestartPhpBin(string $scratch): string
{
    $path = $scratch.'/bin/fake-php-queue-fail';
    file_put_contents($path, "#!/usr/bin/env bash\n"
        .'echo "php $*" >> '.escapeshellarg($scratch.'/artisan.log')."\n"
        ."for arg in \"\$@\"; do\n"
        ."    if [[ \"\${arg}\" == 'queue:restart' ]]; then exit 1; fi\n"
        ."done\n"
        ."exit 0\n");
    chmod($path, 0o755);

    return $path;
}

/**
 * A minimal release directory containing artisan, for exercising the queue
 * transition directly without running deploy's full Laravel preparation
 * (whose `install -g www-data` needs a membership CI does not have).
 */
function deployOpsReleaseDirWithArtisan(string $scratch): string
{
    $dir = $scratch.'/release-'.uniqid('', true);
    expect(@mkdir($dir, 0o755, true))->toBeTrue("could not create release directory: {$dir}");
    file_put_contents($dir.'/artisan', "#!/usr/bin/env php\n<?php // fixture artisan\n");

    return $dir;
}

/**
 * A scratch target root + a separate incoming-artifacts directory + a real
 * .tar.gz built with real tar/sha256sum (portable, no stubbing needed). Pass
 * $laravel=true to additionally include artisan and the required-CLI
 * manifest verify-required-clis (stubbed elsewhere) would otherwise expect.
 *
 * $release controls the ARTIFACT-OWNED environment contract inside the tarball,
 * which is what an ordinary deploy now judges the host's .env against:
 *
 *   'contract' => false      the artifact carries no target registry at all,
 *                            which is what an artifact built before the feature
 *                            looks like (the default, and what every older test
 *                            in this file relies on)
 *   'contract' => 'declared' the artifact declares and ships a template
 *   'keys'                   extra keys that template requires on top of the
 *                            committed staging set
 *   'declared'               override the declared path, to build the unsafe
 *                            declarations the resolver must refuse
 *   'ship'                   false to declare a template and not ship it
 *   'registry'               raw registry contents, for a malformed one
 *
 * $release also controls the artifact's release.json, which is where the
 * deployment protocol handshake reads the minimum protocol the release requires:
 *
 *   (absent)                 no release.json at all — a legacy artifact, which
 *                            is what every older test in this file builds
 *   'protocol' => 1          release.json declaring deployment_protocol_min: 1
 *   'protocol' => 'omitted'  a release.json with no deployment_protocol_min, the
 *                            other legacy shape
 *   'protocol' => '"1"'      raw JSON for the field, to build the non-integer
 *                            declarations the gate must refuse
 *   'release_json'           raw release.json contents, for a malformed one
 *   'artifact_contract'      a deployment-protocol.json to put INSIDE the
 *                            artifact, which deploy must never treat as
 *                            authority over what the host supports
 *
 * @return array{root: string, incoming: string, artifact: string, checksum: string}
 */
function deployOpsBuildFixture(string $scratch, bool $laravel = false, array $release = []): array
{
    $id = uniqid('', true);
    $root = $scratch.'/target-'.$id;
    $incoming = $scratch.'/incoming-'.$id;

    foreach ([
        $root.'/releases',
        $root.'/deployments',
        $root.'/locks',
        $root.'/shared/storage',
        $incoming,
    ] as $dir) {
        expect(@mkdir($dir, 0o755, true))->toBeTrue("could not create fixture directory: {$dir}");
    }
    file_put_contents($root.'/shared/.env', contractSatisfyingEnvironment());

    $artifactSrc = $scratch.'/artifact-src-'.$id;
    mkdir($artifactSrc.'/public', 0o755, true);
    file_put_contents($artifactSrc.'/public/index.php', "<?php // fixture\n");

    $tarEntries = 'public';

    if ($laravel) {
        file_put_contents($artifactSrc.'/artisan', "#!/usr/bin/env php\n<?php // fixture artisan\n");
        mkdir($artifactSrc.'/infrastructure/config', 0o755, true);
        mkdir($artifactSrc.'/infrastructure/scripts', 0o755, true);
        file_put_contents($artifactSrc.'/infrastructure/config/required-clis.txt', "targets\n");
        file_put_contents($artifactSrc.'/infrastructure/scripts/targets', "#!/usr/bin/env bash\nexit 0\n");
        chmod($artifactSrc.'/infrastructure/scripts/targets', 0o755);
        file_put_contents($artifactSrc.'/infrastructure/scripts/common', "#!/usr/bin/env bash\n");
        chmod($artifactSrc.'/infrastructure/scripts/common', 0o644);
        $tarEntries = 'public artisan infrastructure';
    }

    // The artifact-owned environment contract. Absent by default: an artifact
    // that declares nothing is exactly what a pre-feature one is, and deploy
    // must keep deploying it.
    if (($release['contract'] ?? false) !== false) {
        @mkdir($artifactSrc.'/infrastructure/config', 0o755, true);

        $declared = $release['declared'] ?? 'infrastructure/templates/environment/staging.env.example';

        file_put_contents(
            $artifactSrc.'/infrastructure/config/deployment-targets.json',
            $release['registry'] ?? json_encode(
                ['targets' => ['parity-target' => ['environment_template' => $declared]]],
                JSON_PRETTY_PRINT,
            ),
        );

        if (($release['ship'] ?? true) === true) {
            $shipAt = $artifactSrc.'/'.$declared;

            @mkdir(dirname($shipAt), 0o755, true);

            // Built FROM the committed template, so the fixture cannot drift
            // from the real key set.
            $template = File::get(base_path('infrastructure/templates/environment/staging.env.example'));

            foreach ($release['keys'] ?? [] as $key) {
                $template .= $key."=\n";
            }

            file_put_contents($shipAt, $template);
        }

        $tarEntries = $laravel ? 'public artisan infrastructure' : 'public infrastructure';
    }

    // The artifact's own release.json, PRESENT by default — every artifact the
    // build has ever produced carries one, and `deploy` refuses an artifact
    // without one both at the protocol gate and, for a controlled alignment, at
    // the identity check. A fixture without it would be a shape that does not
    // exist. 'release_json' => false builds that shape deliberately, for the
    // tests that prove the refusal.
    if (($release['release_json'] ?? null) !== false) {
        $metadata = is_string($release['release_json'] ?? null)
            ? $release['release_json']
            : null;

        if ($metadata === null) {
            $fields = ['"project": "rateguru"', '"release": "v0.0.0-20260101-000000-abc0000"'];

            // 'omitted' is the legacy shape: an object with no declaration. The
            // default carries the protocol this tooling supports, which is what
            // a freshly built artifact declares.
            $protocol = $release['protocol'] ?? deployOpsSupportedProtocol();

            if ($protocol !== 'omitted') {
                $fields[] = '"deployment_protocol_min": '.$protocol;
            }

            $metadata = '{'.implode(', ', $fields).'}';
        }

        file_put_contents($artifactSrc.'/release.json', $metadata);
        $tarEntries .= ' release.json';
    }

    // A protocol contract carried inside the artifact. deploy must ignore it
    // entirely: an artifact may state what it requires, never what the host
    // supports.
    if (isset($release['artifact_contract'])) {
        @mkdir($artifactSrc.'/infrastructure/config', 0o755, true);
        file_put_contents(
            $artifactSrc.'/infrastructure/config/deployment-protocol.json',
            $release['artifact_contract'],
        );

        if (! str_contains($tarEntries, 'infrastructure')) {
            $tarEntries .= ' infrastructure';
        }
    }

    $artifact = $incoming.'/release.tar.gz';
    exec('tar -C '.escapeshellarg($artifactSrc)." -czf {$artifact} {$tarEntries} 2>&1", $tarOutput, $tarExit);
    expect($tarExit)->toBe(0, "failed to build fixture artifact:\n".implode("\n", $tarOutput));

    exec('cd '.escapeshellarg($incoming).' && sha256sum '.escapeshellarg(basename($artifact)).' > '.escapeshellarg(basename($artifact).'.sha256').' 2>&1', $shaOutput, $shaExit);
    expect($shaExit)->toBe(0, "failed to build fixture checksum:\n".implode("\n", $shaOutput));

    return ['root' => $root, 'incoming' => $incoming, 'artifact' => $artifact, 'checksum' => $artifact.'.sha256'];
}

/**
 * A scratch, writable copy of the real committed deployment.conf.example,
 * verbatim. Formerly rewrote STAGING_ROOT/STAGING_RUNTIME_USER/
 * STAGING_CODE_GROUP/STAGING_DEPLOY_USER/STAGING_INCOMING_ARTIFACTS to point
 * at each fixture's own paths — but the template no longer carries any
 * target-specific field at all (see
 * infrastructure/templates/deployment.conf.example's own header comment):
 * every one of those values now comes exclusively from the target registry,
 * via deployOpsParityRegistry(), which already derives them from the
 * fixture's own root/incoming plus the current account/group. Still returns
 * a fresh scratch copy (rather than the template path itself) so callers
 * that mutate it afterward — e.g. the Laravel-prep test's own PHP_BIN
 * override — never touch the repository's own source file.
 */
function deployOpsDeploymentConfForFixture(string $scratch): string
{
    $path = $scratch.'/deployment-'.uniqid('', true).'.conf';
    file_put_contents($path, File::get(deployOpsDeploymentConfPath()));

    return $path;
}

/**
 * A registry + patched `targets` validator declaring a single, fully valid
 * `parity-target` with lifecycle=active pointing at the fixture's own
 * application root — the same technique CleanupTest.php established.
 * runtime_user/deploy_user/
 * runtime_group/code_group are the current test process's own account/
 * primary group name (deployOpsCurrentAccount()/deployOpsCurrentGroup()) —
 * the registry is the only source of these values in target mode now;
 * deployment.conf no longer carries any target-specific field at all (see
 * deployOpsDeploymentConfForFixture()).
 *
 * @return array{0: string, 1: string} [registryPath, targetsCliPath]
 */
function deployOpsParityRegistry(string $scratch, array $fixture): array
{
    $account = deployOpsCurrentAccount();
    $group = deployOpsCurrentGroup();

    // Four constraints the committed `targets` validator enforces as
    // production safety rails, relaxed only in this throwaway, test-only
    // copy — the same technique CleanupTest.php already established for
    // application_root/ACTIVE_ALLOWLIST: (1) application_root and
    // (2) incoming_artifacts must otherwise live under /home/www/rateguru
    // and /home respectively, which a scratch fixture can't satisfy;
    // (3) code_group must otherwise differ from runtime_group, and
    // (4) code_group must otherwise differ from the runtime user's own
    // name — both real registry-modeling rules this fixture doesn't need to
    // honor. It just needs one group name the test process can chown to
    // without root, and reusing the test's own account/primary-group for
    // both runtime_user and code_group is fine here (that modeling rule is
    // already covered elsewhere, e.g. DeploymentTargetRegistryTest.php).
    // Constraint (4) only surfaces where a host's own account/primary-group
    // pair happen to share a name — e.g. GitHub Actions' `runner` user,
    // whose primary group is also named `runner` — so this was missed
    // locally (this machine's account and primary group differ) until CI
    // caught it.
    $patchedTargets = str_replace(
        'ACTIVE_ALLOWLIST="staging-main"',
        'ACTIVE_ALLOWLIST="parity-target"',
        File::get(deployOpsTargetsCli()),
    );
    $patchedTargets = str_replace(
        'elif [[ "${application_root}" != /home/www/rateguru/* ]]; then',
        'elif false; then',
        $patchedTargets,
    );
    $patchedTargets = str_replace(
        'elif [[ "${incoming}" != /home/* ]]; then',
        'elif false; then',
        $patchedTargets,
    );
    $patchedTargets = str_replace(
        'if [[ "${code_group}" == "${runtime_group}" ]]; then',
        'if false; then',
        $patchedTargets,
    );
    $patchedTargets = str_replace(
        'if [[ "${code_group}" == "${runtime_user}" ]]; then',
        'if false; then',
        $patchedTargets,
    );

    $targetsPath = $scratch.'/parity-targets';
    file_put_contents($targetsPath, $patchedTargets);
    chmod($targetsPath, 0o755);

    $registry = [
        'schema_version' => 1,
        'targets' => [
            'parity-target' => [
                'id' => 'parity-target',
                'lifecycle' => 'active',
                'environment_class' => 'staging',
                'application_root' => $fixture['root'],
                'runtime_user' => $account,
                'runtime_group' => $group,
                'deploy_user' => $account,
                'code_group' => $group,
                'incoming_artifacts' => $fixture['incoming'],
                'release_retention' => 5,
                'database' => ['name' => 'parity_db', 'application_role' => 'parity_app'],
                'health' => ['url' => 'http://127.0.0.1/', 'host_header' => 'parity.internal'],
                'public_hostnames' => ['parity.example', 'parity-secondary.example'],
                'backup' => ['namespace' => 'parity', 'local_retention_days' => 1, 'offsite_retention_days' => 1, 'minimum_retained_backups' => 2],
                'php_fpm' => ['pool' => 'parity', 'socket' => '/run/php/parity.sock'],
                'supervisor' => ['program' => 'parity-queue', 'queue' => 'parity'],
                'scheduler' => ['name' => 'parity-scheduler'],
                'nginx' => ['site_name' => 'parity', 'internal_hostname' => 'parity.internal'],
                'environment_template' => 'infrastructure/templates/environment/staging.env.example',
            ],
        ],
    ];

    $registryPath = $scratch.'/parity-registry.json';
    file_put_contents($registryPath, json_encode($registry, JSON_PRETTY_PRINT));

    exec(escapeshellarg($targetsPath).' validate --file '.escapeshellarg($registryPath).' 2>&1', $validateOutput, $validateExit);
    expect($validateExit)->toBe(0, "parity-target fixture failed validation:\n".implode("\n", $validateOutput));

    return [$registryPath, $targetsPath];
}

/**
 * The current test process's real username/primary group name — via `id`,
 * not PHP's posix_* extension (not guaranteed enabled everywhere). Used as
 * RUNTIME_USER/DEPLOY_ACCOUNT/CODE_GROUP so chown/install succeed without
 * root, and because the target registry validator requires account-name-
 * shaped strings (a raw numeric uid fails is_safe_account_name).
 */
function deployOpsCurrentAccount(): string
{
    exec('id -un', $output);

    return trim($output[0] ?? '');
}

function deployOpsCurrentGroup(): string
{
    exec('id -gn', $output);

    return trim($output[0] ?? '');
}

/**
 * Runs one full, real deployment (extraction, symlinks, ownership/mode
 * normalization, verify-required-clis, atomic switch, health-check, history)
 * against a fresh fixture, via --target parity-target. $artifact, when given,
 * rewrites the fixture's artifact and its checksum before deploy sees them.
 *
 * @param  (callable(array{root: string, incoming: string, artifact: string, checksum: string}): void)|null  $artifact
 * @return array{exit: int, output: string, fixture: array, healthCheckLog: string, verifyCliLog: string, releaseId: string}
 */
function deployOpsRunFullDeployment(string $scratch, ?bool $failHealthCheck = false, ?callable $artifact = null): array
{
    $fixture = deployOpsBuildFixture($scratch);

    if ($artifact !== null) {
        $artifact($fixture);
    }

    $confPath = deployOpsDeploymentConfForFixture($scratch);
    deployOpsInstallCoreStubs($scratch);
    [$registryPath, $targetsPath] = deployOpsParityRegistry($scratch, $fixture);

    $healthCheckLog = $scratch.'/health-check-'.uniqid('', true).'.log';
    touch($healthCheckLog);
    $healthCheckStub = deployOpsHealthCheckStub($scratch, $healthCheckLog, $failHealthCheck ?? false);

    $verifyCliLog = $scratch.'/verify-cli-'.uniqid('', true).'.log';
    touch($verifyCliLog);
    $verifyCliStub = deployOpsVerifyRequiredClisStub($scratch, $verifyCliLog);

    $releaseId = 'v1.0.0-2026010'.random_int(1, 9).'-000000-abc'.random_int(1000, 9999);

    $env = deployOpsBaseEnv($scratch, [
        'RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath,
        'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
        'RATEGURU_TARGETS_CLI' => $targetsPath,
        'RATEGURU_HEALTH_CHECK_BIN' => $healthCheckStub,
        'RATEGURU_VERIFY_REQUIRED_CLIS_BIN' => $verifyCliStub,
    ]);

    [$exit, $output] = deployOpsRunHarness(
        $scratch,
        "parse_deploy_args --target parity-target --release {$releaseId} --artifact {$fixture['artifact']}\nresolve_target\nperform_deploy",
        $env,
    );

    return [
        'exit' => $exit,
        'output' => $output,
        'fixture' => $fixture,
        'healthCheckLog' => $healthCheckLog,
        'verifyCliLog' => $verifyCliLog,
        'releaseId' => $releaseId,
    ];
}

/** @return list<array<string, mixed>> */
function deployOpsHistory(string $root): array
{
    $raw = trim((string) @file_get_contents($root.'/deployments/history.jsonl'));

    if ($raw === '') {
        return [];
    }

    return array_map(
        fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
        array_values(array_filter(explode("\n", $raw))),
    );
}

/*
|--------------------------------------------------------------------------
| install-bootstrap-services against a simulated host
|--------------------------------------------------------------------------
|
| InstallBootstrapServicesTest proves the service installer's contract;
| InstallBootstrapServicesRollbackTest proves what a failed apply puts back.
| Both run the shipped script against the same fixture filesystem root and the
| same stateful stubs, described in InstallBootstrapServicesTest's file
| comment, so the host is built here once.
*/

function bsvcScript(): string
{
    return base_path('infrastructure/scripts/install-bootstrap-services');
}

function bsvcScratchDir(): string
{
    return makeScratchDir('bootstrap-services', ['', '/bin', '/fs', '/log', '/svc', '/toggles']);
}

function bsvcCleanup(string $dir): void
{
    removeScratchDir($dir);
}

/**
 * @param  list<string>  $arguments
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function bsvcRun(array $arguments, array $env): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(
        array_merge(['bash', bsvcScript()], $arguments),
        $descriptors,
        $pipes,
        null,
        $env,
    );

    expect($process)->not->toBeFalse('could not start install-bootstrap-services subprocess');

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $exit = proc_close($process);

    return [$exit, $output];
}

function bsvcWriteStub(string $path, string $content): void
{
    file_put_contents($path, $content);
    chmod($path, 0o755);
}

function bsvcLog(string $scratch, string $name): string
{
    $path = $scratch.'/log/'.$name;

    return is_file($path) ? (string) file_get_contents($path) : '';
}

function bsvcWriteStubs(string $scratch): void
{
    // stat: layered — type from the real scratch filesystem (with a
    // type-table override so a plain fixture file can present as a socket),
    // owner/group from the fixture ownership table, mode real.
    bsvcWriteStub($scratch.'/bin/stat', <<<'STUB'
        #!/bin/bash
        path="${!#}"
        if [[ -L "${path}" ]]; then ftype="symbolic link"
        elif [[ -d "${path}" ]]; then ftype="directory"
        elif [[ -S "${path}" ]]; then ftype="socket"
        elif [[ -f "${path}" ]]; then
            ftype="regular file"
            row_t="$(PATH="${STUB_REAL_PATH}" awk -F'|' -v p="${path}" '$1 == p && $2 == "TYPE" { print $3; exit }' "${STUB_TYPE_TABLE}" 2>/dev/null)"
            [[ -n "${row_t}" ]] && ftype="${row_t}"
        elif [[ -e "${path}" ]]; then ftype="other"
        else exit 1; fi
        mode="$(PATH="${STUB_REAL_PATH}" stat -c '%a' -- "${path}" 2>/dev/null)" \
            || mode="$(PATH="${STUB_REAL_PATH}" stat -f '%Mp%Lp' "${path}" 2>/dev/null)" || exit 1
        mode="$(printf '%o' $(( 8#${mode} )))"
        row="$(PATH="${STUB_REAL_PATH}" awk -F'|' -v p="${path}" '$1 == p && $2 != "TYPE" { print $2 "|" $3; found = 1; exit } END { exit !found }' "${STUB_OWNER_TABLE}" 2>/dev/null)" || row=""
        if [[ -z "${row}" ]]; then
            row="$(PATH="${STUB_REAL_PATH}" stat -c '%U|%G' -- "${path}" 2>/dev/null)" \
                || row="$(PATH="${STUB_REAL_PATH}" stat -f '%Su|%Sg' "${path}" 2>/dev/null)" || exit 1
        fi
        printf '%s|%s|%s\n' "${ftype}" "${row}" "${mode}"
        STUB);

    // chown: records the invocation and upserts the ownership row for the
    // exact path given — never recursive.
    bsvcWriteStub($scratch.'/bin/chown', <<<'STUB'
        #!/bin/bash
        printf 'chown %s\n' "$*" >> "${STUB_LOG}/chown.log"
        owner_group=""; path=""
        for arg in "$@"; do
            case "${arg}" in
                --) ;;
                -*) ;;
                *) if [[ -z "${owner_group}" ]]; then owner_group="${arg}"; else path="${arg}"; fi ;;
            esac
        done
        owner="${owner_group%%:*}"; group="${owner_group##*:}"
        tmp="${STUB_OWNER_TABLE}.tmp"
        PATH="${STUB_REAL_PATH}" awk -F'|' -v p="${path}" '$1 != p' "${STUB_OWNER_TABLE}" > "${tmp}" 2>/dev/null || : > "${tmp}"
        printf '%s|%s|%s\n' "${path}" "${owner}" "${group}" >> "${tmp}"
        PATH="${STUB_REAL_PATH}" mv "${tmp}" "${STUB_OWNER_TABLE}"
        exit 0
        STUB);

    bsvcWriteStub($scratch.'/bin/chmod', <<<'STUB'
        #!/bin/bash
        printf 'chmod %s\n' "$*" >> "${STUB_LOG}/chmod.log"
        PATH="${STUB_REAL_PATH}" chmod "$@"
        STUB);

    bsvcWriteStub($scratch.'/bin/install', <<<'STUB'
        #!/bin/bash
        printf 'install %s\n' "$*" >> "${STUB_LOG}/install.log"
        PATH="${STUB_REAL_PATH}" install "$@"
        STUB);

    // systemctl: stateful — enabled/active per unit as files under the
    // service-state directory; every invocation is logged.
    bsvcWriteStub($scratch.'/bin/systemctl', <<<'STUB'
        #!/bin/bash
        printf 'systemctl %s\n' "$*" >> "${STUB_LOG}/systemctl.log"
        # A real reload replaces workers, so the replacements are created
        # with whatever supplementary groups the account now has. Unless the
        # reload-keeps-workers-stale toggle models a host where that failed.
        respawn_nginx_workers() {
            [[ -f "${STUB_FS}/nginx-fresh-worker-gids.txt" ]] || return 0
            if [[ -e "${STUB_TOGGLES}/nginx-reload-keeps-stale-workers" ]]; then return 0; fi
            gids="$(PATH="${STUB_REAL_PATH}" cat "${STUB_FS}/nginx-fresh-worker-gids.txt")"
            PATH="${STUB_REAL_PATH}" rm -rf "${STUB_FS}/proc"
            : > "${STUB_FS}/nginx-worker-pids.txt"
            for pid in 9001 9002; do
                PATH="${STUB_REAL_PATH}" mkdir -p "${STUB_FS}/proc/${pid}"
                printf 'Name:\tnginx\nGroups:\t%s \n' "${gids}" > "${STUB_FS}/proc/${pid}/status"
                printf '%s\n' "${pid}" >> "${STUB_FS}/nginx-worker-pids.txt"
            done
        }
        cmd=""; unit=""
        for arg in "$@"; do
            case "${arg}" in
                --quiet) ;;
                *) if [[ -z "${cmd}" ]]; then cmd="${arg}"; else unit="${arg}"; fi ;;
            esac
        done
        unit="${unit%.service}"
        case "${cmd}" in
            is-enabled) [[ -e "${STUB_SVC_STATE}/${unit}.enabled" ]] ;;
            is-active)  [[ -e "${STUB_SVC_STATE}/${unit}.active" ]] ;;
            enable)  touch "${STUB_SVC_STATE}/${unit}.enabled" ;;
            disable) rm -f "${STUB_SVC_STATE}/${unit}.enabled" ;;
            start)
                [[ -e "${STUB_TOGGLES}/${unit}-start-fail" ]] && exit 1
                touch "${STUB_SVC_STATE}/${unit}.active"
                if [[ "${unit}" == nginx ]]; then respawn_nginx_workers; fi
                ;;
            stop)    rm -f "${STUB_SVC_STATE}/${unit}.active" ;;
            reload|restart)
                [[ -e "${STUB_SVC_STATE}/${unit}.active" ]] || exit 1
                if [[ "${unit}" == nginx ]]; then respawn_nginx_workers; fi
                ;;
            *) exit 0 ;;
        esac
        STUB);

    // pgrep: the PIDs of the simulated running www-data nginx workers.
    bsvcWriteStub($scratch.'/bin/pgrep', <<<'STUB'
        #!/bin/bash
        printf 'pgrep %s\n' "$*" >> "${STUB_LOG}/pgrep.log"
        [[ -s "${STUB_FS}/nginx-worker-pids.txt" ]] || exit 1
        PATH="${STUB_REAL_PATH}" cat "${STUB_FS}/nginx-worker-pids.txt"
        STUB);

    // nginx / sshd / php-fpm: config tests whose verdict a toggle controls.
    foreach (['nginx' => 'nginx', 'sshd' => 'sshd', 'php-fpm8.5' => 'php-fpm'] as $bin => $log) {
        bsvcWriteStub($scratch.'/bin/'.$bin, <<<STUB
            #!/bin/bash
            printf '{$log} %s\\n' "\$*" >> "\${STUB_LOG}/{$log}.log"
            [[ -e "\${STUB_TOGGLES}/{$log}-t-fail" ]] && exit 1
            exit 0
            STUB);
    }

    // supervisorctl: reread validates (toggle-driven), status answers from
    // the queue-running toggle, update/start flip it on.
    bsvcWriteStub($scratch.'/bin/supervisorctl', <<<'STUB'
        #!/bin/bash
        printf 'supervisorctl %s\n' "$*" >> "${STUB_LOG}/supervisorctl.log"
        case "${1:-}" in
            reread)
                [[ -e "${STUB_TOGGLES}/supervisor-reread-fail" ]] && { echo "ERROR: CANT_REREAD bad config"; exit 0; }
                echo "No config updates to processes"
                ;;
            status)
                if [[ -e "${STUB_TOGGLES}/queue-running" ]]; then
                    echo "rateguru-staging-queue:rateguru-staging-queue_00   RUNNING   pid 123, uptime 0:05:00"
                else
                    echo "rateguru-staging-queue:*: ERROR (no such process)"
                    exit 1
                fi
                ;;
            update|start)
                touch "${STUB_TOGGLES}/queue-running"
                ;;
        esac
        exit 0
        STUB);

    // One stub per child installer: logs the invocation, answers verify
    // from its own "<name>-compliant" toggle, and lets apply either fail
    // (via "<name>-apply-fail") or converge (creating the toggle). The
    // mail-capture apply also satisfies verify-mail-capture, mirroring the
    // real ownership relation between the two. An executable
    // "<name>-apply-hook" runs first: whatever else happens to the host while
    // that child applies, for a test that needs the host to change underneath
    // a running apply.
    foreach ([
        'runtime-installer', 'hostlayout-installer', 'operations-installer',
        'perimeter-installer', 'public-storage-installer', 'mail-capture-installer',
        'verify-mail-capture', 'nightwatch-installer', 'mail-signing-installer',
        'mail-gateway-installer',
    ] as $child) {
        bsvcWriteStub($scratch.'/bin/'.$child, <<<'STUB'
            #!/bin/bash
            me="$(basename "$0")"
            printf '%s %s\n' "${me}" "$*" >> "${STUB_LOG}/children.log"
            case "$*" in
                # The closed allowlist question, answered the way the real
                # installer answers it: staging-main records a deployment
                # marker, and no other target does.
                *--supports-deployment-marker*)
                    [[ "$*" == *"--target staging-main"* ]] && exit 0
                    exit 1
                    ;;
                # The mail signer's and the mail gateway's plan question, which
                # no other child is asked: it passes unless the toggle says the
                # plan is refused.
                *--check*)
                    [[ -e "${STUB_TOGGLES}/${me}-check-fail" ]] && exit 1
                    exit 0
                    ;;
                *--apply*)
                    [[ -x "${STUB_TOGGLES}/${me}-apply-hook" ]] && "${STUB_TOGGLES}/${me}-apply-hook"
                    [[ -e "${STUB_TOGGLES}/${me}-apply-fail" ]] && exit 1
                    touch "${STUB_TOGGLES}/${me}-compliant"
                    if [[ "${me}" == mail-capture-installer ]]; then
                        touch "${STUB_TOGGLES}/verify-mail-capture-compliant"
                        touch "${STUB_SVC_STATE}/staging-mailpit.enabled" "${STUB_SVC_STATE}/staging-mailpit.active"
                        touch "${STUB_SVC_STATE}/staging-mailtrap-local.enabled" "${STUB_SVC_STATE}/staging-mailtrap-local.active"
                    fi
                    exit 0
                    ;;
                *)
                    [[ -e "${STUB_TOGGLES}/${me}-compliant" ]] && exit 0
                    exit 1
                    ;;
            esac
            STUB);
    }
}

/**
 * The managed service files: logical destination => committed source.
 *
 * @return array<string, string>
 */
function bsvcManagedFiles(): array
{
    return [
        '/etc/ssh/sshd_config.d/70-rateguru-deploy.conf' => base_path('infrastructure/config/ssh/70-rateguru-deploy.conf'),
        '/etc/nginx/sites-available/rateguru-staging' => base_path('infrastructure/config/nginx/rateguru-staging'),
        '/etc/php/8.5/fpm/pool.d/rateguru-staging.conf' => base_path('infrastructure/config/php-fpm/rateguru-staging.conf'),
        '/etc/supervisor/conf.d/rateguru-staging-queue.conf' => base_path('infrastructure/config/supervisor/rateguru-staging-queue.conf'),
        '/etc/cron.d/rateguru-staging-scheduler' => base_path('infrastructure/config/cron/rateguru-staging-scheduler'),
    ];
}

/** @return list<string> */
function bsvcExternalPrerequisitePaths(): array
{
    return [
        '/etc/nginx/rateguru-staging.htpasswd',
        '/etc/letsencrypt/live/rateguru.staging.myprojects.pp.ua/fullchain.pem',
        '/etc/letsencrypt/live/rateguru.staging.myprojects.pp.ua/privkey.pem',
        '/etc/letsencrypt/live/staging-mail-capture/fullchain.pem',
        '/etc/letsencrypt/live/staging-mail-capture/privkey.pem',
        '/etc/letsencrypt/options-ssl-nginx.conf',
        '/etc/letsencrypt/ssl-dhparams.pem',
    ];
}

function bsvcOwnerTableAdd(string $scratch, string $physical, string $owner, string $group): void
{
    $table = $scratch.'/fs/owner-table.txt';
    $rows = array_filter(
        explode("\n", (string) @file_get_contents($table)),
        fn (string $row): bool => $row !== '' && ! str_starts_with($row, $physical.'|'),
    );
    $rows[] = "{$physical}|{$owner}|{$group}";
    file_put_contents($table, implode("\n", $rows)."\n");
}

/**
 * Build a fully simulated host and return the environment to run the
 * script against it.
 *
 * Options:
 *   profile:          'clean' (PRE_DEPLOY, nothing the service installer
 *                     owns installed yet, base services stopped) |
 *                     'compliant' (DEPLOYED, everything
 *                     installed and running)
 *   current:          'absent' | 'valid' | 'dangling' | 'outside' | 'wrongtype'
 *                     (defaults: clean => absent, compliant => valid)
 *   omitExternal:     list<string> external-prerequisite paths NOT created
 *   euid:             string (default '0')
 *
 * @param  array<string, mixed>  $options
 * @return array<string, string>
 */
function bsvcFixture(string $scratch, array $options = []): array
{
    $fs = $scratch.'/fs';
    $profile = $options['profile'] ?? 'clean';

    foreach ([
        '/etc/nginx/sites-available', '/etc/nginx/sites-enabled',
        '/etc/php/8.5/fpm/pool.d', '/etc/supervisor/conf.d',
        '/etc/cron.d', '/etc/ssh/sshd_config.d',
        '/home/www/rateguru/staging/shared/storage',
        '/home/www/rateguru/staging/releases',
        '/usr/bin', '/run/php',
    ] as $sub) {
        @mkdir($fs.$sub, 0o755, true);
    }

    touch($fs.'/usr/bin/php8.5');

    // External prerequisites (presence only — sentinel content proves the
    // installer never prints or copies it anywhere).
    $omitted = $options['omitExternal'] ?? [];

    foreach (bsvcExternalPrerequisitePaths() as $path) {
        if (in_array($path, $omitted, true)) {
            continue;
        }

        @mkdir(dirname($fs.$path), 0o755, true);
        file_put_contents($fs.$path, 'SECRET-SENTINEL-'.md5($path)."\n");
    }

    // Fixture passwd: the install-bootstrap-host-layout accounts exist.
    // Group database: the code group's GID is what an Nginx worker must
    // carry in its supplementary groups.
    file_put_contents($fs.'/etc-group', implode("\n", [
        'root:x:0:',
        'www-data:x:33:',
        'rateguru-staging:x:5001:',
        'rateguru-staging-code:x:5010:rateguru-staging,deploy-rateguru-staging,www-data',
    ])."\n");

    // Simulated running Nginx workers. `nginxWorkers` maps PID => list of
    // supplementary GIDs, so a test can model workers that predate the
    // code-group membership (the clean-VPS state) as easily as current ones.
    $workers = $options['nginxWorkers'] ?? ['4101' => ['33', '5010'], '4102' => ['33', '5010']];

    foreach ($workers as $pid => $gids) {
        @mkdir($fs.'/proc/'.$pid, 0o755, true);
        file_put_contents(
            $fs.'/proc/'.$pid.'/status',
            "Name:\tnginx\nUid:\t33\t33\t33\t33\nGroups:\t".implode(' ', $gids)." \n",
        );
    }

    file_put_contents($fs.'/nginx-worker-pids.txt', implode("\n", array_keys($workers))."\n");
    file_put_contents($fs.'/nginx-fresh-worker-gids.txt', ($options['nginxFreshWorkerGids'] ?? '33 5010')."\n");

    file_put_contents($fs.'/etc-passwd', implode("\n", [
        'root:x:0:0:root:/root:/bin/bash',
        'rateguru-staging:x:5001:5001::/home/www/rateguru/staging:/usr/sbin/nologin',
        'deploy-rateguru-staging:x:5002:5002::/home/deploy-rateguru-staging:/bin/bash',
    ])."\n");

    file_put_contents($fs.'/owner-table.txt', '');
    file_put_contents($fs.'/type-table.txt', '');

    // The PHP-FPM pool socket exists as soon as the pool runs; presenting a
    // plain fixture file as a socket via the type table.
    touch($fs.'/run/php/rateguru-staging.sock');
    chmod($fs.'/run/php/rateguru-staging.sock', 0o660);
    file_put_contents($fs.'/type-table.txt', $fs."/run/php/rateguru-staging.sock|TYPE|socket\n", FILE_APPEND);
    bsvcOwnerTableAdd($scratch, $fs.'/run/php/rateguru-staging.sock', 'www-data', 'www-data');

    // Deployment state.
    $current = $options['current'] ?? ($profile === 'compliant' ? 'valid' : 'absent');
    $staging = $fs.'/home/www/rateguru/staging';

    switch ($current) {
        case 'valid':
            @mkdir($staging.'/releases/20240101120000', 0o755, true);
            symlink($staging.'/releases/20240101120000', $staging.'/current');
            break;
        case 'dangling':
            symlink($staging.'/releases/never-deployed', $staging.'/current');
            break;
        case 'outside':
            @mkdir($staging.'/rogue-release', 0o755, true);
            symlink($staging.'/rogue-release', $staging.'/current');
            break;
        case 'wrongtype':
            @mkdir($staging.'/current', 0o755, true);
            break;
    }

    bsvcWriteStubs($scratch);

    if ($profile === 'compliant') {
        // Managed files installed byte-identical, root:root 0644; enabled
        // link present; logs dir present with the runtime ownership.
        foreach (bsvcManagedFiles() as $logical => $src) {
            copy($src, $fs.$logical);
            chmod($fs.$logical, 0o644);
            bsvcOwnerTableAdd($scratch, $fs.$logical, 'root', 'root');
        }

        symlink('/etc/nginx/sites-available/rateguru-staging', $fs.'/etc/nginx/sites-enabled/rateguru-staging');

        @mkdir($staging.'/shared/storage/logs', 0o755, true);
        chmod($staging.'/shared/storage/logs', 0o2770);
        bsvcOwnerTableAdd($scratch, $staging.'/shared/storage/logs', 'rateguru-staging', 'rateguru-staging');

        foreach ([
            'ssh', 'cron', 'nginx', 'postgresql', 'redis-server', 'supervisor',
            'php8.5-fpm', 'staging-mailpit', 'staging-mailtrap-local',
        ] as $unit) {
            touch($scratch.'/svc/'.$unit.'.enabled');
            touch($scratch.'/svc/'.$unit.'.active');
        }

        foreach ([
            'runtime-installer', 'hostlayout-installer', 'operations-installer',
            'perimeter-installer', 'public-storage-installer', 'verify-mail-capture',
            'nightwatch-installer', 'mail-signing-installer', 'mail-gateway-installer',
        ] as $child) {
            touch($scratch.'/toggles/'.$child.'-compliant');
        }

        touch($scratch.'/toggles/queue-running');
    } else {
        // Clean host: only ssh runs (a VPS always has it), and only the
        // runtime and host-layout prerequisite verifies pass.
        touch($scratch.'/svc/ssh.enabled');
        touch($scratch.'/svc/ssh.active');
        touch($scratch.'/toggles/runtime-installer-compliant');
        touch($scratch.'/toggles/hostlayout-installer-compliant');
    }

    return [
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'HOME' => getenv('HOME') ?: '/tmp',
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_BOOTSTRAPSVC_EUID' => $options['euid'] ?? '0',
        'RATEGURU_BOOTSTRAPSVC_FS_ROOT' => $fs,
        'RATEGURU_BOOTSTRAPSVC_PASSWD_FILE' => $fs.'/etc-passwd',
        'RATEGURU_BOOTSTRAPSVC_GROUP_FILE' => $fs.'/etc-group',
        'RATEGURU_BOOTSTRAPSVC_PGREP_BIN' => $scratch.'/bin/pgrep',
        'RATEGURU_BOOTSTRAPSVC_NGINX_WORKER_WAIT_ATTEMPTS' => '2',
        'RATEGURU_BOOTSTRAPSVC_RUNTIME_INSTALLER_BIN' => $scratch.'/bin/runtime-installer',
        'RATEGURU_BOOTSTRAPSVC_HOSTLAYOUT_INSTALLER_BIN' => $scratch.'/bin/hostlayout-installer',
        'RATEGURU_BOOTSTRAPSVC_OPERATIONS_INSTALLER_BIN' => $scratch.'/bin/operations-installer',
        'RATEGURU_BOOTSTRAPSVC_PERIMETER_INSTALLER_BIN' => $scratch.'/bin/perimeter-installer',
        'RATEGURU_BOOTSTRAPSVC_PUBLIC_STORAGE_INSTALLER_BIN' => $scratch.'/bin/public-storage-installer',
        'RATEGURU_BOOTSTRAPSVC_NIGHTWATCH_INSTALLER_BIN' => $scratch.'/bin/nightwatch-installer',
        'RATEGURU_BOOTSTRAPSVC_MAIL_CAPTURE_INSTALLER_BIN' => $scratch.'/bin/mail-capture-installer',
        'RATEGURU_BOOTSTRAPSVC_VERIFY_MAIL_CAPTURE_BIN' => $scratch.'/bin/verify-mail-capture',
        'RATEGURU_BOOTSTRAPSVC_MAIL_SIGNING_INSTALLER_BIN' => $scratch.'/bin/mail-signing-installer',
        'RATEGURU_BOOTSTRAPSVC_MAIL_GATEWAY_INSTALLER_BIN' => $scratch.'/bin/mail-gateway-installer',
        'RATEGURU_BOOTSTRAPSVC_SYSTEMCTL_BIN' => $scratch.'/bin/systemctl',
        'RATEGURU_BOOTSTRAPSVC_NGINX_BIN' => $scratch.'/bin/nginx',
        'RATEGURU_BOOTSTRAPSVC_SSHD_BIN' => $scratch.'/bin/sshd',
        'RATEGURU_BOOTSTRAPSVC_PHP_FPM_BIN' => $scratch.'/bin/php-fpm8.5',
        'RATEGURU_BOOTSTRAPSVC_SUPERVISORCTL_BIN' => $scratch.'/bin/supervisorctl',
        'RATEGURU_BOOTSTRAPSVC_STAT_BIN' => $scratch.'/bin/stat',
        'RATEGURU_BOOTSTRAPSVC_INSTALL_BIN' => $scratch.'/bin/install',
        'RATEGURU_BOOTSTRAPSVC_CHOWN_BIN' => $scratch.'/bin/chown',
        'RATEGURU_BOOTSTRAPSVC_CHMOD_BIN' => $scratch.'/bin/chmod',
        'RATEGURU_BOOTSTRAPSVC_SOCKET_WAIT_ATTEMPTS' => '1',
        'RATEGURU_BOOTSTRAPSVC_QUEUE_WAIT_ATTEMPTS' => '1',
        'RATEGURU_BOOTSTRAPSVC_STABILITY_WAIT' => '0',
        'RATEGURU_BOOTSTRAPSVC_RETRY_DELAY' => '0',
        'STUB_LOG' => $scratch.'/log',
        'STUB_REAL_PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'STUB_OWNER_TABLE' => $fs.'/owner-table.txt',
        'STUB_TYPE_TABLE' => $fs.'/type-table.txt',
        'STUB_SVC_STATE' => $scratch.'/svc',
        'STUB_TOGGLES' => $scratch.'/toggles',
        'STUB_FS' => $fs,
    ];
}

/**
 * The systemctl mutations (everything except is-enabled/is-active probes).
 *
 * @return list<string>
 */
function bsvcSystemctlMutations(string $scratch): array
{
    $lines = array_filter(explode("\n", bsvcLog($scratch, 'systemctl.log')));

    return array_values(array_filter(
        $lines,
        fn (string $line): bool => ! str_contains($line, 'is-enabled') && ! str_contains($line, 'is-active'),
    ));
}

/*
|--------------------------------------------------------------------------
| Translation engine
|--------------------------------------------------------------------------
|
| Shared by the engine's unit tests (items, batches, chunking, prompts) and
| its feature tests (service, router, OpenAI provider). No test reaches a real
| provider: the OpenAI tests run under Http::fake() with stray requests
| prevented, and the service tests use ScriptedTranslationProvider.
|
*/

/**
 * The API key the OpenAI tests configure. Deliberately not shaped like a real
 * OpenAI key, so no secret scanner ever mistakes a fixture for a credential —
 * and distinctive, so a test can prove it never appears where it must not.
 */
const TRANSLATION_TEST_API_KEY = 'translation-test-credential-never-real-4f1c';

/**
 * One valid item — a category name — with any named argument replaced.
 *
 * @param  array<string, mixed>  $overrides  TranslationItem constructor arguments by name
 */
function translationItem(array $overrides = []): TranslationItem
{
    return new TranslationItem(...array_replace([
        'id' => 'categories:17:name',
        'sourceLocale' => 'en',
        'sourceText' => 'Dogs',
        'contentType' => 'category.name',
        'multiline' => false,
        'context' => 'The category name shown on posts.',
        'maxLength' => 80,
        'placeholders' => [],
        'existingTranslations' => [],
    ], $overrides));
}

/**
 * $count short, distinct items with the ids item:1 … item:N, in that order.
 *
 * @param  array<string, mixed>  $overrides  applied to every item
 * @return list<TranslationItem>
 */
function translationItems(int $count, array $overrides = []): array
{
    return array_map(
        static fn (int $number): TranslationItem => translationItem(array_replace([
            'id' => "item:{$number}",
            'sourceText' => "Text number {$number}",
        ], $overrides)),
        $count > 0 ? range(1, $count) : [],
    );
}

/**
 * A batch into German of public project content, by default of one item.
 *
 * @param  list<TranslationItem>|null  $items
 * @param  array<array-key, string>  $glossary
 */
function translationBatch(
    ?array $items = null,
    string $targetLocale = 'de',
    TranslationDataClassification $classification = TranslationDataClassification::PublicContent,
    array $glossary = [],
): TranslationBatchRequest {
    return new TranslationBatchRequest($targetLocale, $classification, $items ?? [translationItem()], $glossary);
}

/**
 * Routes the engine to OpenAI with the test key and the given settings
 * replaced — so a test reads as "OpenAI, configured", whatever .env holds.
 *
 * @param  array<string, mixed>  $overrides  keys of translation.providers.openai
 */
function configureOpenAiTranslation(array $overrides = []): void
{
    config(['translation.default' => 'openai']);

    foreach (array_replace(['api_key' => TRANSLATION_TEST_API_KEY], $overrides) as $key => $value) {
        config(["translation.providers.openai.{$key}" => $value]);
    }
}

/**
 * A completed Responses API body whose assistant message carries this
 * output_text — after a reasoning item, as real responses often are, so a
 * parser that assumed output[0].content[0] would miss it.
 *
 * @param  array<string, mixed>  $overrides  top-level keys replaced or added
 * @return array<string, mixed>
 */
function openAiResponseBody(string $outputText, array $overrides = []): array
{
    return array_replace([
        'id' => 'resp_translation_test',
        'object' => 'response',
        'status' => 'completed',
        'model' => 'gpt-6-luna-2026-09-01',
        'output' => [
            ['type' => 'reasoning', 'id' => 'rs_test', 'summary' => []],
            [
                'type' => 'message',
                'id' => 'msg_test',
                'status' => 'completed',
                'role' => 'assistant',
                'content' => [
                    ['type' => 'output_text', 'text' => $outputText, 'annotations' => []],
                ],
            ],
        ],
        'usage' => ['input_tokens' => 120, 'output_tokens' => 30, 'total_tokens' => 150],
    ], $overrides);
}

/**
 * A Responses API body carrying these translations as its structured output.
 *
 * @param  list<array{id: string, text: string}>  $translations
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function openAiTranslationsBody(array $translations, array $overrides = []): array
{
    return openAiResponseBody(json_encode(['translations' => $translations], JSON_THROW_ON_ERROR), $overrides);
}

/**
 * The translation document a recorded request sent the model, decoded.
 *
 * @return array{target_locale: string, glossary: array<string, string>, items: list<array<string, mixed>>}
 */
function sentTranslationPayload(HttpClientRequest $request): array
{
    return json_decode($request->data()['input'][0]['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
}

/**
 * An Http::fake() responder playing OpenAI: every item a request carries comes
 * back, by id, translated by $translate — "[de] <source text>" by default.
 *
 * @param  (Closure(array<string, mixed>, string): string)|null  $translate  item payload and target locale to translation
 */
function openAiTranslatingResponder(?Closure $translate = null): Closure
{
    $translate ??= static fn (array $item, string $targetLocale): string => "[{$targetLocale}] {$item['source_text']}";

    return static function (HttpClientRequest $request) use ($translate) {
        $payload = sentTranslationPayload($request);

        return Http::response(openAiTranslationsBody(array_map(
            static fn (array $item): array => ['id' => $item['id'], 'text' => $translate($item, $payload['target_locale'])],
            $payload['items'],
        )), 200, ['x-request-id' => 'req_translation_test']);
    };
}

/**
 * A provider that answers each request with whatever its script says, and
 * records what it was sent. $respond gets the request and its 1-based call
 * number, and returns a response or throws TranslationProviderException.
 */
final class ScriptedTranslationProvider implements TranslationProvider
{
    /** @var list<TranslationBatchRequest> */
    public array $received = [];

    /** @param  Closure(TranslationBatchRequest, int): TranslationProviderResponse  $respond */
    public function __construct(
        private readonly Closure $respond,
        private readonly TranslationProviderLimits $limits = new TranslationProviderLimits(50, 60_000),
        private readonly string $name = 'scripted',
    ) {}

    /** A provider that translates every item it is sent as "[target] source". */
    public static function translating(?TranslationProviderLimits $limits = null, string $name = 'scripted'): self
    {
        return new self(
            static fn (TranslationBatchRequest $request): TranslationProviderResponse => scriptedTranslationResponse(
                $request,
                array_map(
                    static fn (TranslationItem $item): array => ['id' => $item->id, 'text' => "[{$request->targetLocale}] {$item->sourceText}"],
                    $request->items,
                ),
            ),
            $limits ?? new TranslationProviderLimits(50, 60_000),
            $name,
        );
    }

    public function name(): string
    {
        return $this->name;
    }

    public function limits(): TranslationProviderLimits
    {
        return $this->limits;
    }

    public function translateBatch(TranslationBatchRequest $request): TranslationProviderResponse
    {
        $this->received[] = $request;

        return ($this->respond)($request, count($this->received));
    }

    /** @return list<int> the number of items in each request, in order */
    public function chunkSizes(): array
    {
        return array_map(static fn (TranslationBatchRequest $request): int => count($request->items), $this->received);
    }
}

/**
 * What a scripted provider returns for a request: these translations, from
 * one successful call carrying the request's items.
 *
 * @param  list<array{id: string, text: string}>  $translations
 */
function scriptedTranslationResponse(TranslationBatchRequest $request, array $translations): TranslationProviderResponse
{
    return new TranslationProviderResponse(
        $translations,
        TranslationProviderCall::succeeded('scripted', 'scripted-model', $request->itemIds(), 1, 'scripted-request', 200, 10, 5, 15),
    );
}

/**
 * Makes a scripted provider the default, registered the way any provider is —
 * a driver class under a name in translation.providers — and accepting every
 * data classification unless $settings says otherwise.
 *
 * @param  array<string, mixed>  $settings  keys of its registry entry
 */
function useScriptedTranslationProvider(ScriptedTranslationProvider $provider, string $name = 'scripted', array $settings = []): ScriptedTranslationProvider
{
    config([
        'translation.default' => $name,
        "translation.providers.{$name}" => array_replace([
            'driver' => ScriptedTranslationProvider::class,
            'allowed_classifications' => array_map(
                static fn (TranslationDataClassification $classification): string => $classification->value,
                TranslationDataClassification::cases(),
            ),
        ], $settings),
    ]);

    // A closure binding, because the router passes the registry name as a
    // parameter and the container builds afresh, past any instance binding,
    // whenever it is given parameters.
    app()->bind(ScriptedTranslationProvider::class, static fn (): ScriptedTranslationProvider => $provider);

    return $provider;
}

/**
 * A provider whose every request fails with this code, as a provider call
 * that came to nothing does — recorded, and failing its items.
 */
function failingTranslationProvider(TranslationErrorCode $code): ScriptedTranslationProvider
{
    return new ScriptedTranslationProvider(
        static fn (TranslationBatchRequest $request): TranslationProviderResponse => throw new TranslationProviderException(
            $code,
            TranslationProviderCall::failed('scripted', 'scripted-model', $request->itemIds(), 3, $code),
        ),
    );
}

/**
 * A provider that answers its requests in turn: the nth request gets the nth
 * answer — a text for every item it carries, or a code it fails with. The
 * last answer repeats once the list runs out.
 *
 * @param  list<string|TranslationErrorCode>  $answers
 */
function answeringTranslationProvider(array $answers): ScriptedTranslationProvider
{
    return new ScriptedTranslationProvider(
        static function (TranslationBatchRequest $request, int $call) use ($answers): TranslationProviderResponse {
            $answer = $answers[min($call, count($answers)) - 1];

            if ($answer instanceof TranslationErrorCode) {
                throw new TranslationProviderException(
                    $answer,
                    TranslationProviderCall::failed('scripted', 'scripted-model', $request->itemIds(), 3, $answer),
                );
            }

            return scriptedTranslationResponse($request, array_map(
                static fn (TranslationItem $item): array => ['id' => $item->id, 'text' => $answer],
                $request->items,
            ));
        },
    );
}

/**
 * Whether a string, or any string inside an array, contains $needle. Objects
 * are not entered: the question is what a frame's own arguments carry.
 */
function translationValueContains(mixed $value, string $needle): bool
{
    if (is_string($value)) {
        return str_contains($value, $needle);
    }

    if (is_array($value)) {
        foreach ($value as $key => $element) {
            if ((is_string($key) && str_contains($key, $needle)) || translationValueContains($element, $needle)) {
                return true;
            }
        }
    }

    return false;
}

/*
|--------------------------------------------------------------------------
| Background translation generation
|--------------------------------------------------------------------------
|
| Generate missing plans, stores and queues; its jobs call the translation
| engine later. Tests fake the queue, then run the collected jobs the way a
| worker would — so "the request did not call a provider" and "the job did"
| are both observable. The draft store is the application's array store
| (tests/TestCase.php), so no test needs Redis.
|
*/

/** Starts Generate missing as this administrator, the way Translation Center does. */
function startTranslationGeneration(User $admin, string $locale): array
{
    return app(StartProjectTranslationGenerationAction::class)->handle($admin, $locale);
}

/**
 * Why a background generation operation was refused — its reason, or with
 * $withMessage the reason and the message an administrator reads, as
 * "reason: message" — or null when it was not.
 */
function generationRefusal(Closure $operation, bool $withMessage = false): ?string
{
    try {
        $operation();
    } catch (CannotGenerateTranslationsException $exception) {
        return $withMessage ? $exception->reason.': '.$exception->getMessage() : $exception->reason;
    }

    return null;
}

/** The administrator's background generation for a language, as Translation Center reads it. */
function translationGenerationOf(User $admin, string $locale): ?array
{
    return app(ReadProjectTranslationGenerationAction::class)->handle($admin, $locale);
}

/**
 * Runs every chunk job the faked queue has collected, each once, as a worker
 * would — or only the ones $which selects — and returns how many ran.
 *
 * @param  (Closure(GenerateProjectTranslationChunkJob): bool)|null  $which
 */
function runTranslationGenerationJobs(?Closure $which = null): int
{
    $jobs = Queue::pushed(GenerateProjectTranslationChunkJob::class, $which)->values()->all();

    foreach ($jobs as $job) {
        app()->call([$job, 'handle']);
    }

    return count($jobs);
}

/** One item of a generation summary, by unit id. */
function translationGenerationItem(array $generation, string $unit): ?array
{
    return collect($generation['items'] ?? [])->firstWhere('unit', $unit);
}
