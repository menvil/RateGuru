<?php

namespace App\Livewire\Import;

use App\Actions\Import\ImportFromUrlAction;
use App\Exceptions\Import\ImportFetchException;
use App\Exceptions\Import\UnsafeImportUrlException;
use App\Exceptions\Import\UrlImportDisabledException;
use App\Support\Settings\ProjectSettingsManager;
use Livewire\Component;

class ImportUrlForm extends Component
{
    public string $url = '';

    public ?string $previewTitle = null;

    public ?string $previewDescription = null;

    public ?string $previewImageUrl = null;

    public ?string $previewSourceUrl = null;

    public ?string $previewProvider = null;

    public ?string $error = null;

    public bool $unsupported = false;

    public bool $loading = false;

    public function import(): void
    {
        $this->reset(['previewTitle', 'previewDescription', 'previewImageUrl', 'previewSourceUrl', 'previewProvider', 'error', 'unsupported']);

        $this->validate(['url' => 'required|url']);

        $this->loading = true;

        try {
            $preview = app(ImportFromUrlAction::class)->handle($this->url);

            if (! $preview->isSupported()) {
                $this->unsupported = true;
                $this->error = __('import.errors.unsupported');
            } else {
                $this->previewTitle = $preview->title;
                $this->previewDescription = $preview->description;
                $this->previewImageUrl = $preview->imageUrl;
                $this->previewSourceUrl = $preview->sourceUrl;
                $this->previewProvider = $preview->provider->value;
            }
        } catch (UnsafeImportUrlException) {
            $this->error = __('import.errors.unsafe_url');
        } catch (UrlImportDisabledException) {
            $this->error = __('import.errors.feature_disabled');
        } catch (ImportFetchException) {
            $this->error = __('import.errors.fetch_failed');
        } catch (\Throwable $e) {
            report($e);
            $this->error = __('import.errors.unsupported');
        } finally {
            $this->loading = false;
        }
    }

    public function usePreview(): void
    {
        $this->dispatch('import-preview-selected', [
            'title' => $this->previewTitle,
            'description' => $this->previewDescription,
            'imageUrl' => $this->previewImageUrl,
            'sourceUrl' => $this->previewSourceUrl,
            'provider' => $this->previewProvider,
        ]);
    }

    public function render()
    {
        $enabled = app(ProjectSettingsManager::class)->featureEnabled('allow_url_imports');

        return view('livewire.import.import-url-form', [
            'enabled' => $enabled,
        ]);
    }
}
