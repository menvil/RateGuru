<?php

namespace App\Support\TranslationEngine;

use App\Support\TranslationEngine\Contracts\TranslationProvider;
use App\Support\TranslationEngine\Data\TranslationBatchRequest;
use App\Support\TranslationEngine\Enums\TranslationDataClassification;
use App\Support\TranslationEngine\Exceptions\TranslationConfigurationException;
use Illuminate\Contracts\Container\Container;

/**
 * Chooses the provider a batch is sent to.
 *
 * Today that is the configured default (translation.default), provided its
 * registry entry accepts the batch's data classification. The registry
 * (translation.providers) maps a name to a driver class, so adding a provider
 * is configuration: register its class under a name, and point the default at
 * it. Nothing here names a provider.
 *
 * It fails closed, and always before a request leaves the application: a
 * provider that is not registered, a driver that is not a provider, or a
 * classification the provider's entry does not list is refused. An entry that
 * lists no classifications accepts none.
 *
 * There is no fallback. When the chosen provider fails, its items fail; the
 * engine never quietly sends the same content to a second, possibly paid,
 * provider.
 */
final class TranslationProviderRouter
{
    public function __construct(
        private readonly Container $container,
    ) {}

    /** @throws TranslationConfigurationException */
    public function providerFor(TranslationBatchRequest $request): TranslationProvider
    {
        $name = config('translation.default');

        if (! is_string($name) || trim($name) === '') {
            throw TranslationConfigurationException::noDefaultProvider();
        }

        $registry = config('translation.providers');
        $settings = is_array($registry) ? ($registry[$name] ?? null) : null;

        if (! is_array($settings)) {
            throw TranslationConfigurationException::unknownProvider($name);
        }

        if (! $this->accepts($settings, $request->dataClassification)) {
            throw TranslationConfigurationException::classificationNotAllowed($name, $request->dataClassification);
        }

        $driver = $settings['driver'] ?? null;

        if (! is_string($driver) || ! is_a($driver, TranslationProvider::class, true)) {
            throw TranslationConfigurationException::invalidDriver($name);
        }

        // Only the name is passed: the provider reads its own settings, so no
        // credential is ever an argument a stack trace could record.
        $provider = $this->container->make($driver, ['name' => $name]);

        if (! $provider instanceof TranslationProvider) {
            throw TranslationConfigurationException::invalidDriver($name);
        }

        return $provider;
    }

    /** @param  array<mixed>  $settings */
    private function accepts(array $settings, TranslationDataClassification $classification): bool
    {
        $allowed = $settings['allowed_classifications'] ?? [];

        if (! is_array($allowed)) {
            return false;
        }

        foreach ($allowed as $entry) {
            if ($entry === $classification || $entry === $classification->value) {
                return true;
            }
        }

        return false;
    }
}
