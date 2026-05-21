<?php

namespace App\Filament\Resources\Tags\Pages;

use App\Actions\Tags\DeleteTagAction;
use App\Exceptions\Tags\CannotDeleteTagException;
use App\Filament\Resources\Tags\TagResource;
use App\Models\Tag;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Str;

class EditTag extends EditRecord
{
    protected static string $resource = TagResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('delete')
                ->label('Delete')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn (): bool => auth()->user()?->isAdmin() === true)
                ->requiresConfirmation()
                ->modalDescription('Tags attached to posts cannot be deleted. Detach or merge them first.')
                ->action(function (): void {
                    /** @var Tag $record */
                    $record = $this->getRecord();

                    try {
                        app(DeleteTagAction::class)->handle(auth()->user(), $record);
                    } catch (CannotDeleteTagException $e) {
                        Notification::make()
                            ->title('Tag is used by posts')
                            ->body('Detach or merge this tag before deleting it.')
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Tag deleted')
                        ->success()
                        ->send();

                    $this->redirect(TagResource::getUrl('index'));
                }),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['slug'] = Str::slug($data['slug'] ?: $data['name']);

        return $data;
    }
}
